<?php

use App\Http\Controllers\PaymentWebhookController;
use App\Http\Controllers\Webhooks\MailWebhookController;
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

/*
 * Bounce and complaint notifications from the mail provider.
 *
 * Same reasoning as the payment webhooks above — no session, no CSRF — but
 * authenticated by a shared secret rather than a signature, because only two
 * of the four supported providers sign anything and they do it differently.
 */
Route::post('/webhooks/mail/{provider}', MailWebhookController::class)
    ->name('webhooks.mail');
