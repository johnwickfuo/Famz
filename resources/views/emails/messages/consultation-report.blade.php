@extends('emails.layout')

@section('subject', __('Your consultation report is ready'))
@section('preheader', $report->title)

@section('content')
    <x-mail.heading>{{ __('Your report is ready') }}</x-mail.heading>

    <x-mail.body>
        {{ __('Hello :name. We have written up what we found and what we recommend.', ['name' => $consultation->full_name]) }}
    </x-mail.body>

    <x-mail.detail-table :rows="array_filter([
        __('Reference') => $consultation->reference,
        __('Report') => $report->title,
    ])" />

    <x-mail.button :url="route('consultations.show', $consultation->reference)">
        {{ __('Read the report') }}
    </x-mail.button>

    <x-mail.body>
        {{-- The follow-up window is the difference between a document and an
             answer, so it is said in the email rather than only on the page. --}}
        {{ __('You can ask us about anything in it from that page for the next :days days.', [
            'days' => (int) settings('consultation_followup_days', 30),
        ]) }}
    </x-mail.body>
@endsection
