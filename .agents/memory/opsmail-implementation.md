---
name: OPSMAIL implementation
description: v2.8.0 OPSMAIL Operational Intelligence Layer — architecture decisions and constraints
---

## Rule
OPB_Opsmail is purely additive. Every public method is `try/catch(\Throwable)` wrapped — it must never throw, never block, never break any existing business workflow.

**Why:** The spec is explicit: OPSMAIL is infrastructure, not user-facing. A failed email send must not surface as a 500 error to the client submitting an inquiry form.

**How to apply:** Any new event push method must follow the same pattern: call `self::push_event()` which has its own try/catch. Never throw from OPSMAIL.

## Load order constraint
`class-opb-opsmail.php` must be required AFTER `class-opb-customizations.php` in `onukonu-pet-boarding-core.php`. OPSMAIL calls `OPB_Customizations::get()` at runtime (not at include time), so load order only matters for the class definitions, not for method calls. The current order is correct.

## Queue viewer is PHP-rendered
The OPSMAIL Queue admin page (`opb-opsmail-queue`) is a standard WP `add_submenu_page` with a PHP render callback `OPB_Admin_Page::render_opsmail_queue()`. It does NOT go through the React SPA. This was intentional — tsc+vite are local-only and the admin queue viewer needed to ship without a frontend rebuild.

**Why:** React SPA pages all render `<div id="opb-root">` from `render()`. Adding a new route would require rebuilding the JS bundle. The PHP page is independent, immediately shippable, and equally capable for an admin-only table view.

## Queue is always populated; email is opt-in
Even when `opsmail_enabled = 0` or no inbox email is configured, events are still inserted into `opb_opsmail_queue`. Only email emission is gated on `is_enabled()` + `inbox_email()`. The queue is therefore always a full audit trail.

## 5 wired hook points (v2.8.0)
- `INQUIRY.RECEIVED` — `OPB_Public_API::submit_inquiry()` after notify calls
- `CLIENT.ONBOARDING_RECEIVED` — `OPB_Public_API::accept_terms()` after notify_onboarding_complete, inside the READY_FOR_REVIEW block
- `BOOKING.CONFIRMED` — `OPB_Bookings_API::create_item()` after `OPB_Invoice_Generator::create_for_booking()`
- `TASK.CREATED` — `OPB_Tasks_API::create_item()` after `$wpdb->insert()`, captures `insert_id` into `$task_id`
- `EXPENSE.LARGE_RECORDED` — `OPB_Expenses_API::create_item()` after the row re-fetch, guarded by threshold check inside `push_expense_if_large()`

## `SHOW TABLES LIKE` guard
`push_event()` checks table existence before inserting. This prevents fatal errors if the plugin is updated but the DB version hasn't triggered `create_tables()` yet.

## MySQL 5.7 compatibility
The `opb_opsmail_queue` table uses standard `CREATE TABLE` (not `IF NOT EXISTS` on columns) and is added to the `$tables[]` array in `OPB_Activator::create_tables()` before the `foreach dbDelta()` loop. No `ADD COLUMN IF NOT EXISTS` or `CREATE INDEX IF NOT EXISTS` is used anywhere in the OPSMAIL code.
