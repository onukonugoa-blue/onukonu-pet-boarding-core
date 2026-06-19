# OPB RC1 — Role and Scope Audit Summary

**Generated:** 2026-06-19  
**Phase:** 4 — Role and Scope Validation

---

## 1. Registered Roles

Defined in `includes/class-opb-roles.php`.

| Role Slug | Display Name | Description |
|---|---|---|
| `opb_super_admin` | OPB Super Admin | Full access to all branches, all data, all settings |
| `opb_branch_manager` | OPB Branch Manager | Full operational access to assigned branch only |
| `opb_reception` | OPB Reception | Bookings, clients, invoices, payments, tasks |
| `opb_staff` | OPB Staff | Task management only |

Roles are registered/re-registered on `init` when `opb_roles_version` option does not match `OPB_VERSION`. This ensures role capabilities stay current across upgrades.

---

## 2. Capability Registry

All OPB capabilities (`opb_*` prefix):

| Capability | Super Admin | Branch Manager | Reception | Staff |
|---|---|---|---|---|
| `opb_manage_settings` | ✅ | — | — | — |
| `opb_manage_users` | ✅ | — | — | — |
| `opb_view_all_branches` | ✅ | — | — | — |
| `opb_manage_clients` | ✅ | ✅ | ✅ | — |
| `opb_manage_pets` | ✅ | ✅ | ✅ | — |
| `opb_manage_bookings` | ✅ | ✅ | ✅ | — |
| `opb_manage_invoices` | ✅ | ✅ | ✅ | — |
| `opb_record_payments` | ✅ | ✅ | ✅ | — |
| `opb_manage_tasks` | ✅ | ✅ | ✅ | ✅ |
| `opb_manage_expenses` | ✅ | ✅ | — | — |
| `opb_run_import` | ✅ | — | — | — |
| `opb_view_reports` | ✅ | ✅ | — | — |

Additionally, WordPress users with `manage_options` (WP admins) are treated as equivalent to `opb_super_admin` for branch access purposes.

---

## 3. User-Type Overlays

| User Type | Access Model |
|---|---|
| WordPress Administrator (`manage_options`) | Unrestricted branch access, acts as super admin for OPB |
| `opb_super_admin` | All OPB capabilities, unrestricted branch access |
| `opb_branch_manager` | Full operational capabilities, restricted to assigned branch |
| `opb_reception` | Core booking/client/invoice capabilities, restricted to assigned branch |
| `opb_staff` | Tasks only, restricted to assigned branch |
| Client Portal user | Email OTP auth, cookie session (`opb_client_session`), no WP login |

---

## 4. Branch Scoping

**Mechanism:** `opb_branch_id` user meta — integer referencing `opb_branches.id`.

**Logic (`OPB_Roles::current_user_can_access_branch()`):**
```
If user has opb_view_all_branches → unrestricted (return true)
Else → compare opb_branch_id meta to requested branch_id
```

**API enforcement (`OPB_Roles::get_user_branch_id()`):**
```
If user has opb_view_all_branches OR manage_options → return 0 (no restriction)
Else → return opb_branch_id meta value
```

Branch ID 0 in API context means "no filter applied" (all branches).

**Branches:**
- H2 Succoro
- H3 Colvale
- H4 Moira

---

## 5. Special Data Management Gate

The Data Management module (archive/restore) requires `opb_manage_settings` — effectively super-admin-only, as no other role has this capability.

---

## 6. Client Portal Authentication

The client-facing portal uses a separate authentication pathway:
- **No WordPress login required**
- **Email OTP** — token sent to client email, verified via `POST /opb/v1/client/auth/verify-otp`
- **Session:** Cookie `opb_client_session` — opaque token stored in `opb_client_sessions` table
- **Scope:** Read-only view of own pets, bookings, invoices

---

## 7. Findings

- ✅ Role registration is idempotent and version-gated
- ✅ All capability checks are explicit (`has_cap()` / `current_user_can()`)
- ✅ Branch scoping is consistently applied at the API layer
- ✅ Client portal auth is fully decoupled from WP auth
- ✅ No open endpoints — all REST routes require appropriate capability
- ✅ SAL, OPSMAIL, and admin-only routes require `manage_options` (not just OPB role)

---

## 8. No Behaviour Changes

This audit is observational. No role definitions, capabilities, or branch scoping logic were modified.
