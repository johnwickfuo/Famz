<?php

use App\Enums\RoleName;
use App\Filament\Admin\Pages\ManageSettings;
use App\Models\User;
use App\Services\Branding\BrandingKey;
use App\Services\Branding\BrandingService;
use App\Services\Settings\SettingsService;
use Database\Seeders\RoleSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

use function Pest\Livewire\livewire;

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    $this->seed(SettingsSeeder::class);

    $this->admin = User::factory()->create();
    $this->admin->assignRole(RoleName::Admin->value);

    $this->actingAs($this->admin);
});

it('is reachable by an administrator', function () {
    $this->get('/admin/manage-settings')->assertOk();
});

it('is not reachable by anyone else', function () {
    $seller = User::factory()->create();
    $seller->assignRole(RoleName::Seller->value);

    $this->actingAs($seller)->get('/admin/manage-settings')->assertForbidden();
});

it('loads the current settings into the form', function () {
    app(SettingsService::class)->setMany([
        BrandingKey::Name->value => 'Ilorin Grainstore',
        BrandingKey::ShortName->value => 'Grainstore',
    ], BrandingKey::GROUP);

    livewire(ManageSettings::class)
        ->assertSchemaStateSet([
            BrandingKey::Name->value => 'Ilorin Grainstore',
            BrandingKey::ShortName->value => 'Grainstore',
            'quote_validity_days' => 30,
            'settlement_driver' => 'escrow',
        ]);
});

it('writes the company name through, and the site follows immediately', function () {
    livewire(ManageSettings::class)
        ->fillForm([
            BrandingKey::Name->value => 'Ilorin Grainstore',
            BrandingKey::ShortName->value => 'Grainstore',
            BrandingKey::Tagline->value => 'Feed, chicks and the know-how.',
            BrandingKey::Email->value => 'hello@example.test',
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    expect(app(SettingsService::class)->string(BrandingKey::Name->value))->toBe('Ilorin Grainstore')
        ->and(app(BrandingService::class)->name())->toBe('Ilorin Grainstore');

    $this->get('/')->assertOk()->assertSee('Ilorin Grainstore', escape: false);
});

it('writes the platform rules through', function () {
    livewire(ManageSettings::class)
        ->fillForm([
            'marketplace_commission_percent' => 7.5,
            'consultation_standard_response_hours' => 24,
            'consultation_urgent_response_hours' => 4,
            'buyer_request_expiry_days' => 21,
            'quote_validity_days' => 45,
            'settlement_driver' => 'instant',
            'active_payment_gateway' => 'flutterwave',
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    $settings = app(SettingsService::class);

    expect($settings->float('marketplace_commission_percent'))->toBe(7.5)
        ->and($settings->integer('consultation_standard_response_hours'))->toBe(24)
        ->and($settings->integer('consultation_urgent_response_hours'))->toBe(4)
        ->and($settings->integer('buyer_request_expiry_days'))->toBe(21)
        ->and($settings->integer('quote_validity_days'))->toBe(45)
        ->and($settings->string('settlement_driver'))->toBe('instant')
        ->and($settings->string('active_payment_gateway'))->toBe('flutterwave');
});

it('stores an uploaded logo and starts using it everywhere', function () {
    Storage::fake('public');

    livewire(ManageSettings::class)
        ->fillForm([
            BrandingKey::Name->value => 'Ilorin Grainstore',
            BrandingKey::Logo->value => [UploadedFile::fake()->image('logo.png', 240, 80)],
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    $stored = app(SettingsService::class)->string(BrandingKey::Logo->value);

    expect($stored)->not->toBeNull();
    Storage::disk('public')->assertExists($stored);

    $branding = app(BrandingService::class);

    expect($branding->hasLogo())->toBeTrue()
        ->and($branding->logoUrl())->toContain($stored);
});

it('rejects a commission outside 0 to 100', function () {
    livewire(ManageSettings::class)
        ->fillForm(['marketplace_commission_percent' => 250])
        ->call('save')
        ->assertHasFormErrors(['marketplace_commission_percent']);
});

it('refuses a settlement driver the application does not have', function () {
    livewire(ManageSettings::class)
        ->fillForm(['settlement_driver' => 'direct'])
        ->call('save')
        ->assertHasFormErrors(['settlement_driver']);

    expect(app(SettingsService::class)->string('settlement_driver'))->toBe('escrow');
});
