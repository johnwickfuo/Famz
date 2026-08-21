@extends('emails.layout')

@section('subject', __('You can start :course now', ['course' => $enrolment->course?->title ?? __('your course')]))
@section('preheader', __('Your course is open. Here is the link to begin.'))

@section('content')
    <x-mail.heading>{{ __('Your course is open') }}</x-mail.heading>

    <x-mail.body>
        {{ __('You now have :course. It does not expire — work through it at whatever pace suits you, and it remembers where you stopped.', [
            'course' => $enrolment->course?->title ?? __('your course'),
        ]) }}
    </x-mail.body>

    <x-mail.detail-table :rows="array_filter([
        __('Course') => $enrolment->course?->title,
        __('Lessons') => $enrolment->course?->lessons_count ? (string) $enrolment->course->lessons_count : null,
        __('Paid') => $enrolment->price_paid_kobo ? \App\Support\Money::fromKobo($enrolment->price_paid_kobo) : null,
    ])" />

    <x-mail.button :url="$actionUrl">{{ __('Start the first lesson') }}</x-mail.button>

    <x-mail.body>
        {{-- Course sales are final. Somebody who cannot find what they bought
             has no way out, so the link above is the whole job of this email. --}}
        {{ __('Finish it and pass the quiz and you get a certificate with your name on it.') }}
    </x-mail.body>
@endsection
