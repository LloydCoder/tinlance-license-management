# TLMS brand system implementation contract

**Authority:** TLMS Brand Kit v1.0 and TLMS Usage Sheet v1.0 (prepared 20 November 2026).
**Owner:** Tinlance Limited.
**Status:** Brand specification baseline; asset integration must use the supplied files.

This document translates the supplied visual identity into implementation rules for the Laravel application, customer-facing surfaces, release artifacts and repository metadata. The supplied SVG/PNG assets remain authoritative: do not redraw, retype, recolour or approximate the mark or wordmark.

## 1. Brand identity

- Short name: **TLMS** (always uppercase).
- Full name: **Tinlance License Management System**.
- Descriptor: **Commercial licensing infrastructure**.
- Repository name: `tinlance-license-management`.
- Tone: quiet, exact, trustworthy and certificate-authority oriented.
- Proprietary to Tinlance Limited. Public repository visibility does not grant logo, trademark, commercial, white-label or redistribution rights.

## 2. Design tokens

Use the exact supplied values. Do not substitute approximate Tailwind defaults.

| Token | Value | Intended role |
|---|---|---|
| Ink | `#0A0C0B` | Primary dark surfaces and default tile |
| Lime | `#C6FF4A` | Single accent; mark on Ink tile |
| Paper | `#F4F4F0` | Primary light surface |
| Edge | `#3A403C` | Tile outline on dark surfaces |
| Graphite | `#4A504B` | Descriptor on light surfaces |
| Fog | `#8A8F87` | Captions on dark surfaces |

Balance target: approximately 60% Ink, 30% Paper and 10% Lime. Lime is not a text colour on Paper (approximately 1.1:1 contrast). Use accessible Ink text on Lime instead.

Canonical token sources in the brand kit:
- `tokens/colors.css`
- `tokens/colors.json`
- `tokens/tailwind.snippet.js`

## 3. Typography

- Interface and surrounding document typography: Inter (ExtraBold, Bold, Medium, Regular).
- Keys, labels, code and technical metadata: JetBrains Mono (Regular, Bold).
- CSS stacks:
  - `"Inter", system-ui, -apple-system, "Segoe UI", sans-serif`
  - `"JetBrains Mono", ui-monospace, SFMono-Regular, Menlo, monospace`
- The supplied wordmark and descriptor are outlined artwork. Never recreate them with live text or a substitute font.

## 4. Logo and mark construction

The mark is the three-node T from the Tinlance T-node family: a horizontal bar, stem, key bow and two teeth. It must retain the supplied proportions and optical balance.

The 120-unit tile specification is:
- Tile: 120 × 120 units; corner radius 28 units (favicon radius 26).
- Bar: x=28 to x=92 at y=29.
- Stem: y=29 to y=89.
- Main stroke: 10 units, round caps.
- Nodes: radius 9 at (28,29), (92,29), (60,91).
- Teeth: 8-unit stroke at y=67 (18-unit length) and y=79 (14-unit length).
- Optical centre: mark sits 5 units above geometric centre.

Use the supplied assets rather than rebuilding this geometry.

## 5. Approved lockups and application

- Horizontal lockup: default for website/app headers and compact horizontal surfaces.
- Horizontal + descriptor: login, cover and deck title surfaces where the full descriptor is useful.
- Stacked lockup: square spaces and avatars.
- Stacked + descriptor: print, certificates, invoices and letterhead.
- Light UI: `logo/svg/lockup/tlms-lockup-horizontal-light.svg`.
- Dark UI: `logo/svg/lockup/tlms-lockup-horizontal-dark.svg`.
- Favicon: use the supplied `favicon/` directory and `favicon/favicon-snippet.html`.
- Admin/sidebar and app icon assets: use the supplied `app-icons/admin-dashboard-logo-256.png` and relevant supplied platform icons.
- Certificate seal: use `logo/svg/seal/tlms-seal-light.svg` or `logo/svg/seal/tlms-seal-dark.svg` according to the surface.

The supplied SVG lockups already include the specified clear space. Preserve it.

## 6. Clear space, minimum sizes and simplification

- Clear space: x equals one node diameter (18 units) on every side.
- Lockup minimum: 160 px digital / 40 mm print.
- Tile minimum: 16 px digital / 8 mm print.
- Seal minimum: 96 px digital / 20 mm print.
- At 48 px and above: full mark.
- At 24–47 px: use the supplied one-tooth `-mid` tile.
- Below 24 px: use the supplied bare-node `-micro` tile.
- Do not scale the full mark below its documented simplification threshold.

## 7. Background pairing

- Primary backgrounds: Ink and Paper.
- On Lime: use the black monochrome lockup.
- On photography: use a 70%+ Ink scrim or the white monochrome lockup on a calm area.
- Lime mark on a light surface is only permitted inside the supplied Ink tile.
- Do not use Lime as small text on Paper.

## 8. Prohibited treatments

Never:
- stretch, squash, rotate or tilt the mark or lockup;
- recolour the mark or wordmark;
- place a Lime mark directly on a light background without the Ink tile;
- add a shield, padlock or other enclosure to the mark;
- add shadows, glow, gradients or decorative effects;
- use an outline-only mark;
- retype the TLMS wordmark;
- place the lockup over busy imagery without the required scrim.

## 9. Required implementation surfaces

When the corresponding application surfaces are implemented, apply the approved assets consistently to:
- browser favicon and pinned tab;
- login and account-recovery pages;
- admin Control Center and navigation/sidebar;
- customer/vendor workspaces;
- licence verification and certificate/seal views;
- invoice, email and notification templates;
- Open Graph, social preview and repository README assets;
- PWA, Android and iOS icon manifests where those clients are shipped.

Do not claim an asset is integrated merely because it exists in the uploaded archive. Record the exact destination path and verify the rendered result for each surface.

## 10. Brand acceptance checklist

- [ ] The supplied SVG/PNG source assets are copied without modification and their provenance is retained.
- [ ] The canonical colour tokens are imported once and reused; no conflicting near-duplicate values are introduced.
- [ ] UI typography uses Inter and JetBrains Mono; logos remain outlined artwork.
- [ ] Correct light/dark lockups are used on their intended backgrounds.
- [ ] Clear space, minimum sizes and small-icon simplification are respected.
- [ ] Lime is not used as text on Paper.
- [ ] Favicon, application icons, seals and social images use their supplied purpose-specific files.
- [ ] Login, admin, customer and vendor surfaces are checked at desktop and mobile breakpoints.
- [ ] Visual regression screenshots are reviewed before declaring brand integration complete.

## 11. Source materials

- Brand kit: `tlms-brand-kit.zip`, version 1.0.
- Usage sheet: `tlms-usage-sheet.pdf`, three pages.
- Page 1: mark construction, lockups, colour variants, tile sizes and simplification.
- Page 2: colour palette, contrast pairings, typography, clear space, minimum sizes, backgrounds and prohibited treatments.
- Page 3: in-use examples, naming/voice, quick rules and file-selection index.
