@extends('emails.layout')

@section('subject', branded(__('Your {company_short} account is active')))
@section('preheader', __('You can now sign in and use the platform.'))

@section('content')
    <x-mail.heading>{{ __('Your account is active') }}</x-mail.heading>

    <x-mail.body>
        {{ branded(__('Hello :name — an administrator has activated your account on {company}.', ['name' => $user->displayName()])) }}
    </x-mail.body>

    <x-mail.detail-table :rows="[
        __('Account') => $user->email,
        __('Status') => $user->status->label(),
        __('Roles') => $user->getRoleNames()->implode(', ') ?: __('Member'),
    ]" />

    <x-mail.button :url="route('dashboard')">{{ __('Sign in') }}</x-mail.button>
@endsection
