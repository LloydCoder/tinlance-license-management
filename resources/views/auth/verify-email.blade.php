@extends('layouts.auth')

@section('title', 'Verify email — TLMS')

@section('content')
    <h1>Verify your email address</h1>
    <p>Your account must have a verified email address before the administration workspace is available.</p>
    @if (session('status') === 'verification-link-sent')
        <p class="status" role="status">A new verification link has been sent.</p>
    @endif
    @if ($errors->any())
        <p class="errors" role="alert">{{ $errors->first() }}</p>
    @endif
    <form method="post" action="{{ route('verification.send') }}">
        @csrf
        <button type="submit">Resend verification email</button>
    </form>
    <form method="post" action="{{ route('logout') }}">
        @csrf
        <button type="submit" class="secondary">Sign out</button>
    </form>
@endsection
