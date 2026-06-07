# OPB Release Notes

---

## v2.0.8 — PWA Add to Home Screen Fallback · 7 June 2026

**Commit:** `2292b33`

### PWA UX — Manual Install Fallback

Chrome Android fires `beforeinstallprompt` and shows **Install Application** natively. Safari iPhone, Firefox Android, and other browsers that do not support `beforeinstallprompt` previously received no install action at all.

This release adds a graceful fallback for those browsers without touching the working Chrome install flow.

**Behaviour by browser:**

| Browser | Action shown |
|---|---|
| Chrome Android / Edge / Chrome Desktop | **Install Application** — native prompt, unchanged |
| Safari iPhone / Safari iPad | **Add to Home Screen** — tap to reveal iOS-specific steps |
| Firefox Android / other | **Add to Home Screen** — tap to reveal generic steps |
| Any browser (already installed) | Nothing — both actions hidden |

**Platform detection** — `usePWAInstall` now exposes a `platform` field (`'ios' | 'other'`), derived from the UA at mount time. iOS copy reads: *Tap Share → Tap Add to Home Screen → Tap Add*. Other copy reads: *Open browser menu → Tap Add to Home Screen*. No user-agent spaghetti; two branches only.

**UI** — Both Sidebar and TopBar use the same single `usePWAInstall()` source. No competing listeners, no duplicate state.
- **Sidebar** — guide expands inline below the button on tap
- **TopBar** — guide appears as a popover; closes on outside click

**Files changed:**
- `plugin/app/src/hooks/usePWAInstall.ts` — added `Platform` type, `detectPlatform()`, and `platform` in return value
- `plugin/app/src/components/Sidebar.tsx` — `'unsupported'` branch with inline guide steps
- `plugin/app/src/components/TopBar.tsx` — `'unsupported'` branch with popover guide steps

### Build

- React rebuilt: tsc + vite — 106 modules, 401 KB JS / 50 KB CSS
- Production ZIP: `onukonu-pet-boarding-core-v2.0.8.zip` — 45.9 MB, 732 files

---

## v2.0.7 — PWA Apache Bypass · 7 June 2026

**Commit:** `7c26ee4`

### PWA Installability Fix — Hostinger Apache Intercept

Hostinger's Apache configuration intercepts requests ending in `.json` and `.js` before they reach WordPress. This caused `/opb-manifest.json` and `/opb-sw.js` to return HTTP 404 on the live server, preventing Chrome from loading the manifest, registering the service worker, or reaching installable state. `beforeinstallprompt` never fired.

The WordPress rewrite rules and PHP handlers were already correct — the requests simply never arrived at WordPress.

**Fix:** Replaced all references to the static-path endpoints with the confirmed-working query-parameter endpoints, which bypass Apache and reach WordPress directly.

| Endpoint | Old (404) | New (200) |
|---|---|---|
| Web App Manifest | `/opb-manifest.json` | `/?opb_manifest=1` |
| Service Worker | `/opb-sw.js` | `/?opb_sw=1` |

Both query-parameter endpoints were verified on production prior to this change:
- `GET /?opb_manifest=1` → `200 application/manifest+json`
- `GET /?opb_sw=1` → `200 application/javascript` + `Service-Worker-Allowed: /`

The manifest `id`, `start_url`, and `scope` remain `/portal/`. Service worker scope argument remains `{ scope: '/portal/' }`. No React source, auth, install UI, or PWA architecture changed.

**Files changed:**
- `plugin/includes/class-opb-portal.php` — `$manifest_url`, `$sw_url`, and inline comment updated to `/?opb_manifest=1` / `/?opb_sw=1`
- `RELEASE_NOTES.md` — stale `/opb-sw.js` reference updated

### Build

- Production ZIP: `onukonu-pet-boarding-core-v2.0.7.zip` — 45.9 MB, 732 files

---

## v2.0.5 — Production Hardening · 7 June 2026

**Commit:** `de8499b`

### PWA Installability Fixes

Chrome Android was not exposing the install prompt despite a valid manifest and service worker being present. Root cause analysis identified two critical issues and three secondary issues.

**Critical — Race condition on `beforeinstallprompt`**
Chrome fires `beforeinstallprompt` before `DOMContentLoaded`, and certainly before React mounts and `useEffect` runs. Both `usePWAInstall` and `TopBar` registered their listeners inside `useEffect`, meaning they always missed the event. Fixed by adding a synchronous inline `<script>` at the top of `<head>` in `render_portal()` that captures the event at parse time and stores it on `window.__opbDeferredInstall`. The `usePWAInstall` hook now reads that stored event on mount.

**Critical — Duplicate competing install listeners**
`TopBar.tsx` had its own independent `beforeinstallprompt` handler with its own React state, racing against the `usePWAInstall` hook used in `Sidebar`. Removed the duplicate logic from `TopBar`; it now consumes `usePWAInstall` — single shared state, single event capture.

**Medium — `apple-touch-icon` served as SVG**
iOS Safari ignores SVG for touch icons. Changed to `icon-192.png`.

**Medium — Manifest missing `id` field**
Chrome 112+ uses `id` to uniquely identify a PWA. Added `"id": "/portal/"` to both the static `manifest.json` and the dynamic PHP `build_manifest()` method.

**Medium — Missing `Service-Worker-Allowed` header**
Added `Service-Worker-Allowed: /` response header when serving `/?opb_sw=1` via WordPress query parameter, confirming the SW may claim the `/portal/` scope.

**Files changed:**
- `plugin/includes/class-opb-portal.php` — early capture script, PNG touch icon, `Service-Worker-Allowed` header, manifest `id`
- `plugin/app/src/hooks/usePWAInstall.ts` — reads `window.__opbDeferredInstall` on mount
- `plugin/app/src/components/TopBar.tsx` — removed duplicate handler, uses `usePWAInstall`
- `plugin/assets/manifest.json` — added `"id": "/portal/"`

### Production Hardening

- Removed 20 `.catch(console.error)` instances across React source files. API error handlers now use `.catch(() => {})` to prevent console output in production browsers.
- Service worker cache key bumped to `opb-2.0.5` to force cache invalidation on all devices after this release.

### Build

- React application rebuilt from cleaned source (tsc + vite, 106 modules, 399 KB JS / 50 KB CSS)
- Production ZIP: `onukonu-pet-boarding-core-v2.0.5.zip` — 45.9 MB, 732 files

---

## v2.0.4 — Invoice Engine & MySQL 5.7 Fixes

**Commit:** `57ea7e1` → `f2f78f7`

### Invoice Engine (mPDF)

- mPDF PDF generation functioning
- PDF persistence via `doc_token`, `doc_generated_at`, `doc_generated_by`, `doc_pdf_path` columns in `opb_invoices`
- Public invoice summary page at `/opb-invoice/{64-char-token}/`
- PDF email attachment via `OPB_Notifications`
- WhatsApp invoice sharing via `wa.me` link
- Full audit trail in `opb_invoice_audit`

### MySQL 5.7 Migration Compatibility

Hostinger shared hosting runs MySQL 5.7. `ADD COLUMN IF NOT EXISTS` and `CREATE INDEX IF NOT EXISTS` are MariaDB / MySQL 8.0.3+ syntax and fail silently on MySQL 5.7.

- Replaced all conditional DDL with `INFORMATION_SCHEMA`-based helpers: `add_col()` and `idx_exists()`
- Both helpers are compatible with MySQL 5.6+ and safe to call on every activation or upgrade run
- `CREATE TABLE IF NOT EXISTS` is standard SQL and retained where appropriate

---

## v2.0.0 — Invoice Document Engine

- mPDF installed via Composer (`plugin/vendor/`)
- PDF generation from booking and invoice data
- Public invoice summary page infrastructure
- Push notification stubs added to service worker (not yet activated)

---

## v1.9.0 — Customization Module

- `opb_customizations` key/value table
- 22 configurable settings across Facility Info, Legal & T&C, Onboarding Messages, Inquiry Messages
- Template engine: `OPB_Customizations::render()` replaces `{{PLACEHOLDER}}` tokens
- Preview mode — render any template with sample data before saving
- Export endpoint — JSON snapshot of all settings
- Access control: read = any staff; write = `opb_manage_settings` / administrator

---

## v1.7.0 — Inquiry & Onboarding Pipeline

- 5 new DB tables: `opb_inquiries`, `opb_inquiry_notes`, `opb_onboarding_clients`, `opb_onboarding_pets`, `opb_onboarding_documents`
- Public inquiry form at `/opb-inquiry/`
- Multi-step onboarding portal at `/opb-onboard/{token}/`
- Staff inquiry management in React SPA
- WhatsApp onboarding link delivery
- Duplicate detection (phone + email cross-check)
- Convert to Client — explicit staff action only

---

## v1.0.x — Core Platform

- WordPress plugin bootstrap, role system, REST API base
- Clients, Pets, Bookings, Stays, Kennels, Occupancy Board, Occupancy Timeline
- Invoices, Payments, Expenses, Tasks, Reports
- Pricing Engine (`OPB_Pricing_Engine`)
- Portal (`/portal/`) — standalone React SPA inside WordPress
- Legacy data migration engine (XLSX/CSV adapters)
