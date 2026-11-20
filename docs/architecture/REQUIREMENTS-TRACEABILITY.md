# TLMS requirements traceability matrix

This matrix links approved requirements to architecture artifacts and future verification. A document reference is not implementation evidence.

| Requirement group | Architecture source | Planned verification |
|---|---|---|
| Public/commercial rights | ADR 0001, LICENSE, COMMERCIAL-LICENSING.md | Legal review, contributor/dependency rights audit |
| Super Admin authority | ADR 0003, SUPER-ADMIN-REQUIREMENTS.md, AUTHORIZATION-MATRIX.md | Positive/negative role tests, step-up, audit tests |
| Provider credentials | ADR 0002, INTEGRATION-CREDENTIALS-REQUIREMENTS.md, INTEGRATION-CREDENTIALS.md | Ciphertext, no-readback, rotation, leakage and SSRF tests |
| Deployment/tenancy | ADR 0004, DOMAIN-MODEL.md | Single-vendor install test, customer org isolation |
| Identity/access | AUTHORIZATION-MATRIX.md, THREAT-MODEL.md | MFA, session, RBAC/ABAC, IDOR and recovery tests |
| Data integrity | DOMAIN-MODEL.md | Migration, FK, transaction, concurrency and immutable-history tests |
| Licence lifecycle | DOMAIN-MODEL.md, STATE-MACHINES.md, API-CONTRACTS.md | State transition, activation race, offline lease and revocation tests |
| Payment/ledger | DOMAIN-MODEL.md, API-CONTRACTS.md, THREAT-MODEL.md | Webhook signature, replay, duplicate, amount mismatch, ledger reconciliation |
| Secure updates | DOMAIN-MODEL.md, THREAT-MODEL.md | TUF metadata, signature, expiry, rollback and artifact provenance |
| Shared hosting | ADR 0004, ADR 0005, SHARED-HOSTING-OPERATIONS.md, ROADMAP.md, THREAT-MODEL.md | Real cPanel install, cron recovery, backup/restore and outbound HTTPS |
| Privacy/retention | DOMAIN-MODEL.md, PRIVACY-AND-DATA-GOVERNANCE.md, THREAT-MODEL.md | Data inventory, export, deletion/pseudonymization, retention tests |
| API compatibility | ADR 0005, API-CONTRACTS.md, docs/api/openapi.yaml | OpenAPI lint, schema compatibility and SDK contract tests |
| Supply chain | ADR 0005, THREAT-MODEL.md, DEFINITION-OF-DONE.md | Pinned Actions, dependency audit, SBOM, provenance and release verification |

## Phase 1 exit criteria

- [x] Initial deployment and tenancy model documented in ADR 0004.
- [x] Conceptual domain model and ownership boundaries documented.
- [x] Authorization matrix baseline covers actor classes, scopes and privileged actions.
- [x] Threat model baseline covers trust boundaries, assets, STRIDE threats, shared-hosting risks and mitigations.
- [x] API conventions and OpenAPI v1 draft documented; Redocly CLI 1.34.24 lint is enforced in CI; semantic authorization, compatibility and executable contract review remain pending.
- [x] Initial state machines for licence, payment, credential, release and subscription lifecycles documented; detailed transitions and tests remain implementation gates.
- [x] Privacy/data classification and provisional retention schedule drafted; legal periods and roles remain unresolved.
- [x] Shared-hosting provider qualification and recovery baseline documented; real-provider proof-of-concept remains pending.
- [x] ADRs and unresolved decisions recorded; DEC-026 reflects CI validator integration and DEC-030 separates TUF trust-threshold decisions from the metadata profile.
- [ ] Every API operation mapped to actor, permission, scope, step-up, audit and negative tests (see `API-SECURITY-REVIEW.md`).
- [ ] Requirements trace to executable tests and release evidence; architecture documents alone are not implementation evidence.
