# Security analysis and dependency governance

**Status:** Initial automated controls are implemented in GitHub Actions. Repository-level GitHub security settings and independent assurance remain separate release gates.

## Automated controls

| Control | Workflow behavior | Coverage and limitation |
|---|---|---|
| Semgrep Community Edition | `p/security-audit` runs on pushes, pull requests, weekly schedule and manual dispatch. Container image is pinned by digest. | SAST coverage depends on the Community ruleset; it is not equivalent to interprocedural commercial analysis or a penetration test. |
| Larastan/PHPStan | Larastan 3.15.0 and PHPStan 2.3.7 are exact-pinned in `composer.json` and `composer.lock`. `composer analyse` runs PHPStan level 8 with the Larastan Laravel extension across `app/`, `src/`, `database/`, `routes/`, `bootstrap/`, `config/` and `tests/`. | Static analysis is not runtime proof, penetration testing or a substitute for integration/security testing. No baseline or ignored-error file is used. |
| Gitleaks | Gitleaks Action is pinned to a full commit SHA, runs Gitleaks 8.30.1 and checks the full Git history (`fetch-depth: 0`). | A finding must be treated as a credential compromise: revoke/rotate first. Removing a string or rewriting history does not invalidate a leaked credential. |
| OSV-Scanner | OSV-Scanner 2.7.1 scans repository dependencies and the committed Composer lockfile on pushes, pull requests and the weekly schedule. | Uses OSV's public advisory database. It is independent of GitHub's dependency graph; it does not replace Composer audit or licensing review. |
| Composer audit | Existing CI runs `composer audit --locked`. | Covers known Composer advisories for the committed lockfile at scan time. |
| Dependabot | Weekly Composer and GitHub Actions update checks are configured in `.github/dependabot.yml`. | Dependabot alerts and security updates are separate repository settings and must be verified in GitHub. |

## Workflow security properties

- Third-party GitHub Actions are pinned to full commit SHAs.
- Checkout credentials are not persisted.
- Workflow permissions default to read-only; OSV-Scanner receives only `contents: read` and does not require GitHub dependency-graph or security-event write access.
- OSV-Scanner runs against the repository's resolved dependency lockfile and fails when known vulnerabilities are found.
- Gitleaks scans the complete available Git history and does not upload a SARIF artifact or post PR comments.
- Semgrep and Larastan/PHPStan level 8 fail the workflow on findings; no baseline or ignored-error suppression is configured.
- Security jobs are not treated as proof of production readiness.

## Owner-controlled GitHub settings still required

The repository API currently reports `main.protected=false` and the repository rulesets endpoint returns an empty list. The connected GitHub integration cannot create rulesets or change admin-only security settings.

An owner with repository administration access must configure and verify:

1. A `main` branch ruleset requiring pull requests and successful CI and Security analysis checks; prohibit force pushes and deletion; require conversation resolution; avoid broad bypass actors.
2. GitHub native secret scanning and push protection, where available for the account and repository.
3. Dependency graph, Dependabot alerts and Dependabot security updates.
4. Code-scanning alert policy and review ownership.
5. Least-privilege GitHub Actions workflow permissions and approval rules for external actions.

Gitleaks in CI is useful detection, but it is not a substitute for native push protection or a branch ruleset. A successful workflow does not prove those account settings are enabled.

## Release gates not yet satisfied

- Secret scanning and push protection enabled in GitHub repository settings.
- Branch ruleset and required checks enforced on `main`.
- Dependency graph and Dependabot alert/security-update settings verified.
- Full application security assessment, threat-model validation, penetration test and cPanel provider qualification.

## Research references

- Larastan 3.15.0 supports PHPStan 2.3.5+ and Illuminate 13; its package metadata lists PHP ^8.2 and Laravel 13 support. TLMS pins Larastan 3.15.0 and PHPStan 2.3.7 in the committed Composer lock and sets `config.platform.php` to `8.3.0` so resolution respects the minimum supported runtime.
- OSV-Scanner supports Composer lockfiles and can scan resolved package versions without GitHub's dependency graph.
- GitHub rulesets support required pull requests, status checks, blocked force pushes, code scanning and secret-alert rules, but creating or editing them requires repository administration rights.

See the official project sources:
- https://github.com/larastan/larastan/releases/tag/v3.15.0
- https://packagist.org/packages/larastan/larastan
- https://github.com/gitleaks/gitleaks-action
- https://github.com/google/osv-scanner-action
- https://docs.github.com/en/repositories/configuring-branches-and-merges-in-your-repository/managing-rulesets/available-rules-for-rulesets
