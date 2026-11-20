# ADR 0004: Initial deployment and tenancy model

- **Status:** Accepted architecture baseline
- **Date:** 2026-11-20
- **Decision owners:** Tinlance Limited

## Decision

The initial product is a **vendor-operated, self-hosted TLMS deployment**: one TLMS installation is operated by one software vendor (the TLMS customer/licensee). That vendor manages its own products, customers, licences, billing integrations and resellers within that installation.

A customer organization using a vendor's software is a customer/business account inside that vendor's TLMS installation. It is not a separate TLMS platform tenant in the initial deployment.

The domain model will nevertheless carry an explicit `vendor_account_id` ownership boundary on vendor-owned records. In the initial self-hosted deployment it resolves to exactly one vendor account. This avoids conflating software customers with SaaS tenants and provides a migration seam for a future hosted multi-tenant edition.

## Initial deployment topology

- One installation, one vendor account, one control plane.
- Multiple customer organizations, customer users, products, plans, licences, installations and reseller relationships.
- Platform Super Admin means the operator's top-level administrator for that TLMS installation.
- Vendor staff roles are scoped to the installation and granted only required permissions.
- Customer users see only their own organization, orders, licences, invoices and permitted support information.
- SDK installations authenticate as constrained product/license principals, never as human administrators.
- Payment and other provider credentials are global to the vendor installation unless a provider explicitly supports customer-owned accounts.

## Future hosted edition

A future SaaS edition may host multiple vendor accounts in one control plane. That requires a separately reviewed ADR and must introduce enforced tenant isolation at every query, cache, queue, object-storage, search, audit, export and API boundary. The presence of `vendor_account_id` is not by itself proof of isolation.

Do not implement hosted multi-tenancy as an implicit assumption in the first release. Do not treat every software customer as a TLMS vendor tenant.

## Consequences

- The first release can be deployed on qualified annual prepaid cPanel/shared hosting.
- No central Tinlance-operated SaaS dependency is required for a vendor's licensing control plane.
- Customer SDKs communicate with the vendor's TLMS base URL.
- Cross-vendor operations are impossible in the initial topology because each vendor has a separate deployment/database.
- Hosted multi-tenancy, central marketplace and cross-vendor administration remain future scope.
- Vendor ownership and customer organization ownership must be explicit in the data model.
- A vendor's Super Admin does not gain access to customer passwords or unrecoverable provider secrets.
