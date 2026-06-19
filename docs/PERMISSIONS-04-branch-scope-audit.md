# OPB Permission Audit — Part 4: Branch Scope Audit

**Plugin Version:** 3.2.0  
**Audit Date:** June 2026  
**Status:** ✅ Hardened for RC1  
**Scope:** How branch restrictions are implemented and enforced across all OPB REST endpoints

---

## 1. Branch Scoping Architecture

OPB operates across three branches. Branch data isolation is enforced through two mechanisms that work together:

| Mechanism | Location | Role |
|---|---|---|
| **`get_user_branch_id()`** | `class-opb-roles.php` | Determines the current user's branch restriction (0 = unrestricted, >0 = single branch, **-1 = denied**) |
| **`branch_filter()`** | `class-opb-rest-base.php` | Applied in query-building logic to override or enforce the branch_id on every REST request |

### Role Classification

| Role | Type | Branch Required? |
|---|---|---|
| WP `administrator` | **Global** | No |
| `opb_super_admin` | **Global** | No |
| `opb_branch_manager` | **Branch-scoped** | **Yes** |
| `opb_reception` | **Branch-scoped** | **Yes** |
| `opb_staff` | **Branch-scoped** | **Yes** |

The set of branch-scoped roles is defined as `OPB_Roles::BRANCH_SCOPED_ROLES` and is the single authoritative list used by all enforcement points.

---

## 2. Branch Identity Storage

Branch assignment for a staff user is stored as WordPress user meta:

| Meta Key | Value Type | Meaning |
|---|---|---|
| `opb_branch_id` | Integer | The `id` of the branch this user is restricted to. Must be > 0 for branch-scoped roles. |

This meta is read-only to branch-scoped users (they cannot change their own branch). It is writable only via:
- `PUT /opb/v1/settings/staff/{id}` (requires `opb_manage_users`)
- WP Admin → Users → Edit User (requires `manage_options` or `opb_manage_users`)

---

## 3. The Branch Scope Decision Function (updated)

**`OPB_Roles::get_user_branch_id()`** — `plugin/includes/class-opb-roles.php`

```
If user has manage_options          → return  0  (global — unrestricted)
If user has opb_view_all_branches   → return  0  (global — unrestricted)
If user is branch-scoped AND opb_branch_id < 1
                                    → return -1  (DENIED — configuration error)
Else                                → return opb_branch_id (positive int)
```

| User / Role | Result |
|---|---|
| WP `administrator` | `0` — unrestricted |
| `opb_super_admin` | `0` — unrestricted (holds `opb_view_all_branches`) |
| `opb_branch_manager` with branch set | Positive branch ID |
| `opb_reception` with branch set | Positive branch ID |
| `opb_staff` with branch set | Positive branch ID |
| Any branch-scoped role with no branch | **`-1` — denied sentinel** |

The `-1` sentinel was introduced in v3.2.0 to replace the previous behaviour where a missing branch returned `0` (unrestricted). This closed the cross-branch access gap.

---

## 4. The Branch Filter Function (updated)

**`OPB_REST_Base::branch_filter(int $branch_id)`** — `plugin/includes/api/class-opb-rest-base.php`

```
user_branch = get_user_branch_id()
If user_branch = -1  → return PHP_INT_MAX  (safe dead-end; permission_check() always fires first)
If user_branch != 0  → return user_branch   (ignore requested branch_id)
Else                 → return branch_id     (unrestricted user chooses branch)
```

`permission_check()` always runs before query logic and blocks on `-1`. The `PHP_INT_MAX` return in `branch_filter()` is a defence-in-depth measure only — it should never be reached in normal execution.

---

## 5. Runtime Safeguard (new)

**`OPB_REST_Base::permission_check()`** — `plugin/includes/api/class-opb-rest-base.php`

All OPB REST endpoints call `permission_check()` before any query logic. As of v3.2.0 it includes an explicit branch-assignment guard:

```php
if ( OPB_Roles::get_user_branch_id() === -1 ) {
    return new WP_Error(
        'opb_no_branch',
        'Your account has no branch assignment. Please contact an administrator.',
        [ 'status' => 403 ]
    );
}
```

**Effect:** A branch-scoped user without a branch assignment receives **HTTP 403** on every OPB REST endpoint. They receive no data from any branch. This is treated as a configuration error, not a permission level.

---

## 6. Validation at Write Time (new)

### 6.1 REST API — `PUT /opb/v1/settings/staff/{id}`

`update_staff()` in `class-opb-settings-api.php` now validates the role/branch combination before applying any change:

```
Effective role = new role (if supplied) OR existing OPB role
If effective role is branch-scoped:
    effective branch_id = new branch_id (if supplied) OR stored meta value
    If effective branch_id < 1 → HTTP 400 "Branch assignment is required..."
```

This prevents the API from creating a branch-scoped user without a branch, regardless of whether the role and branch are set in the same request or separately.

### 6.2 WP Admin — Edit User screen

`class-opb-user-admin.php` adds an "OPB Branch Assignment" section to the WP Admin Edit User profile page. When a branch-scoped role is selected, the branch dropdown is marked required and the `user_profile_update_errors` hook validates before save:

- If role is branch-scoped and no branch selected → save is blocked, WP error displayed.
- Error message: *"Branch assignment is required for Branch Manager, Reception and Staff roles."*

### 6.3 WP Admin — Add New User screen

The branch field is injected via the `user_new_form` action. JavaScript shows/hides the branch dropdown based on the selected role and sets the `required` attribute when a branch-scoped role is chosen. A server-side fallback in the `user_register` hook creates an admin notice if a branch-scoped user was created without a branch.

---

## 7. Admin Warning Panel (new)

**Location:** WP Admin → Pet Boarding → User Management

A PHP-rendered page (no React rebuild required) shows:

- **⚠ Unassigned users panel** — lists every branch-scoped user without a branch, with quick links to Edit User.
- **All OPB Staff table** — shows all OPB role users with their role, branch assignment, and status (OK / ⚠ No Branch).

**Admin notices:** An `admin_notices` hook fires on OPB admin pages and displays a summary warning when any unassigned branch-scoped users exist, linking to the User Management page.

Visibility: WP `administrator` (anyone with `manage_options`).

---

## 8. Branch Access Matrix by Operation (updated)

### 8.1 View (Read) Access by Branch

| Role | Own Branch | Other Branches | All Branches | No Branch Assigned |
|---|:---:|:---:|:---:|:---:|
| WP administrator | ✅ | ✅ | ✅ | ✅ (global) |
| opb_super_admin | ✅ | ✅ | ✅ | ✅ (global) |
| opb_branch_manager | ✅ | ❌ | ❌ | **403 — config error** |
| opb_reception | ✅ | ❌ | ❌ | **403 — config error** |
| opb_staff | ✅ | ❌ | ❌ | **403 — config error** |

### 8.2 Write Access by Branch

| Role | Own Branch | Other Branches | No Branch Assigned |
|---|:---:|:---:|:---:|
| WP administrator | ✅ | ✅ | ✅ (global) |
| opb_super_admin | ✅ | ✅ | ✅ (global) |
| opb_branch_manager | ✅ | ❌ | **403 — config error** |
| opb_reception | ✅ | ❌ | **403 — config error** |
| opb_staff | ❌ (read-only) | ❌ | **403 — config error** |

---

## 9. Enforcement Point Summary

| Layer | Enforcement | File |
|---|---|---|
| REST permission gate | `permission_check()` blocks -1 sentinel → 403 | `class-opb-rest-base.php` |
| REST query isolation | `branch_filter()` locks query to user's branch | `class-opb-rest-base.php` |
| REST write validation | `update_staff()` rejects branch-scoped role without branch | `class-opb-settings-api.php` |
| WP Edit User | `user_profile_update_errors` prevents save | `class-opb-user-admin.php` |
| WP Add New User | JS required field + `user_register` notice | `class-opb-user-admin.php` |
| Admin visibility | User Management page + admin_notices | `class-opb-admin-page.php`, `class-opb-user-admin.php` |

Under no circumstances does a missing branch assignment result in unrestricted access. The worst possible outcome for a misconfigured branch-scoped user is a 403 error on every OPB endpoint.

---

## 10. Migration Notes for Existing Installations

On upgrade to v3.2.0:

1. **Existing branch-scoped users with a branch** — no change. They continue to function normally.

2. **Existing branch-scoped users without a branch** — they will receive HTTP 403 on all OPB REST endpoints immediately after the plugin update. This is the correct behaviour.

   **Action required:** An administrator must visit **WP Admin → Pet Boarding → User Management** after upgrading and assign branches to any users shown in the ⚠ panel.

3. **Admin visibility** — the User Management page and admin_notices warning are visible to WP administrators. The warning will appear automatically after upgrade if any unassigned users are detected.

4. **No database changes** — `opb_branch_id` user meta already exists. No migration queries are required.

---

## 11. Testing Acceptance Criteria

| # | Test | Expected |
|---|---|---|
| 1 | Create Branch Manager via WP admin without a branch | Blocked — validation error displayed |
| 2 | Create Reception via WP admin without a branch | Blocked — validation error displayed |
| 3 | Create Staff via WP admin without a branch | Blocked — validation error displayed |
| 4 | Existing branch-scoped user with a branch calls any OPB endpoint | HTTP 200 — normal response |
| 5 | Existing branch-scoped user without a branch calls any OPB endpoint | HTTP 403 — "Your account has no branch assignment" |
| 6 | `PUT /settings/staff/{id}` with branch-scoped role and no branch_id | HTTP 400 — "Branch assignment is required" |
| 7 | OPB Super Admin calls any OPB endpoint (no branch meta) | HTTP 200 — unrestricted |
| 8 | WP Administrator calls any OPB endpoint (no branch meta) | HTTP 200 — unrestricted |
| 9 | User Management page shows unassigned users in ⚠ panel | Correct list with Edit links |
| 10 | Admin notice appears on OPB pages when unassigned users exist | Warning with link to User Management |
