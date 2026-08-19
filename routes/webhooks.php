<?php

use App\Http\Controllers\PaymentWebhookController;
use Illuminate\Support\Facades\Route;

/*
 * Gateway webhooks.
 *
 * Kept out of routes/web.php because these must not carry session state or CSRF
 * — the caller is a payment provider, not a browser. Authentication is the
 * signature check inside the handler, and nothing else.
 */
Route::post('/webhooks/payments/{gateway}', PaymentWebhookController::class)
    ->name('webhooks.payments');
