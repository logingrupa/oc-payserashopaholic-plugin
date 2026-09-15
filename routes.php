<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Logingrupa\PayseraShopaholic\Classes\Helper\PayseraPaymentGateway;

// Paysera sends the callback as GET by default, POST when the project is configured so.
// October applies CSRF only inside CmsController, so plugin routes are already open;
// the exclusion documents the intent and keeps the route open on stock Laravel too.
Route::match(['get', 'post'], PayseraPaymentGateway::CALLBACK_URL, function (Request $obRequest) {
    return (new PayseraPaymentGateway())->processCallback($obRequest->all());
})->middleware('web')->withoutMiddleware(VerifyCsrfToken::class);
