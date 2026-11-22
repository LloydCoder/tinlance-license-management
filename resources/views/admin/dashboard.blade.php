@extends('layouts.auth')

@section('title', 'Administration — TLMS')

@section('content')
    <h1>Administration</h1>
    <p>Signed in as {{ $user->name }}. This workspace is restricted to verified Super Administrators.</p>
    @if (is_string($mfaRecoveryCodeReplacement))
        <section class="panel warning" aria-labelledby="replacement-recovery-code-heading">
            <h2 id="replacement-recovery-code-heading">Your replacement MFA recovery code — shown once</h2>
            <p>One recovery code was used to sign in. Save this replacement offline now; it cannot be retrieved later.</p>
            <code>{{ $mfaRecoveryCodeReplacement }}</code>
        </section>
    @endif
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
