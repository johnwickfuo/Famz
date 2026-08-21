@extends('errors.layout')

@section('title', 'page not found')
@section('heading', 'That page is not here')

@section('body')
    <p>
        The link may be old, or the listing may have been sold or taken down. Nothing is
        wrong with your account.
    </p>

    {{--
        A dead end with a way out. Somebody who followed a link to a product that
        has since sold should land in the marketplace, not be told to try again.
    --}}
    <p class="muted">
        Try the <a href="{{ url('/market') }}">marketplace</a>, or start again from the
        <a href="{{ url('/') }}">home page</a>.
    </p>
@endsection
