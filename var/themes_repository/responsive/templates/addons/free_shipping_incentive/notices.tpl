{if function_exists('fn_free_shipping_incentive_display')}
    {$text = fn_free_shipping_incentive_display($hook, $position, $product)}
    {if $text !== false}
        {assign var="class_hook" value=':'|str_replace:'_':$hook}
        {assign var="class_hook_position" value="`$class_hook`_`$position`"}
        <div class="fsn-notice fsn-notice-{$class_hook} fsn-notice-{$class_hook_position}" id="cart_status_fsn_{$product.product_id}">
            {$text nofilter}
            <!--cart_status_fsn_{$product.product_id}--></div>
    {/if}
{/if}