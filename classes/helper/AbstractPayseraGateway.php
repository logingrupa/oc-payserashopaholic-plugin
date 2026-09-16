<?php namespace Logingrupa\PayseraShopaholic\Classes\Helper;

use Event;
use Lovata\OrdersShopaholic\Classes\Helper\AbstractPaymentGateway;

/**
 * Shared behaviour of the Classic (WebToPay) and Modern (Checkout API) gateways:
 * redirect bookkeeping, order page URLs, callback storage and the one-time paid marker.
 */
abstract class AbstractPayseraGateway extends AbstractPaymentGateway
{
    // Same event names as Lovata.OmnipayShopaholic so the storeextender and
    // retrypayment listeners resolve the order page for Paysera too.
    const EVENT_GET_RETURN_URL = 'shopaholic.payment_method.omnipay.gateway.return_url';
    const EVENT_GET_CANCEL_URL = 'shopaholic.payment_method.omnipay.gateway.cancel_url';

    const PAID_MARKER = 'paid_at';

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

    protected function processPurchaseResponse()
    {
        if ($this->sRedirectURL === '') {
            return;
        }

        $this->bIsRedirect = true;
        $this->setWaitPaymentStatus();
    }

    /**
     * @return int order total in minor units
     */
    protected function getOrderAmountInCents(): int
    {
        return (int) round($this->obOrder->total_price_data->price_with_tax_value * 100);
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
     * @param array       $arData
     * @param string      $sKey    payment_response key
     * @param string|null $sReason reject reason, stored next to the data
     */
    protected function storeCallback(array $arData, string $sKey, ?string $sReason = null)
    {
        $arPaymentResponse = (array) $this->obOrder->payment_response;
        $arPaymentResponse[$sKey] = $sReason === null ? $arData : ['reason' => $sReason, 'data' => $arData];

        $this->obOrder->payment_response = $arPaymentResponse;
        $this->obOrder->save();
    }

    /**
     * Set the success status once. The marker survives later status changes, so a
     * retried callback cannot drag a shipped order back to paid.
     */
    protected function markPaidOnce()
    {
        if (!empty(array_get((array) $this->obOrder->payment_response, self::PAID_MARKER))) {
            return;
        }

        $this->setSuccessStatus();

        $arPaymentResponse = (array) $this->obOrder->payment_response;
        $arPaymentResponse[self::PAID_MARKER] = now()->toDateTimeString();
        $this->obOrder->payment_response = $arPaymentResponse;
        $this->obOrder->save();
    }
}
