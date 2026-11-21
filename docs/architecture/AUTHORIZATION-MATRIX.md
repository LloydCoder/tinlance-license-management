# TLMS authorization and permission matrix

**Status:** Initial vendor/customer role catalogue, membership-level authorization service and a narrowly scoped customer-organization `view` policy are implemented and tested. Complete resource-level ABAC, mutation policies, MFA/step-up, maker-checker and full actor-principal authorization remain pending.

## 1. Authorization model

Use deny-by-default RBAC with resource attributes and explicit policy checks (RBAC + ABAC). Authentication proves identity; authorization evaluates actor, action, resource, scope, resource state and required assurance level.

Every request, background job, webhook handler and SDK operation must enforce authorization at the service/domain boundary. UI visibility is not an authorization control.

Permission identifiers are stable machine-readable strings. Roles are bundles of permissions, not hard-coded branches in business logic. Policies may impose additional constraints such as ownership, tenant/vendor scope, state, amount thresholds, MFA freshness and approval status.

## 2. Actor classes

| Actor | Scope | Notes |
|---|---|---|
| Super Admin | Entire TLMS installation / vendor account | Full platform administration, subject to cryptographic separation, audit, step-up and legal gates |
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
| platform.settings.manage | Yes | Scoped | No | No | Read-only | No | No | No | No |
| admins.manage | Yes | Scoped | No | No | Read-only | No | No | No | No |
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

**Customer roles:** “Customer roles” column is constrained by organization membership and the named billing/technical/member role. Organization Owner may administer members but cannot elevate themselves into vendor staff.


## 3A. Implemented permission scope boundary

- Permission records carry an explicit `vendor` or `customer` scope and are unique by `(scope, name)`.
- Vendor membership checks accept only vendor-scoped permissions; customer membership checks accept only customer-scoped permissions.
- Customer billing uses `customer.billing.read`; the vendor `payments.read` permission cannot be used to authorize customer operations.
- The membership authorization service reloads actor/resource status from the database to avoid trusting stale model instances.
- Cross-scope role or permission pivots fail closed at authorization time.
- `MembershipRoleAssignmentService` performs transactional, row-locked assignment/revocation, checks actor permission and target scope, blocks self-assignment and privileged roles, requires a reason, and records immutable vendor/customer membership-role events.
- Generic grants are allowlisted; Super Admin, platform admin, finance admin, vendor product manager and customer owner grants require separate bootstrap/approval workflows.
- Membership-role events are an application-level append-only precursor, not the future global hash-chained audit ledger.
- PR #29 adds `CustomerOrganizationPolicy::view`: a customer principal may read only its own organization with `customer.licences.read`; a vendor support principal may read organizations only under its active vendor with `customers.support.read`. Both branches delegate to `MembershipAuthorization`, which reloads current actor, membership and resource/vendor state from the database.
- The policy deliberately defines no `viewAny`, create, update, delete or mutation abilities. Product, reseller, licence, payment and nested-resource policies remain pending; this is not complete ABAC.

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

## 6. Step-up and approval policy

Require recent authentication + MFA for:
- credential create/replace/activate/disable;
- admin role and Super Admin account changes;
- refund, ledger adjustment and high-value manual issuance;
- mass licence revoke/suspend/transfer;
- production release publication and signing-key rotation;
- backup restore, encryption-key rotation and maintenance actions.

Maker-checker must prohibit the same human from initiating and approving a transaction where dual approval is required. The policy must define amount/risk thresholds in configuration and audit the policy version used.

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
