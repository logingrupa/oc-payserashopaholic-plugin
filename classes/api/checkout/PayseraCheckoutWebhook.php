<?php namespace Logingrupa\PayseraShopaholic\Classes\Api\Checkout;

use Logingrupa\PayseraShopaholic\Classes\Api\PayseraCallbackException;

/**
 * Verifies and reads a Paysera Checkout (Modern) webhook: HMAC-SHA256 of the raw
 * body keyed by the OAuth client secret, hex in X-Paysera-Signature.
 */
class PayseraCheckoutWebhook
{
    const HEADER_SIGNATURE = 'x-paysera-signature';
    const HEADER_ALGORITHM = 'x-paysera-signature-alg';
    const HEADER_CALLBACK_ID = 'x-paysera-callback-id';
    const ALGORITHM = 'HMAC-SHA256';

    const EVENT_ORDER_AMOUNT_PAID = 'order:amount_paid_updated';
    const ORDER_STATUS_PAID = 'paid';

    /**
     * @param string $sBody raw request body
     * @return array decoded payload
     * @throws PayseraCallbackException 400 when the body is not a JSON object
     */
    public static function decode(string $sBody): array
    {
        $arPayload = json_decode($sBody, true);
        if (!is_array($arPayload) || empty($arPayload['event'])) {
            throw new PayseraCallbackException('Paysera webhook body is not a valid event', 400);
        }

        return $arPayload;
    }

    /**
     * @param string $sBody     raw request body, exactly as received
     * @param array  $arHeaders request headers, name => value or name => [values]
     * @param string $sSecret   OAuth client secret of the payment method
     * @throws PayseraCallbackException 401 when the signature does not verify
     */
    public static function verify(string $sBody, array $arHeaders, string $sSecret): void
    {
        $sSignature = strtolower(self::header($arHeaders, self::HEADER_SIGNATURE));
        $sAlgorithm = strtoupper(self::header($arHeaders, self::HEADER_ALGORITHM));

        if ($sAlgorithm !== self::ALGORITHM || !preg_match('/^[0-9a-f]{64}$/', $sSignature)) {
            throw new PayseraCallbackException('Paysera webhook signature header is missing or malformed', 401);
        }

        if ($sSecret === '' || !hash_equals(hash_hmac('sha256', $sBody, $sSecret), $sSignature)) {
            throw new PayseraCallbackException('Paysera webhook signature is invalid', 401);
        }
    }

    /**
     * @param array $arPayload
     * @return string "type:name", e.g. order:amount_paid_updated
     */
    public static function eventKey(array $arPayload): string
    {
        return (string) ($arPayload['event']['type'] ?? '') . ':' . (string) ($arPayload['event']['name'] ?? '');
    }

    /**
     * Case-insensitive header read; Laravel hands arrays of values.
     * @param array  $arHeaders
     * @param string $sName lowercase header name
     * @return string
     */
    public static function header(array $arHeaders, string $sName): string
    {
        $arNormalized = array_change_key_case($arHeaders, CASE_LOWER);
        $mValue = $arNormalized[$sName] ?? '';

        return trim((string) (is_array($mValue) ? ($mValue[0] ?? '') : $mValue));
    }
}
