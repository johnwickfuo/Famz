<x-filament-panels::page>
    @if ($total === 0)
        <div class="fi-section rounded-xl bg-white p-6 text-center shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
            <p class="font-semibold text-gray-950 dark:text-white">{{ __('Nothing is waiting') }}</p>
            <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                {{ __('No disputes, applications, listings, requests or reviews need a decision right now.') }}
            </p>
        </div>
    @else
        <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
            @foreach ($rows as $row)
                <a
                    href="{{ $row['url'] }}"
                    @class([
                        'fi-section block rounded-xl p-5 shadow-sm ring-1 transition hover:shadow-md',
                        'bg-white ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10' => ! ($row['urgent'] && $row['count'] > 0),
                        {{-- Red only for a frozen dispute. A page where everything is red is a page where nothing is. --}}
                        'bg-danger-50 ring-danger-600/20 dark:bg-danger-500/10 dark:ring-danger-400/30' => $row['urgent'] && $row['count'] > 0,
                    ])
                >
                    <div class="flex items-baseline justify-between gap-3">
                        <p class="text-xs uppercase tracking-wider text-gray-500 dark:text-gray-400">
                            {{ $row['label'] }}
                        </p>
                        <p @class([
                            'text-2xl font-bold tabular-nums',
                            'text-gray-950 dark:text-white' => $row['count'] === 0,
                            'text-danger-600 dark:text-danger-400' => $row['urgent'] && $row['count'] > 0,
                            'text-warning-600 dark:text-warning-400' => ! $row['urgent'] && $row['count'] > 0,
                        ])>{{ number_format($row['count']) }}</p>
                    </div>

                    {{-- Why the delay matters, not what the queue is called. --}}
                    <p class="mt-2 text-xs text-gray-500 dark:text-gray-400">{{ $row['why'] }}</p>
                </a>
            @endforeach
        </div>

        <p class="text-xs text-gray-500 dark:text-gray-400">
            {{ __('Each of these opens the screen built for that work. Decisions are made there, with the whole record in front of you — not from a count on this page.') }}
        </p>
    @endif
</x-filament-panels::page>
