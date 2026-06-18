# Changelog

## [1.1.0] - 2026-06-18

### Added

- Shell-level summary polling (`useDashboardSummary`) — client filters work on deep-link tabs
- Responsive tab bar: 5 tabs on desktop, Overview + More menu on mobile
- Compact header: status chips in banner-extra, host metrics strip (CPU/Mem/Disk bars)
- Overview search/sort, progressive-disclosure table, mobile peer cards
- Shared `HistoryToolbar` for Bandwidth, Connections, System tabs
- Side-by-side bandwidth charts on wide screens; combined CPU+Memory system chart
- Map split layout with collapsible IP list
- Peer config modal: copy toast, wide QR layout, wg-easy edit link
- Tab badges, dynamic subtitle, disabled/expiring peer styling, uptime footer
- Visibility-aware polling pause; `nc_wireguard` banner icon in NcGcsAppShell

### Fixed

- Empty client dropdown when opening Bandwidth/Connections before Overview

## [1.0.0] - 2026-06-18

### Added

- Initial NC WireGuard app: 5-tab Vue dashboard (Overview, Bandwidth, Connections, Map, System)
- PHP proxy to wg-dashboard sidecar with admin-only gate
- Read-only peer WireGuard config modal + QR
- Admin settings + SidecarWatchdogJob
- Sidecar hardening: `/api/health`, IPv6 endpoint parsing, log prune, wg config route
