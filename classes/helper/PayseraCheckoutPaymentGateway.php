<?php namespace Logingrupa\PayseraShopaholic\Classes\Helper;

use App;
use Log;
use Response;
use Illuminate\Http\Response as HttpResponse;
use System\Classes\VersionManager;
use Logingrupa\PayseraShopaholic\Classes\Api\PayseraCallbackException;
use Logingrupa\PayseraShopaholic\Classes\Api\Checkout\PayseraCheckoutClient;
use Logingrupa\PayseraShopaholic\Classes\Api\Checkout\PayseraCheckoutException;
use Logingrupa\PayseraShopaholic\Classes\Api\Checkout\PayseraCheckoutWebhook;

/**
 * Paysera Checkout (Modern) gateway: OAuth2 client credentials, order plus payment
 * link, hosted payment page, HMAC signed webhook. Only the webhook marks the order
 * paid; the success URL never changes order status.
 */
class PayseraCheckoutPaymentGateway extends AbstractPayseraGateway
{
    const CODE = 'PayseraCheckout';
    const WEBHOOK_URL = 'paysera/checkout/webhook';
    const PLUGIN_CODE = 'Logingrupa.PayseraShopaholic';
    const LINK_LIFETIME_SECONDS = 3600;
    const CALLBACK_ID_HISTORY = 20;

    /**
     * Handle a Paysera Checkout webhook.
     * @param string $sBody     raw request body
     * @param array  $arHeaders request headers
     * @return HttpResponse
     */
    public function processWebhook(string $sBody, array $arHeaders): HttpResponse
    {
        try {
            $arPayload = PayseraCheckoutWebhook::decode($sBody);
            $this->loadWebhookOrder($arPayload);
            PayseraCheckoutWebhook::verify($sBody, $arHeaders, (string) $this->getGatewayProperty('client_secret'));
        } catch (PayseraCallbackException $obException) {
            Log::warning($obException->getMessage(), ['order_id' => $this->obOrder->id ?? null]);

            return Response::make($obException->getMessage(), $obException->getCode());
        }

        $sCallbackId = PayseraCheckoutWebhook::header($arHeaders, PayseraCheckoutWebhook::HEADER_CALLBACK_ID);
        if ($this->isDuplicateCallback($sCallbackId)) {
            return Response::make('OK');
        }

        $sRejectReason = $this->getWebhookRejectReason($arPayload);
        if ($sRejectReason !== null) {
            // Verified but not ours to act on: answer 200, a retry would not change it
            Log::error('Paysera webhook rejected: ' . $sRejectReason, ['order_id' => $this->obOrder->id, 'payload' => $arPayload]);
            $this->storeCallback($arPayload, 'rejected_callback', $sRejectReason);

            return Response::make('OK');
        }

        $this->storeCallback($arPayload, 'callback');
        $this->rememberCallbackId($sCallbackId);
        $this->applyWebhook($arPayload);

        return Response::make('OK');
    }

    protected function preparePurchaseData()
    {
        $iAmount = $this->getOrderAmountInCents();
        $sCancelUrl = $this->resolveUrl(self::EVENT_GET_CANCEL_URL);
        $sPayerName = trim($this->getOrderProperty('name') . ' ' . $this->getOrderProperty('last_name'));

        $this->arPurchaseData = [
            'order' => [
                'purchase'      => [
                    'reference' => (string) $this->obOrder->id,
                    'amount'    => $iAmount,
                    'currency'  => (string) $this->obPaymentMethod->gateway_currency,
                ],
                'redirect_urls' => [
                    'success_url'  => $this->resolveUrl(self::EVENT_GET_RETURN_URL),
                    'failure_url'  => $sCancelUrl,
                    'cancel_url'   => $sCancelUrl,
                    'callback_url' => url(self::WEBHOOK_URL),
                ],
                'metadata'      => [
                    'referer'        => url('/'),
                    'platform'       => 'octobercms',
                    'plugin_name'    => 'logingrupa/oc-payserashopaholic-plugin',
                    'plugin_version' => (string) VersionManager::instance()->getLatestVersion(self::PLUGIN_CODE),
                ],
                'source'        => 'checkout_page',
            ],
            'link'  => [
                'name'              => 'Order ' . $this->obOrder->order_number,
                'lifetime'          => self::LINK_LIFETIME_SECONDS,
                'experience'        => [
                    'language'     => substr(App::getLocale(), 0, 2),
                    'payment_flow' => 'paysera_checkout',
                ],
                'purchase'          => ['amount' => $iAmount],
                'payment_details'   => array_filter([
                    'purpose'      => (string) $this->obOrder->order_number,
                    'country_code' => (string) $this->getGatewayProperty('checkout_country'),
                ]),
                'payer_information' => array_filter([
                    'name'  => $sPayerName,
                    'email' => (string) $this->getOrderProperty('email'),
                ]),
            ],
        ];
    }

    protected function validatePurchaseData()
    {
        if (empty($this->getGatewayProperty('client_id')) || empty($this->getGatewayProperty('client_secret'))) {
            $this->sMessage = 'Paysera Checkout client id or secret is not configured';

            return false;
        }

        $arPurchase = $this->arPurchaseData['order']['purchase'];
        if ($arPurchase['amount'] <= 0 || strlen($arPurchase['currency']) !== 3) {
            $this->sMessage = 'Paysera amount or currency is invalid';

            return false;
        }

        return true;
    }

    protected function sendPurchaseData()
    {
        $obClient = $this->makeClient();

        try {
            $arOrder = $obClient->createOrder($this->arPurchaseData['order']);
            $arLink = $obClient->createPaymentLink(['order_id' => (string) ($arOrder['order_id'] ?? '')] + $this->arPurchaseData['link']);
        } catch (PayseraCheckoutException $obException) {
            Log::error('Paysera Checkout purchase failed: ' . $obException->getMessage(), ['order_id' => $this->obOrder->id, 'http' => $obException->getCode()]);
            $this->sMessage = $obException->getMessage();

            return;
        }

        $this->sRedirectURL = (string) ($arLink['payment_URL'] ?? $arLink['payment_url'] ?? '');
        if ($this->sRedirectURL === '') {
            $this->sMessage = 'Paysera Checkout returned no payment URL';

            return;
        }

        $arPaymentData = (array) $this->obOrder->payment_data;
        $arPaymentData['checkout'] = [
            'paysera_order_id' => (string) ($arOrder['order_id'] ?? ''),
            'link_id'          => (string) ($arLink['link_id'] ?? ''),
            'is_test'          => (bool) ($arOrder['is_test'] ?? false),
            'amount'           => $this->arPurchaseData['order']['purchase']['amount'],
            'currency'         => $this->arPurchaseData['order']['purchase']['currency'],
        ];
        $this->obOrder->payment_data = $arPaymentData;
        $this->obOrder->payment_token = $arPaymentData['checkout']['paysera_order_id'];
        $this->obOrder->save();
    }

    protected function makeClient(): PayseraCheckoutClient
    {
        return new PayseraCheckoutClient((string) $this->getGatewayProperty('client_id'), (string) $this->getGatewayProperty('client_secret'));
    }

    /**
     * @param array $arPayload decoded, not yet verified
     * @throws PayseraCallbackException 404 when the order is not a Paysera Checkout order
     */
    protected function loadWebhookOrder(array $arPayload)
    {
        $this->initOrderObject((int) ($arPayload['order']['merchant_order_id'] ?? 0));

        if (empty($this->obOrder) || empty($this->obPaymentMethod) || $this->obPaymentMethod->gateway_id !== self::CODE) {
            $this->obOrder = null;

            throw new PayseraCallbackException('Unknown order', 404);
        }
    }

    protected function isDuplicateCallback(string $sCallbackId): bool
    {
        return $sCallbackId !== '' && in_array($sCallbackId, (array) array_get((array) $this->obOrder->payment_response, 'callback_ids', []), true);
    }

    protected function rememberCallbackId(string $sCallbackId)
    {
        if ($sCallbackId === '') {
            return;
        }

        $arPaymentResponse = (array) $this->obOrder->payment_response;
        $arIdList = (array) array_get($arPaymentResponse, 'callback_ids', []);
        $arIdList[] = $sCallbackId;
        $arPaymentResponse['callback_ids'] = array_slice($arIdList, -self::CALLBACK_ID_HISTORY);

        $this->obOrder->payment_response = $arPaymentResponse;
        $this->obOrder->save();
    }

    /**
     * @param array $arPayload verified payload
     * @return string|null reason text, null when the webhook is acceptable
     */
    protected function getWebhookRejectReason(array $arPayload): ?string
    {
        $arStored = (array) array_get((array) $this->obOrder->payment_data, 'checkout', []);
        $sPayseraOrderId = (string) ($arPayload['order']['paysera_order_id'] ?? '');

        if ($sPayseraOrderId !== '' && $sPayseraOrderId !== (string) ($arStored['paysera_order_id'] ?? '')) {
            return 'Paysera order id mismatch';
        }

        return null;
    }

    /**
     * Fulfil only on the order snapshot saying paid in full, in the requested currency.
     * @param array $arPayload verified payload
     */
    protected function applyWebhook(array $arPayload)
    {
        $sEvent = PayseraCheckoutWebhook::eventKey($arPayload);
        if ($sEvent !== PayseraCheckoutWebhook::EVENT_ORDER_AMOUNT_PAID) {
            Log::info('Paysera webhook event stored without action', ['order_id' => $this->obOrder->id, 'event' => $sEvent]);

            return;
        }

        $arOrder = (array) ($arPayload['order'] ?? []);
        $arStored = (array) array_get((array) $this->obOrder->payment_data, 'checkout', []);
        $bPaid = ($arOrder['status'] ?? '') === PayseraCheckoutWebhook::ORDER_STATUS_PAID
            && (int) ($arOrder['amount_paid'] ?? 0) >= (int) ($arStored['amount'] ?? PHP_INT_MAX)
            && (string) ($arOrder['currency'] ?? '') === (string) ($arStored['currency'] ?? '');

        if ($bPaid) {
            $this->markPaidOnce();

            return;
        }

        Log::info('Paysera order not paid in full yet', [
            'order_id'    => $this->obOrder->id,
            'status'      => $arOrder['status'] ?? null,
            'amount_paid' => $arOrder['amount_paid'] ?? null,
            'amount'      => $arStored['amount'] ?? null,
        ]);
    }
}
