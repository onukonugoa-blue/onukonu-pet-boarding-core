# PROJECT_AUDIT.md — Onukonu Pet Boarding Core

**Date:** 2026-05-30  
**Auditor:** Replit Agent  
**Status:** Brutally honest. No sugar-coating.

---

## 1. IMPLEMENTED MODULES (exist and structurally correct)

| Module | PHP API | React UI | Notes |
|--------|---------|----------|-------|
| Branches | ✅ `class-opb-branches-api.php` | ✅ `settings/Branches.tsx` | Full CRUD |
| Clients | ✅ `class-opb-clients-api.php` | ✅ `clients/ClientList.tsx`, `ClientProfile.tsx`, `ClientForm.tsx` | Full CRUD, pagination, search |
| Pets | ✅ (via clients API + pets API) | ✅ `pets/PetProfile.tsx`, `PetForm.tsx` | Full CRUD |
| Bookings | ✅ `class-opb-bookings-api.php` | ✅ `bookings/BookingList.tsx`, `BookingDetail.tsx`, `BookingCreate.tsx` | Full CRUD + check-in/check-out endpoints |
| Invoices | ✅ `class-opb-invoices-api.php` | ✅ `invoices/InvoiceList.tsx`, `InvoiceDetail.tsx` | List + detail |
| Payments | ✅ `class-opb-payments-api.php` | ❌ No dedicated payments page (handled inline in InvoiceDetail) | Endpoints exist |
| Tasks | ✅ `class-opb-tasks-api.php` | ✅ `Tasks.tsx` | Full CRUD |
| Expenses | ✅ `class-opb-expenses-api.php` | ✅ `Expenses.tsx` | Full CRUD |
| Dashboard | ✅ `class-opb-dashboard-api.php` | ✅ `Dashboard.tsx` | KPIs + today's movement |
| Import | ✅ `class-opb-import-api.php` | ✅ `Import.tsx` | CSV only (see §7) |
| Settings | ✅ `class-opb-settings-api.php` | ✅ `settings/Settings.tsx` | Boarding catalogue, addon catalogue, staff, branches |
| Reports | ✅ `class-opb-reports-api.php` | ✅ `Reports.tsx` | Exists |
| Occupancy Board | ✅ `/kennel-board` endpoint in bookings API | ✅ `OccupancyBoard.tsx` | Exists |
| Roles/Capabilities | ✅ `class-opb-roles.php` | N/A | Defined but **never registered** (see §5) |

---

## 2. MISSING MODULES

- **Payments page**: No standalone `/payments` route or React page. Payments are only accessible via invoice detail. This is acceptable for MVP but limits historical payment lookup.
- **Pet Documents upload UI**: `PetDocuments.tsx` exists in the filesystem but is not wired into any route in `App.tsx`.
- **WhatsApp integration**: `WhatsAppButton.tsx` component exists but there is no backend endpoint or template engine to drive it.

---

## 3. PLACEHOLDER MODULES

- **XLSX import reader** (`class-opb-import-api.php` line 78):  
  ```php
  $rows = $ext==='csv' ? $this->read_csv($path) : $this->read_csv($path);
  ```
  Both branches call `read_csv`. XLSX files are silently treated as CSV and will produce garbled output or empty rows. XLSX import is broken.

- **`import_bookings`** in the import engine maps very few legacy columns and does not create booking stays, invoice, or line items. Importing bookings produces incomplete records.

---

## 4. BROKEN MODULES

### 4a. React Application — Does Not Mount
**Error:** `Uncaught SyntaxError: redeclaration of non-configurable global property wp`  
**File:** `assets/dist/assets/index.js`

**Root cause:** Vite builds the React app as an ES module (default format). WordPress enqueues it via `wp_enqueue_script()` **without** `type="module"`. The browser loads it as a classic script, placing all top-level declarations into the global (`window`) scope. The Rollup minifier has assigned the variable name `wp` to a bundled module reference. WordPress already defines `window.wp` as a non-configurable property via `Object.defineProperty`. When the classic script tries to create `var wp` at global scope, the browser throws this error and the entire bundle crashes before `ReactDOM.createRoot()` is reached.

**Impact:** Every page in the WordPress admin is a blank `<div id="opb-root"></div>`.

### 4b. Database Tables — Not Created
**Symptom:** No `wp_opb_*` tables exist in MySQL after plugin activation.

**Root cause:** `register_activation_hook` fires **only once** on first plugin activation. If the plugin was installed (or the plugin directory was dropped in) before the current version of `class-opb-activator.php` existed, or if the plugin was network-activated on a multisite, or if it was activated during a request where constants weren't fully loaded — the hook may have fired without creating tables. There is **no version-check fallback** on `init` to re-run table creation if tables are absent.

Additionally, if the plugin was deactivated and reactivated, WordPress should re-fire the activation hook, but only if `register_activation_hook` can resolve `__FILE__` correctly to the installed plugin path. If the plugin lives in a symlinked directory (common in dev setups), this can silently fail.

### 4c. OPB Roles Never Registered
**Root cause:** `OPB_Roles::register()` is defined but is **never called**. There is no `add_action('init', [OPB_Roles::class, 'register'])` or equivalent in `onukonu-pet-boarding-core.php`. The four custom roles (`opb_super_admin`, `opb_branch_manager`, `opb_reception`, `opb_staff`) are never added to WordPress.

**Impact:** Staff users assigned OPB roles cannot log into the system. WordPress Administrators can still access the API because `has_opb_role()` falls back to `current_user_can('manage_options')`.

### 4d. Dashboard API — Invalid `$wpdb->prepare()` Call
**File:** `class-opb-dashboard-api.php`, line 74–80  
```php
$open_tasks = $wpdb->get_results($wpdb->prepare(
    "SELECT ... WHERE t.status!='Done'$task_where
     ORDER BY FIELD(t.priority,'High','Medium','Low'), t.due_date ASC
     LIMIT 5", $today   // ← $today passed but query has NO %s/%d placeholder
),ARRAY_A);
```
`$wpdb->prepare()` receives `$today` as an argument but the SQL string has no corresponding placeholder. In WordPress 5.9+, this triggers an `_doing_it_wrong()` notice and returns the query incorrectly. On strict installs it silently drops the extra argument; on some configs it corrupts the prepared statement.

---

## 5. ACTIVATION ISSUES

1. `register_activation_hook` — Only fires on first activation. No fallback version check.
2. `OPB_Roles::register()` — Never called from the plugin bootstrap. Roles are never created.
3. `OPB_Activator::create_tables()` is `private` — Cannot be called from outside the class for a re-run.

---

## 6. FRONTEND ISSUES

1. **ES module loaded as classic script** — Primary crash. See §4a.
2. **`window.OPB` undefined on crash** — Since the bundle crashes before executing, the `OPB` global passed by `wp_localize_script` is never consumed. API base URL and nonce are lost.
3. **`main.tsx` mounts to `#opb-root` with `!` (non-null assertion)** — If WordPress renders the page without `#opb-root` (e.g., on a screen where the callback is not fired), this throws a runtime error. Low risk but present.
4. **`PetDocuments.tsx` not routed** — File exists but no `<Route>` in `App.tsx`.
5. **Branch store initialises from `localStorage` before `window.OPB` is available** — Since the store is created at module load time and `window.OPB` is set by `wp_localize_script` in the HTML `<head>`, the ordering is fine at runtime. No bug currently, but fragile.

---

## 7. DATABASE ISSUES

1. **No tables exist** — See §4b.
2. **No foreign key constraints** — Tables use `KEY idx_*` indexes but no `FOREIGN KEY` declarations. `dbDelta` does not support foreign keys. Referential integrity is enforced only at the application layer (PHP). Orphan records are possible.
3. **`opb_booking_stays.boarding_service_id`** — References `opb_boarding_services.id` but there is no FK constraint. If a service is deleted, stays retain a dangling ID.
4. **`opb_invoice_line_items` has no `booking_stay_id`** — Line items are linked only to invoices, not to individual stays. This makes per-pet billing breakdowns harder to reconstruct.

---

## 8. REST API ISSUES

1. **OPB roles never created** — API permission checks that call `OPB_Roles::has_opb_role()` will deny all non-admin users.
2. **Dashboard open_tasks — extra `$wpdb->prepare()` argument** — See §4d.
3. **`class-opb-import-api.php`** — XLSX branch calls `read_csv`. Silent data corruption on XLSX uploads.
4. **`class-opb-import-api.php` `run()` method** — `test_type` set to `false` in overrides, meaning WordPress will accept any file type in the live run. The dry-run correctly limits to CSV/XLSX mime types, but the live run does not.
5. **No nonce validation on REST endpoints** — WordPress REST API handles nonce via the `X-WP-Nonce` header automatically when using `wp_create_nonce('wp_rest')`, which the plugin does. This is correct. ✅
6. **`get_dashboard` calls `$this->permission_check($r)` twice** — Once at the top of the method explicitly, and once implicitly through the `permission_callback`. Minor redundancy, not a bug.

---

## 9. BUILD ISSUES

1. **Vite `rollupOptions.input` uses absolute path `/src/main.tsx`** — This resolves relative to the filesystem root on POSIX systems. On some environments this fails. The correct value is `'./src/main.tsx'` or `path.resolve(__dirname, 'src/main.tsx')`.
2. **No `format: 'iife'` or `type="module"` — ES module bundle loaded as classic script** — Root cause of the React crash. See §4a and §6.
3. **`entryFileNames: 'assets/index.js'` has no content hash** — Cache busting relies entirely on the `?ver=OPB_VERSION` query string added by `wp_enqueue_script`. Acceptable.
4. **`assetFileNames: 'assets/[name][extname]'` also has no hash** — CSS file is `assets/main.css`, not `index.css` as assumed in the PHP fallback. The manifest-based lookup should resolve this correctly if the manifest is present.
5. **`plugin/app/node_modules`** — Not checked into the repo (correct). Running the build on a new machine requires `npm install` inside `plugin/app/` before `npm run build`.

---

## 10. DEPLOYMENT ISSUES

1. **Tables not created on deployment** — If the plugin is deployed to Hostinger by uploading files (not via WP Admin activate/deactivate cycle), `register_activation_hook` will not fire automatically. A version-check on `init` is the only reliable solution.
2. **No database migration strategy** — If `OPB_VERSION` is bumped in a future release, `create_tables` will be re-run via the version-check fix. `dbDelta` is additive (adds columns, does not remove them), so this is safe for schema additions but will not handle column renames or drops.
3. **`build.sh` not present at repo root** — Build must be triggered manually from inside `plugin/app/`. No CI/CD integration documented.
4. **`assets/dist/` is committed to the repo** — The built bundle (`index.js`, `main.css`) is checked in. This creates a stale-bundle risk if PHP changes are pushed without rebuilding.

---

## SUMMARY SCORECARD

| Area | Status |
|------|--------|
| PHP Plugin Structure | ✅ Sound |
| Database Schema | ✅ Well-designed, 13 tables |
| Database Creation | ❌ Broken (tables not created) |
| OPB Roles | ❌ Never registered |
| React App | ❌ Does not mount (ES module crash) |
| REST API Endpoints | ✅ All registered, structurally correct |
| REST API Permissions | ⚠️ Works for WP admins only (roles missing) |
| Dashboard API | ⚠️ Minor prepare() bug |
| Import Engine | ⚠️ CSV works, XLSX broken |
| Frontend Pages | ✅ All implemented (crash prevents rendering) |
| Build Configuration | ⚠️ Input path fragile, format mismatch |
