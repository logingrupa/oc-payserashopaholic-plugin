<?php namespace Logingrupa\PayseraShopaholic\Classes\Api;

use Cache;
use RuntimeException;

/**
 * Decodes and verifies a Paysera callback (`data`, `ss1`, `ss2` query parameters).
 * SS1 is required and checked against the project password. SS2 (RSA-SHA1 with the
 * Paysera public key) is checked additionally whenever Paysera sends it.
 */
class PayseraCallback
{
    const PUBLIC_KEY_URL = 'https://www.paysera.com/download/public.key';
    const PUBLIC_KEY_CACHE_KEY = 'logingrupa.paysera.public_key';
    const PUBLIC_KEY_CACHE_SECONDS = 86400;

    const STATUS_NOT_EXECUTED = '0';
    const STATUS_PAID = '1';
    const STATUS_PENDING = '2';
    const STATUS_ADDITIONAL_INFO = '3';

    const TYPE_MACRO = 'macro';

    /**
     * Decode the `data` parameter without verifying it. Use only to look up
     * the order whose password verifies the callback.
     * @param string $sData
     * @return array
     */
    public static function decode(string $sData): array
    {
        $arResult = [];
        parse_str(PayseraRequest::base64UrlDecode($sData), $arResult);

        return $arResult;
    }

    /**
     * Verify signatures and return the decoded callback parameters.
     * @param array       $arQuery    raw request parameters
     * @param string      $sPassword  Paysera project sign password
     * @param string|null $sPublicKey PEM public key, null skips the SS2 check
     * @return array
     * @throws RuntimeException when a signature is missing or invalid
     */
    public static function parse(array $arQuery, string $sPassword, ?string $sPublicKey): array
    {
        $sData = (string) ($arQuery['data'] ?? '');
        $sSs1 = (string) ($arQuery['ss1'] ?? '');
        $sSs2 = (string) ($arQuery['ss2'] ?? '');

        if ($sData === '' || $sSs1 === '') {
            throw new RuntimeException('Paysera callback is missing data or ss1');
        }

        if (!hash_equals(PayseraRequest::sign($sData, $sPassword), $sSs1)) {
            throw new RuntimeException('Paysera callback ss1 signature is invalid');
        }

        if ($sSs2 !== '' && $sPublicKey !== null && !self::verifySs2($sData, $sSs2, $sPublicKey)) {
            throw new RuntimeException('Paysera callback ss2 signature is invalid');
        }

        return self::decode($sData);
    }

    /**
     * @param string $sData      encoded data parameter
     * @param string $sSs2       base64url RSA-SHA1 signature
     * @param string $sPublicKey PEM public key
     * @return bool
     */
    public static function verifySs2(string $sData, string $sSs2, string $sPublicKey): bool
    {
        $obKey = openssl_pkey_get_public($sPublicKey);
        if ($obKey === false) {
            return false;
        }

        return openssl_verify($sData, PayseraRequest::base64UrlDecode($sSs2), $obKey, OPENSSL_ALGO_SHA1) === 1;
    }

    /**
     * Paysera public key, cached for a day. Null when the download fails so the
     * caller can still rely on SS1.
     * @return string|null
     */
    public static function publicKey(): ?string
    {
        $sKey = Cache::remember(self::PUBLIC_KEY_CACHE_KEY, self::PUBLIC_KEY_CACHE_SECONDS, function () {
            $obContext = stream_context_create(['http' => ['timeout' => 5]]);
            $sBody = @file_get_contents(self::PUBLIC_KEY_URL, false, $obContext);

            return is_string($sBody) && str_contains($sBody, 'PUBLIC KEY') ? $sBody : '';
        });

        return $sKey === '' ? null : $sKey;
    }
}
