# OPB Permission Audit — Part 2: Capability Inventory

**Plugin Version:** 3.1.0  
**Audit Date:** June 2026  
**Scope:** Every capability used, checked, or granted anywhere in OPB

---

## 1. OPB-Defined Capabilities

All 12 OPB capabilities are declared in the `OPB_Roles::CAPS` constant in `plugin/includes/class-opb-roles.php`. They use a consistent `opb_` prefix and a `opb_verb_noun` naming pattern.

---

### CAP-01: opb_manage_settings

| Field | Value |
|---|---|
| **Purpose** | Controls access to system-wide configuration: branches, boarding catalogues, addon services, kennels, expense categories, and OPB customization keys. Also gates the Data Management (archive/restore) module. |
| **Roles possessing it** | `opb_super_admin` |
| **Implicit holders** | WP `administrator` (via `manage_options` bypass) |

**Files that check this capability:**

| File | Usage |
|---|---|
| `api/class-opb-branches-api.php` | Create branch, update branch |
| `api/class-opb-settings-api.php` | Create/update/delete boarding services, create/update/delete addon services |
| `api/class-opb-kennels-api.php` | Create/update/delete kennels, assign/remove kennel staff, reorder |
| `api/class-opb-customizations-api.php` | Edit customization keys (`permission_edit`) |
| `api/class-opb-expense-categories-api.php` | Create/update/archive expense categories |
| `api/class-opb-data-management-api.php` | Archive/restore clients, pets, bookings, inquiries (direct `current_user_can` check) |

---

### CAP-02: opb_manage_users

| Field | Value |
|---|---|
| **Purpose** | Controls access to the OPB Staff management screen — listing WP users with OPB roles and updating their role/branch assignment. |
| **Roles possessing it** | `opb_super_admin` |
| **Implicit holders** | WP `administrator` |

**Files that check this capability:**

| File | Usage |
|---|---|
| `api/class-opb-settings-api.php` | `get_staff`, `update_staff` |

---

### CAP-03: opb_view_all_branches

| Field | Value |
|---|---|
| **Purpose** | Grants unrestricted cross-branch data access. Without it, the `get_user_branch_id()` function returns the user's `opb_branch_id` meta, which scopes all queries to one branch. |
| **Roles possessing it** | `opb_super_admin` |
| **Implicit holders** | WP `administrator` (via `manage_options` check in `get_user_branch_id()`) |

**Files that check this capability:**

| File | Usage |
|---|---|
| `includes/class-opb-roles.php` | `get_user_branch_id()`, `current_user_can_access_branch()` |
| `api/class-opb-rest-base.php` | `branch_filter()` calls `get_user_branch_id()` |

> This capability is never used directly in `permission_manage()` calls. It is only consumed by the branch-scoping helper functions.

---

### CAP-04: opb_manage_clients

| Field | Value |
|---|---|
| **Purpose** | Write access to the Clients module and Inquiries/Onboarding pipeline: create, edit, and convert inquiries to clients. Also used for pet creation within client context. |
| **Roles possessing it** | `opb_super_admin`, `opb_branch_manager`, `opb_reception` |

**Files that check this capability:**

| File | Usage |
|---|---|
| `api/class-opb-clients-api.php` | Update client |
| `api/class-opb-inquiries-api.php` | Update inquiry, send/resend onboarding link, reject, archive, convert to client |

---

### CAP-05: opb_manage_pets

| Field | Value |
|---|---|
| **Purpose** | Write access to pet records and pet documents (photos, vaccination certificates). Covers create, update, and delete of pet document attachments. |
| **Roles possessing it** | `opb_super_admin`, `opb_branch_manager`, `opb_reception` |

**Files that check this capability:**

| File | Usage |
|---|---|
| `api/class-opb-clients-api.php` | Create pet on client |
| `api/class-opb-pets-api.php` | Update pet, upload document, delete document |

---

### CAP-06: opb_manage_bookings

| Field | Value |
|---|---|
| **Purpose** | Write access to the Bookings and Boarding module: create booking, check in, check out, assign kennels, add/remove addon services, update booking details. |
| **Roles possessing it** | `opb_super_admin`, `opb_branch_manager`, `opb_reception` |

**Files that check this capability:**

| File | Usage |
|---|---|
| `api/class-opb-bookings-api.php` | Create booking, update booking, check in, check out, assign kennel, add addon, remove addon |

---

### CAP-07: opb_manage_invoices

| Field | Value |
|---|---|
| **Purpose** | Write access to invoice adjustments and payment deletion. Also controls invoice PDF generation and delivery actions. |
| **Roles possessing it** | `opb_super_admin`, `opb_branch_manager`, `opb_reception` |

**Files that check this capability:**

| File | Usage |
|---|---|
| `api/class-opb-invoices-api.php` | Adjust invoice |
| `api/class-opb-payments-api.php` | Delete payment record |
| `api/class-opb-invoice-delivery-api.php` | Generate PDF, send invoice by email |

---

### CAP-08: opb_record_payments

| Field | Value |
|---|---|
| **Purpose** | Allows recording a new payment against an invoice. Separated from `opb_manage_invoices` to allow roles that can take payments but not adjust invoices. |
| **Roles possessing it** | `opb_super_admin`, `opb_branch_manager`, `opb_reception` |

**Files that check this capability:**

| File | Usage |
|---|---|
| `api/class-opb-invoices-api.php` | Record payment (`POST /invoices/{id}/payment`) |

> **Observation:** `opb_record_payments` and `opb_manage_invoices` are both granted to the same three roles. The separation exists in the capability design but produces no practical access difference with the current role set.

---

### CAP-09: opb_manage_tasks

| Field | Value |
|---|---|
| **Purpose** | Create, update, and delete task records. The only capability held by `opb_staff`, making it the entry point for ground-level staff. |
| **Roles possessing it** | `opb_super_admin`, `opb_branch_manager`, `opb_reception`, `opb_staff` |

**Files that check this capability:**

| File | Usage |
|---|---|
| `api/class-opb-tasks-api.php` | Create task, update task, delete task |

---

### CAP-10: opb_manage_expenses

| Field | Value |
|---|---|
| **Purpose** | Create and delete expense records. Scoped to branch managers and above. Reception staff cannot access expenses. |
| **Roles possessing it** | `opb_super_admin`, `opb_branch_manager` |

**Files that check this capability:**

| File | Usage |
|---|---|
| `api/class-opb-expenses-api.php` | Create expense, delete expense |

---

### CAP-11: opb_run_import

| Field | Value |
|---|---|
| **Purpose** | Access to the legacy data import tool (dry-run, run, status, history). Restricted to system administrators performing migrations. |
| **Roles possessing it** | `opb_super_admin` |

**Files that check this capability:**

| File | Usage |
|---|---|
| `api/class-opb-import-api.php` | All import endpoints (dry run, run, status, history) |

---

### CAP-12: opb_view_reports

| Field | Value |
|---|---|
| **Purpose** | Intended to gate access to the financial reports module. |
| **Roles possessing it** | `opb_super_admin`, `opb_branch_manager` |

**Files that check this capability:**

| File | Usage |
|---|---|
| `api/class-opb-reports-api.php` | ✅ **Enforced** — `permission_callback` uses `permission_manage('opb_view_reports')`. `opb_reception` and `opb_staff` receive 403. |

> This is documented as a conflict in Part 7 and a security finding in Part 9.

---

## 2. WordPress Native Capabilities Used by OPB

### manage_options

| Field | Value |
|---|---|
| **Source** | WordPress core — held natively by the `administrator` role |
| **OPB usage** | (a) Bypass check in `permission_manage()` — any `manage_options` user passes any capability gate. (b) Exclusive gate for OPSMAIL and SAL via `super_admin_only()`. (c) Unrestricted branch scope in `get_user_branch_id()`. |
| **Roles with it in OPB** | WP `administrator` only |

---

## 3. Capability-to-Role Summary Table

| Capability | opb_super_admin | opb_branch_manager | opb_reception | opb_staff | WP administrator |
|---|:---:|:---:|:---:|:---:|:---:|
| opb_manage_settings | ✅ | — | — | — | bypass |
| opb_manage_users | ✅ | — | — | — | bypass |
| opb_view_all_branches | ✅ | — | — | — | bypass |
| opb_manage_clients | ✅ | ✅ | ✅ | — | bypass |
| opb_manage_pets | ✅ | ✅ | ✅ | — | bypass |
| opb_manage_bookings | ✅ | ✅ | ✅ | — | bypass |
| opb_manage_invoices | ✅ | ✅ | ✅ | — | bypass |
| opb_record_payments | ✅ | ✅ | ✅ | — | bypass |
| opb_manage_tasks | ✅ | ✅ | ✅ | ✅ | bypass |
| opb_manage_expenses | ✅ | ✅ | — | — | bypass |
| opb_run_import | ✅ | — | — | — | bypass |
| opb_view_reports | ✅ | ✅ | — | — | bypass |
| manage_options | — | — | — | — | ✅ native |

---

## 4. Capabilities Declared but Not Fully Enforced

All 12 OPB capabilities are correctly declared, assigned, and enforced. No gaps remain.

## 5. Capabilities With No Direct REST Check

These capabilities are checked indirectly (through `permission_manage`) but never via a standalone `current_user_can()` call outside of the REST base:

- All 12 OPB capabilities are routed exclusively through `permission_manage()`.
- The exception is `opb_manage_settings` in the Data Management API, which uses a direct `current_user_can()` closure.
