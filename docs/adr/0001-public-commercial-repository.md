# ADR 0001: Public repository with reserved commercial rights

- **Status:** Accepted as initial project policy; legal review required before sale.
- **Date:** 2026-11-20
- **Decision owners:** Tinlance Limited

## Context

TLMS is intended to be inspectable in public and commercially sold, including white-label and redistribution offerings. Making the repository private would conflict with that distribution and transparency strategy. Public visibility, however, must not be confused with an open-source licence.

## Decision

Keep LloydCoder/tinlance-license-management public. Retain copyright and commercial rights with Tinlance Limited, subject to verified contributor and third-party rights. Until a separately reviewed licence is published, use a reserved-rights source-visible policy. Grant commercial, white-label and redistribution permissions only through explicit written agreements.

## Consequences

- Public source can be inspected and discussed.
- Public visibility does not itself grant commercial reuse rights.
- No open-source claim may be made under this policy.
- Dependency and contributor rights must be audited before releases.
- A public licence change requires a new ADR and legal review.
- Branch governance, CI, secret scanning and security disclosure are required.

## Rejected alternatives

- **Private repository:** conflicts with the approved public distribution strategy.
- **Unqualified permissive open-source licence:** would grant broad reuse rights inconsistent with the currently intended commercial control.
- **Unreviewed dual licensing:** risks unclear ownership and incompatible contributor/dependency rights.
