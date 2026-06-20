# Onukonu Pet Boarding Core — RC1 Final Release Notes

**Package:** `onukonu-pet-boarding-rc1.zip`
**Version:** 3.3.0
**Build date:** 2026-06-20
**Build type:** Release Candidate 1 — Production Deployment

---

## 1. What This Package Contains

A fully compiled, production-ready WordPress plugin. Install directly from `wp-admin → Plugins → Add New → Upload Plugin`.

**Runtime requirements:**
- WordPress 6.4+
- PHP 8.2+
- MySQL 8.0+ (or MariaDB equivalent)
- No Node.js required on the server

**No build step required after installation.** The compiled React SPA is included in `assets/dist/`.

---

## 2. Core OPB Features

| Module | Status |
|--------|--------|
| Dashboard (live occupancy, tasks, quick stats) | ✅ Operational |
| Client management (profiles, search, archive) | ✅ Operational |
| Pet management (health history, documents, vaccination) | ✅ Operational |
| Booking engine (create, check-in, check-out, modifications) | ✅ Operational |
| Kennel Board (visual occupancy grid) | ✅ Operational |
| Linear Occupancy view | ✅ Operational |
| Invoice engine (auto-generation, line items, tax) | ✅ Operational |
| PDF invoice generation (mPDF) | ✅ Operational |
| PDF email delivery | ✅ Operational |
| Public invoice summary page (token-gated) | ✅ Operational |
| Payment recording (Cash / UPI / Other) | ✅ Operational |
| Expense tracking + large-expense alerts | ✅ Operational |
| Expense categories | ✅ Operational |
| Reports (financial, occupancy) | ✅ Operational |
| Task management | ✅ Operational |
| Inquiry form + onboarding flow | ✅ Operational |
| Client portal `/my-pets/` (OTP auth, no WP login) | ✅ Operational |
| Staff portal `/portal/` (full-screen SPA) | ✅ Operational |
| WP Admin integration (OPB menu, deep links) | ✅ Operational |
| Data Management (archive/restore — super admin only) | ✅ Operational |
| Role-based access control (4 OPB roles + WP admin) | ✅ Operational |
| Branch scoping (all branch-scoped endpoints gated) | ✅ Operational |
| PWA (installable, service worker, web manifest) | ✅ Operational |
| Settings / Customization (all categories) | ✅ Operational |
| Kennel configuration | ✅ Operational |
| Boarding and addon catalogues | ✅ Operational |

---

## 3. OPSMAIL — Operational Intelligence Layer

OPSMAIL is an additive-only event queue. It never throws, never blocks, and never breaks any core business workflow.

**Queue flow:**
```
Business Event → OPB_Opsmail::push_*() → opb_opsmail_queue → Email emission → Telegram delivery
```

**Event types wired (v2.8.0+):**
- `INQUIRY.RECEIVED` — public inquiry submitted
- `CLIENT.ONBOARDING_RECEIVED` — onboarding terms accepted
- `BOOKING.CONFIRMED` — booking invoice created
- `TASK.CREATED` — new task added
- `EXPENSE.LARGE_RECORDED` — expense ≥ configured threshold

**REST endpoints (manage_options only):**
- `GET /opb/v1/opsmail/queue` — paginated queue with filters
- `GET /opb/v1/opsmail/stats` — counts by status, event type, recent failures
- `POST /opb/v1/opsmail/queue/{id}/acknowledge`
- `POST /opb/v1/opsmail/process-telegram` — flush Telegram queue
- `POST /opb/v1/opsmail/test-telegram` — send test message
- `GET /opb/v1/opsmail/cron-health` — scheduler health (queue, mailbox, SAL components)

**OPSMAIL Queue viewer** — PHP-rendered WP admin page (no React rebuild). Filterable, paginated.

---

## 4. Telegram Integration

- Delivery via WP Cron flush (`opb_cron_queue_flush` on `opb_mailbox_interval` schedule)
- Configurable bot token and chat ID via Customization settings
- Test delivery button in OPSMAIL Queue admin page
- `telegram_ok` / `telegram_status` tracked per queue event
- Direct delivery path for SAL briefs (bypasses queue, uses `send_telegram_to()` directly)

---

## 5. Gemini Integration

- Used as a **formatter only** — structures raw data snapshots into readable Telegram messages
- Model: `gemini-2.5-flash` (configurable)
- **Deterministic fallback** guaranteed: if Gemini API key is missing or unavailable, a plain-text brief is generated from the same snapshot data without Gemini
- Gemini Lab (`/admin/opsmail/gemini-lab`) — test prompt/response pipeline with live Telegram delivery option

---

## 6. SAL — Situational Awareness Layer (v3.1.0 + RC1 Hardening)

Three automated Telegram briefs on WP Cron schedule:

| Brief | Default Time | Content |
|-------|-------------|---------|
| Morning Operations Brief | 07:00 | Check-ins/check-outs today, tasks due, exceptions, active boarders |
| Evening Closure Brief | 19:00 | End-of-day boarding count, tasks completed/pending |
| Accounts Snapshot | 09:00 | Invoices raised/unpaid, payments received, expenses |

**RC1 hardening applied to SAL:**
- `SalDashboard.tsx`: null config guard added — API failure no longer causes a React crash; renders a meaningful error panel with Retry button instead
- `App.tsx`: SAL route (`/admin/sal`) now wrapped in `<ErrorBoundary>` — any unexpected error shows a recoverable error UI instead of a white screen
- `loadConfig()`: error is now captured and displayed (was silently discarded)
- Sidebar: SAL entry (`🛰 SAL`) confirmed present in compiled bundle for `opb_super_admin` role

**Delivery pipeline:**
```
WP Cron (opb_cron_sal_check, hourly)
  → OPB_SAL_Snapshot::generate()
  → OPB_SAL_Formatter::format() [Gemini or deterministic fallback]
  → OPB_Telegram_Consumer::send_telegram_to()
  → opb_sal_brief_history (audit log)
```

**Idempotency:** `opb_sal_sent_today_{type}_{date}` option prevents duplicate delivery per calendar day.

**REST endpoints (manage_options only):**
- `GET /opb/v1/sal/config` — get schedule and Telegram config
- `POST /opb/v1/sal/config` — save config and reschedule
- `POST /opb/v1/sal/generate` — preview full pipeline (no delivery)
- `POST /opb/v1/sal/send` — generate + deliver immediately (manual override)
- `POST /opb/v1/sal/test-telegram` — test SAL Telegram chat ID
- `GET /opb/v1/sal/diagnostics` — last run metadata per brief type
- `GET /opb/v1/sal/history` — brief delivery history (up to 200 rows)

**SAL admin dashboard** — React page at `wp-admin → OPB → SAL`:
- Schedule configuration (enable/disable each brief, set delivery time)
- Telegram chat ID (dedicated SAL chat or fallback to main chat)
- Operations panel — generate any brief immediately without marking day as sent
- Preview Mode — inspect Snapshot JSON → Gemini Prompt → Gemini Output → Telegram Message
- Brief history table — sortable, filterable, expandable rows

---

## 7. Scheduler Registration

| Cron Hook | Schedule | Handler |
|-----------|----------|---------|
| `opb_cron_queue_flush` | `opb_mailbox_interval` (1–60 min, configurable) | Telegram queue consumer |
| `opb_mailbox_check` | `opb_mailbox_interval` | IMAP mailbox processor |
| `opb_cron_sal_check` | `opb_sal_hourly` (1 hour) | SAL brief scheduler |

All hooks registered on `init`. Cleared on plugin deactivation.

---

## 8. Role and Permission Matrix

| Role | Scope | Key Capabilities |
|------|-------|-----------------|
| `administrator` (WP) | Global | Full access to all OPB features and WP admin |
| `opb_super_admin` | Global | All OPB features, settings, SAL, OPSMAIL, data management |
| `opb_branch_manager` | Branch-scoped | Bookings, clients, pets, invoices, expenses, reports for their branch |
| `opb_reception` | Branch-scoped | Bookings, clients, pets, invoices for their branch |
| `opb_staff` | Branch-scoped | Dashboard, tasks, kennel board for their branch |

Branch-scoped users without a `opb_branch_id` assignment receive HTTP 403 on all branch-scoped endpoints (sentinel `-1` detection in `OPB_REST_Base::permission_check()`).

---

## 9. Database

**23 custom tables** (all prefixed `wp_opb_*`). Created/updated via `dbDelta()` on every WordPress `init` when `opb_db_version` option does not match `OPB_VERSION`. Safe to run multiple times (additive only).

**RC1 tables include:** `clients`, `pets`, `pet_documents`, `bookings`, `stays`, `kennel_assignments`, `invoices`, `invoice_items`, `payments`, `expenses`, `expense_categories`, `tasks`, `staff`, `branches`, `kennels`, `boarding_services`, `addon_services`, `inquiries`, `opsmail_queue`, `client_sessions`, `client_otp`, `client_pet_links`, `sal_brief_history`

---

## 10. Known Limitations

1. **XLSX import not yet supported** — export as CSV before uploading to the Import module
2. **WooCommerce adapter** — stub only; not active in RC1
3. **External cron recommended** — WP Cron fires on page load; for reliable SAL brief timing and queue flushing, configure a server-side cron: `*/5 * * * * curl -s https://your-site.com/wp-cron.php > /dev/null`
4. **SAL requires Telegram bot token** — configure under `Settings → Customization → Telegram` before enabling SAL
5. **Gemini is optional** — SAL and OPSMAIL both operate fully without a Gemini API key (deterministic fallback for SAL, plain queue event for OPSMAIL)

---

## 11. Deployment Instructions

### First Installation

1. Upload `onukonu-pet-boarding-rc1.zip` via `wp-admin → Plugins → Add New → Upload Plugin`
2. Activate the plugin
3. All 23 database tables are created on first `init` after activation
4. Navigate to `wp-admin → Pet Boarding → User Management` — assign branches to all branch-scoped staff

### Configuration Sequence

1. **Branches** — `Settings → Branches` — create your H2, H3, H4 branches
2. **Kennels** — `Settings → Kennels` — configure kennel types per branch
3. **Boarding Catalogue** — `Settings → Boarding` — add service rates
4. **Staff** — create WP users, assign OPB roles, assign branches
5. **Customization** — `Settings → Customization`:
   - General: facility name, WhatsApp number
   - Telegram: bot token + chat ID
   - Gemini: API key (optional)
   - SAL: enable, configure brief times and optional dedicated SAL chat ID
   - OPSMAIL: enable, set inbox email and expense threshold
6. **Expense Categories** — `Settings → Expense Categories`

### SAL First-Run Check

After enabling SAL:
1. Go to `wp-admin → OPB → SAL`
2. Under **Preview Mode** — generate a Morning Brief snapshot
3. Under **Telegram Configuration** — click **Send Test Brief**
4. Confirm message received in Telegram
5. Enable `sal_enabled` and set brief times

### Upgrade From Prior Version

The `opb_maybe_create_tables()` function on `init` handles schema migrations automatically — `dbDelta()` is additive and will add any new columns or tables. No manual SQL required.

---

## 12. Build Provenance

| Item | Value |
|------|-------|
| Repository branch | `main` |
| HEAD commit | `97ec0c0f` |
| Plugin version | `3.3.0` |
| Build script | `build-rc1.js` |
| TypeScript | Zero errors |
| Vite modules | 114 |
| Bundle size | 490 KB (JS) · 57 KB (CSS) |
| PHP files | 617 |
| PHP syntax errors | 0 |
| ZIP entries | 751 |
| ZIP size | ~45 MB (includes mPDF vendor library) |
| Stale `2.0.9` in bundle | 0 occurrences |
| Stale `opbData` global | 0 occurrences |
| Build date | 2026-06-20 UTC |
