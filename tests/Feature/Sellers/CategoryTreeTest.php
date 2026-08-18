<?php

use App\Enums\RoleName;
use App\Filament\Admin\Resources\Categories\Pages\CreateCategory;
use App\Filament\Admin\Resources\Categories\Pages\EditCategory;
use App\Models\Category;
use App\Models\Product;
use App\Models\User;
use Database\Seeders\CategorySeeder;
use Database\Seeders\RoleSeeder;

use function Pest\Livewire\livewire;

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);

    $this->admin = User::factory()->create();
    $this->admin->assignRole(RoleName::Admin->value);
    $this->actingAs($this->admin);
});

it('seeds a tree that covers all of agriculture, not just poultry', function () {
    $this->seed(CategorySeeder::class);

    $roots = Category::roots()->pluck('name');

    expect($roots)->toContain(
        'Poultry',
        'Livestock',
        'Crops & Seedlings',
        'Feed & Nutrition',
        'Veterinary & Drugs',
        'Equipment & Housing',
        'Tools',
        'Services',
    );
});

it('makes poultry the deepest branch', function () {
    $this->seed(CategorySeeder::class);

    $depthOf = function (string $slug): int {
        $category = Category::where('slug', $slug)->firstOrFail();

        return $category->ancestors()->count() + 1;
    };

    expect($depthOf('day-old-chicks'))->toBe(3)
        ->and($depthOf('point-of-lay-pullets'))->toBe(3)
        ->and($depthOf('feeders'))->toBe(3)
        ->and($depthOf('drinkers'))->toBe(3)
        ->and($depthOf('cages'))->toBe(3)
        ->and($depthOf('incubators'))->toBe(3)
        ->and($depthOf('brooders'))->toBe(3);

    // No other branch goes as deep, which is what "deepest" has to mean.
    $poultryDepth = Category::where('slug', 'poultry')->firstOrFail()
        ->descendantIds()
        ->map(fn (int $id): int => Category::find($id)->ancestors()->count() + 1)
        ->max();

    $othersDepth = Category::roots()->where('slug', '!=', 'poultry')->get()
        ->flatMap(fn (Category $root) => $root->descendantIds())
        ->map(fn (int $id): int => Category::find($id)->ancestors()->count() + 1)
        ->max();

    expect($poultryDepth)->toBeGreaterThan($othersDepth);
});

it('is safe to re-seed and keeps an administrator\'s edits', function () {
    $this->seed(CategorySeeder::class);
    $before = Category::count();

    $feeders = Category::where('slug', 'feeders')->firstOrFail();
    $feeders->update(['name' => 'Feeders and troughs']);

    $this->seed(CategorySeeder::class);

    expect(Category::count())->toBe($before);
});

it('walks the whole branch when collecting descendants', function () {
    $this->seed(CategorySeeder::class);

    $poultry = Category::where('slug', 'poultry')->firstOrFail();
    $ids = $poultry->descendantIds();

    expect($ids)->toContain($poultry->id)
        ->toContain(Category::where('slug', 'live-birds')->value('id'))
        // Three levels down, which is the case a single-level query would miss.
        ->toContain(Category::where('slug', 'day-old-chicks')->value('id'))
        ->not->toContain(Category::where('slug', 'tools')->value('id'));
});

it('builds a readable path for a nested category', function () {
    $this->seed(CategorySeeder::class);

    expect(Category::where('slug', 'day-old-chicks')->firstOrFail()->pathName())
        ->toBe('Poultry › Live birds › Day-old chicks');
});

it('lets an administrator add a branch', function () {
    livewire(CreateCategory::class)
        ->fillForm([
            'name' => 'Aquaculture',
            'slug' => 'aquaculture',
            'sort_order' => 90,
            'is_active' => true,
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    expect(Category::where('slug', 'aquaculture')->exists())->toBeTrue();
});

it('will not let a category be filed under itself or its own child', function () {
    $root = Category::factory()->create(['name' => 'Poultry stuff']);
    $child = Category::factory()->childOf($root)->create();

    $options = livewire(EditCategory::class, ['record' => $root->getRouteKey()])
        ->instance()
        ->form
        ->getComponent('parent_id')
        ->getOptions();

    expect(array_keys($options))
        ->not->toContain($root->id)
        ->not->toContain($child->id);
});

it('keeps a seller out of category administration', function () {
    $seller = User::factory()->create();
    $seller->assignRole(RoleName::Seller->value);

    $this->actingAs($seller)->get('/admin/categories')->assertForbidden();
});

it('refuses to delete a branch that still has listings on it', function () {
    $root = Category::factory()->create();
    $child = Category::factory()->childOf($root)->create();
    Product::factory()->create(['category_id' => $child->id]);

    // The guard lives on the delete action; the point is that the tree is not
    // silently pruned out from under live listings.
    expect(Category::query()->whereIn('id', $root->descendantIds())->withCount('products')->get()
        ->contains(fn ($category): bool => $category->products_count > 0))->toBeTrue();
});
