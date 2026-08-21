@extends('errors.layout')

@section('title', 'too many attempts')
@section('heading', 'Too many attempts')

@section('body')
    <p>
        You have tried that more times than we allow in a short period. This protects
        everybody's accounts, including yours.
    </p>

    <p class="muted">
        Wait a minute and try again.
    </p>
@endsection
