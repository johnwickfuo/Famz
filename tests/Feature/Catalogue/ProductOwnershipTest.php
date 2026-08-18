<?php

use App\Enums\ProductStatus;
use App\Enums\RoleName;
use App\Enums\SellerStatus;
use App\Filament\Seller\Resources\Products\Pages\CreateProduct;
use App\Filament\Seller\Resources\Products\Pages\EditProduct;
use App\Filament\Seller\Resources\Products\Pages\ListProducts;
use App\Filament\Seller\Resources\Products\ProductResource;
use App\Models\Category;
use App\Models\Product;
use App\Models\SellerProfile;
use App\Models\User;
use Database\Seeders\CategorySeeder;
use Database\Seeders\RoleSeeder;
use Filament\Facades\Filament;

use function Pest\Livewire\livewire;

/**
 * A seller must never be able to see or touch another seller's records.
 *
 * Ownership is enforced twice — by the query scope on the resource and by
 * ProductPolicy — so these tests check both mechanisms independently as well as
 * the behaviour they produce together. A test that only exercised the happy
 * path through the panel would pass even if one of the two were removed.
 */
beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    $this->seed(CategorySeeder::class);

    // These resources live in the seller panel; without saying so, Filament
    // resolves their routes against the default (admin) panel.
    Filament::setCurrentPanel('seller');

    $this->makeSeller = function (): array {
        $user = User::factory()->create();
        $user->assignRole(RoleName::Seller->value);
        $seller = SellerProfile::factory()->approved()->create(['user_id' => $user->id]);

        return [$user, $seller->fresh()];
    };

    [$this->aliceUser, $this->alice] = ($this->makeSeller)();
    [$this->bobUser, $this->bob] = ($this->makeSeller)();

    $this->aliceProduct = Product::factory()->for($this->alice, 'seller')->create(['name' => 'Alice broiler feed']);
    $this->bobProduct = Product::factory()->for($this->bob, 'seller')->create(['name' => 'Bob layer feed']);
});

// ---------------------------------------------------------------------------
// The query scope
// ---------------------------------------------------------------------------

it('scopes a seller\'s listing query to their own records', function () {
    $this->actingAs($this->aliceUser);

    $ids = ProductResource::getEloquentQuery()->pluck('id');

    expect($ids)->toContain($this->aliceProduct->id)
        ->not->toContain($this->bobProduct->id);
});

it('matches nothing rather than everything when there is no seller', function () {
    // Failing closed is the only safe reading of "no seller": a scope that
    // silently matched every row would expose the whole catalogue.
    expect(Product::query()->ownedBy(null)->count())->toBe(0);
});

it('keeps a listing out of a non-owner\'s query even by direct id', function () {
    $this->actingAs($this->bobUser);

    expect(ProductResource::getEloquentQuery()->whereKey($this->aliceProduct->id)->exists())->toBeFalse();
});

// ---------------------------------------------------------------------------
// The policy, independently of the scope
// ---------------------------------------------------------------------------

it('refuses a non-owner through the policy alone', function () {
    expect($this->bobUser->can('view', $this->aliceProduct))->toBeFalse()
        ->and($this->bobUser->can('update', $this->aliceProduct))->toBeFalse()
        ->and($this->bobUser->can('delete', $this->aliceProduct))->toBeFalse();
});

it('allows the owner through the policy', function () {
    expect($this->aliceUser->can('view', $this->aliceProduct))->toBeTrue()
        ->and($this->aliceUser->can('update', $this->aliceProduct))->toBeTrue()
        ->and($this->aliceUser->can('delete', $this->aliceProduct))->toBeTrue();
});

it('stops being the owner the moment a seller account is no longer approved', function () {
    // forceFill, not update: status is deliberately not mass-assignable, so it
    // can only move through SellerApplicationService.
    $this->alice->forceFill(['status' => SellerStatus::Rejected])->save();

    expect($this->aliceUser->fresh()->can('update', $this->aliceProduct))->toBeFalse();
});

it('will not let a seller\'s status be mass-assigned', function () {
    $this->alice->update(['status' => SellerStatus::Rejected]);

    // Approval is a decision, not a form field: it moves only through the
    // application service.
    expect($this->alice->fresh()->status)->toBe(SellerStatus::Approved);
});

it('will not let a seller whose application is only pending create a listing', function () {
    $user = User::factory()->create();
    $user->assignRole(RoleName::Seller->value);
    SellerProfile::factory()->create(['user_id' => $user->id]);

    expect($user->fresh()->can('create', Product::class))->toBeFalse();
});

it('lets an administrator see and edit any listing', function () {
    $admin = User::factory()->create();
    $admin->assignRole(RoleName::Admin->value);

    expect($admin->can('view', $this->aliceProduct))->toBeTrue()
        ->and($admin->can('update', $this->bobProduct))->toBeTrue()
        ->and($admin->can('review', $this->bobProduct))->toBeTrue();
});

it('does not let a seller review their own listing', function () {
    expect($this->aliceUser->can('review', $this->aliceProduct))->toBeFalse();
});

// ---------------------------------------------------------------------------
// Through the panel
// ---------------------------------------------------------------------------

it('shows a seller only their own listings in the panel', function () {
    $this->actingAs($this->aliceUser);

    livewire(ListProducts::class)
        ->assertCanSeeTableRecords([$this->aliceProduct])
        ->assertCanNotSeeTableRecords([$this->bobProduct]);
});

it('refuses to open another seller\'s listing for editing', function () {
    $this->actingAs($this->bobUser);

    livewire(EditProduct::class, ['record' => $this->aliceProduct->getRouteKey()]);
})->throws(Exception::class);

it('files a new listing under the signed-in seller, whatever the form says', function () {
    $this->actingAs($this->aliceUser);

    livewire(CreateProduct::class)
        ->fillForm([
            'name' => 'Chick starter mash, 25kg',
            'category_id' => Category::where('slug', 'poultry-feed')->value('id'),
            'description' => 'Twenty-five kilogram bag of chick starter mash, fresh stock.',
            'price_naira' => 18500,
            'stock_quantity' => 40,
            'min_order_quantity' => 1,
            // A seller must not be able to file a listing under somebody else:
            // the seller is taken from the session, not from the payload.
            'seller_id' => $this->bob->id,
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $created = Product::where('name', 'Chick starter mash, 25kg')->firstOrFail();

    expect($created->seller_id)->toBe($this->alice->id)
        ->and($created->price_kobo)->toBe(1_850_000)
        ->and($created->status)->toBe(ProductStatus::Draft);
});

it('will not let a seller change a listing\'s status by editing it', function () {
    $this->actingAs($this->aliceUser);

    $product = Product::factory()->pendingReview()->for($this->alice, 'seller')->create();

    livewire(EditProduct::class, ['record' => $product->getRouteKey()])
        ->fillForm(['name' => 'Renamed while awaiting review'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($product->fresh()->status)->toBe(ProductStatus::PendingReview);
});
