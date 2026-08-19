<?php

namespace App\Services\Payments\Gateways;

use App\Contracts\PaymentGateway;
use App\Models\Order;
use App\Services\Payments\Data\PaymentInitialisation;
use App\Services\Payments\Data\PaymentVerification;
use App\Services\Payments\Data\TransferRequest;
use App\Services\Payments\Data\TransferResult;
use App\Services\Payments\Data\WebhookEvent;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Paystack.
 *
 * Paystack counts in the currency's minor unit — kobo for Naira — which happens
 * to match how this application stores money, so no conversion is needed here.
 * That is a coincidence of this provider, not a rule; see FlutterwaveGateway.
 */
class PaystackGateway implements PaymentGateway
{
    public const KEY = 'paystack';

    public function key(): string
    {
        return self::KEY;
    }

    public function displayName(): string
    {
        return 'Paystack';
    }

    /**
     * @return array<int, string>
     */
    public function supportedCurrencies(): array
    {
        return ['NGN', 'GHS', 'ZAR', 'USD', 'KES'];
    }

    public function supportsCurrency(string $currency): bool
    {
        return in_array(strtoupper($currency), $this->supportedCurrencies(), true);
    }

    public function isConfigured(): bool
    {
        return filled(config('services.paystack.secret_key'));
    }

    public function initialise(Order $order, string $callbackUrl): PaymentInitialisation
    {
        $response = $this->client()->post('/transaction/initialize', [
            'email' => $order->user->email,
            // Already the minor unit; no conversion.
            'amount' => $order->grand_total_kobo,
            'currency' => $order->currency,
            'reference' => $order->reference,
            'callback_url' => $callbackUrl,
            'metadata' => [
                'order_reference' => $order->reference,
                'order_id' => $order->getKey(),
            ],
        ]);

        $body = $response->json();

        if (! $response->successful() || ($body['status'] ?? false) !== true) {
            throw new RuntimeException(
                'Paystack would not start this payment: '.($body['message'] ?? $response->status())
            );
        }

        return new PaymentInitialisation(
            reference: $body['data']['reference'] ?? $order->reference,
            authorisationUrl: $body['data']['authorization_url'],
            gatewayReference: $body['data']['reference'] ?? null,
            raw: is_array($body) ? $body : [],
        );
    }

    public function verify(string $reference): PaymentVerification
    {
        $response = $this->client()->get('/transaction/verify/'.urlencode($reference));
        $body = $response->json();
        $data = $body['data'] ?? [];

        $status = $data['status'] ?? null;

        return new PaymentVerification(
            successful: $response->successful()
                && ($body['status'] ?? false) === true
                && $status === 'success',
            reference: $data['reference'] ?? $reference,
            amountKobo: (int) ($data['amount'] ?? 0),
            currency: (string) ($data['currency'] ?? 'NGN'),
            gatewayReference: isset($data['id']) ? (string) $data['id'] : null,
            paidAt: $data['paid_at'] ?? null,
            status: $status,
            raw: is_array($body) ? $body : [],
        );
    }

    /**
     * Paystack signs the raw body with the secret key, HMAC SHA512.
     */
    public function verifyWebhookSignature(Request $request): bool
    {
        $signature = (string) $request->header('x-paystack-signature');
        $secret = (string) config('services.paystack.secret_key');

        if ($signature === '' || $secret === '') {
            return false;
        }

        $expected = hash_hmac('sha512', $request->getContent(), $secret);

        // Constant time: a timing oracle on a signature check is a way in.
        return hash_equals($expected, $signature);
    }

    public function parseWebhook(Request $request): WebhookEvent
    {
        $payload = $request->json()->all();

        return new WebhookEvent(
            gateway: self::KEY,
            // Paystack does not send an event id, so the transaction id is what
            // makes a redelivery recognisable.
            id: isset($payload['data']['id']) ? (string) $payload['data']['id'] : null,
            type: $payload['event'] ?? null,
            reference: $payload['data']['reference'] ?? null,
            signatureValid: $this->verifyWebhookSignature($request),
            payload: is_array($payload) ? $payload : [],
        );
    }

    public function transfer(TransferRequest $request): TransferResult
    {
        // A recipient must exist before Paystack will send anything to it.
        $recipient = $this->client()->post('/transferrecipient', [
            'type' => 'nuban',
            'name' => $request->accountName,
            'account_number' => $request->accountNumber,
            'bank_code' => $request->bankCode,
            'currency' => $request->currency,
        ]);

        $recipientBody = $recipient->json();

        if (! $recipient->successful() || ($recipientBody['status'] ?? false) !== true) {
            return new TransferResult(
                accepted: false,
                message: $recipientBody['message'] ?? 'Paystack would not accept the recipient details.',
                raw: is_array($recipientBody) ? $recipientBody : [],
            );
        }

        $response = $this->client()->post('/transfer', [
            'source' => 'balance',
            'amount' => $request->amountKobo,
            'currency' => $request->currency,
            'recipient' => $recipientBody['data']['recipient_code'],
            'reason' => $request->narration ?? 'Marketplace payout',
            'reference' => $request->reference,
        ]);

        $body = $response->json();

        return new TransferResult(
            accepted: $response->successful() && ($body['status'] ?? false) === true,
            reference: $body['data']['reference'] ?? $request->reference,
            status: $body['data']['status'] ?? null,
            message: $body['message'] ?? null,
            raw: is_array($body) ? $body : [],
        );
    }

    private function client(): PendingRequest
    {
        return Http::withToken((string) config('services.paystack.secret_key'))
            ->baseUrl(rtrim((string) config('services.paystack.base_url', 'https://api.paystack.co'), '/'))
            ->acceptJson()
            ->timeout(30)
            ->retry(2, 200, throw: false);
    }
}
