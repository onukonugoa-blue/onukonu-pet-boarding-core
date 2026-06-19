# OPB Permission Audit — Part 3: User Type Audit

**Plugin Version:** 3.1.0  
**Audit Date:** June 2026  
**Scope:** All user types visible inside OPB, their storage mechanism, and their relationship to WordPress roles and branch scope

---

## 1. Observed User-Facing Screens

Two separate screens in the system present user-related information:

| Screen | Location | What it Shows |
|---|---|---|
| **WordPress User Creation** | WP Admin → Users → Add New | A dropdown listing OPB roles alongside standard WP roles |
| **OPB Staff Management** | OPB → Settings → Staff | A table of users holding OPB roles, with their assigned branch |

These screens appear to present two different "user type" models. This part of the audit determines whether a second, independent user type system exists.

---

## 2. WordPress User Creation Screen

When a new WordPress user is created via WP Admin → Users → Add New, the role dropdown includes:

- Subscriber
- Contributor
- Author
- Editor
- Administrator
- **OPB Super Admin**
- **OPB Branch Manager**
- **OPB Reception**
- **OPB Staff**

The OPB roles appear because `add_role()` registers them as native WordPress roles. WordPress automatically includes all registered roles in the role dropdown. There is no custom logic adding these — it is standard WP behaviour.

**Conclusion:** The WP User Creation screen shows OPB roles because they are registered as standard WordPress roles. They are not a separate type system.

---

## 3. OPB Staff Management Screen (Settings → Staff)

**API endpoint:** `GET /opb/v1/settings/staff`  
**Permission required:** `opb_manage_users`

The `get_staff()` method queries WordPress for all users with an OPB role or the `administrator` role:

```php
$users = get_users(['role__in' => array_merge($opb_roles, ['administrator'])]);
```

For each user, it returns:

| Field | Source | Notes |
|---|---|---|
| `id` | `WP_User->ID` | WP user ID |
| `name` | `WP_User->display_name` | WP display name |
| `email` | `WP_User->user_email` | WP email |
| `roles` | `WP_User->roles` | Array of all WP roles on the user |
| `branch_id` | `get_user_meta($id, 'opb_branch_id', true)` | User meta — 0 or null means unassigned |

The React Staff page (`plugin/app/src/pages/settings/Staff.tsx`) renders:
- User name and email
- The first OPB role found on the user (or `administrator`)
- The branch name resolved from `branch_id`

The inline edit form (`update_staff`) allows changing:
- The user's OPB role (using `WP_User::set_role()`)
- The user's branch assignment (updating `opb_branch_id` user meta)

---

## 4. User Type Architecture

### 4.1 Is There a Second User Type Model?

**No.** There is no separate OPB user type table, user type meta key, or parallel identity system. The two screens that appear to show different user type models are both views onto the same underlying data:

- WP roles → determine what a user can do
- `opb_branch_id` user meta → determines which branch a user is scoped to

The apparent two-model appearance is because:
1. WordPress shows all roles globally (including OPB roles) in the user creation dropdown
2. OPB's own screen filters the user list to OPB-relevant roles and surfaces the branch assignment alongside

Both screens describe the same WP user object. There is one user model.

### 4.2 Classification of User Types Under the Audit Framework

Applying the audit framework's classification options:

| User Type | Classification | Explanation |
|---|:---:|---|
| opb_super_admin | **A — WordPress role** | Registered via `add_role()` |
| opb_branch_manager | **A — WordPress role** | Registered via `add_role()` |
| opb_reception | **A — WordPress role** | Registered via `add_role()` |
| opb_staff | **A — WordPress role** | Registered via `add_role()` |
| Branch assignment | **B — Branch assignment** | Stored as `opb_branch_id` WP user meta |
| WP administrator | **A — WordPress role** (with OPB bypass) | Native WP role; treated as OPB super-user |

There are no examples of classification types C (profile metadata as a user type), D (permission overlay), or E (hybrid implementation) in the current system. Branch assignment is strictly metadata, not a user type.

---

## 5. Client Portal User Identity

The OPB client portal (`/my-pets/`) uses a completely separate authentication mechanism:

| Aspect | Implementation |
|---|---|
| **Identity** | OPB client record (`opb_clients` table, `id` and `phone`) |
| **Authentication** | Email OTP — no WordPress login required |
| **Session** | Cookie: `opb_client_session` (session token stored in a dedicated OPB session table) |
| **WP user account** | Not required. Clients are NOT WordPress users. |
| **REST permission** | `/client/auth/*` endpoints use `__return_true`; session validated inside the callback. `/client/me` also `__return_true` with internal session check. |

**Conclusion:** The client portal identity is completely separate from the WP/OPB staff user model. Clients are **not** WordPress users and hold **no** WordPress roles or OPB capabilities. Their authentication is custom (OTP + session cookie).

---

## 6. User Storage Summary

| Entity | Identity Store | Auth Mechanism | Role/Permission Store |
|---|---|---|---|
| WP Administrator | `wp_users` table | WordPress login | `wp_usermeta` (role) |
| OPB Super Admin | `wp_users` table | WordPress login | `wp_usermeta` (role + no branch limit) |
| OPB Branch Manager | `wp_users` table | WordPress login | `wp_usermeta` (role + `opb_branch_id`) |
| OPB Reception | `wp_users` table | WordPress login | `wp_usermeta` (role + `opb_branch_id`) |
| OPB Staff | `wp_users` table | WordPress login | `wp_usermeta` (role + `opb_branch_id`) |
| Client (portal) | `opb_clients` table | Email OTP + session cookie | None (read-only portal) |

---

## 7. Relationship Diagram

```
WordPress User Account (wp_users)
  │
  ├── WP Role (wp_usermeta: wp_capabilities)
  │     ├── opb_super_admin      → 12 OPB capabilities
  │     ├── opb_branch_manager   → 8 OPB capabilities
  │     ├── opb_reception        → 6 OPB capabilities
  │     ├── opb_staff            → 1 OPB capability
  │     └── administrator        → manage_options (full bypass)
  │
  └── Branch Assignment (wp_usermeta: opb_branch_id)
        ├── 0 / null  → Unrestricted (super admin / administrator)
        └── {branch_id} → Scoped to one branch
            (enforced by OPB_Roles::get_user_branch_id() + REST base branch_filter())

OPB Client Record (opb_clients)  ← Entirely separate identity
  └── Authenticated via Email OTP + opb_client_session cookie
        └── No WP account, no WP role, no OPB capabilities
```
