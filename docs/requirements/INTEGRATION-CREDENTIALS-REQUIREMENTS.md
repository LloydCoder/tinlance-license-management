# Integration credential requirements

| ID | Requirement | Priority | Verification |
|---|---|---|---|
| INT-CRED-001 | Super Admin can configure supported provider API credentials from Admin Settings without editing source or deployment files | Must | Dashboard end-to-end test |
| INT-CRED-002 | Platform-global credential mutations are restricted to Super Admin and require MFA/recent authentication | Must | Authorization and step-up tests |
| INT-CRED-003 | Saved credentials are encrypted with authenticated encryption before persistence | Must | Storage integration test |
| INT-CRED-004 | The encryption key is external to the database and protected outside the web root | Must | Deployment/configuration audit |
| INT-CRED-005 | Saved secret values are write-only and never returned after submission | Must | API/UI response tests |
| INT-CRED-006 | UI displays configured state, masked suffix and safe validation metadata only | Must | Browser and API tests |
| INT-CRED-007 | Provider test-connection validates credentials without exposing secrets | Must | Provider adapter contract tests |
| INT-CRED-008 | Credential rotation is versioned and does not replace a working credential before successful validation | Must | Lifecycle tests |
| INT-CRED-009 | Credential actions create metadata-only audit events | Must | Audit redaction tests |
| INT-CRED-010 | Secrets are redacted from logs, traces, exceptions, cache, sessions, exports and queued payloads | Must | Automated leakage tests |
| INT-CRED-011 | Tenant-owned credentials, if supported, are isolated from platform-global and other tenant credentials | Must | Tenant isolation tests |
| INT-CRED-012 | Missing encryption configuration fails closed; plaintext fallback is prohibited | Must | Negative configuration tests |
| INT-CRED-013 | Crypto/payment provider credentials remain disabled until provider and legal qualification is recorded | Must | Feature gate and release checklist |
| INT-CRED-014 | TLMS signing root keys are excluded from ordinary API credential settings | Must | Architecture and UI review |
| INT-CRED-015 | Provider adapters resolve credentials through a scoped credential service, not direct settings-table access | Must | Architecture test/code review |
