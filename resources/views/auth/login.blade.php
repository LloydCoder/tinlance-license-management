@extends('layouts.auth')

@section('title', 'Sign in — TLMS')

@section('content')
    <h1>Sign in</h1>
    <p>Access the Tinlance License Management System administration workspace.</p>

    @if (session('status'))
        <p class="status" role="status">{{ session('status') }}</p>
    @endif
    @if ($errors->any())
        <div class="errors" role="alert">{{ $errors->first() }}</div>
    @endif

    <form method="post" action="{{ route('login') }}" class="stack">
        @csrf
        <label for="email">Email address</label>
        <input id="email" name="email" type="email" value="{{ old('email') }}" autocomplete="username" required autofocus>
        <label for="password">Password</label>
        <input id="password" name="password" type="password" autocomplete="current-password" required>
        <label><input name="remember" type="checkbox" value="1" style="width:auto"> Remember this device</label>
        <button type="submit">Sign in securely</button>
    </form>

    <div class="divider"></div>
    <button type="button" class="secondary" data-passkey-action="verify">Sign in with a passkey</button>
    <p class="muted" data-passkey-status role="status" aria-live="polite"></p>
    <div class="links">
        <a href="{{ route('password.request') }}">Forgot password?</a>
    </div>
@endsection
