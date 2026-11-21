# ADR 0006: MariaDB 10.11 compatibility for cPanel shared hosting

- **Status:** Accepted; CI compatibility evidence passed on the PR #15 candidate head
- **Date:** 2026-11-21
- **Decision owners:** Tinlance Limited
- **Supersedes:** The MariaDB-version candidate in ADR 0005, Section 2, and DEC-004 in the architecture decision register

## 1. Context

ADR 0005 selected MySQL 8.4 LTS as the primary reference database and treated MariaDB as a separately tested future compatibility target, initially suggesting MariaDB 11.8 where available.

That target did not reflect the current cPanel shared-hosting ecosystem. cPanel documentation states that new installations on cPanel v122 and later use MariaDB 10.11 by default unless the server administrator customizes the database engine. Current cPanel support matrices list MySQL 8.4 and MariaDB 10.11 on supported AlmaLinux/CloudLinux operating systems. A MySQL-8.4-only deployment requirement would exclude ordinary cPanel installations that use the default MariaDB engine.

## 2. Decision

1. **MySQL 8.4 remains the primary reference database.**
2. **MariaDB 10.11 is an explicitly supported shared-hosting compatibility database.**
3. The Laravel PDO driver remains mysql for both MySQL and MariaDB. CI must independently verify the actual server vendor using SELECT VERSION(); a driver-name assertion alone is insufficient.
4. The supported database matrix is limited to MySQL 8.4 and MariaDB 10.11 until additional versions receive their own integration jobs and pass the same acceptance suite.
5. MySQL 8.0, MariaDB 11.4 and MariaDB 11.8 are not declared compatible by implication. Do not silently accept a different server version because a provider describes it as "MySQL-compatible".
6. A database compatibility CI pass is necessary but does not qualify a hosting provider. A real cPanel account must still pass the provider proof-of-concept and restore gates.

## 3. Verification evidence

On the PR #15 candidate head:
- MySQL 8.4.13 integration job passed 48 Laravel feature tests and 94 assertions.
- MariaDB 10.11.19 integration job passed the same 48 tests and 94 assertions.
- Both jobs ran migrations and verified the server vendor explicitly.
- PHP 8.3, 8.4 and 8.5 CI matrix passed.
- Larastan/PHPStan level 8, Semgrep, Gitleaks, OSV-Scanner, documentation governance and OpenAPI validation passed.

Final exact-head CI (`6cac99608ad08768f5e51f0bf62984fade7827c3`): https://github.com/LloydCoder/tinlance-license-management/actions/runs/44868884337

Final exact-head Security analysis: https://github.com/LloydCoder/tinlance-license-management/actions/runs/44868884325

The pinned MariaDB 10.11 image resolves to 10.11.19 in the test run. The image digest is committed in the CI workflow.

## 4. Consequences

- cPanel providers using the MariaDB 10.11 default can proceed to a live proof-of-concept instead of being excluded by a MySQL-only rule.
- Application migrations and SQL must remain compatible with both tested engines; future vendor-specific behavior requires a dedicated repository/query boundary and tests.
- Backups, restores, transaction isolation, locking, collation and performance must still be tested on the selected provider.
- MariaDB 10.11 community maintenance is scheduled to end on 2028-02-16. Before that date, evaluate MariaDB 11.4 as the forward cPanel compatibility target (community maintenance currently scheduled through 2029-05-29); add a dedicated CI matrix and provider qualification before any upgrade or support claim. MariaDB 11.8 and MySQL 8.0 likewise require separate CI jobs before being advertised as supported.
- The shared-hosting provider remains unselected. No annual plan purchase is authorized by this ADR.

## 5. Alternatives considered

- **MySQL 8.4 only:** rejected as too restrictive for cPanel v122+ shared-hosting accounts that use MariaDB 10.11 by default.
- **MariaDB 11.8 as the compatibility target:** rejected because it is not the default cPanel database version and had not been the engine exercised by TLMS integration CI.
- **Any MySQL/MariaDB version accepted by the provider:** rejected because version differences in JSON, collations, indexes, locking and SQL behavior require explicit qualification.
- **MariaDB 10.11 only:** rejected because MySQL 8.4 remains the stable reference engine and has already been validated.

## 6. Official references

- cPanel supported MySQL/MariaDB versions: https://docs.cpanel.net/knowledge-base/general-systems-administration/supported-mysql-mariadb-versions/
- cPanel default database behavior: https://docs.cpanel.net/release-notes/release-notes/
- cPanel PHP versions: https://support.cpanel.net/hc/en-us/articles/4412144152855-What-versions-of-PHP-does-cpanel-support
- Official MariaDB 10.11 image metadata/digest: https://github.com/docker-library/repo-info/blob/master/repos/mariadb/remote/10.11.md
