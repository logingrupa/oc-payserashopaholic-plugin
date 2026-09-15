<?php namespace Logingrupa\PayseraShopaholic\Classes\Api;

use Cache;

/**
 * Decodes and verifies a Paysera callback, mirroring paysera/lib-webtopay 3.1:
 * signed mode carries `data` plus ss1/ss2/ss3, encrypted mode carries only `data`
 * as AES-256-GCM keyed by the project password. The strongest RSA signature
 * present (ss3 SHA-256, else ss2 SHA-1) is verified with the Paysera public key,
 * ss1 (md5 with the password) is always required in signed mode.
 */
class PayseraCallback
{
    const PUBLIC_KEY_URL = 'https://www.paysera.com/download/public.key';
    const PUBLIC_KEY_CACHE_KEY = 'logingrupa.paysera.public_key';
    const PUBLIC_KEY_CACHE_SECONDS = 86400;

    const GCM_CIPHER = 'aes-256-gcm';
    const GCM_TAG_LENGTH = 16;

    // Preferred signature first
    const RSA_SIGN_ALGO_MAP = [
        'ss3' => OPENSSL_ALGO_SHA256,
        'ss2' => OPENSSL_ALGO_SHA1,
    ];

    const STATUS_NOT_EXECUTED = '0';
    const STATUS_PAID = '1';
    const STATUS_PENDING = '2';
    const STATUS_ADDITIONAL_INFO = '3';
    const STATUS_EXECUTED_UNCONFIRMED = '4';

    const TYPE_MACRO = 'macro';

    /**
     * @param array $arQuery raw request parameters
     * @return bool true when any signature parameter is present (signed mode)
     */
    public static function isSigned(array $arQuery): bool
    {
        return isset($arQuery['ss1']) || isset($arQuery['ss2']) || isset($arQuery['ss3']);
    }

    /**
     * Decode signed-mode `data` without verifying it. Use only to find the order
     * whose password then verifies the callback.
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
     * Decrypt encrypted-mode `data` (iv, ciphertext, 16 byte tag) with the project password.
     * @param string $sData
     * @param string $sPassword
     * @return array|null null when the password does not fit
     */
    public static function decrypt(string $sData, string $sPassword): ?array
    {
        $sRaw = PayseraRequest::base64UrlDecode($sData);
        $iIvLength = (int) openssl_cipher_iv_length(self::GCM_CIPHER);
        if ($sPassword === '' || strlen($sRaw) <= $iIvLength + self::GCM_TAG_LENGTH) {
            return null;
        }

        $sPlain = openssl_decrypt(
            substr($sRaw, $iIvLength, -self::GCM_TAG_LENGTH),
            self::GCM_CIPHER,
            $sPassword,
            OPENSSL_RAW_DATA,
            substr($sRaw, 0, $iIvLength),
            substr($sRaw, -self::GCM_TAG_LENGTH)
        );
        if ($sPlain === false) {
            return null;
        }

        $arResult = [];
        parse_str($sPlain, $arResult);

        return $arResult;
    }

    /**
     * Verify a signed-mode callback and return its parameters.
     * @param array       $arQuery    raw request parameters
     * @param string      $sPassword  Paysera project sign password
     * @param string|null $sPublicKey PEM key for tests; null downloads the Paysera key
     * @return array
     * @throws PayseraCallbackException 403 on a bad signature, 503 when the key cannot be fetched
     */
    public static function parse(array $arQuery, string $sPassword, ?string $sPublicKey = null): array
    {
        $sData = (string) ($arQuery['data'] ?? '');
        $sSs1 = (string) ($arQuery['ss1'] ?? '');

        if ($sData === '' || $sSs1 === '') {
            throw new PayseraCallbackException('Paysera callback is missing data or ss1', 403);
        }

        if (!hash_equals(PayseraRequest::sign($sData, $sPassword), $sSs1)) {
            throw new PayseraCallbackException('Paysera callback ss1 signature is invalid', 403);
        }

        foreach (self::RSA_SIGN_ALGO_MAP as $sField => $iAlgo) {
            if (empty($arQuery[$sField])) {
                continue;
            }

            self::assertRsaSignature($sData, (string) $arQuery[$sField], $iAlgo, $sField, $sPublicKey);
            break;
        }

        return self::decode($sData);
    }

    /**
     * @param string $sData
     * @param string $sSignature base64url RSA signature
     * @param string $sPublicKey PEM public key
     * @param int    $iAlgo      OPENSSL_ALGO_* constant
     * @return bool
     */
    public static function verifyRsa(string $sData, string $sSignature, string $sPublicKey, int $iAlgo): bool
    {
        $obKey = openssl_pkey_get_public($sPublicKey);
        if ($obKey === false) {
            return false;
        }

        return openssl_verify($sData, PayseraRequest::base64UrlDecode($sSignature), $obKey, $iAlgo) === 1;
    }

    /**
     * Paysera public key, cached for a day. A failed download is not cached.
     * @param bool $bFresh drop the cached key first (key rotation)
     * @return string|null
     */
    public static function publicKey(bool $bFresh = false): ?string
    {
        if ($bFresh) {
            Cache::forget(self::PUBLIC_KEY_CACHE_KEY);
        }

        $sCached = Cache::get(self::PUBLIC_KEY_CACHE_KEY);
        if (is_string($sCached) && $sCached !== '') {
            return $sCached;
        }

        $obContext = stream_context_create(['http' => ['timeout' => 5]]);
        $sBody = @file_get_contents(self::PUBLIC_KEY_URL, false, $obContext);
        if (!is_string($sBody) || !str_contains($sBody, '-----BEGIN')) {
            return null;
        }

        Cache::put(self::PUBLIC_KEY_CACHE_KEY, $sBody, self::PUBLIC_KEY_CACHE_SECONDS);

        return $sBody;
    }

    /**
     * Verify with the given key, or with the cached Paysera key and once more with a
     * freshly downloaded one so a rotated key does not reject callbacks for a day.
     * @throws PayseraCallbackException
     */
    protected static function assertRsaSignature(string $sData, string $sSignature, int $iAlgo, string $sField, ?string $sPublicKey)
    {
        if ($sPublicKey !== null) {
            if (!self::verifyRsa($sData, $sSignature, $sPublicKey, $iAlgo)) {
                throw new PayseraCallbackException("Paysera callback {$sField} signature is invalid", 403);
            }

            return;
        }

        foreach ([false, true] as $bFresh) {
            $sKey = self::publicKey($bFresh);
            if ($sKey !== null && self::verifyRsa($sData, $sSignature, $sKey, $iAlgo)) {
                return;
            }
        }

        if ($sKey === null) {
            throw new PayseraCallbackException('Paysera public key is unavailable', 503);
        }

        throw new PayseraCallbackException("Paysera callback {$sField} signature is invalid", 403);
    }
}
