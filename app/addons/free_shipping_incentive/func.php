<?php
use Tygh\Enum\ProductTracking;
use Tygh\Http;
use Tygh\Registry;
use Tygh\Shippings\Shippings;

/**
 * Hooks
 */

/**
 * Only find promotions offering free shipping as bonuses.
 *
 * @param $params
 * @param $fields
 * @param $sortings
 * @param $condition
 * @param $join
 */
function fn_free_shipping_incentive_get_promotions($params, $fields, $sortings, &$condition, $join)
{
    if (!empty($params['free_shipping_only'])) {
        $condition .= db_quote(' AND bonuses LIKE "%\"free_shipping\"%"');
    }
}

/**
 * Functions
 */

/**
 * @param array $auth
 * @param array $cart Copy of SESSION's cart.
 *
 * @return array
 */
function fn_free_shipping_incentive_calculate_promotions($auth, $cart)
{
    $promotions = \HeloStore\FreeShippingIncentive\Promotion\PromotionRepository::find();
    $cart_products = $cart['products'];
    $currentAmount = $cart['subtotal'];
    $cart['subtotal'] = PHP_INT_MAX;
    $matchedConditions = array();

    static $ignoredPromotionConditions = null;
    if ($ignoredPromotionConditions === null) {
        $ignoredPromotionConditions = array();
        $settings = Registry::get('addons.free_shipping_incentive');
        // Bug in CS-Cart when handling multiple checkboxes; this array should be empty when no checkbox is selected
        if (isset($settings['ignored_promotion_conditions']['N'])) {
            unset($settings['ignored_promotion_conditions']['N']);
        }
        if ( ! empty($settings['ignored_promotion_conditions'])) {
            foreach ($settings['ignored_promotion_conditions'] as $condition => $value) {
                if ($value === 'Y') {
                    $ignoredPromotionConditions[] = $condition;
                }
            }
        }
    }

    foreach ($promotions as $promotion) {

        if (empty($promotion['conditions'])) {
            continue;
        }

        if ( ! empty($ignoredPromotionConditions)) {
            if ( ! empty($promotion['conditions']['conditions']) && $promotion['conditions']['set'] === 'all') {
                foreach ($promotion['conditions']['conditions'] as $k => $condition) {
                    if (in_array($condition['condition'], $ignoredPromotionConditions)) {
                        unset($promotion['conditions']['conditions'][$k]);
                    }
                }
            }
        }

        list($tmp_result, $nested_checked_conditions) = fn_check_promotion_condition_groups_recursive(
            $promotion['conditions'],
            $promotion,
            $cart,
            $auth,
            $cart_products
        );
        if ($tmp_result != true) {
            continue;
        }
        if (!isset($nested_checked_conditions['subtotal'])) {
            continue;
        }

        $rules = $nested_checked_conditions['subtotal'];

        foreach ($rules as $rule) {
            if (empty($rule['condition'])) {
                continue;
            }
            if (empty($rule['condition']['value'])) {
                continue;
            }
            if (in_array($rule['condition']['operator'], array('eq', 'gte', 'gt'))) {
                $requiredAmount = $rule['condition']['value'];

                if ($rule['condition']['operator'] == 'gt') {
                    $roundedRequiredAmount = ceil($rule['condition']['value']);
                    if ($roundedRequiredAmount > $rule['condition']['value']) {
                        $requiredAmount = $roundedRequiredAmount;
                    } else {
                        $requiredAmount = $rule['condition']['value'] + 1;
                    }
                    $requiredAmount = intval($requiredAmount);
                }
                $rule['condition']['required_amount'] = $requiredAmount;
                $rule['condition']['needed_amount'] = $requiredAmount - $currentAmount;
                $rule['condition']['source_type'] = 'promotion';
                $rule['condition']['source_name'] = $promotion['name'];
                $rule['condition']['source_id'] = $promotion['promotion_id'];
                $matchedConditions[$requiredAmount . ""] = $rule['condition'];
            }
        }
    }
    ksort($matchedConditions);

    return $matchedConditions;
}

/**
 * @param $auth
 * @param $cart
 *
 * @return array
 */
function fn_free_shipping_incentive_calculate_cart_shipping($auth, &$cart)
{
    // Code block borrowed from CS-Cart's core fn_calculate_cart_content() function
    $location = fn_get_customer_location($auth, $cart);
    $product_groups = Shippings::groupProductsList($cart['products'], $location);
    $shippings = array();
    if ($cart['shipping_required'] !== false) {
        $cart['shipping_required'] = false;
        foreach ($product_groups as $key_group => $group) {
            if ($group['shipping_no_required'] === false) {
                $cart['shipping_required'] = true;
                break;
            }
        }
    }

    foreach ($product_groups as $key_group => $group) {
        if ($cart['shipping_required'] === false) {
            $product_groups[$key_group]['free_shipping'] = true;
            $product_groups[$key_group]['shipping_no_required'] = true;
        }

        $product_groups[$key_group]['shippings'] = array();
        $shippings_group = Shippings::getShippingsList($group);

        // Adding a shipping method from the created order, if the shipping is not yet in the list.
        if (!empty($cart['chosen_shipping']) && !empty($cart['shipping']) && !empty($cart['order_id'])) {
            foreach ($cart['shipping'] as $shipping) {
                if (!isset($shippings_group[$shipping['shipping_id']])) {
                    $shippings_group[$shipping['shipping_id']] = $shipping;
                }
            }
        }

        foreach ($shippings_group as $shipping_id => $shipping) {
            if (!empty($shipping['service_params']['max_weight_of_box'])) {
                $_group = Shippings::repackProductsByWeight($group, $shipping['service_params']['max_weight_of_box']);
            } else {
                $_group = $group;
            }

            $_shipping = $shipping;
            $_shipping['package_info'] = $_group['package_info'];
            $_shipping['keys'] = array(
                'group_key' => $key_group,
                'shipping_id' => $shipping_id,
            );
            $shippings[] = $_shipping;

            $shipping['group_key'] = $key_group;
            $shipping['rate'] = 0;

            if (in_array($shipping_id, $cart['free_shipping']) || $group['free_shipping']) {
                $shipping['free_shipping'] = true;
            }

            $product_groups[$key_group]['shippings'][$shipping_id] = $shipping;
        }
    }
    // Code block borrowed - end

    return $shippings;
}

/**
 * @param $hook
 * @param $position
 * @param $product
 *
 * @return bool|mixed
 */
function fn_free_shipping_incentive_display($hook, $position, $product, $returnAsObject = false)
{
    $settings = Registry::get('addons.free_shipping_incentive');
    $view = Registry::get('runtime.view');
    $mode = Registry::get('runtime.mode');
    $controller = Registry::get('runtime.controller');

    if ($hook == 'products:notification_items') {
        $cartAddedProducts = Tygh::$app['view']->getTemplateVars('added_products');
        if (!empty($_REQUEST['product_data']) && !empty($cartAddedProducts)) {

            $requestProductId = key($_REQUEST['product_data']);
            foreach ($cartAddedProducts as $cartAddedProduct) {
                if ($cartAddedProduct['product_id'] == $requestProductId){
                    $product = $cartAddedProduct;
                    break;
                }

            }
        }
    }

    $isMainProduct = true;
    if (!empty($_REQUEST['product_id'])) {
        if ($_REQUEST['product_id'] != $product['product_id']) {
            $isMainProduct = false;
        }
    } else if (!empty($view)) {
        $mainProduct = $view->getTemplateVars('product');
        if (!empty($mainProduct)) {
            if ($mainProduct['product_id'] != $product['product_id']) {
                $isMainProduct = false;
            }
        }
    }

    $badgeHooks = ['products:product_labels'];

    if (in_array($hook, $badgeHooks)) {
        $badgePages = [
            ['name' => 'product_page',            'controller' => 'products',           'mode' => ['view', 'options']],
            ['name' => 'search_page',             'controller' => 'products',           'mode' => ['search']],
            ['name' => 'category_page',           'controller' => 'categories',         'mode' => ['view']],
            ['name' => 'home_page',               'controller' => 'index',              'mode' => ['index']],
            ['name' => 'bestsellers_page',        'controller' => 'products',           'mode' => ['bestsellers']],
            ['name' => 'sales_page',              'controller' => 'products',           'mode' => ['on_sale']],
            ['name' => 'newest_page',             'controller' => 'products',           'mode' => ['newest']],
            ['name' => 'product_quick_view_page', 'controller' => 'products',           'mode' => ['quick_view']],
            ['name' => 'product_features_page',   'controller' => 'product_features',   'mode' => ['view']],
        ];
        foreach ($badgePages as $page) {
            $isBadgeEnabledOnPage = (isset($settings['display_product_label_on'][$page['name']]) && $settings['display_product_label_on'][$page['name']] === 'Y');
            $isUserOnPage = ($controller === $page['controller'] && in_array($mode, $page['mode']));
            if ($isBadgeEnabledOnPage && $isUserOnPage) {
                $isPre = ($position == 'pre' && $settings['display_product_details_position'] == 'before');
                $isPost = ($position == 'post' && $settings['display_product_details_position'] == 'after');
                if ($isPre || $isPost) {
                    return fn_free_shipping_incentive_format_text($settings, $product, $returnAsObject);
                }
            }
        }
    } else if ($settings['display_product_details'] == 'Y') {
        $isAddToCartNotification = ($controller == 'checkout' && $mode == 'add');
        $isProductPage = ($controller == 'products' && in_array($mode, array('view', 'options')));

        if ($isAddToCartNotification || ($isMainProduct && $isProductPage)) {
            $standard_hook_enabled = (isset($settings['display_product_details_hooks'][$hook]) && $settings['display_product_details_hooks'][$hook] == 'Y');
            $custom_hook_enabled = (isset($settings['display_product_details_custom_hooks']) && $settings['display_product_details_custom_hooks'] == $hook);
            $isPre = ($position == 'pre' && $settings['display_product_details_position'] == 'before');
            $isPost = ($position == 'post' && $settings['display_product_details_position'] == 'after');
            if ($standard_hook_enabled || $custom_hook_enabled) {
                if ($isPre || $isPost) {
                    return fn_free_shipping_incentive_format_text($settings, $product, $returnAsObject);
                }
            }
        }
    }


    return false;
}

/**
 * @param $settings
 * @param $product
 * @param $cart
 * @param $auth
 *
 * @return array|bool
 */
function fn_free_shipping_incentive_get_variables($settings, $product, $cart, $auth)
{
    static $cache = array();

    $allowNegativeInventory = (Registry::get('settings.General.allow_negative_amount') == 'Y');

    if (!$allowNegativeInventory && $product['tracking'] != ProductTracking::DO_NOT_TRACK && $product['amount'] == 0) {
        return array();
    }

    $variables = array();
    $variables['cart_empty'] = empty($cart['products']);
    $variables['current_product_in_cart'] = false;

    // total = order final total (including shipping cost)
    // subtotal = order total without shipping cost
    $variables['cart_total'] = $cart['subtotal'];
    $variables['free_shipping_product_in_cart'] = false;
    $free_shipping_product_in_cart = null;
    $has_free_shipping_rate = false;
    $min_required_amount = PHP_INT_MAX;

    // check if current product is already in cart
    // $hash - create hash key to index result in cache
    $hash = array();
    if (!empty($cart['products'])) {
        foreach ($cart['products'] as $id => $item) {
            $hash[] = $id;
            $hash[] = $item['amount'];
            $hash[] = $item['price'];

            if (!isset($item['free_shipping'])) {
                $item['free_shipping'] = db_get_field('SELECT free_shipping FROM ?:products WHERE product_id = ?i', $item['product_id']);
            }
            if ($item['free_shipping'] == 'Y') {
                $variables['free_shipping_product_in_cart'] = true;
                $free_shipping_product_in_cart = $item;
            }
            if ($item['product_id'] == $product['product_id']) {
                $variables['current_product_in_cart'] = true;
            }
        }
    }
    $hash[] = $product['product_id'];
    $hash = md5(implode('|', $hash));
    $variables['vendor_name'] = $product['company_name'];

    if ($variables['free_shipping_product_in_cart']) {
        $has_free_shipping_rate = true;
        $min_required_amount = $free_shipping_product_in_cart['price'];
        $variables['required_amount'] = fn_format_price($free_shipping_product_in_cart['price']);
        $variables['source_id'] = $free_shipping_product_in_cart['product_id'];
        $variables['source_type'] = 'individual_product_free_shipping';
        $variables['source_name'] = $free_shipping_product_in_cart['product'];
    } else {
        // if cart empty, add current product to local $cart to estimate if there's any free shipping available
        if (empty($cart['products'])) {
            if (!empty($product['price']) && $product['price'] > 0) {
                $product_data = array(
                    $product['product_id'] => array(
                        'amount' => 1,
                        'product_id' => $product['product_id'],
                    ),
                );
                fn_add_product_to_cart($product_data, $cart, $auth);
                $cart['change_cart_products'] = true;
                fn_calculate_cart_content($cart, $auth, 'S', true, 'F', false);
            } else {
                // do nothing for products without prices
            }
        } else {

            $cartCompanyIds = array();
            foreach ($cart['products'] as $cartProduct) {
                if (!in_array($cartProduct['company_id'], $cartCompanyIds)) {
                    $cartCompanyIds[] = $cartProduct['company_id'];
                }
            }
            $currentCompanyId = (!empty($product) && !empty($product['company_id']) ? $product['company_id'] : 1);
            $otherCompanyIdsInCart = array_diff($cartCompanyIds, array($currentCompanyId));
            $hasProductsInCartFromCurrentVendor = in_array($currentCompanyId, $cartCompanyIds);
            $hasProductsInCartFromOtherVendors = !empty($otherCompanyIdsInCart);

            // @TODO differentiate between all these cases so that we can inform the customer about having products in cart from different Vendors, with different possibly shipping threshold

            if ($hasProductsInCartFromOtherVendors && !$hasProductsInCartFromCurrentVendor) {
                // Case 1. Cart contains products from other Vendors (with different shipping methods), but not from Current Vendor
                //      => display threshold from Current Vendor's shipping methods (calculation should take into account only the current product)
                $cart['products'] = array();
                $product_data = array(
                    $product['product_id'] => array(
                        'amount' => 1,
                        'product_id' => $product['product_id'],
                    ),
                );
                fn_add_product_to_cart($product_data, $cart, $auth);
                $cart['change_cart_products'] = true;
                fn_calculate_cart_content($cart, $auth, 'S', true, 'F', false);
                $variables['cart_total'] = 0;
                $variables['cart_empty'] = true;

            } else if ($hasProductsInCartFromOtherVendors && $hasProductsInCartFromCurrentVendor) {
                // Case 2. Cart contains products from Other Vendors, but also from Current Vendor ($product's vendor)
                //      => display threshold from Current Vendor's shipping methods (calculation should take into account both current product + other cart products from Current Vendor)

                // Filter out cart products from Other Vendors, as they are irrelevant for this Current Vendor's shipping threshold
                foreach ($cart['products'] as $cartProductId => $cartProduct) {
                    if ($cartProduct['company_id'] !== $currentCompanyId) {
                        fn_delete_cart_product($cart, $cartProductId);
                    }
                }
                $cart['change_cart_products'] = true;
                fn_calculate_cart_content($cart, $auth, 'S', true, 'F', false);

            } else if (!$hasProductsInCartFromOtherVendors && $hasProductsInCartFromCurrentVendor) {
                // Case 3. Cart contains other products from Current Vendor
                //      => display threshold from Current Vendor's shipping methods (calculation should take into account both current product + all cart products)
                // Nothing to do here, this is the default behavior.
            }

        }

	    $promotionsConditions = array();
	    if ( defined('PRODUCT_VERSION') && version_compare( PRODUCT_VERSION, '4.3', '>=' ) ) {
		    $promotionsConditions = fn_free_shipping_incentive_calculate_promotions($auth, $cart);
	    }

        // Code block borrowed from CS-Cart's core fn_calculate_cart_content() function
        $shippings = fn_free_shipping_incentive_calculate_cart_shipping($auth, $cart);
        // at this point, an empty $cart would now contain the current product which was added locally by the shipping estimation function

        // Check lowest Free-Shipping-Threshold in promotions list.
        if (!empty($promotionsConditions)) {
            foreach ($promotionsConditions as $rule) {
                $requiredAmount = $rule['required_amount'];
                if (!empty($requiredAmount) && $min_required_amount > $requiredAmount) {
                    $min_required_amount = $requiredAmount;
                    $has_free_shipping_rate = true;
                }

                if ($has_free_shipping_rate && $min_required_amount < PHP_INT_MAX) {
                    $variables['required_amount'] = $min_required_amount;
                    $variables['source_id'] = $rule['source_id'];
                    $variables['source_type'] = $rule['source_type'];
                    $variables['source_name'] = $rule['source_name'];
                }
            }
        }
        if (defined('FREE_SHIPPING_INCENTIVE_CHOSEN_SHIPPING_ONLY') && FREE_SHIPPING_INCENTIVE_CHOSEN_SHIPPING_ONLY === true) {
            $variables['flag_chosen_shipping_only'] = true;
        }

        // Check lowest Free-Shipping-Threshold in shipping list.
        if (!empty($shippings)) {
            foreach ($shippings as $shipping) {
                if ($variables['flag_chosen_shipping_only']) {
                    if (!empty($cart['chosen_shipping']) && !in_array($shipping['shipping_id'], $cart['chosen_shipping'])) {
                        continue;
                    }
                }

                if (empty($shipping['rate_info']) || empty($shipping['rate_info']['rate_value'])) {
                    continue;
                }

                if (!empty($shipping['rate_info']['rate_value']['C'])) {
                    // consider only the cheapest shipping method
                    foreach ($shipping['rate_info']['rate_value']['C'] as $requiredAmount => $rate) {
                        if ($rate['value'] == 0) {
                            if (!empty($requiredAmount) && $min_required_amount > $requiredAmount) {
                                $min_required_amount = $requiredAmount;
                                $has_free_shipping_rate = true;
                            }
                        }
                    }

                    if ($has_free_shipping_rate && $min_required_amount < PHP_INT_MAX) {
                        $variables['required_amount'] = $min_required_amount;
                        $variables['source_id'] = $shipping['shipping_id'];
                        $variables['source_type'] = 'shipping';
                        $variables['source_name'] = $shipping['shipping'];
                    }
                }
            }
        }

        // When adding to cart, the product data doesn't contain this property, so fetch it from database.
        if (!isset($product['free_shipping'])) {
            $product['free_shipping'] = db_get_field('SELECT free_shipping FROM ?:products WHERE product_id = ?i', $product['product_id']);
        }

        if ($product['free_shipping'] === 'Y') {
            $has_free_shipping_rate = true;
            $min_required_amount = $product['price'];
            $variables['required_amount'] = fn_format_price($product['price']);
            $variables['source_id'] = $product['product_id'];
            $variables['source_type'] = 'individual_product_free_shipping';
            $variables['source_name'] = $product['product'];
        }
    }

    if (!$has_free_shipping_rate) {
        $cache[$hash] = false;
        return false;
    }

    if ($min_required_amount == PHP_INT_MAX) {
        $cache[$hash] = false;
        return false;
    }

    // display_product_details_text_ineligible_empty_cart // Add products
    // display_product_details_text_eligible // You get free delivery
    // display_product_details_text_ineligible_add_this // Add this product
    // display_product_details_text_ineligible_add_more // Add more products

    $currentCartAmount = $cart['subtotal'];
    $variables['min_required_amount'] = $min_required_amount;
    if ($variables['cart_empty']) {
        $variables['needed_amount'] = $min_required_amount;
    } else {
        $variables['needed_amount'] = $min_required_amount - $currentCartAmount;
    }

    $textCase = null;

    if ($variables['source_type'] == 'individual_product_free_shipping') {
        if ($variables['cart_empty']) {
            $textCase = 'display_product_details_text_eligible_individual_item';
        } else {
            if ($variables['current_product_in_cart']) {
                $textCase = 'display_product_details_text_eligible';
            } else {
                $textCase = 'display_product_details_text_eligible_individual_item';
            }
        }
    } else {
        if ($variables['cart_empty']) {
            // this is the potential cart total (if the customer would've added current product)
            if ($currentCartAmount >= $min_required_amount) {
                $textCase = 'display_product_details_text_ineligible_add_this';
            } else {
                $textCase = 'display_product_details_text_ineligible_empty_cart';
            }
        } else if ($currentCartAmount >= $min_required_amount) {
            $textCase = 'display_product_details_text_eligible';
        } else if ($product['price'] + $currentCartAmount >= $min_required_amount) {
            $textCase = 'display_product_details_text_ineligible_add_this';
        } else {
            $textCase = 'display_product_details_text_ineligible_add_more';
        }
    }
    $variables['badge_text'] = $settings['display_product_label_text_eligible'];
    $variables['text_case'] = $textCase;
    $variables['text'] = $settings[$textCase];
    $cache[$hash] = $variables;

    return $variables;
}

/**
 * @param $settings
 * @param $product
 *
 * @return bool|mixed
 */
function fn_free_shipping_incentive_format_text($settings, $product, $returnAsObject = false)
{
    $cart = isset($_SESSION['cart']) ? $_SESSION['cart'] : array();
    $auth = isset($_SESSION['auth']) ? $_SESSION['auth'] : array();
    $variables = fn_free_shipping_incentive_get_variables($settings, $product, $cart, $auth);
    if (empty($variables)) {
        return false;
    }

    $text = $variables['text'];
    static $currency = null;
    static $trailingZeros = null;
    if ($currency === null) {
        $currencies = Registry::get('currencies');
        $currency = $currencies[CART_SECONDARY_CURRENCY];
        $decimals = isset($currency['display_decimals']) ? $currency['display_decimals'] : $currency['decimals'];
        $trailingZeros = $currency['decimals_separator'] . str_repeat('0', $decimals);
    }

    foreach ($variables as $variable => $value) {
        $search = '[' . $variable . ']';
        if (in_array($variable, array('cart_total', 'required_amount', 'needed_amount'))) {
            $value = fn_free_shipping_incentive_format_price($value, $currency);
            $value = str_replace($trailingZeros, '', $value);
        }
        $text = str_replace($search, $value, $text);
    }
    $variables['text_final'] = $text;
    if ($returnAsObject) {
        return $variables;
    }

    return $text;
}

/**
 * @param $price
 * @param $currency
 * @param bool $is_secondary
 *
 * @return string
 */
function fn_free_shipping_incentive_format_price($price, $currency, $is_secondary = false)
{
    $number_type = 'F';
    $value = fn_format_rate_value(
        $price,
        $number_type,
        $currency['decimals'],
        $currency['decimals_separator'],
        $currency['thousands_separator'],
        $currency['coefficient']
    );

    $data = array (
        $value,
    );

    if ($currency['after'] == 'Y') {
        array_push($data, '&nbsp;' . $currency['symbol']);
    } else {
        array_unshift($data, $currency['symbol']);
    }

    return implode('', $data);
}
/**
 * @return string
 */
function fn_free_shipping_incentive_display_product_details_variables_info()
{
    $html = '
        <div class="control-group setting-wide">
            <label class="control-label">' . __('fsn.variables') . '</label>
            <div class="controls">
            <ul>
                <li><code>[cart_total]</code> - Current cart\'s total (excluding any shipping cost).</li>
                <li><code>[required_amount]</code> - Cart total must equal or exceed this amount to benefit from free shipping. This amount is configured in the shipping methods rates.</li>
                <li><code>[needed_amount]</code> - Difference amount needed to benefit from free shipping (ie. <code>[required_amount]</code> - <code>cart_total</code>).</li>
                <li><code>[vendor_name]</code> - Vendor\'s name.</li>
            </ul>
            </div>
        </div>';

    return $html;
}

/**
 * @return string
 */
function fn_free_shipping_incentive_display_product_details_hooks_info()
{
    $file = 'fsn-visual-guide.png';
    $addonPath = defined('FREE_SHIPPING_INCENTIVE_ADDON_DIR') ? FREE_SHIPPING_INCENTIVE_ADDON_DIR : dirname(__FILE__);
    $path = $addonPath . DIRECTORY_SEPARATOR . $file;
    $mime = 'image/png';
    if (file_exists($path)) {
        $contents = file_get_contents($path);
        $base64 = base64_encode($contents);
        $image = ('data:' . $mime . ';base64,' . $base64);
        $title = __('help') . ' - Hooks Visual Guide';
        $html = '
            <div class="control-group setting-wide">
                <label class="control-label"></label>
                <div class="controls">
                    <div class="fsn-box">
                        <a class="fsn-push-button" href="#popup1">' . $title . '</a>
                    </div>
                </div>
            </div>

            <div id="popup1" class="fsn-overlay">
              <div class="fsn-popup">
                <h4>' . $title . '</h4>
                <a class="close" href="#">&times;</a>
                <div class="content">
                  <img src="' . $image . '" alt="' . $title . '" style="max-width: 67%;" />
                  <p>To display the text in a custom template hook, the hook must include this code: </p>
                  <code>
                    {include file=&#x22;addons/free_shipping_incentive/notices.tpl&#x22; hook=&#x22;&#x3C;your-custom-hook&#x3E;&#x22; position=&#x22;&#x3C;position&#x3E;&#x22;}
                  </code>
                  <br>
                    Where:
                    <br>
                    - &lt;your-custom-hook&gt; should be in the form: &lt;hook-directory-name&gt;:&lt;hook-file-name&gt; (ex.: products:add_to_cart)<br>
                    - &lt;position&gt; must be either pre or post<br>
                </div>
              </div>
            </div>';
        return $html;
    }

    return '';
}

/**
 * Uninstall.
 */
function fn_free_shipping_incentive_uninstall()
{
    if (class_exists('\HeloStore\ADLS\LicenseClient', true)) {
        \HeloStore\ADLS\LicenseClient::process(\HeloStore\ADLS\LicenseClient::CONTEXT_UNINSTALL);
    }
}

/**
 * Install.
 */
function fn_free_shipping_incentive_install()
{
    if (class_exists('\HeloStore\ADLS\LicenseClient', true)) {
        \HeloStore\ADLS\LicenseClient::process(\HeloStore\ADLS\LicenseClient::CONTEXT_INSTALL);
    }
}
