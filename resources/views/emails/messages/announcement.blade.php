@extends('emails.layout')

@section('subject', branded($headline))
@section('preheader', branded(\Illuminate\Support\Str::limit(strip_tags($body), 90)))

@section('content')
    <x-mail.heading>{{ branded($headline) }}</x-mail.heading>

    @foreach (preg_split('/\R{2,}/', trim($body)) as $paragraph)
        <x-mail.body>{{ branded($paragraph) }}</x-mail.body>
    @endforeach
@endsection
