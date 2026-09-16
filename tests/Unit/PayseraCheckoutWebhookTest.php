<?php

use PHPUnit\Framework\TestCase;
use Logingrupa\PayseraShopaholic\Classes\Api\PayseraCallbackException;
use Logingrupa\PayseraShopaholic\Classes\Api\Checkout\PayseraCheckoutWebhook;

final class PayseraCheckoutWebhookTest extends TestCase
{
    const SECRET = 'client-secret';

    private function body(array $arPayload = []): string
    {
        return json_encode($arPayload + [
            'event' => ['type' => 'order', 'name' => 'amount_paid_updated'],
            'order' => ['paysera_order_id' => 'uuid-1', 'merchant_order_id' => '777', 'amount' => 690, 'amount_paid' => 690, 'currency' => 'EUR', 'status' => 'paid'],
        ]);
    }

    private function headers(string $sBody, string $sSecret = self::SECRET): array
    {
        return [
            'X-Paysera-Signature'     => [hash_hmac('sha256', $sBody, $sSecret)],
            'X-Paysera-Signature-Alg' => ['HMAC-SHA256'],
            'X-Paysera-Callback-Id'   => ['cb-1'],
        ];
    }

    public function test_valid_signature_passes(): void
    {
        $sBody = $this->body();

        PayseraCheckoutWebhook::verify($sBody, $this->headers($sBody), self::SECRET);

        $this->assertSame('order:amount_paid_updated', PayseraCheckoutWebhook::eventKey(PayseraCheckoutWebhook::decode($sBody)));
    }

    public function test_wrong_secret_is_401(): void
    {
        $sBody = $this->body();

        try {
            PayseraCheckoutWebhook::verify($sBody, $this->headers($sBody, 'other'), self::SECRET);
            $this->fail('expected rejection');
        } catch (PayseraCallbackException $obException) {
            $this->assertSame(401, $obException->getCode());
        }
    }

    public function test_body_edit_after_signing_is_401(): void
    {
        $sBody = $this->body();
        $arHeaders = $this->headers($sBody);

        $this->expectException(PayseraCallbackException::class);
        PayseraCheckoutWebhook::verify(str_replace('690', '1', $sBody), $arHeaders, self::SECRET);
    }

    public function test_unknown_algorithm_or_malformed_signature_is_401(): void
    {
        $sBody = $this->body();
        $arHeaders = $this->headers($sBody);
        $arHeaders['X-Paysera-Signature-Alg'] = ['HMAC-SHA1'];

        $this->expectException(PayseraCallbackException::class);
        PayseraCheckoutWebhook::verify($sBody, $arHeaders, self::SECRET);
    }

    public function test_empty_secret_never_verifies(): void
    {
        $sBody = $this->body();
        $arHeaders = ['x-paysera-signature' => hash_hmac('sha256', $sBody, ''), 'x-paysera-signature-alg' => 'hmac-sha256'];

        $this->expectException(PayseraCallbackException::class);
        PayseraCheckoutWebhook::verify($sBody, $arHeaders, '');
    }

    public function test_header_read_is_case_insensitive_and_unwraps_arrays(): void
    {
        $this->assertSame('cb-1', PayseraCheckoutWebhook::header(['X-PAYSERA-CALLBACK-ID' => ['cb-1']], PayseraCheckoutWebhook::HEADER_CALLBACK_ID));
        $this->assertSame('cb-2', PayseraCheckoutWebhook::header(['x-paysera-callback-id' => 'cb-2'], PayseraCheckoutWebhook::HEADER_CALLBACK_ID));
        $this->assertSame('', PayseraCheckoutWebhook::header([], PayseraCheckoutWebhook::HEADER_CALLBACK_ID));
    }

    public function test_invalid_body_is_400(): void
    {
        foreach (['not json', '[]', '{"order":{}}'] as $sBody) {
            try {
                PayseraCheckoutWebhook::decode($sBody);
                $this->fail('expected rejection for ' . $sBody);
            } catch (PayseraCallbackException $obException) {
                $this->assertSame(400, $obException->getCode());
            }
        }
    }
}
