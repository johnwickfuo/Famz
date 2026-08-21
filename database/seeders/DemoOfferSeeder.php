<?php

namespace Database\Seeders;

use App\Enums\BuyerRequestStatus;
use App\Enums\RoleName;
use App\Models\BuyerRequest;
use App\Models\Category;
use App\Models\Product;
use App\Models\User;
use App\Services\Offers\BuyerRequestService;
use App\Services\Offers\OfferService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Throwable;

/**
 * Haggling, and the wanted-ad board.
 *
 * Both halves of the offer engine, left mid-conversation on purpose. An offer
 * chain that has been countered twice and is waiting on somebody is what the
 * feature actually looks like in use; a single pending offer shows the form and
 * nothing else.
 *
 * Run after DemoCatalogueSeeder and DemoTradeSeeder.
 */
class DemoOfferSeeder extends Seeder
{
    /**
     * Wanted ads of the kind people really post: a specific quantity, a place,
     * and a date they need it by.
     *
     * @var array<int, array{title: string, quantity: int, unit: string, state: string, lga: string, min: int, max: int, description: string}>
     */
    private const REQUESTS = [
        [
            'title' => 'Wanted: 500 point-of-lay pullets, Isa Brown',
            'quantity' => 500,
            'unit' => 'birds',
            'state' => 'Ogun',
            'lga' => 'Abeokuta South',
            'min' => 250_000_00,
            'max' => 320_000_00,
            'description' => 'Sixteen to eighteen weeks, vaccinated, with the papers. I can collect from anywhere in the south-west. I need them before the end of the month because the house is standing empty.',
        ],
        [
            'title' => 'Wanted: 200 bags layers mash, monthly',
            'quantity' => 200,
            'unit' => 'bags',
            'state' => 'Oyo',
            'lga' => 'Ibadan North',
            'min' => 1_100_000_00,
            'max' => 1_400_000_00,
            'description' => 'This is a standing order, not a one-off. Twenty-five kilo bags, delivered to Ibadan. Tell me your price for the first month and whether it holds.',
        ],
        [
            'title' => 'Wanted: automatic drinkers for 3,000 birds',
            'quantity' => 300,
            'unit' => 'nipples',
            'state' => 'Kaduna',
            'lga' => 'Zaria',
            'min' => 180_000_00,
            'max' => 250_000_00,
            'description' => 'Nipple drinkers with the regulators and the piping. Second-hand is fine if the nipples are not worn.',
        ],
        [
            'title' => 'Wanted: 50 bags of maize, dried',
            'quantity' => 50,
            'unit' => 'bags',
            'state' => 'Kano',
            'lga' => 'Nassarawa',
            'min' => 400_000_00,
            'max' => 550_000_00,
            'description' => 'For my own feed mill. Moisture under fourteen percent, and I will test it before I pay.',
        ],
    ];

    public function run(): void
    {
        Mail::fake();
        Notification::fake();

        $buyers = User::query()
            ->whereIn('email', ['buyer@example.test', 'musa@example.test', 'tunde@example.test'])
            ->get();

        if ($buyers->isEmpty()) {
            $this->command?->warn('No demo buyers. Run DemoTradeSeeder first.');

            return;
        }

        $admin = User::query()->role(RoleName::Admin->value)->first();

        $this->postRequests($buyers, $admin);
        $this->haggleOnListings($buyers);

        $this->command?->info('Demo offers seeded.');
    }

    /**
     * Wanted ads, in every state the board can show.
     */
    private function postRequests($buyers, ?User $admin): void
    {
        $requestService = app(BuyerRequestService::class);
        $offers = app(OfferService::class);

        $sellers = User::query()
            ->role(RoleName::Seller->value)
            ->with('sellerProfile.categories')
            ->get();

        foreach (self::REQUESTS as $index => $row) {
            $buyer = $buyers[$index % $buyers->count()];

            $request = new BuyerRequest;
            $request->forceFill([
                'user_id' => $buyer->getKey(),
                'title' => $row['title'],
                'slug' => Str::slug($row['title']).'-'.Str::lower(Str::random(4)),
                'description' => $row['description'],
                // A category the demonstration sellers actually trade in.
                // Picking one at random put most requests in a category nobody
                // could answer, and the board is only interesting when offers
                // arrive on it.
                'category_id' => $this->categoryWithSellers($sellers),
                'quantity' => $row['quantity'],
                'unit' => $row['unit'],
                'budget_min_kobo' => $row['min'],
                'budget_max_kobo' => $row['max'],
                'delivery_state' => $row['state'],
                'delivery_lga' => $row['lga'],
                'needed_by' => now()->addDays(random_int(10, 40)),
                'accepts_partial_fulfilment' => $index % 2 === 0,
                'status' => BuyerRequestStatus::PendingApproval,
            ])->save();

            /*
             * One left waiting for review, so the admin moderation queue has
             * something in it. Every wanted ad is checked before it goes on the
             * board, and a demonstration that skips that step misrepresents how
             * the feature works.
             */
            if ($index === 0) {
                continue;
            }

            if ($admin !== null) {
                $requestService->approve($request, $admin);
            }

            /*
             * Two sellers answer each approved ad, so the buyer's dashboard
             * shows offers side by side rather than one lonely row. Filtered to
             * sellers who may actually answer: the eligibility rule is real and
             * refusing six offers in a demonstration proves only that the
             * seeder ignored it.
             */
            $eligible = $sellers
                ->filter(fn (User $seller): bool => $seller->sellerProfile !== null
                    && $offers->sellerCoversCategory($seller->sellerProfile, $request->refresh()))
                ->reject(fn (User $seller): bool => $seller->is($buyer));

            foreach ($eligible->take(2) as $seller) {
                try {
                    $offers->offerOnRequest(
                        request: $request->refresh(),
                        seller: $seller->sellerProfile,
                        quantity: $row['quantity'],
                        unitPriceKobo: intdiv($row['max'], max($row['quantity'], 1)),
                        message: 'I have this in stock. The price includes delivery to '.$row['state'].'.',
                    );
                } catch (Throwable $exception) {
                    $this->command?->warn('Offer skipped: '.$exception->getMessage());
                }
            }
        }
    }

    /**
     * A leaf category that at least two demonstration sellers trade in.
     *
     * @param  Collection<int, User>  $sellers
     */
    private function categoryWithSellers($sellers): ?int
    {
        $categoryIds = $sellers
            ->flatMap(fn (User $seller): array => $seller->sellerProfile?->categories->pluck('id')->all() ?? [])
            ->countBy()
            ->filter(fn (int $count): bool => $count >= 2)
            ->keys();

        return $categoryIds->isEmpty()
            ? Category::query()->whereNotNull('parent_id')->inRandomOrder()->value('id')
            : (int) $categoryIds->random();
    }

    /**
     * Haggling on a listing, left part-way through.
     */
    private function haggleOnListings($buyers): void
    {
        $offers = app(OfferService::class);

        $negotiable = Product::query()
            ->buyable()
            ->where('is_negotiable', true)
            ->with('seller.user')
            ->take(3)
            ->get();

        foreach ($negotiable as $index => $product) {
            $buyer = $buyers[$index % $buyers->count()];
            $seller = $product->seller?->user;

            if ($seller === null || $seller->is($buyer)) {
                continue;
            }

            try {
                $opening = $offers->offerOnProduct(
                    product: $product,
                    buyer: $buyer,
                    quantity: 20,
                    // Below the asking price, which is the entire point of a
                    // negotiable listing.
                    unitPriceKobo: (int) round($product->price_kobo * 0.85),
                    message: 'I will take twenty if you can move on the price. I collect myself.',
                );

                if ($index === 0) {
                    // Left waiting on the seller: the state their panel opens on.
                    continue;
                }

                $counter = $offers->counter(
                    offer: $opening,
                    actor: $seller,
                    quantity: 20,
                    unitPriceKobo: (int) round($product->price_kobo * 0.93),
                    message: 'I cannot do that price on twenty. This is the best I can do.',
                );

                if ($index === 1) {
                    // A live chain waiting on the buyer, which is what the
                    // feature actually looks like in use.
                    continue;
                }

                // Accepted, which mints the private checkout link — the part of
                // this feature worth demonstrating.
                $offers->accept($counter, $buyer);
            } catch (Throwable $exception) {
                $this->command?->warn('Negotiation skipped: '.$exception->getMessage());
            }
        }
    }
}
