<?php

namespace Database\Seeders;

use App\Enums\ProductStatus;
use App\Enums\RoleName;
use App\Enums\UserStatus;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductPriceTier;
use App\Models\ProductVariant;
use App\Models\SellerProfile;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;

/**
 * A catalogue with something in it, for looking at the platform rather than an
 * empty grid.
 *
 * Deliberately not run by DatabaseSeeder: `php artisan db:seed --class=DemoCatalogueSeeder`.
 * Nothing here is the client — the company still has no name — and every
 * business below is invented.
 */
class DemoCatalogueSeeder extends Seeder
{
    /**
     * Sellers with the shape of real ones: a mix of registered companies and
     * market traders, spread across the states where this trade actually
     * happens.
     *
     * @var array<int, array{business: string, state: string, lga: string, categories: array<int, string>, trusted?: bool}>
     */
    private const SELLERS = [
        [
            'business' => 'Bodija Feed & Chick Depot',
            'state' => 'Oyo',
            'lga' => 'Ibadan North',
            'categories' => ['poultry-feed', 'day-old-chicks', 'feeders', 'drinkers'],
            'trusted' => true,
        ],
        [
            'business' => 'Sabon Gari Agro Allied',
            'state' => 'Kaduna',
            'lga' => 'Zaria',
            'categories' => ['feed-raw-materials', 'grains-and-cereals', 'npk-15-15-15-fertiliser'],
        ],
        [
            'business' => 'Mama Ngozi Poultry Supplies',
            'state' => 'Anambra',
            'lga' => 'Nnewi North',
            'categories' => ['table-eggs', 'point-of-lay-pullets', 'egg-trays-and-crates'],
        ],
        [
            'business' => 'Ilorin Livestock Stores',
            'state' => 'Kwara',
            'lga' => 'Ilorin West',
            'categories' => ['goats', 'sheep', 'cattle'],
        ],
        [
            'business' => 'Jos Plateau Farm Services',
            'state' => 'Plateau',
            'lga' => 'Jos North',
            'categories' => ['veterinary-services', 'transport-and-logistics', 'equipment-hire'],
        ],
        [
            'business' => 'Mile 12 Equipment Depot',
            'state' => 'Lagos',
            'lga' => 'Kosofe',
            'categories' => ['cages', 'incubators', 'brooders', 'generators-and-solar'],
            'trusted' => true,
        ],
        [
            'business' => 'Ondo Valley Seedlings',
            'state' => 'Ondo',
            'lga' => 'Akure South',
            'categories' => ['seedlings-and-suckers', 'seeds', 'tree-crops'],
        ],
        [
            'business' => 'Dawanau Grain Merchants',
            'state' => 'Kano',
            'lga' => 'Dawakin Tofa',
            'categories' => ['grains-and-cereals', 'legumes', 'sacks-and-packaging'],
        ],
    ];

    public function run(): void
    {
        if (Category::query()->doesntExist()) {
            $this->call(CategorySeeder::class);
        }

        if (! Role::query()->where('name', RoleName::Seller->value)->exists()) {
            $this->call(RoleSeeder::class);
        }

        foreach (self::SELLERS as $definition) {
            $this->createSeller($definition);
        }

        $this->command?->info('Demo catalogue: '
            .SellerProfile::query()->approved()->count().' sellers, '
            .Product::query()->visible()->count().' visible listings.');
    }

    /**
     * @param  array{business: string, state: string, lga: string, categories: array<int, string>, trusted?: bool}  $definition
     */
    private function createSeller(array $definition): void
    {
        $slug = Str::slug($definition['business']);

        $user = User::query()->firstOrCreate(
            ['email' => $slug.'@example.test'],
            [
                'name' => $definition['business'],
                'password' => Hash::make('password'),
                'status' => UserStatus::Active,
                'email_verified_at' => now(),
            ],
        );

        $user->assignRole(RoleName::Seller->value);
        $user->profile()->firstOrCreate([], ['display_name' => $definition['business']]);

        $seller = SellerProfile::query()->firstOrNew(['user_id' => $user->getKey()]);

        if (! $seller->exists) {
            $seller = SellerProfile::factory()
                ->approved()
                ->create([
                    'user_id' => $user->getKey(),
                    'business_name' => $definition['business'],
                    'slug' => $slug,
                    'state' => $definition['state'],
                    'lga' => $definition['lga'],
                    'auto_approve_products' => $definition['trusted'] ?? null,
                ]);
        }

        $categories = Category::query()
            ->whereIn('slug', $definition['categories'])
            ->get();

        // Fall back to any leaf category rather than skipping the seller
        // entirely if a slug in the list has been renamed.
        if ($categories->isEmpty()) {
            $categories = Category::query()->whereNotNull('parent_id')->inRandomOrder()->limit(3)->get();
        }

        $seller->categories()->sync($categories->pluck('id'));

        $this->createDeliveryRates($seller);

        if ($seller->products()->exists()) {
            return;
        }

        $this->createProducts($seller, $categories);
    }

    /**
     * Where this seller will deliver to, and for how much.
     *
     * Their own state is cheapest, the states next door cost more, and Lagos
     * gets its own rate because everybody ships there and the traffic is
     * priced in. Anywhere not listed simply cannot be delivered to, which is
     * the honest answer for a trader with one van.
     */
    private function createDeliveryRates(SellerProfile $seller): void
    {
        if ($seller->deliveryRates()->exists()) {
            return;
        }

        $neighbours = collect(self::SELLERS)
            ->pluck('state')
            ->reject(fn (string $state): bool => $state === $seller->state)
            ->unique()
            ->shuffle()
            ->take(3);

        $rates = collect([$seller->state => random_int(15, 30) * 100_00])
            ->merge($neighbours->mapWithKeys(fn (string $state): array => [
                $state => random_int(45, 90) * 100_00,
            ]));

        if (! $rates->has('Lagos')) {
            $rates->put('Lagos', random_int(60, 110) * 100_00);
        }

        foreach ($rates as $state => $feeKobo) {
            $seller->deliveryRates()->create([
                'state' => $state,
                'fee_kobo' => $feeKobo,
                'is_active' => true,
            ]);
        }
    }

    /**
     * @param  Collection<int, Category>  $categories
     */
    private function createProducts(SellerProfile $seller, $categories): void
    {
        $count = random_int(4, 9);

        for ($i = 0; $i < $count; $i++) {
            $category = $categories->random();

            $product = Product::factory()
                ->for($seller, 'seller')
                ->create([
                    'category_id' => $category->getKey(),
                    'status' => $this->statusFor($i),
                ]);

            // Bulk pricing on roughly half the listings, which is about how
            // often it appears in this trade.
            if (random_int(0, 1) === 1 && $product->price_kobo > 100_000) {
                $tiers = [
                    ['min_quantity' => 5, 'factor' => 0.96],
                    ['min_quantity' => 20, 'factor' => 0.92],
                    ['min_quantity' => 50, 'factor' => 0.88],
                ];

                foreach (array_slice($tiers, 0, random_int(1, 3)) as $tier) {
                    ProductPriceTier::query()->create([
                        'product_id' => $product->getKey(),
                        'min_quantity' => $tier['min_quantity'],
                        'unit_price_kobo' => (int) (round($product->price_kobo * $tier['factor'] / 5000) * 5000),
                    ]);
                }
            }

            // Size options where the product plausibly has them.
            if (Str::contains($product->name, ['bag', 'mash', 'seed'])) {
                foreach ([['25kg bag', 0], ['50kg bag', (int) round($product->price_kobo * 0.9)]] as $index => [$name, $delta]) {
                    ProductVariant::query()->create([
                        'product_id' => $product->getKey(),
                        'name' => $name,
                        'price_delta_kobo' => $delta,
                        'stock_quantity' => random_int(0, 80),
                        'sort_order' => $index,
                    ]);
                }
            }
        }
    }

    /**
     * A realistic spread: mostly live, with the occasional draft, queued or
     * out-of-stock listing so the panels have something to show.
     */
    private function statusFor(int $index): ProductStatus
    {
        return match (true) {
            $index === 3 => ProductStatus::PendingReview,
            $index === 5 => ProductStatus::Draft,
            $index === 7 => ProductStatus::OutOfStock,
            default => ProductStatus::Active,
        };
    }
}
