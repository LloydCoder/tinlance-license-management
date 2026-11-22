<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex,nofollow">
    <meta name="theme-color" content="#0A0C0B">
    <link rel="icon" href="{{ asset('favicon.svg') }}" type="image/svg+xml">
    <link rel="stylesheet" href="{{ asset('brand/tlms-brand.css') }}">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Your TLMS workspace</title>
</head>
<body>
<main class="tlms-shell">
    <img class="tlms-brand-lockup" src="{{ asset('brand/tlms-lockup-horizontal-dark.svg') }}" alt="TLMS — Tinlance License Management System" width="232" height="78">
    <h1>Your TLMS workspace</h1>
    <p>Signed in as <strong>{{ $user->name }}</strong>. This workspace shows your active memberships and invitations for tenants you are authorized to manage.</p>
    @if (is_string($mfaRecoveryCodeReplacement))
        <section class="panel warning" aria-labelledby="replacement-recovery-code-heading">
            <h2 id="replacement-recovery-code-heading">Your replacement MFA recovery code — shown once</h2>
            <p>One recovery code was used to sign in. Save this replacement offline now; it cannot be retrieved later.</p>
            <code>{{ $mfaRecoveryCodeReplacement }}</code>
        </section>
    @endif

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
                    <form method="post" action="{{ route('workspace.home') }}" data-step-up-totp>
                        @csrf
                        <label for="step-up-code">Authenticator code</label>
                        <div class="form-grid">
                            <input id="step-up-code" name="code" type="text" inputmode="numeric" autocomplete="one-time-code" pattern="[0-9]{6}" minlength="6" maxlength="6" required aria-describedby="step-up-help">
                            <button type="submit" @disabled(! $passwordConfirmationFresh)>Verify authenticator</button>
                        </div>
                        <p id="step-up-help" class="muted-note">Enter the current six-digit code from your enrolled authenticator.</p>
                    </form>
                @endif
                @if ($hasPasskeys)
                    <button type="button" data-passkey-action="verify" @disabled(! $passwordConfirmationFresh)>Verify with passkey</button>
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

            <h4>Active vendor members</h4>
            <p class="muted-note">Only active users with active membership in this vendor account are listed.</p>
            <div class="table-wrap">
                <table>
                    <thead><tr><th scope="col">Member</th><th scope="col">Email</th><th scope="col">Roles</th><th scope="col">Joined</th></tr></thead>
                    <tbody>
                    @forelse ($group['members'] as $member)
                        <tr>
                            <td>{{ $member->user?->name }}</td>
                            <td>{{ $member->user?->email }}</td>
                            <td>
                                @forelse ($member->roles as $role)
                                    <span class="role">{{ $role->name }}</span>
                                @empty
                                    <span class="muted">No role assigned</span>
                                @endforelse
                            </td>
                            <td>{{ $member->joined_at?->format('Y-m-d H:i T') ?? '—' }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="4" class="muted">No active members for this vendor account.</td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
            {{ $group['members']->links() }}

            <form method="post" action="{{ route('workspace.home') }}" data-invitation-form data-action="create" data-endpoint="{{ $createUrl }}">
                @csrf
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
                    <thead><tr><th scope="col">Invitee</th><th scope="col">Role</th><th scope="col">Delivery</th><th scope="col">Created</th><th scope="col">Expiry</th><th scope="col">Actions</th></tr></thead>
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
                            <td>
                                @if ($invitation->delivery_status === 'failed')
                                    Delivery failed
                                @elseif ($invitation->delivery_status === 'sent')
                                    Sent
                                @else
                                    Delivery pending
                                @endif
                            </td>
                            <td>{{ $invitation->created_at?->format('Y-m-d H:i T') }}</td>
                            <td>
                                @if ($invitation->expires_at?->isPast())
                                    Expired
                                @else
                                    {{ $invitation->expires_at?->format('Y-m-d H:i T') }}
                                @endif
                            </td>
                            <td>
                                <div class="actions">
                                    <form method="post" action="{{ route('workspace.home') }}" data-invitation-form data-action="resend" data-endpoint="{{ url('/api/v1/vendor-accounts/'.$vendor->getKey().'/memberships/'.$invitation->vendor_membership_id.'/invitation/resend') }}">
                                        @csrf
                                        <label for="vendor-resend-reason-{{ $invitation->getKey() }}">Resend reason</label>
                                        <input id="vendor-resend-reason-{{ $invitation->getKey() }}" name="reason" type="text" minlength="5" maxlength="500" required>
                                        <button type="submit" @disabled(! $canManageMembershipWrites)>Resend</button>
                                        <p class="muted-note" role="status" aria-live="polite" data-form-status></p>
                                    </form>
                                    <form method="post" action="{{ route('workspace.home') }}" data-invitation-form data-action="revoke" data-endpoint="{{ url('/api/v1/vendor-accounts/'.$vendor->getKey().'/memberships/'.$invitation->vendor_membership_id.'/revoke') }}">
                                        @csrf
                                        <label for="vendor-revoke-reason-{{ $invitation->getKey() }}">Revocation reason</label>
                                        <input id="vendor-revoke-reason-{{ $invitation->getKey() }}" name="reason" type="text" minlength="1" maxlength="500" required>
                                        <button type="submit" @disabled(! $canManageMembershipWrites)>Cancel invitation</button>
                                        <p class="muted-note" role="status" aria-live="polite" data-form-status></p>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="muted">No open invitations for this vendor account.</td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
            {{ $group['invitations']->links() }}

            <h4>Revoked vendor memberships</h4>
            <p class="muted-note">Prior roles are shown for audit context only. Reinstatement clears them, assigns a new allowlisted role and sends a fresh invitation; access resumes only after acceptance.</p>
            <div class="table-wrap">
                <table>
                    <thead><tr><th scope="col">Former member</th><th scope="col">Prior roles</th><th scope="col">Revoked</th><th scope="col">Reason</th><th scope="col">Reinstate</th></tr></thead>
                    <tbody>
                    @forelse ($group['revokedMemberships'] as $revokedMembership)
                        <tr>
                            <td><strong>{{ $revokedMembership->user?->name }}</strong><br>{{ $revokedMembership->user?->email }}</td>
                            <td>
                                @forelse ($revokedMembership->roles as $role)
                                    <span>{{ $role->name }}</span>
                                @empty
                                    <span class="muted">No prior role assigned</span>
                                @endforelse
                            </td>
                            <td>{{ $revokedMembership->latestLifecycleEvent?->created_at?->format('Y-m-d H:i T') }}</td>
                            <td>{{ $revokedMembership->latestLifecycleEvent?->reason }}</td>
                            <td>
                                <form method="post" action="{{ route('workspace.home') }}" data-invitation-form data-action="reinstate" data-endpoint="{{ url('/api/v1/vendor-accounts/'.$vendor->getKey().'/memberships/'.$revokedMembership->getKey().'/reinstate') }}">
                                    @csrf
                                    <label for="vendor-reinstate-role-{{ $revokedMembership->getKey() }}">New role</label>
                                    <select id="vendor-reinstate-role-{{ $revokedMembership->getKey() }}" name="role_slug" required>
                                        <option value="">Choose a role</option>
                                        @foreach ($group['roles'] as $role)
                                            <option value="{{ $role->slug }}">{{ $role->name }}</option>
                                        @endforeach
                                    </select>
                                    <label for="vendor-reinstate-reason-{{ $revokedMembership->getKey() }}">Reinstatement reason</label>
                                    <textarea id="vendor-reinstate-reason-{{ $revokedMembership->getKey() }}" name="reason" minlength="5" maxlength="500" required></textarea>
                                    <button type="submit" @disabled(! $canManageMembershipWrites)>Send reinstatement invitation</button>
                                    <p class="muted-note" role="status" aria-live="polite" data-form-status></p>
                                </form>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="muted">No revoked vendor memberships for this account.</td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
            {{ $group['revokedMemberships']->links() }}
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

            <h4>Active customer members</h4>
            <p class="muted-note">Only active users with active membership in this customer organization are listed.</p>
            <div class="table-wrap">
                <table>
                    <thead><tr><th scope="col">Member</th><th scope="col">Email</th><th scope="col">Roles</th><th scope="col">Joined</th></tr></thead>
                    <tbody>
                    @forelse ($group['members'] as $member)
                        <tr>
                            <td>{{ $member->user?->name }}</td>
                            <td>{{ $member->user?->email }}</td>
                            <td>
                                @forelse ($member->roles as $role)
                                    <span class="role">{{ $role->name }}</span>
                                @empty
                                    <span class="muted">No role assigned</span>
                                @endforelse
                            </td>
                            <td>{{ $member->joined_at?->format('Y-m-d H:i T') ?? '—' }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="4" class="muted">No active members for this customer organization.</td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
            {{ $group['members']->links() }}

            <form method="post" action="{{ route('workspace.home') }}" data-invitation-form data-action="create" data-endpoint="{{ $createUrl }}">
                @csrf
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
                    <thead><tr><th scope="col">Invitee</th><th scope="col">Role</th><th scope="col">Delivery</th><th scope="col">Created</th><th scope="col">Expiry</th><th scope="col">Actions</th></tr></thead>
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
                            <td>
                                @if ($invitation->delivery_status === 'failed')
                                    Delivery failed
                                @elseif ($invitation->delivery_status === 'sent')
                                    Sent
                                @else
                                    Delivery pending
                                @endif
                            </td>
                            <td>{{ $invitation->created_at?->format('Y-m-d H:i T') }}</td>
                            <td>
                                @if ($invitation->expires_at?->isPast())
                                    Expired
                                @else
                                    {{ $invitation->expires_at?->format('Y-m-d H:i T') }}
                                @endif
                            </td>
                            <td>
                                <div class="actions">
                                    <form method="post" action="{{ route('workspace.home') }}" data-invitation-form data-action="resend" data-endpoint="{{ url('/api/v1/customer-organizations/'.$organization->getKey().'/memberships/'.$invitation->customer_membership_id.'/invitation/resend') }}">
                                        @csrf
                                        <label for="customer-resend-reason-{{ $invitation->getKey() }}">Resend reason</label>
                                        <input id="customer-resend-reason-{{ $invitation->getKey() }}" name="reason" type="text" minlength="5" maxlength="500" required>
                                        <button type="submit" @disabled(! $canManageMembershipWrites)>Resend</button>
                                        <p class="muted-note" role="status" aria-live="polite" data-form-status></p>
                                    </form>
                                    <form method="post" action="{{ route('workspace.home') }}" data-invitation-form data-action="revoke" data-endpoint="{{ url('/api/v1/customer-organizations/'.$organization->getKey().'/memberships/'.$invitation->customer_membership_id.'/revoke') }}">
                                        @csrf
                                        <label for="customer-revoke-reason-{{ $invitation->getKey() }}">Revocation reason</label>
                                        <input id="customer-revoke-reason-{{ $invitation->getKey() }}" name="reason" type="text" minlength="1" maxlength="500" required>
                                        <button type="submit" @disabled(! $canManageMembershipWrites)>Cancel invitation</button>
                                        <p class="muted-note" role="status" aria-live="polite" data-form-status></p>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="muted">No open invitations for this customer organization.</td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
            {{ $group['invitations']->links() }}

            <h4>Revoked customer memberships</h4>
            <p class="muted-note">Prior roles are shown for audit context only. Reinstatement clears them, assigns a new allowlisted customer role and sends a fresh invitation; access resumes only after acceptance.</p>
            <div class="table-wrap">
                <table>
                    <thead><tr><th scope="col">Former member</th><th scope="col">Prior roles</th><th scope="col">Revoked</th><th scope="col">Reason</th><th scope="col">Reinstate</th></tr></thead>
                    <tbody>
                    @forelse ($group['revokedMemberships'] as $revokedMembership)
                        <tr>
                            <td><strong>{{ $revokedMembership->user?->name }}</strong><br>{{ $revokedMembership->user?->email }}</td>
                            <td>
                                @forelse ($revokedMembership->roles as $role)
                                    <span>{{ $role->name }}</span>
                                @empty
                                    <span class="muted">No prior role assigned</span>
                                @endforelse
                            </td>
                            <td>{{ $revokedMembership->latestLifecycleEvent?->created_at?->format('Y-m-d H:i T') }}</td>
                            <td>{{ $revokedMembership->latestLifecycleEvent?->reason }}</td>
                            <td>
                                <form method="post" action="{{ route('workspace.home') }}" data-invitation-form data-action="reinstate" data-endpoint="{{ url('/api/v1/customer-organizations/'.$organization->getKey().'/memberships/'.$revokedMembership->getKey().'/reinstate') }}">
                                    @csrf
                                    <label for="customer-reinstate-role-{{ $revokedMembership->getKey() }}">New role</label>
                                    <select id="customer-reinstate-role-{{ $revokedMembership->getKey() }}" name="role_slug" required>
                                        <option value="">Choose a role</option>
                                        @foreach ($group['roles'] as $role)
                                            <option value="{{ $role->slug }}">{{ $role->name }}</option>
                                        @endforeach
                                    </select>
                                    <label for="customer-reinstate-reason-{{ $revokedMembership->getKey() }}">Reinstatement reason</label>
                                    <textarea id="customer-reinstate-reason-{{ $revokedMembership->getKey() }}" name="reason" minlength="5" maxlength="500" required></textarea>
                                    <button type="submit" @disabled(! $canManageMembershipWrites)>Send reinstatement invitation</button>
                                    <p class="muted-note" role="status" aria-live="polite" data-form-status></p>
                                </form>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="muted">No revoked customer memberships for this organization.</td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
            {{ $group['revokedMemberships']->links() }}
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
