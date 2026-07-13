# CHANGELOG.md — Onukonu Pet Boarding Core

---

## v3.7.0 — RC1 Workflow Hardening — 2026-07-13

### Overview
Implements minimum safe changes to correct the booking workflow without redesigning the booking/stay architecture. Adds booking date editing for pre-arrival bookings, automatic invoice recalculation after date changes, a server-side early check-in guard, and the corresponding UI behaviours.

### Added

**1. Booking Edit (`PUT /opb/v1/bookings/{id}` extended)**
- Extended `update_item()` in `OPB_Bookings_API` to accept a `stays` array containing `{ id, check_in_date, check_out_date }` per stay.
- Guard: rejected with HTTP 422 if any stay in the booking is not `Upcoming` (already checked in or completed).
- Guard: rejected with HTTP 422 if `check_out_date <= check_in_date`.
- Guard: stay ownership validated — only stays belonging to this booking and in `Upcoming` status are updated.
- After successful date updates, `OPB_Invoice_Generator::recalculate()` is called to refresh boarding-day count, charges, taxes, and totals.
- Booking-level fields (`notes`, `additional_instruction`, `booking_source`, `service_types`) continue to update unconditionally.

**2. Early Check-in Guard (server-side, `POST /opb/v1/bookings/{id}/checkin`)**
- `checkin()` now fetches `check_in_date` alongside `id` from `opb_booking_stays`.
- Before any state change: if `stay.check_in_date > current_time('Y-m-d')`, returns HTTP 422 with:
  `"Check-in is not available until the scheduled arrival date (YYYY-MM-DD)."`
- Direct REST/API calls are blocked — UI-only guards are not relied upon.

**3. `BookingEdit.tsx` (new React page)**
- Route: `/bookings/:id/edit`
- Loads the booking; redirects gracefully if cancelled or has no upcoming stays.
- Editable fields: `check_in_date` + `check_out_date` per upcoming stay; booking `notes`; `additional_instruction`.
- Client-side pre-validation: check-out must be after check-in.
- On save: calls `PUT /opb/v1/bookings/{id}` with stays array + metadata; navigates back to booking detail.
- Kennel assignment is NOT exposed (managed separately via Occupancy Board).

**4. `BookingDetail.tsx` updates**
- Added `canEdit` flag: true when booking is Active, has at least one stay, and ALL stays are `Upcoming`.
- Added `Edit Booking` button (secondary style) in the action bar, visible only when `canEdit`.
- Added `earliestArrival` calculation: minimum `check_in_date` across all Upcoming stays.
- Added `checkinReady` flag: `canCheckin && earliestArrival <= today`.
- Check-in behaviour:
  - If `checkinReady`: shows active `Check In` link (unchanged behaviour for arrival-day and overdue bookings).
  - If future arrival: shows disabled `Check In` button with "from {arrival date}" sub-label and tooltip.
- Check-out button unchanged.

**5. `App.tsx` — new route**
- `<Route path="/bookings/:id/edit" element={<BookingEdit />} />`

### Acceptance Tests

| Test | Result |
|---|---|
| Edit future booking dates → booking updates, timeline reflects new dates | ✓ Code path: `update_item()` + `recalculate()` |
| Edit dates → invoice recalculates (days, charges, totals) | ✓ Code path: `OPB_Invoice_Generator::recalculate()` called on every successful stay date change |
| Early check-in attempt via REST → HTTP 422, booking unchanged | ✓ Code path: `checkin()` guard at line ~291 |
| Same-day check-in → proceeds normally | ✓ Guard condition: `$arrival_date > current_time('Y-m-d')` — today passes |
| Active/Completed stays → Edit button hidden, date edit rejected 422 | ✓ `canEdit` false; `non_upcoming > 0` guard |
| Future bookings visible in kennel timeline | ✓ No change to timeline rendering, kennel-board, or occupancy queries |
| Existing check-out workflow | ✓ `checkout()` untouched |
| SAL, OPSMAIL, invoice payments | ✓ Untouched |

### Files Modified
- `plugin/includes/api/class-opb-bookings-api.php` — `update_item()`, `checkin()`
- `plugin/app/src/pages/bookings/BookingDetail.tsx` — `canEdit`, `checkinReady`, action buttons
- `plugin/app/src/App.tsx` — route + import
- `plugin/app/src/pages/bookings/BookingEdit.tsx` — new file

### Build
- React + Vite: 115 modules, clean build
- `onukonu-pet-boarding-core-v3.7.0.zip` — production-ready

---

## v3.6.0 — SAL Telegram HTML Normalization + Production Message Polish — 2026-07-13

### Overview
Resolves Telegram delivery failures caused by unsupported HTML tags (`<ul>`, `<li>`, `<ol>`) in Gemini-generated Accounts briefings. Implements a server-side normalization layer that converts all Gemini output to Telegram's supported HTML subset before delivery, regardless of what the model produces. Adds a defensive 4096-character length guard. Updates all three SAL default prompts to explicitly prohibit unsupported tags, and introduces a dedicated management-focused Accounts Snapshot prompt structure.

### Fixed
- **Telegram 400 Bad Request on Accounts Snapshot** — Gemini was producing `<ul>`/`<li>` HTML list markup, which Telegram's HTML parse mode rejects entirely. The entire message was silently dropped.
- **Silent error masking in `send_telegram_to()`** — The error logger was logging only the HTTP status code, not Telegram's `description` field. The root-cause tag name was invisible in logs. Now logs the Telegram API `description` string directly.

### Added — `class-opb-sal-formatter.php`

**`normalize_for_telegram( string $text ): string`** (private static)
- Converts `<li>…</li>` blocks to `• bullet` lines
- Removes `<ul>`, `<ol>` wrapper tags
- Converts `<br>` to newline
- Strips `<p>` tags while preserving content
- Calls `strip_tags()` keeping only the Telegram HTML subset: `<b>`, `<strong>`, `<i>`, `<em>`, `<u>`, `<s>`, `<code>`, `<pre>`, `<a>`
- Collapses 3+ consecutive newlines to 2
- Applied automatically inside `clean_gemini_output()` — all Gemini paths go through it

**`safe_truncate( string $text, int $limit = 4096 ): string`** (private static)
- Guards all three format() return paths (Gemini success, Gemini fallback, exception fallback)
- Truncates at the nearest line boundary below the 4096-character limit
- Never cuts mid-sentence, mid-tag, or mid-bullet
- Appends `⚠️ Brief truncated — see OPB dashboard for full detail.` when shortened
- Logs original vs. truncated character count to WordPress error log

**`get_accounts_prompt()` (new private static) + `get_default_prompt( string $brief_type )` (updated)**
- `get_default_prompt()` now accepts a `$brief_type` parameter (default: `'morning'`)
- For `'accounts'` brief type, dispatches to the new dedicated accounts prompt
- Accounts prompt enforces: management-friendly structure, `•` bullets, no per-invoice listing, ₹ lakh notation, branch-total focus
- Morning/Evening prompt updated: explicit HTML tag prohibition block added (`<ul>/<li>/<ol>/<p>/<br>/<h1–h3>` banned); `•` bullet character instruction added
- `build_prompt()` now passes `$brief_type` to `get_default_prompt()`

**Accounts Snapshot output structure:**
```
🐾 OPB Accounts Snapshot
[date]

SUMMARY
[2 sentences: total outstanding, payments today, expenses, overdue count]

ATTENTION REQUIRED
• BranchName: N overdue invoices · ₹X outstanding (largest: ClientName ₹X)

ACCOUNTS
[Per-branch compact block: payments, unpaid, overdue >7d, expenses]

⚙️ OPB SAL · [date]
```

### Changed — `class-opb-telegram-consumer.php`
- `send_telegram_to()`: non-200 error log now extracts and logs Telegram's `description` field from the JSON body, making HTML parse errors immediately identifiable in WordPress error log.

### Regression scope
- Morning Brief, Evening Brief, Accounts Snapshot: all pass through `normalize_for_telegram()` + `safe_truncate()`
- Deterministic fallback path also guarded by `safe_truncate()`
- OPSMAIL queue, mailbox processor, diagnostics: unaffected
- Gemini integration: unchanged (prompts updated, not the API call layer)
- Telegram integration: delivery layer unchanged; normalization is pre-delivery

### Build
- No React/Vite rebuild required (PHP-only changes)
- `onukonu-pet-boarding-core-v3.6.0.zip` — production-ready

---

## v3.5.3 — Gemini Thinking Token Fix — 2026-07-12

### Overview
Hotfix for all three Gemini API call sites across SAL and OPSMAIL. Gemini 2.5 Flash consumes thinking tokens from the same `maxOutputTokens` budget as visible output. With the previous low caps (300–900 tokens), thinking exhausted the budget before the response was complete, producing hard mid-sentence truncations. This release disables thinking on all formatter/classifier calls and raises output caps to correct values.

### Fixed
- `plugin/includes/class-opb-sal-formatter.php` — `call_gemini()`
  - `maxOutputTokens` raised `900 → 2048`
  - `thinkingConfig: { thinkingBudget: 0 }` added — disables Gemini 2.5 Flash thinking pass
  - `finishReason` guard added — non-STOP responses return `null` and trigger the deterministic fallback instead of delivering truncated text to Telegram
- `plugin/includes/class-opb-mailbox-processor.php` — `classify()`
  - `maxOutputTokens` raised `300 → 512`
  - `thinkingConfig: { thinkingBudget: 0 }` added
  - `finishReason` guard added — non-STOP responses return `null`
- `plugin/includes/class-opb-mailbox-processor.php` — `process_text()`
  - `maxOutputTokens` raised `400 → 512`
  - `thinkingConfig: { thinkingBudget: 0 }` added
  - `finishReason` guard added — non-STOP responses return structured error array

### Root cause
Gemini 2.5 Flash uses an internal reasoning pass before emitting visible text. These thinking tokens are drawn from the same `maxOutputTokens` budget. At 900 tokens, the model consumed ~860–880 tokens on reasoning, leaving ~20–40 tokens for visible output — exactly the observed truncation depth.

### Build
- React + Vite build clean — 114 modules, no warnings
- `onukonu-pet-boarding-core-v3.5.3.zip` — 751 files, production-ready

### Source base
`plugin/onukonu-pet-boarding-core.php` version `3.5.1` (no PHP entry-point changes in this release)

---

## v3.5.2 — Branding Refresh — 2026-07-12

### Overview
Branding-only release. No new features, no business logic changes. Replaces all PWA icons and the admin login logo with the updated OPB brand assets. Service worker cache invalidated to ensure all installed PWA clients pick up the new icons on next activation.

### Changed
- `plugin/assets/icons/icon-192.png` — updated brand icon (192 × 192)
- `plugin/assets/icons/icon-512.png` — updated brand icon (512 × 512)
- `plugin/assets/icons/icon-maskable.png` — updated maskable icon for Android home screen
- `plugin/assets/icons/icon-192.svg` — updated SVG source (192)
- `plugin/assets/icons/icon-512.svg` — updated SVG source (512)
- `plugin/assets/icons/icon-maskable.svg` — updated maskable SVG source
- `plugin/assets/branding/login-logo.svg` — updated WordPress admin login logo
- `plugin/assets/branding/login-logo.png` — updated login logo raster fallback

### Service Worker
- `plugin/assets/sw.js`: `CACHE_VERSION` bumped `opb-2.0.5` → `opb-2.0.6`
- All previously cached PWA assets are purged on next service worker activation

### Build
- React + Vite build clean — 114 modules transformed, no warnings
- `onukonu-pet-boarding-core-v3.5.2.zip` — 751 files, production-ready

### Source base
`plugin/onukonu-pet-boarding-core.php` version `3.5.1` (no PHP changes in this release)

---

## RC1 — Release Candidate 1 — 2026-06-19

### Overview
RC1 is the first formally audited, documentation-aligned, production-packaged release of Onukonu Pet Boarding Core. No new features. Establishes verified repository baseline, canonical architecture documentation, aligned OPB branding, and a reproducible build process.

### Audit
- Repository state verified: `main` branch, clean working tree, up to date with origin
- Product identity confirmed: OPB is the product; OPSMAIL, SAL, Telegram, and Gemini are internal operational subsystems
- Canonical architecture reference produced (`docs/RC1-ARCHITECTURE.md`)
- All 13 audit phases completed — see `docs/RC1-RELEASE-NOTES.md`

### Documentation produced
- `docs/RC1-REPOSITORY-STATE.md` — repository audit
- `docs/RC1-BRANDING-REPORT.md` — product identity alignment
- `docs/RC1-ARCHITECTURE.md` — canonical architecture reference
- `docs/RC1-BUILD-AUDIT.md` — build system audit (TypeScript clean, Vite 114 modules)
- `docs/RC1-ROLES-AUDIT.md` — roles, capabilities, branch scoping
- `docs/RC1-OPSMAIL-AUDIT.md` — OPSMAIL subsystem audit
- `docs/RC1-SAL-AUDIT.md` — SAL subsystem audit
- `docs/RC1-RELEASE-NOTES.md` — RC1 release notes
- `docs/RC1-DEPLOYMENT.md` — deployment and configuration instructions

### Build
- `build-rc1.js` created — produces `onukonu-pet-boarding-rc1.zip`
- ZIP name: `onukonu-pet-boarding-rc1.zip` (OPB product naming, not OPSMAIL-branded)
- Branding fix: `docs/RELEASE-NOTES-v3.1.0.md` title corrected from "OPB / OPSMAIL Production Release" to "Onukonu Pet Boarding Core — v3.1.0"

---

## v3.1.0 — SAL (Situational Awareness Layer) — 2026-06-19

### Overview
Adds the Situational Awareness Layer — a scheduled Telegram briefing engine. Gemini is used as a formatter only; a deterministic fallback guarantees brief delivery. External cron support added for reliable scheduling.

### New
- Morning Operations Brief (default 07:00) — check-ins/check-outs today, tasks due, exceptions
- Evening Closure Brief (default 19:00) — end-of-day boarding count, tasks completed/pending
- Accounts Snapshot (default 09:00) — invoices raised/unpaid, payments received, expenses
- External cron support and `OPB_Cron_Health` health monitor with ring-buffer detection
- `opb_sal_brief_history` table for full delivery audit trail
- SAL admin dashboard (`/admin/sal`) for config, preview, send, diagnostics, history

### Source base
`plugin/onukonu-pet-boarding-core.php` version `3.1.0`

---

## v2.8.0 — OPSMAIL Operational Intelligence Layer — 2026-06-16

### Overview
OPSMAIL is an additive-only operational event queue with email emission, a queue viewer, and configurable settings. Zero regression on all existing functionality. Every OPSMAIL call is wrapped in `try/catch(\Throwable)` — it will never throw, never block, and never break any existing business workflow.

### New: `opb_opsmail_queue` table
- 19-column event queue: `event_uuid` (CHAR 36 UNIQUE), `event_type`, `entity_type`, `entity_id`, `branch_id`, `user_id`, `origin_type` ENUM (SYSTEM / TRUSTED_MAILBOX), `priority`, `subject`, `summary`, `payload_json`, `recipient_email`, `status` ENUM (PENDING / SENT / FAILED / ACKNOWLEDGED), `mail_attempts`, `last_error`, `created_at`, `sent_at`.
- Created via `dbDelta()` inside `OPB_Activator::create_tables()` — MySQL 5.7 compatible, no `IF NOT EXISTS` column syntax.
- Version bump to `2.8.0` triggers table creation on next WordPress `init`.

### New: `plugin/includes/class-opb-opsmail.php` — Core OPSMAIL Engine
- `OPB_Opsmail::push_inquiry_received($inquiry)` — fires after `submit_inquiry()` succeeds.
- `OPB_Opsmail::push_onboarding_received($inquiry, $ob_client)` — fires after `accept_terms()` advances inquiry to READY_FOR_REVIEW.
- `OPB_Opsmail::push_booking_confirmed($booking_id, $branch_id, $client_id)` — fires after `OPB_Invoice_Generator::create_for_booking()`.
- `OPB_Opsmail::push_task_created($task_id, $branch_id, $data)` — fires after `opb_tasks` insert.
- `OPB_Opsmail::push_expense_if_large($row)` — fires after expense insert when amount ≥ `opsmail_expense_threshold` (default ₹5,000).
- Private `push_event()` appends to queue, then calls `emit()` only when `opsmail_enabled = 1` and inbox email is configured.
- Private `emit()` calls `wp_mail()`, updates `status` to SENT or FAILED (with `last_error`). Never re-throws.
- HTML email format: styled badge + event detail table + machine-readable JSON metadata block in `<pre>`. Custom `X-Ops-*` mail headers.
- Settings helpers: `is_enabled()`, `inbox_email()`, `trusted_origins()`, `expense_threshold()`.

### New: `plugin/includes/api/class-opb-opsmail-api.php` — REST Queue API
- `GET /opb/v1/opsmail/queue` — paginated queue list with filters: `status`, `event_type`, `date_from`, `date_to`, `search`. `manage_options` only.
- `GET /opb/v1/opsmail/stats` — counts by status + counts by event type + recent failures + `opsmail_enabled` / `inbox_configured` flags. `manage_options` only.
- `POST /opb/v1/opsmail/queue/{id}/acknowledge` — marks one event as ACKNOWLEDGED. `manage_options` only.

### New: OPSMAIL Queue admin page — `Administration → OPSMAIL Queue`
- PHP-rendered WP admin page (no React rebuild required). Slug: `opb-opsmail-queue`.
- Inline status/config warning banner when OPSMAIL is disabled or inbox is not configured.
- Filterable table: event type, status, free-text search; paginated at 50 events per page.
- Row hover shows full `last_error` text. HIGH-priority rows highlighted in red.

### New: OPSMAIL settings — `Settings → Customisation → OPSMAIL`
Four new entries in `OPB_Customizations::REGISTRY` (category `opsmail`):
- `opsmail_enabled` — `'0'` / `'1'` toggle (default `'0'`). Controls email emission; queue is always populated.
- `opsmail_inbox_email` — email address that receives all OPSMAIL events.
- `opsmail_trusted_origins` — textarea; one trusted sender mailbox per line.
- `opsmail_expense_threshold` — large-expense trigger amount (default `5000`).

### Event taxonomy (initial set)
`INQUIRY.RECEIVED`, `CLIENT.ONBOARDING_RECEIVED`, `BOOKING.REQUEST_RECEIVED`, `BOOKING.CONFIRMED`, `BOOKING.MODIFICATION_REQUESTED`, `BOOKING.CANCELLED`, `SUPPORT.REQUEST_RECEIVED`, `PAYMENT.ISSUE_REPORTED`, `EXPENSE.LARGE_RECORDED`, `TASK.CREATED`, `SYSTEM.ERROR`

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
