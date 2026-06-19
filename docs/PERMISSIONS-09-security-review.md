# OPB Permission Audit — Part 9: Security Review

**Plugin Version:** 3.1.0  
**Audit Date:** June 2026  
**Scope:** Permission vulnerabilities, missing checks, nonce validation, branch bypass opportunities, and public surface analysis

> **Important:** This review documents the existing implementation as-is. No code changes have been made. All findings are observations, not fixes.

---

## Security Finding Index

| ID | Title | Severity | Status |
|---|---|---|---|
| S-1 | Reports endpoint does not check `opb_view_reports` | Medium | Open |
| S-2 | Branch-scoped users without `opb_branch_id` get unrestricted access | Medium | Open |
| S-3 | `/client/me` uses `__return_true` — no WP-level auth gate | Low | Accepted pattern |
| S-4 | No nonce validation on OPB REST endpoints | Info | By design (WP REST uses cookie+nonce or JWT) |
| S-5 | `opb_super_admin` cannot reach OPSMAIL/SAL — intent/implementation split | Medium | Documented gap |
| S-6 | Public REST routes expose booking service catalogue with no auth | Info | By design |
| S-7 | `opb_staff` can read all financial data (invoices, expenses, payments) | Low | Permission_check is the gate; by design |
| S-8 | Kennel board (`/bookings/kennel-board`) readable by all OPB roles | Info | By design |
| S-9 | Data Management closures bypass the standard permission helper | Low | Functionally equivalent; maintenance risk |
| S-10 | No rate limiting on public OTP request endpoint | Medium | Out of scope for WP plugin layer |

---

## S-1 — Reports Endpoint Does Not Enforce `opb_view_reports`

**Severity:** Medium  
**File:** `plugin/includes/api/class-opb-reports-api.php`

The `GET /opb/v1/reports` endpoint is protected by `permission_check`, which allows any logged-in OPB user to access it. The `opb_view_reports` capability exists and is assigned only to `opb_super_admin` and `opb_branch_manager`, but is not checked.

**Impact:** `opb_reception` and `opb_staff` users can retrieve financial report data including revenue totals, expense summaries, and occupancy metrics — scoped to their branch by `branch_filter()`, but accessible nonetheless.

**Actual data exposed to reception/staff:**
- Monthly revenue for their branch
- Total outstanding dues
- Expense breakdowns by category
- Occupancy statistics

**Mitigating factor:** Branch filtering is still applied, so a reception user at Branch A cannot see Branch B's financial data.

---

## S-2 — Branch-Scoped User With No Branch Assignment Is Unrestricted

**Severity:** Medium  
**File:** `plugin/includes/class-opb-roles.php` (`get_user_branch_id()`)

If a user is created with `opb_branch_manager`, `opb_reception`, or `opb_staff` role, but no `opb_branch_id` user meta is set (value = 0 or empty), `get_user_branch_id()` returns 0. This is the same return value as an unrestricted user.

**Impact:** The user can read data across all branches. For `opb_branch_manager` this also means write access (e.g., creating bookings, recording payments) across all branches.

**Trigger condition:** A user account created via WP Admin (Add User) — assigning an OPB role — without subsequently using the OPB Staff management screen to set a branch. This is a common setup mistake.

**Mitigating factor:** Requires a logged-in OPB staff user, not a public user. An administrator who creates users this way must be aware of OPB's branch assignment requirement.

---

## S-3 — `/client/me` Uses `__return_true` at WP Permission Level

**Severity:** Low (accepted pattern with risk note)  
**File:** `plugin/includes/api/class-opb-client-relationship-api.php`

The `GET /opb/v1/client/me` endpoint returns the authenticated client's profile data. Its `permission_callback` is `__return_true`, meaning WordPress does not apply any authentication check before the handler runs. The session is validated inside the callback body using the `opb_client_session` cookie.

**Why this matters:** If a future developer modifies the callback and inadvertently removes or short-circuits the session check, client profile data would be returned without authentication. There is no WP-level failsafe.

**Current state:** The internal session check is the first operation in the callback — any invalid or missing session token results in an immediate error response. The risk is real but currently mitigated by discipline.

**Note on other `__return_true` endpoints:**
`/client/auth/request-otp`, `/client/auth/verify-otp`, `/client/auth/logout` are legitimately public — they are the authentication flow itself. `__return_true` is correct for these. The concern applies only to `/client/me`.

---

## S-4 — No WP REST Nonce Validation on OPB Endpoints

**Severity:** Info (by design)  
**File:** All API files

None of the OPB REST endpoints manually call `wp_verify_nonce()`. WordPress REST API handles this implicitly through:
- Cookie-based auth: WP automatically validates `wp_rest` nonce from the cookie when using `X-WP-Nonce` header
- The React SPA sends the nonce via the `X-WP-Nonce` header, localised into the page via `wp_localize_script`

This is the standard WP REST API pattern. Individual nonce calls in handlers are not needed.

**Not a finding** — standard implementation.

---

## S-5 — `opb_super_admin` Cannot Access OPSMAIL or SAL Infrastructure

**Severity:** Medium (intent/implementation split)

As documented in Part 6 and Part 7, the highest OPB business role (`opb_super_admin`) cannot access the OPSMAIL queue, diagnostics, SAL configuration, or SAL briefs. Only the WordPress `administrator` role (holding `manage_options`) can.

**Security perspective (positive):** This is actually a security strength — infrastructure tooling (IMAP credentials, Telegram bot tokens, Gemini API keys, queue manipulation) is locked to the WP system administrator rather than being accessible to any OPB super admin account that might be compromised.

**Operational perspective (risk):** An `opb_super_admin` who needs to debug OPSMAIL/SAL delivery issues cannot do so without being given the WP `administrator` role — which grants them full WP site control, far beyond OPB scope.

---

## S-6 — Public REST Routes Expose Boarding Service Catalogue

**Severity:** Info (by design)  
**File:** `plugin/includes/api/class-opb-public-api.php`

The following routes are accessible to any unauthenticated user:
- `GET /opb/v1/public/boarding-services` — returns the boarding services catalogue for a branch
- `POST /opb/v1/public/inquiry` — submits a new inquiry (lead capture)
- `GET/POST /opb/v1/public/onboarding-link/{token}` — accesses onboarding form via secure token
- `POST /opb/v1/public/onboarding-submit/{token}` — submits client onboarding data

These are intentionally public — they are the customer-facing APIs for the website and onboarding flow. The boarding catalogue contains pricing information. The onboarding endpoints use a single-use or time-limited token for access control.

**Not a vulnerability** — by design. Token security of onboarding links is the relevant concern (token entropy, expiry).

---

## S-7 — `opb_staff` Can Read Financial Module Data

**Severity:** Low (by design)  
**Files:** All modules using `permission_check` for GET endpoints

The `opb_staff` role holds only `opb_manage_tasks`. However, all GET (read) endpoints across the system use `permission_check`, which passes for any OPB role including `opb_staff`. This means `opb_staff` users can read:
- Invoices list and individual invoices
- Payments list
- Expenses list and individual expenses
- Reports data
- Client and booking details

**Design rationale:** The separation between `permission_check` (read) and `permission_manage(cap)` (write) is an intentional architecture. Staff can see context (e.g., which bookings exist) to do their job (assign to kennels, update tasks) without being able to modify financial records.

**Risk:** Staff have visibility into financial data. If financial data privacy between staff roles is a requirement, the read gates on financial modules need dedicated capability checks.

---

## S-8 — Kennel Board Readable by All OPB Roles

**Severity:** Info (by design)

`GET /opb/v1/bookings/kennel-board` uses `permission_check`. All OPB roles can view the kennel board. This is appropriate — ground staff need to see kennel assignments to do their physical work.

---

## S-9 — Data Management Uses Bespoke Permission Closure

**Severity:** Low (maintenance risk)

The Data Management API constructs its own permission check via a constructor-level closure rather than using `permission_manage()`. While functionally equivalent today, any future change to `permission_manage()` (e.g., adding a login guard, rate limit, or audit log) will not automatically apply to Data Management endpoints.

---

## S-10 — No Rate Limiting on OTP Request Endpoint

**Severity:** Medium (out of scope for plugin layer)  
**File:** `plugin/includes/api/class-opb-client-relationship-api.php`

`POST /opb/v1/client/auth/request-otp` uses `__return_true` and accepts any phone/email input. There is no rate limiting on OTP requests at the plugin level.

**Impact:** An adversary could enumerate client phone numbers by sending repeated OTP requests and observing response differences, or flood the OTP email system.

**Mitigating factors:**
- OTP requests only confirm whether a phone number matches a client — not a high-value disclosure
- Rate limiting is typically handled at the server/CDN level (Hostinger, Nginx) rather than in application code
- This is out of scope for a WordPress plugin but should be noted for infrastructure configuration

---

## Overall Security Posture Summary

| Area | Assessment |
|---|---|
| REST authentication model | Sound — all write operations require a logged-in user with appropriate capability |
| Branch data isolation | Sound for configured users; gap exists for users with missing branch meta |
| Financial data access | Read access is wider than role design suggests (reports, expenses visible to all OPB roles) |
| Infrastructure tools (OPSMAIL, SAL) | Well-locked — `manage_options` gate is strong |
| Public endpoints | Appropriate use of `__return_true` for customer-facing flows |
| Client portal auth | Valid custom pattern; fragile if discipline lapses |
| Capability enforcement | One gap (`opb_view_reports` not enforced in API) |
| WP security integration | Standard WP REST nonce/cookie model; no deviations |
