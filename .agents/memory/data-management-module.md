---
name: Data Management module
description: v2.6.0 super-admin archive/restore module — schema findings, permission gate, and booking status migration.
---

# Data Management Module (v2.6.0)

## Permission gate
All endpoints in `class-opb-data-management-api.php` use `permission_super_admin()`:
`current_user_can('opb_manage_settings') || current_user_can('manage_options')`
`opb_manage_settings` is exclusive to `opb_super_admin`; branch managers do NOT have it.

## Schema findings (what already existed vs what was added)
- `opb_clients`: `status ENUM('active','archived')` + `archive_reason TEXT` — already existed ✅
- `opb_pets`: `is_active TINYINT(1)` — already existed ✅
- `opb_bookings`: **no status column** — added `status VARCHAR(20) NOT NULL DEFAULT 'Active'` via `add_col()` in activator migrate path
- `opb_inquiries`: `status` with `'ARCHIVED'` value — already existed ✅

**Why:** Client portal already filtered `c.status='active'` and `p.is_active=1` before this module existed. Archiving via those columns naturally excludes records from portal without extra code.

## Booking status
Values used: `'Active'` (default), `'Cancelled'`. Column added in `maybe_upgrade()` using `add_col()` (MySQL 5.7 safe).
The existing `payment_status` and stay-level `status` fields are separate — cancellation is a booking-header flag only.

## Route and sidebar
- Route: `/admin/data-management`
- Sidebar: `roles: ['opb_super_admin']`
- API namespace: `opb/v1/admin/*`
- React page: `plugin/app/src/pages/admin/DataManagement.tsx`
- API client: `plugin/app/src/api/dataManagement.ts`

## Hard delete pre-conditions (Phase 1 — not implemented)
- Client: safe only when no bookings + no invoices + no payments
- Pet: safe only when no booking stays
- Booking: safe only when no stays + no invoices + no payments
- Inquiry: safe when status ARCHIVED/REJECTED AND no converted_client_id
