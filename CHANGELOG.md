# CHANGELOG.md — Onukonu Pet Boarding Core

---

## Recovery & Stabilization Sprint — 2026-05-30

### Critical Fixes

#### [FIX] React application now mounts — `plugin/admin/class-opb-admin-page.php`
- Added a `script_loader_tag` filter that injects `type="module"` on the `opb-app` script handle.
- **Root cause:** Vite builds ES modules by default. `wp_enqueue_script()` loads scripts as classic scripts. Without `type="module"`, all top-level variable declarations in the bundle enter the global (`window`) scope. The Rollup minifier had assigned the name `wp` to a bundled module reference, which collided with WordPress's non-configurable `window.wp` property, crashing the bundle before `ReactDOM.createRoot()` was reached.
- **Result:** React application now mounts correctly. All pages (Dashboard, Clients, Pets, Bookings, Invoices, etc.) are now accessible.

#### [FIX] Database tables created on every request until version matches — `plugin/onukonu-pet-boarding-core.php`, `plugin/includes/class-opb-activator.php`
- Added `opb_maybe_create_tables()` hooked to `init`. On every WordPress load, if `get_option('opb_db_version')` does not equal `OPB_VERSION`, `OPB_Activator::activate()` is called. `dbDelta` is additive and safe to run multiple times.
- Changed `create_tables()` visibility from `private` to `public static` to allow external calls.
- **Root cause:** `register_activation_hook` fires only once on first plugin activation. The hook had either already fired with an older codebase or silently failed. There was no fallback to re-run table creation.
- **Result:** All 13 `wp_opb_*` tables are created on next page load regardless of prior activation state.

#### [FIX] OPB roles now registered — `plugin/onukonu-pet-boarding-core.php`
- Added `add_action('init', [OPB_Roles::class, 'register'])`.
- **Root cause:** `OPB_Roles::register()` was defined but never called from the plugin bootstrap. The four custom roles (`opb_super_admin`, `opb_branch_manager`, `opb_reception`, `opb_staff`) were never added to WordPress.
- **Result:** OPB roles are registered. Non-admin staff can now be assigned OPB roles and access the system.

### Non-Critical Fixes

#### [FIX] Dashboard API — stray `$today` argument in `$wpdb->prepare()` — `plugin/includes/api/class-opb-dashboard-api.php`
- Removed the stray `$today` argument from the open tasks `get_results()` call.
- Refactored the open tasks query to use a named `$open_tasks_sql` variable (avoids double-prepare; `$task_where` is already safely prepared with the branch_id embedded inline).
- **Root cause:** `$wpdb->prepare()` was called with `$today` as a bound parameter but the SQL had no `%s`/`%d` placeholder for it. This triggered a `_doing_it_wrong()` notice in WordPress 5.9+ and could produce a malformed query.

#### [FIX] XLSX import returns clear error instead of silently misreading — `plugin/includes/api/class-opb-import-api.php`
- Replaced the broken ternary (`$ext==='csv' ? read_csv : read_csv`) with an explicit check.
- XLSX uploads now return a user-readable error: *"XLSX import is not yet supported. Please export your spreadsheet as CSV and re-upload."*
- **Root cause:** Both branches of the original ternary called `read_csv()`. XLSX binary data was being parsed as CSV, producing empty or garbled rows with no error shown.

#### [FIX] Vite build input path — `plugin/app/vite.config.ts`
- Changed `input: '/src/main.tsx'` (absolute filesystem path from root) to `input: path.resolve(__dirname, 'src/main.tsx')` (resolved relative to the config file).
- **Root cause:** An absolute path starting with `/` resolves from the filesystem root, which can silently fail or resolve incorrectly depending on the build host.

---

### Files Changed

| File | Change |
|------|--------|
| `plugin/onukonu-pet-boarding-core.php` | Added `opb_maybe_create_tables()` on `init`; added `OPB_Roles::register()` on `init` |
| `plugin/includes/class-opb-activator.php` | `create_tables()` changed from `private` to `public static` |
| `plugin/admin/class-opb-admin-page.php` | Added `script_loader_tag` filter to inject `type="module"` on `opb-app` |
| `plugin/includes/api/class-opb-dashboard-api.php` | Fixed stray `$today` in open tasks `prepare()` call |
| `plugin/includes/api/class-opb-import-api.php` | XLSX branch now returns explicit unsupported error |
| `plugin/app/vite.config.ts` | Fixed Rollup `input` path to use `path.resolve(__dirname, ...)` |

### Documents Added

| File | Description |
|------|-------------|
| `PROJECT_AUDIT.md` | Full brutally-honest audit of all modules, bugs, and issues |
| `FIX_PLAN.md` | Prioritised fix plan mapped to acceptance criteria |
| `CHANGELOG.md` | This file |
