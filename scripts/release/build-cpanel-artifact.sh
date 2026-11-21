#!/usr/bin/env bash
set -Eeuo pipefail

repo_root="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
release_version="${TLMS_RELEASE_VERSION:-0.0.0-dev}"
output_dir="${TLMS_ARTIFACT_DIR:-$repo_root/dist}"

if [[ ! "$release_version" =~ ^[A-Za-z0-9][A-Za-z0-9._+-]{0,63}$ ]]; then
    printf 'Invalid TLMS_RELEASE_VERSION; use 1-64 safe version characters.\n' >&2
    exit 2
fi

for required_command in composer git sha256sum tar touch zip; do
    if ! command -v "$required_command" >/dev/null 2>&1; then
        printf 'Required build command is unavailable: %s\n' "$required_command" >&2
        exit 2
    fi
done

if [[ ! -f "$repo_root/composer.json" || ! -f "$repo_root/composer.lock" ]]; then
    echo "Composer manifest and lockfile are required to build a production artifact." >&2
    exit 2
fi

source_commit="$(git -C "$repo_root" rev-parse HEAD)"
source_date_epoch="$(git -C "$repo_root" show -s --format=%ct HEAD)"
composer_lock_sha256="$(sha256sum "$repo_root/composer.lock" | cut -d ' ' -f 1)"
composer_version="$(composer --version --no-ansi | awk '{ print $3 }')"
if [[ "$composer_version" != "2.11.2" ]]; then
    printf 'Composer 2.11.2 is required for the reproducible build; found %s\n' "$composer_version" >&2
    exit 2
fi

if [[ ! "$source_commit" =~ ^[a-f0-9]{40}$ || ! "$source_date_epoch" =~ ^[0-9]+$ || ! "$composer_lock_sha256" =~ ^[a-f0-9]{64}$ ]]; then
    echo "Source revision or lockfile digest could not be verified." >&2
    exit 2
fi

case "$output_dir" in
    /*) ;;
    *) output_dir="$repo_root/$output_dir" ;;
esac

mkdir -p "$output_dir"
output_dir="$(cd "$output_dir" && pwd)"
artifact_name="tlms-$release_version-cpanel.zip"
artifact_path="$output_dir/$artifact_name"
checksum_path="$artifact_path.sha256"

tmp_root="$(mktemp -d)"
trap 'rm -rf "$tmp_root"' EXIT
stage_root="$tmp_root/stage"
app_root="$stage_root/tlms"
mkdir -p "$stage_root"

git -C "$repo_root" archive --format=tar --prefix=tlms/ HEAD | tar -xf - -C "$stage_root"
if [[ ! -f "$app_root/.env.example" || -L "$app_root/.env.example" ]]; then
    echo "A regular root .env.example is required in the source tree." >&2
    exit 2
fi

source_symlink="$(find "$app_root" -type l -print -quit)"
if [[ -n "$source_symlink" ]]; then
    printf 'Symlinks are not allowed in the production artifact source tree: %s\n' "$source_symlink" >&2
    exit 2
fi


(
    cd "$app_root"
    composer validate --strict
    composer audit --locked --no-interaction
    composer install         --no-dev         --no-interaction         --prefer-dist         --no-progress         --optimize-autoloader         --classmap-authoritative
)

rm -rf     "$app_root/.github"     "$app_root/docs"     "$app_root/scripts"     "$app_root/tests"     "$app_root/node_modules"     "$app_root/phpstan.neon"     "$app_root/phpunit.xml"     "$app_root/composer.json"     "$app_root/composer.lock"

find "$app_root" \( -type f -o -type l \) -name '.env*' ! -path "$app_root/.env.example" -delete
find "$app_root" -type d -name '.env*' -prune -exec rm -rf {} +
find "$app_root" \( -type f -o -type l \) \( -name 'auth.json' -o -name '.npmrc' -o -name '.pypirc' -o -name '.netrc' \) -delete
find "$app_root/storage" -type f -name '*.log' -delete
find "$app_root/bootstrap/cache" -type f ! -name '.gitignore' -delete

mkdir -p     "$app_root/storage/app"     "$app_root/storage/framework/cache/data"     "$app_root/storage/framework/sessions"     "$app_root/storage/framework/views"     "$app_root/storage/logs"     "$app_root/bootstrap/cache"

cat > "$app_root/BUILD-MANIFEST.json" <<MANIFEST
{
  "artifact_format": 1,
  "product": "TLMS",
  "release_version": "$release_version",
  "source_commit": "$source_commit",
  "composer_lock_sha256": "$composer_lock_sha256",
  "composer_version": "$composer_version",
  "runtime_php": ">=8.3 <8.6",
  "database_targets": ["MySQL 8.4", "MariaDB 10.11"],
  "dependency_install_mode": "production-only --no-dev",
  "public_document_root": "public/"
}
MANIFEST

find "$app_root" -exec touch -h -d "@$source_date_epoch" {} +

rm -f "$artifact_path" "$checksum_path"
(
    cd "$stage_root"
    find tlms -type f -print | LC_ALL=C sort | zip -X -q "$artifact_path" -@
)

unzip -t "$artifact_path" >/dev/null
(
    cd "$output_dir"
    sha256sum "$artifact_name" > "$(basename "$checksum_path")"
)

printf 'Built cPanel production artifact: %s\n' "$artifact_path"
printf 'SHA-256 checksum: %s\n' "$checksum_path"
