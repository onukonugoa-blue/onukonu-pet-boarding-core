# OPB Permission Audit — Part 7: SAL Permission Matrix

**Plugin Version:** 3.1.0  
**Audit Date:** June 2026  
**Scope:** All Situational Awareness Layer (SAL) REST endpoints, permission mechanism, and role access

---

## 1. SAL Permission Mechanism

Every SAL endpoint uses the identical `super_admin_only()` method as OPSMAIL, defined in `class-opb-sal-api.php`:

```php
public function super_admin_only( WP_REST_Request $r ): bool|WP_Error {
    return $this->permission_manage( 'manage_options', $r );
}
```

**Result: Only WordPress `administrator` users can access SAL.**

Identical reasoning to OPSMAIL: `opb_super_admin` does not hold `manage_options` and therefore cannot access any SAL endpoint.

---

## 2. SAL Endpoint Registry

All SAL routes are registered under the namespace `opb/v1` with the prefix `/sal/`.

| Endpoint | Method | Action | Permission |
|---|---|---|---|
| `/sal/config` | GET | View current SAL configuration | `super_admin_only` |
| `/sal/config` | POST | Save SAL configuration | `super_admin_only` |
| `/sal/generate` | POST | Generate a brief preview (dry run) | `super_admin_only` |
| `/sal/send` | POST | Manually send a brief now | `super_admin_only` |
| `/sal/test-telegram` | POST | Test Telegram delivery for SAL | `super_admin_only` |
| `/sal/diagnostics` | GET | View schedule and runtime diagnostics | `super_admin_only` |
| `/sal/history` | GET | View brief delivery history log | `super_admin_only` |

---

## 3. SAL Role Access Matrix

| Action | Super Admin | Branch Manager | Reception | Staff | WP Administrator |
|---|:---:|:---:|:---:|:---:|:---:|
| View SAL configuration | ❌ | ❌ | ❌ | ❌ | ✅ |
| Save SAL configuration | ❌ | ❌ | ❌ | ❌ | ✅ |
| Generate brief preview | ❌ | ❌ | ❌ | ❌ | ✅ |
| Send brief manually | ❌ | ❌ | ❌ | ❌ | ✅ |
| Test Telegram delivery | ❌ | ❌ | ❌ | ❌ | ✅ |
| View diagnostics | ❌ | ❌ | ❌ | ❌ | ✅ |
| View brief history | ❌ | ❌ | ❌ | ❌ | ✅ |

---

## 4. SAL-Specific Access Questions

| Question | Answer |
|---|---|
| **Can super admin view briefing previews?** | ❌ Only WP `administrator` |
| **Can super admin generate manual briefings?** | ❌ |
| **Can super admin configure schedules?** | ❌ SAL config endpoint requires `manage_options` |
| **Can super admin view diagnostics?** | ❌ |
| **Can super admin run reports?** | ❌ (SAL itself is the report mechanism; individual financial reports via `/reports` use only `permission_check`) |
| **Can branch manager access any SAL endpoint?** | ❌ |
| **Can reception access any SAL endpoint?** | ❌ |
| **Can staff access any SAL endpoint?** | ❌ |

---

## 5. SAL Automated Delivery

SAL uses WP Cron for scheduled brief generation and Telegram delivery. The cron mechanism bypasses all REST permissions.

| Cron Hook | Interval | Action |
|---|---|---|
| `opb_cron_sal_check` | Hourly | Evaluates configured schedule windows; generates and sends daily/weekly/monthly briefs when due |

SAL's automated delivery does not require any user session. The hourly cron check applies time and date-sent guards to prevent duplicate delivery.

---

## 6. SAL Data Sources

SAL aggregates data from multiple OPB modules to produce situation-awareness briefs. The data it reads includes:

| Data Source | Module | Notes |
|---|---|---|
| Booking counts, check-ins, check-outs | Bookings | Branch-filtered internally |
| Revenue and outstanding balances | Invoices | Branch-filtered internally |
| Occupancy statistics | Booking Stays | Branch-filtered internally |
| Task counts | Tasks | Branch-filtered internally |
| Expense summaries | Expenses | Branch-filtered internally |
| Brief delivery history | `opb_sal_brief_history` | SAL-specific table |

SAL reads system-wide aggregates when running as WP Cron (no user session, no branch restriction). This is by design — SAL briefs are addressed to system administrators and contain cross-branch summaries.

---

## 7. SAL Delivery Architecture

```
WP Cron: opb_cron_sal_check (hourly)
  │
  ├── Evaluates schedule windows
  ├── Generates brief using Gemini (formatter) with deterministic fallback
  ├── Queues into opb_opsmail_queue (source_system = 'SAL')
  └── Delivers via send_telegram_to() — bypasses OPSMAIL consumer for direct delivery

Manual trigger (WP Admin only):
  └── POST /sal/generate → preview only (no send)
  └── POST /sal/send → generate + send immediately
```

---

## 8. SAL vs OPSMAIL Permission Alignment

Both OPSMAIL and SAL use identical permission gates (`super_admin_only` → `manage_options`). This is intentional: both are infrastructure-level tools intended exclusively for the site technical administrator, not for business operations staff.

| System | Gate | Accessible By |
|---|---|---|
| OPSMAIL (queue/diagnostics) | `manage_options` | WP administrator only |
| SAL (config/briefs/diagnostics) | `manage_options` | WP administrator only |
| OPB business modules | `opb_*` capabilities | Role-appropriate OPB staff |
