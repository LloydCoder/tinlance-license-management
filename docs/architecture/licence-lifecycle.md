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
