# OPB Permission Audit — Part 1: Role Inventory

**Plugin Version:** 3.1.0  
**Audit Date:** June 2026  
**Scope:** All WordPress roles registered by the OPB plugin

---

## 1. Registration Mechanism

Roles are registered on the WordPress `init` hook by `OPB_Roles::register()` which calls `maybe_add_roles()`. The function is version-gated: it only re-registers roles when the stored `opb_roles_version` option does not match `OPB_VERSION`. On each registration run, existing OPB roles are removed first (`remove_role()`), then re-added with their full capability sets.

**Registration file:** `plugin/includes/class-opb-roles.php`  
**Hook:** `add_action( 'init', [ 'OPB_Roles', 'register' ] )`  
**Cleanup on deactivation:** `OPB_Roles::remove()` — removes all four roles and deletes `opb_roles_version`

---

## 2. Role Registry

### Role 1 — opb_super_admin

| Field | Value |
|---|---|
| **Role key** | `opb_super_admin` |
| **Display name** | OPB Super Admin |
| **Registered in** | `plugin/includes/class-opb-roles.php` |
| **Registration function** | `OPB_Roles::maybe_add_roles()` |
| **Status** | Active |
| **Purpose** | Full system access across all branches. Manages settings, users, catalogue, reports, and data management. Unrestricted branch scope. |

**Capabilities granted:**

| Capability | Granted |
|---|---|
| opb_manage_settings | ✅ |
| opb_manage_users | ✅ |
| opb_view_all_branches | ✅ |
| opb_manage_clients | ✅ |
| opb_manage_pets | ✅ |
| opb_manage_bookings | ✅ |
| opb_manage_invoices | ✅ |
| opb_record_payments | ✅ |
| opb_manage_tasks | ✅ |
| opb_manage_expenses | ✅ |
| opb_run_import | ✅ |
| opb_view_reports | ✅ |

> **Note:** `opb_super_admin` does NOT hold WordPress's native `manage_options` capability. Modules guarded exclusively by `manage_options` (OPSMAIL, SAL) are accessible only to WordPress `administrator` users, not to `opb_super_admin` unless the WP administrator explicitly grants `manage_options` as an additional capability.

---

### Role 2 — opb_branch_manager

| Field | Value |
|---|---|
| **Role key** | `opb_branch_manager` |
| **Display name** | OPB Branch Manager |
| **Registered in** | `plugin/includes/class-opb-roles.php` |
| **Registration function** | `OPB_Roles::maybe_add_roles()` |
| **Status** | Active |
| **Purpose** | Operational management of an assigned branch. Full access to clients, pets, bookings, financials, tasks, and expenses within their branch. Cannot manage system-wide settings, users, import, or branch definitions. Cannot see other branches. |

**Capabilities granted:**

| Capability | Granted |
|---|---|
| opb_manage_settings | ❌ |
| opb_manage_users | ❌ |
| opb_view_all_branches | ❌ |
| opb_manage_clients | ✅ |
| opb_manage_pets | ✅ |
| opb_manage_bookings | ✅ |
| opb_manage_invoices | ✅ |
| opb_record_payments | ✅ |
| opb_manage_tasks | ✅ |
| opb_manage_expenses | ✅ |
| opb_run_import | ❌ |
| opb_view_reports | ✅ |

---

### Role 3 — opb_reception

| Field | Value |
|---|---|
| **Role key** | `opb_reception` |
| **Display name** | OPB Reception |
| **Registered in** | `plugin/includes/class-opb-roles.php` |
| **Registration function** | `OPB_Roles::maybe_add_roles()` |
| **Status** | Active |
| **Purpose** | Front-desk operations within an assigned branch. Handles client intake, bookings, invoicing, and payment recording. Cannot access expenses, financial reports, settings, user management, or import. |

**Capabilities granted:**

| Capability | Granted |
|---|---|
| opb_manage_settings | ❌ |
| opb_manage_users | ❌ |
| opb_view_all_branches | ❌ |
| opb_manage_clients | ✅ |
| opb_manage_pets | ✅ |
| opb_manage_bookings | ✅ |
| opb_manage_invoices | ✅ |
| opb_record_payments | ✅ |
| opb_manage_tasks | ✅ |
| opb_manage_expenses | ❌ |
| opb_run_import | ❌ |
| opb_view_reports | ❌ |

---

### Role 4 — opb_staff

| Field | Value |
|---|---|
| **Role key** | `opb_staff` |
| **Display name** | OPB Staff |
| **Registered in** | `plugin/includes/class-opb-roles.php` |
| **Registration function** | `OPB_Roles::maybe_add_roles()` |
| **Status** | Active |
| **Purpose** | Ground-level staff (kennel attendants, caretakers). Read access to bookings and clients, and the ability to create, update, and close tasks. No financial or administrative access. |

**Capabilities granted:**

| Capability | Granted |
|---|---|
| opb_manage_settings | ❌ |
| opb_manage_users | ❌ |
| opb_view_all_branches | ❌ |
| opb_manage_clients | ❌ |
| opb_manage_pets | ❌ |
| opb_manage_bookings | ❌ |
| opb_manage_invoices | ❌ |
| opb_record_payments | ❌ |
| opb_manage_tasks | ✅ |
| opb_manage_expenses | ❌ |
| opb_run_import | ❌ |
| opb_view_reports | ❌ |

---

### Implicit Role — WordPress administrator

| Field | Value |
|---|---|
| **Role key** | `administrator` |
| **Display name** | Administrator |
| **Registered by** | WordPress core |
| **Status** | Active — treated as OPB super-user |
| **Purpose** | WordPress site administrator. Holds `manage_options`. The OPB code treats `manage_options` as an implicit bypass for all OPB capability checks AND as the sole gate for OPSMAIL and SAL. |

The `OPB_Roles::has_opb_role()` method explicitly includes `manage_options` users:

```php
return user_can( $user, 'manage_options' );
```

The `OPB_Roles::get_user_branch_id()` method returns `0` (unrestricted) for `manage_options` users — even if they have no `opb_branch_id` meta.

---

## 3. Role Comparison Matrix

| Capability | opb_super_admin | opb_branch_manager | opb_reception | opb_staff | WP administrator |
|---|:---:|:---:|:---:|:---:|:---:|
| opb_manage_settings | ✅ | ❌ | ❌ | ❌ | via manage_options |
| opb_manage_users | ✅ | ❌ | ❌ | ❌ | via manage_options |
| opb_view_all_branches | ✅ | ❌ | ❌ | ❌ | via manage_options |
| opb_manage_clients | ✅ | ✅ | ✅ | ❌ | via manage_options |
| opb_manage_pets | ✅ | ✅ | ✅ | ❌ | via manage_options |
| opb_manage_bookings | ✅ | ✅ | ✅ | ❌ | via manage_options |
| opb_manage_invoices | ✅ | ✅ | ✅ | ❌ | via manage_options |
| opb_record_payments | ✅ | ✅ | ✅ | ❌ | via manage_options |
| opb_manage_tasks | ✅ | ✅ | ✅ | ✅ | via manage_options |
| opb_manage_expenses | ✅ | ✅ | ❌ | ❌ | via manage_options |
| opb_run_import | ✅ | ❌ | ❌ | ❌ | via manage_options |
| opb_view_reports | ✅ | ✅ | ❌ | ❌ | via manage_options |
| manage_options (OPSMAIL/SAL) | ❌ | ❌ | ❌ | ❌ | ✅ (native) |

---

## 4. Role Hierarchy

```
WordPress administrator (manage_options)
  └── Full OPB + OPSMAIL + SAL access

opb_super_admin
  └── Full OPB access (all 12 caps)
  └── ⚠ NO access to OPSMAIL / SAL

opb_branch_manager
  └── Client/Pet/Booking/Invoice/Payment/Task/Expense/Reports
  └── Branch-scoped only

opb_reception
  └── Client/Pet/Booking/Invoice/Payment/Task
  └── Branch-scoped only

opb_staff
  └── Task management only
  └── Read access to clients/bookings (via permission_check)
  └── Branch-scoped only
```

---

## 5. Cleanup Functions

| Function | Effect |
|---|---|
| `OPB_Roles::register()` | Called on `init`; version-gates role registration |
| `OPB_Roles::remove()` | Called on plugin deactivation; removes all 4 OPB roles and clears `opb_roles_version` option |
| `OPB_Roles::has_opb_role()` | Returns true if current user has any OPB role OR `manage_options` |
| `OPB_Roles::get_user_branch_id()` | Returns 0 (unrestricted) for `opb_view_all_branches` or `manage_options` |
| `OPB_Roles::current_user_can_access_branch($id)` | Returns true if user has `opb_view_all_branches` or meta matches branch |
