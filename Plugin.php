<?php namespace Logingrupa\PayseraShopaholic;

use Event;
use System\Classes\PluginBase;
use Logingrupa\PayseraShopaholic\Classes\Event\ExtendFieldHandler;
use Logingrupa\PayseraShopaholic\Classes\Event\PaymentMethodModelHandler;

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

    public function boot()
    {
        Event::subscribe(ExtendFieldHandler::class);
        Event::subscribe(PaymentMethodModelHandler::class);
    }
}
