# Identity and organization persistence baseline

**Status:** Identity/membership persistence, role catalogue, membership-level authorization, Super Admin authentication, fresh MFA step-up for membership role changes, maker-checker for high-risk vendor role grants and tenant-admin membership revocation are implemented. PR #55 adds transactional `invited|active -> revoked` transitions and immutable lifecycle evidence. Complete resource-level ABAC, invitation creation/acceptance/expiry, generalized privileged step-up/approval and full portals remain unimplemented.

## Deployment and ownership model

The first release is a vendor-operated installation, not a multi-tenant SaaS control plane. One installation owns one primary VendorAccount. CustomerOrganization records are customers of that vendor and are not TLMS platform tenants.

The vendor account is identified by a non-user-editable installation key whose initial value is primary. The database unique constraint prevents duplicate primary account creation. The installation bootstrap service must only ever create or select this fixed key.

## Identity model

- User is the installation-wide human identity.
- Email is trimmed and lowercased before persistence, then uniquely indexed. Authentication and password reset flows must use the canonical stored address.
- Passwords use Laravel's hashed cast with Argon2id by default (19 MiB memory, 2 iterations, 1 lane); plaintext is never persisted or returned. An explicit bcrypt fallback is bounded to work factor ≥12 and 72 bytes.
- Users have explicit status, email verification timestamp, remember token and last-login timestamp.
- A user can hold a vendor membership and one or more customer memberships. These are separate authorization contexts.
- Membership removal is represented by status and retained records, not cascading deletion.

## Vendor and customer membership

- VendorMembership links a user to the installation's VendorAccount.
- CustomerMembership links a user to one CustomerOrganization.
- Unique constraints prevent duplicate membership pairs.
- Inviter references are nullable and become null if the inviter record is removed; membership ownership itself is restrictive.
- Customer organizations are owned by a vendor account. External references are unique within that vendor account.

## RBAC persistence

- Roles have an explicit scope: vendor or customer.
- Role slug uniqueness is scoped, so a vendor role and customer role may share a slug without sharing authority.
- Permissions carry an explicit `vendor` or `customer` scope and are unique by `(scope, name)`. Current catalogue identifiers use disjoint vendor and `customer.*` namespaces.
- Role-permission, vendor-membership-role and customer-membership-role assignments use composite primary keys to prevent duplicate grants.
- Role scope and membership status are checked by MembershipAuthorization before evaluation; a pivot relationship is not itself proof of authorization.
- No role is assigned directly to a User. Permissions are derived through the applicable membership context.
- AuthorizationCatalogSeeder idempotently seeds vendor/customer roles and stable permission identifiers.
- MembershipAuthorization re-reads the actor and resource status from the database for each decision, then requires an active membership, matching role scope, matching permission scope and explicit permission. Stale in-memory models, unknown permissions and missing memberships deny by default.
- Super Admin receives the catalogued administrative permissions but the catalogue deliberately omits integrations.credentials.read_secret, signing.root.manage and audit.delete_or_edit.
- Customer roles are separate from vendor roles; attaching a customer-scoped role to a vendor membership does not authorize vendor actions. A permission from the opposite scope is also rejected even if an invalid pivot assignment is present.
- `MembershipRoleAssignmentService` is the write boundary for vendor/customer membership role assignment/revocation. It locks actor, resource and membership rows in a transaction, revalidates scope and permission, blocks self-assignment and privileged roles, requires a reason, and writes an immutable membership-role event in the same transaction.
- `MembershipLifecycleService` is the write boundary for tenant membership status transitions. The initial implemented transition is `invited|active -> revoked`; it locks the actor, parent tenant and target membership, revalidates current scoped permission, blocks self-revocation, requires a reason, and appends an immutable lifecycle event in the same transaction. Repeated revocation is idempotent; role pivots and event history are retained.
- Vendor generic grants are allowlisted to support_agent, security_auditor and vendor_developer. Customer generic grants are allowlisted to customer_billing_admin, customer_technical_admin and customer_member. The initial Super Admin grant is available only through the one-time bootstrap; subsequent Super Admin grants and platform admin, finance admin, vendor product manager and customer owner grants require separate future approval workflows.
- Membership-role event models reject Eloquent update/delete operations. This is application-level append-only evidence, not a replacement for the future global hash-chained audit ledger or database-level tamper controls.

## Identifier and deletion rules

- All primary entity IDs are ULIDs generated by Laravel's HasUlids trait.
- ULIDs are identifiers, not bearer secrets or authorization proof.
- Foreign keys are restrictive by default. Do not cascade-delete financial, licensing, membership or audit history.
- Status fields are portable strings rather than database-specific ENUM types.
- MySQL 8.4 is the primary reference database; MariaDB 10.11 is the supported cPanel/shared-hosting compatibility engine. SQLite is used for fast feature tests; CI runs the same identity feature suite against MySQL 8.4.13 and MariaDB 10.11.19 with explicit server-vendor assertions. See ADR 0006.

## Authentication and security boundary

- Laravel Fortify login/logout, email verification, password reset, TOTP and WebAuthn passkey authentication are implemented. Public registration remains disabled.
- Super Admin portal access requires an active Super Admin membership, verified email, a current session version and either confirmed TOTP or a registered passkey. Passkey login uses the upstream WebAuthn user-verification requirement.
- Database-backed sessions are encrypted and use secure cookies. Login records the session version; protected requests compare it against fresh database state. Logout and global revocation invalidate sessions and rotate the remember token.
- Password reset and global session revocation run in one transaction. Supported bcrypt/Argon2i legacy hashes are verified with their own algorithm and upgraded to the configured stronger driver without downgrading stronger hashes.
- MFA management and recovery-code retrieval require recent password confirmation. Recovery codes are not embedded in rendered pages; the user explicitly requests reveal/regeneration through the protected Fortify endpoints.
- Fortify's recovery codes are encrypted at rest. A separate hashed recovery-verifier ledger, TOTP replay-specific controls, privileged step-up, maker-checker and resource-level ABAC remain additional hardening/acceptance work.
- A one-time browser bootstrap implementation merged in PR #20 is built behind a deployment-configured 256-bit base64url secret, HTTPS enforcement outside local/testing, CSRF protection, no-store security headers, rate limiting, a row-locked installation state and a persistent consumed flag. The token is not stored in the database or flashed back to the browser. The initial Super Admin grant is recorded in the vendor membership-role event table as a system-attributed `assigned` event in the same transaction. The owner must remove `TLMS_BOOTSTRAP_TOKEN` from the environment and clear cached Laravel configuration after successful bootstrap.
- Bootstrap creates the initial vendor account, user, active vendor membership and system Super Admin role transactionally. The initial email remains unverified and the password must be at least 15 characters; long passphrases are accepted without character-composition rules. It does not create a login session. The administrator must verify email and enroll TOTP or a passkey before accessing the Super Admin portal.
- WebAuthn passkeys and TOTP are supported authentication factors. TOTP seeds and Fortify recovery-code material are encrypted at rest; recovery codes are single-use and accessed only through password-confirmed endpoints. Explicit TOTP replay testing and a separate hashed recovery-verifier ledger remain outstanding.
- MFA settings require recent password confirmation. Privileged business actions do not yet have a complete step-up or maker-checker layer. Authorization is deny-by-default at the implemented membership boundary; resource-level ABAC remains outstanding.
- Provider secrets, licence signing roots, passwords, MFA seeds and recovery codes must never be returned in API responses or written to logs.

## Implemented tables

- vendor_accounts
- users
- password_reset_tokens
- roles
- permissions
- role_permissions
- vendor_memberships
- vendor_membership_roles
- customer_organizations
- customer_memberships
- customer_membership_roles
- vendor_membership_role_events (user- or system-attributed; append-only at the model boundary)
- customer_membership_role_events (user- or system-attributed; append-only at the model boundary)
- vendor_membership_lifecycle_events (actor-attributed; immutable lifecycle transitions)
- customer_membership_lifecycle_events (actor-attributed; immutable lifecycle transitions)
- platform_installations
- sessions (encrypted database-backed browser sessions)
- passkeys (WebAuthn credential records)

## Verification evidence

PR #23 exact head `9d742211701501e5d7a2b1b2272d6d7b1ab70d5c` passed [CI](https://github.com/LloydCoder/tinlance-license-management/actions/runs/44877579451) and [Security analysis](https://github.com/LloydCoder/tinlance-license-management/actions/runs/44877579382). CI passed all eight jobs across PHP 8.3/8.4/8.5, MySQL 8.4, MariaDB 10.11, cPanel artifact verification, documentation governance and OpenAPI validation. Security passed Larastan/PHPStan, Semgrep, Gitleaks and OSV-Scanner. The framework suite passed 82 tests; 27 framework-independent domain tests also passed. The exact merge commit and post-merge mainline results are recorded in IMPLEMENTATION-STATUS.md.

## Remaining acceptance work

1. Add resource-level ABAC for product assignment, reseller relationships, customer ownership and vendor scope; membership permission alone is not sufficient for these resources.
2. Extend fresh step-up and typed maker-checker policies to financial, licensing, release, signing-key and operational actions; maker-checker currently covers high-risk vendor role grants only.
3. Add explicit TOTP replay prevention tests and evaluate a separate hashed recovery-verifier ledger instead of relying only on encrypted Fortify recovery-code storage.
4. Implement invitation creation, email delivery, account-bound acceptance, expiry/resend and reinstatement; then add full customer/vendor portals and administrative Control Center workflows.
5. Add cross-customer IDOR tests for future reads, writes, exports and nested resources, plus concurrency tests for simultaneous role assignment, role revocation and membership lifecycle transitions.
6. Integrate global append-only/hash-chained audit anchoring; membership-role events are a local transactional audit precursor only.
