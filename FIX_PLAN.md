# FIX_PLAN.md — Onukonu Pet Boarding Core Recovery Sprint

**Date:** 2026-05-30  
**Priority order follows sprint spec.**

---

## FIX 1 — Database Table Creation (CRITICAL)

**Problem:** Tables are not created because:
- `register_activation_hook` already fired (or silently failed) and there is no re-run guard.
- `OPB_Activator::create_tables()` is `private` — cannot be called externally.

**Fix:**
1. Make `create_tables()` `public static`.
2. Add `add_action('init', ...)` in the main plugin file to call `OPB_Activator::activate()` if `get_option('opb_db_version')` does not match `OPB_VERSION`.
3. This uses `dbDelta` which is additive and safe to run repeatedly.

**Files:** `plugin/includes/class-opb-activator.php`, `plugin/onukonu-pet-boarding-core.php`

---

## FIX 2 — OPB Roles Registration (CRITICAL)

**Problem:** `OPB_Roles::register()` is never called. Non-admin OPB staff cannot access any endpoint.

**Fix:**
- Add `add_action('init', [OPB_Roles::class, 'register'])` in the main plugin file.

**Files:** `plugin/onukonu-pet-boarding-core.php`

---

## FIX 3 — React App Mounts (CRITICAL — ES Module crash)

**Problem:** Vite builds ES modules. `wp_enqueue_script` loads them as classic scripts. Minified variable `wp` collides with `window.wp` (non-configurable). Bundle crashes before `ReactDOM.createRoot()`.

**Fix:**
- Add a `script_loader_tag` filter in `OPB_Admin_Page::enqueue_assets()` that injects `type="module"` on the `opb-app` handle.
- This tells the browser to treat the bundle as an ES module, scoping all variable declarations inside the module and eliminating the `window.wp` collision.

**Files:** `plugin/admin/class-opb-admin-page.php`

---

## FIX 4 — Dashboard Rendering (wpdb::prepare bug)

**Problem:** `$wpdb->prepare()` receives `$today` as an argument but the open_tasks query has no placeholder for it. Triggers `_doing_it_wrong()` and may return a malformed query.

**Fix:**
- Remove the stray `$today` argument from the `get_results(prepare(...))` call for open_tasks.

**Files:** `plugin/includes/api/class-opb-dashboard-api.php`

---

## FIX 5 — Clients Module

**Status:** Structurally complete. Will work once Fix 1 (tables) and Fix 3 (React) are applied.  
No additional code changes required.

---

## FIX 6 — Pets Module

**Status:** Structurally complete. Will work once Fix 1 (tables) and Fix 3 (React) are applied.  
No additional code changes required.

---

## FIX 7 — Bookings Module

**Status:** Structurally complete. Will work once Fix 1 (tables) and Fix 3 (React) are applied.  
No additional code changes required.

---

## FIX 8 — Occupancy Board

**Status:** Endpoint (`/kennel-board`) exists in `OPB_Bookings_API`. React page exists.  
Will work once Fix 1 (tables) and Fix 3 (React) are applied.  
No additional code changes required.

---

## FIX 9 — Invoices

**Status:** Structurally complete. Will work once Fix 1 (tables) and Fix 3 (React) are applied.  
No additional code changes required.

---

## FIX 10 — Payments

**Status:** API endpoints exist. No standalone UI page (handled via InvoiceDetail).  
Will work once Fix 1 (tables) and Fix 3 (React) are applied.  
No additional code changes required.

---

## FIX 11 — Import Engine (XLSX placeholder)

**Problem:** XLSX branch in `parse_file()` calls `read_csv()` instead of an XLSX reader. PHP has no native XLSX parser without a library (e.g., PhpSpreadsheet). Adding PhpSpreadsheet to the plugin is out of scope for this sprint.

**Fix (minimal):**
- Make the XLSX branch return a clear error response instead of silently reading garbled data.
- Document that XLSX import requires export to CSV first.

**Files:** `plugin/includes/api/class-opb-import-api.php`

---

## ACCEPTANCE CRITERIA MAPPING

| Criterion | Fix | Status after fixes |
|-----------|-----|--------------------|
| OPB tables created | Fix 1 | ✅ |
| Dashboard renders | Fix 1 + Fix 3 + Fix 4 | ✅ |
| React application mounts | Fix 3 | ✅ |
| Clients screen loads | Fix 1 + Fix 3 | ✅ |
| REST endpoints respond | Fix 1 + Fix 2 | ✅ |
| No JavaScript startup errors | Fix 3 | ✅ |
