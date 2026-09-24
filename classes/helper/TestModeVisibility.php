<?php namespace Logingrupa\PayseraShopaholic\Classes\Helper;

use BackendAuth;
use Lovata\OrdersShopaholic\Models\PaymentMethod;

/**
 * A Paysera payment method in test mode is offered at checkout only to visitors
 * who are logged into the October backend, so a project under Paysera review can
 * be exercised on the live shop without customers seeing it.
 */
class TestModeVisibility
{
    // gateway code => gateway_property flag that marks the method as test
    const TEST_FLAG_MAP = [
        PayseraPaymentGateway::CODE         => 'test_mode',
        PayseraCheckoutPaymentGateway::CODE => 'checkout_test_mode',
    ];

    protected static ?array $arHiddenIdList = null;

    /**
     * @return int[] payment method ids to hide from the current visitor
     */
    public static function hiddenIdList(): array
    {
        if (self::$arHiddenIdList !== null) {
            return self::$arHiddenIdList;
        }

        if (BackendAuth::check()) {
            return self::$arHiddenIdList = [];
        }

        $arHiddenIdList = [];
        $obMethodList = PaymentMethod::whereIn('gateway_id', array_keys(self::TEST_FLAG_MAP))->get();
        foreach ($obMethodList as $obMethod) {
            if (self::isTestMethod((string) $obMethod->gateway_id, (array) $obMethod->gateway_property)) {
                $arHiddenIdList[] = (int) $obMethod->id;
            }
        }

        return self::$arHiddenIdList = $arHiddenIdList;
    }

    /**
     * @param string $sGatewayId        payment method gateway code
     * @param array  $arGatewayProperty decrypted gateway_property
     * @return bool
     */
    public static function isTestMethod(string $sGatewayId, array $arGatewayProperty): bool
    {
        $sFlag = self::TEST_FLAG_MAP[$sGatewayId] ?? null;

        return $sFlag !== null && !empty($arGatewayProperty[$sFlag]);
    }

    public static function reset(): void
    {
        self::$arHiddenIdList = null;
    }
}
