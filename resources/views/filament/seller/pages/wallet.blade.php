<x-filament-panels::page>
    <div class="grid gap-4 sm:grid-cols-3">
        <div class="fi-section rounded-xl bg-white p-6 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
            <p class="text-sm font-medium text-gray-500 dark:text-gray-400">{{ __('Available now') }}</p>
            <p class="mt-1 text-3xl font-bold tracking-tight text-gray-950 dark:text-white">{{ $available }}</p>
        </div>

        <div class="fi-section rounded-xl bg-white p-6 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
            <p class="text-sm font-medium text-gray-500 dark:text-gray-400">{{ __('Held until delivery') }}</p>
            <p class="mt-1 text-3xl font-bold tracking-tight text-gray-950 dark:text-white">{{ $held }}</p>
            <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                {{ __('Released when the buyer confirms, or automatically afterwards.') }}
            </p>
        </div>

        <div class="fi-section rounded-xl bg-white p-6 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
            <p class="text-sm font-medium text-gray-500 dark:text-gray-400">{{ __('Earned altogether') }}</p>
            <p class="mt-1 text-3xl font-bold tracking-tight text-gray-950 dark:text-white">{{ $lifetime }}</p>
        </div>
    </div>

    <div class="fi-section mt-6 overflow-hidden rounded-xl bg-white shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
        <table class="w-full text-sm">
            <thead class="bg-gray-50 text-left text-xs uppercase tracking-wide text-gray-500 dark:bg-white/5 dark:text-gray-400">
                <tr>
                    <th class="px-4 py-3">{{ __('Date') }}</th>
                    <th class="px-4 py-3">{{ __('What for') }}</th>
                    <th class="px-4 py-3">{{ __('Status') }}</th>
                    <th class="px-4 py-3 text-right">{{ __('Amount') }}</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100 dark:divide-white/10">
                @forelse ($entries as $entry)
                    <tr>
                        <td class="whitespace-nowrap px-4 py-3 text-gray-500 dark:text-gray-400">{{ $entry['date'] }}</td>
                        <td class="px-4 py-3">
                            {{ $entry['description'] }}
                            @if ($entry['reference'])
                                <span class="block text-xs text-gray-500 dark:text-gray-400">{{ $entry['reference'] }}</span>
                            @endif
                        </td>
                        <td class="px-4 py-3 text-gray-500 dark:text-gray-400">{{ $entry['state'] }}</td>
                        <td class="whitespace-nowrap px-4 py-3 text-right font-semibold {{ $entry['positive'] ? 'text-success-600 dark:text-success-400' : 'text-danger-600 dark:text-danger-400' }}">
                            {{ $entry['amount'] }}
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="4" class="px-4 py-8 text-center text-gray-500 dark:text-gray-400">
                            {{ __('Nothing yet. Your first sale will show up here.') }}
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</x-filament-panels::page>
