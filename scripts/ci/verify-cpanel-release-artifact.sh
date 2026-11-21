#!/usr/bin/env bash
set -Eeuo pipefail

repo_root="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
builder="$repo_root/scripts/release/build-cpanel-artifact.sh"

if [[ ! -f "$builder" ]]; then
    printf 'Required cPanel artifact builder is missing: %s\n' "$builder" >&2
    exit 1
fi

tmp_dir="$(mktemp -d)"
trap 'rm -rf "$tmp_dir"' EXIT

artifact_dir="$tmp_dir/dist"
TLMS_RELEASE_VERSION="0.0.0-test" TLMS_ARTIFACT_DIR="$artifact_dir" bash "$builder"

repeat_artifact_dir="$tmp_dir/repeat"
TLMS_RELEASE_VERSION="0.0.0-test" TLMS_ARTIFACT_DIR="$repeat_artifact_dir" bash "$builder"

artifact="$artifact_dir/tlms-0.0.0-test-cpanel.zip"
checksum="$artifact.sha256"
repeat_artifact="$repeat_artifact_dir/tlms-0.0.0-test-cpanel.zip"

[[ -s "$artifact" ]] || { echo "Artifact was not created." >&2; exit 1; }
[[ -s "$checksum" ]] || { echo "Artifact checksum was not created." >&2; exit 1; }
[[ -s "$repeat_artifact" ]] || { echo "Second reproducibility artifact was not created." >&2; exit 1; }
cmp -s "$artifact" "$repeat_artifact" || { echo "Repeated package builds are not byte-for-byte reproducible." >&2; exit 1; }
unzip -t "$artifact" >/dev/null
(cd "$artifact_dir" && sha256sum -c "$(basename "$checksum")")

mapfile -t entries < <(unzip -Z1 "$artifact")
required_entries=(
    "tlms/artisan"
    "tlms/public/index.php"
    "tlms/vendor/autoload.php"
    "tlms/.env.example"
    "tlms/BUILD-MANIFEST.json"
    "tlms/LICENSE"
    "tlms/COMMERCIAL-LICENSING.md"
)

for required in "${required_entries[@]}"; do
    if ! printf '%s\n' "${entries[@]}" | grep -Fxq "$required"; then
        printf 'Required release entry is missing: %s\n' "$required" >&2
        exit 1
    fi
done

for forbidden in "tlms/.env" "tlms/composer.json" "tlms/composer.lock" "tlms/phpunit.xml" "tlms/phpstan.neon"; do
    if printf '%s\n' "${entries[@]}" | grep -Fxq "$forbidden"; then
        printf 'Forbidden release entry found: %s\n' "$forbidden" >&2
        exit 1
    fi
done

if printf '%s\n' "${entries[@]}" | grep -Eq '^tlms/(tests|docs|scripts|\.github)/'; then
    echo "Development or internal-only files were included in the production artifact." >&2
    exit 1
fi

if printf '%s\n' "${entries[@]}" | grep -Eq '^tlms/\.env(\.|$)' | grep -vFxq "tlms/.env.example"; then
    echo "Unexpected environment file found in the production artifact." >&2
    exit 1
fi

unzip -p "$artifact" tlms/vendor/composer/installed.json > "$tmp_dir/installed.json"
php -r '
$installed = json_decode(file_get_contents($argv[1]), true, 512, JSON_THROW_ON_ERROR);
if (($installed["dev"] ?? true) !== false) {
    fwrite(STDERR, "Production Composer install metadata is missing or marked as a dev install.".PHP_EOL);
    exit(1);
}
$forbidden = ["larastan/larastan", "phpstan/phpstan", "phpunit/phpunit", "laravel/pint", "mockery/mockery"];
foreach (($installed["packages"] ?? []) as $package) {
    if (in_array($package["name"] ?? "", $forbidden, true)) {
        fwrite(STDERR, "Development dependency included in production artifact: ".$package["name"].PHP_EOL);
        exit(1);
    }
}
' "$tmp_dir/installed.json"

unzip -p "$artifact" tlms/BUILD-MANIFEST.json > "$tmp_dir/manifest.json"
php -r '
$manifest = json_decode(file_get_contents($argv[1]), true, 512, JSON_THROW_ON_ERROR);
if (($manifest["release_version"] ?? null) !== "0.0.0-test") {
    fwrite(STDERR, "Unexpected release version in manifest.".PHP_EOL);
    exit(1);
}
if (! preg_match("/^[a-f0-9]{40}$/", (string) ($manifest["source_commit"] ?? ""))) {
    fwrite(STDERR, "Manifest source commit is invalid.".PHP_EOL);
    exit(1);
}
if (($manifest["source_commit"] ?? null) !== trim($argv[2])) {
    fwrite(STDERR, "Manifest source commit does not match the checked-out revision.".PHP_EOL);
    exit(1);
}
if (($manifest["composer_lock_sha256"] ?? null) !== trim($argv[3])) {
    fwrite(STDERR, "Manifest lockfile digest does not match composer.lock.".PHP_EOL);
    exit(1);
}
if (! preg_match("/^[a-f0-9]{64}$/", (string) ($manifest["composer_lock_sha256"] ?? ""))) {
    fwrite(STDERR, "Manifest lockfile digest is invalid.".PHP_EOL);
    exit(1);
}
if (($manifest["public_document_root"] ?? null) !== "public/") {
    fwrite(STDERR, "Manifest document root is not public/.".PHP_EOL);
    exit(1);
}
if (($manifest["dependency_install_mode"] ?? null) !== "production-only --no-dev") {
    fwrite(STDERR, "Manifest does not record a production-only dependency install.".PHP_EOL);
    exit(1);
}
' "$tmp_dir/manifest.json" "$(git -C "$repo_root" rev-parse HEAD)" "$(sha256sum "$repo_root/composer.lock" | cut -d ' ' -f 1)"

echo "cPanel production artifact verification passed."
