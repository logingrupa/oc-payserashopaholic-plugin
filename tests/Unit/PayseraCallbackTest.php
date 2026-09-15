<?php

use PHPUnit\Framework\TestCase;
use Logingrupa\PayseraShopaholic\Classes\Api\PayseraCallback;
use Logingrupa\PayseraShopaholic\Classes\Api\PayseraCallbackException;
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

    private function encodeData(array $arData): string
    {
        return PayseraRequest::base64UrlEncode(http_build_query($arData, '', '&'));
    }

    private function rsaSign(string $sData, int $iAlgo): string
    {
        openssl_sign($sData, $sSignature, $this->sPrivateKey, $iAlgo);

        return PayseraRequest::base64UrlEncode($sSignature);
    }

    /**
     * @param string[] $arRsaFields which of ss2, ss3 to attach
     */
    private function signedQuery(array $arData, array $arRsaFields = ['ss2', 'ss3'], string $sPassword = self::PASSWORD): array
    {
        $sData = $this->encodeData($arData);
        $arQuery = ['data' => $sData, 'ss1' => PayseraRequest::sign($sData, $sPassword)];

        foreach ($arRsaFields as $sField) {
            $arQuery[$sField] = $this->rsaSign($sData, PayseraCallback::RSA_SIGN_ALGO_MAP[$sField]);
        }

        return $arQuery;
    }

    private function encryptedQuery(array $arData, string $sPassword = self::PASSWORD): array
    {
        $sIv = random_bytes((int) openssl_cipher_iv_length(PayseraCallback::GCM_CIPHER));
        $sTag = '';
        $sCipher = openssl_encrypt(http_build_query($arData, '', '&'), PayseraCallback::GCM_CIPHER, $sPassword, OPENSSL_RAW_DATA, $sIv, $sTag, '', PayseraCallback::GCM_TAG_LENGTH);

        return ['data' => PayseraRequest::base64UrlEncode($sIv . $sCipher . $sTag)];
    }

    public function test_valid_ss1_ss2_and_ss3_return_decoded_data(): void
    {
        $arQuery = $this->signedQuery(['orderid' => '777', 'status' => '1', 'amount' => '1234']);

        $arData = PayseraCallback::parse($arQuery, self::PASSWORD, $this->sPublicKey);

        $this->assertSame('777', $arData['orderid']);
        $this->assertSame(PayseraCallback::STATUS_PAID, $arData['status']);
    }

    public function test_ss2_only_is_verified_with_sha1(): void
    {
        $arQuery = $this->signedQuery(['orderid' => '777'], ['ss2']);

        $this->assertSame('777', PayseraCallback::parse($arQuery, self::PASSWORD, $this->sPublicKey)['orderid']);
    }

    public function test_ss3_only_is_verified_with_sha256(): void
    {
        $arQuery = $this->signedQuery(['orderid' => '777'], ['ss3']);

        $this->assertSame('777', PayseraCallback::parse($arQuery, self::PASSWORD, $this->sPublicKey)['orderid']);
    }

    public function test_ss1_alone_is_refused(): void
    {
        $arQuery = $this->signedQuery(['orderid' => '777'], []);

        try {
            PayseraCallback::parse($arQuery, self::PASSWORD, $this->sPublicKey);
            $this->fail('expected rejection');
        } catch (PayseraCallbackException $obException) {
            $this->assertSame(403, $obException->getCode());
        }
    }

    public function test_wrong_password_rejects_ss1_with_403(): void
    {
        $arQuery = $this->signedQuery(['orderid' => '777'], ['ss3'], 'other-password');

        try {
            PayseraCallback::parse($arQuery, self::PASSWORD, $this->sPublicKey);
            $this->fail('expected rejection');
        } catch (PayseraCallbackException $obException) {
            $this->assertSame(403, $obException->getCode());
        }
    }

    public function test_tampered_data_rejects_rsa_signature(): void
    {
        $arQuery = $this->signedQuery(['orderid' => '777', 'status' => '0']);
        $sTampered = $this->encodeData(['orderid' => '777', 'status' => '1']);
        $arQuery['data'] = $sTampered;
        $arQuery['ss1'] = PayseraRequest::sign($sTampered, self::PASSWORD);

        $this->expectException(PayseraCallbackException::class);
        PayseraCallback::parse($arQuery, self::PASSWORD, $this->sPublicKey);
    }

    public function test_ss3_is_preferred_over_ss2(): void
    {
        $arQuery = $this->signedQuery(['orderid' => '777']);
        $arQuery['ss2'] = PayseraRequest::base64UrlEncode(random_bytes(256));

        $this->assertSame('777', PayseraCallback::parse($arQuery, self::PASSWORD, $this->sPublicKey)['orderid']);
    }

    public function test_wrong_key_rejects_rsa_signature(): void
    {
        $arQuery = $this->signedQuery(['orderid' => '777']);

        $this->expectException(PayseraCallbackException::class);
        PayseraCallback::parse($arQuery, self::PASSWORD, "-----BEGIN PUBLIC KEY-----\nnot a key\n-----END PUBLIC KEY-----\n");
    }

    public function test_missing_signature_throws(): void
    {
        $this->expectException(PayseraCallbackException::class);
        PayseraCallback::parse(['data' => 'abc'], self::PASSWORD, null);
    }

    public function test_decode_reads_unverified_order_id(): void
    {
        $arQuery = $this->signedQuery(['orderid' => '42'], []);

        $this->assertSame('42', PayseraCallback::decode($arQuery['data'])['orderid']);
    }

    public function test_is_signed_detects_mode(): void
    {
        $this->assertTrue(PayseraCallback::isSigned(['data' => 'x', 'ss1' => 'y']));
        $this->assertTrue(PayseraCallback::isSigned(['data' => 'x', 'ss3' => 'y']));
        $this->assertFalse(PayseraCallback::isSigned(['data' => 'x']));
    }

    public function test_encrypted_callback_decrypts_with_the_project_password(): void
    {
        $arQuery = $this->encryptedQuery(['orderid' => '777', 'status' => '1']);

        $arData = PayseraCallback::decrypt($arQuery['data'], self::PASSWORD);

        $this->assertSame('777', $arData['orderid']);
        $this->assertSame('1', $arData['status']);
    }

    public function test_encrypted_callback_with_wrong_password_returns_null(): void
    {
        $arQuery = $this->encryptedQuery(['orderid' => '777']);

        $this->assertNull(PayseraCallback::decrypt($arQuery['data'], 'other-password'));
        $this->assertNull(PayseraCallback::decrypt('abc', self::PASSWORD));
    }
}
