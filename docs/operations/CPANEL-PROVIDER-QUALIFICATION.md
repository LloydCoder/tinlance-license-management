# cPanel hosting provider qualification

**Research date:** 2026-11-20
**Decision:** No provider approved for production purchase. Public plan pages are candidate evidence only; a live account proof-of-concept is mandatory.

## 1. Non-negotiable deployment constraints

TLMS central control plane must run on annual-prepaid cPanel/shared hosting. The production design must not require a VPS, root access, Docker, Redis, a persistent queue worker, or an always-on Node.js process. The application must remain deployable from prebuilt CI artifacts through cPanel File Manager/FTP; SSH and Composer access are useful but not mandatory if deployment artifacts are complete.

The selected plan must prove all of the following in an actual account:

- cPanel access and a supported PHP 8.3+ selector/runtime compatible with Laravel 13 (PHP 8.3–8.5 supported range).
- Password hashing requirement: `password_algos()` must include `argon2id`; verify the selected 19 MiB / 2-pass / 1-lane parameters under realistic concurrent login load and confirm PHP memory limits. Do not silently fall back to bcrypt.

Required Laravel extensions: Ctype, cURL, DOM, Fileinfo, Filter, Hash, Mbstring, OpenSSL, PCRE, PDO, Session, Tokenizer, XML and intl; also verify PDO MySQL and Sodium for the selected cryptographic libraries.
- MySQL 8.4 LTS is the canonical target. MariaDB 11.8 LTS is acceptable only after the full application compatibility suite passes. Do not assume the provider's advertised “MySQL databases” feature identifies its engine/version.
- Database size, connection, query and CPU limits adequate for transactional licensing, ledger, webhook inbox and outbox workloads.
- cPanel Cron Jobs can invoke PHP CLI or a protected HTTP scheduler endpoint at five-minute cadence, with documented execution limits and reliable catch-up behavior.
- Outbound HTTPS to approved payment, email and update providers; inbound HTTPS webhooks; no provider firewall restriction that breaks required integrations.
- Application source, .env, logs, private material and backups can be stored outside the public document root. Only Laravel's public directory is web-exposed.
- Backup export and independent encrypted off-host backup are possible; restore access and actual recovery times can be tested.
- Resource limits (CPU, RAM, entry processes, I/O, inode count, concurrent connections, process lifetime and storage) are disclosed.
- Commercial use, software licensing platform use, outbound API calls and webhook processing are permitted by the acceptable-use policy and contract.

Laravel's official 13.x deployment requirements are documented at https://laravel.com/docs/13.x/deployment. Laravel 13's supported PHP range is 8.3–8.5: https://laravel.com/docs/13.x/releases.

## 2. Public-plan shortlist — not endorsements

| Provider / candidate | Publicly stated plan evidence | Annual price evidence | Critical unknowns |
|---|---|---:|---|
| MokoHost — Shared Premium | cPanel; 6 hosted domains; 30 GB NVMe; unlimited databases; Node.js/Python/Ruby/Git; daily offsite backups; SSL | ₦54,000/year shown in the order flow (₦4,500 monthly equivalent) | Exact PHP versions/extensions, MySQL/MariaDB version, SSH/CLI limits, cron minimum interval, outbound HTTPS policy, webhook policy, CPU/RAM/EP/inodes, source outside webroot, commercial-use terms |
| Webixhost — Mercury | cPanel on CloudLinux; PHP Selector; 2 websites + 2 addon domains; 5 GB SSD; daily backups; SSL | ₦21,600/year shown on plan page | Exact PHP versions/extensions, database engine/version, cron cadence, outbound HTTPS, webhook policy, SSH/CLI availability, resource limits and commercial-use terms |
| Hi-Tech Hosting — GW1 Growth | cPanel; PHP Selector; SSH/Git/Python/Node.js/Laravel; 10 websites; 20 MySQL databases; daily backups; stated entry-process range 90–120 | ₦63,700/year shown on plan comparison | Exact PHP selector versions/extensions, database engine/version, cron minimum interval, outbound HTTPS/webhook policy, source outside webroot, CPU/RAM/I/O/inode limits, commercial-use terms |
| KanoHost — Web Medium | cPanel; 1 CPU / 2 GB RAM / 25 entry processes; SSH/Git/Python/Node.js; daily backups | ₦18,000 per 6 months shown; annual billing not established | Annual prepaid option, PHP versions/extensions, database engine/version, cron, outbound HTTPS/webhooks, storage limits and commercial-use terms |

Sources (provider statements, accessed 2026-11-20):
- MokoHost plans: https://mokohost.com/shared-hosting and https://mportal.mokohost.com/order/web-hosting/shared-premium
- Webixhost plans: https://webixhost.com/
- Hi-Tech Hosting plans: https://hitechwebhosting.com/ng/shared-hosting/
- KanoHost plans: https://www.kanohost.com/web-hosting

Prices are advertised prices and may change, exclude taxes where stated, and may have promotional or renewal conditions. The order page and terms at purchase time control. A listed feature is not proof of a technical capability until tested.

## 3. Current engineering recommendation

Do not choose or pay for an annual plan yet. First request written answers from MokoHost, Webixhost and Hi-Tech Hosting for the unknowns above, then purchase the lowest-cost refundable/short-cycle plan that allows a real PoC (if available). KanoHost remains a secondary candidate until an annual prepaid option is confirmed.

A provider that cannot support MySQL 8.4 may be considered only if it offers MariaDB 11.8 and the complete compatibility matrix passes. Do not silently downgrade the canonical database version based on a sales response.

## 4. Qualification proof-of-concept

Run these checks against a disposable test account before production selection:

1. Record cPanel version, PHP CLI/FPM versions, extensions, PHP limits and web-server configuration.
2. Install a minimal Laravel 13 artifact without root, Docker, persistent workers or mandatory SSH.
3. Verify document-root isolation: request the public URL for .env, source, storage, logs, private keys and backup paths; all must be inaccessible.
4. Create MySQL 8.4 or MariaDB 11.8 database; run migrations, unique constraints, transactions, row-lock/concurrency tests and backup restore.
5. Test scheduler/cron at five-minute cadence over at least 24 hours; record missed runs, duration, process limits and catch-up behavior.
6. Send outbound HTTPS to controlled endpoints; receive signed test webhooks over HTTPS; test DNS failures, timeouts and provider egress restrictions.
7. Verify encrypted off-host backup, integrity checks and a timed restore; record measured RPO/RTO rather than adopting targets from documentation.
8. Exercise resource limits under concurrent activation and webhook load; confirm no silent process kills or database connection exhaustion.
9. Confirm provider terms allow TLMS as a commercial software licensing service and allow the intended customer/vendor integrations.
10. Archive sanitized evidence: provider/plan, date, versions, command outputs, screenshots, test logs, resource limits, support ticket answers and restore duration.

## 5. Acceptance decision

A provider is qualified only when every mandatory item is evidenced and the application deployment, database, scheduler, webhook, backup/restore and isolation tests pass. If no shared-hosting plan meets these requirements, revisit the architecture explicitly; do not smuggle in a VPS or persistent worker as an undocumented dependency.
