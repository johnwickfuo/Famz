<x-filament-panels::page>
    {{ $this->form }}

    {{-- The answer first, in one sentence, before any table. --}}
    <div @class([
        'fi-section rounded-xl p-6 ring-1',
        'bg-success-50 ring-success-600/20 dark:bg-success-500/10 dark:ring-success-500/30' => $balanced,
        'bg-danger-50 ring-danger-600/20 dark:bg-danger-500/10 dark:ring-danger-500/30' => ! $balanced,
    ])>
        <p class="text-lg font-semibold text-gray-950 dark:text-white">
            @if ($balanced)
                {{ __('The books agree.') }}
            @else
                {{ __('The books are out by :amount.', ['amount' => $money(abs($figures['difference_kobo']))]) }}
            @endif
        </p>

        <p class="mt-1 text-sm text-gray-600 dark:text-gray-400">
            @if ($balanced)
                {{ __('Every naira charged in this period is accounted for in the ledger.') }}
            @else
                {{ __('A payment that landed after the period closed will do this. So will a bug. Widen the dates first, then look at the ledger for the days at each end.') }}
            @endif
        </p>
    </div>

    <div class="fi-section overflow-hidden rounded-xl bg-white shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
        <table class="w-full text-sm">
            <tbody class="divide-y divide-gray-200 dark:divide-white/10">
                @foreach ($rows as $row)
                    <tr>
                        <td class="px-6 py-4">
                            <p @class(['text-gray-950 dark:text-white', 'font-semibold' => $row['strong'] ?? false])>
                                {{ $row['label'] }}
                            </p>
                            <p class="text-xs text-gray-500 dark:text-gray-400">{{ $row['help'] }}</p>
                        </td>
                        <td @class([
                            'px-6 py-4 text-right tabular-nums text-gray-950 dark:text-white',
                            'font-bold' => $row['strong'] ?? false,
                        ])>
                            {{ $row['value'] }}
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>

    <div class="grid gap-4 sm:grid-cols-3">
        <div class="fi-section rounded-xl bg-white p-6 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
            <p class="text-sm font-medium text-gray-500 dark:text-gray-400">{{ __('Held for other people') }}</p>
            <p class="mt-1 text-2xl font-bold tracking-tight text-gray-950 dark:text-white">
                {{ $money($figures['owed_to_sellers_kobo']) }}
            </p>
            <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                {{ __('Seller balances and escrow. This much of the bank account is not ours.') }}
            </p>
        </div>

        <div class="fi-section rounded-xl bg-white p-6 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
            <p class="text-sm font-medium text-gray-500 dark:text-gray-400">{{ __('Paid out in this period') }}</p>
            <p class="mt-1 text-2xl font-bold tracking-tight text-gray-950 dark:text-white">
                {{ $money($figures['paid_out_kobo']) }}
            </p>
            <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                {{ __('Transfers sent to sellers\' banks.') }}
            </p>
        </div>

        <div class="fi-section rounded-xl bg-white p-6 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
            <p class="text-sm font-medium text-gray-500 dark:text-gray-400">{{ __('The platform\'s own balance') }}</p>
            <p class="mt-1 text-2xl font-bold tracking-tight text-gray-950 dark:text-white">
                {{ $money($figures['platform_balance_kobo']) }}
            </p>
            <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                {{ __('Commission earned and released.') }}
            </p>
        </div>
    </div>
</x-filament-panels::page>
