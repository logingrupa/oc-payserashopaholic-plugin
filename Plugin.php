<?php namespace Logingrupa\PayseraShopaholic;

use Event;
use System\Classes\PluginBase;
use Logingrupa\PayseraShopaholic\Classes\Event\ExtendFieldHandler;
use Logingrupa\PayseraShopaholic\Classes\Event\PaymentMethodModelHandler;
use Logingrupa\PayseraShopaholic\Classes\Helper\TestModeVisibility;
use Logingrupa\PayseraShopaholic\Classes\Collection\VisiblePaymentMethodCollection;
use Lovata\OrdersShopaholic\Classes\Collection\PaymentMethodCollection;

/**
 * Paysera (WebToPay) payment gateway for Lovata OrdersShopaholic.
 */
class Plugin extends PluginBase
{
    public $require = [
        'Lovata.Toolbox',
        'Lovata.Shopaholic',
        'Lovata.OrdersShopaholic',
    ];

    public function pluginDetails()
    {
        return [
            'name'        => 'logingrupa.payserashopaholic::lang.plugin.name',
            'description' => 'logingrupa.payserashopaholic::lang.plugin.description',
            'author'      => 'Logingrupa',
            'icon'        => 'icon-credit-card',
            'homepage'    => 'https://github.com/logingrupa/oc-payserashopaholic-plugin',
        ];
    }

    /**
     * Every PaymentMethodCollection the container builds hides test-mode methods from
     * customers: the checkout method list and the retry payment page.
     */
    public function register()
    {
        $this->app->bind(PaymentMethodCollection::class, VisiblePaymentMethodCollection::class);
    }

    public function boot()
    {
        Event::subscribe(ExtendFieldHandler::class);
        Event::subscribe(PaymentMethodModelHandler::class);
        Event::listen('cms.page.init', fn () => TestModeVisibility::rememberQueryFlag());
    }
}
