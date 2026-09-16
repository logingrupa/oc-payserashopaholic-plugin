<?php namespace Logingrupa\PayseraShopaholic\Classes\Api\Checkout;

use Cache;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Minimal client for the Paysera Checkout (Modern) integration API:
 * OAuth2 client credentials token, create order, create payment link, get order.
 * Amounts are integer minor units. Endpoints as in paysera/lib-checkout-integration-sdk 3.2.
 */
class PayseraCheckoutClient
{
    const BASE_URL = 'https://api.paysera.com';
    const TOKEN_PATH = '/auth/realms/Paysera/protocol/openid-connect/token';
    const ORDERS_PATH = '/merchant-order/integration/v1/orders';
    const PAYMENT_LINKS_PATH = '/checkout-payment-link/integration/v1/payment-links';

    const TOKEN_CACHE_PREFIX = 'logingrupa.paysera.checkout.token.';
    // Refresh this long before the token's expires_in, as the docs advise
    const TOKEN_EXPIRY_MARGIN = 300;
    const TIMEOUT_SECONDS = 10;

    public function __construct(protected string $sClientId, protected string $sClientSecret)
    {
    }

    /**
     * @param array $arPayload create order request body
     * @return array response body (order_id, is_test, purchase, ...)
     * @throws PayseraCheckoutException
     */
    public function createOrder(array $arPayload): array
    {
        return $this->request('post', self::ORDERS_PATH, $arPayload);
    }

    /**
     * @param array $arPayload create payment link request body
     * @return array response body (link_id, payment_URL, expired_at, ...)
     * @throws PayseraCheckoutException
     */
    public function createPaymentLink(array $arPayload): array
    {
        return $this->request('post', self::PAYMENT_LINKS_PATH, $arPayload);
    }

    /**
     * @param string $sOrderId Paysera order UUID
     * @return array order (status, amount, amount_paid, currency, ...)
     * @throws PayseraCheckoutException
     */
    public function getOrder(string $sOrderId): array
    {
        return $this->request('get', self::ORDERS_PATH . '/' . rawurlencode($sOrderId));
    }

    /**
     * Send an authenticated JSON request; one retry with a fresh token on 401.
     * @throws PayseraCheckoutException
     */
    protected function request(string $sMethod, string $sPath, array $arPayload = []): array
    {
        $obResponse = $this->send($sMethod, $sPath, $arPayload, $this->accessToken());
        if ($obResponse->status() === 401) {
            $obResponse = $this->send($sMethod, $sPath, $arPayload, $this->accessToken(true));
        }

        if (!$obResponse->successful()) {
            throw new PayseraCheckoutException(self::errorMessage($obResponse), $obResponse->status());
        }

        return (array) $obResponse->json();
    }

    protected function send(string $sMethod, string $sPath, array $arPayload, string $sToken): Response
    {
        try {
            return Http::withToken($sToken)
                ->acceptJson()
                ->timeout(self::TIMEOUT_SECONDS)
                ->{$sMethod}(self::BASE_URL . $sPath, $arPayload);
        } catch (ConnectionException $obException) {
            throw new PayseraCheckoutException('Paysera Checkout API unreachable: ' . $obException->getMessage(), 0);
        }
    }

    /**
     * @param bool $bFresh discard the cached token first
     * @return string bearer token
     * @throws PayseraCheckoutException
     */
    protected function accessToken(bool $bFresh = false): string
    {
        $sCacheKey = self::TOKEN_CACHE_PREFIX . sha1($this->sClientId);
        if ($bFresh) {
            Cache::forget($sCacheKey);
        }

        $sCached = Cache::get($sCacheKey);
        if (is_string($sCached) && $sCached !== '') {
            return $sCached;
        }

        try {
            $obResponse = Http::asForm()->acceptJson()->timeout(self::TIMEOUT_SECONDS)->post(self::BASE_URL . self::TOKEN_PATH, [
                'grant_type'    => 'client_credentials',
                'client_id'     => $this->sClientId,
                'client_secret' => $this->sClientSecret,
            ]);
        } catch (ConnectionException $obException) {
            throw new PayseraCheckoutException('Paysera token endpoint unreachable: ' . $obException->getMessage(), 0);
        }

        $sToken = (string) $obResponse->json('access_token', '');
        if (!$obResponse->successful() || $sToken === '') {
            throw new PayseraCheckoutException('Paysera authentication failed: ' . self::errorMessage($obResponse), $obResponse->status());
        }

        $iTtl = (int) $obResponse->json('expires_in', 3600) - self::TOKEN_EXPIRY_MARGIN;
        if ($iTtl > 0) {
            Cache::put($sCacheKey, $sToken, $iTtl);
        }

        return $sToken;
    }

    protected static function errorMessage(Response $obResponse): string
    {
        $sError = (string) $obResponse->json('error', '');
        $sDescription = (string) $obResponse->json('error_description', '');
        $arProperties = (array) $obResponse->json('error_properties', []);

        $sMessage = trim($sError . ' ' . $sDescription);
        foreach ($arProperties as $sField => $arMessageList) {
            $sMessage .= ' ' . $sField . ': ' . implode(', ', (array) $arMessageList) . ';';
        }

        return $sMessage !== '' ? $sMessage : 'HTTP ' . $obResponse->status();
    }
}
