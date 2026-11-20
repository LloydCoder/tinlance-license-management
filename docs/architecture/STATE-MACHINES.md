# TLMS lifecycle state machines

**Status:** Phase 1 baseline; transitions require domain/service tests before implementation.

## 1. General transition rules

- Every transition names the initiating actor, authorization policy, preconditions, transaction boundary, emitted domain event, side effects and idempotency key.
- State is changed only by domain services; controllers, webhooks and jobs cannot directly mass-assign status.
- Use optimistic versioning and database transactions for concurrent mutations.
- Invalid transitions return a stable conflict/problem code and do not partially mutate related records.
- Audit/domain events are immutable; corrections are new events.
- External side effects use a transactional outbox and idempotent consumers.
- Timestamps are UTC; policy timezone is stored where business-day rules apply.

## 2. Licence lifecycle

| From | To | Initiator | Preconditions / controls |
|---|---|---|---|
| draft | pending_payment | System/order service | Order line and immutable plan snapshot exist |
| draft | active | Super Admin / grant service | Free/NFR/manual grant reason and required approval recorded |
| pending_payment | active | Payment processing | Verified payment allocation, matching amount/currency/order and idempotency |
| pending_payment | cancelled | Customer/vendor admin | No settled payment; cancellation policy |
| active | grace | System | Term ended but configured grace period remains |
| grace | active | Renewal processing | Verified renewal/payment or approved extension |
| active/grace | expired | System | Term/grace ended |
| active/grace | suspended | Authorized vendor admin/system policy | Reason, notice policy, audit and effective time |
| suspended | active | Authorized vendor admin | Suspension reason resolved and reinstatement approved |
| active/grace/suspended | revoked | Super Admin / authorized admin | Reason, step-up, optional dual approval; irreversible unless explicit reinstatement policy |
| active | transferred | Transfer workflow | Ownership verification, destination org acceptance and transfer event |
| active | replaced | Replacement workflow | Replacement licence linked; old licence disposition explicit |

Revoked and expired are not interchangeable. A perpetual licence has no term expiry for the core grant, but maintenance, updates and hosted services may have independent expiry states.

## 3. Payment attempt lifecycle

| From | To | Initiator | Preconditions / controls |
|---|---|---|---|
| created | pending | Payment adapter | Payment intent and amount/currency snapshot persisted |
| pending | succeeded | Verified provider event/reconciliation | Signature, event identity, provider transaction, order, amount and currency validated |
| pending | failed | Provider event/reconciliation | Authenticated provider evidence or verified status lookup |
| pending | cancelled | Customer/system | Provider cancellation confirmed or safe timeout policy |
| pending | expired | Scheduled processor | Provider-specific expiry and reconciliation policy |
| succeeded | partially_refunded/refunded | Refund workflow | Provider refund confirmed; append compensating ledger postings |
| succeeded | disputed/chargeback | Provider event | Authenticated dispute event; entitlement policy applied separately |

Never mutate a succeeded attempt back to pending/failed. Refunds, chargebacks and reversals are linked financial events.

## 4. Integration credential version lifecycle

| From | To | Initiator | Preconditions / controls |
|---|---|---|---|
| absent | draft | Super Admin | MFA, recent re-authentication, validated field schema |
| draft | validated | Provider adapter | Safe test connection succeeds; metadata-only result |
| draft | invalid | Provider adapter | Validation failure; safe normalized reason |
| validated | active | Super Admin | Explicit activation, step-up, audit; prior active credential retained until commit |
| active | disabled | Super Admin | Step-up; provider calls stop using version |
| active | rotation_pending | Super Admin | New version staged; old active remains operational |
| rotation_pending | active(new) | Super Admin | New version validated and activated atomically |
| any non-root version | revoked | Super Admin/system incident process | Incident reason and audit; revoke provider-side where possible |

The application never returns saved plaintext. Encryption-key rotation is a separate cryptographic migration, not a provider credential lifecycle transition.

## 5. Release lifecycle

| From | To | Initiator | Preconditions / controls |
|---|---|---|---|
| draft | validated | CI/release service | Build provenance, SBOM, tests and artifact digest exist |
| validated | staged | Release manager | Channel, compatibility and rollout policy approved |
| staged | published | Authorized release publisher | TUF metadata signatures, threshold policy and expiry checks pass |
| published | deprecated | Release manager | Replacement path and support window documented |
| published/deprecated | withdrawn | Incident process | Reason, customer impact and signed metadata update |
| withdrawn | superseded | Release manager | Replacement release is published; do not rewrite historic artifact |

A published artifact is immutable. Rollback is a new signed metadata version that references an approved prior artifact, not a timestamp/version rollback.

## 6. Subscription lifecycle

Initial states: trialing, active, past_due, paused, cancelled, expired. Define grace, dunning, entitlement suspension, proration, cancellation timing and renewal rules per versioned plan policy. Subscription state and licence state remain separate aggregates; one subscription can grant multiple licences, and a licence can have perpetual terms independent of subscription.

## 7. Required transition test matrix

- Every allowed transition succeeds with required actor and preconditions.
- Every disallowed transition fails without partial writes.
- Concurrent transition attempts produce one authoritative outcome.
- Duplicate webhook/job/request does not duplicate state changes or ledger entries.
- Audit/outbox events commit atomically with domain state.
- Step-up and maker-checker rules are enforced on privileged transitions.
- Clock, timezone, retry and outage conditions are deterministic under tests.


## 8. Implementation contract still required

The transition tables above are architectural baselines, not a complete executable specification. Before implementing each aggregate, add a transition contract containing:

- Stable transition identifier and aggregate version.
- Permitted source state(s) and target state.
- Initiating actor class and exact permission identifier.
- Resource/vendor/customer scope checks.
- Required MFA freshness, step-up authentication and maker-checker approval.
- Preconditions, including payment evidence, plan snapshot, ownership proof or release signature as applicable.
- Transaction boundary, uniqueness constraints and optimistic-lock behavior.
- Idempotency-key scope, retention and same-key/different-payload behavior.
- Immutable audit event and transactional outbox event.
- External side effects, retry policy and dead-letter/recovery behavior.
- Stable success, conflict, forbidden and validation response codes.
- Positive, negative, concurrency, duplicate-delivery, clock and rollback tests.

### Required aggregate-specific clarifications

- **Licence:** define transfer acceptance, replacement linkage, reinstatement after revocation, perpetual grant versus maintenance expiry, and the precise effect of chargeback/refund policy.
- **Payment:** distinguish provider event receipt from verified settlement; define partial refund aggregation, dispute reversal and reconciliation authority. A webhook must never directly grant an entitlement.
- **Credential:** define draft expiry, validation evidence freshness, concurrent rotation, active-version rollback, disable versus revoke semantics and provider-side revocation evidence.
- **Release:** define metadata version monotonicity, expiry, threshold verification, delegated target scope, freeze/rollback rejection and emergency root recovery.
- **Subscription:** define each transition for renewal, retry, grace, pause, cancellation timing, proration and entitlement projection. Subscription and licence state remain separate aggregates.

No aggregate is implementation-ready solely because its state names are listed. Domain service tests and database concurrency tests are mandatory acceptance evidence.
