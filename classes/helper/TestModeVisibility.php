<?php namespace Logingrupa\PayseraShopaholic\Classes\Helper;

use BackendAuth;
use Request;
use Session;
use Lovata\OrdersShopaholic\Models\PaymentMethod;

/**
 * A Paysera payment method in test mode is offered at checkout only to visitors
 * who are logged into the October backend, or who opened a shop page with
 * ?paysera_test=1 (kept in their session until ?paysera_test=0), so a project
 * under Paysera review can be exercised on the live shop without customers seeing it.
 */
class TestModeVisibility
{
    // gateway code => gateway_property flag that marks the method as test
    const TEST_FLAG_MAP = [
        PayseraPaymentGateway::CODE         => 'test_mode',
        PayseraCheckoutPaymentGateway::CODE => 'checkout_test_mode',
    ];

    const QUERY_FLAG = 'paysera_test';
    const SESSION_KEY = 'logingrupa.payserashopaholic.test_visible';

    protected static ?array $arHiddenIdList = null;

    /**
     * Grants (?paysera_test=1) or revokes (?paysera_test=0) test method visibility
     * for the rest of the session; any other value leaves the session untouched.
     */
    public static function rememberQueryFlag(): void
    {
        $sFlag = Request::query(self::QUERY_FLAG);
        if ($sFlag === '1') {
            Session::put(self::SESSION_KEY, true);
        } elseif ($sFlag === '0') {
            Session::forget(self::SESSION_KEY);
        } else {
            return;
        }

        self::reset();
    }

    public static function canSeeTestMethods(): bool
    {
        return BackendAuth::check() || Session::get(self::SESSION_KEY) === true;
    }

    /**
     * @return int[] payment method ids to hide from the current visitor
     */
    public static function hiddenIdList(): array
    {
        if (self::$arHiddenIdList !== null) {
            return self::$arHiddenIdList;
        }

        if (self::canSeeTestMethods()) {
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
