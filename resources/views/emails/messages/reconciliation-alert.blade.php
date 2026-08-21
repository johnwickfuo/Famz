@extends('emails.layout')

@section('subject', __('Reconciliation found discrepancies'))
@section('preheader', __(':count finding(s), :critical critical.', ['count' => count($result['findings']), 'critical' => $result['critical']]))

@section('content')
    <x-mail.heading>{{ __('The books do not agree') }}</x-mail.heading>

    <x-mail.body>
        {{ __('The nightly reconciliation found :count thing(s) that do not add up. Each one names the record it is about.', [
            'count' => count($result['findings']),
        ]) }}
    </x-mail.body>

    <x-mail.detail-table :rows="[
        __('Critical') => (string) $result['critical'],
        __('Total findings') => (string) count($result['findings']),
        __('Checked at') => $result['ran_at']->format('j F Y, H:i'),
    ]" />

    {{-- Capped: an email carrying four hundred lines is an email nobody reads. --}}
    @foreach (array_slice($result['findings'], 0, 15) as $finding)
        <x-mail.body>
            <strong>{{ strtoupper($finding['severity']) }}</strong> · {{ $finding['subject'] }}<br>
            {{ $finding['message'] }}
        </x-mail.body>
    @endforeach

    @if (count($result['findings']) > 15)
        <x-mail.body>
            {{ __('And :more more. The full list is in the reconciliation log on the server.', [
                'more' => count($result['findings']) - 15,
            ]) }}
        </x-mail.body>
    @endif

    <x-mail.button :url="route('filament.admin.pages.reconciliation')">
        {{ __('Open reconciliation') }}
    </x-mail.button>

    <x-mail.body>
        {{ __('Nothing has been changed automatically. Every figure above is a question, not a correction — the ledger is never rewritten by a script.') }}
    </x-mail.body>
@endsection
