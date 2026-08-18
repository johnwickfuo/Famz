<?php

use App\Enums\ProductStatus;
use App\Enums\RoleName;
use App\Filament\Admin\Resources\Products\Pages\ListProducts as AdminListProducts;
use App\Models\Product;
use App\Models\SellerProfile;
use App\Models\User;
use App\Services\Catalogue\ProductPublisher;
use Database\Seeders\RoleSeeder;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;

use function Pest\Livewire\livewire;

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);

    $this->publisher = app(ProductPublisher::class);

    $this->admin = User::factory()->create();
    $this->admin->assignRole(RoleName::Admin->value);
});

// ---------------------------------------------------------------------------
// The auto-approval rule
// ---------------------------------------------------------------------------

it('sends a new seller\'s first listings for review', function () {
    $seller = SellerProfile::factory()->approved()->create();
    $product = Product::factory()->draft()->for($seller, 'seller')->create();

    expect($this->publisher->submit($product))->toBe(ProductStatus::PendingReview)
        ->and($product->fresh()->status)->toBe(ProductStatus::PendingReview);
});

it('stops queueing once the seller has three approved listings', function () {
    $seller = SellerProfile::factory()->approved()->create();

    // Two approved is not yet enough.
    Product::factory()->count(2)->for($seller, 'seller')->create(['status' => ProductStatus::Active]);
    expect($seller->fresh()->skipsProductReview())->toBeFalse();

    Product::factory()->for($seller, 'seller')->create(['status' => ProductStatus::Active]);

    $fourth = Product::factory()->draft()->for($seller, 'seller')->create();

    expect($seller->fresh()->skipsProductReview())->toBeTrue()
        ->and($this->publisher->submit($fourth))->toBe(ProductStatus::Active);
});

it('counts an out-of-stock listing towards the track record but not a rejected one', function () {
    $seller = SellerProfile::factory()->approved()->create();

    Product::factory()->count(2)->for($seller, 'seller')->create(['status' => ProductStatus::Active]);
    Product::factory()->rejected()->for($seller, 'seller')->create();

    expect($seller->fresh()->skipsProductReview())->toBeFalse();

    Product::factory()->outOfStock()->for($seller, 'seller')->create();

    expect($seller->fresh()->skipsProductReview())->toBeTrue();
});

it('lets an administrator vouch for a seller before they have any track record', function () {
    $seller = SellerProfile::factory()->trusted()->create();
    $product = Product::factory()->draft()->for($seller, 'seller')->create();

    expect($this->publisher->submit($product))->toBe(ProductStatus::Active);
});

it('lets an administrator keep a seller under review despite a track record', function () {
    $seller = SellerProfile::factory()->alwaysReviewed()->create();
    Product::factory()->count(5)->for($seller, 'seller')->create(['status' => ProductStatus::Active]);

    $product = Product::factory()->draft()->for($seller, 'seller')->create();

    expect($seller->fresh()->skipsProductReview())->toBeFalse()
        ->and($this->publisher->submit($product))->toBe(ProductStatus::PendingReview);
});

it('publishes an auto-approved listing as out of stock when there is none', function () {
    $seller = SellerProfile::factory()->trusted()->create();
    $product = Product::factory()->draft()->for($seller, 'seller')->create(['stock_quantity' => 0]);

    expect($this->publisher->submit($product))->toBe(ProductStatus::OutOfStock);
});

it('counts down the listings left before a seller stops queueing', function () {
    $seller = SellerProfile::factory()->approved()->create();

    expect($this->publisher->listingsUntilAutoApproval($seller))->toBe(3);

    Product::factory()->count(2)->for($seller, 'seller')->create(['status' => ProductStatus::Active]);

    expect($this->publisher->listingsUntilAutoApproval($seller->fresh()))->toBe(1);

    // The countdown does not apply to a seller an administrator has ruled on.
    expect($this->publisher->listingsUntilAutoApproval(SellerProfile::factory()->trusted()->create()))->toBeNull();
});

// ---------------------------------------------------------------------------
// Admin review
// ---------------------------------------------------------------------------

it('approves a listing and puts it in the catalogue', function () {
    Filament::setCurrentPanel('admin');
    $this->actingAs($this->admin);

    $product = Product::factory()->pendingReview()->create(['stock_quantity' => 12]);

    livewire(AdminListProducts::class)
        ->callAction(TestAction::make('approveListing')->table($product))
        ->assertHasNoActionErrors();

    $product->refresh();

    expect($product->status)->toBe(ProductStatus::Active)
        ->and($product->published_at)->not->toBeNull()
        ->and($product->reviewed_by)->toBe($this->admin->id);
});

it('will not reject a listing without telling the seller why', function () {
    Filament::setCurrentPanel('admin');
    $this->actingAs($this->admin);

    $product = Product::factory()->pendingReview()->create();

    livewire(AdminListProducts::class)
        ->callAction(TestAction::make('rejectListing')->table($product), ['reason' => ''])
        ->assertHasActionErrors(['reason']);

    expect($product->fresh()->status)->toBe(ProductStatus::PendingReview);
});

it('rejects a listing with a reason the seller can act on', function () {
    Filament::setCurrentPanel('admin');
    $this->actingAs($this->admin);

    $product = Product::factory()->pendingReview()->create();

    livewire(AdminListProducts::class)
        ->callAction(TestAction::make('rejectListing')->table($product), [
            'reason' => 'The photographs show a different product from the one described.',
        ])
        ->assertHasNoActionErrors();

    $product->refresh();

    expect($product->status)->toBe(ProductStatus::Rejected)
        ->and($product->review_notes)->toBe('The photographs show a different product from the one described.')
        ->and($product->published_at)->toBeNull();
});

it('keeps a seller out of listing administration', function () {
    $seller = User::factory()->create();
    $seller->assignRole(RoleName::Seller->value);

    $this->actingAs($seller)->get('/admin/products')->assertForbidden();
});

// ---------------------------------------------------------------------------
// Stock behaviour
// ---------------------------------------------------------------------------

it('takes a live listing out of stock when the last one goes, and back when it returns', function () {
    $product = Product::factory()->create(['status' => ProductStatus::Active, 'stock_quantity' => 3]);

    $product->update(['stock_quantity' => 0]);
    expect($product->fresh()->status)->toBe(ProductStatus::OutOfStock);

    $product->update(['stock_quantity' => 25]);
    expect($product->fresh()->status)->toBe(ProductStatus::Active);
});

it('does not resurrect a draft or a rejected listing just because stock arrived', function () {
    $draft = Product::factory()->draft()->create(['stock_quantity' => 0]);
    $rejected = Product::factory()->rejected()->create(['stock_quantity' => 0]);

    $draft->update(['stock_quantity' => 10]);
    $rejected->update(['stock_quantity' => 10]);

    expect($draft->fresh()->status)->toBe(ProductStatus::Draft)
        ->and($rejected->fresh()->status)->toBe(ProductStatus::Rejected);
});
