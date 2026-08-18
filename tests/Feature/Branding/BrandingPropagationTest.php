<?php

use App\Documents\CompletionCertificate;
use App\Mail\WelcomeMail;
use App\Models\User;
use App\Services\Branding\BrandingKey;
use App\Services\Branding\BrandingService;
use App\Services\Settings\SettingsService;
use Illuminate\Support\Facades\Storage;

/**
 * The promise this platform makes: an administrator types a company name into
 * Settings and it appears everywhere, with no code change and no redeploy.
 *
 * These tests hold that promise to three very different surfaces — a rendered
 * page header, a rendered email and a rendered PDF — and check that changing
 * the name changes all three.
 */
function renameCompanyTo(string $name, array $extra = []): void
{
    app(SettingsService::class)->setMany([
        BrandingKey::Name->value => $name,
        ...$extra,
    ], BrandingKey::GROUP);
}

it('renders the seeded fallback when the company has not been named yet', function () {
    $this->seed(\Database\Seeders\SettingsSeeder::class);

    expect(app(BrandingService::class)->name())->toBe(config('app.name'));
});

it('shows a new company name in the page header', function () {
    renameCompanyTo('Ilorin Grainstore');

    $header = $this->get('/')->assertOk()->getContent();

    expect($header)->toContain('Ilorin Grainstore');

    // And a second rename replaces it, rather than sitting behind a stale cache.
    renameCompanyTo('Zaria Layer House');

    $header = $this->get('/')->assertOk()->getContent();

    expect($header)
        ->toContain('Zaria Layer House')
        ->not->toContain('Ilorin Grainstore');
});

it('shows a new company name in a rendered email', function () {
    $user = User::factory()->create();

    renameCompanyTo('Ilorin Grainstore');

    $first = (new WelcomeMail($user))->render();
    expect($first)->toContain('Ilorin Grainstore');

    renameCompanyTo('Zaria Layer House');

    $second = (new WelcomeMail($user))->render();

    expect($second)
        ->toContain('Zaria Layer House')
        ->not->toContain('Ilorin Grainstore');
});

it('puts the company details in the email subject and sender', function () {
    $user = User::factory()->create();

    renameCompanyTo('Ilorin Grainstore', [
        BrandingKey::Email->value => 'hello@example.test',
    ]);

    $envelope = (new WelcomeMail($user))->envelope();

    expect($envelope->subject)->toContain('Ilorin Grainstore')
        ->and($envelope->from->name)->toBe('Ilorin Grainstore');
});

it('shows a new company name in a rendered PDF certificate', function () {
    $user = User::factory()->create(['name' => 'Aisha Bello']);

    renameCompanyTo('Ilorin Grainstore', [
        BrandingKey::RcNumber->value => '1234567',
    ]);

    $certificate = CompletionCertificate::for($user, 'Brooder management', 'CERT-TEST0001');

    expect($certificate->render())
        ->toContain('Ilorin Grainstore')
        ->toContain('Aisha Bello')
        ->toContain('CERT-TEST0001');

    renameCompanyTo('Zaria Layer House');

    expect(CompletionCertificate::for($user, 'Brooder management', 'CERT-TEST0001')->render())
        ->toContain('Zaria Layer House')
        ->not->toContain('Ilorin Grainstore');
});

it('produces a real PDF carrying the current name', function () {
    $user = User::factory()->create(['name' => 'Aisha Bello']);

    renameCompanyTo('Ilorin Grainstore');

    $bytes = CompletionCertificate::for($user, 'Brooder management', 'CERT-TEST0002')->output();

    expect($bytes)->toStartWith('%PDF-')
        ->and(strlen($bytes))->toBeGreaterThan(1000);
});

it('falls back to a wordmark when no logo has been uploaded', function () {
    renameCompanyTo('Ilorin Grainstore');

    $branding = app(BrandingService::class);

    expect($branding->hasLogo())->toBeFalse()
        ->and($branding->logoUrl())->toBeNull()
        ->and($branding->initials())->toBe('IG');

    // The site header and the certificate both fall back to the name itself.
    expect($this->get('/')->getContent())->toContain('Ilorin Grainstore');
});

it('uses an uploaded logo once one exists, and the main logo for dark surfaces until a dark one is set', function () {
    Storage::fake('public');
    Storage::disk('public')->put('branding/logo.png', 'not-really-a-png');

    renameCompanyTo('Ilorin Grainstore', [
        BrandingKey::Logo->value => 'branding/logo.png',
    ]);

    $branding = app(BrandingService::class);

    expect($branding->hasLogo())->toBeTrue()
        ->and($branding->logoUrl())->toContain('branding/logo.png')
        ->and($branding->logoDarkUrl())->toBe($branding->logoUrl());

    Storage::disk('public')->put('branding/logo-dark.png', 'not-really-a-png-either');

    renameCompanyTo('Ilorin Grainstore', [
        BrandingKey::Logo->value => 'branding/logo.png',
        BrandingKey::LogoDark->value => 'branding/logo-dark.png',
    ]);

    expect(app(BrandingService::class)->logoDarkUrl())->toContain('branding/logo-dark.png');
});

it('drops the cached identity the moment a branding setting is written', function () {
    renameCompanyTo('Ilorin Grainstore');

    // Warm the cache.
    expect(app(BrandingService::class)->name())->toBe('Ilorin Grainstore');
    expect(cache()->get(BrandingService::CACHE_KEY))->not->toBeNull();

    app(SettingsService::class)->set(BrandingKey::Name->value, 'Zaria Layer House');

    expect(cache()->get(BrandingService::CACHE_KEY))->toBeNull();
});

it('leaves the cached identity alone when an unrelated setting is written', function () {
    renameCompanyTo('Ilorin Grainstore');

    expect(app(BrandingService::class)->name())->toBe('Ilorin Grainstore');
    expect(cache()->get(BrandingService::CACHE_KEY))->not->toBeNull();

    app(SettingsService::class)->set('quote_validity_days', 45, 'int', 'platform');

    expect(cache()->get(BrandingService::CACHE_KEY))->not->toBeNull();
});

it('expands company placeholders in content an administrator wrote', function () {
    renameCompanyTo('Ilorin Grainstore', [
        BrandingKey::ShortName->value => 'Grainstore',
        BrandingKey::Phone->value => '0803 000 0000',
    ]);

    expect(branded('Call {company_short} on {company_phone} — {company} is here to help.'))
        ->toBe('Call Grainstore on 0803 000 0000 — Ilorin Grainstore is here to help.');
});

it('tolerates a very short name and a very long one', function (string $name) {
    renameCompanyTo($name);

    $page = $this->get('/')->assertOk()->getContent();
    expect($page)->toContain(e($name));

    $user = User::factory()->create();
    expect((new WelcomeMail($user))->render())->toContain(e($name));

    expect(CompletionCertificate::for($user, 'Brooder management', 'CERT-X')->render())
        ->toContain(e($name));
})->with([
    'two letters' => 'AF',
    'one word' => 'Grainstore',
    'a long, many-word name' => 'The Northern Nigeria Poultry and Feed Growers Cooperative Society',
]);
