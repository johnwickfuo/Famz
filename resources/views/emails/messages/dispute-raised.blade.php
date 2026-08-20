@extends('emails.layout')

@section('subject', __('Action needed: dispute opened on :reference', ['reference' => $dispute->subjectReference()]))
@section('preheader', __('You have a limited time to respond before this is decided.'))

@section('content')
    <x-mail.heading>{{ __('A dispute has been opened') }}</x-mail.heading>

    <x-mail.body>
        {{ __(':name has opened a dispute about :reference. Nothing has been decided — your side of it is what decides this.', [
            'name' => $dispute->raiser?->displayName() ?? __('The other party'),
            'reference' => $dispute->subjectReference(),
        ]) }}
    </x-mail.body>

    <x-mail.detail-table :rows="array_filter([
        __('Reference') => $dispute->subjectReference(),
        __('Reason') => $dispute->reason?->label() ?? null,
        __('Opened') => $dispute->created_at?->format('j F Y, H:i'),
    ])" />

    <x-mail.button :url="$actionUrl">{{ __('Answer this dispute') }}</x-mail.button>

    <x-mail.body>
        {{ __('While a dispute is open the money for this order is held and cannot be withdrawn. It is released as soon as the dispute is settled.') }}
    </x-mail.body>
@endsection
