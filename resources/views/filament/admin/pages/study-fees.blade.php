<x-filament-panels::page>
    {{ $this->form }}

    {{-- The ratio first. It is the number the pricing decision turns on. --}}
    <div class="fi-section rounded-xl bg-white p-6 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
        @if ($credited_share === null)
            <p class="text-lg font-semibold text-gray-950 dark:text-white">
                {{ __('No study fees have been paid in this period.') }}
            </p>
        @else
            <p class="text-lg font-semibold text-gray-950 dark:text-white">
                {{ __(':share% of what was charged for studies came back off a project.', ['share' => $credited_share]) }}
            </p>
            <p class="mt-1 text-sm text-gray-600 dark:text-gray-400">
                {{ __('A high share means the fee is working as a deposit on real projects. A low one means it is a revenue line in its own right — either is fine, but pricing it needs to know which.') }}
            </p>
        @endif
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
                            'px-6 py-4 text-right tabular-nums',
                            'font-bold' => $row['strong'] ?? false,
                            'text-success-600 dark:text-success-400' => ($row['tone'] ?? null) === 'success',
                            'text-danger-600 dark:text-danger-400' => ($row['tone'] ?? null) === 'danger',
                            'text-gray-950 dark:text-white' => ! isset($row['tone']),
                        ])>
                            {{ $row['value'] }}
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>

    {{--
        The fees nobody has decided about.

        This is the list that prevents the argument. A paid fee with no
        recorded decision is fine this week and indefensible next year.
    --}}
    <div class="fi-section overflow-hidden rounded-xl bg-white shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
        <div class="px-6 py-4">
            <h2 class="font-semibold text-gray-950 dark:text-white">{{ __('Nobody has decided about these') }}</h2>
            <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                {{ __('Paid, and no credit decision recorded against them. Deciding takes a moment now and is impossible to reconstruct in a year.') }}
            </p>
        </div>

        @if (count($undecided) === 0)
            <p class="border-t border-gray-200 px-6 py-6 text-sm text-gray-500 dark:border-white/10 dark:text-gray-400">
                {{ __('Every paid study fee has a decision recorded against it.') }}
            </p>
        @else
            <div class="overflow-x-auto border-t border-gray-200 dark:border-white/10">
                <table class="w-full text-sm">
                    <thead class="bg-gray-50 text-left text-xs uppercase tracking-wider text-gray-500 dark:bg-white/5 dark:text-gray-400">
                        <tr>
                            <th class="px-6 py-3 font-medium">{{ __('Request') }}</th>
                            <th class="px-6 py-3 font-medium">{{ __('Client') }}</th>
                            <th class="px-6 py-3 text-right font-medium">{{ __('Amount') }}</th>
                            <th class="px-6 py-3 font-medium">{{ __('Paid') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-200 dark:divide-white/10">
                        @foreach ($undecided as $fee)
                            <tr>
                                <td class="whitespace-nowrap px-6 py-3 font-medium text-gray-950 dark:text-white">
                                    @if ($fee['url'])
                                        <a href="{{ $fee['url'] }}" class="hover:underline">{{ $fee['reference'] }}</a>
                                    @else
                                        {{ $fee['reference'] }}
                                    @endif
                                </td>
                                <td class="px-6 py-3 text-gray-600 dark:text-gray-400">{{ $fee['client'] }}</td>
                                <td class="whitespace-nowrap px-6 py-3 text-right tabular-nums text-gray-950 dark:text-white">
                                    {{ $fee['amount'] }}
                                </td>
                                <td class="whitespace-nowrap px-6 py-3 text-gray-600 dark:text-gray-400">
                                    {{ $fee['paid_at'] }}
                                    <span class="text-xs text-gray-400">· {{ $fee['waiting'] }}</span>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>
</x-filament-panels::page>
