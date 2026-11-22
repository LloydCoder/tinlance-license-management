@extends('layouts.auth')

@section('title', 'Customer organization profiles — TLMS')

@section('content')
    <h1>Customer organization profiles</h1>
    <p>Update the display name and billing contact for customer organizations where you hold the Customer Owner role. Legal identity, vendor ownership and lifecycle status remain vendor-controlled.</p>

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

    <section class="panel warning" aria-labelledby="profile-security-heading">
        <h2 id="profile-security-heading">Security verification</h2>
        @if (! $passwordConfirmationFresh)
            <p>Your password confirmation has expired. Confirm your password before changing customer profile information.</p>
            <p><a href="{{ route('password.confirm') }}">Confirm password</a></p>
        @endif
        @if (! $mfaStepUpFresh)
            <p>Complete a fresh authenticator or passkey verification before saving changes.</p>
            @if ($hasTotp)
                <form method="post" action="{{ url('/api/v1/security/step-up/totp') }}" data-step-up-totp class="stack">
                    @csrf
                    <label for="customer-profile-step-up-code">Six-digit authenticator code</label>
                    <input id="customer-profile-step-up-code" name="code" type="text" inputmode="numeric" autocomplete="one-time-code" pattern="[0-9]{6}" minlength="6" maxlength="6" required>
                    <button type="submit" @disabled(! $passwordConfirmationFresh)>Verify authenticator</button>
                </form>
            @endif
            @if ($hasPasskeys)
                <button type="button" class="secondary" data-passkey-action="verify" @disabled(! $passwordConfirmationFresh)>Verify with passkey</button>
            @endif
        @endif
        <p class="status" role="status" aria-live="polite" data-invitation-status></p>
        <p class="status" role="status" aria-live="polite" data-passkey-status></p>
        @unless ($canManageWrites)
            <p class="muted">Profile changes remain disabled until recent password confirmation and fresh MFA verification are valid.</p>
        @endunless
    </section>

    @foreach ($organizations as $organization)
        <section class="panel" id="customer-profile-{{ $organization->getKey() }}" aria-labelledby="customer-profile-heading-{{ $organization->getKey() }}">
            <h2 id="customer-profile-heading-{{ $organization->getKey() }}">{{ $organization->display_name }}</h2>
            <p>{{ $organization->legal_name }}</p>
            <p class="muted">Vendor: {{ $organization->vendorAccount?->display_name }} · Status: {{ $organization->status }}</p>
            <form method="post" action="{{ route('workspace.customer-profiles.update', $organization) }}" data-customer-organization-form data-method="PATCH" data-endpoint="{{ route('workspace.customer-profiles.update', $organization) }}" class="stack">
                @csrf
                @method('PATCH')
                <label for="customer-display-name-{{ $organization->getKey() }}">Organization display name</label>
                <input id="customer-display-name-{{ $organization->getKey() }}" name="display_name" type="text" maxlength="160" value="{{ $organization->display_name }}" required>
                <label for="customer-billing-email-{{ $organization->getKey() }}">Billing contact email</label>
                <input id="customer-billing-email-{{ $organization->getKey() }}" name="billing_email" type="email" maxlength="254" value="{{ $organization->billing_email }}">
                <label for="customer-profile-reason-{{ $organization->getKey() }}">Reason for change</label>
                <input id="customer-profile-reason-{{ $organization->getKey() }}" name="reason" type="text" minlength="8" maxlength="500" required>
                <button type="submit" @disabled(! $canManageWrites)>Save profile</button>
                <p class="muted" role="status" aria-live="polite" data-form-status></p>
            </form>
        </section>
    @endforeach

    <div class="links"><a href="{{ route('workspace.home') }}">Return to workspace</a></div>
@endsection
