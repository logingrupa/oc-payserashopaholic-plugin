<?php

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Logingrupa\PayseraShopaholic\Classes\Api\Checkout\PayseraCheckoutClient;
use Logingrupa\PayseraShopaholic\Classes\Api\Checkout\PayseraCheckoutException;

final class PayseraCheckoutClientTest extends PluginTestCase
{
    protected $autoMigrate = false;

    const TOKEN_URL = PayseraCheckoutClient::BASE_URL . PayseraCheckoutClient::TOKEN_PATH;
    const ORDERS_URL = PayseraCheckoutClient::BASE_URL . PayseraCheckoutClient::ORDERS_PATH;
    const LINKS_URL = PayseraCheckoutClient::BASE_URL . PayseraCheckoutClient::PAYMENT_LINKS_PATH;

    public function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    private function client(): PayseraCheckoutClient
    {
        return new PayseraCheckoutClient('client-1', 'secret-1');
    }

    public function test_order_and_link_are_created_with_one_cached_token(): void
    {
        Http::fake([
            self::TOKEN_URL  => Http::response(['access_token' => 'tok-1', 'expires_in' => 3600]),
            self::ORDERS_URL => Http::response(['order_id' => 'ord-uuid', 'is_test' => true], 201),
            self::LINKS_URL  => Http::response(['link_id' => 'lnk-uuid', 'payment_URL' => 'https://api.paysera.com/pay/x'], 201),
        ]);

        $obClient = $this->client();
        $arOrder = $obClient->createOrder(['purchase' => ['reference' => '777', 'amount' => 690, 'currency' => 'EUR']]);
        $arLink = $obClient->createPaymentLink(['order_id' => $arOrder['order_id'], 'purchase' => ['amount' => 690]]);

        $this->assertSame('ord-uuid', $arOrder['order_id']);
        $this->assertTrue($arOrder['is_test']);
        $this->assertSame('https://api.paysera.com/pay/x', $arLink['payment_URL']);

        Http::assertSentCount(3);
        Http::assertSent(function (Request $obRequest) {
            return $obRequest->url() === self::TOKEN_URL
                && $obRequest['grant_type'] === 'client_credentials'
                && $obRequest['client_id'] === 'client-1'
                && $obRequest['client_secret'] === 'secret-1';
        });
        Http::assertSent(function (Request $obRequest) {
            return $obRequest->url() === self::ORDERS_URL
                && $obRequest->hasHeader('Authorization', 'Bearer tok-1')
                && $obRequest['purchase']['amount'] === 690;
        });
        Http::assertSent(function (Request $obRequest) {
            return $obRequest->url() === self::LINKS_URL && $obRequest['order_id'] === 'ord-uuid';
        });
    }

    public function test_expired_token_is_refreshed_once_on_401(): void
    {
        Cache::put(PayseraCheckoutClient::TOKEN_CACHE_PREFIX . sha1('client-1'), 'stale', 3600);
        Http::fake([
            self::TOKEN_URL  => Http::response(['access_token' => 'tok-2', 'expires_in' => 3600]),
            self::ORDERS_URL => Http::sequence()
                ->push(['error' => 'invalid_token'], 401)
                ->push(['order_id' => 'ord-2'], 201),
        ]);

        $this->assertSame('ord-2', $this->client()->createOrder(['purchase' => []])['order_id']);

        Http::assertSentCount(3);
        Http::assertSent(fn (Request $obRequest) => $obRequest->url() === self::ORDERS_URL && $obRequest->hasHeader('Authorization', 'Bearer tok-2'));
    }

    public function test_validation_error_surfaces_paysera_description(): void
    {
        Http::fake([
            self::TOKEN_URL  => Http::response(['access_token' => 'tok-1', 'expires_in' => 3600]),
            self::ORDERS_URL => Http::response([
                'error'             => 'invalid_properties',
                'error_description' => 'Validation failed',
                'error_properties'  => ['purchase.amount' => ['Amount should be greater than 0']],
            ], 400),
        ]);

        try {
            $this->client()->createOrder(['purchase' => ['amount' => 0]]);
            $this->fail('expected exception');
        } catch (PayseraCheckoutException $obException) {
            $this->assertSame(400, $obException->getCode());
            $this->assertStringContainsString('Amount should be greater than 0', $obException->getMessage());
        }
    }

    public function test_failed_authentication_is_not_cached(): void
    {
        Http::fake([self::TOKEN_URL => Http::response(['error' => 'invalid_client'], 401)]);

        try {
            $this->client()->getOrder('ord-1');
            $this->fail('expected exception');
        } catch (PayseraCheckoutException $obException) {
            $this->assertSame(401, $obException->getCode());
        }

        $this->assertNull(Cache::get(PayseraCheckoutClient::TOKEN_CACHE_PREFIX . sha1('client-1')));
    }
}
