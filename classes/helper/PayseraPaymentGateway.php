<?php namespace Logingrupa\PayseraShopaholic\Classes\Helper;

use App;
use Event;
use Log;
use Response;
use InvalidArgumentException;
use Illuminate\Http\Response as HttpResponse;
use Lovata\OrdersShopaholic\Models\PaymentMethod;
use Lovata\OrdersShopaholic\Classes\Helper\AbstractPaymentGateway;
use Logingrupa\PayseraShopaholic\Classes\Api\PayseraCallback;
use Logingrupa\PayseraShopaholic\Classes\Api\PayseraCallbackException;
use Logingrupa\PayseraShopaholic\Classes\Api\PayseraRequest;

/**
 * Shopaholic payment gateway for Paysera. Purchase builds a signed redirect,
 * the callback marks the order paid. The accept URL never changes order status.
 */
class PayseraPaymentGateway extends AbstractPaymentGateway
{
    const CODE = 'Paysera';
    const CALLBACK_URL = 'paysera/callback';

    // Same event names as Lovata.OmnipayShopaholic so the storeextender and
    // retrypayment listeners resolve the order page for Paysera too.
    const EVENT_GET_RETURN_URL = 'shopaholic.payment_method.omnipay.gateway.return_url';
    const EVENT_GET_CANCEL_URL = 'shopaholic.payment_method.omnipay.gateway.cancel_url';

    // Site locale to Paysera interface language (ISO 639-2/B)
    const LANG_MAP = [
        'lv' => 'LAV',
        'lt' => 'LIT',
        'ru' => 'RUS',
        'en' => 'ENG',
    ];

    protected string $sRedirectURL = '';
    protected string $sMessage = '';

    public function getResponse(): array
    {
        return [];
    }

    public function getRedirectURL(): string
    {
        return $this->sRedirectURL;
    }

    public function getMessage(): string
    {
        return $this->sMessage;
    }

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

            return Response::make($sRejectReason, 400);
        }

        $this->storeCallback($arData);
        $this->applyCallbackStatus((string) ($arData['status'] ?? ''));

        return Response::make('OK');
    }

    protected function preparePurchaseData()
    {
        $this->arPurchaseData = [
            'projectid'   => $this->getGatewayProperty('project_id'),
            'orderid'     => (string) $this->obOrder->id,
            'amount'      => (int) round($this->obOrder->total_price_data->price_with_tax_value * 100),
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

    protected function processPurchaseResponse()
    {
        if ($this->sRedirectURL === '') {
            return;
        }

        $this->bIsRedirect = true;
        $this->setWaitPaymentStatus();
    }

    /**
     * @param string $sEventName
     * @return string
     */
    protected function resolveUrl(string $sEventName): string
    {
        $arEventResult = (array) Event::fire($sEventName, [$this->obOrder, $this->obPaymentMethod]);
        foreach ($arEventResult as $sURL) {
            if (!empty($sURL) && is_string($sURL)) {
                return $sURL;
            }
        }

        return url('/');
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

        // payamount/paycurrency are what was actually paid, present when they differ from the request
        $iPaid = (int) ($arData['payamount'] ?? $arData['amount'] ?? 0);
        $sPaidCurrency = (string) ($arData['paycurrency'] ?? $arData['currency'] ?? '');
        if ($iPaid !== (int) ($arRequest['amount'] ?? 0) || $sPaidCurrency !== (string) ($arRequest['currency'] ?? '')) {
            return 'Amount or currency mismatch';
        }

        return null;
    }

    /**
     * @param array $arData
     */
    protected function storeCallback(array $arData)
    {
        $arPaymentResponse = (array) $this->obOrder->payment_response;
        $arPaymentResponse['callback'] = $arData;

        $this->obOrder->payment_response = $arPaymentResponse;
        $this->obOrder->payment_token = (string) ($arData['requestid'] ?? '');
        $this->obOrder->save();
    }

    /**
     * Statuses 1 and 3 approve the order (Paysera docs). 0 and 2 mean not executed
     * yet, a later 1 can still arrive, so nothing changes. 4 means executed without
     * bank confirmation of the funds, left for a manual check.
     * @param string $sStatus Paysera payment status
     */
    protected function applyCallbackStatus(string $sStatus)
    {
        if (in_array($sStatus, [PayseraCallback::STATUS_PAID, PayseraCallback::STATUS_ADDITIONAL_INFO], true)) {
            if ((int) $this->obOrder->status_id !== (int) $this->obPaymentMethod->after_status_id) {
                $this->setSuccessStatus();
            }

            return;
        }

        if ($sStatus === PayseraCallback::STATUS_EXECUTED_UNCONFIRMED) {
            Log::warning('Paysera payment executed without bank confirmation, check the funds before completing', ['order_id' => $this->obOrder->id]);

            return;
        }

        Log::info('Paysera callback with non-final status', ['order_id' => $this->obOrder->id, 'status' => $sStatus]);
    }
}
