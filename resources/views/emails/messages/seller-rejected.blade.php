@extends('emails.layout')

@section('subject', branded(__('About your {company_short} seller application')))
@section('preheader', __('We could not approve this application.'))

@section('content')
    <x-mail.heading>{{ __('We could not approve your application') }}</x-mail.heading>

    <x-mail.body>
        {{ branded(__('Thank you for applying to sell on {company}. We were not able to approve :business this time.', ['business' => $seller->business_name])) }}
    </x-mail.body>

    <x-mail.detail-table :rows="[__('Reason') => $reason]" />

    <x-mail.body>
        {{ __('If you can put this right, you are welcome to apply again — nothing you entered has been lost.') }}
    </x-mail.body>

    <x-mail.button :url="route('seller-application.create')">{{ __('Review my application') }}</x-mail.button>
@endsection
