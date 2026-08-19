<?php

namespace App\Services\Payments\Gateways;

use App\Contracts\PaymentGateway;
use App\Models\Order;
use App\Services\Payments\Data\Bank;
use App\Services\Payments\Data\BankAccount;
use App\Services\Payments\Data\PaymentInitialisation;
use App\Services\Payments\Data\PaymentVerification;
use App\Services\Payments\Data\TransferRequest;
use App\Services\Payments\Data\TransferResult;
use App\Services\Payments\Data\WebhookEvent;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Flutterwave.
 *
 * Flutterwave works in MAJOR units — Naira, not kobo — where this application
 * stores everything in kobo. Every amount crossing this class is converted, and
 * every amount coming back is converted the other way, so nothing outside here
 * has to remember which provider counts in which unit. Getting this backwards
 * charges a buyer a hundred times the price, so the conversion lives in two
 * clearly named methods rather than being inlined at each call site.
 */
class FlutterwaveGateway implements PaymentGateway
{
    public const KEY = 'flutterwave';

    public function key(): string
    {
        return self::KEY;
    }

    public function displayName(): string
    {
        return 'Flutterwave';
    }

    /**
     * @return array<int, string>
     */
    public function supportedCurrencies(): array
    {
        return ['NGN', 'GHS', 'KES', 'UGX', 'TZS', 'ZAR', 'USD', 'GBP', 'EUR'];
    }

    public function supportsCurrency(string $currency): bool
    {
        return in_array(strtoupper($currency), $this->supportedCurrencies(), true);
    }

    public function isConfigured(): bool
    {
        return filled(config('services.flutterwave.secret_key'));
    }

    /**
     * Kobo to Naira. Amounts are always whole kobo, so this is exact.
     */
    private function toMajorUnits(int $minor): string
    {
        return number_format($minor / 100, 2, '.', '');
    }

    /**
     * Naira back to kobo, rounded because the provider returns a decimal.
     */
    private function toMinorUnits(float|int|string $major): int
    {
        return (int) round(((float) $major) * 100);
    }

    public function initialise(Order $order, string $callbackUrl): PaymentInitialisation
    {
        $response = $this->client()->post('/payments', [
            'tx_ref' => $order->reference,
            'amount' => $this->toMajorUnits($order->grand_total_kobo),
            'currency' => $order->currency,
            'redirect_url' => $callbackUrl,
            'customer' => [
                'email' => $order->user->email,
                'name' => $order->delivery_name,
                'phonenumber' => $order->delivery_phone,
            ],
            'meta' => [
                'order_reference' => $order->reference,
                'order_id' => $order->getKey(),
            ],
        ]);

        $body = $response->json();

        if (! $response->successful() || ($body['status'] ?? null) !== 'success') {
            throw new RuntimeException(
                'Flutterwave would not start this payment: '.($body['message'] ?? $response->status())
            );
        }

        return new PaymentInitialisation(
            reference: $order->reference,
            authorisationUrl: $body['data']['link'],
            gatewayReference: null,
            raw: is_array($body) ? $body : [],
        );
    }

    public function verify(string $reference): PaymentVerification
    {
        $response = $this->client()->get('/transactions/verify_by_reference', ['tx_ref' => $reference]);
        $body = $response->json();
        $data = $body['data'] ?? [];

        $status = $data['status'] ?? null;

        return new PaymentVerification(
            successful: $response->successful()
                && ($body['status'] ?? null) === 'success'
                && $status === 'successful',
            reference: $data['tx_ref'] ?? $reference,
            // Back into kobo before it leaves this class.
            amountKobo: $this->toMinorUnits($data['amount'] ?? 0),
            currency: (string) ($data['currency'] ?? 'NGN'),
            gatewayReference: isset($data['id']) ? (string) $data['id'] : null,
            paidAt: $data['created_at'] ?? null,
            status: $status,
            raw: is_array($body) ? $body : [],
        );
    }

    /**
     * Flutterwave sends a shared secret in a header rather than signing the
     * body, so this is a constant-time comparison of that value.
     */
    public function verifyWebhookSignature(Request $request): bool
    {
        $sent = (string) $request->header('verif-hash');
        $expected = (string) config('services.flutterwave.webhook_hash');

        if ($sent === '' || $expected === '') {
            return false;
        }

        return hash_equals($expected, $sent);
    }

    public function parseWebhook(Request $request): WebhookEvent
    {
        $payload = $request->json()->all();

        // Flutterwave has used several shapes over the years; the reference has
        // appeared at both of these paths.
        $reference = $payload['data']['tx_ref']
            ?? $payload['txRef']
            ?? null;

        return new WebhookEvent(
            gateway: self::KEY,
            id: isset($payload['data']['id']) ? (string) $payload['data']['id'] : ($payload['id'] ?? null),
            type: $payload['event'] ?? ($payload['event.type'] ?? null),
            reference: $reference,
            signatureValid: $this->verifyWebhookSignature($request),
            payload: is_array($payload) ? $payload : [],
        );
    }

    /**
     * @return array<int, Bank>
     */
    public function banks(): array
    {
        return Cache::remember('flutterwave.banks', now()->addDay(), function (): array {
            $response = $this->client()->get('/banks/NG');
            $body = $response->json();

            if (! $response->successful() || ($body['status'] ?? null) !== 'success') {
                return [];
            }

            return collect($body['data'] ?? [])
                ->map(fn (array $bank): Bank => new Bank(
                    code: (string) ($bank['code'] ?? ''),
                    name: (string) ($bank['name'] ?? ''),
                ))
                ->filter(fn (Bank $bank): bool => $bank->code !== '' && $bank->name !== '')
                ->sortBy(fn (Bank $bank): string => $bank->name)
                ->values()
                ->all();
        });
    }

    public function resolveAccount(string $accountNumber, string $bankCode): BankAccount
    {
        $response = $this->client()->post('/accounts/resolve', [
            'account_number' => $accountNumber,
            'account_bank' => $bankCode,
        ]);

        $body = $response->json();

        if (! $response->successful() || ($body['status'] ?? null) !== 'success') {
            return BankAccount::failed(
                $body['message'] ?? __('That account number could not be checked with the bank.'),
                is_array($body) ? $body : [],
            );
        }

        return new BankAccount(
            resolved: true,
            accountName: $body['data']['account_name'] ?? null,
            accountNumber: $body['data']['account_number'] ?? $accountNumber,
            bankCode: $bankCode,
            raw: is_array($body) ? $body : [],
        );
    }

    public function transfer(TransferRequest $request): TransferResult
    {
        $response = $this->client()->post('/transfers', [
            'account_bank' => $request->bankCode,
            'account_number' => $request->accountNumber,
            'amount' => $this->toMajorUnits($request->amountKobo),
            'currency' => $request->currency,
            'reference' => $request->reference,
            'narration' => $request->narration ?? 'Marketplace payout',
            'beneficiary_name' => $request->accountName,
        ]);

        $body = $response->json();

        return new TransferResult(
            accepted: $response->successful() && ($body['status'] ?? null) === 'success',
            reference: $body['data']['reference'] ?? $request->reference,
            status: $body['data']['status'] ?? null,
            message: $body['message'] ?? null,
            raw: is_array($body) ? $body : [],
        );
    }

    private function client(): PendingRequest
    {
        return Http::withToken((string) config('services.flutterwave.secret_key'))
            ->baseUrl(rtrim((string) config('services.flutterwave.base_url', 'https://api.flutterwave.com/v3'), '/'))
            ->acceptJson()
            ->timeout(30)
            ->retry(2, 200, throw: false);
    }
}
