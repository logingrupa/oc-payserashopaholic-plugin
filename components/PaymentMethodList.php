<?php namespace Logingrupa\PayseraShopaholic\Components;

use Lovata\OrdersShopaholic\Components\PaymentMethodList as BasePaymentMethodList;
use Logingrupa\PayseraShopaholic\Classes\Collection\VisiblePaymentMethodCollection;

/**
 * Registered under the same alias as the OrdersShopaholic component, so theme
 * templates keep calling PaymentMethodList.makeCollection().active() and get the
 * test-mode filtering for free.
 */
class PaymentMethodList extends BasePaymentMethodList
{
    public function makeCollection($arElementIDList = null)
    {
        return VisiblePaymentMethodCollection::make($arElementIDList);
    }
}
