---
name: Permission audit v3.1.0
description: Key findings and architecture decisions from the OPB permission/role/scope audit — durable facts needed before any future role or capability changes
---

## Key Architecture Facts

- 4 OPB roles: opb_super_admin (12 caps), opb_branch_manager (8), opb_reception (6), opb_staff (1)
- 12 caps in OPB_Roles::CAPS; all `opb_` prefixed
- WP `administrator` (manage_options) bypasses all OPB capability checks AND is the only role that can access OPSMAIL/SAL
- opb_super_admin does NOT have manage_options — cannot access OPSMAIL or SAL endpoints

## Permission Check Hierarchy

- `permission_check()` = logged in + has any OPB role or manage_options
- `permission_manage($cap)` = permission_check + has $cap OR manage_options
- `super_admin_only()` = permission_manage('manage_options') — OPSMAIL and SAL only

## Branch Scoping

- opb_branch_id user meta is the only branch assignment store
- get_user_branch_id() returns 0 (unrestricted) for opb_view_all_branches or manage_options
- branch_filter() in REST base overrides any request branch_id with the user's assigned branch
- If opb_branch_id is 0/unset for a branch-scoped role, user is silently unrestricted (bug)

## Known Gaps (documented, not fixed)

- opb_view_reports is declared and assigned but NOT checked in the Reports API — all OPB roles can read reports
- Data Management API uses a bespoke closure instead of permission_manage() — functionally equivalent but won't inherit future permission_manage() changes
- /client/me uses __return_true at WP level; session check is inside the callback

## Deliverables

11 docs in docs/PERMISSIONS-01 through PERMISSIONS-11; all surfaced at /permissions in server.js

**Why:** Before any new role, capability, or module is added, these audit findings must be reviewed. Particularly: never assume opb_super_admin = full system access (OPSMAIL/SAL require manage_options).
