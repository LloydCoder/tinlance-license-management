# Membership invitation lifecycle

**Status:** Invitation APIs, acceptance flow, tenant-admin management workspace and explicit audited membership reinstatement are implemented.

## Scope

TLMS supports vendor-account and customer-organization membership invitations. An invitation provisions or targets an active user, creates an `invited` membership, assigns only a role permitted by the membership-role assignment service, and sends a one-time acceptance link.

Invitation creation and resend are privileged tenant actions. The HTTP routes require an authenticated, verified, active session, enrolled MFA, recent password confirmation, fresh MFA step-up, CSRF protection and throttling. The service independently reloads the actor, membership and tenant state and checks the matching tenant-scoped permission.

## Lifecycle

1. **Create:** In one database transaction, validate the actor and tenant, normalize the email address, find or provision the active user, create the invited membership, assign an allowlisted role and persist an invitation record.
2. **Deliver:** After commit, synchronously send the invitation email. Delivery status is recorded as `pending`, `sent` or `failed`. A delivery failure does not silently roll back the already-created membership; an administrator must correct mail configuration and resend.
3. **Prepare:** The recipient opens an HTTPS link. The raw 256-bit random token is placed in the URL fragment, read by same-origin page code, removed from browser history, and submitted to the prepare endpoint. The server stores only the SHA-256 token hash in the session.
4. **Accept new account:** An unverified invitee sets a name and a password with a minimum length of 15 characters. The transaction revalidates the invitation and tenant, verifies the invited email, invalidates prior sessions, activates the membership, marks the invitation accepted and appends immutable membership lifecycle evidence.
5. **Accept existing account:** A verified invitee must sign in to the exact invited account and complete recent password confirmation before accepting. A different authenticated account cannot complete either acceptance path.
6. **Expire, resend or revoke:** The default invitation lifetime is 72 hours, bounded to 1–168 hours. Resend revokes earlier pending tokens and creates a fresh token. Revoking the invited membership invalidates its pending invitations. Accepted invitations cannot be reused.
7. **Reinstate:** A tenant administrator with the matching membership-management permission, recent password confirmation, enrolled MFA and fresh five-minute TOTP/passkey step-up may reinstate a revoked membership. The existing membership row transitions `revoked -> invited`; the target account and parent tenant must still be active. All previous role pivots are removed through the role-assignment service and recorded as immutable role-revocation events; only a directly assignable low-risk role may be granted in the reinstatement transaction. Protected `super_admin`/`platform_admin` role cleanup additionally requires an acting vendor Super Admin. The service creates a new 256-bit invitation token, stores only its SHA-256 hash, and delivers a new invitation. Earlier revoked invitation tokens remain invalid. The invitee must accept the new invitation under the normal verified-identity flow before access resumes.

## Tenant-admin workspace

The authenticated workspace groups invitations by the active vendor account or customer organization. Vendor-side workspace access is granted by the least-privilege vendor_admin role (PR #70), not by platform_admin: the first active vendor administrator is bootstrapped by a Super Admin, and all later grants require maker-checker approval. The controller revalidates the actor's tenant permissions through database-backed authorization before invoking the invitation workspace query. Each tenant receives its own paginated result set; the query includes pending, expired and failed-delivery invitations and excludes accepted or revoked invitations.

The workspace displays the invitee email, assigned role, delivery state, creation time and expiry. It offers create, resend and revoke actions through the existing tenant-scoped JSON APIs. The UI does not create an alternate mutation path or authorize requests from client-supplied tenant identifiers. Direct invitation role choices come from the same allowlists as the invitation service; maker-checker-only roles are not offered.

All invitation mutations remain disabled until recent password confirmation and a fresh five-minute TOTP or enrolled-passkey step-up are present. The server-side APIs independently enforce the same assurance, tenant authorization, CSRF protection and throttling. Browser interactions use same-origin POST requests with CSRF headers; the no-JavaScript form fallback is POST-only and does not put invitation data in a URL. The workspace query never selects or renders raw invitation tokens or token hashes.

The passkey and invitation JavaScript entry points are compiled into public/build/ during release packaging. Node.js 22.16.0 or newer and npm are build-time requirements for CI/release packaging only; the cPanel customer runtime does not require Node.js, npm, Composer or a persistent worker.

## Security invariants

- Generate 32 random bytes per invitation; expose the raw token only in the email URL and persist only its 64-character SHA-256 hexadecimal hash.
- Compare normalized email identities with constant-time equality checks.
- Reject malformed, expired, revoked, accepted, orphaned or tenant-inactive invitations with a generic unavailable response.
- Require the invited user's account to remain active and the parent vendor/customer organization to remain active at prepare and acceptance time.
- Lock the persisted tenant, membership, invitation and user rows during acceptance to prevent concurrent acceptance or revocation races.
- Reinstatement serializes on the persisted parent vendor (and customer organization), membership and target user rows; it never reuses a prior invitation token or silently restores historical roles.
- Keep invitation identity, inviter, email, reason, token hash and expiry immutable at the Eloquent model boundary; retain database foreign-key restrictions as a second deletion barrier.
- Never put raw tokens in query strings, application logs or rendered page content. Acceptance pages use `no-referrer`, `no-store`, `noindex`, frame denial and a restrictive same-origin Content Security Policy.
- Do not grant access to a different account merely because a browser session contains an invitation token.

## Shared-hosting behavior

Invitation email delivery is synchronous and does not require Redis, a queue worker or a persistent process. Node.js/npm are used only by the CI/release builder to compile browser bundles; they are removed from the production ZIP and are not required on customer hosting. Production requires a valid HTTPS `APP_URL` and functioning outbound mail transport. A future durable outbox, retry scheduler, bounce tracking and delivery monitoring are not part of this increment.

## Remaining work

- Add workspace controls and a revoked-member query for the reinstatement API; the current workspace still focuses on pending/expired/failed invitations.
- Add mail transport configuration validation, provider-specific delivery telemetry and operational retry controls.
- Add broader concurrency tests for simultaneous invite creation, resend, acceptance and membership revocation on MySQL and MariaDB.
- Extend privacy retention, audit export and notification preferences.
