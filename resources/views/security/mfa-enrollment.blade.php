@extends('layouts.auth')

@section('title', 'Multi-factor authentication — TLMS')

@section('content')
    <h1>Secure your account</h1>
    <p>Register a phishing-resistant passkey or configure an authenticator app before using the administration workspace.</p>
    @if (session('status'))
        <p class="status" role="status">{{ session('status') }}</p>
    @endif
    @if ($errors->any())
        <p class="errors" role="alert">{{ $errors->first() }}</p>
    @endif

    <section class="stack">
        <h2>Passkeys</h2>
        <p class="muted">Use a device authenticator or security key. Registration requires recent password confirmation.</p>
        <label for="passkey-name">Passkey name</label>
        <input id="passkey-name" data-passkey-name type="text" maxlength="120" value="My security key">
        <button type="button" data-passkey-action="register">Register passkey</button>
        <p class="muted" data-passkey-status role="status" aria-live="polite"></p>
        @forelse ($passkeys as $passkey)
            <div>
                <strong>{{ $passkey->name }}</strong>
                <span class="muted">Added {{ $passkey->created_at?->toDateString() }}</span>
                <form method="post" action="{{ route('passkey.destroy', $passkey) }}">
                    @csrf
                    @method('DELETE')
                    <button type="submit" class="secondary">Remove passkey</button>
                </form>
            </div>
        @empty
            <p class="muted">No passkeys registered yet.</p>
        @endforelse
    </section>

    <div class="divider"></div>

    <section class="stack">
        <h2>Authenticator app (TOTP)</h2>
        @if (! $totpEnabled && ! $user->two_factor_secret)
            <p class="muted">Enable an authenticator app, scan the QR code, then confirm with a current six-digit code.</p>
            <form method="post" action="{{ route('two-factor.enable') }}">
                @csrf
                <button type="submit">Set up authenticator app</button>
            </form>
        @elseif (! $totpEnabled)
            <p>Scan this QR code using your authenticator app.</p>
            <div>{!! $user->twoFactorQrCodeSvg() !!}</div>
            <form method="post" action="{{ route('two-factor.confirm') }}" class="stack">
                @csrf
                <label for="code">Six-digit confirmation code</label>
                <input id="code" name="code" inputmode="numeric" autocomplete="one-time-code" pattern="[0-9]{6}" maxlength="6" required>
                <button type="submit">Confirm authenticator</button>
            </form>
        @else
            <p class="status">Authenticator app is enabled.</p>
            <p>Recovery codes are sensitive backup credentials. Reveal them only when needed, store them in a secure password manager, and regenerate them immediately if exposed. Each code can be used once.</p>
            <div
                class="stack"
                data-recovery-codes
                data-view-url="{{ route('two-factor.recovery-codes') }}"
                data-regenerate-url="{{ route('two-factor.regenerate-recovery-codes') }}"
            >
                <button type="button" class="secondary" data-recovery-codes-action="view">Reveal recovery codes</button>
                <button type="button" class="secondary" data-recovery-codes-action="regenerate">Regenerate recovery codes</button>
                <p class="muted" data-recovery-codes-status role="status" aria-live="polite"></p>
                <div class="stack" data-recovery-codes-output hidden></div>
            </div>
            <form method="post" action="{{ route('two-factor.disable') }}">
                @csrf
                @method('DELETE')
                <button type="submit" class="secondary">Disable authenticator app</button>
            </form>
        @endif
    </section>

    <div class="links"><a href="{{ route('admin.dashboard') }}">Return to administration</a></div>
@endsection
