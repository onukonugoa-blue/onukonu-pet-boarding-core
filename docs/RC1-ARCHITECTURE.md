# OPB RC1 — Canonical Architecture Reference

**Product:** Onukonu Pet Boarding Core (OPB)  
**Version:** RC1 (source base: v3.1.0)  
**Generated:** 2026-06-19  
**Phase:** 3 — Architecture Audit

---

## 1. System Overview

Onukonu Pet Boarding Core is a WordPress plugin that replaces a discontinued SaaS boarding management platform. It provides end-to-end management for clients, pets, bookings, invoices, payments, and operations across three branches.

**Deployment target:** WordPress 6.4+ on Hostinger shared hosting (PHP 8.2, MySQL 5.7+)

---

## 2. Canonical Architecture

```
OPB Core
│
├── Dashboard          — occupancy overview, today's check-ins/outs, task summary
├── Clients            — client CRM, contact records, portal access
├── Pets               — pet profiles, vaccination records, boarding history
├── Bookings           — booking creation, check-in, check-out, stay tracking
├── Boarding           — kennel catalogue, add-ons, pricing catalogue
├── Occupancy          — kennel board (grid view), linear occupancy view
├── Invoices           — automated invoice generation, PDF delivery, audit trail
├── Payments           — payment recording against invoices
├── Expenses           — expense recording, category management
├── Tasks              — task creation, assignment, completion tracking
├── Documents          — client documents, onboarding paperwork
├── Users              — OPB role assignment, branch access control
├── Reports            — financial summaries, occupancy reports, business metrics
└── Import             — legacy data migration (XLSX/CSV adapters)

Operational Layer
│
├── OPSMAIL            — event queue, email emission, queue viewer
├── Queue              — opb_opsmail_queue table, push/emit pipeline
├── Telegram           — bot transport, consumer, diagnostics, testing
├── Gemini             — text classification/formatting, Gemini Lab UI
├── SAL                — Situational Awareness Layer (morning/evening/accounts briefs)
└── Scheduler          — WP Cron pipeline, external cron support, health monitoring
```

---

## 3. Core Module Detail

### Dashboard
- **File:** `includes/api/class-opb-dashboard-api.php`
- **Route:** `GET /opb/v1/dashboard`
- **Provides:** Today's bookings, check-ins, check-outs, current boarders, task counts, revenue summary
- **Scope:** Branch-filtered for non-super-admin users

### Clients
- **Files:** `includes/api/class-opb-clients-api.php`, `includes/api/class-opb-client-relationship-api.php`
- **Routes:** `CRUD /opb/v1/clients`, `GET /opb/v1/clients/{id}/pets`, `GET /opb/v1/clients/{id}/bookings`
- **Portal:** Email OTP auth (`/client/auth/*`), client-facing `GET /opb/v1/client/me`
- **Tables:** `opb_clients`, `opb_client_sessions`, `opb_client_otp_tokens`, `opb_client_inquiries`

### Pets
- **Files:** `includes/api/class-opb-pets-api.php`
- **Routes:** `CRUD /opb/v1/pets`
- **Table:** `opb_pets`

### Bookings
- **Files:** `includes/api/class-opb-bookings-api.php`
- **Routes:** `CRUD /opb/v1/bookings`, `POST /bookings/{id}/checkin`, `POST /bookings/{id}/checkout`, `POST /bookings/{id}/addons`, `GET /kennel-board`, `PUT /stays/{stay_id}/assign-kennel`
- **Tables:** `opb_bookings`, `opb_stays`, `opb_booking_addons`

### Boarding / Catalogue
- **Files:** `includes/api/class-opb-branches-api.php`, `includes/api/class-opb-kennels-api.php`, `includes/services/`
- **Routes:** `CRUD /opb/v1/branches`, `CRUD /opb/v1/kennels`
- **Tables:** `opb_branches`, `opb_kennels`, `opb_boarding_catalogue`, `opb_addons_catalogue`
- **Pricing Engine:** `includes/class-opb-pricing-engine.php`

### Occupancy Board
- **Component:** `app/src/pages/OccupancyBoard.tsx`, `app/src/pages/LinearOccupancy.tsx`
- **API:** `GET /opb/v1/kennel-board`

### Invoices
- **Files:** `includes/api/class-opb-invoices-api.php`, `includes/api/class-opb-invoice-delivery-api.php`, `includes/class-opb-invoice-generator.php`, `includes/class-opb-invoice-document.php`
- **Routes:** `CRUD /opb/v1/invoices`, invoice delivery endpoints
- **PDF Engine:** mPDF via Composer (`vendor/mpdf/`)
- **Table:** `opb_invoices`, `opb_invoice_items`, `opb_invoice_audit`

### Payments
- **Files:** `includes/api/class-opb-payments-api.php`
- **Routes:** `CRUD /opb/v1/payments`
- **Table:** `opb_payments`

### Expenses
- **Files:** `includes/api/class-opb-expenses-api.php`, `includes/api/class-opb-expense-categories-api.php`
- **Routes:** `CRUD /opb/v1/expenses`, `CRUD /opb/v1/expense-categories`
- **Tables:** `opb_expenses`, `opb_expense_categories`

### Tasks
- **Files:** `includes/api/class-opb-tasks-api.php`
- **Routes:** `CRUD /opb/v1/tasks`
- **Table:** `opb_tasks`

### Documents
- **Files:** `includes/class-opb-invoice-document.php`, `includes/class-opb-portal.php`, `includes/class-opb-public-portal.php`
- **Covers:** Onboarding documents, public invoice download portal

### Users
- **Files:** `includes/class-opb-roles.php`, `includes/api/class-opb-settings-api.php`
- **Roles:** `opb_super_admin`, `opb_branch_manager`, `opb_reception`, `opb_staff`
- **Branch scoping:** `opb_branch_id` user meta

### Reports
- **Files:** `includes/api/class-opb-reports-api.php`
- **Route:** `GET /opb/v1/reports`

### Import
- **Files:** `includes/migration/`
- **Route:** `POST /opb/v1/import`
- **Capability:** `opb_run_import`

---

## 4. Operational Layer Detail

### OPSMAIL
**Purpose:** Additive-only operational event queue. Captures significant business events (large bookings, payments, expenses, check-ins, invoices) and emits them as structured notifications.

- **Engine:** `includes/class-opb-opsmail.php` — `OPB_Opsmail::push_*()`
- **API:** `includes/api/class-opb-opsmail-api.php`
- **Table:** `opb_opsmail_queue`
- **Safety:** Every call wrapped in `try/catch(\Throwable)` — never throws, never blocks business logic

### Queue
**Purpose:** Persistent event store for all OPSMAIL and SAL messages.

- **Table:** `opb_opsmail_queue` — columns: `id`, `event_type`, `source_system`, `payload`, `status`, `created_at`, `processed_at`, `error`
- `source_system`: `OPSMAIL` | `SAL`
- `status`: `PENDING` | `SENT` | `FAILED` | `ACKNOWLEDGED`

### Telegram Transport
**Purpose:** Delivers queue messages to a configured Telegram bot/chat.

- **Consumer:** `includes/class-opb-telegram-consumer.php` — `OPB_Telegram_Consumer::process_queue()`
- **Direct delivery:** `OPB_Telegram_Consumer::send_telegram_to()` — used by SAL for immediate delivery
- **Cron hook:** `opb_cron_process_telegram` (1-minute interval)
- **Diagnostics:** `GET /opb/v1/opsmail/diagnostics`
- **Test:** `POST /opb/v1/opsmail/test-telegram`

### Gemini Integration
**Purpose:** Text classification (mailbox) and brief formatting (SAL). Gemini is a formatter only — no forecasting, no analysis.

- **Processor:** `includes/class-opb-mailbox-processor.php`
- **API endpoint:** `POST /opb/v1/opsmail/process-text`
- **Lab UI:** `app/src/pages/admin/GeminiLab.tsx`
- **Fallback:** Deterministic text rendering if Gemini unavailable

### SAL — Situational Awareness Layer
**Purpose:** Scheduled Telegram briefing engine. Converts OPB database state into concise factual briefs.

- **Snapshot:** `includes/class-opb-sal-snapshot.php` — sole DB data source for briefs
- **Formatter:** `includes/class-opb-sal-formatter.php` — Gemini + deterministic fallback
- **Scheduler:** `includes/class-opb-sal-scheduler.php` — hourly WP Cron check
- **API:** `includes/api/class-opb-sal-api.php`
- **Admin UI:** `app/src/pages/admin/SalDashboard.tsx`
- **Table:** `opb_sal_brief_history`
- **Brief types:** Morning Operations Brief (07:00), Evening Closure Brief (19:00), Accounts Snapshot (09:00)

### Scheduler
**Purpose:** Manages all WP Cron hooks. Detects external cron presence.

- **Health monitor:** `includes/class-opb-cron-health.php` — ring buffer of last 12 execution timestamps
- **External cron detection:** Median interval < 8 minutes OR `DISABLE_WP_CRON` constant
- **Hooks registered:**
  - `opb_cron_process_telegram` — 1-minute interval
  - `opb_cron_process_mailbox` — configurable (default 5 min)
  - `opb_cron_sal_check` — hourly (SAL brief gate)
- **Deactivation:** All three hooks cleared via `wp_clear_scheduled_hook()`

---

## 5. Module Boundaries

| Module | Owns | Depends On |
|---|---|---|
| Core OPB | All `opb_*` DB tables, REST routes, roles | WordPress core |
| OPSMAIL | `opb_opsmail_queue` | OPB_Customizations (settings) |
| Telegram | Cron consumer, bot API calls | OPSMAIL queue, WP Cron |
| Gemini | Text classification/formatting | OPSMAIL settings (API key) |
| SAL | `opb_sal_brief_history`, snapshot engine | OPB DB tables, Telegram, Gemini, OPSMAIL queue |
| Scheduler | WP Cron registration, health tracking | WP Cron, all other cron-dependent modules |

**Load order in `onukonu-pet-boarding-core.php`:**
1. `OPB_Customizations` (settings registry — must be first)
2. `OPB_Roles`
3. `OPB_Activator` / `OPB_Deactivator`
4. `OPB_Opsmail` (after OPB_Customizations)
5. `OPB_Telegram_Consumer`
6. `OPB_Mailbox_Processor`
7. `OPB_SAL_*` classes
8. `OPB_Cron_Health`
9. All API controllers

---

## 6. Database Tables

| Table | Purpose |
|---|---|
| `opb_branches` | Branch definitions |
| `opb_clients` | Client CRM records |
| `opb_pets` | Pet profiles |
| `opb_kennels` | Kennel definitions per branch |
| `opb_bookings` | Booking records |
| `opb_stays` | Individual stay records (booking → kennel mapping) |
| `opb_booking_addons` | Add-ons per booking |
| `opb_boarding_catalogue` | Boarding rate catalogue |
| `opb_addons_catalogue` | Add-on catalogue |
| `opb_invoices` | Invoice records |
| `opb_invoice_items` | Line items per invoice |
| `opb_invoice_audit` | Invoice audit trail |
| `opb_payments` | Payment records |
| `opb_expenses` | Expense records |
| `opb_expense_categories` | Expense category definitions |
| `opb_tasks` | Task records |
| `opb_customizations` | Plugin settings registry |
| `opb_client_sessions` | Client portal sessions |
| `opb_client_otp_tokens` | Email OTP tokens for client portal |
| `opb_client_inquiries` | Client inquiries/contact form |
| `opb_opsmail_queue` | OPSMAIL + SAL event queue |
| `opb_sal_brief_history` | SAL brief delivery log |

---

## 7. Frontend Architecture

**Framework:** React 18 + TypeScript + Vite + Tailwind CSS  
**Router:** React Router DOM v6 (HashRouter)  
**State:** Zustand  
**Build output:** `plugin/assets/dist/assets/index.js`, `plugin/assets/dist/assets/main.css`

The React SPA is loaded in the WordPress admin as a single page application. WordPress enqueues the compiled assets. All data access is via the `opb/v1` REST API. No direct DB access from the frontend.

---

## 8. Production Dependencies

### PHP (Composer)
- `mpdf/mpdf` — PDF generation for invoices

### React (npm, compiled away in production)
- `react`, `react-dom`, `react-router-dom`, `zustand`

**No Node.js runtime is required in production.** All React/TypeScript/Tailwind source is compiled to static JS/CSS files during development. Only the compiled assets in `plugin/assets/dist/` are deployed.
