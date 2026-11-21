# Pending Invitation Management Workspace — Design

**Date:** 2026-11-21
**Status:** Approved implementation direction under the user's standing instruction to continue sequentially.

## Intent

Give vendor administrators and customer-organization managers a safe, usable way to see and manage invitations they are authorized to administer. The current workspace shows only the signed-in user's active memberships; invitation creation, resend, acceptance and revocation APIs now exist, but tenant administrators cannot inspect pending, expired or failed-delivery invitations from the browser.

## Goals

- Show open invitations only for vendor accounts and customer organizations where the signed-in user has the matching management permission.
- Display invited email, role, delivery status, expiry and creation time; include expired and failed-delivery invitations so they can be recovered.
- Support create, resend and cancel/revoke actions using existing APIs and services.
- Require the same verified session, recent password confirmation, MFA enrollment, fresh TOTP/passkey step-up, CSRF protection and throttling already enforced by the API.
- Preserve the shared-hosting-first architecture: Laravel Blade, PHP, MySQL/MariaDB and build-time Vite assets only.

## Non-goals

- No new invitation token format, persistence tables, queue worker, Redis dependency or email provider.
- No bulk operations, role editing, invitation reinstatement, audit export or full tenant portal navigation.
- No access to accepted/revoked invitation token material or raw tokens in the workspace.
- No separate SPA or client-side router.

## Existing behavior and constraints

- `WorkspaceController` currently loads only the authenticated user's active memberships.
- `MembershipInvitationService` already implements vendor/customer create, resend, prepare and accept operations.
- `MembershipLifecycleService` revokes invited or active memberships, records immutable lifecycle evidence and invalidates pending invitation tokens.
- Existing API write routes enforce recent password confirmation, MFA enrollment, fresh step-up and throttling; step-up accepts TOTP or a verified passkey event.
- `resources/js/passkeys.js` already calls Laravel Passkeys; Vite currently emits `public/build/passkeys.js`.
- Tenant authorization must remain service-enforced and database-reloaded. UI visibility is not an authorization boundary.

## Approaches considered

1. **Blade workspace + focused Vite module calling existing JSON APIs (chosen).** The server renders tenant-scoped invitation rows and forms. A small same-origin module posts JSON to existing create/resend/revoke endpoints, handles RFC 9457/423 errors, and refreshes the workspace after success. This reuses existing authorization and MFA enforcement without duplicating business logic.
2. **Separate SPA or frontend portal.** Rejected for this increment because it adds another runtime/build/deployment surface and does not fit the current cPanel-first modular monolith.
3. **New server-rendered POST controllers that duplicate invitation/revocation workflows.** Rejected because they would duplicate validation, authorization, error mapping and step-up behavior already implemented by the API services.

## Design decisions

- Keep `GET /workspace` as the entry point. Add manageable-tenant invitation groups only when the active caller has `admins.manage` for a vendor or `customer.members.manage` for a customer organization.
- Add a focused read/query service for open invitation records. Revalidate the caller and tenant from the database; scope every query to the parent tenant; eager-load only the membership user, assigned roles and inviter.
- Include invitations with `accepted_at IS NULL`, `revoked_at IS NULL` and an `invited` membership. Do not exclude expired or failed-delivery records; label them so the administrator can resend. Paginate 25 per tenant and use tenant-specific page parameter names.
- Do not return `token_hash`, raw token, recovery credentials or other secret fields to the view.
- Use the existing API endpoints for create, resend and revoke. Require a reason for every write. The cancel action revokes the invited membership through the existing lifecycle API so token invalidation and immutable lifecycle evidence remain atomic.
- Render only role choices that the existing role-assignment service considers directly assignable. Privileged vendor roles remain unavailable in invitation forms and must continue through maker-checker approval.
- Use an explicit recent-password confirmation link when the session is stale. After password confirmation, return to `/workspace`.
- Offer both TOTP step-up and passkey verification in the workspace. A successful step-up refreshes the page; writes remain disabled until the server-side session assurance is fresh.
- Keep the UI progressively understandable without JavaScript: invitation rows and statuses remain readable; action forms explain that JavaScript is required to submit securely through the JSON API.

## Security invariants

- Never rely on hidden controls or rendered tenant IDs for authorization.
- Cross-tenant parent/child mismatches return 404 or authorization denial without leaking invitation existence.
- Tenant suspension, user suspension, inactive membership or missing permission must prevent listing and mutation.
- Escape all user-controlled values using Blade defaults; never render token hashes or raw tokens.
- Use same-origin `fetch`, `credentials: 'same-origin'`, CSRF token header and JSON content type.
- Handle 401/403/409/410/422/423/429/503 responses without exposing stack traces or internal exception text.
- Require a fresh five-minute TOTP/passkey step-up and recent password confirmation before create/resend/revoke; the UI must not weaken the API middleware.
- Require an explicit confirmation before revocation and a non-empty audit reason.

## Acceptance criteria

- A vendor administrator sees only invitations belonging to their active vendor account(s); a customer manager sees only invitations for their active customer organization(s).
- Users without the corresponding management permission see no invitation-management panel and cannot access the data through direct requests.
- Pending, expired and failed-delivery invitations are visible with accurate state labels; accepted and revoked invitations are absent.
- Create uses the existing invite endpoint; resend rotates the token via the existing resend endpoint; revoke uses the existing membership lifecycle endpoint.
- Cross-tenant IDs, suspended tenants/users, revoked memberships and stale MFA/password assurance are denied.
- The UI never exposes invitation token material.
- Tests cover tenant isolation, pagination, role allowlists, delivery failures, expired/resend behavior, revoke invalidation, CSRF/session assurance and safe error rendering.

## Remaining work after this increment

Full member directory and role administration, role editing, reinstatement, approval inbox, notification preferences, delivery telemetry and a durable mail outbox remain separate Phase 4/operations work.
