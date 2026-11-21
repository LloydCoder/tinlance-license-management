@extends('layouts.auth')

@section('title', 'Choose a new password — TLMS')

@section('content')
    <h1>Choose a new password</h1>
    @if ($errors->any())
        <p class="errors" role="alert">{{ $errors->first() }}</p>
    @endif
    <form method="post" action="{{ route('password.update') }}" class="stack">
        @csrf
        <input type="hidden" name="token" value="{{ request()->route('token') }}">
        <label for="email">Email address</label>
        <input id="email" name="email" type="email" value="{{ old('email', request('email')) }}" autocomplete="email" required>
        <label for="password">New password</label>
        <input id="password" name="password" type="password" minlength="15" maxlength="1024" autocomplete="new-password" required>
        <label for="password_confirmation">Confirm new password</label>
        <input id="password_confirmation" name="password_confirmation" type="password" minlength="15" maxlength="1024" autocomplete="new-password" required>
        <button type="submit">Update password</button>
    </form>
@endsection
