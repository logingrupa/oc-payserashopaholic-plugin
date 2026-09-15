<?php namespace Logingrupa\PayseraShopaholic\Classes\Event;

use Lovata\OrdersShopaholic\Models\PaymentMethod;
use Logingrupa\PayseraShopaholic\Classes\Helper\PayseraPaymentGateway;

/**
 * Registers the Paysera gateway on the PaymentMethod model.
 */
class PaymentMethodModelHandler
{
    /**
     * @param \Illuminate\Events\Dispatcher $obEvent
     */
    public function subscribe($obEvent)
    {
        $obEvent->listen(PaymentMethod::EVENT_GET_GATEWAY_LIST, function () {
            return [PayseraPaymentGateway::CODE => 'Paysera'];
        });

        PaymentMethod::extend(function ($obPaymentMethod) {
            /** @var PaymentMethod $obPaymentMethod */
            $obPaymentMethod->addGatewayClass(PayseraPaymentGateway::CODE, PayseraPaymentGateway::class);

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
        if ($obPaymentMethod->gateway_id !== PayseraPaymentGateway::CODE) {
            return;
        }

        $obPaymentMethod->rules = array_merge($obPaymentMethod->rules, [
            'gateway_property.project_id' => 'required',
            'gateway_property.password'   => 'required',
            'gateway_currency'            => 'required|alpha|size:3',
        ]);

        $obPaymentMethod->attributeNames = array_merge($obPaymentMethod->attributeNames, [
            'gateway_property.project_id' => 'logingrupa.payserashopaholic::lang.field.project_id',
            'gateway_property.password'   => 'logingrupa.payserashopaholic::lang.field.password',
        ]);
    }
}
