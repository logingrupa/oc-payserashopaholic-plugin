<?php

use PHPUnit\Framework\TestCase;
use Logingrupa\PayseraShopaholic\Classes\Api\PayseraRequest;

final class PayseraRequestTest extends TestCase
{
    const PASSWORD = 'sign-password';

    private function params(): array
    {
        return [
            'projectid'   => '12345',
            'orderid'     => '777',
            'amount'      => 1234,
            'currency'    => 'EUR',
            'accepturl'   => 'https://nc.test/lv/checkout/abc',
            'cancelurl'   => 'https://nc.test/lv/checkout/abc',
            'callbackurl' => 'https://nc.test/paysera/callback',
            'test'        => '1',
            'country'     => '',
            'p_lastname'  => null,
        ];
    }

    public function test_redirect_url_targets_paysera_with_signed_data(): void
    {
        $sUrl = PayseraRequest::buildRedirectUrl($this->params(), self::PASSWORD);

        $this->assertStringStartsWith(PayseraRequest::PAY_URL . '?data=', $sUrl);

        parse_str((string) parse_url($sUrl, PHP_URL_QUERY), $arQuery);
        $this->assertSame(md5($arQuery['data'] . self::PASSWORD), $arQuery['sign']);

        parse_str(PayseraRequest::base64UrlDecode($arQuery['data']), $arData);
        $this->assertSame('12345', $arData['projectid']);
        $this->assertSame('777', $arData['orderid']);
        $this->assertSame('1234', $arData['amount']);
        $this->assertSame(PayseraRequest::API_VERSION, $arData['version']);
        $this->assertArrayNotHasKey('country', $arData);
        $this->assertArrayNotHasKey('p_lastname', $arData);
    }

    public function test_missing_required_parameter_throws(): void
    {
        $arParams = $this->params();
        unset($arParams['callbackurl']);

        $this->expectException(InvalidArgumentException::class);
        PayseraRequest::encodeData($arParams);
    }

    public function test_long_order_id_throws(): void
    {
        $arParams = $this->params();
        $arParams['orderid'] = str_repeat('9', PayseraRequest::ORDER_ID_MAX_LENGTH + 1);

        $this->expectException(InvalidArgumentException::class);
        PayseraRequest::encodeData($arParams);
    }

    public function test_long_return_url_throws(): void
    {
        $arParams = $this->params();
        $arParams['accepturl'] = 'https://nc.test/' . str_repeat('a', PayseraRequest::URL_MAX_LENGTH);

        $this->expectException(InvalidArgumentException::class);
        PayseraRequest::encodeData($arParams);
    }

    public function test_free_text_is_cut_to_the_limit(): void
    {
        $arParams = $this->params();
        $arParams['p_firstname'] = str_repeat('ā', PayseraRequest::TEXT_MAX_LENGTH + 5);

        parse_str(PayseraRequest::base64UrlDecode(PayseraRequest::encodeData($arParams)), $arData);

        $this->assertSame(PayseraRequest::TEXT_MAX_LENGTH, mb_strlen($arData['p_firstname']));
    }

    public function test_strict_decode_rejects_garbage(): void
    {
        $this->assertSame('', PayseraRequest::base64UrlDecode('not base64!'));
    }

    public function test_base64url_round_trip_is_url_safe(): void
    {
        $sRaw = random_bytes(96);
        $sEncoded = PayseraRequest::base64UrlEncode($sRaw);

        $this->assertMatchesRegularExpression('/^[A-Za-z0-9_=-]+$/', $sEncoded);
        $this->assertSame($sRaw, PayseraRequest::base64UrlDecode($sEncoded));
    }
}
