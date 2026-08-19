<?php

use App\Models\Order;
use App\Models\User;
use App\Services\Payments\Data\TransferRequest;
use App\Services\Payments\Gateways\FlutterwaveGateway;
use App\Services\Payments\Gateways\PaystackGateway;
use App\Services\Payments\PaymentGatewayManager;
use App\Services\Settings\SettingsService;
use Database\Seeders\SettingsSeeder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

/**
 * The two providers, and above all the unit difference between them.
 *
 * Paystack counts in kobo; Flutterwave counts in Naira. Getting that backwards
 * charges a buyer a hundred times the price or a hundredth of it, so it is
 * tested from both directions on both providers.
 */
beforeEach(function (): void {
    config()->set('services.paystack.secret_key', 'sk_test_paystack');
    config()->set('services.flutterwave.secret_key', 'FLWSECK_TEST');
    config()->set('services.flutterwave.webhook_hash', 'my-webhook-hash');

    $this->order = Order::factory()->create([
        'user_id' => User::factory()->create(['email' => 'buyer@example.test'])->id,
        // ₦18,500.
        'subtotal_kobo' => 1_850_000,
        'grand_total_kobo' => 1_850_000,
        'currency' => 'NGN',
    ]);
});

// ---------------------------------------------------------------------------
// Paystack: minor units, same as ours
// ---------------------------------------------------------------------------

it('sends Paystack an amount in kobo', function () {
    Http::fake(['*/transaction/initialize' => Http::response([
        'status' => true,
        'data' => ['reference' => $this->order->reference, 'authorization_url' => 'https://checkout.paystack.com/abc'],
    ])]);

    app(PaystackGateway::class)->initialise($this->order, 'https://example.test/callback');

    Http::assertSent(function ($request): bool {
        // 1_850_000 kobo, unchanged.
        return $request['amount'] === 1_850_000 && $request['currency'] === 'NGN';
    });
});

it('reads a Paystack verification in kobo', function () {
    Http::fake(['*/transaction/verify/*' => Http::response([
        'status' => true,
        'data' => ['id' => 42, 'reference' => 'ORD-1', 'status' => 'success', 'amount' => 1_850_000, 'currency' => 'NGN'],
    ])]);

    $verification = app(PaystackGateway::class)->verify('ORD-1');

    expect($verification->successful)->toBeTrue()
        ->and($verification->amountKobo)->toBe(1_850_000)
        ->and($verification->matches(1_850_000, 'NGN'))->toBeTrue();
});

it('verifies a Paystack signature and rejects a forged one', function () {
    $body = json_encode(['event' => 'charge.success', 'data' => ['reference' => 'ORD-1']]);

    $good = Request::create('/webhooks/payments/paystack', 'POST', [], [], [], [
        'HTTP_X_PAYSTACK_SIGNATURE' => hash_hmac('sha512', $body, 'sk_test_paystack'),
        'CONTENT_TYPE' => 'application/json',
    ], $body);

    $bad = Request::create('/webhooks/payments/paystack', 'POST', [], [], [], [
        'HTTP_X_PAYSTACK_SIGNATURE' => hash_hmac('sha512', $body, 'somebody-elses-secret'),
        'CONTENT_TYPE' => 'application/json',
    ], $body);

    $gateway = app(PaystackGateway::class);

    expect($gateway->verifyWebhookSignature($good))->toBeTrue()
        ->and($gateway->verifyWebhookSignature($bad))->toBeFalse();
});

it('rejects a Paystack webhook with no signature at all', function () {
    $request = Request::create('/webhooks/payments/paystack', 'POST', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
    ], '{}');

    expect(app(PaystackGateway::class)->verifyWebhookSignature($request))->toBeFalse();
});

// ---------------------------------------------------------------------------
// Flutterwave: major units, unlike ours
// ---------------------------------------------------------------------------

it('sends Flutterwave an amount in Naira, not kobo', function () {
    Http::fake(['*/payments' => Http::response([
        'status' => 'success',
        'data' => ['link' => 'https://checkout.flutterwave.com/abc'],
    ])]);

    app(FlutterwaveGateway::class)->initialise($this->order, 'https://example.test/callback');

    Http::assertSent(function ($request): bool {
        // ₦18,500 — not 1,850,000, which would be ₦1.85m.
        return $request['amount'] === '18500.00' && $request['currency'] === 'NGN';
    });
});

it('converts a Flutterwave verification back into kobo', function () {
    Http::fake(['*/transactions/verify_by_reference*' => Http::response([
        'status' => 'success',
        'data' => ['id' => 99, 'tx_ref' => 'ORD-1', 'status' => 'successful', 'amount' => 18500, 'currency' => 'NGN'],
    ])]);

    $verification = app(FlutterwaveGateway::class)->verify('ORD-1');

    expect($verification->amountKobo)->toBe(1_850_000)
        ->and($verification->matches(1_850_000, 'NGN'))->toBeTrue();
});

it('handles a Flutterwave amount with kobo in it', function () {
    Http::fake(['*/transactions/verify_by_reference*' => Http::response([
        'status' => 'success',
        'data' => ['tx_ref' => 'ORD-1', 'status' => 'successful', 'amount' => 18500.75, 'currency' => 'NGN'],
    ])]);

    expect(app(FlutterwaveGateway::class)->verify('ORD-1')->amountKobo)->toBe(1_850_075);
});

it('verifies a Flutterwave webhook by its shared secret', function () {
    $good = Request::create('/webhooks/payments/flutterwave', 'POST', [], [], [], [
        'HTTP_VERIF_HASH' => 'my-webhook-hash',
        'CONTENT_TYPE' => 'application/json',
    ], '{}');

    $bad = Request::create('/webhooks/payments/flutterwave', 'POST', [], [], [], [
        'HTTP_VERIF_HASH' => 'not-my-hash',
        'CONTENT_TYPE' => 'application/json',
    ], '{}');

    $gateway = app(FlutterwaveGateway::class);

    expect($gateway->verifyWebhookSignature($good))->toBeTrue()
        ->and($gateway->verifyWebhookSignature($bad))->toBeFalse();
});

it('sends a Flutterwave payout in Naira too', function () {
    Http::fake(['*/transfers' => Http::response(['status' => 'success', 'data' => ['reference' => 'PO-1', 'status' => 'NEW']])]);

    app(FlutterwaveGateway::class)->transfer(new TransferRequest(
        amountKobo: 1_900_000,
        currency: 'NGN',
        accountNumber: '0123456789',
        bankCode: '058',
        accountName: 'A Seller',
        reference: 'PO-1',
    ));

    Http::assertSent(fn ($request): bool => $request['amount'] === '19000.00');
});

it('sends a Paystack payout in kobo', function () {
    Http::fake([
        '*/transferrecipient' => Http::response(['status' => true, 'data' => ['recipient_code' => 'RCP_1']]),
        '*/transfer' => Http::response(['status' => true, 'data' => ['reference' => 'PO-1', 'status' => 'pending']]),
    ]);

    app(PaystackGateway::class)->transfer(new TransferRequest(
        amountKobo: 1_900_000,
        currency: 'NGN',
        accountNumber: '0123456789',
        bankCode: '058',
        accountName: 'A Seller',
        reference: 'PO-1',
    ));

    Http::assertSent(fn ($request): bool => str_contains($request->url(), '/transfer')
        && ! str_contains($request->url(), 'recipient')
        ? $request['amount'] === 1_900_000
        : true);
});

// ---------------------------------------------------------------------------
// Currencies and selection
// ---------------------------------------------------------------------------

it('knows which currencies each provider can take', function () {
    expect(app(PaystackGateway::class)->supportsCurrency('NGN'))->toBeTrue()
        ->and(app(PaystackGateway::class)->supportsCurrency('ngn'))->toBeTrue()
        ->and(app(PaystackGateway::class)->supportsCurrency('JPY'))->toBeFalse()
        ->and(app(FlutterwaveGateway::class)->supportsCurrency('KES'))->toBeTrue()
        ->and(app(FlutterwaveGateway::class)->supportsCurrency('JPY'))->toBeFalse();
});

it('resolves the gateway named in settings', function () {
    $this->seed(SettingsSeeder::class);
    $settings = app(SettingsService::class);
    $manager = app(PaymentGatewayManager::class);

    $settings->set('active_payment_gateway', 'paystack', 'string', 'platform');
    expect($manager->gateway())->toBeInstanceOf(PaystackGateway::class);

    $settings->set('active_payment_gateway', 'flutterwave', 'string', 'platform');
    expect($manager->gateway())->toBeInstanceOf(FlutterwaveGateway::class);
});

it('refuses an unknown gateway rather than silently picking one', function () {
    expect(fn () => app(PaymentGatewayManager::class)->gateway('cowries'))
        ->toThrow(InvalidArgumentException::class);
});

it('offers only gateways that are configured and can take the currency', function () {
    config()->set('services.flutterwave.secret_key', null);

    $available = collect(app(PaymentGatewayManager::class)->availableFor('NGN'))
        ->map(fn ($gateway): string => $gateway->key());

    expect($available->all())->toBe(['paystack']);
});

it('will not treat a failed charge as a payment', function () {
    Http::fake(['*/transaction/verify/*' => Http::response([
        'status' => true,
        'data' => ['reference' => 'ORD-1', 'status' => 'failed', 'amount' => 1_850_000, 'currency' => 'NGN'],
    ])]);

    $verification = app(PaystackGateway::class)->verify('ORD-1');

    expect($verification->successful)->toBeFalse()
        ->and($verification->matches(1_850_000, 'NGN'))->toBeFalse();
});
