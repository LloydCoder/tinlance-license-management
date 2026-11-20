# ADR 0003: Platform-wide Super Admin authority and Control Center

- **Status:** Accepted project requirement
- **Date:** 2026-11-20
- **Decision owners:** Tinlance Limited

## Context

TLMS requires one clearly defined platform authority for operating the complete licensing business: identity, customers, products, entitlements, payments, integrations, releases, resellers, security, operations and reporting. Privileged controls must be coherent, discoverable and governed by consistent authorization and audit rules.

## Decision

Implement a dedicated **Super Admin Control Center** as the central administrative interface. The Super Admin role has platform-wide administrative authority across every TLMS module and every tenant/deployment managed by that TLMS control plane.

The Control Center must cover:

- Platform overview and global configuration.
- Administrator accounts, roles and access policies.
- Customers, organizations, memberships and ownership.
- Product catalogue, plans, pricing and entitlements.
- Licence issuance, activation, renewal, transfer, suspension, revocation and reinstatement.
- Payments, invoices, refunds, reconciliation and financial reporting.
- Integration catalogue and provider credentials.
- Resellers, agencies, white-label deployments and enterprise accounts.
- Software versions, release channels, packages and update policy.
- Email, SMS and notification configuration.
- Security, compliance, monitoring, backups, recovery and maintenance operations.
- Platform-wide audit trails, activity and operational reporting.

## Authority and safeguards

1. Super Admin authorization is enforced server-side on every privileged route, API, job and action; hiding UI controls is not authorization.
2. Super Admin actions are attributable and recorded in tamper-evident or append-only audit history. Audit records capture actor, action, target, time, request correlation, outcome and reason where applicable, while excluding secret material.
3. High-impact operations require MFA and recent reauthentication. Configurable maker-checker approval applies to sensitive financial, security and destructive actions where separation of duties is appropriate.
4. Saved provider credentials remain write-only and encrypted; no administrator can retrieve their original plaintext through the application.
5. Licence-signing root keys remain outside ordinary dashboard credential management and follow a separate offline key ceremony and recovery procedure.
6. Tenant isolation remains mandatory for all non-Super-Admin actors. Super Admin may perform cross-tenant administration, but cross-tenant access and mutations must be explicitly authorized, scoped, and audited.
7. No role, including Super Admin, may bypass cryptographic validation, financial ledger integrity, immutable event history, or required legal/provider qualification gates.
8. Emergency access and account recovery must be documented, time-bounded where feasible, and audited.
9. Destructive operations require explicit confirmation, impact preview and recovery strategy where technically possible; bulk actions require bounded scope and traceability.
10. Platform-wide authority does not imply unrestricted access to user passwords, payment-provider secrets, private signing roots or other non-retrievable cryptographic material.

## Consequences

- A centralized authorization policy and permission matrix are foundational services.
- Every module must declare its Super Admin capabilities, protected actions, step-up requirements and audit events.
- The Control Center navigation is a presentation of capabilities, not the source of authority.
- Automated tests must cover positive Super Admin access, negative non-Super-Admin access, cross-tenant isolation, step-up authentication, audit completeness and sensitive-data non-disclosure.
- This decision establishes an authority model; it does not claim that any dashboard or application functionality has been implemented.
