# Membership invitation lifecycle

**Status:** Implemented API and acceptance flow; pending-invitation management UI and reinstatement remain outstanding.

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

## Security invariants

- Generate 32 random bytes per invitation; expose the raw token only in the email URL and persist only its 64-character SHA-256 hexadecimal hash.
- Compare normalized email identities with constant-time equality checks.
- Reject malformed, expired, revoked, accepted, orphaned or tenant-inactive invitations with a generic unavailable response.
- Require the invited user's account to remain active and the parent vendor/customer organization to remain active at prepare and acceptance time.
- Lock the persisted tenant, membership, invitation and user rows during acceptance to prevent concurrent acceptance or revocation races.
- Keep invitation identity, inviter, email, reason, token hash and expiry immutable at the Eloquent model boundary; retain database foreign-key restrictions as a second deletion barrier.
- Never put raw tokens in query strings, application logs or rendered page content. Acceptance pages use `no-referrer`, `no-store`, `noindex`, frame denial and a restrictive same-origin Content Security Policy.
- Do not grant access to a different account merely because a browser session contains an invitation token.

## Shared-hosting behavior

Invitation email delivery is synchronous and does not require Redis, a queue worker, Node.js or a persistent process. Production requires a valid HTTPS `APP_URL` and functioning outbound mail transport. A future durable outbox, retry scheduler, bounce tracking and delivery monitoring are not part of this increment.

## Remaining work

- Build tenant-admin UI for listing pending, expired, accepted and failed-delivery invitations, with controlled resend and revoke actions.
- Add explicit membership reinstatement as a separate audited workflow.
- Add mail transport configuration validation, provider-specific delivery telemetry and operational retry controls.
- Add broader concurrency tests for simultaneous invite creation, resend, acceptance and membership revocation on MySQL and MariaDB.
- Extend privacy retention, audit export and notification preferences.
