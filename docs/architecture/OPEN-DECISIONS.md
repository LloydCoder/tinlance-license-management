# TLMS architecture decision register

This register prevents unresolved design choices from being silently converted into implementation assumptions. A decision is closed only when its owner, rationale, options, consequences, ADR (where material), and acceptance evidence are recorded.

| ID | Decision | Current baseline | Closure gate / owner |
|---|---|---|---|
| DEC-001 | Initial deployment topology | One self-hosted installation per software vendor; customer organizations are records inside that installation | Accepted in ADR 0004 |
| DEC-002 | Future hosted multi-tenancy | Explicitly deferred; vendor_account_id is a migration seam, not proof of tenant isolation | Separate ADR + isolation architecture before SaaS implementation |
| DEC-003 | Exact cPanel provider and plan | Annual prepaid shared hosting is mandatory target; provider not selected | Written capability confirmation + real-account PoC / Operations |
| DEC-004 | Database engine/version | MySQL/MariaDB family only; exact supported engines and versions not yet frozen | Test matrix against actual provider and Laravel support |
| DEC-005 | PHP runtime matrix | PHP 8.3 minimum baseline; PHP 8.4/8.5 compatibility target | Verify Laravel/dependency support and provider runtime |
| DEC-006 | Identifier strategy | Stable opaque identifiers; ULID/UUID selection open | Schema/URL leakage review and DB portability test |
| DEC-007 | Licence key format and entropy | Random high-entropy secret; keyed verifier at rest; one-time display | Threat model, entropy analysis, lookup/index and support workflow |
| DEC-008 | Domain binding semantics | Canonical IDNA domain normalization; apex/subdomain/wildcard rules require explicit policy | Domain policy ADR and property/fuzz tests |
| DEC-009 | Offline lease TTL and grace | Bounded signed lease; exact TTL, grace and clock rollback policy open | Risk analysis balancing outage tolerance and revocation delay |
| DEC-010 | Installation identity recovery | Stable random installation ID; clone/backup/restore recovery process open | SDK and migration threat tests |
| DEC-011 | Signing-key custody and thresholds | Offline root + delegated operational keys; exact roles, threshold and ceremony open | Signing architecture ADR and compromise drill |
| DEC-012 | Secure update metadata profile | TUF-compatible role separation; exact metadata versions, thresholds and delegation open | TUF profile ADR and conformance tests |
| DEC-013 | Initial fiat payment providers | Provider adapter pattern; Paystack/Flutterwave are candidates, not activated integrations | Commercial, technical and compliance qualification |
| DEC-014 | Crypto payments | Disabled until jurisdiction/provider/legal assessment | Qualified legal opinion, provider due diligence and release gate |
| DEC-015 | Currency, FX and tax rules | Integer minor units and immutable FX snapshots; supported currencies/tax rules open | Target-market and tax review / Finance |
| DEC-016 | Subscription and dunning policy | Explicit subscription state machine; grace/proration/retry policy open | Product/pricing policy and contract tests |
| DEC-017 | Refund/chargeback entitlement policy | Financial event remains separate from licence state; suspension/refund policy open | Finance and customer-contract approval |
| DEC-018 | Reseller attribution and commission | Versioned rules and immutable accruals; rates/windows/payout rules open | Commercial policy and reconciliation tests |
| DEC-019 | Admin MFA and bootstrap | MFA and step-up required for privileged actions; exact WebAuthn/TOTP and bootstrap/recovery flows open | Authentication ADR and recovery exercise |
| DEC-020 | API token format and rotation | Opaque hashed tokens with scopes, expiry and revocation | Token lifecycle spec and leakage/revocation tests |
| DEC-021 | Email/SMS providers | Adapter model; initial providers not selected | Provider qualification and delivery testing |
| DEC-022 | Retention periods and controller/processor roles | Data classification and provisional schedule documented | Qualified legal review per jurisdiction and deployment |
| DEC-023 | Backup RPO/RTO | 15-minute RPO / 4-hour RTO are unverified targets only | Measured backup/restore drill on selected host |
| DEC-024 | Monitoring and cron cadence | DB-backed recoverable jobs + external monitoring; provider cadence unknown | Real-host cron, heartbeat and catch-up test |
| DEC-025 | Availability/SLA commitments | No public SLA committed | Measure production behavior and legal/commercial review |
| DEC-026 | API schema validation | OpenAPI 3.1.1 draft committed; CI schema lint not yet integrated | Automated validator + contract review |
| DEC-027 | Commercial source-visible licence | Reserved-rights policy documented | Counsel review, contributor rights and dependency audit |
| DEC-028 | Data export/deletion workflow | Scoped export and deletion/pseudonymization principles documented | Legal retention matrix and automated workflow tests |

## Rules

- Do not silently close a decision by implementing a convenient default.
- Decisions affecting trust boundaries, cryptography, money, privacy, tenancy or deployment require an ADR.
- Decisions that depend on a real provider cannot be closed by documentation or online marketing claims alone.
- If an implementation needs a provisional value, record it as a versioned configuration default and document the migration path.
- A decision register item may remain open for beta only if its impact is bounded, owner assigned, and explicitly accepted at release review.
