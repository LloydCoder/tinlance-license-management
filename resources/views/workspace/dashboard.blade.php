<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex,nofollow">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Your TLMS workspace</title>
    <style>
        :root{color-scheme:dark;font-family:Inter,ui-sans-serif,system-ui,sans-serif;background:#0b1220;color:#e5edf8}
        *{box-sizing:border-box}body{margin:0;min-height:100vh;padding:28px 16px;background:#0b1220}
        main{width:min(100%,1080px);margin:0 auto}h1{font-size:1.7rem;margin:0 0 8px}h2{font-size:1.15rem;margin:26px 0 12px}
        h3{font-size:1rem;margin:0 0 8px}p{color:#a9bad0;line-height:1.55}.muted{font-size:.88rem;color:#9cafc7}
        .panel{border:1px solid #293b53;border-radius:14px;background:#101c2d;padding:18px;margin:12px 0}
        .grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(240px,1fr));gap:12px}
        .role{display:inline-block;margin:6px 6px 0 0;padding:4px 8px;border:1px solid #40536b;border-radius:999px;color:#d9e5f3;font-size:.8rem}
        form{margin-top:16px}label{display:block;margin:10px 0 5px;color:#c8d5e5;font-size:.9rem}
        input,select,textarea{width:100%;max-width:100%;padding:10px 11px;border:1px solid #40536b;border-radius:8px;background:#0b1524;color:#f4f7fb;font:inherit}
        textarea{min-height:76px;resize:vertical}button{padding:10px 14px;border:1px solid #55708f;border-radius:9px;background:#23466e;color:#fff;font:inherit;cursor:pointer}
        button:disabled{opacity:.48;cursor:not-allowed}a{color:#9dc8ff}.table-wrap{width:100%;overflow-x:auto}
        table{width:100%;border-collapse:collapse;min-width:620px}th,td{text-align:left;vertical-align:top;padding:10px 8px;border-bottom:1px solid #293b53}
        th{font-size:.8rem;color:#9cafc7;font-weight:600}td{font-size:.9rem}.actions{display:grid;gap:8px;min-width:190px}
        .form-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:10px}
        .status{min-height:1.5em;color:#c9d9ed}.warning{border-color:#745a32}.muted-note{font-size:.82rem;color:#9cafc7}
        @media(max-width:600px){body{padding:20px 12px}.panel{padding:14px}h1{font-size:1.45rem}}
    </style>
</head>
<body>
<main>
    <div class="muted">TINLANCE LICENSE MANAGEMENT SYSTEM</div>
    <h1>Your TLMS workspace</h1>
    <p>Signed in as <strong>{{ $user->name }}</strong>. This workspace shows your active memberships and invitations for tenants you are authorized to manage.</p>

    <h2>Vendor accounts</h2>
    @forelse ($vendorMemberships as $membership)
        <section class="panel">
            <strong>{{ $membership->vendorAccount->display_name }}</strong>
            <p class="muted">{{ $membership->vendorAccount->legal_name }}</p>
            @foreach ($membership->roles as $role)
                <span class="role">{{ $role->name }}</span>
            @endforeach
        </section>
    @empty
        <p class="muted">You do not currently have an active vendor-account membership.</p>
    @endforelse

    <h2>Customer organizations</h2>
    @forelse ($customerMemberships as $membership)
        <section class="panel">
            <strong>{{ $membership->customerOrganization->display_name }}</strong>
            <p class="muted">Managed by {{ $membership->customerOrganization->vendorAccount->display_name }}</p>
            @foreach ($membership->roles as $role)
                <span class="role">{{ $role->name }}</span>
            @endforeach
        </section>
    @empty
        <p class="muted">You do not currently have an active customer-organization membership.</p>
    @endforelse

    @if ($vendorInvitationGroups !== [] || $customerInvitationGroups !== [])
        <h2>Invitation management</h2>
        <section class="panel warning" aria-labelledby="assurance-heading">
            <h3 id="assurance-heading">Security verification for invitation changes</h3>
            @if (! $passwordConfirmationFresh)
                <p>Your password confirmation has expired. Confirm your password before changing invitations.</p>
                <p><a href="{{ route('password.confirm') }}">Confirm password</a></p>
            @endif
            @if (! $mfaStepUpFresh)
                @if ($hasTotp)
                    <form data-step-up-totp>
                        <label for="step-up-code">Authenticator code</label>
                        <div class="form-grid">
                            <input id="step-up-code" name="code" type="text" inputmode="numeric" autocomplete="one-time-code" pattern="[0-9]{6}" minlength="6" maxlength="6" required aria-describedby="step-up-help">
                            <button type="submit">Verify authenticator</button>
                        </div>
                        <p id="step-up-help" class="muted-note">Enter the current six-digit code from your enrolled authenticator.</p>
                    </form>
                @endif
                @if ($hasPasskeys)
                    <button type="button" data-passkey-action="verify">Verify with passkey</button>
                @endif
            @endif
            <p class="status" role="status" aria-live="polite" data-passkey-status></p>
            <p class="status" role="status" aria-live="polite" data-invitation-status></p>
            @if (! $canManageMembershipWrites)
                <p class="muted-note">Create, resend and revoke controls remain disabled until recent password confirmation and fresh MFA step-up are both valid.</p>
            @endif
        </section>
    @endif

    @foreach ($vendorInvitationGroups as $group)
        @php
            $vendor = $group['tenant'];
            $createUrl = url('/api/v1/vendor-accounts/'.$vendor->getKey().'/memberships/invitations');
        @endphp
        <section class="panel" aria-labelledby="vendor-invitations-{{ $vendor->getKey() }}">
            <h3 id="vendor-invitations-{{ $vendor->getKey() }}">{{ $vendor->display_name }} — vendor invitations</h3>
            <p class="muted-note">Pending, expired and failed-delivery invitations for this vendor account. Accepted and revoked invitations are not shown.</p>
            <form data-invitation-form data-action="create" data-endpoint="{{ $createUrl }}">
                <div class="form-grid">
                    <div>
                        <label for="vendor-email-{{ $vendor->getKey() }}">Invitee email</label>
                        <input id="vendor-email-{{ $vendor->getKey() }}" name="email" type="email" maxlength="254" autocomplete="email" required>
                    </div>
                    <div>
                        <label for="vendor-name-{{ $vendor->getKey() }}">Invitee name (optional)</label>
                        <input id="vendor-name-{{ $vendor->getKey() }}" name="name" type="text" minlength="2" maxlength="160" autocomplete="name">
                    </div>
                    <div>
                        <label for="vendor-role-{{ $vendor->getKey() }}">Role</label>
                        <select id="vendor-role-{{ $vendor->getKey() }}" name="role_slug" required>
                            <option value="">Choose a role</option>
                            @foreach ($group['roles'] as $role)
                                <option value="{{ $role->slug }}">{{ $role->name }}</option>
                            @endforeach
                        </select>
                    </div>
                </div>
                <label for="vendor-reason-{{ $vendor->getKey() }}">Invitation reason</label>
                <textarea id="vendor-reason-{{ $vendor->getKey() }}" name="reason" minlength="5" maxlength="500" required></textarea>
                <button type="submit" @disabled(! $canManageMembershipWrites)>Send invitation</button>
                <p class="muted-note" role="status" aria-live="polite" data-form-status></p>
            </form>

            <div class="table-wrap">
                <table>
                    <thead><tr><th scope="col">Invitee</th><th scope="col">Role</th><th scope="col">Delivery</th><th scope="col">Expiry</th><th scope="col">Actions</th></tr></thead>
                    <tbody>
                    @forelse ($group['invitations'] as $invitation)
                        <tr>
                            <td>{{ $invitation->invited_email }}</td>
                            <td>
                                @forelse ($invitation->membership?->roles ?? [] as $role)
                                    <span>{{ $role->name }}</span>
                                @empty
                                    <span class="muted">No role assigned</span>
                                @endforelse
                            </td>
                            <td>{{ $invitation->delivery_status === 'failed' ? 'Delivery failed' : 'Sent' }}</td>
                            <td>
                                @if ($invitation->expires_at?->isPast())
                                    Expired
                                @else
                                    {{ $invitation->expires_at?->format('Y-m-d H:i T') }}
                                @endif
                            </td>
                            <td>
                                <div class="actions">
                                    <form data-invitation-form data-action="resend" data-endpoint="{{ url('/api/v1/vendor-accounts/'.$vendor->getKey().'/memberships/'.$invitation->vendor_membership_id.'/invitation/resend') }}">
                                        <label for="vendor-resend-reason-{{ $invitation->getKey() }}">Resend reason</label>
                                        <input id="vendor-resend-reason-{{ $invitation->getKey() }}" name="reason" type="text" minlength="5" maxlength="500" required>
                                        <button type="submit" @disabled(! $canManageMembershipWrites)>Resend</button>
                                        <p class="muted-note" role="status" aria-live="polite" data-form-status></p>
                                    </form>
                                    <form data-invitation-form data-action="revoke" data-endpoint="{{ url('/api/v1/vendor-accounts/'.$vendor->getKey().'/memberships/'.$invitation->vendor_membership_id.'/revoke') }}">
                                        <label for="vendor-revoke-reason-{{ $invitation->getKey() }}">Revocation reason</label>
                                        <input id="vendor-revoke-reason-{{ $invitation->getKey() }}" name="reason" type="text" minlength="1" maxlength="500" required>
                                        <button type="submit" @disabled(! $canManageMembershipWrites)>Cancel invitation</button>
                                        <p class="muted-note" role="status" aria-live="polite" data-form-status></p>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="muted">No open invitations for this vendor account.</td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
            {{ $group['invitations']->links() }}
        </section>
    @endforeach

    @foreach ($customerInvitationGroups as $group)
        @php
            $organization = $group['tenant'];
            $createUrl = url('/api/v1/customer-organizations/'.$organization->getKey().'/memberships/invitations');
        @endphp
        <section class="panel" aria-labelledby="customer-invitations-{{ $organization->getKey() }}">
            <h3 id="customer-invitations-{{ $organization->getKey() }}">{{ $organization->display_name }} — customer invitations</h3>
            <p class="muted-note">Pending, expired and failed-delivery invitations for this customer organization.</p>
            <form data-invitation-form data-action="create" data-endpoint="{{ $createUrl }}">
                <div class="form-grid">
                    <div>
                        <label for="customer-email-{{ $organization->getKey() }}">Invitee email</label>
                        <input id="customer-email-{{ $organization->getKey() }}" name="email" type="email" maxlength="254" autocomplete="email" required>
                    </div>
                    <div>
                        <label for="customer-name-{{ $organization->getKey() }}">Invitee name (optional)</label>
                        <input id="customer-name-{{ $organization->getKey() }}" name="name" type="text" minlength="2" maxlength="160" autocomplete="name">
                    </div>
                    <div>
                        <label for="customer-role-{{ $organization->getKey() }}">Role</label>
                        <select id="customer-role-{{ $organization->getKey() }}" name="role_slug" required>
                            <option value="">Choose a role</option>
                            @foreach ($group['roles'] as $role)
                                <option value="{{ $role->slug }}">{{ $role->name }}</option>
                            @endforeach
                        </select>
                    </div>
                </div>
                <label for="customer-reason-{{ $organization->getKey() }}">Invitation reason</label>
                <textarea id="customer-reason-{{ $organization->getKey() }}" name="reason" minlength="5" maxlength="500" required></textarea>
                <button type="submit" @disabled(! $canManageMembershipWrites)>Send invitation</button>
                <p class="muted-note" role="status" aria-live="polite" data-form-status></p>
            </form>

            <div class="table-wrap">
                <table>
                    <thead><tr><th scope="col">Invitee</th><th scope="col">Role</th><th scope="col">Delivery</th><th scope="col">Expiry</th><th scope="col">Actions</th></tr></thead>
                    <tbody>
                    @forelse ($group['invitations'] as $invitation)
                        <tr>
                            <td>{{ $invitation->invited_email }}</td>
                            <td>
                                @forelse ($invitation->membership?->roles ?? [] as $role)
                                    <span>{{ $role->name }}</span>
                                @empty
                                    <span class="muted">No role assigned</span>
                                @endforelse
                            </td>
                            <td>{{ $invitation->delivery_status === 'failed' ? 'Delivery failed' : 'Sent' }}</td>
                            <td>
                                @if ($invitation->expires_at?->isPast())
                                    Expired
                                @else
                                    {{ $invitation->expires_at?->format('Y-m-d H:i T') }}
                                @endif
                            </td>
                            <td>
                                <div class="actions">
                                    <form data-invitation-form data-action="resend" data-endpoint="{{ url('/api/v1/customer-organizations/'.$organization->getKey().'/memberships/'.$invitation->customer_membership_id.'/invitation/resend') }}">
                                        <label for="customer-resend-reason-{{ $invitation->getKey() }}">Resend reason</label>
                                        <input id="customer-resend-reason-{{ $invitation->getKey() }}" name="reason" type="text" minlength="5" maxlength="500" required>
                                        <button type="submit" @disabled(! $canManageMembershipWrites)>Resend</button>
                                        <p class="muted-note" role="status" aria-live="polite" data-form-status></p>
                                    </form>
                                    <form data-invitation-form data-action="revoke" data-endpoint="{{ url('/api/v1/customer-organizations/'.$organization->getKey().'/memberships/'.$invitation->customer_membership_id.'/revoke') }}">
                                        <label for="customer-revoke-reason-{{ $invitation->getKey() }}">Revocation reason</label>
                                        <input id="customer-revoke-reason-{{ $invitation->getKey() }}" name="reason" type="text" minlength="1" maxlength="500" required>
                                        <button type="submit" @disabled(! $canManageMembershipWrites)>Cancel invitation</button>
                                        <p class="muted-note" role="status" aria-live="polite" data-form-status></p>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="muted">No open invitations for this customer organization.</td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
            {{ $group['invitations']->links() }}
        </section>
    @endforeach

    <noscript><p class="muted">Invitation changes require JavaScript to submit securely to the existing protected JSON APIs. Invitation listings and statuses remain readable without JavaScript.</p></noscript>

    <p class="muted">All invitation changes are authorized and audited by TLMS server-side services.</p>
    <form method="post" action="{{ route('logout') }}">
        @csrf
        <button type="submit">Sign out</button>
    </form>
</main>
<script type="module" src="{{ asset('build/passkeys.js') }}"></script>
<script type="module" src="{{ asset('build/membership-invitations.js') }}"></script>
</body>
</html>
