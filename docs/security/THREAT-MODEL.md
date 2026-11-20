# TLMS security threat model

**Status:** Phase 1 baseline threat model; must be refined against implementation, hosting provider and external penetration-test findings.

## 1. System and security objectives

TLMS issues and verifies software licences, controls entitlements, receives payment-provider events, manages provider credentials, distributes signed software updates and operates customer/vendor administration.

Security objectives:
- Only authorized actors can administer a vendor installation, customer organization, product or licence.
- Payment evidence cannot be forged, replayed or used to grant duplicate entitlements.
- Licence and update metadata cannot be forged, rolled back or silently substituted.
- Provider secrets and signing private keys remain confidential and scoped.
- Customer and vendor data remain isolated.
- Critical actions are attributable and recoverable.
- Temporary central outages do not silently grant indefinite entitlement.
- Shared-hosting limitations are documented; no false claims of HSM-grade protection or unmodifiable PHP source.

## 2. Trust boundaries

1. **Human browser → TLMS web app:** untrusted input, session theft, CSRF, XSS and authorization attacks.
2. **External SDK/customer server → TLMS API:** internet-facing machine identity, replay, spoofing, domain/install cloning and abuse.
3. **Payment/email/storage provider → webhook endpoint:** externally supplied payloads; trust only after signature/authentication and event validation.
4. **TLMS app → provider API:** outbound requests, credential exposure, SSRF and provider outage.
5. **Database/backups → runtime:** sensitive persistence and restore/key pairing.
6. **Build pipeline → release artifacts:** dependency compromise, CI credential theft, artifact substitution.
7. **Signing ceremony → operational signing service:** root/operational key separation and key compromise.
8. **Shared-hosting account → OS/runtime/document root:** provider administrators, compromised account, filesystem exposure, resource limits and missing process isolation.

## 3. Assets and security impact

| Asset | Security property | Impact if compromised |
|---|---|---|
| Offline signing root private key | Confidentiality/integrity | Arbitrary licences/releases may be forged |
| Delegated signing keys | Confidentiality/integrity | Forged credentials/metadata within delegation |
| Provider API credentials | Confidentiality | Payment capture/refund, messaging abuse, storage access |
| Admin sessions/MFA | Confidentiality/integrity | Privileged takeover |
| Licence key verifiers and signed leases | Integrity/confidentiality | Activation abuse or unauthorized access |
| Product/update packages and TUF metadata | Integrity/availability | Supply-chain compromise of customer software |
| Financial ledger/provider events | Integrity/non-repudiation | Incorrect revenue, duplicate/refunded entitlements |
| Customer data and invoices | Confidentiality/integrity | Privacy breach and fraud |
| Audit/outbox/backup | Integrity/availability/confidentiality | Forensic loss, data exposure, unrecoverable service |
| CI/CD and dependency graph | Integrity | Malicious release or secret exfiltration |

## 4. Threat register (STRIDE-oriented)

| ID | Threat / scenario | Impact | Required mitigations | Verification evidence |
|---|---|---|---|---|
| TM-001 | Broken object-level authorization / IDOR across customers, licences, invoices or products | Cross-customer data disclosure/mutation | Deny-by-default policies, scope checks in services/queries, opaque IDs not treated as authorization | Cross-org negative tests and ASVS access-control evidence |
| TM-002 | Stolen Super Admin session or MFA bypass | Full installation compromise | MFA, secure cookies, session rotation/revocation, step-up auth, login throttling, recovery controls | Auth abuse tests and incident exercise |
| TM-003 | CSRF/XSS leading to privileged mutation | Credential/payment/licence abuse | CSRF tokens, output encoding, CSP, safe rich text, SameSite cookies, re-auth for sensitive actions | Browser security tests |
| TM-004 | Provider secret reflected in API response, logs, exceptions, audit, queue or cache | Provider account compromise | Write-only UI, AEAD at rest, centralized redaction, scoped credential service, safe diagnostics | Secret canary leakage tests |
| TM-005 | Credential encryption key stored beside DB or exposed via webroot | Ciphertext decryption | Separate protected deployment secret, outside webroot, strict file permissions, key ID/rotation, separate backup access | Deployment audit and restore/key-rotation drill |
| TM-006 | Arbitrary provider URL or redirect causes SSRF to metadata/private services | Internal service compromise | Fixed provider adapters/allow-list, block private/link-local destinations, redirect restrictions, DNS/IP revalidation, egress controls where available | SSRF test suite |
| TM-007 | Forged/replayed/duplicated payment webhook | Fraudulent licence issuance or duplicate ledger postings | Provider signature verification, timestamp/replay policy, unique provider event ID, idempotent transaction, reconciliation | Forged, replayed and duplicate event tests |
| TM-008 | Payment state race or amount/currency mismatch | Incorrect entitlement or financial loss | Verify server-side provider transaction, amount/currency/order binding, atomic ledger and allocation | Race, mismatch and reconciliation tests |
| TM-009 | Licence key brute force, leakage or activation race | Unauthorized use / seat bypass | High-entropy keys, keyed verifier, rate limiting, atomic activation, anomaly alerts, one-time display | Entropy, brute-force and concurrency tests |
| TM-010 | Domain normalization bypass (IDNA, trailing dot, case, port, wildcard) | Activation limit bypass | Canonical domain parser and documented policy; reject ambiguous syntax | Property/fuzz tests |
| TM-011 | Offline lease copied or clock rolled back | Continued unauthorized access | Signed bounded lease, installation binding, monotonic version/expiry policy, bounded revocation delay and documented clock assumptions | Offline tamper/clock tests |
| TM-012 | Root or operational signing key compromise | Forged licences/updates | Offline root, delegated short-lived keys, threshold policy, key IDs, revocation/recovery, ceremony controls | Key compromise exercise |
| TM-013 | Update metadata/package rollback or substitution | Customer software compromise | TUF role separation, threshold signatures, expiry, snapshot consistency, hashes, rollback/freeze detection | TUF conformance/adversarial tests |
| TM-014 | Malicious dependency, GitHub Action or artifact | Supply-chain compromise | Pinned actions, dependency review/audit, SBOM, provenance, protected release process, least-privilege CI tokens | CI policy and artifact verification |
| TM-015 | ZIP path traversal, symlink or decompression bomb in installer/update | Arbitrary file overwrite/resource exhaustion | Reject traversal/symlinks, canonical path validation, size/count limits, staging and atomic extraction | Malicious archive tests |
| TM-016 | Shared-hosting account or control panel compromise | Runtime key/data/source compromise | Least privilege, protected files, external encrypted backups, monitoring, provider qualification, migration/recovery plan | Host compromise tabletop and restore test |
| TM-017 | Cron/queue missed or duplicated on shared hosting | Expiry/payment/notifications drift | DB-backed outbox, idempotent scheduled tasks, overlap locks, catch-up processing, health monitoring | Delayed/duplicate job tests |
| TM-018 | Audit events modified/deleted by DB administrator | Loss of forensic evidence | Append-only app interface, hash chaining/export to separate storage where feasible, restricted backups, external monitoring | Tamper detection test |
| TM-019 | Privilege escalation via role assignment or mass assignment | Unauthorized administration | Server-owned role grants, allow-listed fields, maker-checker for Super Admin changes, re-auth | Role escalation tests |
| TM-020 | Sensitive data retained or exported without controls | Privacy/legal exposure | Data minimization, purpose limitation, retention schedule, scoped export, deletion/pseudonymization workflow | Privacy workflow tests |
| TM-021 | API denial-of-service / activation brute force | Availability loss | Rate limits by IP/key/install/provider, quotas, bounded request size, backoff, external monitoring | Load and abuse tests |
| TM-022 | Exception handling leaks secrets or fails open | Secret disclosure / authorization bypass | Fail-closed config, generic external errors, correlation IDs, safe internal diagnostics | Fault injection and log scans |
| TM-023 | Webhook endpoint confused across providers/environments | Cross-provider event acceptance | Provider-specific endpoint/secret, environment binding, signature validation, event allow-list | Cross-provider negative tests |
| TM-024 | Backup restore reuses stale keys or replays outbox | Duplicate operations / inability to decrypt | Backup/key pairing, restore mode, outbox replay control, credential/signing key separation | Full restore exercise |

## 5. Shared-hosting-specific assumptions and limitations

- A shared-hosting provider administrator or full account compromise may access application files, database and runtime environment variables. Database encryption does not protect against a fully compromised runtime account that can access the decryption key.
- cPanel cron is not a durable queue worker. Critical activation and webhook processing must be synchronous through durable DB transactions; scheduled work must be recoverable and idempotent.
- External monitoring and independent backups are required because local monitoring/backups can be unavailable during account compromise or suspension.
- Do not require Redis, Docker, root access, persistent workers, Node.js at runtime or SSH for customer installation.
- Validate outbound HTTPS, webhook ingress, cron frequency, PHP extensions, resource ceilings, document root and restore behavior on the actual provider before launch.
- PHP SDK code distributed to customers can be inspected or modified by the hosting owner. Signed server-side entitlements and bounded offline leases reduce abuse but do not make customer-controlled source tamper-proof.

## 6. Security engineering baseline

Map controls and verification to:
- OWASP Top 10:2025, including broken access control, misconfiguration, supply-chain failures, cryptographic failures, injection, insecure design, authentication failures, integrity failures, logging/alerting failures and exceptional-condition handling.
- OWASP ASVS 5.0.0 as the detailed application verification baseline; target Level 2 coverage for the internet-facing application, with selected Level 3 controls for signing, payment, credential and release subsystems subject to documented risk analysis.
- OWASP API Security Top 10 current applicable edition.
- Secure SDLC: threat modeling before feature implementation, dependency pinning, SBOM, secret scanning, static analysis, dynamic testing and independent penetration testing before production.

## 7. Residual risks requiring explicit acceptance

- Shared-hosting operator/account compromise can expose runtime secrets.
- Vendor-controlled PHP source can be modified by the vendor/customer.
- Offline licence verification necessarily permits a bounded revocation delay.
- Provider outages and webhook delivery delays can delay reconciliation.
- Root signing-key compromise requires a carefully rehearsed recovery process.
- Legal treatment of crypto payment processing, tax and cross-border data processing must be reviewed before enabling relevant integrations.

No residual risk is accepted merely because it is listed here. Each must be assigned an owner, mitigation, test evidence and explicit release decision.

## 8. Standards and external references

- [OWASP Application Security Verification Standard 5.0.0](https://asvs.owasp.org/) — detailed verification requirements; target Level 2 coverage with selected higher-assurance controls.
- [OWASP Top 10:2025](https://owasp.org/Top10/) — application risk awareness and threat coverage.
- [OWASP API Security Project](https://owasp.org/www-project-api-security/) — API-specific risk coverage.
- [OWASP Cheat Sheet Series](https://cheatsheetseries.owasp.org/) — implementation guidance for authentication, authorization, secrets, logging, cryptography and SSRF.

Standards versions must be rechecked at each release gate; this baseline does not claim certification or formal ASVS compliance.
