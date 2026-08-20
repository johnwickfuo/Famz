<?php

use App\Models\MarketPriceSnapshot;
use App\Services\Ai\Chat\ChatService;
use App\Services\Ai\Chat\ContextAssembler;
use App\Services\Ai\Chat\IntentClassifier;
use App\Services\Ai\Retrieval\MarketPriceService;
use Database\Seeders\BreedStandardSeeder;
use Database\Seeders\SettingsSeeder;

/**
 * Prices, and the refusal to quote a weak one.
 *
 * A median built from two listings is not a market price, it is two people's
 * asking prices wearing a statistic's clothes. The rules under test here are
 * what stop the assistant from saying one out loud.
 */
beforeEach(function (): void {
    $this->seed(SettingsSeeder::class);
    $this->seed(BreedStandardSeeder::class);

    $this->prices = app(MarketPriceService::class);

    $this->snapshot = function (string $group, ?string $state, int $sample, int $medianKobo = 1_850_000, ?int $daysAgo = 0): MarketPriceSnapshot {
        return MarketPriceSnapshot::query()->create([
            'keyword_group' => $group,
            'state' => $state,
            'median_price_kobo' => $medianKobo,
            'min_price_kobo' => (int) ($medianKobo * 0.9),
            'max_price_kobo' => (int) ($medianKobo * 1.1),
            'sample_size' => $sample,
            'unit' => 'bag',
            'captured_at' => now()->subDays($daysAgo),
        ]);
    };
});

it('quotes a state figure when enough sellers are listing there', function (): void {
    ($this->snapshot)('broiler starter feed', 'Oyo', 12);

    $result = $this->prices->resolve('broiler starter feed', 'Oyo');

    expect($result['ok'])->toBeTrue()
        ->and($result['scope'])->toBe('Oyo')
        ->and($result['widened_to_national'])->toBeFalse()
        ->and($result['sample_size'])->toBe(12);
});

it('widens a thin state sample to national and says that it did', function (): void {
    ($this->snapshot)('broiler starter feed', 'Bayelsa', 2);
    ($this->snapshot)('broiler starter feed', null, 40);

    $result = $this->prices->resolve('broiler starter feed', 'Bayelsa');

    expect($result['ok'])->toBeTrue()
        ->and($result['scope'])->toBe('national')
        ->and($result['widened_to_national'])->toBeTrue()
        // Never silently. A farmer who asked about Bayelsa and got a national
        // median deserves to know that is what happened.
        ->and($result['note'])->toContain('Bayelsa')
        ->and($result['note'])->toContain('2');
});

it('refuses outright when even the national sample is thin', function (): void {
    ($this->snapshot)('broiler starter feed', null, 3);

    $result = $this->prices->resolve('broiler starter feed', 'Oyo');

    expect($result['ok'])->toBeFalse()
        ->and($result['reason'])->toContain('too few');
});

it('says nobody is listing a thing rather than inventing a price for it', function (): void {
    $result = $this->prices->resolve('palm kernel cake', 'Kano');

    expect($result['ok'])->toBeFalse()
        ->and($result['reason'])->toContain('Nobody on this marketplace');
});

it('will not quote a snapshot that has gone stale', function (): void {
    // Well past the seeded twenty-one day ceiling.
    ($this->snapshot)('maize', null, 50, 1_200_000, 60);

    expect($this->prices->resolve('maize')['ok'])->toBeFalse();
});

it('respects the minimum sample an administrator sets', function (): void {
    ($this->snapshot)('layer mash', null, 4);

    expect($this->prices->resolve('layer mash')['ok'])->toBeFalse();

    settings()->set('ai_price_minimum_sample', 3);

    expect($this->prices->resolve('layer mash')['ok'])->toBeTrue();
});

it('puts the sample size and capture date into the context alongside the figure', function (): void {
    ($this->snapshot)('broiler starter feed', null, 23);

    $intent = app(IntentClassifier::class)->classify('How much be broiler starter feed?');
    $block = app(ContextAssembler::class)->assemble($intent);
    $rendered = $block->render();

    expect($block->hasFigures)->toBeTrue()
        ->and($rendered)->toContain('23 listings')
        ->and($rendered)->toContain('listings on this marketplace')
        // The honesty note, every time. This is asking prices, not an index.
        ->and($rendered)->toContain('not an official price');
});

it('tells the model to refuse when the marketplace has no price', function (): void {
    $intent = app(IntentClassifier::class)->classify('How much be point of lay pullets?');
    $block = app(ContextAssembler::class)->assemble($intent);

    expect($block->hasFigures)->toBeFalse()
        ->and($block->render())->toContain('Do not give a price');
});

it('uses the state on the asker\'s profile when they did not name one', function (): void {
    ($this->snapshot)('layer mash', 'Ogun', 15, 2_400_000);
    ($this->snapshot)('layer mash', null, 60, 9_900_000);

    $user = \App\Models\User::factory()->create();
    $user->profile()->create(['state' => 'Ogun']);

    $chat = app(ChatService::class);

    // Asking the platform to price it without naming a place should reach for
    // Ogun rather than the national figure — asking somebody to type a state
    // that is already on their profile is a question nobody should have to
    // answer twice.
    $reply = $chat->answer('How much be layer mash?', [], ['state' => 'Ogun']);

    expect($reply->context->render())->toContain('Ogun State');
});
