<?php

namespace App\Services\Reporting;

use App\Enums\LedgerState;
use App\Enums\LedgerType;
use App\Models\User;
use App\Models\WalletTransaction;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * What the platform earned, split by where it came from.
 *
 * Five streams, and the reason for separating them is that they behave nothing
 * alike. Marketplace commission is a percentage of somebody else's trade and
 * moves with the market. Course sales are the platform's own product and are
 * nearly pure margin. Consultation and quotation fees are the company's own
 * labour and are capped by how many hours there are. An administrator looking
 * at one combined figure cannot tell a good month for the marketplace from a
 * month somebody sold four farm proposals.
 *
 * Everything reads the wallet ledger rather than the source tables. The ledger
 * is the record the money actually moved through, and counting "consultations
 * marked paid" instead would eventually disagree with it — which is the one
 * thing a revenue figure must never do.
 */
class RevenueByStream
{
    /**
     * The five streams, and how each is recognised in the ledger.
     *
     * Four are a distinct ledger type. Mentorship is not: its commission is
     * written as LedgerType::Commission on the platform account, exactly like
     * marketplace commission, so the type alone cannot tell them apart.
     *
     * What tells them apart is `mentorship_invoice_id`. A mentorship commission
     * entry carries one because it was raised against an invoice; a marketplace
     * one never does. That is a real distinction in the data rather than a
     * convention somebody has to remember, which is why it is safe to report
     * on — but it does mean marketplace commission has to EXCLUDE the rows
     * mentorship claims, or every naira of mentorship revenue would be counted
     * twice and the total would be wrong.
     *
     * @return array<string, array{label: string, types: array<int, LedgerType>, invoice: string|null}>
     */
    public static function streams(): array
    {
        return [
            'marketplace' => [
                'label' => __('Marketplace commission'),
                'types' => [LedgerType::Commission],
                // Commission with no invoice behind it.
                'invoice' => 'without',
            ],
            'mentorship' => [
                'label' => __('Mentorship commission'),
                'types' => [LedgerType::Commission],
                'invoice' => 'with',
            ],
            'courses' => [
                'label' => __('Course sales'),
                'types' => [LedgerType::CourseSale],
                'invoice' => null,
            ],
            'consultations' => [
                'label' => __('Consultations'),
                'types' => [LedgerType::ConsultationFee],
                'invoice' => null,
            ],
            'quotations' => [
                'label' => __('Farm setup study fees'),
                'types' => [LedgerType::QuotationStudyFee],
                'invoice' => null,
            ],
        ];
    }

    /**
     * Revenue by stream between two dates.
     *
     * @return array<int, array<string, mixed>>
     */
    public function between(?Carbon $from, ?Carbon $until): array
    {
        return collect(self::streams())
            ->map(fn (array $stream, string $key): array => [
                'key' => $key,
                'label' => $stream['label'],
                'kobo' => $this->sumFor($stream['types'], $stream['invoice'], $from, $until),
            ])
            ->values()
            ->all();
    }

    /**
     * Month by month, per stream, oldest first.
     *
     * Shaped for a chart: one row per month, one column per stream. Built in
     * PHP from a single grouped query rather than one query per stream per
     * month, which for five streams and a year is sixty round trips to draw
     * one picture.
     *
     * @return array<int, array<string, mixed>>
     */
    public function byMonth(int $months = 12): array
    {
        $start = now()->startOfMonth()->subMonths($months - 1);

        $rows = WalletTransaction::query()
            ->platform()
            ->whereIn('type', $this->allTypes())
            ->whereIn('state', LedgerState::spendable())
            ->where('created_at', '>=', $start)
            ->get(['type', 'amount_kobo', 'created_at', 'mentorship_invoice_id']);

        $grouped = $rows->groupBy(fn (WalletTransaction $row): string => $row->created_at->format('Y-m'));

        $months = collect(range(0, $months - 1))
            ->map(fn (int $offset): string => $start->copy()->addMonths($offset)->format('Y-m'));

        return $months->map(function (string $month) use ($grouped): array {
            $inMonth = $grouped->get($month, collect());

            $totals = collect(self::streams())->map(fn (array $_, string $key): int => 0)->all();

            foreach ($inMonth as $row) {
                $key = $this->streamKeyFor($row);

                if ($key !== null) {
                    $totals[$key] += (int) $row->amount_kobo;
                }
            }

            return [
                'month' => $month,
                'label' => Carbon::createFromFormat('Y-m', $month)->format('M Y'),
                'streams' => $totals,
                'total' => array_sum($totals),
            ];
        })->all();
    }

    /**
     * New accounts per month, oldest first.
     *
     * @return array<int, array<string, mixed>>
     */
    public function userGrowth(int $months = 12): array
    {
        $start = now()->startOfMonth()->subMonths($months - 1);

        $counts = User::query()
            ->where('created_at', '>=', $start)
            ->get(['created_at'])
            ->groupBy(fn (User $user): string => $user->created_at->format('Y-m'))
            ->map(fn (Collection $group): int => $group->count());

        $running = User::query()->where('created_at', '<', $start)->count();

        return collect(range(0, $months - 1))
            ->map(function (int $offset) use ($start, $counts, &$running): array {
                $month = $start->copy()->addMonths($offset);
                $key = $month->format('Y-m');
                $new = $counts->get($key, 0);
                $running += $new;

                return [
                    'month' => $key,
                    'label' => $month->format('M Y'),
                    'new' => $new,
                    // The running total matters as much as the monthly figure:
                    // a flat month on a growing base is a different story from
                    // a flat month on a shrinking one.
                    'total' => $running,
                ];
            })
            ->all();
    }

    /**
     * @param  array<int, LedgerType>  $types
     * @param  string|null  $invoice  'with', 'without', or null for either.
     */
    private function sumFor(array $types, ?string $invoice, ?Carbon $from, ?Carbon $until): int
    {
        return (int) WalletTransaction::query()
            ->platform()
            ->whereIn('type', $types)
            ->whereIn('state', LedgerState::spendable())
            ->when($invoice === 'with', fn ($query) => $query->whereNotNull('mentorship_invoice_id'))
            ->when($invoice === 'without', fn ($query) => $query->whereNull('mentorship_invoice_id'))
            ->when($from, fn ($query) => $query->where('created_at', '>=', $from))
            ->when($until, fn ($query) => $query->where('created_at', '<=', $until))
            ->sum('amount_kobo');
    }

    /**
     * @return array<int, LedgerType>
     */
    private function allTypes(): array
    {
        return collect(self::streams())
            ->flatMap(fn (array $stream): array => $stream['types'])
            ->all();
    }

    /**
     * Which stream one ledger row belongs to.
     *
     * Applies the same invoice rule the totals use, so a chart and the figures
     * beside it cannot disagree — which they would if one classified on type
     * alone and the other did not.
     */
    private function streamKeyFor(WalletTransaction $row): ?string
    {
        foreach (self::streams() as $key => $stream) {
            $typeMatches = in_array($row->type, $stream['types'], true);

            if (! $typeMatches) {
                continue;
            }

            $hasInvoice = $row->mentorship_invoice_id !== null;

            $invoiceMatches = match ($stream['invoice']) {
                'with' => $hasInvoice,
                'without' => ! $hasInvoice,
                default => true,
            };

            if ($invoiceMatches) {
                return $key;
            }
        }

        return null;
    }
}
