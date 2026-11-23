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


## Automated expiry processing

- The `tlms:licenses:expire` command processes a bounded batch (default 100; maximum 1,000) of issued, active or suspended licences whose expiry and configured grace deadline have both elapsed.
- Each candidate is rechecked under a row lock. The status transition and immutable system-authored `expired` event commit atomically; retries and concurrent scheduler invocations cannot append a second expiry event for the same aggregate version.
- The event records the previous status, expiry/grace boundary, next aggregate version and a deterministic system request identifier. No customer database or installation data is transmitted.
- Laravel schedules the command every five minutes with overlap prevention. Shared-hosting operators must invoke Laravel's scheduler from the hosting cron facility once per minute (for example, `php artisan schedule:run` from the application directory). No persistent worker is required.
- Operators may run `php artisan tlms:licenses:expire --limit=100` manually for controlled catch-up. The batch limit must be between 1 and 1,000.
- The scheduler is intentionally eventual within its five-minute cadence; real-time verification must independently reject licences whose effective expiry boundary has passed.


## Usage metering policy

- Usage is product-scoped through the immutable published plan version and is represented by entitlement keys `usage.{metric}.{period}`, where period is `monthly`, `annual` or `lifetime` and the value is a positive integer quota.
- Monthly and annual counters use UTC calendar-month and calendar-year windows respectively. Lifetime counters begin at the licence start time (or creation time when no start time is set) and do not reset.
- Only active, in-window licences can consume usage. Suspended, revoked, expired, not-yet-started and grace-expired licences are rejected.
- A licence row lock serializes concurrent metering for that licence. The mutable counter projection and append-only usage event are committed in one transaction.
- Vendor-scoped request IDs are payload-bound and unique across issuance/lifecycle, allocation and usage event streams. Replays do not increment usage a second time.
- Usage events contain metric, period, positive delta, resulting counter, actor, reason and a payload digest; they do not contain customer database contents or installation identifiers.
- The initial API records usage on behalf of an authorized vendor operator with recent password confirmation and fresh MFA step-up. It is not yet the machine-authenticated SDK ingestion endpoint; SDK credentials and installation-bound authentication are delivered in later activation/SDK phases.


## Successor transfer and replacemen

- Transfer and replacement never mutate a licence's customer, product, plan version or credential identity in place. They create a new successor licence and revoke the source in one transaction.
- Transfer requires a different active customer organization in the same vendor; replacement retains the current customer.
- The successor inherits the source plan version, original start time, expiry and grace deadline. It receives a fresh random key; the key is never returned in the successor response and remains available only through the existing one-time issuance secret-delivery workflow.
- The immutable successor relation binds source, successor, vendor, product, source/destination customer, actor, reason, request fingerprint, idempotency key and payload digest.
- A source licence can have only one successor. A successor may later itself be transferred or replaced, producing a new link in the chain.
- Only active, in-term licences may be transferred or replaced. The source becomes terminally revoked; the successor begins issued and must be activated separately.


## Domain allocation normalization

- Domain allocations use the `domains` integer entitlement and the same versioned allocation/event ledger as sites, seats and users.
- Hostnames are normalized with the shared IDNA/UTS #46 `DomainName` value object: lowercase ASCII A-label, optional terminal dot removed, and invalid URL syntax, wildcards, IP literals and invalid DNS names rejected.
- Only the keyed HMAC fingerprint is persisted; the raw hostname is not stored in the allocation table or event payload.
- The domain quota is enforced atomically with the other allocation types. A canonical hostname cannot be allocated twice concurrently, and released allocations may be reactivated under the normal version/event rules.
- Existing deployments receive the updated MySQL/MariaDB insert guard through a forward migration; the original allocation migration remains unchanged.
