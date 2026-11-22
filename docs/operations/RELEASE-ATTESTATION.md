# TLMS signed release, SBOM and provenance workflow

**Status:** Release pipeline implemented in CI; a real version-tag release and external consumer verification are still required before the release-trust phase is accepted.

## 1. Release pipeline

The `.github/workflows/release.yml` workflow has two stages:

1. **Build and verify** runs for pull requests, version-tag pushes and manual dispatch. It builds the deterministic production-only cPanel ZIP using the same release builder as the CI artifact test. The builder uses pinned Node.js 22.16.0/npm to run the locked Vite production build and includes `public/build/passkeys.js` and `public/build/membership-invitations.js`; the package manifest records the build tool versions. It then removes npm manifests, Vite configuration and `node_modules` before packaging, generates an SPDX 2.3 SBOM from the packaged ZIP, validates that the package inventory is non-empty and includes `laravel/framework`, computes SBOM checksum, and uploads the ZIP/checksums/SBOM as a short-lived workflow artifact.
2. **Publish and attest** runs only for a pushed `vMAJOR.MINOR.PATCH` (or supported prerelease) tag. It downloads the exact build job outputs, verifies both checksums, creates GitHub/Sigstore SLSA provenance and SPDX SBOM attestations for the ZIP, verifies both attestations with GitHub CLI, and only then publishes the ZIP, checksum and SBOM as GitHub Release assets.

The workflow does not publish releases for pull requests or manual dispatch. The build job runs on PRs so the artifact and SBOM generation path is continuously exercised without requesting OIDC signing authority. Node.js/npm are build-time tools only; the customer cPanel runtime does not require Node.js, npm, Composer or a persistent worker.

## 2. Verification by a release consumer

After a tagged release has been published, download the ZIP, its `.sha256` file and its SPDX JSON SBOM from the GitHub Release. Verify the checksum:

```bash
sha256sum -c tlms-VERSION-cpanel.zip.sha256
```

Verify SLSA provenance for the artifact:

```bash
gh attestation verify tlms-VERSION-cpanel.zip --repo LloydCoder/tinlance-license-management
```

Verify the SBOM attestation:

```bash
gh attestation verify tlms-VERSION-cpanel.zip \
  --repo LloydCoder/tinlance-license-management \
  --predicate-type https://spdx.dev/Document/v2.3
```

The GitHub attestation must identify the expected repository, workflow, source commit and tag event. A checksum alone detects accidental corruption; it does not authenticate who built an artifact. A valid attestation binds the artifact digest to the GitHub Actions/Sigstore identity and the build provenance.

## 3. Trust boundaries and limitations

- The signing authority is the GitHub Actions OIDC/Sigstore attestation identity. It is not the TLMS Ed25519 licence/update signing root and does not replace the future offline signing ceremony.
- A valid provenance attestation proves the recorded build origin and workflow identity; it does not prove the application is vulnerability-free, legally compliant or production-ready.
- The SBOM is generated from the production ZIP, not from the developer checkout, so development-only Composer packages should not appear in its dependency inventory. Browser bundles are included, while Node/npm/Vite build tooling is excluded from the customer ZIP.
- Release tags must be protected by repository governance. Until the owner configures and verifies a tag ruleset, release creation remains an owner-controlled operation.
- No release is created by this PR. A real version tag, successful attestation upload, independent verification and consumer-side verification are required to close the release trust acceptance gate.
- GitHub artifact attestations are available for public repositories on current GitHub plans. They are not a replacement for independent offline verification or a separately controlled release-signing key where customer contracts require one.

## 4. Evidence required to accept this control

- [ ] PR workflow build and SBOM generation pass on the exact PR head.
- [ ] The cPanel ZIP contains both browser entry points and excludes package manifests, Vite configuration and node_modules.
- [ ] A real protected version tag builds the same artifact and creates both attestations.
- [ ] GitHub CLI verifies provenance and SPDX SBOM attestations against the repository.
- [ ] Release ZIP, checksum and SBOM are published and downloadable.
- [ ] A clean external environment downloads the assets and independently verifies checksum and attestations.
- [ ] Tag governance and release approval authority are configured and tested.
