# Security policy

## Reporting a vulnerability

Do not publish exploitable vulnerability details in a public issue or pull request. Report suspected vulnerabilities privately to **security@tinlance.com**. If that address is not yet operational, use a private communication channel with Tinlance Limited and do not include secrets in a public report.

Include the affected commit or release, impact, reproducible steps, prerequisites, and any suggested mitigation. Allow a reasonable coordinated-disclosure period.

## Supported versions

Until the first supported release is published, the main branch is the only development line and is not a production support commitment. Release support windows will be published with the first production release.

## Security expectations

- Never commit credentials, private signing keys, customer data or production configuration.
- Use least-privilege credentials and rotate exposed secrets immediately.
- Verify webhook signatures and enforce idempotency.
- Treat customer-controlled input, remote responses and package contents as untrusted.
- Report dependency, supply-chain, authorization, cryptographic and data-isolation issues with the same care as application vulnerabilities.
- Do not conduct destructive testing against systems without authorization.

## Response

Tinlance will triage reports, assess severity and affected versions, coordinate a fix, validate the remediation and publish an advisory when appropriate. Response targets and a supported-release policy will be established before GA.

Do not send vulnerability details to a public email list or issue tracker.
