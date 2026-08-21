@extends('errors.layout')

@section('title', 'the page expired')
@section('heading', 'This page sat too long')

@section('body')
    <p>
        For security, forms stop being accepted after a while. Nothing you typed was
        sent anywhere.
    </p>

    {{--
        The most common real cause is a form left open overnight. "419 Page
        Expired" tells somebody nothing; this tells them to reload and retype.
    --}}
    <p class="muted">
        Go back, reload the page, and fill it in again.
    </p>
@endsection
