@extends('errors.layout')

@section('title', 'something went wrong')
@section('heading', 'Something went wrong at our end')

@section('body')
    <p>
        This is our fault, not yours. The error has been recorded and somebody will
        look at it.
    </p>

    {{--
        Said plainly, because the question anybody who has just paid for something
        actually has is whether their money moved.
    --}}
    <p class="muted">
        If you were in the middle of paying, check your orders before trying again —
        a payment that went through will be there.
    </p>
@endsection
