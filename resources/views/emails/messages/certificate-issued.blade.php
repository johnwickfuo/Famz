@extends('emails.layout')

@section('subject', branded(__('Your certificate from {company}')))
@section('preheader', __('Your training certificate is ready to download.'))

@section('content')
    <x-mail.heading>{{ __('Your certificate is ready') }}</x-mail.heading>

    <x-mail.body>
        {{ branded(__('Well done, :name. You have completed the course and {company} has issued your certificate.', ['name' => $user->displayName()])) }}
    </x-mail.body>

    <x-mail.detail-table :rows="[
        __('Course') => $courseTitle,
        __('Holder') => $user->displayName(),
        __('Reference') => $reference,
        __('Issued') => now()->format('j F Y'),
    ]" />

    <x-mail.body>
        {{ __('Keep the reference number: an employer can use it to check the certificate is genuine.') }}
    </x-mail.body>
@endsection
