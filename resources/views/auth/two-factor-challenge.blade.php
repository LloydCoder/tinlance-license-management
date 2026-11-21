@extends('layouts.auth')

@section('title', 'Verify identity — TLMS')

@section('content')
    <h1>Additional verification</h1>
    <p>Enter the current code from your authenticator app, or use one unused recovery code.</p>
    @if ($errors->any())
        <p class="errors" role="alert">{{ $errors->first() }}</p>
    @endif
    <form method="post" action="{{ route('two-factor.login') }}" class="stack">
        @csrf
        <label for="code">Authenticator code</label>
        <input id="code" name="code" inputmode="numeric" autocomplete="one-time-code" pattern="[0-9]{6}" maxlength="6">
        <button type="submit">Verify code</button>
    </form>
    <div class="divider"></div>
    <form method="post" action="{{ route('two-factor.login') }}" class="stack">
        @csrf
        <label for="recovery_code">Recovery code</label>
        <input id="recovery_code" name="recovery_code" autocomplete="one-time-code">
        <button type="submit" class="secondary">Use recovery code</button>
    </form>
@endsection
