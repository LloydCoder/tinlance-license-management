@extends('layouts.auth')

@section('title', 'Password recovery — TLMS')

@section('content')
    <h1>Reset your password</h1>
    <p>Enter your account email. A reset link will be sent if the account is eligible.</p>
    @if (session('status'))
        <p class="status" role="status">{{ session('status') }}</p>
    @endif
    @if ($errors->any())
        <p class="errors" role="alert">{{ $errors->first() }}</p>
    @endif
    <form method="post" action="{{ route('password.email') }}" class="stack">
        @csrf
        <label for="email">Email address</label>
        <input id="email" name="email" type="email" value="{{ old('email') }}" autocomplete="email" required autofocus>
        <button type="submit">Send password reset link</button>
    </form>
    <div class="links"><a href="{{ route('login') }}">Return to sign in</a></div>
@endsection
