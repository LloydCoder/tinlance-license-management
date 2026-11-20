# Super Admin authority and Control Center requirements

| ID | Requirement | Priority | Verification |
|---|---|---|---|
| SA-001 | A dedicated Super Admin role has platform-wide administrative authority across all TLMS modules and managed tenants | Must | Authorization matrix and integration tests |
| SA-002 | A centralized Super Admin Control Center exposes the platform's administrative modules | Must | Browser end-to-end and navigation tests |
| SA-003 | Super Admin can manage administrators, roles, access policies, customers, organizations and ownership | Must | Role and lifecycle tests |
| SA-004 | Super Admin can manage products, plans, prices, entitlements and the complete licence lifecycle | Must | Product/licence workflow tests |
| SA-005 | Super Admin can manage payment configuration, invoices, refunds, reconciliation and ledger operations without bypassing ledger integrity | Must | Financial authorization and ledger tests |
| SA-006 | Super Admin can manage supported integrations and provider credentials under INT-CRED requirements | Must | Credential lifecycle and authorization tests |
| SA-007 | Super Admin can manage resellers, agencies, white-label deployments and enterprise accounts | Must | Reseller/tenant workflow tests |
| SA-008 | Super Admin can manage releases, versions, channels, package publication and update policies | Must | Release authorization tests |
| SA-009 | Super Admin can configure notifications, security policies, monitoring, backups, maintenance and recovery operations | Must | Configuration and operational tests |
| SA-010 | Every privileged endpoint, API, background action and resource mutation enforces authorization server-side | Must | Negative authorization and route/API tests |
| SA-011 | High-impact actions require MFA and recent reauthentication; sensitive actions support configurable dual approval where appropriate | Must | Step-up and maker-checker tests |
| SA-012 | Privileged actions create attributable, append-only/tamper-evident audit events with correlation and outcome metadata | Must | Audit integrity/completeness tests |
| SA-013 | Audit events and diagnostics never contain passwords, provider secrets, signing private keys or other secret material | Must | Secret-leakage tests |
| SA-014 | Non-Super-Admin users remain tenant-isolated; Super Admin cross-tenant actions are explicit and audited | Must | Cross-tenant isolation tests |
| SA-015 | Signing root keys remain outside ordinary dashboard/API credential settings | Must | Architecture and UI review |
| SA-016 | Destructive/bulk operations require confirmation, bounded scope, impact awareness and recovery handling where feasible | Must | Workflow and abuse-case tests |
| SA-017 | Emergency access and recovery are documented, constrained and audited | Must | Recovery exercise |
| SA-018 | No Super Admin capability bypasses cryptographic checks, ledger integrity, auditability or legal/provider qualification gates | Must | Security and architecture review |
