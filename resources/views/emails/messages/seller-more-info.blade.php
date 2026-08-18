@extends('emails.layout')

@section('subject', branded(__('We need a little more for your {company_short} application')))
@section('preheader', __('One more thing before we can approve you.'))

@section('content')
    <x-mail.heading>{{ __('Just one more thing') }}</x-mail.heading>

    <x-mail.body>
        {{ __('We are reviewing the application for :business and need a little more from you before we can approve it.', ['business' => $seller->business_name]) }}
    </x-mail.body>

    <x-mail.detail-table :rows="[__('What we need') => $notes]" />

    <x-mail.button :url="route('seller-application.create')">{{ __('Update my application') }}</x-mail.button>

    <x-mail.body>
        {{ __('Your answers are still saved — you only need to change what is asked for above and send it back.') }}
    </x-mail.body>
@endsection
