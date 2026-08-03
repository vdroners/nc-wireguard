# Continuity pass (2026-08) — nc-wireguard

## Changes
- Redrew `img/app.svg` as single-ink `currentColor` shield.
- Replaced dark-only status/accent hexes in `nc-wireguard-theme.scss` with NC CSS tokens.
- Rebuilt `ErrorBanner.vue` on `NcNoteCard` (same props/emits; 7 call sites unchanged).
- Licence → `AGPL-3.0-or-later`; nav order → 82.

## Verify
- Light theme: badges, error banner, and primary buttons legible.
- App nav icon monochrome.
