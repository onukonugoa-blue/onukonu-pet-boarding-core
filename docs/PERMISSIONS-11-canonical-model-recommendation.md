# OPB Permission Audit — Part 11: Canonical Model Recommendation

**Plugin Version:** 3.1.0  
**Audit Date:** June 2026  
**Scope:** Recommended simplified permission model — documentation only, no implementation

> **This document is a recommendation. No code changes are proposed here. The existing implementation is documented as-is in Parts 1–10.**

---

## 1. Observations from the Audit

Before proposing a simplified model, the audit identified several areas where the current model could be clearer:

| Observation | Impact |
|---|---|
| `opb_super_admin` cannot access OPSMAIL or SAL — the top OPB business role is not the top system role | Operationally confusing |
| Two separate "super" tiers exist (WP `administrator` and `opb_super_admin`) with no clear documented boundary | Undocumented split |
| `opb_view_reports` capability is defined but not enforced | Silent inconsistency |
| `opb_record_payments` and `opb_manage_invoices` are assigned to exactly the same roles | Unnecessary complexity |
| Branch-scoped users with missing `opb_branch_id` meta are silently unrestricted | Invisible risk |
| The "user type" model has two entry points (WP Users screen and OPB Staff screen) that look separate but are the same | Onboarding confusion |
| Read access is universally open to all OPB roles; write access is capability-gated | Inconsistent with role intent for some modules |

---

## 2. Proposed Canonical Model

The recommended model consolidates the current four OPB roles and the WP administrator into five named tiers with clear, documented boundaries:

```
┌────────────────────────────────────────────────────────┐
│  TIER 0 — System Administrator                         │
│                                                        │
│  Maps to: WordPress administrator (manage_options)     │
│                                                        │
│  Responsibilities:                                     │
│  · WP site management                                  │
│  · OPSMAIL diagnostics and configuration               │
│  · SAL configuration and manual briefs                 │
│  · Data migrations and imports                         │
│  · Full OPB access as a bypass                         │
└────────────────────────────────────────────────────────┘
                          │
                          ▼
┌────────────────────────────────────────────────────────┐
│  TIER 1 — OPB Super Admin                              │
│                                                        │
│  Maps to: opb_super_admin (current)                    │
│                                                        │
│  Responsibilities:                                     │
│  · Cross-branch business operations                    │
│  · Settings, catalogues, branches, user management     │
│  · Financial reporting, data management, archive       │
│  · Full write access to all business modules           │
│                                                        │
│  Recommended change: Explicitly document that this     │
│  tier cannot access infrastructure (OPSMAIL/SAL)       │
│  unless promoted to Tier 0.                            │
└────────────────────────────────────────────────────────┘
                          │
                          ▼
┌────────────────────────────────────────────────────────┐
│  TIER 2 — Branch Manager                               │
│                                                        │
│  Maps to: opb_branch_manager (current)                 │
│                                                        │
│  Responsibilities:                                     │
│  · Branch-scoped operations manager                    │
│  · Full write access to clients, pets, bookings,       │
│    invoices, payments, expenses, tasks                 │
│  · Financial reports (own branch)                      │
│  · No system settings, no cross-branch access          │
└────────────────────────────────────────────────────────┘
                          │
                          ▼
┌────────────────────────────────────────────────────────┐
│  TIER 3 — Operations Staff (Reception)                 │
│                                                        │
│  Maps to: opb_reception (current)                      │
│                                                        │
│  Responsibilities:                                     │
│  · Branch front-desk and booking operations            │
│  · Write: clients, pets, bookings, invoices, payments  │
│  · No expenses, no reports, no settings                │
└────────────────────────────────────────────────────────┘
                          │
                          ▼
┌────────────────────────────────────────────────────────┐
│  TIER 4 — Read-Only / Task Staff                       │
│                                                        │
│  Maps to: opb_staff (current)                          │
│                                                        │
│  Responsibilities:                                     │
│  · Ground-level care staff                             │
│  · Read access to bookings, clients (context only)     │
│  · Write: tasks only                                   │
│  · No financial access at all                          │
└────────────────────────────────────────────────────────┘

Separate identity space (unchanged):
┌────────────────────────────────────────────────────────┐
│  CLIENT — Portal User                                  │
│                                                        │
│  Not a WordPress user. Email OTP auth.                 │
│  Read-only access to own records via /my-pets/         │
└────────────────────────────────────────────────────────┘
```

---

## 3. Mapping Current Implementation to Canonical Model

| Canonical Tier | Current Role(s) | Changes Required |
|---|---|---|
| Tier 0 — System Administrator | WP `administrator` | None — document boundary explicitly |
| Tier 1 — OPB Super Admin | `opb_super_admin` | Document OPSMAIL/SAL exclusion; fix `opb_view_reports` enforcement |
| Tier 2 — Branch Manager | `opb_branch_manager` | Fix `opb_view_reports` enforcement; add `opb_branch_id` validation |
| Tier 3 — Operations Staff | `opb_reception` | No capability changes; consider restricting read on financial modules |
| Tier 4 — Read-Only / Task Staff | `opb_staff` | No capability changes; consider restricting read on financial modules |
| Client | OPB client record | Strengthen `permission_callback` on `/client/me` |

---

## 4. Recommended Capability Simplifications

### 4.1 Merge `opb_record_payments` into `opb_manage_invoices`

These two capabilities are assigned to identical role sets and checked on adjacent operations (record payment vs adjust invoice). Unless a future role needs one without the other, they can be documented as a single logical permission.

**Recommendation:** Retain both names for backward compatibility, but document them as a combined "financial write" permission. Any new role that gets one should get both.

### 4.2 Enforce `opb_view_reports` in the Reports API

The `opb_view_reports` capability is declared and assigned but never checked. Enforcing it would align implementation with intent and prevent reception/staff from accessing financial summaries.

**Recommended change (not implemented):**
```php
// class-opb-reports-api.php — current:
'permission_callback' => [ $this, 'permission_check' ],

// Should be:
'permission_callback' => fn($r) => $this->permission_manage('opb_view_reports', $r),
```

### 4.3 Add `opb_branch_id` Validation Guard

For branch-scoped roles, add a server-side guard: if the user has a branch-scoped role and their `opb_branch_id` is 0 or unset, deny access with a clear error message instructing the administrator to assign a branch.

**Recommended change (not implemented):** Add a check in `get_user_branch_id()` or `permission_check()` that returns a `WP_Error` if a branch-scoped role has no assigned branch, rather than silently returning 0.

---

## 5. Recommended User Onboarding Clarification

The current user setup flow requires:
1. Create user in WP Admin → Assign OPB role in WP Users screen
2. Navigate to OPB → Settings → Staff → Set branch

This two-step process, across two different screens, is error-prone. Step 1 without Step 2 leaves a user with an OPB role but no branch, which silently grants unrestricted access.

**Recommendation (documentation only):** Document this two-step requirement prominently in operator documentation. Optionally (not implemented) add a validation warning in the OPB admin UI when OPB users exist with no branch assignment.

---

## 6. Recommended OPSMAIL/SAL Access Documentation

The current boundary between OPB Super Admin and WP Administrator is undocumented in the system. A developer or new site administrator will be confused when `opb_super_admin` cannot access the OPSMAIL tab.

**Recommendation:** Add inline documentation (could be a dismissible admin notice) that explains:

> "OPSMAIL and SAL are infrastructure tools accessible only to WordPress Administrators. OPB Super Admin users manage business operations but do not have access to communication infrastructure settings."

---

## 7. Summary — What to Change, What to Leave Alone

### Leave unchanged:
- Role names and keys (backward compatible, in use across the system)
- All 12 capability names
- The `manage_options` gate for OPSMAIL and SAL (this is a security strength)
- The client portal authentication model
- The `branch_filter()` + `get_user_branch_id()` mechanism (well-designed)
- The `permission_check` / `permission_manage` helper pattern

### Document more clearly (no code change):
- The OPSMAIL/SAL exclusion from `opb_super_admin`
- The two-step user creation requirement
- The `opb_record_payments` / `opb_manage_invoices` redundancy

### Fix in future development (not now):
- Enforce `opb_view_reports` in the Reports API permission callback
- Add `opb_branch_id` presence validation for branch-scoped roles
- Refactor Data Management to use `permission_manage()` consistently
- Consider strengthening `/client/me` `permission_callback` to validate session at the WP layer
