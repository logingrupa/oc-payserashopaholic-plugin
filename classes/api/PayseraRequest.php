<?php namespace Logingrupa\PayseraShopaholic\Classes\Api;

use InvalidArgumentException;

/**
 * Builds the signed Paysera checkout redirect (WebToPay specification).
 * Paysera accepts the request as GET with `data` and `sign` query parameters.
 * Field limits follow paysera/lib-webtopay RequestBuilder::REQUEST_SPECS.
 */
class PayseraRequest
{
    const PAY_URL = 'https://bank.paysera.com/pay/';
    const API_VERSION = '1.8';
    const ORDER_ID_MAX_LENGTH = 40;
    const URL_MAX_LENGTH = 255;
    const TEXT_MAX_LENGTH = 255;
    const REQUIRED_PARAM_LIST = ['projectid', 'orderid', 'accepturl', 'cancelurl', 'callbackurl'];
    const URL_PARAM_LIST = ['accepturl', 'cancelurl', 'callbackurl'];
    const TEXT_PARAM_LIST = ['paytext', 'p_firstname', 'p_lastname', 'p_email', 'p_street', 'p_city'];

    /**
     * @param array  $arParamList Paysera request parameters (projectid, orderid, amount in cents, ...)
     * @param string $sPassword   Paysera project sign password
     * @return string
     * @throws InvalidArgumentException
     */
    public static function buildRedirectUrl(array $arParamList, string $sPassword): string
    {
        $sData = self::encodeData($arParamList);
        $arQuery = [
            'data' => $sData,
            'sign' => self::sign($sData, $sPassword),
        ];

        return self::PAY_URL . '?' . http_build_query($arQuery, '', '&');
    }

    /**
     * @param array $arParamList
     * @return string base64url encoded query string
     * @throws InvalidArgumentException
     */
    public static function encodeData(array $arParamList): string
    {
        $arParamList = array_filter($arParamList, function ($sValue) {
            return $sValue !== null && $sValue !== '';
        });
        $arParamList['version'] = self::API_VERSION;

        foreach (self::REQUIRED_PARAM_LIST as $sName) {
            if (empty($arParamList[$sName])) {
                throw new InvalidArgumentException("Paysera parameter [{$sName}] is required");
            }
        }

        if (strlen((string) $arParamList['orderid']) > self::ORDER_ID_MAX_LENGTH) {
            throw new InvalidArgumentException('Paysera orderid exceeds ' . self::ORDER_ID_MAX_LENGTH . ' characters');
        }

        foreach (self::URL_PARAM_LIST as $sName) {
            if (strlen((string) $arParamList[$sName]) > self::URL_MAX_LENGTH) {
                throw new InvalidArgumentException("Paysera parameter [{$sName}] exceeds " . self::URL_MAX_LENGTH . ' characters');
            }
        }

        // Free text from the order is cut to the limit instead of failing the checkout
        foreach (self::TEXT_PARAM_LIST as $sName) {
            if (isset($arParamList[$sName])) {
                $arParamList[$sName] = mb_substr((string) $arParamList[$sName], 0, self::TEXT_MAX_LENGTH);
            }
        }

        return self::base64UrlEncode(http_build_query($arParamList, '', '&'));
    }

    /**
     * SS1 signature: md5 of the encoded data and the project password.
     * @param string $sData
     * @param string $sPassword
     * @return string
     */
    public static function sign(string $sData, string $sPassword): string
    {
        return md5($sData . $sPassword);
    }

    public static function base64UrlEncode(string $sValue): string
    {
        return strtr(base64_encode($sValue), '+/', '-_');
    }

    /**
     * Strict decode: anything outside the alphabet yields an empty string.
     */
    public static function base64UrlDecode(string $sValue): string
    {
        $sDecoded = base64_decode(strtr($sValue, '-_', '+/'), true);

        return $sDecoded === false ? '' : $sDecoded;
    }
}
