# TLMS authorization and permission matrix

**Status:** Initial vendor/customer role catalogue, membership-level authorization service, customer-organization/vendor-account/vendor-membership/customer-membership `view` policies, scoped vendor/customer membership role assignment/revocation APIs, fresh TOTP/passkey step-up for those role mutations, and maker-checker for high-risk vendor role grants are implemented and tested. Self-only membership reads and persisted tenant-binding checks are covered. Tenant-admin membership revocation is implemented with fresh step-up and immutable lifecycle evidence; customer membership revocation locks and revalidates the persisted parent vendor row to serialize against concurrent suspension (PR #58). Invitation creation/delivery/acceptance/expiry/resend and the tenant-scoped management workspace are implemented. The least-privilege vendor_admin role enables that workspace for ordinary vendor tenants; first-admin bootstrap is Super Admin-only and subsequent grants require maker-checker. Audited membership reinstatement and workspace controls are implemented. PR #77 adds explicit platform-bootstrap Super Admin read authority across active vendor tenants and customer organizations, with application-level access audit events, Eloquent instance guards and MySQL/MariaDB BEFORE UPDATE and BEFORE DELETE triggers. PR #80 adds a serialized SHA-256 hash chain and operator verification for that platform-access stream. PR #82 records denied cross-tenant platform-read attempts as hash-chained events with an explicit denial reason. This remains a narrow platform-read audit stream, not a complete global audit ledger. This remains a narrow platform-read audit stream, not a complete global audit ledger. Complete resource-level ABAC for all business resources, non-membership mutation policies, generalized step-up/approval and full actor-principal authorization remain pending.

## 1. Authorization model

Use deny-by-default RBAC with resource attributes and explicit policy checks (RBAC + ABAC). Authentication proves identity; authorization evaluates actor, action, resource, scope, resource state and required assurance level.

Every request, background job, webhook handler and SDK operation must enforce authorization at the service/domain boundary. UI visibility is not an authorization control.

Permission identifiers are stable machine-readable strings. Roles are bundles of permissions, not hard-coded branches in business logic. Policies may impose additional constraints such as ownership, tenant/vendor scope, state, amount thresholds, MFA freshness and approval status.

## 2. Actor classes

| Actor | Scope | Notes |
|---|---|---|
| Super Admin | Entire TLMS installation / vendor account | Full platform administration, subject to cryptographic separation, audit, step-up and legal gates |
| Vendor Administrator | One vendor account | Manage vendor memberships and invitations; can request privileged role grants but cannot approve them or administer platform settings, finance, products, releases or signing |
| Platform Admin | Explicitly assigned platform modules | No automatic access to secrets, signing roots or financial approvals |
| Finance Admin | Payments, invoices, ledger and reconciliation | No provider secret readback; refunds/adjustments may require maker-checker |
| Support Agent | Customer support and limited licence diagnostics | No credential, payment mutation or private key access |
| Security Auditor | Read-only security/audit evidence | No mutation; sensitive values remain redacted |
| Vendor Product Manager | Assigned products, plans and releases | Cannot administer identity, finance or global integrations unless separately granted |
| Vendor Developer | Assigned products, SDK credentials and non-production release workflows | No production signing authority |
| Reseller Manager | Assigned reseller relationships and commission reports | No access to unrelated resellers or global configuration |
| Customer Organization Owner | One customer organization | Manage organization membership and permitted billing/technical settings |
| Customer Billing Admin | One customer organization | Orders, invoices, payment methods and billing contacts |
| Customer Technical Admin | One customer organization | Licence activations, installation records and technical contacts |
| Customer Member | Own organization, limited read | No organization-wide administration |
| SDK Installation Principal | One installation/license/product scope | Narrow machine identity for activation, verification and entitlement operations |
| Payment Provider Webhook Principal | One provider integration | May submit signed provider events; cannot choose arbitrary tenant or grant entitlements |
| Scheduled System Principal | Explicit job capability | Non-interactive, least privilege, auditable and idempotent |

## 3. Permission catalogue

| Permission | Super Admin | Platform Admin | Finance | Support | Auditor | Product Manager | Developer | Reseller Manager | Customer roles |
|---|---:|---:|---:|---:|---:|---:|---:|---:|---:|
| vendor.account.read | Yes | Yes | Yes | Yes | Yes | Yes | Yes | Yes | No |
| platform.settings.manage | Yes | Scoped | No | No | Read-only | No | No | No | No |
| admins.manage | Yes | No | No | No | Read-only | No | No | No | No |
| customers.manage | Yes | Scoped | No | Scoped support | Read-only | Assigned products only | No | Assigned accounts | Own org roles |
| products.manage | Yes | Scoped | No | Read-only | Read-only | Assigned products | Scoped | No | No |
| plans.entitlements.manage | Yes | Scoped | No | Read-only | Read-only | Assigned products | No | No | No |
| licences.issue | Yes | Scoped | Approved/manual workflow only | No | No | Scoped workflow | No | No | No |
| licences.read | Yes | Scoped | Scoped | Scoped diagnostics | Read-only | Assigned products | Assigned products | Assigned referrals | Own org |
| licences.mutate | Yes | Scoped | No | No | No | Scoped | No | No | Technical admin: own org limited actions |
| payments.read | Yes | Scoped | Yes | Redacted status | Read-only | No | No | Commission scope | No — vendor scope only |
| customer.billing.read | No | No | No | No | No | No | No | No | Customer Owner / Billing Admin: own organization |
| customer.members.manage | No | No | No | No | No | No | No | No | Customer Owner: own organization |
| customer.licences.read | No | No | No | No | No | No | No | No | Customer Owner / Technical Admin / Member: own organization |
| customer.activations.manage | No | No | No | No | No | No | No | No | Customer Owner / Technical Admin: own organization |
| payments.refund | Yes + step-up/approval | No by default | Maker-checker | No | Read-only | No | No | No | No |
| ledger.adjust | Yes + step-up/approval | No | Maker-checker | No | Read-only | No | No | No | No |
| integrations.credentials.write | Yes + MFA/reauth | Explicit delegated credential admin only if separately approved | No | No | Metadata only | No | No | No | No |
| integrations.credentials.read_secret | Never through UI/API | Never | Never | Never | Never | Never | Never | Never | Never |
| releases.publish | Yes + step-up | Scoped | No | No | Read-only | Scoped | Non-production only | No | No |
| signing.root.manage | Offline ceremony only; not dashboard permission | No | No | No | Ceremony evidence only | No | No | No | No |
| signing.operational.rotate | Dedicated signing operator workflow + approval | No | No | No | Read-only evidence | No | No | No | No |
| resellers.manage | Yes | Scoped | Commission read | Support scope | Read-only | No | No | Assigned relationships | No |
| audit.read | Yes | Scoped | Finance events | Support events | Yes | Product events | Own technical events | Own commission events | Own org permitted events |
| audit.delete_or_edit | No application capability | No | No | No | No | No | No | No | No |
| backups.restore | Yes + step-up + recorded change | No by default | No | No | Read-only evidence | No | No | No | No |
| system.maintenance.manage | Yes + step-up | Explicit ops scope | No | No | Read-only | No | No | No | No |

**Vendor Administrator:** a tenant-scoped role with only vendor.account.read, admins.manage and audit.read. It has no approvals.review permission and cannot manage platform settings, customer resources, payments, products, releases or signing. The first active vendor administrator may be bootstrapped by a Super Admin only; later grants require an independent maker-checker approval. The role is not directly invitable.

**Customer roles:** “Customer roles” column is constrained by organization membership and the named billing/technical/member role. Organization Owner may administer members but cannot elevate themselves into vendor staff.


## 3A. Implemented permission scope boundary

- Permission records carry an explicit `vendor` or `customer` scope and are unique by `(scope, name)`.
- Vendor membership checks accept only vendor-scoped permissions; customer membership checks accept only customer-scoped permissions.
- Customer billing uses `customer.billing.read`; the vendor `payments.read` permission cannot be used to authorize customer operations.
- The membership authorization service reloads actor/resource status from the database to avoid trusting stale model instances.
- Cross-scope role or permission pivots fail closed at authorization time.
- `MembershipRoleAssignmentService` performs transactional, row-locked assignment/revocation, checks actor permission and target scope, blocks self-assignment and privileged roles, requires a reason, and records immutable vendor/customer membership-role events.
- Generic grants are allowlisted; Super Admin, platform admin, finance admin, vendor product manager, reseller manager and customer owner grants require separate bootstrap/approval workflows. vendor_admin has a one-time Super Admin bootstrap path; all subsequent grants require maker-checker.
- Membership-role events are an application-level append-only precursor, not the future global hash-chained audit ledger.
- PR #29 adds `CustomerOrganizationPolicy::view`: a customer principal may read only its own organization with `customer.licences.read`; a vendor support principal may read organizations only under its active vendor with `customers.support.read`. Both branches delegate to `MembershipAuthorization`, which reloads current actor, membership and resource/vendor state from the database.
- PR #38 adds `VendorAccountPolicy::view`, backed by the dedicated vendor-scoped `vendor.account.read` permission. The permission is granted to the defined vendor staff roles, never customer roles; authorization reloads current user, vendor and membership state. The policy exposes no collection listing or mutation abilities.
- PR #40 adds `VendorMembershipPolicy::view` and `CustomerMembershipPolicy::view`. Each policy reloads the target membership and parent resource, permits active/invited targets while rejecting revoked/suspended/deleted targets, and requires `admins.manage` or `customer.members.manage` in the matching vendor/customer scope. The existing authorization service reloads actor and role/permission state; cross-vendor and cross-organization access is denied.
- PR #42 adds four vendor/customer membership role assignment and revocation endpoints. Parent IDs are bound to the target membership, the existing transactional service enforces allowlisted role changes and immutable reasoned audit events, and the endpoints require a verified current session, recent password confirmation, MFA enrollment and the matching tenant-scoped management permission. RFC 9457 errors and OpenAPI contracts are included.
- PR #45 adds fresh TOTP/passkey step-up for those four membership role mutation endpoints. TOTP uses Fortify's single-use cache-backed provider; passkey confirmation uses the existing WebAuthn assertion event and is bound to the authenticated session. A five-minute server-side marker is required and stale markers are cleared. Step-up successes/failures are audited without storing factor secrets.
- Fresh step-up is not yet applied to refunds, ledger adjustments, release publication, signing-key rotation, backup restore, ownership transfer or other privileged resources; those integrations and maker-checker remain pending.
- These resource policies define no `viewAny`, create, update or delete abilities for the membership resource itself. Product, reseller, licence, payment and other business-resource policies remain pending; this is not complete ABAC.

## 4. Super Admin authority

Super Admin has platform-wide administrative authority across every module in the installation: configuration, administrators, customers, products, plans, licences, payments, integrations, resellers, white-label, releases, notifications, security, operations, backups, reports and audit.

This authority does not grant:
- plaintext retrieval of stored provider credentials;
- access to customer passwords or authentication factors;
- dashboard access to offline signing root private keys;
- ability to bypass signature verification, ledger invariants, legal gates or append-only audit;
- silent or unattributed privileged actions.

Destructive or high-impact actions require explicit confirmation, recent authentication and MFA. Financial refunds, ledger adjustments, bulk revocation and production signing-key changes require separation of duties or an independently recorded approval when configured by policy.

## 5. Scope enforcement

- Initial deployment: all vendor-owned resources belong to the single configured `vendor_account_id`.
- Customer-owned resources additionally require `customer_organization_id` membership checks.
- Product resources require product assignment/ownership checks for scoped staff.
- Reseller resources require explicit reseller relationship checks.
- SDK principals are restricted by product, licence, installation and operation.
- Webhook principals are restricted to one configured provider, verified signature and event type.
- Background jobs carry an explicit actor/system principal, tenant/vendor scope and correlation ID; never infer authority from a serialized user-supplied ID.

## 5A. Platform Super Admin cross-tenant read boundary

PR #77 implements a narrowly scoped platform-wide read exception for the installation bootstrap Super Admin:

- The actor must be the persisted `platform_installations.bootstrapped_user_id`, the installation must be bootstrapped, the user must remain active, and the actor must retain an active membership and the `super_admin` role on the active primary vendor account.
- The exception applies only to viewing active vendor accounts and active customer organizations belonging to other vendor accounts. The target tenant vendor must be reloaded from the database and remain active; customer organization state and its persisted vendor binding are reloaded before authorization.
- Suspended/deactivated users, revoked Super Admin membership/role, suspended primary vendor, suspended target vendor and suspended customer organization fail closed.
- Every cross-tenant platform Super Admin read decision is recorded in `platform_access_audit_events`, including allowed and denied decisions, actor, action, resource, tenant, outcome, reason code and validated/generated request correlation ID. Eloquent model-instance updates/deletes are rejected, and MySQL 8.4/MariaDB 10.11 BEFORE UPDATE/DELETE triggers reject query-builder and direct row mutations. SQLite relies on model guards. A privileged database administrator can still alter/drop triggers or rewrite storage; a hash chain and external anchor remain necessary for tamper evidence.
- A vendor-scoped `super_admin` role outside the original installation bootstrap binding does not receive platform-wide authority.
- This exception does not grant collection listing, create, update or delete abilities. It does not replace tenant-specific policies for normal vendor/customer actors.

## 5B. Explicitly pending authorization scope

The current schema contains vendor accounts, customer organizations, memberships, invitations, approval requests and platform installation records. PR #84 adds tenant-scoped read-only active membership directory queries for the two existing tenant aggregates. Product, plan, licence, activation, payment, reseller and release business-resource models/policies are not yet implemented. Their operation-level ABAC cannot be marked complete until those resources and service boundaries exist. SDK installation, webhook-provider and scheduled-system principals remain design requirements, not implemented actor types.

## 6. Step-up and approval policy

PR #45 membership role mutations require a current verified session, recent password confirmation and a fresh TOTP or passkey assertion within five minutes. TOTP codes are single-use through Fortify's cache-backed provider; passkey assertions use the existing WebAuthn verifier. A session-bound marker is granted only after successful verification and step-up audit persistence. Recovery codes are not accepted.

PR #47 implements a narrowly scoped maker-checker workflow for high-risk vendor role grants only: `finance_admin`, `vendor_product_manager` and `reseller_manager`. Both request and decision operations require fresh step-up. The maker and checker must be distinct active users in the same vendor scope; the checker requires `admins.manage` and `approvals.review`; the role beneficiary cannot approve or reject their own grant. Requests are bound to a vendor, target membership, role and action; use an idempotency key and payload hash; and expire. An immutable approval-decision record must match the persisted approved request and checker before the role-assignment service can grant the role. Approval state, decision evidence and role mutation are atomic. Direct assignment of these high-risk roles is denied; revocation remains immediate.

This is not a generalized approval engine. Fresh step-up and typed approval policies remain to be integrated for:
- credential create/replace/activate/disable;
- admin role and Super Admin account changes outside the implemented vendor-role workflow;
- refund, ledger adjustment and high-value manual issuance;
- mass licence revoke/suspend/transfer;
- production release publication and signing-key rotation;
- backup restore, encryption-key rotation and maintenance actions.

Any future dual-control workflow must prohibit both maker/checker identity conflicts and beneficiary self-review, define amount/risk thresholds in configuration, bind the exact typed action payload, and audit the policy version used.

## 7. Authorization test requirements

- Every endpoint has positive and negative permission tests.
- Cross-customer IDOR tests cover reads, updates, deletes, exports and nested resources.
- Customer A cannot access Customer B by changing IDs, filters, pagination cursors or object references.
- A scoped staff member cannot access an unassigned product/customer.
- SDK principal cannot access dashboard endpoints or another licence/installation.
- Forged webhook cannot create payment or entitlement state.
- Revoked/suspended actors and tokens lose access immediately according to documented session/token revocation semantics.
- Background jobs reject stale, malformed or out-of-scope actor context.
- Super Admin actions remain audited and sensitive data stays redacted.


## 3B. Additional Phase 4 hardening (PRs #49–#52)

- Super Admin session listing and global session revocation require Laravel recent password confirmation. Revocation remains self-scoped and invalidates all of the actor's sessions.
- The self-service `/security/mfa` enrollment page is available to any authenticated, verified active user, not only Super Admins. Secure transport, session-version enforcement and recent password confirmation remain mandatory; the page exposes only the current user's own factors and passkeys (PR #60).
- Platform Super Admin cross-tenant reads require the original installation bootstrap binding plus active primary-vendor Super Admin membership; target resource and tenant state are reloaded, and allowed/denied decisions are recorded in application-level access audit events with Eloquent instance guards plus MySQL/MariaDB BEFORE UPDATE and BEFORE DELETE triggers (PR #77). PR #80 chains those events with SHA-256 and exposes `tlms:audit:verify`; PR #82 ensures denied non-bootstrap attempts that reach the platform fallback are recorded without granting access. The stream is unkeyed and not externally anchored.
- Session-version middleware reloads persisted account status on every protected request and fails closed for missing or non-active users, so account suspension invalidates existing web/API sessions.
- Active users may view their own active/invited vendor or customer membership without administrator permissions. Viewing another member remains subject to the relevant tenant-scoped administrator permission; no `viewAny`, update or delete ability is granted.
- Customer organization reads reload the persisted organization and parent vendor before evaluating customer-member, vendor-support or platform-bootstrap Super Admin access. In-memory relationship state is not an authorization source; suspended organizations and stale/tampered tenant foreign keys are denied.


## 3C. Membership lifecycle revocation (PR #55)

- `MembershipLifecycleService` is the write boundary for vendor/customer membership status changes; the initial implemented transition is `invited|active -> revoked`.
- Vendor revocation requires an active same-vendor actor with `admins.manage`; customer revocation requires an active same-organization actor with `customer.members.manage`.
- Both operations require a verified current session, recent password confirmation, enrolled MFA, fresh five-minute TOTP/passkey step-up, a reason and throttling. Cross-tenant parent/target mismatches return 404; self-revocation is denied.
- Status transition and immutable lifecycle evidence commit in one transaction. Repeated revocation is idempotent and creates no duplicate event. Role pivots are retained; a revoked membership cannot authorize tenant operations.
- Revocation is tenant-scoped and does not invalidate all of the user's sessions, because the same user may hold other tenant memberships. Each protected operation must re-evaluate the relevant persisted membership.
- PR #58 additionally locks the persisted parent vendor row during customer membership revocation. The active vendor check is now serialized against concurrent vendor suspension; the customer organization row is also locked before the parent vendor row.
- PR #70 adds a least-privilege vendor_admin role (vendor.account.read, admins.manage, audit.read only). The first active vendor administrator is bootstrapped by a Super Admin under the existing fresh password/MFA-gated role mutation route; the operation is serialized under the vendor lock and records an immutable role event. Subsequent vendor-admin grants use the existing maker-checker workflow; vendor admins can request but cannot approve grants. The role is excluded from direct invitation/assignment allowlists.
- PRs #66–#68 implement a tenant-scoped pending-invitation workspace. The query reloads manager permissions, scopes results to active parent tenants, paginates independently per tenant, includes expired/failed-delivery records for recovery, excludes accepted/revoked records and never selects token material. The controller revalidates vendor/customer manager permissions before passing groups to the workspace view. The UI calls the existing invitation/lifecycle APIs for create/resend/revoke; role options use the invitation-service allowlists, and maker-checker-only roles remain excluded. Mutations require recent password confirmation and fresh five-minute TOTP/passkey step-up; same-origin POST/CSRF controls are UX safeguards only, while server authorization remains authoritative. Raw invitation tokens and token hashes are not rendered. PRs #74–#75 add revoked-member review and reinstatement controls; reinstatement creates a fresh invitation, clears prior role pivots, invalidates old tokens and restores access only after acceptance. PR #84 adds manager-only active membership directory queries and tables, tenant-scoped, active-user filtered, role-scope filtered and independently paginated. The directory is read-only; active-member role/revocation UI remains pending.
