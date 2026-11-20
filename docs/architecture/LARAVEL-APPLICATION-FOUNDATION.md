# Laravel application foundation

**Status:** Implemented bootstrap and committed dependency lock; CI-verified on PHP 8.3, 8.4 and 8.5. This is an application foundation, not a production licensing service.

## Framework baseline

- Laravel Framework ^13.17; committed lock currently resolves Laravel Framework v13.43.0.
- PHP 8.3 minimum; CI exercises PHP 8.3, 8.4 and 8.5.
- composer.lock is committed; CI uses composer install, not composer update.
- Existing Tlms namespace and dependency-free domain test runner remain intact.
- Application code uses the App namespace; framework-independent licensing logic remains under Tlms.
- PHPUnit 12.5.45 and Laravel Pint 1.32.3 are locked development dependencies.

## Runtime boundaries

- HTTP entry point is public/index.php; the web document root must point to public/.
- Never move the application entry point to the project root or expose .env, vendor/, storage/, bootstrap/, or source files directly.
- Keep writable runtime directories limited to storage/ and bootstrap/cache/; deployment must provision correct ownership and permissions.
- Sessions are file-backed by default for shared-hosting compatibility, encrypted, Secure by default, HttpOnly, SameSite=Lax, and use JSON serialization. Production must preserve SESSION_SECURE_COOKIE=true; only local HTTP development may explicitly disable it.
- Cache uses the file driver and queue uses the synchronous driver at this bootstrap stage. Durable DB-backed outbox and cron processing are separate implementation work.
- No default user, organization or tenant migration is introduced before identity and authorization invariants are implemented.
- MySQL 8.4 is the canonical production database. The identity schema and feature suite are exercised against a MySQL 8.4.13 CI service with an explicit driver assertion; SQLite remains a fast secondary test driver.

## Password hashing

- New accounts use Argon2id by default with minimum parameters of 19 MiB memory, 2 iterations and 1 lane. Configuration floors prevent lowering these parameters through environment values.
- These parameters meet the minimum Argon2id configuration in the [OWASP Password Storage Cheat Sheet](https://cheatsheetseries.owasp.org/cheatsheets/Password_Storage_Cheat_Sheet.html). Production providers must benchmark the chosen work factor under realistic concurrency and memory limits before qualification.
- The explicit bcrypt fallback is not the default; it is constrained to work factor 12 or higher and a maximum 72-byte password input. It should only be selected after a documented compatibility/security decision.
- Password hashes are generated through Laravel's hashing service and stored only via the model's hashed cast. Rehash-on-login remains enabled.

## Secret and environment rules

- .env is never committed.
- APP_DEBUG=false is the production default.
- A unique production APP_KEY must be generated during installation and stored outside source control.
- Licence signing roots and provider API credentials are separate key domains; neither is stored in ordinary application configuration as plaintext.
- Configuration must be read through Laravel's config repository outside config files so config caching remains safe.

## Verification evidence

CI run [44823881333](https://github.com/LloydCoder/tinlance-license-management/actions/runs/44823881333) passed on candidate commit `1ab8a45280fda48e2bb2bc8d3fc3e0ece9c1fbf4`. It passed Composer manifest/lock validation, dependency installation, Composer audit (no advisories), PHP syntax checks, Laravel Pint, all 25 domain tests, four Laravel feature tests (8 assertions), Artisan bootstrap, documentation governance and OpenAPI validation across PHP 8.3/8.4/8.5.

The two Laravel feature tests assert the framework /up health endpoint and the non-production root response. No persistence or business API is claimed.

## Acceptance gates still open

1. Static analysis and security scanning are integrated and pass.
2. One-time Super Admin bootstrap, authentication, MFA, session revocation and recovery are implemented with deny-by-default tests.
3. Resource-level ABAC and cross-customer IDOR protections are implemented for business resources.
4. Production artifact excludes secrets, tests, development dependencies and writable runtime data.
5. Shared-hosting provider proof-of-concept validates Argon2id support, PHP extensions, outbound HTTPS, cron, webhooks, file permissions and restore procedure.

This bootstrap does not complete Phase 3.
