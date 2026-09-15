<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Logingrupa\PayseraShopaholic\Classes\Helper\PayseraPaymentGateway;

// Paysera sends the callback as GET by default, POST when the project is configured so.
// CSRF is excluded because Paysera cannot send a token; the callback is signed instead.
Route::match(['get', 'post'], PayseraPaymentGateway::CALLBACK_URL, function (Request $obRequest) {
    return (new PayseraPaymentGateway())->processCallback($obRequest->all());
})->middleware('web')->withoutMiddleware(VerifyCsrfToken::class);
