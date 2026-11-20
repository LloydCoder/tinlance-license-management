# TLMS privacy and data governance baseline

**Status:** Phase 1 baseline; final retention periods and legal roles require counsel and deployment-specific review.

## 1. Data principles

- Collect only data required for licensing, billing, support, security and contractual obligations.
- Define a purpose, lawful basis/contractual rationale, access owner and retention rule for every personal-data category.
- Keep vendor operator data separate from customer organization data and provider credentials.
- Do not place personal data in licence tokens, signed offline leases, URLs, analytics identifiers or public release metadata.
- Minimize SDK telemetry; make optional diagnostics transparent and configurable.
- Support export, correction, retention expiry and deletion/pseudonymization workflows subject to legal retention and dispute holds.

## 2. Data classification

| Class | Examples | Handling |
|---|---|---|
| Public | Published product metadata, release notes, public keys, public API docs | Integrity controls; no secrets |
| Internal | Product configuration, non-sensitive operational metadata | Authenticated access, least privilege |
| Confidential | Customer contacts, invoices, licence ownership, support tickets, usage metadata | Scoped access, encryption in transit/at rest, retention and export controls |
| Restricted secret | Provider API keys, webhook secrets, API tokens, encryption keys, signing private keys, MFA recovery material | Dedicated secret handling; never log/export; write-only UI; separate key custody |
| Security evidence | Audit events, login/security events, fraud/abuse signals | Restricted access, tamper evidence, bounded retention and incident/legal hold policy |

## 3. Data subject and customer workflows

- Customer organization can request a scoped export of its own account, users, licences, invoices and support records.
- A user may update profile/contact details subject to verified identity and organization policy.
- Account closure revokes sessions/tokens and disables new access.
- Deletion requests are evaluated against statutory accounting, tax, contract, security and dispute-retention obligations.
- Where deletion is not lawful or operationally safe, pseudonymize or restrict access and record the retention basis.
- Support exports are scoped, time-limited, access logged and exclude provider credentials and private signing material.
- Backups expire under a documented schedule; deletion from immutable backups occurs through expiry/rotation rather than unsafe in-place edits.

## 4. Retention schedule — values to finalize

| Data class | Proposed rule | Owner / decision needed |
|---|---|---|
| Admin/customer account profile | Account lifetime + defined closure period | Privacy/legal review |
| Authentication/security events | Risk-based rolling retention | Security owner |
| Invoices, payment and ledger evidence | Applicable tax/accounting statutory period | Finance/legal review by jurisdiction |
| Licence ownership and lifecycle | Licence term + dispute/contract evidence period | Product/legal |
| Provider webhook raw payload | Minimum needed for verification/reconciliation, then redact/minimize | Finance/security |
| Support tickets/attachments | Contractual support period + defined closure period | Support/privacy |
| SDK diagnostics | Disabled/minimized by default; short configurable retention | Product/privacy |
| Audit events | Defined risk-based retention; restricted and tamper-evident | Security/legal |
| Backups | Rotating encrypted schedule with tested expiry | Operations |

Do not hard-code retention periods until jurisdiction, customer contract and legal requirements are confirmed. Implement retention as versioned policy configuration and record each deletion/pseudonymization run.

## 5. Security and operational controls

- TLS for transport; encryption at rest for backups and sensitive database volumes where provider supports it.
- Provider secrets use dedicated authenticated encryption and external key configuration per ADR 0002.
- Signing root keys remain offline and outside application data processing.
- Role-based access, scoped exports, MFA for privileged access and access audit.
- Data breach response process with evidence preservation, impact assessment, notification decision and customer communication.
- Data processing agreement, subprocessors list, cross-border transfer assessment and privacy notice before production customer onboarding.
- No production personal data in development/test fixtures; use synthetic data.
- Redact logs and support diagnostics by default.

## 6. Legal review

The initial vendor may act as controller for its own staff/business data and as processor or independent controller for customer/licensee data depending on contract and processing purpose. This must be determined per deployment and jurisdiction, not assumed globally.

Review applicable privacy law including Nigeria Data Protection Act 2023 for Tinlance/Nigeria operations, plus the laws applicable to the vendor and its customers (including GDPR where in scope). Obtain qualified legal advice for controller/processor roles, lawful bases, data subject rights, retention, breach notification, cross-border transfer and contract clauses.

This document is an engineering baseline, not legal advice or a completed compliance certification.
