@extends('layouts.auth')

@section('title', 'Confirm identity — TLMS')

@section('content')
    <h1>Confirm your identity</h1>
    <p>Re-enter your password before changing security settings or registering a new authenticator.</p>
    @if ($errors->any())
        <p class="errors" role="alert">{{ $errors->first() }}</p>
    @endif
    <form method="post" action="{{ route('password.confirm') }}" class="stack">
        @csrf
        <label for="password">Current password</label>
        <input id="password" name="password" type="password" autocomplete="current-password" required autofocus>
        <button type="submit">Confirm password</button>
    </form>
@endsection
