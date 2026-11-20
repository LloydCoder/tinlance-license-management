# TLMS shared-hosting operations and recovery baseline

**Status:** Phase 1 architecture baseline; provider-specific settings and recovery objectives remain unvalidated.

## 1. Deployment contract

The central TLMS application must be deployable to an annual prepaid cPanel/shared-hosting account without requiring VPS, root, Docker, Redis, persistent workers, Node.js at runtime or mandatory SSH access.

Proposed runtime:
- Laravel modular monolith.
- PHP 8.3 minimum; PHP 8.4/8.5 tested and preferred where provider and dependencies support them.
- MySQL 8.4 LTS is canonical; MariaDB 11.8 LTS is optional only after the full compatibility suite passes. Do not claim all MariaDB/MySQL variants are interchangeable.
- PHP extensions: Sodium, OpenSSL, cURL, PDO MySQL, JSON, Mbstring, tokenizer, XML, intl, Ctype, fileinfo, filter, hash, session and required Laravel extensions.
- HTTPS ingress and outbound HTTPS.
- Public document root points only to Laravel public directory; application source, environment, storage, backups and private keys remain outside webroot.

## 2. Synchronous and scheduled work

Must be synchronous/durable:
- Admin/customer authentication and authorization.
- Licence issuance transaction.
- Activation and verification request.
- Payment webhook signature verification and durable event acceptance.
- Credential writes/activation state transition.
- Financial ledger transaction.

Recoverable scheduled work:
- Renewal and expiry processing.
- Email/SMS notifications.
- Provider polling/reconciliation.
- Outbox delivery retries.
- Usage aggregation and reports.
- Backup generation/verification.

Use database-backed outbox/jobs, idempotent handlers, overlap locks and bounded batches. cPanel cron is a trigger, not a durable queue. Missed cron executions must be caught up on next run; repeated execution must not duplicate effects.

## 3. Provider qualification checklist

Obtain written provider confirmation and verify on a real test account:
- Annual billing, renewal price, refund and suspension policy.
- PHP versions and selectable CLI/web runtime.
- Required extensions and ability to configure PHP limits.
- MySQL/MariaDB version, DB size, connection and user limits.
- HTTPS inbound, outbound HTTPS and webhook delivery.
- Cron minimum interval and execution timeout.
- CPU, RAM, entry process, I/O, inode and storage quotas.
- Document-root configuration and ability to keep application files private.
- File permissions, symlink behavior and backup/restore.
- Commercial API/webhook usage policy, malware/security scanning and acceptable-use terms.
- Support response/SLA and escalation path.
- Migration/export path and account termination process.

Do not buy an annual plan until a minimal Laravel deployment, database migration, outbound HTTPS, webhook, cron, backup and restore proof-of-concept succeeds.

## 4. Backup and recovery

- Backups are encrypted and stored independently of the hosting account.
- Database and file backups are consistent and include release metadata.
- Provider credential ciphertext is backed up; the dedicated encryption key is backed up separately with restricted access.
- Signing root key backup is governed by offline ceremony and separate custody; it is never included in ordinary hosting backup.
- Restore runbook validates DB/key pairing, application version, storage, scheduled jobs, webhook replay protection and audit integrity.
- A restore must not blindly replay payment events, outbox messages or licence issuance.
- Perform periodic restore drills and record measured RPO/RTO. Initial RPO 15 minutes / RTO 4 hours are targets only if the chosen host and backup design prove them.

## 5. Monitoring and incident response

External monitoring must test public availability and selected API health without exposing sensitive system details. Alert on:
- Application unavailable or readiness degraded.
- Cron heartbeat stale.
- Outbox backlog and repeated delivery failures.
- Payment webhook failures/reconciliation drift.
- Unusual activation failures or rate-limit spikes.
- Backup missing/failed and restore-test overdue.
- Credential validation/rotation failures.
- Signing key status or release metadata verification failure.

Runbooks cover provider outage, account suspension, compromised admin, provider credential leak, database compromise, encryption-key loss, signing-key compromise, malicious release, backup restore and migration to another qualified host.

## 6. Shared-hosting limitations

A fully compromised cPanel account may expose application files, database and runtime environment variables, including the application credential-encryption key. This hosting profile is not equivalent to isolated compute, HSM or managed KMS. TLMS must state this limitation accurately and offer stronger optional deployment profiles only where supported.
