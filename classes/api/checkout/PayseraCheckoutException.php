<?php namespace Logingrupa\PayseraShopaholic\Classes\Api\Checkout;

use RuntimeException;

/**
 * Checkout API call failed. getCode() is the HTTP status (0 for transport errors),
 * getMessage() is Paysera's error_description when the response carried one.
 */
class PayseraCheckoutException extends RuntimeException
{
}
