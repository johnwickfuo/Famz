<x-filament-panels::page>
    {{ $this->form }}

    {{--
        Today's budget first, because it is the only figure on this page that
        is about right now. Everything below it is history.
    --}}
    <div @class([
        'fi-section rounded-xl p-5 shadow-sm ring-1',
        'bg-white ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10' => ! $budget['over'],
        'bg-warning-50 ring-warning-600/20 dark:bg-warning-500/10 dark:ring-warning-400/30' => $budget['over'],
    ])>
        <div class="flex flex-wrap items-baseline justify-between gap-2">
            <h2 class="font-semibold text-gray-950 dark:text-white">{{ __("Today's token budget") }}</h2>
            <p class="text-sm tabular-nums text-gray-500 dark:text-gray-400">
                @if ($budget['limit'] > 0)
                    {{ number_format($budget['spent']) }} / {{ number_format($budget['limit']) }}
                @else
                    {{ number_format($budget['spent']) }} {{ __('spent — no ceiling set') }}
                @endif
            </p>
        </div>

        @if ($budget['limit'] > 0)
            {{--
                The bar's geometry is inline rather than in utility classes, and
                deliberately. Tailwind compiles the panel stylesheet from a scan
                of these files, so a height class used only here exists only if
                somebody rebuilt the assets after touching this view — which is
                exactly how this bar rendered at zero pixels the first time.
                A progress bar that silently has no height is worse than no bar.
                Colour stays a class: Filament ships those regardless.
            --}}
            <div
                class="mt-3 overflow-hidden rounded-full bg-gray-200 dark:bg-white/10"
                style="height: 8px"
                role="progressbar"
                aria-valuenow="{{ $budget['percent'] }}"
                aria-valuemin="0"
                aria-valuemax="100"
                aria-label="{{ __('Share of today\'s token budget used') }}"
            >
                <div
                    @class([
                        'rounded-full',
                        'bg-primary-500' => $budget['percent'] < 80,
                        'bg-warning-500' => $budget['percent'] >= 80 && ! $budget['over'],
                        'bg-danger-500' => $budget['over'],
                    ])
                    style="height: 100%; width: {{ max(2, $budget['percent']) }}%"
                ></div>
            </div>
        @endif

        <p class="mt-2 text-xs text-gray-500 dark:text-gray-400">
            @if ($budget['over'])
                {{ __('The budget is spent. The assistant is answering common questions from its cache and telling people plainly that it is busy — it is not returning errors.') }}
            @elseif ($budget['limit'] > 0)
                {{ __(':remaining tokens left today. When it runs out the assistant drops to cached answers rather than failing.', ['remaining' => number_format($budget['remaining'])]) }}
            @else
                {{ __('No daily ceiling is set, so nothing will stop the spend. Set one under platform settings.') }}
            @endif
        </p>
    </div>

    {{-- The shape of the period. --}}
    <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
        @foreach ([
            [
                'label' => __('Questions asked'),
                'value' => number_format($totals['questions']),
                'help' => __('Across :count conversation(s).', ['count' => number_format($totals['people'])]),
            ],
            [
                'label' => __('Answered from cache'),
                'value' => $totals['cache_rate'].'%',
                'help' => __(':count of :total answers cost nothing.', ['count' => number_format($totals['cached']), 'total' => number_format($totals['answered'])]),
            ],
            [
                'label' => __('Answers with figures'),
                'value' => $totals['figure_rate'].'%',
                'help' => __('The rest were husbandry questions with no number in them.'),
            ],
            [
                'label' => __('Tokens used'),
                'value' => number_format($totals['tokens']),
                'help' => $totals['median_latency'] > 0
                    ? __('Median answer took :ms ms.', ['ms' => number_format($totals['median_latency'])])
                    : __('No timed answers in this period.'),
            ],
        ] as $stat)
            <div class="fi-section rounded-xl bg-white p-5 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
                <p class="text-xs uppercase tracking-wider text-gray-500 dark:text-gray-400">{{ $stat['label'] }}</p>
                <p class="mt-1 text-2xl font-bold tabular-nums text-gray-950 dark:text-white">{{ $stat['value'] }}</p>
                <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">{{ $stat['help'] }}</p>
            </div>
        @endforeach
    </div>

    {{--
        The most useful list on this page. What people ask is a plain statement
        of what the platform's customers do not know — a course catalogue, a
        consultation pitch and a set of feeding tables worth loading, written by
        the people who need them.
    --}}
    <div class="fi-section overflow-hidden rounded-xl bg-white shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
        <div class="px-6 py-4">
            <h2 class="font-semibold text-gray-950 dark:text-white">{{ __('What people are asking') }}</h2>
            <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                {{ __('Grouped by question, not listed one by one. A question asked twenty times is a course, a consultation, or a feeding table worth adding.') }}
            </p>
        </div>

        @if (count($topQuestions) === 0)
            <p class="border-t border-gray-200 px-6 py-6 text-sm text-gray-500 dark:border-white/10 dark:text-gray-400">
                {{ __('Nobody has asked the assistant anything in this period.') }}
            </p>
        @else
            <div class="overflow-x-auto border-t border-gray-200 dark:border-white/10">
                <table class="w-full text-sm">
                    <thead class="bg-gray-50 text-left text-xs uppercase tracking-wider text-gray-500 dark:bg-white/5 dark:text-gray-400">
                        <tr>
                            <th class="px-6 py-3 font-medium">{{ __('Question') }}</th>
                            <th class="px-6 py-3 text-right font-medium">{{ __('Asked') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-200 dark:divide-white/10">
                        @foreach ($topQuestions as $question)
                            <tr>
                                <td class="px-6 py-3 text-gray-950 dark:text-white" style="min-width: 16rem">{{ $question['question'] }}</td>
                                <td class="px-6 py-3 text-right tabular-nums text-gray-500 dark:text-gray-400">
                                    {{ number_format($question['asked']) }}
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>

    {{--
        Recent answers, with the audit column. "Figures" says whether the model
        was handed any; an answer full of numbers with nothing behind it is the
        one thing on this page worth stopping for.
    --}}
    <div class="fi-section overflow-hidden rounded-xl bg-white shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
        <div class="px-6 py-4">
            <h2 class="font-semibold text-gray-950 dark:text-white">{{ __('Recent answers') }}</h2>
            <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                {{ __('“Figures” is how many numbers the assistant was permitted for that answer. Everything it said had to come from those.') }}
            </p>
        </div>

        @if (count($recent) === 0)
            <p class="border-t border-gray-200 px-6 py-6 text-sm text-gray-500 dark:border-white/10 dark:text-gray-400">
                {{ __('Nothing yet.') }}
            </p>
        @else
            <div class="overflow-x-auto border-t border-gray-200 dark:border-white/10">
                <table class="w-full text-sm">
                    <thead class="bg-gray-50 text-left text-xs uppercase tracking-wider text-gray-500 dark:bg-white/5 dark:text-gray-400">
                        <tr>
                            <th class="px-6 py-3 font-medium">{{ __('When') }}</th>
                            <th class="px-6 py-3 font-medium">{{ __('Who') }}</th>
                            <th class="px-6 py-3 font-medium">{{ __('Answer') }}</th>
                            <th class="px-6 py-3 text-right font-medium">{{ __('Figures') }}</th>
                            <th class="px-6 py-3 text-right font-medium">{{ __('Tokens') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-200 dark:divide-white/10">
                        @foreach ($recent as $row)
                            <tr>
                                <td class="whitespace-nowrap px-6 py-3 text-gray-500 dark:text-gray-400">{{ $row['when'] }}</td>
                                <td class="whitespace-nowrap px-6 py-3 text-gray-950 dark:text-white">{{ $row['who'] }}</td>
                                {{--
                                    A floor on the width, set inline for the
                                    same reason as the bar above: arbitrary
                                    Tailwind values are only compiled if the
                                    scan saw them. On a phone this column was
                                    being crushed to a forty-pixel sliver of
                                    wrapped letters — the surrounding container
                                    already scrolls, so scrolling to readable
                                    text beats fitting unreadable text.
                                --}}
                                <td class="px-6 py-3 text-gray-500 dark:text-gray-400" style="min-width: 18rem">
                                    {{ $row['answer'] }}
                                    @if ($row['from_cache'])
                                        <span class="ml-1 rounded bg-gray-100 px-1.5 py-0.5 text-xs text-gray-600 dark:bg-white/10 dark:text-gray-300">
                                            {{ __('cached') }}
                                        </span>
                                    @elseif (! $row['from_model'])
                                        {{-- Written by the platform: a dosage redirect, or an apology. --}}
                                        <span class="ml-1 rounded bg-gray-100 px-1.5 py-0.5 text-xs text-gray-600 dark:bg-white/10 dark:text-gray-300">
                                            {{ __('written by the platform') }}
                                        </span>
                                    @endif
                                </td>
                                <td class="px-6 py-3 text-right tabular-nums text-gray-500 dark:text-gray-400">
                                    {{ $row['from_model'] && $row['permitted'] > 0 ? number_format($row['permitted']) : '—' }}
                                </td>
                                <td class="px-6 py-3 text-right tabular-nums text-gray-500 dark:text-gray-400">
                                    {{ $row['tokens'] > 0 ? number_format($row['tokens']) : '—' }}
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>
</x-filament-panels::page>
