<?php namespace Logingrupa\PayseraShopaholic\Classes\Api;

use RuntimeException;

/**
 * Callback rejected. getCode() carries the HTTP status to answer Paysera with:
 * 403 invalid, 404 unknown order, 503 cannot verify right now (Paysera retries).
 */
class PayseraCallbackException extends RuntimeException
{
}
