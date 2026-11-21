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
    <form method="post" action="{{ route('admin.security.sessions.revoke-all') }}">
        @csrf
        <button type="submit">Revoke all sessions and sign out</button>
    </form>
    <div class="links"><a href="{{ route('admin.dashboard') }}">Return to administration</a></div>
@endsection
