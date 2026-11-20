#!/usr/bin/env bash
set -euo pipefail

required_files=(
  "README.md"
  "LICENSE"
  "COMMERCIAL-LICENSING.md"
  "TRADEMARKS.md"
  "CONTRIBUTING.md"
  "SECURITY.md"
  "IMPLEMENTATION-STATUS.md"
  "docs/architecture/ROADMAP.md"
  "docs/architecture/DEFINITION-OF-DONE.md"
  "docs/architecture/AUTHORIZATION-MATRIX.md"
  "docs/architecture/DOMAIN-MODEL.md"
  "docs/architecture/API-CONTRACTS.md"
  "docs/architecture/REQUIREMENTS-TRACEABILITY.md"
  "docs/architecture/STATE-MACHINES.md"
  "docs/security/PRIVACY-AND-DATA-GOVERNANCE.md"
  "docs/operations/SHARED-HOSTING-OPERATIONS.md"
  "docs/security/PRIVACY-AND-DATA-GOVERNANCE.md"
  "docs/operations/SHARED-HOSTING-OPERATIONS.md"
  "docs/security/THREAT-MODEL.md"
  "docs/adr/0004-initial-deployment-and-tenancy-model.md"
  "docs/api/openapi.yaml"
  "docs/adr/0001-public-commercial-repository.md"
  "docs/adr/0002-super-admin-managed-integration-credentials.md"
  "docs/adr/0003-platform-wide-super-admin-control-center.md"
  "docs/security/INTEGRATION-CREDENTIALS.md"
  "docs/requirements/INTEGRATION-CREDENTIALS-REQUIREMENTS.md"
  "docs/requirements/SUPER-ADMIN-REQUIREMENTS.md"
)

for file in "${required_files[@]}"; do
  if [[ ! -s "$file" ]]; then
    printf 'ERROR: required project document is missing or empty: %s\n' "$file" >&2
    exit 1
  fi
done

while IFS= read -r -d '' file; do
  if grep -nE '[[:blank:]]+$' "$file"; then
    printf 'ERROR: trailing whitespace found in %s\n' "$file" >&2
    exit 1
  fi
  if [[ "$(tail -c 1 "$file" | wc -l)" -ne 1 ]]; then
    printf 'ERROR: missing final newline in %s\n' "$file" >&2
    exit 1
  fi
done < <(find . -type f -name '*.md' -not -path './.git/*' -print0)

printf 'Documentation governance checks passed.\n'
