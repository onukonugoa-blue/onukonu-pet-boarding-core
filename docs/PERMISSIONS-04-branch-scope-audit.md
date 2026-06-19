# OPB Permission Audit — Part 4: Branch Scope Audit

**Plugin Version:** 3.1.0  
**Audit Date:** June 2026  
**Scope:** How branch restrictions are implemented and enforced across all OPB REST endpoints

---

## 1. Branch Scoping Architecture

OPB operates across three branches. Branch data isolation is enforced through two mechanisms that work together:

| Mechanism | Location | Role |
|---|---|---|
| **`get_user_branch_id()`** | `class-opb-roles.php` | Determines the current user's branch restriction (0 = unrestricted, >0 = single branch) |
| **`branch_filter()`** | `class-opb-rest-base.php` | Applied in query-building logic to override or enforce the branch_id on every REST request |

---

## 2. Branch Identity Storage

Branch assignment for a staff user is stored as WordPress user meta:

| Meta Key | Value Type | Meaning |
|---|---|---|
| `opb_branch_id` | Integer | The `id` of the branch this user is restricted to. `0` or empty means unrestricted. |

This meta is read-only to branch-scoped users (they cannot change their own branch). It is writable only via `PUT /opb/v1/settings/staff/{id}`, which requires `opb_manage_users`.

---

## 3. The Branch Scope Decision Function

**`OPB_Roles::get_user_branch_id()`** — `plugin/includes/class-opb-roles.php`

```
If user has manage_options  → return 0  (unrestricted)
If user has opb_view_all_branches → return 0  (unrestricted)
Else → return (int) get_user_meta(user_id, 'opb_branch_id', true)
```

| User / Role | Result |
|---|---|
| WP `administrator` | 0 — unrestricted |
| `opb_super_admin` | 0 — unrestricted (holds `opb_view_all_branches`) |
| `opb_branch_manager` | Value of `opb_branch_id` meta |
| `opb_reception` | Value of `opb_branch_id` meta |
| `opb_staff` | Value of `opb_branch_id` meta |
| Any role with `opb_branch_id = 0` or unset | 0 — effectively unrestricted (no meta restriction applied) |

> **Risk note:** If a branch-scoped role user has `opb_branch_id` unset (0 or null), `get_user_branch_id()` returns 0, and `branch_filter()` will not restrict their data access. Staff users without a branch assignment are inadvertently unrestricted. This is documented in Part 9.

---

## 4. The Branch Filter Function

**`OPB_REST_Base::branch_filter(int $branch_id)`** — `plugin/includes/api/class-opb-rest-base.php`

```
user_branch = get_user_branch_id()
If user_branch != 0 → return user_branch   (ignore requested branch_id)
Else → return requested branch_id           (pass through — unrestricted user chooses branch)
```

This means:
- **Restricted users:** the `branch_id` parameter in the request is silently overridden with the user's assigned branch. They cannot request data from another branch.
- **Unrestricted users:** the `branch_id` parameter is passed through as-is. They can explicitly query any branch, or query all branches if `branch_id = 0`.

---

## 5. Branch Access Matrix by Operation

### 5.1 View (Read) Access by Branch

| Role | Own Branch | Other Branches | All Branches |
|---|:---:|:---:|:---:|
| WP administrator | ✅ | ✅ | ✅ |
| opb_super_admin | ✅ | ✅ | ✅ |
| opb_branch_manager | ✅ | ❌ | ❌ |
| opb_reception | ✅ | ❌ | ❌ |
| opb_staff | ✅ | ❌ | ❌ |

*Branch-scoped users cannot explicitly request another branch's data — `branch_filter()` overrides any branch parameter they send.*

### 5.2 Create Records in a Branch

| Role | Own Branch | Other Branches |
|---|:---:|:---:|
| WP administrator | ✅ | ✅ |
| opb_super_admin | ✅ | ✅ |
| opb_branch_manager | ✅ (branch_filter applied) | ❌ |
| opb_reception | ✅ (branch_filter applied) | ❌ |
| opb_staff | ✅ (tasks, branch_filter applied) | ❌ |

### 5.3 Edit Branch Records (branch/settings data)

Creating or editing branch definitions (`opb_branches` table), boarding catalogues, kennel layouts, and addon services requires `opb_manage_settings`.

| Role | Can Create/Edit Branch Definition | Can Edit Branch Catalogues |
|---|:---:|:---:|
| WP administrator | ✅ | ✅ |
| opb_super_admin | ✅ | ✅ |
| opb_branch_manager | ❌ | ❌ |
| opb_reception | ❌ | ❌ |
| opb_staff | ❌ | ❌ |

### 5.4 Delete Branch Records

No hard-delete endpoint exists for branch definitions in the REST API. Branches are soft-managed (the `is_active` column on `opb_branches`). Branch deletion is not exposed.

---

## 6. Module-Level Branch Enforcement

Branch filtering is applied through `branch_filter()` in the query-building logic of each API module. The table below shows where branch filtering is in effect:

| Module | Branch Filter Applied | Mechanism |
|---|:---:|---|
| Bookings | ✅ | `branch_filter()` on `bk.branch_id` |
| Clients | ✅ | `branch_filter()` on `c.home_branch_id` |
| Invoices | ✅ | `branch_filter()` on `i.branch_id` |
| Payments | ✅ | `branch_filter()` on `py.branch_id` |
| Expenses | ✅ | `branch_filter()` on `e.branch_id` |
| Tasks | ✅ | `branch_filter()` on `t.branch_id` |
| Dashboard | ✅ | `branch_filter()` for all counts |
| Reports | ✅ | `branch_filter()` on report queries |
| Kennels | ✅ | `branch_filter()` on `k.branch_id` |
| Boarding Services | ✅ | `branch_filter()` on `branch_id` |
| Data Management | ✅ | Scoped queries by design (operates cross-branch for super admin) |
| Inquiries | ✅ | `branch_filter()` on `i.branch_id` |
| OPSMAIL | ✅ | Queue queries include branch scope |
| SAL | N/A | SAL is system-wide, no branch concept |
| Health | N/A | System-level endpoint, no branch concept |
| Customizations | N/A | System-level settings, no branch concept |
| Import | N/A | System-level operation |

---

## 7. Branch Bypass Paths

The following paths can result in cross-branch data being accessible to a branch-scoped user:

| Bypass Path | Condition | Risk Level |
|---|---|---|
| `opb_branch_id` meta = 0 or unset | Branch-scoped role with missing meta acts as unrestricted | Medium |
| `opb_view_all_branches` manually granted to branch-scoped role | Admin grants cap outside normal role definition | Low (admin action required) |
| `manage_options` granted to branch-scoped role | Any user given `manage_options` bypasses all OPB gates | High (admin action required) |
| Client portal (`/client/auth/*`, `/client/me`) | Entirely separate auth, no branch concept | N/A — clients can only see their own records |

---

## 8. Branch Access Control Summary

**Enforcement layers (in order of evaluation):**

```
1. REST permission_callback
   └── permission_check() — must be logged in + have OPB role
       └── permission_manage($cap) — must have specific capability

2. branch_filter() in query-building
   └── get_user_branch_id() → 0 (unrestricted) or branch_id
       └── If restricted: overrides any branch_id in the request
           └── All SQL WHERE clauses receive the user's branch_id
```

**What branch scoping protects:**
- A branch manager at Branch A cannot view, create, or edit records at Branch B
- The restriction is enforced server-side on every query; it is not dependent on the React frontend

**What branch scoping does NOT protect:**
- A user with no `opb_branch_id` meta set (returns 0, unrestricted)
- The Reports module lacks the `opb_view_reports` capability check (any OPB user can call it), though branch filtering still limits the data returned to their branch
