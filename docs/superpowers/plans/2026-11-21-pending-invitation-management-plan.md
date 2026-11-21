# Pending Invitation Management Workspace Implementation Plan

> **For agentic workers:** Use superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox syntax for tracking.

**Goal:** Add tenant-scoped invitation management to the existing TLMS Blade workspace without weakening existing authorization, password-confirmation or MFA-step-up controls.

**Architecture:** A focused query service returns only open invitations for tenants the current user can manage. The existing workspace renders those invitations and allowlisted role options; a small Vite-built same-origin module calls the existing JSON APIs for create, resend and revoke, and supports TOTP/passkey step-up. No new persistence tables or API write semantics are introduced.

**Tech Stack:** Laravel 13, PHP 8.3–8.5, Blade, Eloquent, Vite 8, existing Laravel Passkeys JS, existing JSON APIs.

**Spec:** `docs/superpowers/specs/2026-11-21-pending-invitation-management-design.md`

## Global Constraints

- Preserve PHP 8.3–8.5 compatibility and Laravel 13.
- Preserve MySQL 8.4 and MariaDB 10.11 integration compatibility.
- Keep customer hosting free of Node.js, Redis, Docker, SSH and persistent queue-worker requirements.
- Do not add Composer or npm runtime dependencies.
- Keep raw invitation tokens and token hashes out of workspace responses and rendered HTML.
- Reuse existing `MembershipInvitationService`, `MembershipLifecycleService`, `MembershipAuthorization` and API middleware.
- Keep `EnsureRecentPasswordConfirmation`, `EnsureMfaEnrolled`, `EnsureRecentMfaStepUp`, CSRF and throttling enforced by existing write APIs.
- Keep vendor roles `support_agent`, `security_auditor`, `vendor_developer` as directly invitable; keep `finance_admin`, `vendor_product_manager`, `reseller_manager` on maker-checker only.
- Keep customer invitable roles `customer_billing_admin`, `customer_technical_admin`, `customer_member`.

## Review Focus

- A caller with an active membership but no `admins.manage` / `customer.members.manage` permission must not see invitation data or management controls.
- A caller must not see invitations for another vendor or customer organization, even if they submit another tenant's identifier.
- Suspended users, vendors and customer organizations must fail closed.
- Expired and failed-delivery invitations must remain visible and resendable; accepted/revoked invitations must not appear.
- Stale password or MFA assurance must block writes and produce an actionable UI path without bypassing API middleware.

---

### Task 1: Scoped invitation query and assignable role catalogue

**Files:**
- Create: `app/Services/Authorization/MembershipInvitationWorkspaceQuery.php`
- Modify: `app/Services/Authorization/MembershipRoleAssignmentService.php`
- Test: `tests/Feature/MembershipInvitationWorkspaceTest.php`

**Interfaces:**
- `vendorInvitations(User $actor, VendorAccount $vendor): LengthAwarePaginator`
- `customerInvitations(User $actor, CustomerOrganization $organization): LengthAwarePaginator`
- `assignableVendorRoles(): Collection<int, Role>`
- `assignableCustomerRoles(): Collection<int, Role>`

- [ ] **Step 1: Write failing query and role-catalogue tests**
  - Vendor manager sees only non-accepted, non-revoked invitations under the specified active vendor; expired and failed-delivery rows remain included.
  - Customer manager sees only invitations under the specified active organization and active parent vendor.
  - Missing permission, inactive actor, suspended tenant and cross-tenant caller fail closed.
  - Each paginator uses 25 rows per page and a tenant-specific page parameter.
  - Returned role collections contain only the exact directly assignable vendor/customer slugs; no maker-checker-only vendor role is returned.

- [ ] **Step 2: Run focused tests and confirm RED**
  - Run: `composer test:framework -- --filter=MembershipInvitationWorkspaceTest`
  - Expected: failures because the query service and role catalogue methods do not yet exist.

- [ ] **Step 3: Implement query service and role catalogue methods**
  - Reload/authorize the actor and tenant through `MembershipAuthorization`.
  - Query invitation rows with `accepted_at IS NULL`, `revoked_at IS NULL`, and an invited target membership.
  - Eager-load only `membership.user`, `membership.roles` and `inviter`; never select or serialize token fields into the view.
  - Use `paginate(25, ['*'], 'vendor_'.$vendor->getKey().'_page')` and the corresponding customer page name.
  - Expose role queries from the same private allowlists used by role assignment; do not duplicate role slug arrays in the controller or view.

- [ ] **Step 4: Run focused tests and confirm GREEN**
  - Run: `composer test:framework -- --filter=MembershipInvitationWorkspaceTest`
  - Expected: all query and role-catalogue tests pass.

- [ ] **Step 5: Commit**
  - Commit message: `feat(identity): add tenant-scoped invitation workspace queries`

### Task 2: Workspace authorization and view model

**Files:**
- Modify: `app/Http/Controllers/Identity/WorkspaceController.php`
- Test: `tests/Feature/MembershipInvitationWorkspaceTest.php`

**Interfaces:**
- `WorkspaceController::__invoke(Request $request, MembershipAuthorization $authorization, MembershipInvitationWorkspaceQuery $invitations, MembershipRoleAssignmentService $roles): View|RedirectResponse`
- View data: `vendorInvitationGroups`, `customerInvitationGroups`, `passwordConfirmationFresh`, `mfaStepUpFresh`, `hasTotp`, `hasPasskeys`, `canManageMembershipWrites`.

- [ ] **Step 1: Write failing workspace view tests**
  - Vendor admins receive their vendor invitation group; non-admin vendor users do not.
  - Customer managers receive their customer invitation group; ordinary customer members do not.
  - Cross-tenant invitations never appear in rendered HTML.
  - The controller exposes assurance state but never raw token/hash fields.

- [ ] **Step 2: Run focused tests and confirm RED**
  - Run: `composer test:framework -- --filter=MembershipInvitationWorkspaceTest`
  - Expected: view data assertions fail because invitation groups and assurance flags are absent.

- [ ] **Step 3: Implement grouped workspace view data**
  - Iterate only the authenticated user's already-loaded active tenant memberships.
  - Use `vendorCan(..., 'admins.manage')` and `customerCan(..., 'customer.members.manage')` before constructing each invitation group.
  - Query invitation rows through Task 1 services.
  - Compute password-confirmation freshness and five-minute TOTP/passkey step-up freshness from the same configuration and session keys used by the middleware.
  - Set `url.intended=/workspace` only when a management-capable caller needs password confirmation.

- [ ] **Step 4: Run focused tests and confirm GREEN**
  - Run: `composer test:framework -- --filter=MembershipInvitationWorkspaceTest`

- [ ] **Step 5: Commit**
  - Commit message: `feat(identity): scope workspace invitation management by tenant`

### Task 3: Blade management UI and same-origin interactions

**Files:**
- Modify: `resources/views/workspace/dashboard.blade.php`
- Create: `resources/js/membership-invitations.js`
- Modify: `vite.config.js`
- Test: `tests/Feature/MembershipInvitationWorkspaceTest.php`

**Interfaces:**
- Vendor create endpoint: `POST /api/v1/vendor-accounts/{vendor}/memberships/invitations`
- Vendor resend endpoint: `POST /api/v1/vendor-accounts/{vendor}/memberships/{membership}/invitation/resend`
- Vendor revoke endpoint: `POST /api/v1/vendor-accounts/{vendor}/memberships/{membership}/revoke`
- Customer endpoints use `/api/v1/customer-organizations/{organization}/...`.
- TOTP step-up endpoint: `POST /api/v1/security/step-up/totp`.
- Passkey step-up uses the existing `Passkeys.verify({ remember: true })` flow and `RecordPasskeyStepUp` listener.

- [ ] **Step 1: Write failing view and asset-contract tests**
  - Authorized groups render email, role, delivery state, expiry, create/resend/revoke controls and tenant-specific API URLs.
  - Privileged vendor roles are absent from invite forms.
  - No `token_hash`, raw token or invitation token URL is rendered.
  - Stale password confirmation renders the password-confirmation link; stale step-up renders TOTP/passkey controls.
  - The workspace includes both `build/passkeys.js` and `build/membership-invitations.js`.

- [ ] **Step 2: Run focused tests and confirm RED**
  - Run: `composer test:framework -- --filter=MembershipInvitationWorkspaceTest`

- [ ] **Step 3: Implement Blade UI and JS module**
  - Keep invitation tables readable on mobile and accessible with semantic headings, labels, status text and live regions.
  - Use Blade escaping for every user-controlled field.
  - Use same-origin `fetch`, session credentials, CSRF header and JSON bodies.
  - Provide create, resend and revoke forms; require an audit reason for each mutation and confirm before revocation.
  - Provide TOTP verification through the existing step-up endpoint and passkey verification through the existing Passkeys module.
  - Disable mutation controls until recent password confirmation and fresh step-up are present.
  - Handle 401/403/409/410/422/423/429/503 with safe user-facing messages; never surface exception traces.
  - Preserve the existing output filename `public/build/passkeys.js`; add `public/build/membership-invitations.js` as a second Vite entry.

- [ ] **Step 4: Run focused tests and build assets**
  - Run: `composer test:framework -- --filter=MembershipInvitationWorkspaceTest`
  - Run: `npm ci && npm run build`
  - Expected: focused tests pass and both browser bundles are emitted.

- [ ] **Step 5: Commit**
  - Commit message: `feat(identity): add invitation management to workspace`

### Task 4: Documentation and complete verification

**Files:**
- Modify: `docs/architecture/INVITATION-LIFECYCLE.md`
- Modify: `IMPLEMENTATION-STATUS.md`
- Modify: `README.md`
- Modify: `CHANGELOG.md`

- [ ] **Step 1: Document workspace management and remaining limitations**
  - Document tenant-scoped list access, role allowlists, step-up UX, action endpoints and no-token-rendering rule.
  - Keep reinstatement, durable mail outbox, delivery telemetry and full member directory explicitly outstanding.

- [ ] **Step 2: Run full verification**
  - Run: `composer test`
  - Run: `composer test:framework`
  - Run: `composer format:check`
  - Run: `composer analyse`
  - GitHub CI must pass PHP 8.3/8.4/8.5, MySQL 8.4, MariaDB 10.11, OpenAPI, documentation governance and cPanel artifact verification.
  - Security analysis must pass Larastan, Semgrep, Gitleaks and OSV-Scanner on the exact PR head.

- [ ] **Step 3: Review and merge**
  - Verify the PR diff, exact-head checks and tenant-isolation tests.
  - Merge only when all required checks pass; then verify post-merge main CI/Security.

