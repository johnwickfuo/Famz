@extends('emails.layout')

@section('subject', branded(__('An invitation to mentor on {company}')))
@section('preheader', __('You have been invited to take on farmers as a mentor.'))

@section('content')
    <x-mail.heading>{{ __('You have been invited to mentor') }}</x-mail.heading>

    <x-mail.body>
        {{ branded(__('{company} matches farmers with experienced people who know their kind of farming. Somebody has put your name forward.')) }}
    </x-mail.body>

    @if ($invitation->note)
        <x-mail.body>“{{ $invitation->note }}”</x-mail.body>
    @endif

    <x-mail.body>
        {{ __('If you take it up, you set your own packages and prices, and you decide which engagements to accept. Your phone number stays hidden until you confirm one.') }}
    </x-mail.body>

    <x-mail.button :url="$actionUrl">{{ __('Set up your profile') }}</x-mail.button>

    <x-mail.detail-table :rows="array_filter([
        __('Invited') => $invitation->email,
        __('Open until') => $invitation->expires_at?->format('j F Y'),
    ])" />

    <x-mail.body>
        {{-- There is no public mentor signup, so this link is the only route in
             and it is worth saying plainly that it is personal. --}}
        {{ __('This link is for you alone and stops working after that date. There is no other way to join — we do not take public applications.') }}
    </x-mail.body>
@endsection
