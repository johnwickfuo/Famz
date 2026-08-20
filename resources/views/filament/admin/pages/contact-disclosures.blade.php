<x-filament-panels::page>
    {{ $this->form }}

    {{-- The shape of it, before any table. --}}
    <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
        @foreach ([
            ['label' => __('Numbers released'), 'value' => number_format($totals['released']), 'help' => __('Times a worker\'s number was actually shown.')],
            ['label' => __('Refused'), 'value' => number_format($totals['refused']), 'help' => __('Mostly anonymous visitors. This is the rule working.')],
            ['label' => __('Workers involved'), 'value' => number_format($totals['workers']), 'help' => __('Distinct people whose number went out.')],
            ['label' => __('Accounts'), 'value' => number_format($totals['accounts']), 'help' => __('Employers who were given at least one.')],
        ] as $stat)
            <div class="fi-section rounded-xl bg-white p-5 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
                <p class="text-xs uppercase tracking-wider text-gray-500 dark:text-gray-400">{{ $stat['label'] }}</p>
                <p class="mt-1 text-2xl font-bold tabular-nums text-gray-950 dark:text-white">{{ $stat['value'] }}</p>
                <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">{{ $stat['help'] }}</p>
            </div>
        @endforeach
    </div>

    {{--
        The list that matters. An account averaging close to its full daily
        allowance every day it is active is not filling one job, whatever it
        says it is doing.
    --}}
    <div class="fi-section overflow-hidden rounded-xl bg-white shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
        <div class="px-6 py-4">
            <h2 class="font-semibold text-gray-950 dark:text-white">{{ __('Who is looking most') }}</h2>
            <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                {{ __('Ranked by how many different workers they opened, not how many times. The daily allowance is :limit.', ['limit' => $limit]) }}
            </p>
        </div>

        @if (count($topViewers) === 0)
            <p class="border-t border-gray-200 px-6 py-6 text-sm text-gray-500 dark:border-white/10 dark:text-gray-400">
                {{ __('Nobody has been given a number in this period.') }}
            </p>
        @else
            <div class="overflow-x-auto border-t border-gray-200 dark:border-white/10">
                <table class="w-full text-sm">
                    <thead class="bg-gray-50 text-left text-xs uppercase tracking-wider text-gray-500 dark:bg-white/5 dark:text-gray-400">
                        <tr>
                            <th class="px-6 py-3 font-medium">{{ __('Account') }}</th>
                            <th class="px-6 py-3 text-right font-medium">{{ __('Workers') }}</th>
                            <th class="px-6 py-3 text-right font-medium">{{ __('Days') }}</th>
                            <th class="px-6 py-3 text-right font-medium">{{ __('Per day') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-200 dark:divide-white/10">
                        @foreach ($topViewers as $viewer)
                            <tr @class(['bg-danger-50 dark:bg-danger-500/10' => $viewer['at_limit']])>
                                <td class="px-6 py-3">
                                    <p class="font-medium text-gray-950 dark:text-white">{{ $viewer['user'] }}</p>
                                    <p class="text-xs text-gray-500 dark:text-gray-400">
                                        {{ $viewer['employer'] ?? __('No employer profile') }}
                                        @if ($viewer['email']) · {{ $viewer['email'] }} @endif
                                    </p>
                                </td>
                                <td class="px-6 py-3 text-right font-semibold tabular-nums text-gray-950 dark:text-white">
                                    {{ number_format($viewer['workers_seen']) }}
                                </td>
                                <td class="px-6 py-3 text-right tabular-nums text-gray-600 dark:text-gray-400">
                                    {{ $viewer['active_days'] }}
                                </td>
                                <td @class([
                                    'px-6 py-3 text-right tabular-nums',
                                    'font-bold text-danger-600 dark:text-danger-400' => $viewer['at_limit'],
                                    'text-gray-600 dark:text-gray-400' => ! $viewer['at_limit'],
                                ])>
                                    {{ $viewer['per_day'] }}
                                    @if ($viewer['at_limit'])
                                        <span class="block text-xs font-normal">{{ __('at the limit') }}</span>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>

    <div class="grid gap-4 lg:grid-cols-2">
        <div class="fi-section overflow-hidden rounded-xl bg-white shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
            <div class="px-6 py-4">
                <h2 class="font-semibold text-gray-950 dark:text-white">{{ __('Workers seen by most farms') }}</h2>
                <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                    {{ __('Usually a good profile doing well. Occasionally a number being passed around.') }}
                </p>
            </div>

            @if (count($mostViewed) === 0)
                <p class="border-t border-gray-200 px-6 py-6 text-sm text-gray-500 dark:border-white/10 dark:text-gray-400">
                    {{ __('Nothing to show.') }}
                </p>
            @else
                <ul class="divide-y divide-gray-200 border-t border-gray-200 dark:divide-white/10 dark:border-white/10">
                    @foreach ($mostViewed as $worker)
                        <li class="flex items-center justify-between gap-4 px-6 py-3 text-sm">
                            <span class="text-gray-950 dark:text-white">
                                {{ $worker['worker'] }}
                                <span class="text-xs text-gray-500 dark:text-gray-400">{{ $worker['state'] }}</span>
                            </span>
                            <span class="tabular-nums text-gray-600 dark:text-gray-400">
                                {{ $worker['employers'] }} {{ __('farms') }}
                            </span>
                        </li>
                    @endforeach
                </ul>
            @endif
        </div>

        <div class="fi-section overflow-hidden rounded-xl bg-white shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
            <div class="px-6 py-4">
                <h2 class="font-semibold text-gray-950 dark:text-white">{{ __('Latest disclosures') }}</h2>
            </div>

            <div class="max-h-96 overflow-y-auto border-t border-gray-200 dark:border-white/10">
                <ul class="divide-y divide-gray-200 dark:divide-white/10">
                    @forelse ($recent as $view)
                        <li class="px-6 py-3 text-sm">
                            <p class="text-gray-950 dark:text-white">
                                {{ $view['employer'] ?? $view['user'] }} → {{ $view['worker'] }}
                            </p>
                            <p class="text-xs text-gray-500 dark:text-gray-400">
                                {{ $view['when'] }}@if ($view['ip']) · {{ $view['ip'] }} @endif
                            </p>
                        </li>
                    @empty
                        <li class="px-6 py-6 text-sm text-gray-500 dark:text-gray-400">{{ __('Nothing yet.') }}</li>
                    @endforelse
                </ul>
            </div>
        </div>
    </div>
</x-filament-panels::page>
