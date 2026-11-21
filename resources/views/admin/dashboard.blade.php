@extends('layouts.auth')

@section('title', 'Administration — TLMS')

@section('content')
    <h1>Administration</h1>
    <p>Signed in as {{ $user->name }}. This workspace is restricted to verified Super Administrators.</p>
    <div class="stack">
        <a class="button" href="{{ route('security.mfa.enroll') }}">Manage multi-factor authentication</a>
        <a class="button secondary" href="{{ route('admin.security.sessions') }}">Review active sessions</a>
    </div>
    <div class="divider"></div>
    <form method="post" action="{{ route('logout') }}">
        @csrf
        <button type="submit" class="secondary">Sign out</button>
    </form>
@endsection
