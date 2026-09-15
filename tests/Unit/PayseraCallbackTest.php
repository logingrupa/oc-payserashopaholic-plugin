<?php

use PHPUnit\Framework\TestCase;
use Logingrupa\PayseraShopaholic\Classes\Api\PayseraCallback;
use Logingrupa\PayseraShopaholic\Classes\Api\PayseraRequest;

final class PayseraCallbackTest extends TestCase
{
    const PASSWORD = 'sign-password';

    private string $sPrivateKey;
    private string $sPublicKey;

    protected function setUp(): void
    {
        // Throwaway RSA pair generated for these tests only, never used anywhere else.
        $this->sPrivateKey = (string) file_get_contents(__DIR__ . '/../fixtures/rsa-private.pem');
        $this->sPublicKey = (string) file_get_contents(__DIR__ . '/../fixtures/rsa-public.pem');
    }

    private function signedQuery(array $arData, bool $bWithSs2 = true, string $sPassword = self::PASSWORD): array
    {
        $sData = PayseraRequest::base64UrlEncode(http_build_query($arData, '', '&'));
        $arQuery = [
            'data' => $sData,
            'ss1'  => PayseraRequest::sign($sData, $sPassword),
        ];

        if ($bWithSs2) {
            openssl_sign($sData, $sSignature, $this->sPrivateKey, OPENSSL_ALGO_SHA1);
            $arQuery['ss2'] = PayseraRequest::base64UrlEncode($sSignature);
        }

        return $arQuery;
    }

    public function test_valid_ss1_and_ss2_return_decoded_data(): void
    {
        $arQuery = $this->signedQuery(['orderid' => '777', 'status' => '1', 'amount' => '1234']);

        $arData = PayseraCallback::parse($arQuery, self::PASSWORD, $this->sPublicKey);

        $this->assertSame('777', $arData['orderid']);
        $this->assertSame(PayseraCallback::STATUS_PAID, $arData['status']);
    }

    public function test_ss1_alone_is_enough_when_paysera_sends_no_ss2(): void
    {
        $arQuery = $this->signedQuery(['orderid' => '777'], false);

        $this->assertSame('777', PayseraCallback::parse($arQuery, self::PASSWORD, $this->sPublicKey)['orderid']);
    }

    public function test_wrong_password_rejects_ss1(): void
    {
        $arQuery = $this->signedQuery(['orderid' => '777'], false, 'other-password');

        $this->expectException(RuntimeException::class);
        PayseraCallback::parse($arQuery, self::PASSWORD, null);
    }

    public function test_tampered_data_rejects_ss2(): void
    {
        $arQuery = $this->signedQuery(['orderid' => '777', 'status' => '0']);
        $sTampered = PayseraRequest::base64UrlEncode(http_build_query(['orderid' => '777', 'status' => '1'], '', '&'));
        $arQuery['data'] = $sTampered;
        $arQuery['ss1'] = PayseraRequest::sign($sTampered, self::PASSWORD);

        $this->expectException(RuntimeException::class);
        PayseraCallback::parse($arQuery, self::PASSWORD, $this->sPublicKey);
    }

    public function test_missing_signature_throws(): void
    {
        $this->expectException(RuntimeException::class);
        PayseraCallback::parse(['data' => 'abc'], self::PASSWORD, null);
    }

    public function test_decode_reads_unverified_order_id(): void
    {
        $arQuery = $this->signedQuery(['orderid' => '42'], false);

        $this->assertSame('42', PayseraCallback::decode($arQuery['data'])['orderid']);
    }
}
