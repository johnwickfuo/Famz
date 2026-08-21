<?php

use App\Enums\DeliveryMethod;
use App\Enums\LedgerState;
use App\Enums\LedgerType;
use App\Enums\OrderStatus;
use App\Enums\RoleName;
use App\Enums\SubOrderStatus;
use App\Enums\WithdrawalStatus;
use App\Models\Category;
use App\Models\Offer;
use App\Models\Order;
use App\Models\PayoutAccount;
use App\Models\Product;
use App\Models\SellerProfile;
use App\Models\User;
use App\Models\WalletTransaction;
use App\Services\Cart\CartService;
use App\Services\Payouts\WithdrawalService;
use App\Services\Reporting\ReconciliationReport;
use App\Services\Wallet\WalletService;
use Database\Seeders\RoleSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;

/**
 * One journey, from an empty database to money in a seller's bank.
 *
 * Register, browse, negotiate, check out, get paid through a stubbed webhook,
 * mark delivered, release the funds, withdraw. Every other test on this
 * platform proves one step; this proves the steps compose — which is a
 * different claim, and the one that breaks when two correct pieces are wired
 * together wrongly.
 *
 * The ledger is checked at every stage rather than only at the end. A total
 * that is right at the finish can be right by two errors cancelling, and a test
 * that only looked at the finish would call that a pass.
 */
beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    $this->seed(SettingsSeeder::class);

    config()->set('services.paystack.secret_key', 'sk_test_secret');

    $this->wallets = app(WalletService::class);
    $this->cart = app(CartService::class);

    /*
     * One dynamic fake rather than a fixed amount.
     *
     * The processor compares what the gateway says was charged against what the
     * order says it costs, and refuses a mismatch — correctly. A stub returning
     * a constant therefore fails verification on every order except one, and
     * the failure surfaces as `duplicate`, which reads like a harmless
     * redelivery rather than a rejected payment.
     */
    Http::fake(function ($request) {
        if (str_contains($request->url(), 'transaction/initialize')) {
            return Http::response([
                'status' => true,
                'data' => ['reference' => 'ref', 'authorization_url' => 'https://checkout.paystack.com/go'],
            ]);
        }

        if (str_contains($request->url(), 'transaction/verify/')) {
            $reference = basename(parse_url($request->url(), PHP_URL_PATH) ?: '');
            $order = Order::query()->where('reference', $reference)->first();

            return Http::response([
                'status' => true,
                'data' => [
                    'status' => 'success',
                    'amount' => $order?->grand_total_kobo ?? 0,
                    'currency' => 'NGN',
                ],
            ]);
        }

        // The payout side: creating a recipient, then queuing the transfer.
        if (str_contains($request->url(), 'transferrecipient')) {
            return Http::response(['status' => true, 'data' => ['recipient_code' => 'RCP_test']]);
        }

        if (str_contains($request->url(), '/transfer')) {
            return Http::response([
                'status' => true,
                'message' => 'Transfer queued',
                'data' => ['reference' => 'TRF_test', 'status' => 'pending'],
            ]);
        }

        if (str_contains($request->url(), 'bank/resolve')) {
            return Http::response([
                'status' => true,
                'data' => ['account_name' => 'Aisha Bello', 'account_number' => '0123456789'],
            ]);
        }

        return Http::response(['status' => true, 'data' => []]);
    });
});

/**
 * A correctly signed Paystack webhook, exactly as the gateway would send it.
 */
function signedCharge(string $reference, int $amountKobo): TestResponse
{
    $body = json_encode([
        'event' => 'charge.success',
        'data' => [
            'id' => random_int(100000, 999999),
            'reference' => $reference,
            'amount' => $amountKobo,
            'currency' => 'NGN',
            'status' => 'success',
        ],
    ]);

    return test()->call(
        'POST',
        route('webhooks.payments', 'paystack'),
        [], [], [],
        [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_PAYSTACK_SIGNATURE' => hash_hmac('sha512', $body, 'sk_test_secret'),
        ],
        $body,
    );
}

it('walks a buyer and a seller from registration to a paid-out withdrawal', function (): void {
    // -----------------------------------------------------------------------
    // 1. A seller exists with something to sell.
    // -----------------------------------------------------------------------
    $sellerUser = User::factory()->create(['email_verified_at' => now()]);
    $sellerUser->assignRole(RoleName::Seller->value);

    $seller = SellerProfile::factory()->approved()->create([
        'user_id' => $sellerUser->id,
        'state' => 'Oyo',
    ]);

    $seller->deliveryRates()->create([
        'state' => 'Lagos',
        'fee_kobo' => 500_000,
        'is_active' => true,
    ]);

    $product = Product::factory()->for($seller, 'seller')->pricedAt(1_850_000)->create([
        'category_id' => Category::factory()->create()->id,
        'stock_quantity' => 100,
        'min_order_quantity' => 1,
        // The seller takes offers, which is what step 4 depends on.
        'is_negotiable' => true,
    ]);

    // -----------------------------------------------------------------------
    // 2. A buyer registers.
    // -----------------------------------------------------------------------
    $this->post('/register', [
        'name' => 'Aisha Bello',
        'email' => 'aisha@example.test',
        'password' => 'correct-horse-battery',
        'password_confirmation' => 'correct-horse-battery',
    ])->assertRedirect();

    $buyer = User::query()->where('email', 'aisha@example.test')->sole();
    $buyer->forceFill(['email_verified_at' => now()])->save();

    // -----------------------------------------------------------------------
    // 3. They browse, and find it.
    // -----------------------------------------------------------------------
    $this->get(route('catalogue.home'))->assertOk();

    $this->get(route('catalogue.product', $product))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('Catalogue/Product'));

    // -----------------------------------------------------------------------
    // 4. They negotiate a better price, and the seller accepts.
    // -----------------------------------------------------------------------
    $this->actingAs($buyer)
        ->post(route('offers.store', $product), [
            'quantity' => 4,
            'unit_price' => 17000,
            'message' => 'Four bags — can you do this?',
        ])->assertRedirect();

    $offer = Offer::query()->latest('id')->sole();

    expect($offer->responder_id)->toBe($sellerUser->id);

    $this->actingAs($sellerUser)
        ->post(route('offers.respond', $offer), ['decision' => 'accept'])
        ->assertRedirect();

    expect($offer->fresh()->status->value)->toBe('accepted');

    // -----------------------------------------------------------------------
    // 5. Ordinary checkout, on top of that.
    // -----------------------------------------------------------------------
    $this->actingAs($buyer);
    $this->cart->add($product, 2);

    $this->post(route('checkout.store'), [
        'name' => 'Aisha Bello',
        'phone' => '08030000000',
        'address' => '14 Taiwo Road',
        'state' => 'Lagos',
        'lga' => 'Kosofe',
        'delivery_methods' => [$seller->id => DeliveryMethod::SellerArranged->value],
        'gateway' => 'paystack',
    ])->assertRedirect('https://checkout.paystack.com/go');

    $order = Order::query()->where('user_id', $buyer->id)->sole();

    // ₦37,000 of feed and ₦5,000 to bring it.
    expect($order->status)->toBe(OrderStatus::PendingPayment)
        ->and($order->grand_total_kobo)->toBe(4_200_000);

    // Nothing has moved yet: an unpaid order credits nobody.
    expect($this->wallets->availableBalance($sellerUser))->toBe(0)
        ->and($this->wallets->heldBalance($sellerUser))->toBe(0);

    // -----------------------------------------------------------------------
    // 6. The gateway confirms it.
    // -----------------------------------------------------------------------
    signedCharge($order->reference, $order->grand_total_kobo)
        ->assertOk()
        ->assertJson(['outcome' => 'processed']);

    $order->refresh();

    expect($order->status)->toBe(OrderStatus::Paid)
        ->and($order->paid_at)->not->toBeNull();

    /*
     * Held, not available. Under escrow the seller has earned it and cannot
     * yet touch it — that distinction is the entire promise the platform makes
     * to the buyer.
     */
    expect($this->wallets->heldBalance($sellerUser))->toBeGreaterThan(0)
        ->and($this->wallets->availableBalance($sellerUser))->toBe(0);

    $held = $this->wallets->heldBalance($sellerUser);

    // -----------------------------------------------------------------------
    // 7. The seller sends it; the buyer confirms.
    // -----------------------------------------------------------------------
    $subOrder = $order->subOrders()->sole();

    $subOrder->forceFill([
        'status' => SubOrderStatus::Delivered,
        'delivered_at' => now(),
    ])->save();

    $this->actingAs($buyer)
        ->post(route('orders.received', $subOrder))
        ->assertRedirect();

    // -----------------------------------------------------------------------
    // 8. The money is released.
    // -----------------------------------------------------------------------
    expect($this->wallets->availableBalance($sellerUser))->toBe($held)
        ->and($this->wallets->heldBalance($sellerUser))->toBe(0);

    // The platform took its commission out of the seller's side, not the
    // buyer's: what the buyer paid is the price they were shown.
    $commission = (int) WalletTransaction::query()
        ->whereNull('user_id')
        ->where('type', LedgerType::Commission)
        ->sum('amount_kobo');

    expect($commission)->toBeGreaterThan(0)
        ->and($held + $commission)->toBe($order->subtotal_kobo + $order->delivery_total_kobo);

    // -----------------------------------------------------------------------
    // 9. The seller withdraws it.
    // -----------------------------------------------------------------------
    $account = PayoutAccount::factory()->for($sellerUser)->create();

    $admin = User::factory()->create();
    $admin->assignRole(RoleName::Admin->value);

    $withdrawal = app(WithdrawalService::class)->request(
        $sellerUser,
        $this->wallets->availableBalance($sellerUser),
        $account,
    );

    expect($withdrawal->status)->toBe(WithdrawalStatus::Requested);

    /*
     * Three steps, not two. approve() records the decision; process() is what
     * actually asks the gateway to send the money and writes the debit to the
     * ledger; markPaid() records the gateway confirming it landed. Skipping
     * process() leaves an approved withdrawal and an untouched balance, which
     * is precisely the state a real operator would panic about.
     */
    app(WithdrawalService::class)->approve($withdrawal, $admin);
    app(WithdrawalService::class)->process($withdrawal->fresh(), $admin);
    app(WithdrawalService::class)->markPaid($withdrawal->fresh(), 'PS-PAYOUT-1');

    expect($withdrawal->fresh()->status)->toBe(WithdrawalStatus::Paid);

    // Drawn down to nothing, and not below it.
    expect($this->wallets->availableBalance($sellerUser))->toBe(0);

    // -----------------------------------------------------------------------
    // 10. And the books agree at the end of all of it.
    // -----------------------------------------------------------------------
    $reconciliation = app(ReconciliationReport::class)->run();

    expect($reconciliation['critical'])->toBe(0)
        ->and($reconciliation['findings'])->toBe([]);
});

it('leaves the books balanced after a refund', function (): void {
    /*
     * The other direction. A journey that only ever adds money is a journey
     * that never tests the subtraction, and refunds are where a ledger most
     * often stops adding up.
     */
    $sellerUser = User::factory()->create();
    $sellerUser->assignRole(RoleName::Seller->value);
    $seller = SellerProfile::factory()->approved()->create(['user_id' => $sellerUser->id]);

    $buyer = User::factory()->create(['email_verified_at' => now()]);

    $product = Product::factory()->for($seller, 'seller')->pricedAt(1_000_000)->create([
        'category_id' => Category::factory()->create()->id,
        'stock_quantity' => 10,
        'min_order_quantity' => 1,
    ]);

    $this->actingAs($buyer);
    $this->cart->add($product, 1);

    $this->post(route('checkout.store'), [
        'name' => 'Aisha Bello',
        'phone' => '08030000000',
        'address' => '14 Taiwo Road',
        'state' => 'Oyo',
        'lga' => 'Ibadan North',
        'delivery_methods' => [$seller->id => DeliveryMethod::BuyerPickup->value],
        'gateway' => 'paystack',
    ]);

    $order = Order::query()->where('user_id', $buyer->id)->sole();

    signedCharge($order->reference, $order->grand_total_kobo)->assertOk();

    $subOrder = $order->fresh()->subOrders()->sole();

    // The seller cannot fulfil it, so everything is reversed.
    app(WalletService::class)->reverseForSubOrder($subOrder, 'Out of stock', $sellerUser);

    expect(app(WalletService::class)->availableBalance($sellerUser))->toBe(0)
        ->and(app(WalletService::class)->heldBalance($sellerUser))->toBe(0);

    /*
     * Held money is voided by moving it to `refunded`, not by writing an
     * opposing entry — and that distinction is deliberate rather than an
     * inconsistency. Money that was only ever held has not been spent, so
     * cancelling it AND writing a reversal would cancel the same amount twice
     * and drive the balance negative. Released money, which the seller could
     * already have drawn against, does get a correcting entry beside it.
     *
     * Either way the original row survives: the ledger is a record of what
     * happened, not of what is currently true.
     */
    expect(WalletTransaction::query()->where('sub_order_id', $subOrder->id)->count())
        ->toBeGreaterThan(0)
        ->and(WalletTransaction::query()
            ->where('sub_order_id', $subOrder->id)
            ->where('state', LedgerState::Refunded)
            ->count())->toBeGreaterThan(0);

    $reconciliation = app(ReconciliationReport::class)->run();

    expect($reconciliation['critical'])->toBe(0);
});

it('never lets a seller withdraw money that is still held', function (): void {
    $sellerUser = User::factory()->create();
    $sellerUser->assignRole(RoleName::Seller->value);

    WalletTransaction::query()->create([
        'user_id' => $sellerUser->id,
        'type' => LedgerType::Sale,
        'amount_kobo' => 5_000_000,
        // Held: earned, not yet releasable.
        'state' => LedgerState::Held,
        'description' => 'escrowed sale',
    ]);

    $account = PayoutAccount::factory()->for($sellerUser)->create();

    expect(fn () => app(WithdrawalService::class)->request($sellerUser, 5_000_000, $account))
        ->toThrow(Exception::class);

    expect(app(ReconciliationReport::class)->run()['critical'])->toBe(0);
});
