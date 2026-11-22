@extends('layouts.auth')

@section('title', 'Vendor profile — TLMS')

@section('content')
    <h1>Vendor profile</h1>
    <p>Manage the customer-facing display name for your vendor account. Legal identity, installation identity, commercial defaults and lifecycle status remain protected.</p>

    @if (session('status'))
        <p class="status" role="status">{{ session('status') }}</p>
    @endif
    @if ($errors->any())
        <section class="panel warning" role="alert">
            <h2>Check the submitted information</h2>
            <ul>
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </section>
    @endif

    <section class="panel warning" aria-labelledby="vendor-profile-security-heading">
        <h2 id="vendor-profile-security-heading">Security verification</h2>
        @if (! $passwordConfirmationFresh)
            <p>Your password confirmation has expired. Confirm your password before changing the vendor profile.</p>
            <p><a href="{{ route('password.confirm') }}">Confirm password</a></p>
        @endif
        @if (! $mfaStepUpFresh)
            <p>Complete a fresh authenticator or passkey verification before saving changes.</p>
            @if ($hasTotp)
                <form method="post" action="{{ url('/api/v1/security/step-up/totp') }}" data-step-up-totp class="stack">
                    @csrf
                    <label for="vendor-profile-step-up-code">Six-digit authenticator code</label>
                    <input id="vendor-profile-step-up-code" name="code" type="text" inputmode="numeric" autocomplete="one-time-code" pattern="[0-9]{6}" minlength="6" maxlength="6" required>
                    <button type="submit" @disabled(! $passwordConfirmationFresh)>Verify authenticator</button>
                </form>
            @endif
            @if ($hasPasskeys)
                <button type="button" class="secondary" data-passkey-action="verify" @disabled(! $passwordConfirmationFresh)>Verify with passkey</button>
            @endif
        @endif
        <p class="status" role="status" aria-live="polite" data-passkey-status></p>
        @unless ($canManageWrites)
            <p class="muted">Profile changes remain disabled until recent password confirmation and fresh MFA verification are valid.</p>
        @endunless
    </section>

    <section class="panel" aria-labelledby="vendor-profile-current-heading">
        <h2 id="vendor-profile-current-heading">{{ $vendor->display_name }}</h2>
        <p><strong>Legal identity:</strong> {{ $vendor->legal_name }}</p>
        <p class="muted">Installation key, account status, currency and timezone are system-controlled.</p>

        <form method="post" action="{{ route('workspace.vendor-profile.update') }}" class="stack">
            @csrf
            @method('PATCH')
            <label for="vendor-display-name">Customer-facing display name</label>
            <input id="vendor-display-name" name="display_name" type="text" minlength="2" maxlength="160" value="{{ old('display_name', $vendor->display_name) }}" required>
            <label for="vendor-profile-reason">Reason for change</label>
            <input id="vendor-profile-reason" name="reason" type="text" minlength="8" maxlength="500" required>
            <button type="submit" @disabled(! $canManageWrites)>Save vendor profile</button>
        </form>
    </section>

    <div class="links">
        <a href="{{ route('workspace.home') }}">Return to workspace</a>
    </div>
@endsection
