{if function_exists('fn_free_shipping_incentive_display')}
    {$variables = fn_free_shipping_incentive_display($hook, $position, $product, true)}
    {if !empty($variables) && !empty($variables.text_final)}

        {assign var="class_hook" value=':'|str_replace:'_':$hook}
        {assign var="class_hook_position" value="`$class_hook`_`$position`"}
        <div class="fsn-notice fsn-notice-{$class_hook} fsn-notice-{$class_hook_position}" id="cart_status_fsn_{$product.product_id}">
            {$variables.text_final nofilter}
            {if defined('FREE_SHIPPING_INCENTIVE_DISPLAY_DEBUG')}
                {var_dump($variables)}
            {/if}
            <!--cart_status_fsn_{$product.product_id}--></div>
    {/if}
{/if}