<?php

use App\Enums\ProductStatus;
use App\Enums\RoleName;
use App\Filament\Seller\Resources\Products\Pages\CreateProduct;
use App\Models\Category;
use App\Models\Product;
use App\Models\SellerProfile;
use App\Models\User;
use App\Services\Catalogue\ProductPublisher;
use Database\Seeders\CategorySeeder;
use Database\Seeders\RoleSeeder;
use Filament\Facades\Filament;

use function Pest\Livewire\livewire;

/**
 * Live birds and perishable goods are the two things on this platform that go
 * wrong between the sale and the buyer. A listing for either has to say how it
 * reaches them — and that has to hold on every path into the table, not just
 * the one form a seller usually uses.
 */
beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    $this->seed(CategorySeeder::class);

    $this->seller = SellerProfile::factory()->trusted()->create();
    $this->category = Category::where('slug', 'day-old-chicks')->firstOrFail();
});

it('refuses to publish a live animal listing with no handling note', function () {
    Product::factory()->for($this->seller, 'seller')->create([
        'category_id' => $this->category->id,
        'is_live_animal' => true,
        'handling_note' => null,
        'status' => ProductStatus::Active,
    ]);
})->throws(LogicException::class);

it('refuses to publish a perishable listing with no handling note', function () {
    Product::factory()->for($this->seller, 'seller')->create([
        'category_id' => $this->category->id,
        'is_live_animal' => false,
        'is_perishable' => true,
        'handling_note' => null,
        'status' => ProductStatus::Active,
    ]);
})->throws(LogicException::class);

it('lets a seller keep a half-finished draft without one', function () {
    $draft = Product::factory()->draft()->for($this->seller, 'seller')->create([
        'category_id' => $this->category->id,
        'is_live_animal' => true,
        'handling_note' => null,
    ]);

    expect($draft->exists)->toBeTrue()
        ->and($draft->needsHandlingNote())->toBeTrue()
        ->and($draft->hasHandlingNote())->toBeFalse();
});

it('will not let that draft be published until the note is written', function () {
    $draft = Product::factory()->draft()->for($this->seller, 'seller')->create([
        'category_id' => $this->category->id,
        'is_live_animal' => true,
        'handling_note' => null,
    ]);

    expect(fn () => app(ProductPublisher::class)->submit($draft))
        ->toThrow(LogicException::class);

    $draft->update(['handling_note' => 'Collected from the farm gate before 9am. Buyer brings crates.']);

    expect(app(ProductPublisher::class)->submit($draft->fresh()))->toBe(ProductStatus::Active);
});

it('allows an ordinary listing without a note', function () {
    $product = Product::factory()->for($this->seller, 'seller')->create([
        'category_id' => $this->category->id,
        'is_live_animal' => false,
        'is_perishable' => false,
        'handling_note' => null,
        'status' => ProductStatus::Active,
    ]);

    expect($product->fresh()->status)->toBe(ProductStatus::Active);
});

it('holds even when an administrator is the one saving', function () {
    // The rule lives on the model, so an admin edit, an import or a seeder is
    // bound by it too — not just the seller's form.
    $product = Product::factory()->perishable()->for($this->seller, 'seller')->create([
        'category_id' => $this->category->id,
    ]);

    expect(fn () => $product->update(['handling_note' => null]))
        ->toThrow(LogicException::class);
});

it('asks the seller for a note in the form when they flag a listing', function () {
    $user = User::factory()->create();
    $user->assignRole(RoleName::Seller->value);

    // user_id is not fillable, so the seller is created for this user rather
    // than reassigned to them.
    $seller = SellerProfile::factory()->trusted()->create(['user_id' => $user->id]);

    Filament::setCurrentPanel('seller');
    $this->actingAs($user);

    livewire(CreateProduct::class)
        ->fillForm([
            'name' => 'Point-of-lay pullets',
            'category_id' => $this->category->id,
            'description' => 'Eighteen-week pullets, vaccinated and ready to lay.',
            'price_naira' => 6500,
            'stock_quantity' => 200,
            'min_order_quantity' => 10,
            'is_live_animal' => true,
            'handling_note' => '',
        ])
        ->call('create')
        ->assertHasFormErrors(['handling_note']);
});

it('surfaces both flags on the catalogue card, not only the detail page', function () {
    $product = Product::factory()->liveAnimal()->for($this->seller, 'seller')->create([
        'category_id' => $this->category->id,
    ]);

    $props = $this->get(route('catalogue.category', $this->category->slug))
        ->assertOk()
        ->viewData('page')['props'];

    // A farmer scanning a grid needs to know which of these is a living thing
    // before they click into it.
    expect($props['products'][0]['is_live_animal'])->toBeTrue();
});

it('sends the handling note to the product page so it can be shown on its own', function () {
    $product = Product::factory()->liveAnimal()->for($this->seller, 'seller')->create([
        'category_id' => $this->category->id,
        'handling_note' => 'Travel before 10am so the birds arrive cool.',
    ]);

    $props = $this->get(route('catalogue.product', $product->slug))->assertOk()->viewData('page')['props'];

    expect($props['product']['is_live_animal'])->toBeTrue()
        ->and($props['product']['handling_note'])->toBe('Travel before 10am so the birds arrive cool.');
});
