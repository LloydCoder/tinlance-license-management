# TLMS licence lifecycle policy

## Persisted states

A newly issued licence begins in **issued**. Activation is a separate action and does not happen during issuance.

| Current state | Allowed next states |
|---|---|
| issued | active, suspended, revoked, expired |
| active | suspended, revoked, expired |
| suspended | active, revoked, expired |
| revoked | terminal |
| expired | terminal |

The public lifecycle API accepts active, suspended and revoked as user-directed targets. Expiry is system-managed. Revoked and expired licences cannot be reactivated; issue a replacement licence when access must be restored after a terminal state.

## Activation window

Activation and reinstatement require the licence start time to have been reached. If expires_at is present, the effective validity boundary is grace_until when configured, otherwise expires_at. A licence cannot be activated or reinstated after that boundary. A configured grace_until therefore extends the effective validity window; issuance currently leaves it unset unless a future approved grace policy is added.

## Authorization and concurrency

- Vendor membership must be active and carry licences.mutate.
- The actor must have an active product assignment, except for the vendor Super Admin role.
- Bootstrap platform super-admin identities cannot act as tenant licence operators.
- Lifecycle changes require recent password confirmation and fresh MFA step-up.
- The vendor and licence rows are locked in one database transaction.
- The licence aggregate version advances exactly once for each accepted transition.
- The lifecycle event is appended in the same transaction; a failure rolls back both changes.
- X-Request-ID is unique within the vendor and provides idempotent replay. Reusing it for a different licence action is a conflict.

## Immutable event evidence

Each accepted action appends an immutable licence event with actor, reason, request ID, from/to states, aggregate version and a SHA-256 payload digest. Event records cannot be updated or deleted. Replayed requests do not append duplicate events.

## Renewal policy

- Only active or suspended annual and subscription licences can be renewed through the renewal endpoint.
- The immutable published plan version determines the renewal period: annual plans add one calendar year; subscriptions add their published month/year interval.
- Renewal extends from the effective expiry (grace deadline when present, otherwise expiry) and clears the consumed grace deadline.
- A perpetual, trial, already-expired or otherwise terminal licence cannot be renewed through this operation.
- Renewal preserves the current lifecycle state. Renewing a suspended licence does not silently reinstate it.
- Renewal locks the vendor, licence and published plan version, advances the licence aggregate version exactly once and appends an immutable `renewed` event atomically.
- Vendor-scoped `X-Request-ID` retries are payload-bound; a conflicting reuse is rejected.

## Site, seat and user allocations

- Allocations are allowed only for active, in-window licences.
- `site`, `seat` and `user` allocation types map to the published plan entitlements `sites`, `seats` and `users`. Missing, non-integer or non-positive limits grant no allocation capacity.
- A licence-row lock serializes capacity checks and reservations. The active allocation count is checked before a reservation is created or reactivated.
- Each allocation is uniquely identified by licence, type and a keyed subject fingerprint. The raw caller-provided subject identifier is never persisted in the allocation row, event, or response.
- Fingerprints use a domain-separated HMAC key derived from the licence's retained pepper key ID. Retaining old peppers is required while licences and allocations reference them.
- Released allocations remain as historical records. A released subject may be reactivated with a new aggregate version; active allocations cannot be duplicated.
- Allocation creation and release append immutable events in the same transaction. Request IDs are vendor-scoped and cannot be reused across the licence-event and allocation-event streams.

## Remaining Phase 6 scope

This increment does not yet implement successor-based transfer/replacement workflows, full usage metering, or automated expiry processing. Domain normalization, installation identity and activation leases remain Phase 8 work. Do not mark the licence engine phase complete until those Phase 6 acceptance requirements and their concurrency/tenant-isolation tests are delivered.
