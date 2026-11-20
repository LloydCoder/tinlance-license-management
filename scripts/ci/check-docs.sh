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
  "docs/adr/0001-public-commercial-repository.md"
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
