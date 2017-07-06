<?php

namespace HeloStore\FreeShippingIncentive\Promotion;

class PromotionRepository
{
    public static function find()
    {
        $params = array (
            'free_shipping_only' => true,
            'active' => true,
            'expand' => true,
            /*'zone' => 'catalog',*/
            'get_hidden' => false,
            'sort_by' => 'priority',
            'sort_order' => 'asc'
        );
        list($promotions) = fn_get_promotions($params);

        return $promotions;
    }
}