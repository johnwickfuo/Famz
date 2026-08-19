<?php

namespace App\Contracts;

use App\Models\Order;
use App\Services\Payments\Data\PaymentInitialisation;
use App\Services\Payments\Data\PaymentVerification;
use App\Services\Payments\Data\TransferRequest;
use App\Services\Payments\Data\TransferResult;
use App\Services\Payments\Data\WebhookEvent;
use Illuminate\Http\Request;

/**
 * A payment provider.
 *
 * Everything crossing this boundary is in kobo, whatever units the provider
 * itself works in — Paystack counts in kobo, Flutterwave in Naira, and letting
 * that difference leak into the application is how an order gets charged a
 * hundred times its value.
 */
interface PaymentGateway
{
    /**
     * The key this gateway is selected by in settings.
     */
    public function key(): string;

    public function displayName(): string;

    /**
     * Whether this provider can take money in a given currency.
     */
    public function supportsCurrency(string $currency): bool;

    /**
     * @return array<int, string>
     */
    public function supportedCurrencies(): array;

    /**
     * Whether the provider has been given the keys it needs.
     */
    public function isConfigured(): bool;

    /**
     * Start a payment and get somewhere to send the buyer.
     */
    public function initialise(Order $order, string $callbackUrl): PaymentInitialisation;

    /**
     * Ask the provider what actually happened. This is the only source of
     * truth about whether an order was paid.
     */
    public function verify(string $reference): PaymentVerification;

    /**
     * Whether a webhook really came from the provider.
     */
    public function verifyWebhookSignature(Request $request): bool;

    /**
     * Pull the few fields the application acts on out of a webhook body.
     */
    public function parseWebhook(Request $request): WebhookEvent;

    /**
     * Push money out to a seller's bank account.
     */
    public function transfer(TransferRequest $request): TransferResult;
}
