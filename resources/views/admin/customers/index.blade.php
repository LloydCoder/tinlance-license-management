@extends('layouts.auth')

@section('title', 'Customer organizations — TLMS')

@section('content')
    <h1>Customer organizations</h1>
    <p>Manage customer records belonging to this TLMS vendor installation. Tenant ownership is assigned by the server and cannot be changed through this workspace.</p>
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

    <section class="panel warning" aria-labelledby="customer-security-heading">
        <h2 id="customer-security-heading">Security verification</h2>
        @if (! $passwordConfirmationFresh)
            <p>Your password confirmation has expired. Confirm your password before creating or updating customer records.</p>
            <p><a href="{{ route('password.confirm') }}">Confirm password</a></p>
        @endif
        @if (! $mfaStepUpFresh)
            <p>Complete a fresh authenticator or passkey verification before changing customer records.</p>
            @if ($hasTotp)
                <form method="post" action="{{ url('/api/v1/security/step-up/totp') }}" data-step-up-totp class="stack">
                    @csrf
                    <label for="customer-step-up-code">Six-digit authenticator code</label>
                    <input id="customer-step-up-code" name="code" type="text" inputmode="numeric" autocomplete="one-time-code" pattern="[0-9]{6}" minlength="6" maxlength="6" required>
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
            <p class="muted">Create and update actions remain disabled until recent password confirmation and fresh MFA verification are valid.</p>
        @endunless
    </section>

    <section class="panel" aria-labelledby="create-customer-heading">
        <h2 id="create-customer-heading">Add customer organization</h2>
        <form method="post" action="{{ route('admin.customers.store') }}" data-customer-organization-form data-method="POST" data-endpoint="{{ route('admin.customers.store') }}" class="stack">
            @csrf
            <label for="new-customer-legal-name">Legal name</label>
            <input id="new-customer-legal-name" name="legal_name" type="text" maxlength="255" required>
            <label for="new-customer-display-name">Display name</label>
            <input id="new-customer-display-name" name="display_name" type="text" maxlength="160" required>
            <label for="new-customer-reference">External reference (optional)</label>
            <input id="new-customer-reference" name="external_reference" type="text" maxlength="100" pattern="[A-Za-z0-9._:-]+">
            <label for="new-customer-billing-email">Billing email (optional)</label>
            <input id="new-customer-billing-email" name="billing_email" type="email" maxlength="254">
            <label for="new-customer-reason">Reason for creation</label>
            <input id="new-customer-reason" name="reason" type="text" minlength="8" maxlength="500" required>
            <button type="submit" @disabled(! $canManageWrites)>Create customer organization</button>
            <p class="muted" role="status" aria-live="polite" data-form-status></p>
        </form>
    </section>

    <h2>Existing organizations</h2>
    <p class="muted">{{ $organizations->total() }} customer organization(s)</p>
    @forelse ($organizations as $organization)
        <section class="panel" aria-labelledby="customer-{{ $organization->getKey() }}">
            <h3 id="customer-{{ $organization->getKey() }}">{{ $organization->display_name }}</h3>
            <p>{{ $organization->legal_name }}</p>
            <p class="muted">Reference: {{ $organization->external_reference ?: '—' }} · Billing: {{ $organization->billing_email ?: '—' }}</p>
            <p class="muted">Status: {{ $organization->status }} · Members: {{ $organization->memberships_count }}</p>
            <details>
                <summary>Edit customer profile</summary>
                <form method="post" action="{{ route('admin.customers.update', $organization) }}" data-customer-organization-form data-method="PATCH" data-endpoint="{{ route('admin.customers.update', $organization) }}" class="stack">
                    @csrf
                    @method('PATCH')
                    <label for="legal-name-{{ $organization->getKey() }}">Legal name</label>
                    <input id="legal-name-{{ $organization->getKey() }}" name="legal_name" type="text" maxlength="255" value="{{ $organization->legal_name }}" required>
                    <label for="display-name-{{ $organization->getKey() }}">Display name</label>
                    <input id="display-name-{{ $organization->getKey() }}" name="display_name" type="text" maxlength="160" value="{{ $organization->display_name }}" required>
                    <label for="reference-{{ $organization->getKey() }}">External reference (optional)</label>
                    <input id="reference-{{ $organization->getKey() }}" name="external_reference" type="text" maxlength="100" value="{{ $organization->external_reference }}" pattern="[A-Za-z0-9._:-]+">
                    <label for="billing-email-{{ $organization->getKey() }}">Billing email (optional)</label>
                    <input id="billing-email-{{ $organization->getKey() }}" name="billing_email" type="email" maxlength="254" value="{{ $organization->billing_email }}">
                    <label for="reason-{{ $organization->getKey() }}">Reason for change</label>
                    <input id="reason-{{ $organization->getKey() }}" name="reason" type="text" minlength="8" maxlength="500" required>
                    <button type="submit" @disabled(! $canManageWrites)>Save customer profile</button>
                    <p class="muted" role="status" aria-live="polite" data-form-status></p>
                </form>
            </details>
        </section>
    @empty
        <p class="muted">No customer organizations have been created yet.</p>
    @endforelse

    {{ $organizations->links() }}
    <div class="links"><a href="{{ route('admin.dashboard') }}">Return to administration</a></div>
@endsection
