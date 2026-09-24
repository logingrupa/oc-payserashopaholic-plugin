<?php namespace Logingrupa\PayseraShopaholic\Classes\Event;

use Lovata\Toolbox\Classes\Event\AbstractBackendFieldHandler;
use Lovata\OrdersShopaholic\Models\PaymentMethod;
use Lovata\OrdersShopaholic\Controllers\PaymentMethods;
use Logingrupa\PayseraShopaholic\Classes\Helper\PayseraCheckoutPaymentGateway;
use Logingrupa\PayseraShopaholic\Classes\Helper\PayseraPaymentGateway;

/**
 * Adds the credential fields of both Paysera gateways to the payment method form.
 */
class ExtendFieldHandler extends AbstractBackendFieldHandler
{
    const TAB = 'lovata.ordersshopaholic::lang.tab.gateway';
    const LANG = 'logingrupa.payserashopaholic::lang.field.';

    const COUNTRY_OPTIONS = [
        ''   => 'logingrupa.payserashopaholic::lang.field.country_any',
        'LV' => 'Latvija',
        'LT' => 'Lietuva',
        'EE' => 'Eesti',
    ];

    /**
     * @param \Backend\Widgets\Form $obWidget
     */
    protected function extendFields($obWidget)
    {
        $obWidget->addTabFields($this->classicFields() + $this->checkoutFields());
    }

    protected function classicFields(): array
    {
        $arTrigger = $this->trigger(PayseraPaymentGateway::CODE);

        return [
            'gateway_property[project_id]' => $this->field('project_id', 'text', 'left', $arTrigger),
            'gateway_property[password]'   => $this->field('password', 'sensitive', 'right', $arTrigger),
            'gateway_property[test_mode]'  => $this->field('test_mode', 'switch', 'left', $arTrigger),
            'gateway_property[country]'    => $this->field('country', 'dropdown', 'right', $arTrigger) + ['options' => self::COUNTRY_OPTIONS],
            'paysera_callback_hint'        => $this->hint('_callback_hint.htm', $arTrigger),
        ];
    }

    protected function checkoutFields(): array
    {
        $arTrigger = $this->trigger(PayseraCheckoutPaymentGateway::CODE);

        return [
            'gateway_property[client_id]'        => $this->field('client_id', 'text', 'left', $arTrigger),
            'gateway_property[client_secret]'    => $this->field('client_secret', 'sensitive', 'right', $arTrigger),
            'gateway_property[checkout_test_mode]' => $this->field('checkout_test_mode', 'switch', 'left', $arTrigger),
            'gateway_property[checkout_country]' => $this->field('checkout_country', 'dropdown', 'right', $arTrigger) + ['options' => self::COUNTRY_OPTIONS],
            'paysera_checkout_hint'              => $this->hint('_checkout_hint.htm', $arTrigger),
        ];
    }

    protected function field(string $sName, string $sType, string $sSpan, array $arTrigger): array
    {
        return [
            'label'   => self::LANG . $sName,
            'comment' => self::LANG . $sName . '_comment',
            'tab'     => self::TAB,
            'type'    => $sType,
            'span'    => $sSpan,
            'trigger' => $arTrigger,
        ];
    }

    protected function hint(string $sPartial, array $arTrigger): array
    {
        return [
            'tab'     => self::TAB,
            'type'    => 'partial',
            'path'    => '$/logingrupa/payserashopaholic/partials/' . $sPartial,
            'span'    => 'full',
            'trigger' => $arTrigger,
        ];
    }

    protected function trigger(string $sGatewayCode): array
    {
        return [
            'action'    => 'show',
            'field'     => 'gateway_id',
            'condition' => 'value[' . $sGatewayCode . ']',
        ];
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
