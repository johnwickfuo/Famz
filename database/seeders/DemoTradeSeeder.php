<?php

namespace Database\Seeders;

use App\Enums\DisputeReason;
use App\Enums\RoleName;
use App\Enums\UserStatus;
use App\Models\Order;
use App\Models\PayoutAccount;
use App\Models\Product;
use App\Models\SubOrder;
use App\Models\User;
use App\Services\Cart\CartService;
use App\Services\Disputes\DisputeService;
use App\Services\Orders\FulfilmentService;
use App\Services\Orders\OrderBuilder;
use App\Services\Payments\PaymentProcessor;
use App\Services\Payouts\WithdrawalService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Throwable;

/**
 * Money that has actually moved.
 *
 * A demonstration where every screen reads "no orders yet" shows nothing. This
 * puts real orders through the real services — the cart, the order builder, the
 * payment processor, the settlement driver — rather than writing rows by hand.
 *
 * That distinction is the whole point. Hand-forged orders would look right on
 * the screen and be wrong in the ledger, and the first thing the client is
 * shown after the tour is the reconciliation report. Seeded this way, that
 * report comes back clean, because the money got there the same way real money
 * will.
 *
 * Every state a buyer or seller can be looking at is represented: money held in
 * escrow, an order in transit, one delivered and released, one frozen by a
 * dispute, and a payout part-way through.
 *
 * Run with `php artisan db:seed --class=DemoTradeSeeder`, after
 * DemoCatalogueSeeder.
 */
class DemoTradeSeeder extends Seeder
{
    /**
     * Buyers with different stories, so the demonstration has somebody to be.
     *
     * @var array<int, array{name: string, email: string, state: string, lga: string}>
     */
    private const BUYERS = [
        ['name' => 'Adaeze Nwosu', 'email' => 'buyer@example.test', 'state' => 'Lagos', 'lga' => 'Ikeja'],
        ['name' => 'Musa Abdullahi', 'email' => 'musa@example.test', 'state' => 'Kano', 'lga' => 'Nassarawa'],
        ['name' => 'Tunde Bakare', 'email' => 'tunde@example.test', 'state' => 'Oyo', 'lga' => 'Ibadan North'],
    ];

    /**
     * A number in the right shape that cannot be anybody's.
     *
     * 0700 is reserved and unassigned in the Nigerian numbering plan, so a
     * demonstration screenshot cannot show a stranger's phone number.
     */
    private static function phone(): string
    {
        return '0700'.str_pad((string) random_int(0, 9999999), 7, '0', STR_PAD_LEFT);
    }

    public function run(): void
    {
        /*
         * No mail, no notifications.
         *
         * Seeding six orders would otherwise queue several dozen messages, and
         * on a staging box wired to a real provider they would go to invented
         * addresses — which is how a sending domain gets a bounce rate.
         */
        Mail::fake();
        Notification::fake();

        $products = Product::query()->buyable()->with('seller')->get();

        if ($products->isEmpty()) {
            $this->command?->warn('No products. Run DemoCatalogueSeeder first.');

            return;
        }

        $orders = collect(self::BUYERS)->flatMap(function (array $row) use ($products): array {
            $buyer = $this->buyer($row);

            return [
                $this->placeAndPay($buyer, $row, $products->random(2)),
                $this->placeAndPay($buyer, $row, $products->random(1)),
            ];
        })->filter();

        $this->tellTheStories($orders->values()->each->load('subOrders', 'user'));
        $this->arrangePayouts();

        $this->command?->info('Demo trade seeded: '.$orders->count().' paid orders.');
    }

    /**
     * @param  array{name: string, email: string, state: string, lga: string}  $row
     */
    private function buyer(array $row): User
    {
        $user = User::query()->firstOrCreate(
            ['email' => $row['email']],
            [
                'name' => $row['name'],
                'password' => Hash::make('password'),
                'status' => UserStatus::Active,
                'email_verified_at' => now(),
            ],
        );

        // No role assigned, deliberately. Buying needs none — every registered
        // user can buy — and giving the demonstration buyer a role would show
        // the client a dashboard no real buyer sees.
        return $user;
    }

    /**
     * A cart, an order, and a payment — through the real services.
     */
    /**
     * @param  array{name: string, email: string, state: string, lga: string}  $row
     */
    private function placeAndPay(User $buyer, array $row, iterable $products): ?Order
    {
        $cart = app(CartService::class);
        $cart->clear($buyer);

        foreach ($products as $product) {
            $cart->add($product, random_int(2, 10), null, $buyer);
        }

        try {
            $order = app(OrderBuilder::class)->build(
                buyer: $buyer,
                address: [
                    'name' => $buyer->name,
                    'phone' => self::phone(),
                    'address' => random_int(1, 90).' '.fake()->streetName().' Road',
                    'state' => $row['state'],
                    'lga' => $row['lga'],
                ],
                deliveryMethods: [],
            );

            /*
             * Paid the way a webhook pays: through the processor, so the
             * settlement driver writes the ledger entries and the commission
             * split is the real one rather than a number typed in here.
             */
            app(PaymentProcessor::class)->markPaid($order, 'paystack', 'DEMO-'.$order->reference);

            return $order->refresh();
        } catch (Throwable $exception) {
            $this->command?->warn('Skipped an order: '.$exception->getMessage());

            return null;
        } finally {
            $cart->clear($buyer);
        }
    }

    /**
     * Move each order somewhere different, so every screen has something on it.
     *
     * A demonstration where all six orders sit in the same state shows one
     * screen six times.
     */
    /**
     * Move each seller's part of each order somewhere different.
     *
     * Iterated over sub-orders rather than orders, because an order with two
     * sellers on it has two independent stories — and advancing only the first
     * one left seven of nine parts sitting untouched, so exactly one seller had
     * any money and every other earnings screen read zero.
     *
     * @param  Collection<int, Order>  $orders
     */
    private function tellTheStories($orders): void
    {
        $fulfilment = app(FulfilmentService::class);

        $parts = $orders->flatMap(fn (Order $order) => $order->subOrders->map(
            fn (SubOrder $subOrder): array => [$subOrder, $order->user],
        ));

        foreach ($parts as $index => [$subOrder, $buyer]) {
            try {
                match ($index % 5) {
                    // Just paid, and the seller has not looked at it yet. This
                    // is the state the seller panel opens on.
                    0 => null,

                    // Accepted and on its way.
                    1 => $this->ship($fulfilment, $subOrder),

                    // Delivered, then disputed. The most interesting screen on
                    // the platform, and the one nobody thinks to demonstrate.
                    2 => $this->dispute($fulfilment, $subOrder, $buyer),

                    // Delivered and confirmed: the money is the seller's, which
                    // is what makes an earnings screen worth looking at. Two of
                    // the five, so several sellers have a balance rather than
                    // one.
                    default => $this->complete($fulfilment, $subOrder),
                };
            } catch (Throwable $exception) {
                $this->command?->warn('Could not advance '.$subOrder->reference.': '.$exception->getMessage());
            }
        }
    }

    private function ship(FulfilmentService $fulfilment, SubOrder $subOrder): void
    {
        $fulfilment->accept($subOrder);
        $fulfilment->markShipped($subOrder->refresh());
    }

    private function complete(FulfilmentService $fulfilment, SubOrder $subOrder): void
    {
        $fulfilment->accept($subOrder);
        $fulfilment->markShipped($subOrder->refresh());
        $fulfilment->markDelivered($subOrder->refresh());
        $fulfilment->markReceived($subOrder->refresh());
    }

    private function dispute(FulfilmentService $fulfilment, SubOrder $subOrder, ?User $buyer): void
    {
        if ($buyer === null) {
            return;
        }

        $fulfilment->accept($subOrder);
        $fulfilment->markShipped($subOrder->refresh());
        $fulfilment->markDelivered($subOrder->refresh());

        app(DisputeService::class)->raise(
            subOrder: $subOrder->refresh(),
            buyer: $buyer,
            reason: DisputeReason::QuantityShort,
            description: 'I counted the bags twice. Eighteen came, not twenty, and the delivery note says twenty.',
        );
    }

    /**
     * A payout mid-flight, so the withdrawals screen is not empty either.
     */
    private function arrangePayouts(): void
    {
        $withdrawals = app(WithdrawalService::class);

        $sellers = User::query()->role(RoleName::Seller->value)->get();

        foreach ($sellers as $seller) {
            $available = $withdrawals->requestableBalance($seller);

            if ($available < $withdrawals->minimumKobo()) {
                continue;
            }

            $this->bankAccountFor($seller);

            try {
                // Half the available balance: a seller who withdraws everything
                // leaves an earnings screen reading zero, which demonstrates
                // nothing.
                $withdrawals->request($seller, intdiv($available, 2), null, $seller);
            } catch (Throwable $exception) {
                $this->command?->warn('No payout for '.$seller->name.': '.$exception->getMessage());
            }
        }
    }

    private function bankAccountFor(User $seller): PayoutAccount
    {
        return PayoutAccount::query()->firstOrCreate(
            ['user_id' => $seller->getKey()],
            [
                'bank_code' => '058',
                'bank_name' => 'Guaranty Trust Bank',
                // Not a real account number: the first digit of a real NUBAN is
                // never 0, so nothing here can resolve to somebody's account.
                'account_number' => '0'.str_pad((string) $seller->getKey(), 9, '0', STR_PAD_LEFT),
                'account_name' => $seller->name,
                'is_default' => true,
                // is_verified is the gate the payout service actually checks;
                // verified_at is only the record of when.
                'is_verified' => true,
                'verified_at' => now(),
            ],
        );
    }
}
