# OPB RC1 — Version Consistency Audit Report

**Product:** Onukonu Pet Boarding Core (OPB)
**Release:** RC1
**Audit date:** 2026-06-19
**Plugin version:** 3.3.0

---

## Executive Summary

A version discrepancy was observed: the OPB application UI displayed `3.3.0` while WordPress Admin displayed `2.0.9`. This audit identifies the root causes, documents every version declaration in the repository, and records all corrective actions applied.

**Root causes identified: 3**

| # | Root Cause | Severity |
|---|---|---|
| 1 | `OPB_Admin_Page::enqueue_assets()` missing `version` in `wp_localize_script` — `window.OPB.version` is `undefined` in WP Admin context, triggering stale fallback | **Critical** |
| 2 | `Sidebar.tsx` fallback `'2.0.9'` is a stale hardcoded version baked into compiled dist | **High** |
| 3 | `SalDashboard.tsx` reads `window.opbData` (never set) instead of `window.OPB` — causes SAL Dashboard to always use unauthenticated API calls | **Critical** |

**All three issues have been corrected.**

---

## Phase 1 — Complete Version Inventory

### 1.1 PHP Version Declarations

| Source | Location | Version | Purpose |
|---|---|---|---|
| Plugin header | `onukonu-pet-boarding-core.php:6` | `3.3.0` | WordPress plugin screen display |
| `OPB_VERSION` constant | `onukonu-pet-boarding-core.php:18` | `3.3.0` | **Primary authoritative version** |
| Build script constant | `build-plugin-zip.js:9` | `3.3.0` | ZIP output filename |
| Build script constant | `build-rc1.js` | N/A — no version constant | RC1 ZIP (label-only, not version-pinned) |

### 1.2 PHP Version Reads and Writes

| Operation | Location | Option Key | Value Written | Purpose |
|---|---|---|---|---|
| Write | `OPB_Activator::create_tables()` | `opb_db_version` | `OPB_VERSION` | Schema migration gate |
| Read | `opb_maybe_create_tables()` | `opb_db_version` | Compared to `OPB_VERSION` | Triggers schema upgrade if mismatch |
| Write | `OPB_Roles::maybe_add_roles()` | `opb_roles_version` | `OPB_VERSION` | Role migration gate |
| Read | `OPB_Roles::maybe_add_roles()` | `opb_roles_version` | Compared to `OPB_VERSION` | Skips role setup if already at current version |
| Delete | `OPB_Roles::remove()` | `opb_roles_version` | — | Deactivation cleanup |
| Write | `OPB_Portal::register()` | `opb_rewrite_version` | `OPB_VERSION` | Rewrite flush gate |
| Read | `OPB_Portal::register()` | `opb_rewrite_version` | Compared to `OPB_VERSION` | Flushes rewrites once per version change |
| Read | `OPB_Health_API::get_health()` | `opb_db_version` | Compared to `OPB_VERSION` | Health check endpoint version alignment |

### 1.3 PHP → JavaScript Version Injection

| Context | Class | Method | Key passed | Value |
|---|---|---|---|---|
| Staff Portal | `OPB_Portal` | `enqueue_portal_assets()` | `version` | `OPB_VERSION` ✅ |
| WP Admin | `OPB_Admin_Page` | `enqueue_assets()` | **missing** ❌ → **fixed: `version`** | `OPB_VERSION` |

### 1.4 JavaScript / React Version Declarations

| Source | Location | Value | Purpose |
|---|---|---|---|
| SPA sidebar display | `Sidebar.tsx:135` | `window.OPB?.version ?? '–'` (was `'2.0.9'`) | Version badge in sidebar footer |
| SPA API client | `api/client.ts:14–15` | Reads `window.OPB?.apiBase`, `window.OPB?.nonce` | REST API base + nonce |
| SAL Dashboard | `SalDashboard.tsx:3–4` | `window.OPB?.apiBase` (was `window.opbData?.apiUrl`) | SAL API calls + nonce |
| React package | `plugin/app/package.json:4` | `"version": "1.0.0"` | SPA internal NPM metadata — **not a plugin version** |
| Customizations API response | `class-opb-customizations-api.php:143` | `plugin_version: OPB_VERSION` | Settings page info panel |
| Health API response | `class-opb-health-api.php:70` | `version: OPB_VERSION` | Portal health endpoint |

### 1.5 Build Metadata

| Source | Location | Value | Purpose |
|---|---|---|---|
| Script cache buster (portal) | `class-opb-portal.php:124` | `OPB_VERSION` | Browser cache invalidation on upgrade |
| Style cache buster (portal) | `class-opb-portal.php:134` | `OPB_VERSION` | Browser cache invalidation |
| Script cache buster (admin) | `class-opb-admin-page.php:471` | `OPB_VERSION` | Browser cache invalidation |
| Style cache buster (admin) | `class-opb-admin-page.php:483` | `OPB_VERSION` | Browser cache invalidation |
| PDF creator string | `class-opb-invoice-document.php:191` | `OPB_VERSION` | Invoice PDF metadata |
| Compiled dist (pre-fix) | `assets/dist/assets/index.js` | `"2.0.9"` embedded | **Stale baked-in fallback — purged by rebuild** |

---

## Phase 2 — Version Flow Analysis

```
plugin/onukonu-pet-boarding-core.php
│
├── Plugin Header: Version: 3.3.0
│     └── WordPress Plugins screen
│           └── WP Admin → Plugins → "3.3.0"
│
└── define('OPB_VERSION', '3.3.0')
      │
      ├── opb_db_version option
      │     ├── OPB_Activator::create_tables()   [write on activation/upgrade]
      │     ├── opb_maybe_create_tables()         [read on every init]
      │     └── OPB_Health_API                    [read for health check]
      │
      ├── opb_roles_version option
      │     └── OPB_Roles::maybe_add_roles()      [read + write on init]
      │
      ├── opb_rewrite_version option
      │     └── OPB_Portal::register()            [read + write on init]
      │
      ├── wp_localize_script → window.OPB.version
      │     ├── OPB_Portal (staff portal context)  [was correct ✅]
      │     └── OPB_Admin_Page (WP admin context)  [was missing ❌ → fixed ✅]
      │           └── Sidebar.tsx
      │                 └── v{window.OPB.version}  [was '2.0.9' fallback → now '–']
      │
      ├── wp_enqueue_script/style version param    [cache busting]
      │
      └── PDF creator string                       [invoice documents]
```

---

## Phase 3 — Database Version Audit

### Options Created by OPB

| Option name | Purpose | Set by | Read by | Expected value |
|---|---|---|---|---|
| `opb_db_version` | Schema migration gate | `OPB_Activator::create_tables()` | `opb_maybe_create_tables()`, `OPB_Health_API` | `OPB_VERSION` |
| `opb_roles_version` | Role migration gate | `OPB_Roles::maybe_add_roles()` | `OPB_Roles::maybe_add_roles()` | `OPB_VERSION` |
| `opb_rewrite_version` | Rewrite flush gate | `OPB_Portal::register()` | `OPB_Portal::register()` | `OPB_VERSION` |

All three option values are written from `OPB_VERSION`. If a stored value differs from `OPB_VERSION`, the migration/upgrade routine re-runs automatically on the next page load — no manual intervention required.

**No independent version constants exist in the database layer. All DB version gates derive from `OPB_VERSION`.**

---

## Phase 4 — Build Version Audit

### React SPA (`plugin/app/`)

The SPA does **not** embed the plugin version at build time. It receives version information at runtime through `window.OPB`, which is injected by PHP via `wp_localize_script`.

**Before fix:** `Sidebar.tsx` had `window.OPB?.version ?? '2.0.9'` — the `'2.0.9'` was a stale hardcoded fallback from when that line was first written. It was subsequently compiled into `assets/dist/assets/index.js`.

**After fix:** `window.OPB?.version ?? '–'` — the fallback `'–'` makes it immediately visible when `window.OPB` is not available (i.e., outside a WordPress context), rather than displaying a misleading version string.

**`plugin/app/package.json` `"version": "1.0.0"`** — this is the NPM package version for the SPA workspace only. It is not the plugin version and is not displayed anywhere in the UI. This value does not need to match `OPB_VERSION`.

---

## Phase 5 — Migration Audit

### Schema Migrations (`OPB_Activator`)

The migration gate is:
```php
if ( get_option('opb_db_version') !== OPB_VERSION ) {
    OPB_Activator::create_tables();
    update_option('opb_db_version', OPB_VERSION);
}
```

`create_tables()` uses:
- `CREATE TABLE IF NOT EXISTS` — idempotent
- `INFORMATION_SCHEMA` column checks — MySQL 5.7 compatible (no `ADD COLUMN IF NOT EXISTS`)
- `dbDelta()` from WP upgrade API

**Version drift risk: None.** If `OPB_VERSION` is bumped but `opb_db_version` lags, `create_tables()` runs again on next `init`. No migration path can be skipped.

### Role Migrations (`OPB_Roles`)

```php
if ( get_option('opb_roles_version') === OPB_VERSION ) return;
// Remove old roles, re-add all roles with current capabilities
update_option('opb_roles_version', OPB_VERSION);
```

**Version drift risk: None.** If roles are stale, the next `init` after activation/upgrade re-creates them from the current constants.

---

## Phase 6 — Version Normalization Plan

### Recommended Model (Implemented)

```
Plugin Header: Version: {X.Y.Z}
        ↕ (must be identical)
define('OPB_VERSION', '{X.Y.Z}')
        ↓
┌───────────────────────────────────────────────────┐
│  opb_db_version    (schema migration gate)        │
│  opb_roles_version (role migration gate)          │
│  opb_rewrite_version (rewrite flush gate)         │
│  wp_enqueue_script/style version param            │
│  wp_localize_script → window.OPB.version          │
│  PDF creator string                               │
│  REST health API response                         │
│  REST customizations API response                 │
└───────────────────────────────────────────────────┘
```

**Single rule:** To release a new version, change `Version: X.Y.Z` in the plugin header AND `define('OPB_VERSION', 'X.Y.Z')`. Everything else derives automatically.

`build-plugin-zip.js` has its own `const VERSION = '3.3.0'` — this is a build-tool-only constant and only affects the ZIP filename. It must be kept in sync manually (documented in the build notes and `MEMORY.md`).

---

## Phase 7 — Corrections Applied

### Fix 1 — Admin Page Missing `version` in `wp_localize_script`

**File:** `plugin/admin/class-opb-admin-page.php`

**Before:**
```php
wp_localize_script( 'opb-app', 'OPB', [
    'apiBase'   => rest_url( 'opb/v1' ),
    'nonce'     => wp_create_nonce( 'wp_rest' ),
    'adminUrl'  => admin_url( 'admin.php' ),
    'logoutUrl' => wp_logout_url( admin_url() ),
    'user'      => [ ... ],
] );
```

**After:**
```php
wp_localize_script( 'opb-app', 'OPB', [
    'apiBase'   => rest_url( 'opb/v1' ),
    'nonce'     => wp_create_nonce( 'wp_rest' ),
    'adminUrl'  => admin_url( 'admin.php' ),
    'logoutUrl' => wp_logout_url( admin_url() ),
    'version'   => OPB_VERSION,
    'user'      => [ ... ],
] );
```

**Effect:** `window.OPB.version` is now `'3.3.0'` in both the staff portal and the WP Admin page context. The sidebar displays `v3.3.0` in both contexts.

---

### Fix 2 — Sidebar Stale Hardcoded Version Fallback

**File:** `plugin/app/src/components/Sidebar.tsx:135`

**Before:** `window.OPB?.version ?? '2.0.9'`

**After:** `window.OPB?.version ?? '–'`

**Effect:** If `window.OPB` is not available (outside WordPress), the sidebar shows `v–` rather than a misleading stale version number. The stale `'2.0.9'` string is purged from the compiled dist on rebuild.

---

### Fix 3 — SAL Dashboard Wrong Global Variable

**File:** `plugin/app/src/pages/admin/SalDashboard.tsx:3–4`

**Before:**
```typescript
const API   = (window as any).opbData?.apiUrl ?? '/wp-json/opb/v1'
const nonce = (window as any).opbData?.nonce  ?? ''
```

**After:**
```typescript
const API   = (window as any).OPB?.apiBase ?? '/wp-json/opb/v1'
const nonce = (window as any).OPB?.nonce   ?? ''
```

**Effect:** SAL Dashboard now reads from `window.OPB` (populated by PHP in both portal and admin contexts). All SAL API calls are now authenticated with the correct WP REST nonce. Previously, every SAL Dashboard API call used an empty nonce and would return 401 Unauthorized.

---

## Phase 8 — RC1 Version Strategy

### Recommended Version: `3.3.0`

**Rationale:**

- Repository HEAD is `v3.3.0` in both the plugin header and `OPB_VERSION`
- RC1 is a stabilisation pass on top of `v3.3.0` — no new features added
- Bumping to `3.3.1-rc1` or `4.0.0-rc1` would require changing `OPB_VERSION`, which triggers all migration gates on next activation — unnecessary for a stabilisation-only pass
- The RC1 label is carried in the ZIP filename (`onukonu-pet-boarding-rc1.zip`), not in the version number
- This follows the pattern of WordPress core RCs (5.9-RC1 is still version 5.9 in the plugin header)

**Decision:** RC1 ships as `OPB_VERSION = '3.3.0'`. The release candidate designation lives in the ZIP filename only.

---

## Phase 9 — Validation

### After Rebuild

| Location | Expected value | Source |
|---|---|---|
| WordPress Plugins screen | `3.3.0` | Plugin header |
| OPB Sidebar (portal context) | `v3.3.0` | `window.OPB.version` from `OPB_Portal::enqueue_portal_assets()` |
| OPB Sidebar (admin context) | `v3.3.0` | `window.OPB.version` from `OPB_Admin_Page::enqueue_assets()` ✅ fixed |
| Outside WordPress (fallback) | `v–` | Fallback value ✅ fixed |
| SAL Dashboard API calls | Authenticated | `window.OPB.nonce` ✅ fixed |
| Health API response | `3.3.0` | `OPB_VERSION` via PHP |
| Customizations API response | `3.3.0` | `OPB_VERSION` via PHP |
| PDF invoice creator | `3.3.0` | `OPB_VERSION` via PHP |
| Compiled dist `index.js` | No `"2.0.9"` string | Rebuilt from fixed source |
| `onukonu-pet-boarding-rc1.zip` | Built from fixed source | `build-rc1.js` |

### Migration Validation

| Migration | Gate | Status |
|---|---|---|
| Schema (`opb_db_version`) | `OPB_VERSION` → auto-runs on mismatch | ✅ No change — still `OPB_VERSION` |
| Roles (`opb_roles_version`) | `OPB_VERSION` → auto-runs on mismatch | ✅ No change — still `OPB_VERSION` |
| Rewrites (`opb_rewrite_version`) | `OPB_VERSION` → auto-runs on mismatch | ✅ No change — still `OPB_VERSION` |

No migration paths affected. All gates continue to derive from `OPB_VERSION`.
