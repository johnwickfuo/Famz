@extends('emails.layout')

@section('subject', $forMentor
    ? __('You confirmed a mentorship — :reference', ['reference' => $engagement->reference])
    : __('Your mentor has confirmed — :reference', ['reference' => $engagement->reference]))
@section('preheader', $forMentor
    ? __('The client can now see how to reach you.')
    : __('You can now see how to reach your mentor.'))

@section('content')
    @if ($forMentor)
        <x-mail.heading>{{ __('You have taken this on') }}</x-mail.heading>

        <x-mail.body>
            {{ __(':name is expecting to hear from you. Their contact details are on the engagement page.', [
                'name' => $engagement->client?->displayName() ?? __('The client'),
            ]) }}
        </x-mail.body>
    @else
        <x-mail.heading>{{ __('Your mentor has confirmed') }}</x-mail.heading>

        <x-mail.body>
            {{ __(':name has taken on your mentorship. You can now see how to reach them.', [
                'name' => $engagement->mentor?->displayName() ?? __('Your mentor'),
            ]) }}
        </x-mail.body>
    @endif

    <x-mail.detail-table :rows="array_filter([
        __('Reference') => $engagement->reference,
        __('Package') => $engagement->package_name,
        __('Starts') => $engagement->starts_at?->format('j F Y'),
    ])" />

    <x-mail.button :url="$actionUrl">{{ __('Open the engagement') }}</x-mail.button>

    <x-mail.body>
        {{-- Contact details are the product here: they are withheld until this
             moment, which makes "they are visible now" the news. --}}
        {{ __('Keep the arrangement on the platform. If something goes wrong, a record of what was agreed is what a dispute is decided on.') }}
    </x-mail.body>
@endsection
