# OPB Permission Audit — Part 8: Conflict Detection Report

**Plugin Version:** 3.1.0  
**Audit Date:** June 2026  
**Scope:** Duplicate roles, unused capabilities, naming inconsistencies, dead permission checks, enforcement gaps

---

## Summary

| Conflict Category | Count | Severity |
|---|:---:|---|
| Capability declared but not enforced in API | 1 | Medium |
| Capability redundancy (same roles, different names) | 1 | Low |
| Role cannot access its "home" module (OPSMAIL for super admin) | 1 | Medium |
| Missing branch restriction on role with no branch meta | 1 | Medium |
| REST endpoint with `__return_true` performing data reads | 1 | Low |
| Inconsistent permission check pattern (direct vs helper) | 1 | Low |

---

## Conflict 1 — `opb_view_reports` Declared but Not Enforced

**Category:** Capability granted but never checked in the API  
**Severity:** Medium

| Field | Detail |
|---|---|
| **Capability** | `opb_view_reports` |
| **Granted to** | `opb_super_admin`, `opb_branch_manager` |
| **Intended exclusion** | `opb_reception`, `opb_staff` |
| **Actual enforcement** | The Reports API (`class-opb-reports-api.php`) calls only `permission_check`, not `permission_manage('opb_view_reports')` |
| **Effect** | Any logged-in OPB user — including `opb_reception` and `opb_staff` — can call `GET /opb/v1/reports` and receive financial data |

**Evidence:**
```php
// class-opb-reports-api.php
[ 'methods' => 'GET', 'callback' => [ $this, 'get_report' ],
  'permission_callback' => [ $this, 'permission_check' ] ],  // ← should be permission_manage('opb_view_reports')
```

**Design intent vs reality:**
- Capability design says: reception and staff cannot view reports
- Implementation says: everyone can

---

## Conflict 2 — `opb_record_payments` and `opb_manage_invoices` Are Redundant

**Category:** Capability redundancy — same role assignments, different names  
**Severity:** Low

| Aspect | `opb_record_payments` | `opb_manage_invoices` |
|---|---|---|
| Roles holding it | super admin, branch manager, reception | super admin, branch manager, reception |
| Roles NOT holding it | staff | staff |
| Purpose | Record a new payment | Adjust invoice, delete payment, generate/send PDF |

The two capabilities are assigned to exactly the same three roles. The separation was designed to allow a role that can take payments but cannot adjust invoices — but no such role exists in the current system. Both capabilities always travel together.

**Effect:** No practical difference in access between a user with one vs both capabilities.

---

## Conflict 3 — `opb_super_admin` Cannot Access OPSMAIL or SAL

**Category:** Role intent vs permission implementation mismatch  
**Severity:** Medium

| Field | Detail |
|---|---|
| **Affected role** | `opb_super_admin` |
| **Module** | OPSMAIL, SAL |
| **Gate** | `manage_options` |
| **Issue** | `opb_super_admin` is described as "full access to all branches" and holds all 12 OPB capabilities. However, OPSMAIL and SAL use `manage_options` as their gate — a WordPress administrator capability that `opb_super_admin` does not hold. |
| **Effect** | A user with `opb_super_admin` role cannot access OPSMAIL queue, diagnostics, or SAL configuration despite being the highest OPB business role. Only a user with the `administrator` WP role can. |

This is architecturally intentional (OPSMAIL/SAL are infrastructure tools for site administrators), but contradicts the intuition that "OPB super admin = full OPB access." A developer or operator unfamiliar with this split will be confused.

---

## Conflict 4 — Branch-Scoped User With No `opb_branch_id` Meta Acts as Unrestricted

**Category:** Missing branch restriction on role without branch meta  
**Severity:** Medium

| Field | Detail |
|---|---|
| **Affected roles** | `opb_branch_manager`, `opb_reception`, `opb_staff` |
| **Condition** | User created with a branch-scoped OPB role but no `opb_branch_id` meta set (value = 0 or empty string) |
| **Behaviour** | `get_user_branch_id()` returns 0, which means unrestricted. `branch_filter()` then passes `branch_id = 0` to queries, returning data across all branches. |
| **Effect** | A newly created branch-scoped user with a missing branch assignment silently has cross-branch read access. |

There is no runtime guard that checks "if role is branch-scoped and branch_id is 0, reject the request." The system trusts that branch_id will always be set for branch-scoped roles.

---

## Conflict 5 — `/client/me` Uses `__return_true` at WP Permission Level

**Category:** REST endpoint performing authenticated data reads with `__return_true`  
**Severity:** Low (architectural note, not an active vulnerability)

| Field | Detail |
|---|---|
| **Endpoint** | `GET /opb/v1/client/me` |
| **Permission callback** | `__return_true` |
| **Actual auth** | Session cookie (`opb_client_session`) validated inside the callback method |

The WP REST framework considers this endpoint public (anyone can call it). Authentication is performed inside the callback, not via `permission_callback`. This is a valid pattern (custom auth mechanisms often work this way), but it means:
- WordPress does not apply REST nonce or cookie authentication before the handler runs
- If the internal session check ever fails silently, the handler would process an unauthenticated request
- Standard WP REST API tooling (logs, security plugins) that inspect `permission_callback` will report this as a public endpoint

The mitigation is that the client session check is the first thing executed inside the callback — but it is the developer's responsibility to maintain this discipline on all future changes to that method.

---

## Conflict 6 — Inconsistent Permission Check Pattern in Data Management API

**Category:** Inconsistent use of permission helper vs direct capability check  
**Severity:** Low

| Field | Detail |
|---|---|
| **File** | `class-opb-data-management-api.php` |
| **Pattern used** | Direct `current_user_can('opb_manage_settings') \|\| current_user_can('manage_options')` in a constructor-level closure `$sa` |
| **Pattern used everywhere else** | `permission_manage('opb_manage_settings', $r)` in `permission_callback` |

The Data Management API correctly replicates the logic of `permission_manage()`, but does so manually rather than calling the inherited helper. This means:
- If the logic in `permission_manage()` ever changes (e.g., to add logging, rate limiting, or additional checks), Data Management will not automatically inherit the change
- A developer reading the codebase sees two different patterns and may not recognise they are equivalent

---

## Conflict 7 — No Duplicate or Legacy Roles

**Status:** No issues found

All four OPB roles (`opb_super_admin`, `opb_branch_manager`, `opb_reception`, `opb_staff`) are:
- Actively assigned to real users
- Used in `has_opb_role()` checks
- Returned in `get_staff()` queries
- Referenced in the React sidebar for user type rendering

There are no legacy OPB roles remaining in the database from prior versions (the `maybe_add_roles()` function removes all OPB roles before re-registering them on each version bump).

---

## Conflict 8 — No Orphaned Capabilities

**Status:** No issues found (with one exception noted above)

All 12 capabilities in `OPB_Roles::CAPS` are:
- Assigned to at least one role
- Checked in at least one API file

The one exception is `opb_view_reports`, which is assigned but not checked — documented as Conflict 1.

---

## Conflict Summary Table

| ID | Type | Affected Component | Severity | Recommended Action |
|---|---|---|---|---|
| C-1 | Capability not enforced | `opb_view_reports` / Reports API | Medium | Add `permission_manage('opb_view_reports')` to the Reports API permission callback |
| C-2 | Redundant capabilities | `opb_record_payments` + `opb_manage_invoices` | Low | No action required unless a new role separation is introduced |
| C-3 | Role/module intent mismatch | `opb_super_admin` vs OPSMAIL/SAL | Medium | Document clearly; optionally add `opb_manage_settings`-gated OPSMAIL read-only view |
| C-4 | Missing branch guard | Branch-scoped roles with no `opb_branch_id` | Medium | Add validation in `update_staff` and consider server-side guard in `get_user_branch_id()` |
| C-5 | `__return_true` on data endpoint | `GET /client/me` | Low | Document; ensure callback always validates session first |
| C-6 | Inconsistent permission pattern | Data Management API | Low | Refactor to use `permission_manage()` or document the equivalence |
