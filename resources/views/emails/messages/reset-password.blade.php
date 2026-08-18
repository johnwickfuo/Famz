@extends('emails.layout')

@section('subject', branded(__('Reset your {company_short} password')))
@section('preheader', __('A link to choose a new password.'))

@section('content')
    <x-mail.heading>{{ __('Reset your password') }}</x-mail.heading>

    <x-mail.body>
        {{ branded(__('Somebody asked to reset the password for this {company} account. If it was you, tap below.')) }}
    </x-mail.body>

    <x-mail.button :url="$url">{{ __('Choose a new password') }}</x-mail.button>

    <x-mail.detail-table :rows="[__('Link expires in') => trans_choice(':count minute|:count minutes', $expiresInMinutes, ['count' => $expiresInMinutes])]" />

    <x-mail.body>
        {{ __('If you did not ask for this, ignore the message — your password stays as it is.') }}
    </x-mail.body>
@endsection
