@extends('emails.layout')

@section('subject', branded(__('Verify your email for {company}')))
@section('preheader', __('One tap to confirm your email address.'))

@section('content')
    <x-mail.heading>{{ __('Verify your email address') }}</x-mail.heading>

    <x-mail.body>
        {{ branded(__('Tap the button below to confirm this email address belongs to you. It is the last step before your {company_short} account is ready.')) }}
    </x-mail.body>

    <x-mail.button :url="$url">{{ __('Verify my email') }}</x-mail.button>

    <x-mail.body>
        {{ __('If you did not create an account, nothing else is needed — you can ignore this message.') }}
    </x-mail.body>
@endsection
