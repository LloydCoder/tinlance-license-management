@extends('layouts.auth')

@section('title', 'Multi-factor authentication — TLMS')

@section('content')
    <h1>Secure your account</h1>
    <p>Register a phishing-resistant passkey or configure an authenticator app before using protected TLMS features.</p>
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
            @if (session()->has('mfa.recovery_codes_once'))
                <section class="panel warning" aria-labelledby="new-recovery-codes-heading">
                    <h3 id="new-recovery-codes-heading">New recovery codes — shown once</h3>
                    <p>Save these codes now. They will not be displayed again. Regenerating the set invalidates every previously issued code.</p>
                    <ul>
                        @foreach (session('mfa.recovery_codes_once') as $recoveryCode)
                            <li><code>{{ $recoveryCode }}</code></li>
                        @endforeach
                    </ul>
                </section>
            @endif
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
            @if (is_string(session('mfa.recovery_code_replacement_once')))
                <section class="panel warning" aria-labelledby="replacement-recovery-code-heading">
                    <h3 id="replacement-recovery-code-heading">Your replacement recovery code — shown once</h3>
                    <p>Store this code offline. It will not be displayed again.</p>
                    <code>{{ session('mfa.recovery_code_replacement_once') }}</code>
                </section>
            @endif
            <p>Recovery codes are stored as one-way SHA-256 digests and cannot be retrieved after issuance. Store the codes offline in a secure password manager. Each code is single-use; using one issues a replacement that is shown once after sign-in.</p>
            @if (! $mfaStepUpFresh)
                <section class="panel warning" aria-labelledby="recovery-step-up-heading">
                    <h3 id="recovery-step-up-heading">Verify your identity before regenerating codes</h3>
                    @if ($totpEnabled)
                        <form method="post" action="{{ route('security.mfa.enroll') }}" data-step-up-totp class="stack">
                            @csrf
                            <label for="recovery-step-up-code">Authenticator code</label>
                            <input id="recovery-step-up-code" name="code" type="text" inputmode="numeric" autocomplete="one-time-code" pattern="[0-9]{6}" minlength="6" maxlength="6" required>
                            <button type="submit">Verify authenticator</button>
                        </form>
                    @endif
                    @if ($hasPasskeys)
                        <button type="button" data-passkey-action="verify">Verify with passkey</button>
                    @endif
                    <p class="muted" role="status" aria-live="polite" data-invitation-status></p>
                </section>
            @endif
            <div class="stack" data-recovery-codes data-regenerate-url="{{ route('two-factor.regenerate-recovery-codes') }}">
                <button type="button" class="secondary" data-recovery-codes-action="regenerate">Generate new recovery codes</button>
                <p class="muted" data-recovery-codes-status role="status" aria-live="polite"></p>
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
