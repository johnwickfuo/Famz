<x-filament-panels::page>
    {{ $this->form }}

    {{-- Revenue by stream, for the chosen period. --}}
    <div class="fi-section rounded-xl bg-white p-5 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
        <div class="flex flex-wrap items-baseline justify-between gap-2">
            <h2 class="font-semibold text-gray-950 dark:text-white">{{ __('Revenue by stream') }}</h2>
            <p class="text-lg font-bold tabular-nums text-gray-950 dark:text-white">{{ $total }}</p>
        </div>

        <div class="mt-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-5">
            @foreach ($streams as $stream)
                <div class="rounded-lg bg-gray-50 p-3 dark:bg-white/5">
                    <p class="text-xs uppercase tracking-wider text-gray-500 dark:text-gray-400">{{ $stream['label'] }}</p>
                    <p class="mt-1 text-lg font-bold tabular-nums text-gray-950 dark:text-white">{{ $stream['money'] }}</p>
                </div>
            @endforeach
        </div>

        <p class="mt-3 text-xs text-gray-500 dark:text-gray-400">
            {{ __('Read from the wallet ledger, which is the record the money actually moved through.') }}
        </p>
    </div>

    {{--
        Twelve months, stacked. Bars are sized with inline styles rather than
        utility classes: an arbitrary height only exists in the compiled
        stylesheet if Tailwind's scan happened to see it, and a chart that
        silently renders flat is worse than no chart.
    --}}
    <div class="fi-section rounded-xl bg-white p-5 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
        <h2 class="font-semibold text-gray-950 dark:text-white">{{ __('The last twelve months') }}</h2>

        <div class="mt-5 overflow-x-auto">
            <div class="flex items-end gap-2" style="min-width: 32rem; height: 12rem">
                @foreach ($months as $month)
                    <div class="flex flex-1 flex-col items-center justify-end gap-1" style="height: 100%">
                        <span class="text-2xs tabular-nums text-gray-500 dark:text-gray-400">
                            {{ $month['total'] > 0 ? number_format($month['total'] / 100000, 0).'k' : '' }}
                        </span>
                        <div
                            class="w-full rounded-t bg-primary-500"
                            style="height: {{ max(1, (int) round(($month['total'] / $peak) * 100)) }}%"
                            title="{{ $month['label'] }}"
                        ></div>
                        <span class="text-2xs text-gray-500 dark:text-gray-400">{{ \Illuminate\Support\Str::substr($month['label'], 0, 3) }}</span>
                    </div>
                @endforeach
            </div>
        </div>
    </div>

    <div class="grid gap-4 lg:grid-cols-2">
        {{-- Growth --}}
        <div class="fi-section overflow-hidden rounded-xl bg-white shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
            <div class="px-6 py-4">
                <h2 class="font-semibold text-gray-950 dark:text-white">{{ __('People joining') }}</h2>
                <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                    {{ __('New accounts each month, and the running total. A flat month on a growing base is a different story from a flat month on a shrinking one.') }}
                </p>
            </div>

            <div class="overflow-x-auto border-t border-gray-200 dark:border-white/10">
                <table class="w-full text-sm">
                    <thead class="bg-gray-50 text-left text-xs uppercase tracking-wider text-gray-500 dark:bg-white/5 dark:text-gray-400">
                        <tr>
                            <th class="px-6 py-3 font-medium">{{ __('Month') }}</th>
                            <th class="px-6 py-3 text-right font-medium">{{ __('New') }}</th>
                            <th class="px-6 py-3 text-right font-medium">{{ __('Total') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-200 dark:divide-white/10">
                        @foreach (array_reverse($growth) as $row)
                            <tr>
                                <td class="whitespace-nowrap px-6 py-2.5 text-gray-950 dark:text-white">{{ $row['label'] }}</td>
                                <td class="px-6 py-2.5 text-right tabular-nums text-gray-500 dark:text-gray-400">
                                    {{ $row['new'] > 0 ? '+'.number_format($row['new']) : '—' }}
                                </td>
                                <td class="px-6 py-2.5 text-right tabular-nums text-gray-950 dark:text-white">
                                    {{ number_format($row['total']) }}
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>

        {{-- Module activity --}}
        <div class="fi-section overflow-hidden rounded-xl bg-white shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
            <div class="px-6 py-4">
                <h2 class="font-semibold text-gray-950 dark:text-white">{{ __('What each part did') }}</h2>
                <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                    {{ __('Counts for the period above. For the free modules this is the only question there is: is anybody using it.') }}
                </p>
            </div>

            <div class="border-t border-gray-200 dark:border-white/10">
                <table class="w-full text-sm">
                    <tbody class="divide-y divide-gray-200 dark:divide-white/10">
                        @foreach ($activity as $row)
                            <tr>
                                <td class="px-6 py-2.5 text-gray-950 dark:text-white">{{ $row['label'] }}</td>
                                <td class="px-6 py-2.5 text-right tabular-nums text-gray-500 dark:text-gray-400">
                                    {{ number_format($row['count']) }}
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</x-filament-panels::page>
