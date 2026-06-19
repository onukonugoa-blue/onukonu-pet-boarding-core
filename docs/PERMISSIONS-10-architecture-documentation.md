# OPB Permission Audit — Part 10: Access Control Architecture Documentation

**Plugin Version:** 3.1.0  
**Audit Date:** June 2026  
**Scope:** Complete reference architecture for OPB's access control system

---

## 1. Overview

OPB implements a layered access control architecture built on top of WordPress's native role and capability system. There is no custom RBAC framework — OPB uses the existing WordPress primitives (`add_role`, `WP_User`, `current_user_can`, `user meta`) and adds its own layer of helper methods that centralise enforcement.

The system has three distinct identity spaces:

| Identity Space | Who | Auth Mechanism | Capabilities |
|---|---|---|---|
| **OPB Staff** | WordPress users with OPB roles | WP login (username/password) | OPB capabilities via WP role |
| **WP Administrator** | WordPress admin users | WP login | `manage_options` (full bypass) |
| **OPB Client** | Boarding clients | Email OTP + session cookie | None (read-only portal) |

---

## 2. Role Hierarchy

```
┌─────────────────────────────────────────────────────┐
│  WordPress Site Layer                               │
│                                                     │
│  ┌──────────────────────────────────────────────┐  │
│  │  WP Administrator (manage_options)           │  │
│  │  · Full OPB access                           │  │
│  │  · OPSMAIL + SAL access                      │  │
│  │  · Cross-branch unrestricted                 │  │
│  └──────────────────────────────────────────────┘  │
│                                                     │
│  ┌──────────────────────────────────────────────┐  │
│  │  OPB Super Admin (opb_super_admin)           │  │
│  │  · All 12 OPB capabilities                   │  │
│  │  · Cross-branch unrestricted                 │  │
│  │  · ⚠ No OPSMAIL/SAL access                  │  │
│  └──────────────────────────────────────────────┘  │
│                                                     │
│  ┌──────────────────────────────────────────────┐  │
│  │  OPB Branch Manager (opb_branch_manager)     │  │
│  │  · 8 OPB capabilities                        │  │
│  │  · Branch-scoped                             │  │
│  └──────────────────────────────────────────────┘  │
│                                                     │
│  ┌──────────────────────────────────────────────┐  │
│  │  OPB Reception (opb_reception)               │  │
│  │  · 6 OPB capabilities                        │  │
│  │  · Branch-scoped                             │  │
│  └──────────────────────────────────────────────┘  │
│                                                     │
│  ┌──────────────────────────────────────────────┐  │
│  │  OPB Staff (opb_staff)                       │  │
│  │  · 1 OPB capability (tasks only)             │  │
│  │  · Branch-scoped                             │  │
│  └──────────────────────────────────────────────┘  │
│                                                     │
└─────────────────────────────────────────────────────┘

Separate identity space:
┌──────────────────────────────────────────────────────┐
│  OPB Client Portal (/my-pets/)                       │
│  · Identified by phone number in opb_clients         │
│  · Authenticated via Email OTP + session cookie      │
│  · No WordPress account, no OPB capabilities         │
│  · Read-only access to own client/pet/booking data   │
└──────────────────────────────────────────────────────┘
```

---

## 3. Permission Flow

Every OPB REST request passes through this evaluation sequence:

```
HTTP Request → WordPress REST Router
  │
  ▼
permission_callback (registered per route)
  │
  ├── __return_true ──────────────────────────────────→ Allow (public route)
  │
  ├── permission_check()
  │     ├── is_user_logged_in()? → No → 401 Forbidden
  │     └── OPB_Roles::has_opb_role()? → No → 403 Forbidden
  │           (has_opb_role = has any OPB role OR manage_options)
  │           → Yes → Allow (read gate passed)
  │
  └── permission_manage($cap)
        ├── permission_check() → (as above, 401/403 on failure)
        └── current_user_can($cap) OR current_user_can('manage_options')?
              → No → 403 Forbidden
              → Yes → Allow (write gate passed)
                       │
                       ▼
                  Callback executes
                       │
                       ▼
                  branch_filter()
                  (applied in query building)
                       │
                       ├── get_user_branch_id() = 0 → no branch WHERE clause
                       └── get_user_branch_id() = N → force WHERE branch_id = N
```

---

## 4. Capability Flow

```
OPB Role (WP role registration)
  │
  └── Capability set assigned at registration
        │
        └── Checked via current_user_can($cap)
              │
              ├── Direct cap check  →  current_user_can('opb_manage_bookings')
              └── Bypass check      →  current_user_can('manage_options')
                                         (equivalent to WP administrator)
```

The `manage_options` bypass means every capability check in OPB has an implicit "OR this user is a WP administrator" condition. This is the standard WP pattern for plugin permission checks.

---

## 5. Branch Scope Flow

```
User logs in
  │
  └── WP sets role: opb_branch_manager / opb_reception / opb_staff
        │
        └── Admin assigns branch in OPB Settings → Staff screen
              └── Writes opb_branch_id = N to wp_usermeta

REST Request arrives
  │
  └── branch_filter($requested_branch_id) in query builder
        │
        └── get_user_branch_id()
              │
              ├── has manage_options? → return 0 (unrestricted)
              ├── has opb_view_all_branches? → return 0 (unrestricted)
              └── read wp_usermeta opb_branch_id
                    ├── = 0 or unset → return 0 (⚠ effectively unrestricted)
                    └── = N → return N

  └── branch_filter result:
        ├── 0 → use $requested_branch_id from request params
        └── N → override $requested_branch_id with N
                  → All SQL queries get WHERE branch_id = N
```

---

## 6. User Type Relationship

```
WordPress User (wp_users)
  │
  ├── wp_usermeta: wp_capabilities
  │     └── {opb_super_admin: true}  OR
  │         {opb_branch_manager: true}  OR
  │         {opb_reception: true}  OR
  │         {opb_staff: true}  OR
  │         {administrator: true}
  │
  └── wp_usermeta: opb_branch_id
        └── integer — branch this user is restricted to
            (0 or missing = no restriction applied)

OPB Settings → Staff screen reads:
  get_users(['role__in' => [all OPB roles + 'administrator']])
  + get_user_meta(id, 'opb_branch_id')

  Presents: Name | Role | Branch
  Allows editing: Role + Branch assignment
```

The Staff screen is a management view over the WP user system. It does not create a second user store — it is a filtered view with OPB-relevant meta surfaced alongside.

---

## 7. Client Portal Authentication Flow

```
Public website (/my-pets/)
  │
  └── POST /opb/v1/client/auth/request-otp
        • No auth required (__return_true)
        • Input: phone number
        • Server: looks up opb_clients by phone
        • Generates OTP, sends to client email
        │
        └── POST /opb/v1/client/auth/verify-otp
              • No auth required (__return_true)
              • Input: phone + OTP code
              • Server: validates OTP, creates session record
              • Response: sets opb_client_session cookie
                    │
                    └── GET /opb/v1/client/me
                          • __return_true at WP level
                          • FIRST operation in callback: validate opb_client_session cookie
                          • Returns: client profile, pets, bookings, invoices
                          │
                          └── POST /opb/v1/client/auth/logout
                                • Clears session record
                                • Clears cookie
```

---

## 8. Module Permission Architecture Summary

```
                        ┌─────────────────────────────────────┐
                        │         Module Permission Tiers     │
                        └─────────────────────────────────────┘

Tier 1 — Public (no auth)
  POST   /public/inquiry
  GET    /public/boarding-services
  GET/POST /public/onboarding-link/{token}
  POST   /public/onboarding-submit/{token}
  POST   /client/auth/request-otp
  POST   /client/auth/verify-otp
  POST   /client/auth/logout

Tier 2 — Client session (custom OTP auth, not WP)
  GET    /client/me

Tier 3 — Any OPB role (permission_check)
  GET    /bookings, /clients, /pets, /invoices, /payments,
         /expenses, /tasks, /reports, /dashboard,
         /settings/boarding, /settings/addons,
         /kennels, /kennels/staff-options,
         /branches, /health, /customizations (read),
         /inquiries, /invoice-delivery/{id}/public

Tier 4 — Specific OPB capability (permission_manage)
  opb_manage_bookings  → booking mutations, check-in/out, kennel assignment
  opb_manage_clients   → client/inquiry mutations, onboarding, convert
  opb_manage_pets      → pet mutations, document upload/delete
  opb_manage_invoices  → invoice adjustments, payment deletion, PDF delivery
  opb_record_payments  → record new payment
  opb_manage_tasks     → task mutations
  opb_manage_expenses  → expense mutations
  opb_manage_settings  → branch/catalogue/kennel/customization mutations, staff management, data management
  opb_manage_users     → view/edit staff roles and branch assignments
  opb_run_import       → all import operations

Tier 5 — WP Administrator only (manage_options)
  ALL /opsmail/* endpoints
  ALL /sal/* endpoints
```

---

## 9. Key Files Reference

| File | Role in Access Control |
|---|---|
| `plugin/includes/class-opb-roles.php` | Role registration, capability constants, branch scope helpers, `has_opb_role()` |
| `plugin/includes/api/class-opb-rest-base.php` | `permission_check()`, `permission_manage()`, `branch_filter()` |
| `plugin/includes/class-opb-activator.php` | Database table creation; no permission logic |
| `plugin/includes/api/class-opb-settings-api.php` | Staff management (`opb_manage_users`) |
| `plugin/includes/api/class-opb-opsmail-api.php` | `super_admin_only()` for OPSMAIL |
| `plugin/includes/api/class-opb-sal-api.php` | `super_admin_only()` for SAL |
| `plugin/includes/api/class-opb-client-relationship-api.php` | Client portal routes, OTP auth |
| `plugin/includes/api/class-opb-public-api.php` | Public customer-facing routes |
| `plugin/includes/api/class-opb-data-management-api.php` | Archive/restore — `opb_manage_settings` gated |
| `plugin/app/src/pages/settings/Staff.tsx` | React UI for staff/branch management |

---

## 10. Acceptance Test Answers

A new developer reading this audit should be able to answer:

**What roles exist?**  
Four OPB roles (`opb_super_admin`, `opb_branch_manager`, `opb_reception`, `opb_staff`) plus the WP `administrator` role which acts as an implicit OPB super-user. See Part 1.

**What permissions exist?**  
12 OPB capabilities plus `manage_options`. All defined in `OPB_Roles::CAPS`. See Part 2.

**How does branch scoping work?**  
Via `opb_branch_id` user meta, enforced by `get_user_branch_id()` + `branch_filter()` on every query. `opb_super_admin` and `administrator` are unrestricted. See Part 4.

**Who can access what?**  
See the Module Permission Matrix (Part 5) for a complete per-role, per-operation breakdown.

**How is OPSMAIL secured?**  
All OPSMAIL endpoints require `manage_options` (WP administrator only). The `opb_super_admin` role cannot access OPSMAIL. See Part 6.

**How is SAL secured?**  
All SAL endpoints require `manage_options` (WP administrator only). Same pattern as OPSMAIL. See Part 7.
