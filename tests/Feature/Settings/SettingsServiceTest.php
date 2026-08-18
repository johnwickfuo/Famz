<?php

use App\Models\Setting;
use App\Services\Settings\SettingsService;
use Database\Seeders\SettingsSeeder;
use Illuminate\Support\Facades\DB;

beforeEach(function (): void {
    $this->settings = app(SettingsService::class);
});

it('seeds the platform rules the business runs on', function () {
    $this->seed(SettingsSeeder::class);

    expect($this->settings->integer('consultation_standard_response_hours'))->toBe(48)
        ->and($this->settings->integer('consultation_urgent_response_hours'))->toBe(6)
        ->and($this->settings->integer('buyer_request_expiry_days'))->toBe(14)
        ->and($this->settings->integer('quote_validity_days'))->toBe(30)
        ->and($this->settings->string('settlement_driver'))->toBe('escrow')
        ->and($this->settings->string('active_payment_gateway'))->toBe('paystack')
        ->and($this->settings->float('marketplace_commission_percent'))->toBeFloat();
});

it('seeds the company name empty so the platform falls back', function () {
    $this->seed(SettingsSeeder::class);

    expect(Setting::query()->where('key', 'company_name')->exists())->toBeTrue()
        ->and(Setting::query()->where('key', 'company_name')->value('value'))->toBeNull()
        ->and($this->settings->string('company_name'))->toBeNull();
});

it('does not overwrite a value the administrator has already set', function () {
    $this->settings->set('quote_validity_days', 90, 'int', 'platform');

    $this->seed(SettingsSeeder::class);

    expect($this->settings->integer('quote_validity_days'))->toBe(90);
});

it('casts on the way out according to the declared type', function () {
    $this->settings->set('a_string', 'escrow');
    $this->settings->set('an_int', 7);
    $this->settings->set('a_float', 2.5);
    $this->settings->set('a_true', true);
    $this->settings->set('a_false', false);
    $this->settings->set('a_list', ['facebook' => 'https://example.test/f']);

    expect($this->settings->string('a_string'))->toBe('escrow')
        ->and($this->settings->integer('an_int'))->toBe(7)
        ->and($this->settings->float('a_float'))->toBe(2.5)
        ->and($this->settings->boolean('a_true'))->toBeTrue()
        ->and($this->settings->array('a_list'))->toBe(['facebook' => 'https://example.test/f']);

    // A stored `false` is a real value, not an absent one: it must come back as
    // false rather than falling through to the caller's default.
    expect($this->settings->boolean('a_false'))->toBeFalse()
        ->and($this->settings->get('a_false', 'fallback'))->toBeFalse();
});

it('returns the default for a key that was never set', function () {
    expect($this->settings->get('never_set', 'fallback'))->toBe('fallback')
        ->and($this->settings->integer('never_set', 12))->toBe(12)
        ->and($this->settings->array('never_set', []))->toBe([]);
});

it('treats a stored-but-empty value as not filled in yet', function () {
    $this->settings->set('company_name', '');

    expect($this->settings->string('company_name', 'fallback'))->toBe('fallback');
});

it('reads the whole table once per request rather than once per key', function () {
    $this->settings->setMany([
        'one' => 1,
        'two' => 2,
        'three' => 3,
    ], 'platform');

    DB::flushQueryLog();
    DB::enableQueryLog();

    $this->settings->get('one');
    $this->settings->get('two');
    $this->settings->get('three');

    expect(DB::getQueryLog())->toHaveCount(1);

    DB::disableQueryLog();
});

it('survives the settings table not existing yet', function () {
    // An artisan command may run before migrate. Defaults are the right answer,
    // not a fatal error.
    DB::statement('DROP TABLE settings');

    app()->forgetInstance(SettingsService::class);

    expect(app(SettingsService::class)->string('company_name', 'fallback'))->toBe('fallback');
});

it('forgets a key on request', function () {
    $this->settings->set('temporary', 'value');
    expect($this->settings->string('temporary'))->toBe('value');

    $this->settings->forget('temporary');

    expect($this->settings->has('temporary'))->toBeFalse()
        ->and($this->settings->string('temporary', 'gone'))->toBe('gone');
});

it('keeps a value in the group it was first filed under', function () {
    $this->settings->set('quote_validity_days', 30, 'int', 'platform');

    // A later write that names no group must not re-file it.
    $this->settings->set('quote_validity_days', 45);

    expect(Setting::query()->where('key', 'quote_validity_days')->value('group'))->toBe('platform')
        ->and($this->settings->integer('quote_validity_days'))->toBe(45);
});
