<?php namespace Logingrupa\PayseraShopaholic\Classes\Collection;

use Lovata\OrdersShopaholic\Classes\Collection\PaymentMethodCollection;
use Logingrupa\PayseraShopaholic\Classes\Helper\TestModeVisibility;

/**
 * PaymentMethodCollection whose active() also drops Paysera methods in test mode
 * for visitors without a backend session.
 */
class VisiblePaymentMethodCollection extends PaymentMethodCollection
{
    public function active()
    {
        parent::active();

        return $this->diff(TestModeVisibility::hiddenIdList());
    }
}
