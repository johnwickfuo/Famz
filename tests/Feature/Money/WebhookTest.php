<?php

use App\Enums\OrderStatus;
use App\Enums\SubOrderStatus;
use App\Models\Category;
use App\Models\Order;
use App\Models\PaymentWebhook;
use App\Models\Product;
use App\Models\SellerProfile;
use App\Models\SubOrder;
use App\Models\User;
use App\Models\WalletTransaction;
use App\Services\Settings\SettingsService;
use App\Services\Settlement\EscrowDriver;
use App\Services\Wallet\WalletService;
use Database\Seeders\RoleSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;

/**
 * Webhook handling.
 *
 * A payment provider will deliver the same event more than once — after a
 * timeout, after a retry, sometimes days later. Everything here is about making
 * that harmless: an order must be marked paid exactly once, and a seller's
 * ledger must be written exactly once, however many times the provider calls.
 */
beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    $this->seed(SettingsSeeder::class);

    $this->settings = app(SettingsService::class);
    $this->settings->set('settlement_driver', EscrowDriver::KEY, 'string', 'platform');
    $this->wallet = app(WalletService::class);

    config()->set('services.paystack.secret_key', 'sk_test_secret');

    $this->buyer = User::factory()->create();
    $seller = SellerProfile::factory()->approved()->create();
    $this->sellerUser = $seller->user;

    $this->order = Order::factory()->create([
        'user_id' => $this->buyer->id,
        'subtotal_kobo' => 2_000_000,
        'delivery_total_kobo' => 0,
        'grand_total_kobo' => 2_000_000,
    ]);

    $this->subOrder = SubOrder::factory()->pricedAt(2_000_000, 5)->create([
        'order_id' => $this->order->id,
        'seller_id' => $seller->id,
    ]);

    $this->paystackVerifies = function (int $amountKobo = 2_000_000, string $status = 'success', string $currency = 'NGN'): void {
        Http::fake([
            '*/transaction/verify/*' => Http::response([
                'status' => true,
                'data' => [
                    'id' => 998877,
                    'reference' => $this->order->reference,
                    'status' => $status,
                    'amount' => $amountKobo,
                    'currency' => $currency,
                    'paid_at' => now()->toIso8601String(),
                ],
            ]),
        ]);
    };
});

/**
 * Post a Paystack webhook with a correctly computed signature.
 */
function paystackWebhook(array $payload, ?string $secret = 'sk_test_secret'): TestResponse
{
    $body = json_encode($payload);
    $signature = hash_hmac('sha512', $body, (string) $secret);

    return test()->call(
        'POST',
        route('webhooks.payments', 'paystack'),
        [],
        [],
        [],
        ['CONTENT_TYPE' => 'application/json', 'HTTP_X_PAYSTACK_SIGNATURE' => $signature],
        $body,
    );
}

function chargePayload(string $reference, int $amountKobo = 2_000_000, int $id = 998877): array
{
    return [
        'event' => 'charge.success',
        'data' => [
            'id' => $id,
            'reference' => $reference,
            'amount' => $amountKobo,
            'currency' => 'NGN',
            'status' => 'success',
        ],
    ];
}

// ---------------------------------------------------------------------------
// The happy path
// ---------------------------------------------------------------------------

it('marks the order paid on a verified webhook', function () {
    ($this->paystackVerifies)();

    paystackWebhook(chargePayload($this->order->reference))
        ->assertOk()
        ->assertJson(['outcome' => 'processed']);

    $order = $this->order->fresh();

    expect($order->status)->toBe(OrderStatus::Paid)
        ->and($order->paid_at)->not->toBeNull()
        ->and($order->payment_gateway)->toBe('paystack')
        ->and($order->gateway_reference)->toBe('998877');
});

it('writes the ledger once the payment is verified', function () {
    ($this->paystackVerifies)();

    paystackWebhook(chargePayload($this->order->reference));

    expect($this->wallet->heldBalance($this->sellerUser))->toBe(1_900_000)
        ->and($this->wallet->heldBalance(null))->toBe(100_000);
});

it('logs every delivery with its raw body', function () {
    ($this->paystackVerifies)();

    paystackWebhook(chargePayload($this->order->reference));

    $webhook = PaymentWebhook::query()->sole();

    expect($webhook->gateway)->toBe('paystack')
        ->and($webhook->event_type)->toBe('charge.success')
        ->and($webhook->signature_valid)->toBeTrue()
        ->and($webhook->payload['data']['reference'])->toBe($this->order->reference)
        ->and($webhook->processed_at)->not->toBeNull()
        ->and($webhook->outcome)->toBe('processed');
});

// ---------------------------------------------------------------------------
// Idempotency — the point of the exercise
// ---------------------------------------------------------------------------

it('is idempotent when the same event is delivered twice', function () {
    ($this->paystackVerifies)();

    paystackWebhook(chargePayload($this->order->reference))->assertJson(['outcome' => 'processed']);
    paystackWebhook(chargePayload($this->order->reference))->assertJson(['outcome' => 'duplicate']);

    // The ledger must not have doubled.
    expect(WalletTransaction::query()->count())->toBe(2)
        ->and($this->wallet->heldBalance($this->sellerUser))->toBe(1_900_000)
        ->and($this->wallet->heldBalance(null))->toBe(100_000);
});

it('survives ten deliveries of the same event', function () {
    ($this->paystackVerifies)();

    for ($i = 0; $i < 10; $i++) {
        paystackWebhook(chargePayload($this->order->reference));
    }

    expect(WalletTransaction::query()->count())->toBe(2)
        ->and($this->wallet->heldBalance($this->sellerUser))->toBe(1_900_000)
        ->and($this->order->fresh()->paid_at)->not->toBeNull();
});

it('is idempotent even when the gateway sends a different event id for the same payment', function () {
    ($this->paystackVerifies)();

    // Different event id, so the unique index does not catch it — the order's
    // own paid state has to.
    paystackWebhook(chargePayload($this->order->reference, id: 111))->assertJson(['outcome' => 'processed']);
    paystackWebhook(chargePayload($this->order->reference, id: 222))->assertJson(['outcome' => 'duplicate']);

    expect(WalletTransaction::query()->count())->toBe(2)
        ->and(PaymentWebhook::query()->count())->toBe(2);
});

it('records a duplicate delivery in the log too', function () {
    ($this->paystackVerifies)();

    paystackWebhook(chargePayload($this->order->reference, id: 111));
    paystackWebhook(chargePayload($this->order->reference, id: 222));

    expect(PaymentWebhook::query()->where('outcome', 'duplicate')->count())->toBe(1);
});

// ---------------------------------------------------------------------------
// Refusing what it should refuse
// ---------------------------------------------------------------------------

it('refuses a webhook whose signature does not verify', function () {
    ($this->paystackVerifies)();

    paystackWebhook(chargePayload($this->order->reference), secret: 'the-wrong-secret')
        ->assertOk()
        ->assertJson(['outcome' => 'invalid_signature']);

    expect($this->order->fresh()->paid_at)->toBeNull()
        ->and(WalletTransaction::query()->count())->toBe(0);
});

it('still logs a webhook it refuses, because that is the interesting one', function () {
    ($this->paystackVerifies)();

    paystackWebhook(chargePayload($this->order->reference), secret: 'the-wrong-secret');

    $webhook = PaymentWebhook::query()->sole();

    expect($webhook->signature_valid)->toBeFalse()
        ->and($webhook->outcome)->toBe('invalid_signature')
        // The body is kept whatever the verdict.
        ->and($webhook->payload['data']['reference'])->toBe($this->order->reference)
        ->and($webhook->ip_address)->not->toBeNull();
});

it('will not mark an order paid on the callback alone, only on a webhook', function () {
    ($this->paystackVerifies)();

    // A buyer returning from the gateway proves nothing: anybody can visit
    // that URL.
    $this->actingAs($this->buyer)
        ->get(route('checkout.callback', ['reference' => $this->order->reference]))
        ->assertOk();

    expect($this->order->fresh()->paid_at)->toBeNull()
        ->and(WalletTransaction::query()->count())->toBe(0);
});

it('ignores an event that is not a successful charge', function () {
    ($this->paystackVerifies)();

    paystackWebhook([
        'event' => 'charge.failed',
        'data' => ['id' => 5, 'reference' => $this->order->reference, 'status' => 'failed'],
    ])->assertJson(['outcome' => 'ignored']);

    expect($this->order->fresh()->paid_at)->toBeNull();
});

it('refuses a payment for the wrong amount', function () {
    // The gateway says success, but for a fifth of the price.
    ($this->paystackVerifies)(amountKobo: 400_000);

    paystackWebhook(chargePayload($this->order->reference, amountKobo: 400_000))
        ->assertJson(['outcome' => 'duplicate']);

    expect($this->order->fresh()->paid_at)->toBeNull()
        ->and(WalletTransaction::query()->count())->toBe(0);
});

it('refuses a payment in the wrong currency', function () {
    ($this->paystackVerifies)(currency: 'GHS');

    paystackWebhook(chargePayload($this->order->reference));

    expect($this->order->fresh()->paid_at)->toBeNull();
});

it('refuses a payment the gateway does not actually report as successful', function () {
    // The webhook claims success; asking the gateway directly says otherwise.
    ($this->paystackVerifies)(status: 'abandoned');

    paystackWebhook(chargePayload($this->order->reference));

    expect($this->order->fresh()->paid_at)->toBeNull()
        ->and(WalletTransaction::query()->count())->toBe(0);
});

it('handles a webhook for an order it has never heard of', function () {
    ($this->paystackVerifies)();

    paystackWebhook(chargePayload('ORD-000000-NOTHING'))
        ->assertOk()
        ->assertJson(['outcome' => 'duplicate']);

    expect(PaymentWebhook::query()->count())->toBe(1);
});

it('rejects an unknown gateway outright', function () {
    $this->postJson(route('webhooks.payments', 'bureau-de-change'), [])->assertNotFound();
});

it('takes stock down once, not once per delivery', function () {
    $category = Category::factory()->create();
    $product = Product::factory()
        ->for($this->subOrder->seller, 'seller')
        ->create(['category_id' => $category->id, 'stock_quantity' => 100]);

    $this->subOrder->items()->create([
        'product_id' => $product->id,
        'product_name' => $product->name,
        'unit_of_measure' => $product->unit_of_measure->value,
        'unit_price_kobo' => 1_000_000,
        'quantity' => 2,
        'line_total_kobo' => 2_000_000,
    ]);

    ($this->paystackVerifies)();

    paystackWebhook(chargePayload($this->order->reference, id: 1));
    paystackWebhook(chargePayload($this->order->reference, id: 2));
    paystackWebhook(chargePayload($this->order->reference, id: 3));

    expect($product->fresh()->stock_quantity)->toBe(98);
});

it('starts each seller\'s part waiting on them once paid', function () {
    ($this->paystackVerifies)();

    paystackWebhook(chargePayload($this->order->reference));

    expect($this->subOrder->fresh()->status)->toBe(SubOrderStatus::Pending);
});
