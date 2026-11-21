# Laravel application foundation

**Status:** Implemented bootstrap and committed dependency lock; CI-verified on PHP 8.3, 8.4 and 8.5. This is an application foundation, not a production licensing service.

## Framework baseline

- Laravel Framework ^13.17; committed lock currently resolves Laravel Framework v13.43.0.
- PHP 8.3 minimum; CI exercises PHP 8.3, 8.4 and 8.5.
- composer.lock is committed; CI uses composer install, not composer update.
- Composer dependency resolution is pinned to PHP 8.3.0 via `config.platform.php`, the minimum supported runtime, to prevent dev-tool transitive dependencies from silently raising the PHP floor.
- Larastan 3.15.0 and PHPStan 2.3.7 are locked development dependencies; `composer analyse` runs at level 8 across the Laravel application, domain, database, routes, bootstrap, config and tests, without a baseline.
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
- MySQL 8.4 is the primary reference database and MariaDB 10.11 is the supported cPanel/shared-hosting compatibility database. CI runs the identity migrations and feature suite against both engines, with explicit server-vendor assertions; SQLite remains a fast secondary test driver. Other database versions are not declared compatible until separately qualified.

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

CI run [44826085608](https://github.com/LloydCoder/tinlance-license-management/actions/runs/44826085608) passed on exact head `85010d60d49a1df7f1c1b8916f10fb674ea424e6`. It passed Composer manifest/lock validation, locked dependency installation, Composer audit (no advisories), PHP syntax checks, Laravel Pint, 25 domain tests, 47 Laravel feature tests (91 assertions), Artisan bootstrap, documentation governance and OpenAPI validation across PHP 8.3/8.4/8.5, plus the same feature suite against MySQL 8.4.13 and MariaDB 10.11.

The bootstrap is still not a complete licensing service; identity/business APIs, authentication and production deployment remain separate implementation work.

## Acceptance gates still open

1. Static analysis and security scanning are integrated and pass; Larastan/PHPStan is configured at level 8 with no baseline.
2. One-time Super Admin bootstrap, authentication, MFA, session revocation and recovery are implemented with deny-by-default tests.
3. Resource-level ABAC and cross-customer IDOR protections are implemented for business resources.
4. Production artifact excludes secrets, tests, development dependencies and writable runtime data.
5. Shared-hosting provider proof-of-concept validates Argon2id support, PHP extensions, outbound HTTPS, cron, webhooks, file permissions and restore procedure.

This bootstrap does not complete Phase 3.
