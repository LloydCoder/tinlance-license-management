# Engineering definition of done

A task is complete only when all applicable criteria are satisfied.

## Code
- Behavior is implemented without placeholder paths or silent stubs.
- Tests were written first for behavior changes and demonstrate meaningful failure before implementation.
- Error paths, validation, authorization and boundary cases are covered.
- Formatting, static analysis and full relevant test suites pass.
- No secrets, customer data or unsafe generated artifacts are committed.

## Security
- Trust boundaries and attacker-controlled inputs are identified.
- Authentication is not confused with authorization.
- Tenant and object access is checked server-side.
- Sensitive actions are auditable and appropriately reauthenticated.
- Cryptographic operations use reviewed algorithms and versioned formats.
- Dependencies and build actions are pinned and reviewed.
- Logs redact credentials and sensitive personal data.

## Data and compatibility
- Migrations are tested on supported database engines.
- Upgrade and rollback/recovery implications are documented.
- API, SDK and signed credential compatibility is versioned.
- Idempotency and concurrency behavior are tested where applicable.

## Documentation
- README, configuration, operations and security documentation match actual behavior.
- ADRs explain material architectural choices.
- Known limitations and unresolved risks are explicit.
- Requirements trace to implementation and test evidence.

## Delivery
- CI is green on the exact commit.
- No required job is skipped or neutral.
- Artifacts are reproducible or independently verifiable to the degree specified.
- Phase forensic review is complete.
- IMPLEMENTATION-STATUS.md records commit, checks, evidence and residual risks.

## Phase completion
A phase is complete only when every mandatory exit criterion is evidenced. A green workflow alone does not prove security, product correctness, hosting compatibility or commercial readiness.
