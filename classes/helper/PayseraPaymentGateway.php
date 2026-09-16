<?php namespace Logingrupa\PayseraShopaholic\Classes\Helper;

use App;
use Log;
use Response;
use InvalidArgumentException;
use Illuminate\Http\Response as HttpResponse;
use Lovata\OrdersShopaholic\Models\PaymentMethod;
use Logingrupa\PayseraShopaholic\Classes\Api\PayseraCallback;
use Logingrupa\PayseraShopaholic\Classes\Api\PayseraCallbackException;
use Logingrupa\PayseraShopaholic\Classes\Api\PayseraRequest;

/**
 * Checkout Classic (WebToPay) gateway. Purchase builds a signed redirect,
 * the callback marks the order paid. The accept URL never changes order status.
 */
class PayseraPaymentGateway extends AbstractPayseraGateway
{
    const CODE = 'Paysera';
    const CALLBACK_URL = 'paysera/callback';

    // Site locale to Paysera interface language (ISO 639-2/B)
    const LANG_MAP = [
        'lv' => 'LAV',
        'lt' => 'LIT',
        'ru' => 'RUS',
        'en' => 'ENG',
    ];

    /**
     * Handle the Paysera callback request.
     * @param array $arQuery raw request parameters
     * @return HttpResponse
     */
    public function processCallback(array $arQuery): HttpResponse
    {
        try {
            $arData = $this->resolveCallbackData($arQuery);
        } catch (PayseraCallbackException $obException) {
            Log::warning($obException->getMessage(), ['order_id' => $this->obOrder->id ?? null]);

            return Response::make($obException->getMessage(), $obException->getCode());
        }

        $sRejectReason = $this->getCallbackRejectReason($arData);
        if ($sRejectReason !== null) {
            Log::error('Paysera callback rejected: ' . $sRejectReason, ['order_id' => $this->obOrder->id, 'data' => $arData]);
            $this->storeCallback($arData, 'rejected_callback', $sRejectReason);

            return Response::make($sRejectReason, 400);
        }

        $this->storeCallback($arData, 'callback');
        $this->obOrder->payment_token = (string) ($arData['requestid'] ?? '');
        $this->obOrder->save();
        $this->applyCallbackStatus((string) ($arData['status'] ?? ''));

        return Response::make('OK');
    }

    protected function preparePurchaseData()
    {
        $this->arPurchaseData = [
            'projectid'   => $this->getGatewayProperty('project_id'),
            'orderid'     => (string) $this->obOrder->id,
            'amount'      => $this->getOrderAmountInCents(),
            'currency'    => $this->obPaymentMethod->gateway_currency,
            'accepturl'   => $this->resolveUrl(self::EVENT_GET_RETURN_URL),
            'cancelurl'   => $this->resolveUrl(self::EVENT_GET_CANCEL_URL),
            'callbackurl' => url(self::CALLBACK_URL),
            'country'     => $this->getGatewayProperty('country'),
            'lang'        => self::LANG_MAP[App::getLocale()] ?? 'ENG',
            'test'        => $this->getGatewayProperty('test_mode') ? '1' : '0',
            'p_firstname' => $this->getOrderProperty('name'),
            'p_lastname'  => $this->getOrderProperty('last_name'),
            'p_email'     => $this->getOrderProperty('email'),
        ];
    }

    protected function validatePurchaseData()
    {
        if (empty($this->arPurchaseData['projectid']) || empty($this->getGatewayProperty('password'))) {
            $this->sMessage = 'Paysera project id or password is not configured';

            return false;
        }

        if ($this->arPurchaseData['amount'] <= 0 || strlen((string) $this->arPurchaseData['currency']) !== 3) {
            $this->sMessage = 'Paysera amount or currency is invalid';

            return false;
        }

        return true;
    }

    protected function sendPurchaseData()
    {
        try {
            $this->sRedirectURL = PayseraRequest::buildRedirectUrl($this->arPurchaseData, (string) $this->getGatewayProperty('password'));
        } catch (InvalidArgumentException $obException) {
            $this->sMessage = $obException->getMessage();

            return;
        }

        $arPaymentData = (array) $this->obOrder->payment_data;
        $arPaymentData['request'] = $this->arPurchaseData;
        $this->obOrder->payment_data = $arPaymentData;
        $this->obOrder->save();
    }

    /**
     * Load the order and return the verified callback parameters. Signed mode reads
     * the order id before verifying so the order's own password is used. Encrypted
     * mode needs the password first, so every Paysera method's password is tried.
     * @param array $arQuery
     * @return array
     * @throws PayseraCallbackException
     */
    protected function resolveCallbackData(array $arQuery): array
    {
        $sData = (string) ($arQuery['data'] ?? '');

        if (PayseraCallback::isSigned($arQuery)) {
            $this->loadCallbackOrder(PayseraCallback::decode($sData));

            return PayseraCallback::parse($arQuery, (string) $this->getGatewayProperty('password'));
        }

        $obMethodList = PaymentMethod::where('gateway_id', self::CODE)->get();
        foreach ($obMethodList as $obMethod) {
            $arData = PayseraCallback::decrypt($sData, (string) array_get((array) $obMethod->gateway_property, 'password'));
            if ($arData === null) {
                continue;
            }

            $this->loadCallbackOrder($arData);

            return $arData;
        }

        throw new PayseraCallbackException('Paysera callback data decryption failed', 403);
    }

    /**
     * @param array $arData callback parameters, verified or not
     * @throws PayseraCallbackException when the order is not a Paysera order
     */
    protected function loadCallbackOrder(array $arData)
    {
        $this->initOrderObject((int) ($arData['orderid'] ?? 0));

        if (empty($this->obOrder) || empty($this->obPaymentMethod) || $this->obPaymentMethod->gateway_id !== self::CODE) {
            $this->obOrder = null;

            throw new PayseraCallbackException('Unknown order', 404);
        }
    }

    /**
     * @param array $arData verified callback parameters
     * @return string|null reason text, null when the callback is acceptable
     */
    protected function getCallbackRejectReason(array $arData): ?string
    {
        $arRequest = (array) array_get((array) $this->obOrder->payment_data, 'request', []);

        if ((string) ($arData['projectid'] ?? '') !== (string) $this->getGatewayProperty('project_id')) {
            return 'Project id mismatch';
        }

        if (($arData['type'] ?? PayseraCallback::TYPE_MACRO) !== PayseraCallback::TYPE_MACRO) {
            return 'Unsupported callback type';
        }

        if (($arData['test'] ?? '0') === '1' && !$this->getGatewayProperty('test_mode')) {
            return 'Test callback on a live payment method';
        }

        if (!$this->isPaidAmountMatching($arData, $arRequest)) {
            return 'Amount or currency mismatch';
        }

        return null;
    }

    /**
     * Paysera's own rule (lib-checkout-sdk-facade): the requested pair or the paid
     * pair must equal what the merchant asked for.
     * @param array $arData    callback parameters
     * @param array $arRequest request stored at purchase time
     * @return bool
     */
    protected function isPaidAmountMatching(array $arData, array $arRequest): bool
    {
        $iExpectedAmount = (int) ($arRequest['amount'] ?? 0);
        $sExpectedCurrency = (string) ($arRequest['currency'] ?? '');

        foreach ([['amount', 'currency'], ['payamount', 'paycurrency']] as [$sAmountKey, $sCurrencyKey]) {
            if (!isset($arData[$sAmountKey], $arData[$sCurrencyKey])) {
                continue;
            }

            if ((int) $arData[$sAmountKey] === $iExpectedAmount && (string) $arData[$sCurrencyKey] === $sExpectedCurrency) {
                return true;
            }
        }

        return false;
    }

    /**
     * Only status 1 approves the order, once. 0, 2 and 3 are not a payment.
     * 4 (funds unconfirmed) and 5 (refunded) need a manual look.
     * @param string $sStatus Paysera payment status
     */
    protected function applyCallbackStatus(string $sStatus)
    {
        if ($sStatus === PayseraCallback::STATUS_PAID) {
            $this->markPaidOnce();

            return;
        }

        if (in_array($sStatus, [PayseraCallback::STATUS_EXECUTED_UNCONFIRMED, PayseraCallback::STATUS_REFUNDED], true)) {
            Log::warning('Paysera callback needs a manual check', ['order_id' => $this->obOrder->id, 'status' => $sStatus]);

            return;
        }

        Log::info('Paysera callback with non-final status', ['order_id' => $this->obOrder->id, 'status' => $sStatus]);
    }
}
