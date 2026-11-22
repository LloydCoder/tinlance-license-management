@extends('layouts.auth')

@section('title', 'Active sessions — TLMS')

@section('content')
    <h1>Active sessions</h1>
    <p>These are the database-backed sessions associated with your account. Viewing or revoking them requires a recent password confirmation.</p>
    @if (session('status'))
        <p class="status" role="status">{{ session('status') }}</p>
    @endif
    <div class="stack">
        @forelse ($sessions as $session)
            <section>
                <strong>{{ $session->id === $currentSessionId ? 'Current session' : 'Other session' }}</strong>
                <p class="muted">IP: {{ $session->ip_address ?: 'Unavailable' }}</p>
                <p class="muted">Client: {{ $session->user_agent ?: 'Unavailable' }}</p>
                <p class="muted">Last activity: {{ date('Y-m-d H:i:s', (int) $session->last_activity) }} UTC</p>
            </section>
        @empty
            <p>No active sessions were found.</p>
        @endforelse
    </div>
    <section class="panel warning" aria-labelledby="session-revocation-step-up-heading">
        <h2 id="session-revocation-step-up-heading">Fresh MFA verification</h2>
        <p>Revoking every session signs out all devices, including this one. Confirm your identity with an enrolled authenticator or passkey immediately before proceeding.</p>
        <form method="post" action="{{ route('admin.security.sessions.index') }}" data-step-up-totp class="stack">
            @csrf
            <label for="session-revocation-step-up-code">Six-digit authenticator code</label>
            <input id="session-revocation-step-up-code" name="code" type="text" inputmode="numeric" autocomplete="one-time-code" pattern="[0-9]{6}" minlength="6" maxlength="6" required>
            <button type="submit">Verify authenticator</button>
        </form>
        <button type="button" class="secondary" data-passkey-action="verify">Verify with passkey</button>
        <p class="muted" role="status" aria-live="polite" data-invitation-status></p>
    </section>
    <form method="post" action="{{ route('admin.security.sessions.revoke-all') }}">
        @csrf
        <button type="submit">Revoke all sessions and sign out</button>
    </form>
    <div class="links"><a href="{{ route('admin.dashboard') }}">Return to administration</a></div>
@endsection
