@extends('emails.layout')

@section('subject', __('Website message: :subject', ['subject' => $messageSubject]))
@section('preheader', __('From :name (:email)', ['name' => $senderName, 'email' => $senderEmail]))

@section('content')
    <x-mail.heading>{{ __('Message from the website') }}</x-mail.heading>

    <x-mail.detail-table :rows="[
        __('From') => $senderName,
        __('Email') => $senderEmail,
        __('Subject') => $messageSubject,
    ]" />

    {{-- Escaped by Blade, as all user-supplied content in mail must be. --}}
    <x-mail.body>{{ $messageBody }}</x-mail.body>

    <x-mail.body>
        {{ __('Reply to this email and it goes straight back to them.') }}
    </x-mail.body>
@endsection
