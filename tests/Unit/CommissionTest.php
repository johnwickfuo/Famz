<?php

use App\Support\Commission;

/**
 * The split between a seller and the platform.
 *
 * This is the arithmetic every other money test depends on, so it is tested
 * exhaustively rather than by example: the invariant is checked across tens of
 * thousands of generated inputs, because a rounding bug that shows up on one
 * amount in ten thousand is still a bug that loses somebody money.
 */
it('splits a whole sale exactly', function () {
    $split = Commission::on(1_850_000, 5);

    expect($split->commissionKobo)->toBe(92_500)
        ->and($split->payoutKobo)->toBe(1_757_500)
        ->and($split->balances())->toBeTrue();
});

it('handles a fractional percentage without losing kobo', function () {
    $split = Commission::on(1_850_000, 7.5);

    expect($split->commissionKobo)->toBe(138_750)
        ->and($split->payoutKobo)->toBe(1_711_250)
        ->and($split->balances())->toBeTrue();
});

it('always balances, whatever the amount and rate', function () {
    // Every combination that could plausibly arise, plus the awkward ones.
    $amounts = [0, 1, 2, 3, 7, 99, 100, 101, 999, 1_000, 12_345, 1_850_000, 999_999_999, 100_000_000_000];
    $percents = [0, 0.01, 0.5, 1, 2.25, 5, 7.5, 10, 12.75, 33.33, 50, 99.99, 100];

    foreach ($amounts as $amount) {
        foreach ($percents as $percent) {
            $split = Commission::on($amount, $percent);

            expect($split->balances())->toBeTrue(
                "commission + payout != subtotal for {$amount} at {$percent}%"
            );

            expect($split->commissionKobo)->toBeGreaterThanOrEqual(0)
                ->and($split->payoutKobo)->toBeGreaterThanOrEqual(0)
                ->and($split->commissionKobo)->toBeLessThanOrEqual($amount);
        }
    }
});

it('never drifts across many random sales', function () {
    // A float-based implementation passes a handful of examples and fails
    // somewhere in here.
    mt_srand(20260319);

    for ($i = 0; $i < 20_000; $i++) {
        $amount = mt_rand(1, 5_000_000_000);
        $percent = mt_rand(0, 3_000) / 100;

        $split = Commission::on($amount, $percent);

        expect($split->commissionKobo + $split->payoutKobo)->toBe($amount);
    }
});

it('sums a basket without drift', function () {
    // The number that matters to the platform is the total across many orders,
    // which is where per-order rounding errors would accumulate.
    mt_srand(999);

    $subtotalTotal = 0;
    $commissionTotal = 0;
    $payoutTotal = 0;

    for ($i = 0; $i < 5_000; $i++) {
        $amount = mt_rand(100, 200_000_000);
        $split = Commission::on($amount, 7.5);

        $subtotalTotal += $split->subtotalKobo;
        $commissionTotal += $split->commissionKobo;
        $payoutTotal += $split->payoutKobo;
    }

    expect($commissionTotal + $payoutTotal)->toBe($subtotalTotal);
});

it('rounds half up on the half kobo', function () {
    // 1% of 50 kobo is exactly half a kobo.
    expect(Commission::on(50, 1)->commissionKobo)->toBe(1);

    // Just under half rounds down.
    expect(Commission::on(49, 1)->commissionKobo)->toBe(0);
});

it('cannot take more than the whole sale', function () {
    $split = Commission::on(1_000, 100);

    expect($split->commissionKobo)->toBe(1_000)
        ->and($split->payoutKobo)->toBe(0);
});

it('converts a percentage to basis points', function () {
    expect(Commission::toBasisPoints(5))->toBe(500)
        ->and(Commission::toBasisPoints(7.5))->toBe(750)
        ->and(Commission::toBasisPoints('2.25'))->toBe(225)
        ->and(Commission::toBasisPoints(0))->toBe(0);
});

it('refuses a nonsensical rate rather than quietly charging it', function (float $percent) {
    // A settings typo turning 5% into 500% should stop, not bill.
    expect(fn () => Commission::on(1_000, $percent))->toThrow(InvalidArgumentException::class);
})->with([-1, 100.01, 500, 1000]);

it('refuses a negative subtotal', function () {
    expect(fn () => Commission::on(-1, 5))->toThrow(InvalidArgumentException::class);
});
