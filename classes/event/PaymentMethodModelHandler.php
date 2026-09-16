<?php namespace Logingrupa\PayseraShopaholic\Classes\Event;

use Lovata\OrdersShopaholic\Models\PaymentMethod;
use Logingrupa\PayseraShopaholic\Classes\Helper\PayseraCheckoutPaymentGateway;
use Logingrupa\PayseraShopaholic\Classes\Helper\PayseraPaymentGateway;

/**
 * Registers both Paysera gateways on the PaymentMethod model.
 */
class PaymentMethodModelHandler
{
    const RULES = [
        PayseraPaymentGateway::CODE         => [
            'gateway_property.project_id' => 'required',
            'gateway_property.password'   => 'required',
        ],
        PayseraCheckoutPaymentGateway::CODE => [
            'gateway_property.client_id'     => 'required',
            'gateway_property.client_secret' => 'required',
        ],
    ];

    /**
     * @param \Illuminate\Events\Dispatcher $obEvent
     */
    public function subscribe($obEvent)
    {
        $obEvent->listen(PaymentMethod::EVENT_GET_GATEWAY_LIST, function () {
            return [
                PayseraPaymentGateway::CODE         => 'Paysera Classic (WebToPay)',
                PayseraCheckoutPaymentGateway::CODE => 'Paysera Checkout',
            ];
        });

        PaymentMethod::extend(function ($obPaymentMethod) {
            /** @var PaymentMethod $obPaymentMethod */
            $obPaymentMethod->addGatewayClass(PayseraPaymentGateway::CODE, PayseraPaymentGateway::class);
            $obPaymentMethod->addGatewayClass(PayseraCheckoutPaymentGateway::CODE, PayseraCheckoutPaymentGateway::class);

            $obPaymentMethod->bindEvent('model.beforeValidate', function () use ($obPaymentMethod) {
                $this->addValidationRules($obPaymentMethod);
            });
        });
    }

    /**
     * @param PaymentMethod $obPaymentMethod
     */
    protected function addValidationRules($obPaymentMethod)
    {
        $arRules = self::RULES[$obPaymentMethod->gateway_id] ?? null;
        if ($arRules === null) {
            return;
        }

        $obPaymentMethod->rules = array_merge($obPaymentMethod->rules, $arRules, [
            'gateway_currency' => 'required|alpha|size:3',
        ]);

        $arAttributeNames = [];
        foreach (array_keys($arRules) as $sField) {
            $arAttributeNames[$sField] = 'logingrupa.payserashopaholic::lang.field.' . substr($sField, strlen('gateway_property.'));
        }
        $obPaymentMethod->attributeNames = array_merge($obPaymentMethod->attributeNames, $arAttributeNames);
    }
}
