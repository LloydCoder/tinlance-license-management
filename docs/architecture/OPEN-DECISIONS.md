# TLMS architecture decision register

This register prevents unresolved design choices from being silently converted into implementation assumptions. A decision is closed only when its owner, rationale, options, consequences, ADR (where material), and acceptance evidence are recorded.

| ID | Decision | Current baseline | Closure gate / owner |
|---|---|---|---|
| DEC-001 | Initial deployment topology | One self-hosted installation per software vendor; customer organizations are records inside that installation | Accepted in ADR 0004 |
| DEC-002 | Future hosted multi-tenancy | Explicitly deferred; vendor_account_id is a migration seam, not proof of tenant isolation | Separate ADR + isolation architecture before SaaS implementation |
| DEC-003 | Exact cPanel provider and plan | Annual prepaid shared hosting is mandatory target; provider not selected | Written capability confirmation + real-account PoC / Operations |
| DEC-004 | Database engine/version | **Selected:** MySQL 8.4 LTS canonical; MariaDB 11.8 LTS optional only after independent compatibility tests | ADR 0005; provider PoC and DB matrix remain open |
| DEC-005 | PHP runtime matrix | **Selected:** Laravel 13; PHP 8.3 minimum, CI 8.3/8.4/8.5, PHP 8.5 preferred production runtime | ADR 0005; provider PoC remains open |
| DEC-006 | Identifier strategy | **Selected:** ULID identifiers; never treated as authorization secrets | ADR 0005; schema and portability tests remain open |
| DEC-007 | Licence key format and entropy | **Selected:** 256-bit CSPRNG secret, one-time display, keyed HMAC-SHA-256 verifier at rest | ADR 0005; format/vector and entropy tests remain open |
| DEC-008 | Domain binding semantics | **Selected:** IDNA ASCII normalization, lowercase, strip one trailing dot, reject URL/path/port; wildcard off by default | ADR 0005; domain property/fuzz tests remain open |
| DEC-009 | Offline lease TTL and grace | **Selected baseline:** 72-hour lease, refresh attempt around 24 hours, enterprise hard max 7 days; licence grace is separate | ADR 0005; SDK outage/clock tests and final risk review remain open |
| DEC-010 | Installation identity recovery | Stable random installation ID; clone/backup/restore recovery process open | SDK and migration threat tests |
| DEC-011 | Signing-key custody and thresholds | Offline root + delegated operational keys; exact roles, threshold and ceremony open | Signing architecture ADR and compromise drill |
| DEC-012 | Secure update metadata profile | TUF-compatible role separation; exact metadata versions, thresholds and delegation open | TUF profile ADR and conformance tests |
| DEC-013 | Initial fiat payment providers | Provider adapter pattern; Paystack/Flutterwave are candidates, not activated integrations | Commercial, technical and compliance qualification |
| DEC-014 | Crypto payments | Disabled until jurisdiction/provider/legal assessment | Qualified legal opinion, provider due diligence and release gate |
| DEC-015 | Currency, FX and tax rules | Integer minor units and immutable FX snapshots; supported currencies/tax rules open | Target-market and tax review / Finance |
| DEC-016 | Subscription and dunning policy | Explicit subscription state machine; grace/proration/retry policy open | Product/pricing policy and contract tests |
| DEC-017 | Refund/chargeback entitlement policy | Financial event remains separate from licence state; suspension/refund policy open | Finance and customer-contract approval |
| DEC-018 | Reseller attribution and commission | Versioned rules and immutable accruals; rates/windows/payout rules open | Commercial policy and reconciliation tests |
| DEC-019 | Admin MFA and bootstrap | **Selected:** WebAuthn/passkeys preferred, TOTP fallback, one-time bootstrap, single-use recovery codes | ADR 0005; recovery flow tests and exercise remain open |
| DEC-020 | API token format and rotation | **Selected:** 256-bit opaque token, public identifier + secret, keyed verifier, scopes, expiry, rotation and revocation | ADR 0005; token lifecycle tests remain open |
| DEC-021 | Email/SMS providers | Adapter model; initial providers not selected | Provider qualification and delivery testing |
| DEC-022 | Retention periods and controller/processor roles | Data classification and provisional schedule documented | Qualified legal review per jurisdiction and deployment |
| DEC-023 | Backup RPO/RTO | 15-minute RPO / 4-hour RTO remain unverified targets only | Real-provider backup/restore drill required |
| DEC-024 | Monitoring and cron cadence | **Selected baseline:** DB-backed recoverable jobs; 5-minute cPanel cron where supported; external monitoring | Real-host cron, heartbeat and catch-up test required |
| DEC-025 | Availability/SLA commitments | No public SLA committed | Measure production behavior and legal/commercial review |
| DEC-026 | API schema validation | **Selected and CI-integrated:** OpenAPI 3.1.1; Redocly CLI 1.34.24 lint runs in the `OpenAPI contract validation` workflow job | Keep the validator pinned; complete semantic authorization, compatibility and request/response contract review; add executable contract tests when application code exists |
| DEC-027 | Commercial source-visible licence | Reserved-rights policy documented | Counsel review, contributor rights and dependency audit |
| DEC-028 | Data export/deletion workflow | Scoped export and deletion/pseudonymization principles documented | Legal retention matrix and automated workflow tests |
| DEC-029 | Audit tamper evidence | Application append-only log + hash chain; periodic external digest anchoring | Design and test anchoring destination, key custody and tamper detection |
| DEC-030 | TUF production trust thresholds and key custody | Separate from DEC-012: production root/delegated-role thresholds, key custody, rotation and compromise recovery are not yet approved | Signing architecture ADR, documented key ceremony, compromise/recovery exercise and TUF conformance tests |
| DEC-031 | Human password hashing | **Selected:** Argon2id by default, minimum 19 MiB memory / 2 iterations / 1 lane; rehash-on-login enabled. Explicit bcrypt fallback is non-default, work factor ≥12 and 72-byte input ceiling. | OWASP baseline covered by feature tests; benchmark on the selected cPanel host and retain Argon2id as a provider qualification requirement |

## Rules

- Do not silently close a decision by implementing a convenient default.
- Decisions affecting trust boundaries, cryptography, money, privacy, tenancy or deployment require an ADR.
- Decisions that depend on a real provider cannot be closed by documentation or online marketing claims alone.
- If an implementation needs a provisional value, record it as a versioned configuration default and document the migration path.
- A decision register item may remain open for beta only if its impact is bounded, owner assigned, and explicitly accepted at release review.
