@extends('emails.layout')

@section('subject', branded(__('You can start selling on {company}')))
@section('preheader', __('Your seller account is open.'))

@section('content')
    <x-mail.heading>{{ __('You are approved to sell') }}</x-mail.heading>

    <x-mail.body>
        {{ branded(__('Good news — :business has been approved on {company}. You can start listing straight away.', ['business' => $seller->business_name])) }}
    </x-mail.body>

    <x-mail.detail-table :rows="[
        __('Business') => $seller->business_name,
        __('Location') => $seller->location(),
        __('Approved') => $seller->reviewed_at?->format('j F Y'),
    ]" />

    <x-mail.button :url="url('/seller')">{{ __('Open my seller panel') }}</x-mail.button>

    <x-mail.body>
        {{ __('Your first few listings are checked by our team before they go live. Once three have been approved, the rest go up straight away.') }}
    </x-mail.body>
@endsection
