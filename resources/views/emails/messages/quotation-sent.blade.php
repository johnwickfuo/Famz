@extends('emails.layout')

@section('subject', __('Your proposal is ready'))
@section('preheader', __('Costed, written up and ready to read.'))

@section('content')
    <x-mail.heading>{{ __('Your proposal is ready') }}</x-mail.heading>

    <x-mail.body>
        {{ __('Hello :name. We have finished costing your project. The full proposal is on your dashboard and you can download it as a PDF to print or send on.', [
            'name' => $request?->user?->name,
        ]) }}
    </x-mail.body>

    <x-mail.detail-table :rows="array_filter([
        __('Reference') => $request?->reference,
        __('Proposal') => $quotation->title,
        __('Version') => (string) $quotation->version,
        __('Total') => $quotation->total(),
        __('Valid until') => $quotation->valid_until?->format('j F Y'),
    ])" />

    <x-mail.button :url="route('quotations.show', $request?->reference)">
        {{ __('Read the proposal') }}
    </x-mail.button>

    <x-mail.body>
        {{ __('Read it properly before you decide — the assumptions and exclusions matter as much as the total. When you are ready to go ahead, call or email us and quote :reference. There is nothing to click.', [
            'reference' => $request?->reference,
        ]) }}
    </x-mail.body>
@endsection
