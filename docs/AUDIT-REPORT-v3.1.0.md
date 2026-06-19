# OPB v3.1.0 — Pre-Release Audit Report

**Audited:** 2026-06-19
**Auditor:** Automated codebase review
**Scope:** Full plugin source — PHP, React/TypeScript, build output

---

## Executive Summary

The v3.1.0 codebase is **production-ready**. One defect was identified and
fixed during this audit. No security issues, no dead production code paths,
no missing assets, no broken hooks.

---

## 1. Code Quality Audit

### 1.1 Debug statements

| Check | Result |
|---|---|
| `console.log` / `console.warn` / `console.error` in React | ✅ None found |
| `var_dump` / `print_r` in PHP | ✅ None found |
| `die()` / `exit()` (bare) in PHP | ✅ None (`wp_die()` used correctly for 404/500 HTTP responses) |
| `error_log()` in PHP | ✅ Present — all in exception/failure catch blocks only, all prefixed `[OPB ...]`, guarded by `Throwable` traps. Production-appropriate. |

### 1.2 Dead code

| Item | Status |
|---|---|
| `class-opb-loader.php` | Present but never `require_once`'d. Legacy scaffold from early development. Harmless; excluded from production ZIP. |
| `class-opb-woocommerce-adapter.php` | Architecture stub with all hooks commented out. Never loaded. Excluded from production ZIP. |
| `OPB_Woocommerce_Adapter::register_hooks()` body | All lines commented. No active hooks registered. |

### 1.3 TODO / FIXME / placeholder strings

| Check | Result |
|---|---|
| `TODO` / `FIXME` / `HACK` in PHP source | ✅ None in active code |
| `placeholder` strings | ✅ Only in HTML `<input placeholder="…">` attributes — correct usage |
| Hardcoded tokens / API keys / chat IDs | ✅ None found |
| Mock / dummy / fake data | ✅ None found |

---

## 2. Security Audit

### 2.1 REST permission callbacks

| API file | Permission model |
|---|---|
| All admin APIs (branches, clients, pets, bookings, etc.) | `manage_options` (super-admin) via `OPB_REST_Base::permission_manage()` |
| `class-opb-public-api.php` | `__return_true` — **intentional**. Handles public inquiry form submissions and onboarding. No sensitive data written without server-side validation. |
| `class-opb-client-relationship-api.php` | `__return_true` — **intentional**. Authentication enforced at handler level via OTP session cookie (`opb_client_session`). REST callback cannot validate cookies. |
| `class-opb-sal-api.php` | `manage_options` on all 7 endpoints ✅ |
| `class-opb-opsmail-api.php` | `manage_options` on all endpoints ✅ |
| `class-opb-health-api.php` | `manage_options` ✅ |

### 2.2 Input sanitization

| Check | Result |
|---|---|
| `$wpdb->prepare()` used for all dynamic SQL | ✅ Verified across all API files |
| `sanitize_text_field()` / `sanitize_email()` / `absint()` on request params | ✅ Present throughout |
| `esc_html()` / `esc_attr()` on output in PHP-rendered pages | ✅ Present in portal and admin page renderers |
| Nonce verification on admin AJAX (where used) | ✅ WP REST API nonce (`X-WP-Nonce`) used throughout React; validated by WP core |

### 2.3 Option storage

| Check | Result |
|---|---|
| Sensitive values (tokens, keys) stored in `wp_opb_customizations` table | ✅ Not in `wp_options` directly |
| No credentials echoed to frontend | ✅ Config endpoints return only boolean `_configured` flags, not raw values |

---

## 3. Structural Audit

### 3.1 Require completeness

All 24 API classes and all 16 core include classes are `require_once`'d in
the correct order in `onukonu-pet-boarding-core.php`.

**Order verified:**
1. `vendor/autoload.php` (mPDF / Composer)
2. Core classes (activator, deactivator, roles, pricing)
3. `class-opb-customizations.php` (must precede OPSMAIL)
4. `class-opb-opsmail.php`
5. `class-opb-telegram-consumer.php`
6. `class-opb-mailbox-processor.php`
7. SAL classes (snapshot → formatter → scheduler)
8. Portal, branding, client portal classes
9. All API classes
10. Admin page

### 3.2 Missing class files

| Check | Result |
|---|---|
| Any class used but file missing from `includes/` | ✅ None |
| Any `require_once` pointing to a non-existent file | ✅ None |

### 3.3 React assets

| Asset | Status |
|---|---|
| `assets/dist/assets/index.js` | ✅ Present (478 KB, 114 modules) |
| `assets/dist/assets/main.css` | ✅ Present (55 KB) |
| `assets/dist/.vite/manifest.json` | ✅ Valid — entry point `src/main.tsx` correctly mapped |

### 3.4 Vendor / Composer

| Check | Result |
|---|---|
| `vendor/autoload.php` present | ✅ |
| mPDF available | ✅ (`vendor/mpdf/`) |
| No dev-only Composer packages in vendor | ✅ (`composer install --no-dev` used for deployment) |

---

## 4. Cron Audit

### 4.1 Registered hooks

| Hook | Schedule | Interval | Handler |
|---|---|---|---|
| `opb_cron_process_mailbox` | `opb_mailbox_interval` | 1–60 min (configurable) | `OPB_Mailbox_Processor::process()` |
| `opb_cron_process_telegram` | `opb_telegram_interval` | 1 min (fixed) | `OPB_Telegram_Consumer::process_queue()` |
| `opb_cron_sal_check` | `opb_sal_hourly` | 60 min (fixed) | `OPB_SAL_Scheduler::check_and_run()` |

### 4.2 Conflicts

No hook name collisions. No schedule key collisions. All three hooks use
distinct action names and distinct schedule identifiers.

### 4.3 Deactivation cleanup — DEFECT FOUND AND FIXED

**Before fix:** `OPB_Deactivator::deactivate()` only called `flush_rewrite_rules()`.
Cron hooks remained registered in `_get_cron_array()` after plugin deactivation.

**After fix:** All three hooks are cleared via `wp_clear_scheduled_hook()` on
deactivation. Data tables are NOT dropped (intentional — data preservation policy).

### 4.4 Duplicate scheduling protection

`opb_maybe_schedule_cron()` and `OPB_SAL_Scheduler::maybe_schedule()` both
check `wp_next_scheduled()` before calling `wp_schedule_event()`. No duplicate
events can be created.

---

## 5. Database Migration Audit

### 5.1 Activation path

On fresh install: `register_activation_hook` → `OPB_Activator::activate()` →
`create_tables()` → all tables created including `opb_sal_brief_history`.

### 5.2 Upgrade path

On upgrade from any prior version: `opb_maybe_create_tables()` fires on `init`,
detects `opb_db_version !== OPB_VERSION`, calls `create_tables()`. All new
migrations are idempotent:

- `CREATE TABLE IF NOT EXISTS` for new tables ✅
- `self::add_col()` uses `INFORMATION_SCHEMA` check for column additions ✅
- `MODIFY COLUMN` on ENUM guarded by current column type read ✅
- No `DROP TABLE`, no `TRUNCATE`, no destructive DDL ✅

### 5.3 MySQL 5.7 compatibility

- No `ADD COLUMN IF NOT EXISTS` (MariaDB/MySQL 8.0.3+ only) ✅
- No `CREATE INDEX IF NOT EXISTS` ✅
- All column additions use `self::add_col()` which checks `INFORMATION_SCHEMA` ✅

---

## 6. Feature Verification Matrix

| Feature | Code path | Status |
|---|---|---|
| Queue system | `OPB_Opsmail::push_event()` → `opb_opsmail_queue` | ✅ |
| Event generation | OPB action hooks → `OPB_Opsmail::push_event()` | ✅ |
| Telegram integration | `OPB_Telegram_Consumer::send_telegram()` | ✅ |
| Telegram consumer | `OPB_Telegram_Consumer::process_queue()` via cron | ✅ |
| Telegram scheduler | `opb_cron_process_telegram` every 1 min | ✅ |
| Gemini integration | `OPB_Mailbox_Processor::classify()` + `OPB_SAL_Formatter::call_gemini()` | ✅ |
| Gemini Lab | `POST /opsmail/process-text` → `OPB_Mailbox_Processor::process_text()` | ✅ |
| Queue diagnostics | `GET /opsmail/stats` | ✅ |
| Telegram diagnostics | `GET /opsmail/diagnostics` | ✅ |
| SAL Morning Brief | `OPB_SAL_Scheduler::run_brief('morning')` | ✅ |
| SAL Evening Brief | `OPB_SAL_Scheduler::run_brief('evening')` | ✅ |
| SAL Accounts Snapshot | `OPB_SAL_Scheduler::run_brief('accounts')` | ✅ |
| SAL schedule engine | `opb_cron_sal_check` hourly + time/date guard | ✅ |
| SAL preview mode | `POST /sal/generate` (no delivery) | ✅ |
| SAL Gemini formatting | `OPB_SAL_Formatter::call_gemini()` + deterministic fallback | ✅ |
| SAL queue integration | `OPB_SAL_Scheduler::queue_brief()` → `opb_opsmail_queue` | ✅ |
| SAL Telegram delivery | `OPB_Telegram_Consumer::send_telegram_to()` | ✅ |
| SAL brief history | `OPB_SAL_Scheduler::log_brief()` → `opb_sal_brief_history` | ✅ |

---

## 7. Defect Log

| ID | Severity | Description | Resolution |
|---|---|---|---|
| DEF-001 | Medium | `OPB_Deactivator::deactivate()` did not clear SAL or OPSMAIL cron hooks | Fixed — `wp_clear_scheduled_hook()` called for all 3 hooks |

No other defects found.

---

## 8. Production Build Exclusions

The production ZIP (`opsmail-production-v3.1.0.zip`) excludes:

- `app/` — React source files (only compiled `assets/dist/` is needed)
- `tests/` — test fixtures
- `vendor/bin/` — Composer CLI tools
- `class-opb-loader.php` — legacy scaffold, never loaded
- `class-opb-woocommerce-adapter.php` — stub, never loaded
- `package.json`, `package-lock.json`, `tsconfig.json`, `vite.config.ts` — build tooling
- `composer.json`, `composer.lock` — Composer manifests
- `.map` files — source maps
- `.DS_Store`, `Thumbs.db`, `.editorconfig`, `.gitignore`, etc.

**Verdict: APPROVED FOR PRODUCTION DEPLOYMENT**
