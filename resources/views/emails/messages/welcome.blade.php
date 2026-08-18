@extends('emails.layout')

@section('subject', branded(__('Welcome to {company}')))
@section('preheader', branded(__('Your {company_short} account is ready.')))

@section('content')
    <x-mail.heading>{{ __('Welcome, :name', ['name' => $user->displayName()]) }}</x-mail.heading>

    <x-mail.body>
        {{ branded(__('Your account on {company} is ready. You can browse the market, follow training and ask a mentor a question.')) }}
    </x-mail.body>

    <x-mail.body>
        {{ __('Selling, mentoring and posting jobs are switched on by an administrator once your details are checked.') }}
    </x-mail.body>

    <x-mail.button :url="route('dashboard')">{{ __('Open my dashboard') }}</x-mail.button>
@endsection
