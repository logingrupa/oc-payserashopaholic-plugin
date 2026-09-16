<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Logingrupa\PayseraShopaholic\Classes\Helper\PayseraCheckoutPaymentGateway;
use Logingrupa\PayseraShopaholic\Classes\Helper\PayseraPaymentGateway;

// October applies CSRF only inside CmsController, so plugin routes are already open;
// the exclusion documents the intent and keeps the routes open on stock Laravel too.

// Checkout Classic: Paysera sends the callback as GET by default, POST when configured so.
Route::match(['get', 'post'], PayseraPaymentGateway::CALLBACK_URL, function (Request $obRequest) {
    return (new PayseraPaymentGateway())->processCallback($obRequest->all());
})->middleware('web')->withoutMiddleware(VerifyCsrfToken::class);

// Checkout Modern: JSON webhook, HMAC over the raw body.
Route::post(PayseraCheckoutPaymentGateway::WEBHOOK_URL, function (Request $obRequest) {
    return (new PayseraCheckoutPaymentGateway())->processWebhook((string) $obRequest->getContent(), $obRequest->headers->all());
})->middleware('web')->withoutMiddleware(VerifyCsrfToken::class);
