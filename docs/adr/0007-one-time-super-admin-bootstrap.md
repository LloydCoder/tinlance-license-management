# ADR 0007: One-time browser bootstrap for the initial Super Admin

- **Status:** Accepted implementation decision
- **Date:** 2026-11-21
- **Decision owners:** Tinlance Limited

## Context

The first TLMS installation needs a way to create its initial platform administrator without shipping a default account or requiring a public registration path. The central service is intended to run on annual prepaid shared hosting, where SSH/terminal access cannot be assumed for routine setup.

## Decision

Use a one-time browser setup route protected by a deployment-provided random bootstrap credential.

1. Generate at least 32 random bytes and encode them as unpadded base64url. Configure the resulting 43-character-or-longer token as `TLMS_BOOTSTRAP_TOKEN` in the environment file outside the public document root.
2. Expose the setup page only while the token is configured in the required format and the singleton platform installation record has not been bootstrapped.
3. Serve setup only over HTTPS. Redirect safe HTTP GET/HEAD requests to HTTPS, but reject insecure POST requests with 426 rather than redirecting and replaying a credential-bearing body. Apply HSTS to secure setup responses. Protect the POST with Laravel's web/CSRF middleware, strict validation and an IP rate limit. Never flash the bootstrap token or password back to the browser.
4. Compare the supplied token using `hash_equals`; never persist it or return it.
5. In one database transaction, lock the singleton installation row, refuse an already completed bootstrap, create the primary vendor account if absent, initialize the authorization catalogue only when empty, create the initial user and vendor membership, attach the system Super Admin role, append an immutable membership-role event attributed to `actor_type=system` with a null `actor_user_id`, and persist the consumed bootstrap state.
6. Do not automatically create an authenticated session after bootstrap. The Fortify authentication foundation (login/logout, verified email, TOTP/WebAuthn passkeys, MFA enrollment gating, password recovery and session revocation) is implemented separately in PR #23. The initial administrator must authenticate and enroll a supported factor before privileged access. Resource-level ABAC, privileged step-up, maker-checker and full portal controls remain separate acceptance work.
7. The operator must remove `TLMS_BOOTSTRAP_TOKEN` from the environment and clear the cached Laravel configuration (`php artisan config:clear`, or remove `bootstrap/cache/config.php` through cPanel File Manager) after successful setup. The persisted consumed state keeps the route closed even if the token is accidentally left configured.

## Security properties and limitations

- There is no default password, public sign-up path or token readback.
- The singleton row lock and persistent consumed flag prevent concurrent/repeated bootstrap on supported transactional databases.
- The setup route is not a replacement for login, MFA, authorization, audit or account recovery.
- The deployment environment and database must be protected. A party who obtains the bootstrap secret before setup can race the legitimate operator; keep the secret out of source control, tickets, browser history and logs.
- This flow assumes database migrations have already been applied. It does not provide a complete web-based migration installer.
- Recovery after losing the initial Super Admin account must use a separately documented, authenticated recovery procedure; do not reset the consumed flag or re-open public bootstrap.

## Consequences

- The installation can be initialized through a browser on a shared-hosting account after schema deployment.
- Deployment documentation must include local token generation and immediate token disposal.
- The remaining identity subtracks are resource-level ABAC, privileged step-up authentication, maker-checker, full customer/vendor portals, stronger recovery assurance and end-to-end audit coverage.
