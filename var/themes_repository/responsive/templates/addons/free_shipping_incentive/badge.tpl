{strip}
    {if function_exists('fn_free_shipping_incentive_display')}
        {$variables = fn_free_shipping_incentive_display($hook, $position, $product, true)}
        {if !empty($variables) && !empty($variables.badge_text) && !empty($variables.text_case)}
            {if in_array($variables.text_case, ['display_product_details_text_eligible', 'display_product_details_text_ineligible_add_this'])}
                {assign var="class_hook" value=':'|str_replace:'_':$hook}
                {assign var="class_hook_position" value="`$class_hook`_`$position`"}
                {include
                    file="views/products/components/product_label.tpl"
                    label_meta="ty-product-labels__itelm--fsn fsn-badge-{$class_hook} fsn-badge-{$class_hook_position}"
                    label_text=$variables.badge_text
                    label_mini=false
                    label_static=false
                    label_rounded=false
                }
            {/if}
        {/if}
    {/if}
{/strip}