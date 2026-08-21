@extends('errors.layout')

@section('title', 'not allowed')
@section('heading', 'You cannot open that')

@section('body')
    <p>
        That page belongs to somebody else's account, or needs a role your account
        does not have.
    </p>

    <p class="muted">
        If you think this is wrong, get in touch and tell us what you were trying to
        open.
    </p>
@endsection
