<?php namespace Logingrupa\PayseraShopaholic\Classes\Event;

use Lovata\Toolbox\Classes\Event\AbstractBackendFieldHandler;
use Lovata\OrdersShopaholic\Models\PaymentMethod;
use Lovata\OrdersShopaholic\Controllers\PaymentMethods;
use Logingrupa\PayseraShopaholic\Classes\Helper\PayseraPaymentGateway;

/**
 * Adds the Paysera credential fields to the payment method form.
 */
class ExtendFieldHandler extends AbstractBackendFieldHandler
{
    const TAB = 'lovata.ordersshopaholic::lang.tab.gateway';

    /**
     * @param \Backend\Widgets\Form $obWidget
     */
    protected function extendFields($obWidget)
    {
        $arTrigger = [
            'action'    => 'show',
            'field'     => 'gateway_id',
            'condition' => 'value[' . PayseraPaymentGateway::CODE . ']',
        ];

        $obWidget->addTabFields([
            'gateway_property[project_id]' => [
                'label'   => 'logingrupa.payserashopaholic::lang.field.project_id',
                'comment' => 'logingrupa.payserashopaholic::lang.field.project_id_comment',
                'tab'     => self::TAB,
                'type'    => 'text',
                'span'    => 'left',
                'trigger' => $arTrigger,
            ],
            'gateway_property[password]' => [
                'label'   => 'logingrupa.payserashopaholic::lang.field.password',
                'comment' => 'logingrupa.payserashopaholic::lang.field.password_comment',
                'tab'     => self::TAB,
                'type'    => 'sensitive',
                'span'    => 'right',
                'trigger' => $arTrigger,
            ],
            'gateway_property[test_mode]' => [
                'label'   => 'logingrupa.payserashopaholic::lang.field.test_mode',
                'comment' => 'logingrupa.payserashopaholic::lang.field.test_mode_comment',
                'tab'     => self::TAB,
                'type'    => 'switch',
                'span'    => 'left',
                'trigger' => $arTrigger,
            ],
            'gateway_property[country]' => [
                'label'   => 'logingrupa.payserashopaholic::lang.field.country',
                'comment' => 'logingrupa.payserashopaholic::lang.field.country_comment',
                'tab'     => self::TAB,
                'type'    => 'dropdown',
                'span'    => 'right',
                'options' => [
                    ''   => 'logingrupa.payserashopaholic::lang.field.country_any',
                    'LV' => 'Latvija',
                    'LT' => 'Lietuva',
                    'EE' => 'Eesti',
                ],
                'trigger' => $arTrigger,
            ],
            'paysera_callback_hint' => [
                'tab'     => self::TAB,
                'type'    => 'partial',
                'path'    => '$/logingrupa/payserashopaholic/partials/_callback_hint.htm',
                'span'    => 'full',
                'trigger' => $arTrigger,
            ],
        ]);
    }

    protected function getModelClass(): string
    {
        return PaymentMethod::class;
    }

    protected function getControllerClass(): string
    {
        return PaymentMethods::class;
    }
}
