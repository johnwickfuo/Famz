@extends('emails.layout')

@section('subject', __('Your farm setup request'))
@section('preheader', __('One step before we start costing it.'))

@section('content')
    <x-mail.heading>{{ __('We have your request') }}</x-mail.heading>

    <x-mail.body>
        {{ __('Hello :name. We have everything you sent us about your :farm project.', [
            'name' => $request->user?->name,
            'farm' => $request->farm_type,
        ]) }}
    </x-mail.body>

    <x-mail.detail-table :rows="array_filter([
        __('Reference') => $request->reference,
        __('Project') => $request->project_type->label(),
        __('Farm') => $request->farm_type,
        __('Size') => $request->capacity(),
        __('Where') => $request->place(),
        __('Study fee') => $amount,
    ])" />

    <x-mail.body>
        {{ __('Before we start, there is a study fee of :amount. It covers the work of costing your project properly — measuring up what you need, pricing the housing, equipment and stock against your site, and writing it all down.', ['amount' => $amount]) }}
    </x-mail.body>

    <x-mail.button :url="route('quotations.show', $request->reference)">
        {{ __('Pay :amount', ['amount' => $amount]) }}
    </x-mail.button>

    <x-mail.body>
        {{ __('If you go ahead with the project, we normally take this fee off your first invoice. Call us if you would rather talk it through first.') }}
    </x-mail.body>
@endsection
