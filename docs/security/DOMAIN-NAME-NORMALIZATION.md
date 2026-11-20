# Domain-name normalization contract

**Status:** Framework-independent implementation exists in `src/Domain/Licensing/DomainName.php`; API, persistence and SDK integration remain pending.

## Canonicalization

The activation-domain input is normalized as follows:

1. Reject empty input and surrounding whitespace. Do not silently trim.
2. Reject URL syntax, scheme separators, path, query, fragment, credentials, explicit port, percent encoding, wildcard and bracket syntax.
3. Remove exactly one terminal DNS root dot.
4. Convert using PHP intl `idn_to_ascii()` with UTS #46, STD3 rules and non-transitional ASCII processing.
5. Reject IDNA errors.
6. Lowercase the ASCII result.
7. Reject values over 253 bytes, invalid hostname labels and IP address literals.

The PHP `intl` extension is mandatory. If it is unavailable, normalization fails closed rather than falling back to inconsistent ASCII-only behavior.

## Security and authorization boundaries

- Normalize before uniqueness checks, activation-limit accounting, allowlist comparison, audit canonicalization and signed lease audience construction.
- Apply the exact same normalization algorithm and version to activation, verification, transfer, migration and SDK test vectors.
- Do not infer subdomain equivalence. Exact host matching is the baseline; wildcard and parent-domain matching require explicit product policy and tests.
- A domain match is only one activation binding signal. It does not replace licence state, product, installation identity, environment, entitlement or authorization checks.
- IP literals are rejected by this baseline. Localhost and private DNS suffix policy must be decided explicitly before production SDK use.
- Persist the normalized ASCII hostname and, if required for support display, the separately supplied original input with a documented privacy/retention policy. Never use the original input for security comparisons.

## Required future tests

- IDNA UTS #46 known vectors and invalid code points.
- Unicode confusables and mixed-script warning policy (display safety is separate from canonical equality).
- Case and terminal-dot equivalence.
- Scheme, credentials, port, path, query, fragment, wildcard, percent-encoding and whitespace rejection.
- Maximum total/label length and empty labels.
- Concurrent activation attempts after normalization.
- Cross-language SDK vectors so PHP server and client implementations agree exactly.
