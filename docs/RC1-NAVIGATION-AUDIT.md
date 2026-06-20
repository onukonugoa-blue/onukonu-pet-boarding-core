# OPB RC1 — Navigation & Route Audit Report

**Product:** Onukonu Pet Boarding Core (OPB)
**Release:** RC1
**Audit date:** 2026-06-20
**Plugin version:** 3.3.0

---

## Executive Summary

Three navigation defects were observed and corrected:

| # | Observed | Root Cause | Severity |
|---|---|---|---|
| 1 | Multiple WP Admin menu items open Dashboard instead of intended destination | All SPA-routed submenus registered as separate WP pages with `render()` callback; HashRouter read empty fragment → wildcard → Dashboard | **Critical** |
| 2 | SAL not visible in OPB sidebar | No SAL entry in `ALL_LINKS` in `Sidebar.tsx` | **High** |
| 3 | SAL WP Admin menu entry opens Dashboard | SAL submenu used `render()` callback — same defect as Issue 1 | **Critical** |

**All three defects corrected.**

---

## Section A — WordPress Admin Menu Registration Audit

### A.1 Pre-Fix State

**`class-opb-admin-page.php` — `register_menu()` before fix:**

| Registration | WP Slug | Label | Callback | Defect |
|---|---|---|---|---|
| `add_menu_page` | `opb-dashboard` | Pet Boarding | `render()` | — |
| `add_submenu_page` (loop) | `opb-dashboard` | Dashboard | `render()` | ✅ correct |
| `add_submenu_page` (loop) | `opb-clients` | Clients | `render()` | ❌ page loads at `?page=opb-clients` with no hash → Dashboard |
| `add_submenu_page` (loop) | `opb-pets` | Pets | `render()` | ❌ no React list route exists for `/pets` |
| `add_submenu_page` (loop) | `opb-bookings` | Bookings | `render()` | ❌ same hash defect |
| `add_submenu_page` (loop) | `opb-kennel` | Kennel Board | `render()` | ❌ same hash defect |
| `add_submenu_page` (loop) | `opb-invoices` | Invoices | `render()` | ❌ same hash defect |
| `add_submenu_page` (loop) | `opb-tasks` | Tasks | `render()` | ❌ same hash defect |
| `add_submenu_page` (loop) | `opb-expenses` | Expenses | `render()` | ❌ same hash defect |
| `add_submenu_page` (loop) | `opb-settings` | Settings | `render()` | ❌ same hash defect |
| `add_submenu_page` (loop) | `opb-import` | Import | `render()` | ❌ same hash defect |
| `add_submenu_page` | `opb-opsmail-queue` | OPSMAIL Queue | `render_opsmail_queue()` | ✅ PHP-rendered page, correct |
| `add_submenu_page` | `opb-sal` | SAL | `render()` | ❌ same hash defect |
| `add_submenu_page` | `opb-user-management` | User Management | `render_user_management()` | ✅ PHP-rendered page, correct |

**Root cause:** The SPA uses `HashRouter`. Routes are determined by the URL fragment (`#/route`). When WordPress loads `admin.php?page=opb-clients`, the hash fragment is empty (`#`). `HashRouter` sees no matching route, the wildcard `<Route path="*" element={<Navigate to="/" replace />} />` fires, and the SPA renders Dashboard. This affects every item that registers its own WP admin page with `render()`.

### A.2 Post-Fix State

**`class-opb-admin-page.php` — `register_menu()` after fix:**

| Registration | WP Slug / URL | Label | Mechanism |
|---|---|---|---|
| `add_menu_page` | `opb-dashboard` | Pet Boarding | Real WP page — SPA shell |
| `add_submenu_page` | `opb-dashboard` | Dashboard | Same slug as parent — renames auto-label |
| `add_submenu_page` | `…?page=opb-dashboard#/clients` | Clients | URL-as-slug direct link |
| `add_submenu_page` | `…?page=opb-dashboard#/bookings` | Bookings | URL-as-slug direct link |
| `add_submenu_page` | `…?page=opb-dashboard#/kennel` | Kennel Board | URL-as-slug direct link |
| `add_submenu_page` | `…?page=opb-dashboard#/invoices` | Invoices | URL-as-slug direct link |
| `add_submenu_page` | `…?page=opb-dashboard#/tasks` | Tasks | URL-as-slug direct link |
| `add_submenu_page` | `…?page=opb-dashboard#/expenses` | Expenses | URL-as-slug direct link |
| `add_submenu_page` | `…?page=opb-dashboard#/admin/data-management` | Data Management | URL-as-slug direct link |
| `add_submenu_page` | `…?page=opb-dashboard#/admin/opsmail` | OPSMAIL Queue | URL-as-slug direct link |
| `add_submenu_page` | `…?page=opb-dashboard#/admin/sal` | SAL | URL-as-slug direct link |
| `add_submenu_page` | `…?page=opb-dashboard#/settings` | Settings | URL-as-slug direct link |
| `add_submenu_page` | `…?page=opb-dashboard#/import` | Import | URL-as-slug direct link |
| `add_submenu_page` | `opb-opsmail-queue` | OPSMAIL Admin | PHP-rendered page — unchanged |
| `add_submenu_page` | `opb-user-management` | User Management | PHP-rendered page — unchanged |

**`opb-pets` removed** — no `/pets` list route exists in React. Pets are accessed through client profiles (`/clients/:id`).

### A.3 URL-as-Slug Mechanism

WordPress treats `add_submenu_page` slugs that begin with `https://` (i.e. full URLs) as direct navigation links. No separate admin page callback is registered. The menu item's `href` is set to the provided URL verbatim.

When the user clicks "Clients" in the WP Admin sidebar:
1. Browser navigates to `admin.php?page=opb-dashboard#/clients`
2. WordPress loads the `opb-dashboard` page → `render()` → `<div id="opb-root"></div>`
3. `opb_enqueue_admin_assets()` fires with `$hook = 'toplevel_page_opb-dashboard'` → `str_contains($hook, 'opb')` → `true` → SPA assets enqueued ✅
4. React mounts, `HashRouter` reads fragment `#/clients` → matches `<Route path="/clients">` → renders `ClientList` ✅

---

## Section B — React Router Configuration Audit

### B.1 Router Type

**`main.tsx`:** `HashRouter` from `react-router-dom`

Hash-based routing (`#/route`) is appropriate for WordPress plugin SPAs because:
- WordPress controls the real URL path (`admin.php?page=opb-dashboard`)
- Hash fragments are not sent to the server — WordPress never sees them
- Browser-managed history works correctly within WordPress Admin

### B.2 All Registered Routes

| Route Pattern | Component | Notes |
|---|---|---|
| `/` | `Dashboard` | Default / home |
| `/clients` | `ClientList` | — |
| `/clients/new` | `ClientForm` | — |
| `/clients/:id` | `ClientProfile` | — |
| `/clients/:id/edit` | `ClientForm` | — |
| `/clients/:clientId/pets/new` | `PetForm` | — |
| `/pets/:id` | `PetProfile` | Detail only — no list route |
| `/pets/:id/edit` | `PetForm` | — |
| `/bookings` | `BookingList` | — |
| `/bookings/new` | `BookingCreate` | — |
| `/bookings/:id` | `BookingDetail` | — |
| `/bookings/:id/checkin` | `CheckIn` | — |
| `/bookings/:id/checkout` | `CheckOut` | — |
| `/kennel` | `OccupancyBoard` | — |
| `/kennel/linear` | `LinearOccupancy` | — |
| `/invoices` | `InvoiceList` | — |
| `/invoices/:id` | `InvoiceDetail` | — |
| `/tasks` | `Tasks` | — |
| `/expenses` | `Expenses` | — |
| `/import` | `Import` | — |
| `/settings` | `Settings` | — |
| `/settings/branches` | `Branches` | — |
| `/settings/boarding` | `BoardingCatalogue` | — |
| `/settings/addons` | `AddonCatalogue` | — |
| `/settings/staff` | `Staff` | — |
| `/settings/kennels` | `KennelSettings` | — |
| `/settings/customization` | `Customization` | — |
| `/settings/expense-categories` | `ExpenseCategories` | — |
| `/inquiries` | `InquiryList` | — |
| `/inquiries/:id` | `InquiryDetail` | — |
| `/reports` | `Reports` | Wrapped in `ErrorBoundary` |
| `/admin/data-management` | `DataManagement` | — |
| `/admin/opsmail` | `OpsmailQueue` | — |
| `/admin/opsmail/gemini-lab` | `GeminiLab` | — |
| `/admin/sal` | `SalDashboard` | ✅ Route exists |
| `*` | `<Navigate to="/" replace />` | Wildcard fallback → Dashboard |

**SAL route status:** `/admin/sal` → `SalDashboard` ✅ — route was correctly defined in `App.tsx` throughout. The defect was in the sidebar entry (missing) and the WP Admin menu (wrong mechanism).

### B.3 Routes Without WP Admin Menu Entries

The following SPA routes are accessible within the SPA (e.g., from client profiles or breadcrumbs) but have no direct WP Admin sidebar entry. This is intentional:

| Route | Reason no top-level menu entry |
|---|---|
| `/clients/new`, `/clients/:id`, `/clients/:id/edit` | Sub-routes of Clients |
| `/clients/:clientId/pets/new`, `/pets/:id`, `/pets/:id/edit` | Sub-routes of client/pet flow |
| `/bookings/new`, `/bookings/:id`, `/bookings/:id/checkin`, `/bookings/:id/checkout` | Sub-routes of Bookings |
| `/kennel/linear` | Sub-view of Kennel Board |
| `/invoices/:id` | Sub-route of Invoices |
| `/inquiries/:id` | Sub-route of Inquiries |
| `/settings/branches`, `/settings/boarding`, etc. | Sub-routes of Settings |
| `/admin/opsmail/gemini-lab` | Accessed from within OPSMAIL Queue |

### B.4 Routes Accessible in SPA Sidebar but Not in WP Admin Menu

| SPA Route | SPA Sidebar | WP Admin Menu |
|---|---|---|
| `/inquiries` | ✅ Yes | ❌ Not present (accessible via SPA only) |
| `/reports` | ✅ Yes | ❌ Not present (accessible via SPA only) |
| `/admin/opsmail/gemini-lab` | ✅ Yes (`opb_super_admin`) | ❌ Not present (accessed from within OPSMAIL) |

These are not defects — they are intentionally SPA-only navigation targets.

---

## Section C — Sidebar Navigation Audit

### C.1 Pre-Fix `ALL_LINKS` State

| `to` | Label | Icon | Roles | Status |
|---|---|---|---|---|
| `/` | Dashboard | ⊞ | All | ✅ |
| `/clients` | Clients | 👥 | reception, branch_manager, super_admin | ✅ |
| `/bookings` | Bookings | 📋 | reception, branch_manager, super_admin | ✅ |
| `/kennel` | Kennel Board | 🏠 | reception, branch_manager, super_admin | ✅ |
| `/invoices` | Invoices | 🧾 | reception, branch_manager, super_admin | ✅ |
| `/inquiries` | Inquiries | 📩 | reception, branch_manager, super_admin | ✅ |
| `/tasks` | Tasks | ✓ | All | ✅ |
| `/expenses` | Expenses | 💰 | branch_manager, super_admin | ✅ |
| `/reports` | Reports | 📊 | branch_manager, super_admin | ✅ |
| `/admin/data-management` | Data Management | 🛡 | super_admin | ✅ |
| `/admin/opsmail` | OPSMAIL Queue | 📡 | super_admin | ✅ |
| `/admin/opsmail/gemini-lab` | Gemini Lab | 🤖 | super_admin | ✅ |
| `/admin/sal` | SAL | — | — | ❌ **MISSING** |
| `/settings` | Settings | ⚙ | super_admin | ✅ |
| `/import` | Import | 📥 | super_admin | ✅ |

**Root cause of Issue 2:** `/admin/sal` was never added to `ALL_LINKS`. The React route existed; the WP Admin menu entry existed (though broken); only the SPA sidebar entry was absent.

### C.2 Post-Fix `ALL_LINKS` State

SAL added between Gemini Lab and Settings:

```typescript
{ to: '/admin/sal', label: 'SAL', icon: '🛰', roles: ['opb_super_admin'] },
```

**Visibility condition:** `roles: ['opb_super_admin']` — SAL is visible only to `opb_super_admin`. This matches the capability used for the WP Admin menu entry (`manage_options`).

`getVisibleLinks()` logic:
- If user has `administrator` role → all links shown
- Otherwise → filter by `link.roles.some(r => roles.includes(r))`
- `opb_super_admin` role includes SAL → visible ✅
- Reception, Branch Manager, Staff roles do not include `opb_super_admin` → hidden ✅

---

## Section D — Fallback Routing Audit

**Wildcard route in `App.tsx`:**
```tsx
<Route path="*" element={<Navigate to="/" replace />} />
```

This is correct behaviour. It ensures that any unrecognised path (including broken internal links) redirects cleanly to Dashboard rather than rendering a blank page.

**Before the fix:** Every SPA-routed WP Admin submenu item landed here because `HashRouter` saw an empty fragment → no route matched → wildcard fired.

**After the fix:** All WP Admin submenu links include the correct hash fragment. The wildcard only fires if the fragment genuinely has no matching route.

**SAL navigation failure was caused by:** Route mismatch chain (no entry → no hash in URL → wildcard → Dashboard), not by the wildcard itself.

---

## Section E — Validation: Complete Routing Table

### E.1 WP Admin Menu → React Route → React Component

| WP Admin Menu Label | WP Admin URL | React Route | React Component |
|---|---|---|---|
| Pet Boarding (top-level) | `admin.php?page=opb-dashboard` | `/` | `Dashboard` |
| Dashboard | `admin.php?page=opb-dashboard` | `/` | `Dashboard` |
| Clients | `admin.php?page=opb-dashboard#/clients` | `/clients` | `ClientList` |
| Bookings | `admin.php?page=opb-dashboard#/bookings` | `/bookings` | `BookingList` |
| Kennel Board | `admin.php?page=opb-dashboard#/kennel` | `/kennel` | `OccupancyBoard` |
| Invoices | `admin.php?page=opb-dashboard#/invoices` | `/invoices` | `InvoiceList` |
| Tasks | `admin.php?page=opb-dashboard#/tasks` | `/tasks` | `Tasks` |
| Expenses | `admin.php?page=opb-dashboard#/expenses` | `/expenses` | `Expenses` |
| Data Management | `admin.php?page=opb-dashboard#/admin/data-management` | `/admin/data-management` | `DataManagement` |
| OPSMAIL Queue | `admin.php?page=opb-dashboard#/admin/opsmail` | `/admin/opsmail` | `OpsmailQueue` |
| SAL | `admin.php?page=opb-dashboard#/admin/sal` | `/admin/sal` | `SalDashboard` |
| Settings | `admin.php?page=opb-dashboard#/settings` | `/settings` | `Settings` |
| Import | `admin.php?page=opb-dashboard#/import` | `/import` | `Import` |
| OPSMAIL Admin | `admin.php?page=opb-opsmail-queue` | N/A (PHP-rendered) | `render_opsmail_queue()` |
| User Management | `admin.php?page=opb-user-management` | N/A (PHP-rendered) | `render_user_management()` |

### E.2 SPA Sidebar → React Route → React Component

| Sidebar Label | Route | Component | Roles |
|---|---|---|---|
| Dashboard | `/` | `Dashboard` | All |
| Clients | `/clients` | `ClientList` | reception, branch_manager, super_admin |
| Bookings | `/bookings` | `BookingList` | reception, branch_manager, super_admin |
| Kennel Board | `/kennel` | `OccupancyBoard` | reception, branch_manager, super_admin |
| Invoices | `/invoices` | `InvoiceList` | reception, branch_manager, super_admin |
| Inquiries | `/inquiries` | `InquiryList` | reception, branch_manager, super_admin |
| Tasks | `/tasks` | `Tasks` | All |
| Expenses | `/expenses` | `Expenses` | branch_manager, super_admin |
| Reports | `/reports` | `Reports` | branch_manager, super_admin |
| Data Management | `/admin/data-management` | `DataManagement` | super_admin |
| OPSMAIL Queue | `/admin/opsmail` | `OpsmailQueue` | super_admin |
| Gemini Lab | `/admin/opsmail/gemini-lab` | `GeminiLab` | super_admin |
| SAL | `/admin/sal` | `SalDashboard` | super_admin |
| Settings | `/settings` | `Settings` | super_admin |
| Import | `/import` | `Import` | super_admin |

### E.3 Additional Findings

| Finding | Status | Action Taken |
|---|---|---|
| `opb-pets` WP Admin menu entry — no matching React list route | Removed | Entry dropped from menu registration |
| `class-opb-loader.php` — dead legacy class with duplicate `add_menu_page` | Present but not loaded | `onukonu-pet-boarding-core.php` does not `require` this file; it registers nothing |
| `/inquiries` and `/reports` — SPA routes with no WP Admin menu entry | By design | No action (not reported as issues) |

---

## Acceptance Criteria — Verification

| Criterion | Status |
|---|---|
| Every WP Admin menu item opens the correct OPB page | ✅ All SPA items now use URL-with-hash mechanism |
| SAL appears in the OPB sidebar when permitted | ✅ Added to `ALL_LINKS` with `roles: ['opb_super_admin']` |
| SAL WP Admin menu entry opens SAL Dashboard | ✅ Now links to `admin.php?page=opb-dashboard#/admin/sal` |
| No menu item incorrectly redirects to Dashboard | ✅ All items now carry the correct hash fragment |
| Routing table documented | ✅ Section E above |
