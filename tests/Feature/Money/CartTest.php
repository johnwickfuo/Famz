<?php

use App\Enums\ProductStatus;
use App\Models\Cart;
use App\Models\Category;
use App\Models\Product;
use App\Models\SellerProfile;
use App\Models\User;
use App\Services\Cart\CartService;
use App\Services\Cart\DatabaseCartStore;
use App\Services\Cart\SessionCartStore;

beforeEach(function (): void {
    $this->cart = app(CartService::class);
    $this->category = Category::factory()->create();
    $this->seller = SellerProfile::factory()->approved()->create();

    $this->product = Product::factory()->for($this->seller, 'seller')->pricedAt(1_850_000)->create([
        'category_id' => $this->category->id,
        'stock_quantity' => 100,
        'min_order_quantity' => 1,
    ]);
});

it('keeps a guest\'s cart in the session, not the database', function () {
    expect($this->cart->store())->toBeInstanceOf(SessionCartStore::class);

    $this->cart->add($this->product, 2);

    expect($this->cart->count())->toBe(2)
        // A guest cart in the database would be a row nobody comes back for.
        ->and(Cart::query()->count())->toBe(0);
});

it('keeps a signed-in buyer\'s cart in the database', function () {
    $user = User::factory()->create();

    expect($this->cart->store($user))->toBeInstanceOf(DatabaseCartStore::class);

    $this->cart->add($this->product, 3, null, $user);

    expect(Cart::query()->where('user_id', $user->id)->exists())->toBeTrue()
        ->and($this->cart->count($user))->toBe(3);
});

it('remembers the price as it stood when the item went in', function () {
    $this->cart->add($this->product, 1);

    $this->product->update(['price_kobo' => 2_500_000]);

    $line = $this->cart->lines()->first();

    expect($line->unitPriceKobo)->toBe(1_850_000)
        ->and($line->priceHasChanged())->toBeTrue()
        ->and($line->currentUnitPriceKobo())->toBe(2_500_000);
});

it('adds to an existing line rather than making a second one', function () {
    $this->cart->add($this->product, 2);
    $this->cart->add($this->product, 3);

    expect($this->cart->lines())->toHaveCount(1)
        ->and($this->cart->count())->toBe(5);
});

it('treats the same product with a different option as a separate line', function () {
    $small = $this->product->variants()->create(['name' => '25kg', 'price_delta_kobo' => 0, 'stock_quantity' => 10]);
    $large = $this->product->variants()->create(['name' => '50kg', 'price_delta_kobo' => 900_000, 'stock_quantity' => 10]);

    $this->cart->add($this->product, 1, $small);
    $this->cart->add($this->product, 1, $large);

    expect($this->cart->lines())->toHaveCount(2);
});

it('will not hold more than the seller has', function () {
    $this->product->update(['stock_quantity' => 4]);

    $this->cart->add($this->product, 20);

    expect($this->cart->count())->toBe(4);
});

it('respects the listing\'s minimum order', function () {
    $this->product->update(['min_order_quantity' => 5]);

    $this->cart->add($this->product, 1);

    expect($this->cart->count())->toBe(5);
});

it('applies the bulk price when the quantity reaches a tier', function () {
    $this->product->priceTiers()->create(['min_quantity' => 10, 'unit_price_kobo' => 1_600_000]);
    $this->product->load('priceTiers');

    $this->cart->add($this->product, 10);

    expect($this->cart->lines()->first()->unitPriceKobo)->toBe(1_600_000)
        ->and($this->cart->subtotalKobo())->toBe(16_000_000);
});

it('reprices when the quantity changes across a tier boundary', function () {
    $this->product->priceTiers()->create(['min_quantity' => 10, 'unit_price_kobo' => 1_600_000]);

    $this->cart->add($this->product, 5);
    expect($this->cart->lines()->first()->unitPriceKobo)->toBe(1_850_000);

    $this->cart->updateQuantity($this->product->id, null, 12);
    expect($this->cart->lines()->first()->unitPriceKobo)->toBe(1_600_000);
});

it('refuses a listing that is not on sale', function () {
    $draft = Product::factory()->draft()->for($this->seller, 'seller')->create(['category_id' => $this->category->id]);

    expect(fn () => $this->cart->add($draft, 1))->toThrow(RuntimeException::class);
});

it('drops a line whose listing has since been withdrawn', function () {
    $this->cart->add($this->product, 2);

    // forceFill: status is deliberately not mass-assignable, so it moves only
    // through the publisher.
    $this->product->forceFill(['status' => ProductStatus::Draft])->save();

    expect($this->cart->lines())->toHaveCount(0);
});

it('groups the cart the way it will be split into orders', function () {
    $other = SellerProfile::factory()->approved()->create();
    $theirs = Product::factory()->for($other, 'seller')->pricedAt(500_000)->create([
        'category_id' => $this->category->id,
        'stock_quantity' => 50,
        'min_order_quantity' => 1,
    ]);

    $this->cart->add($this->product, 2);
    $this->cart->add($theirs, 3);

    $groups = $this->cart->groupedBySeller();

    expect($groups)->toHaveCount(2)
        ->and($groups->firstWhere('seller.id', $this->seller->id)['subtotal_kobo'])->toBe(3_700_000)
        ->and($groups->firstWhere('seller.id', $other->id)['subtotal_kobo'])->toBe(1_500_000);
});

// ---------------------------------------------------------------------------
// Merging on sign-in
// ---------------------------------------------------------------------------

it('moves a guest cart into an account on sign-in', function () {
    $this->cart->add($this->product, 2);

    $user = User::factory()->create();
    $this->cart->mergeGuestCartInto($user);

    expect($this->cart->count($user))->toBe(2)
        // And the guest cart is gone, so it cannot be merged twice.
        ->and((new SessionCartStore(app('session.store')))->isEmpty())->toBeTrue();
});

it('adds quantities together rather than throwing one away', function () {
    $user = User::factory()->create();

    // One already in their account, two added before signing in.
    $this->cart->add($this->product, 1, null, $user);
    $this->cart->add($this->product, 2);

    $this->cart->mergeGuestCartInto($user);

    expect($this->cart->count($user))->toBe(3);
});

it('merges the cart when somebody signs in for real', function () {
    $user = User::factory()->create(['password' => bcrypt('password')]);

    $this->cart->add($this->product, 2);

    $this->post('/login', ['email' => $user->email, 'password' => 'password'])
        ->assertRedirect();

    expect($this->cart->count($user->fresh()))->toBe(2);
});

it('does not merge a listing that has since been withdrawn', function () {
    $this->cart->add($this->product, 2);
    $this->product->forceFill(['status' => ProductStatus::Draft])->save();

    $user = User::factory()->create();
    $merged = $this->cart->mergeGuestCartInto($user);

    expect($merged)->toBe(0)
        ->and($this->cart->count($user))->toBe(0);
});

it('does nothing when there is no guest cart to merge', function () {
    $user = User::factory()->create();

    expect($this->cart->mergeGuestCartInto($user))->toBe(0);
});
