# OPB RC1 — SAL Audit Summary

**Generated:** 2026-06-19  
**Phase:** 8 — SAL Audit  
**Subsystem:** Situational Awareness Layer (SAL)

---

## 1. Overview

The Situational Awareness Layer (SAL) is an internal operational subsystem of OPB. It converts live OPB database state into concise, factual Telegram briefings delivered on a configurable schedule.

**Design principles:**
- SAL is factual only — no forecasting, no business intelligence, no speculation
- Gemini is a formatter only — not a decision-maker
- A deterministic fallback guarantees brief delivery even if Gemini is unavailable
- Sending via "Send Now" does not set the daily-sent flag (allows re-generation)

---

## 2. Component Inventory

| Component | File | Status |
|---|---|---|
| Snapshot engine | `includes/class-opb-sal-snapshot.php` | ✅ Present |
| Gemini formatter + fallback | `includes/class-opb-sal-formatter.php` | ✅ Present |
| Scheduler + orchestrator | `includes/class-opb-sal-scheduler.php` | ✅ Present |
| SAL REST API | `includes/api/class-opb-sal-api.php` | ✅ Present |
| SAL Admin UI | `app/src/pages/admin/SalDashboard.tsx` | ✅ Present |
| Brief history table | `opb_sal_brief_history` | ✅ Present |

---

## 3. Brief Types

| Brief | Default Time | Contents |
|---|---|---|
| Morning Operations Brief | 07:00 | Check-ins/check-outs today, tasks due, exceptions |
| Evening Closure Brief | 19:00 | End-of-day boarding count, tasks completed/pending |
| Accounts Snapshot | 09:00 | Invoices raised/unpaid, payments received, expenses |

All delivery times are configurable via `Settings → Customization → SAL`.

---

## 4. Scheduler

**File:** `includes/class-opb-sal-scheduler.php`

**Approach:** Single hourly WP Cron hook (`opb_cron_sal_check`) checks whether each enabled brief should run based on:
1. Current server time vs configured brief time (within the same hour)
2. Whether the brief has already been sent today (`opb_sal_sent_today_{type}_{date}` WP option)

**Idempotency:** Daily-sent guard prevents duplicate delivery within the same calendar day.

**Registration:**
```
add_filter( 'cron_schedules', [ OPB_SAL_Scheduler::class, 'add_schedule' ] );
add_action( 'init',           [ OPB_SAL_Scheduler::class, 'maybe_schedule' ] );
add_action( 'opb_cron_sal_check', 'opb_cron_sal_handler' );
```

**Deactivation:** `opb_cron_sal_check` cleared via `wp_clear_scheduled_hook()` on plugin deactivation.

---

## 5. REST Endpoints

All endpoints require `manage_options`.

| Method | Path | Purpose |
|---|---|---|
| `GET` | `/opb/v1/sal/config` | Read schedule + Telegram config |
| `POST` | `/opb/v1/sal/config` | Save schedule + Telegram config |
| `POST` | `/opb/v1/sal/generate` | Preview mode — full pipeline, no delivery |
| `POST` | `/opb/v1/sal/send` | Generate + queue + deliver immediately |
| `POST` | `/opb/v1/sal/test-telegram` | Send test message to SAL chat ID |
| `GET` | `/opb/v1/sal/diagnostics` | Last run metadata per brief type |
| `GET` | `/opb/v1/sal/history` | Brief delivery history log |

---

## 6. Snapshot Engine

**File:** `includes/class-opb-sal-snapshot.php`

The snapshot engine is the **sole data source** for SAL briefs. It reads directly from `opb_*` database tables and returns structured data. No other class performs DB queries for brief generation.

Data sources:
- `opb_bookings` + `opb_stays` — current boarders, today's movements
- `opb_tasks` — tasks due, tasks completed
- `opb_invoices` — unpaid/raised today
- `opb_payments` — payments received today
- `opb_expenses` — expenses recorded today

---

## 7. Formatter and Fallback

**File:** `includes/class-opb-sal-formatter.php`

**Primary path:** Snapshot data → Gemini API → formatted brief text  
**Fallback path:** Snapshot data → deterministic PHP text rendering → brief text

The fallback is triggered when:
- Gemini API key is not configured
- Gemini API returns an error
- Gemini API call times out

**Guarantee:** A brief is always generated and delivered. No silent failures.

---

## 8. Queue Integration

SAL briefs are pushed into `opb_opsmail_queue` with:
- `source_system = 'SAL'`
- `event_type` = `sal_morning` | `sal_evening` | `sal_accounts`

Delivery is handled by `OPB_Telegram_Consumer::send_telegram_to()` — direct delivery method, bypasses the consumer queue loop for immediate SAL delivery.

---

## 9. Settings (opb_customizations category: `sal`)

| Key | Default | Description |
|---|---|---|
| `sal_enabled` | `1` | Master on/off switch |
| `sal_morning_brief_enabled` | `1` | Enable morning brief |
| `sal_morning_brief_time` | `07:00` | Delivery hour |
| `sal_evening_brief_enabled` | `1` | Enable evening brief |
| `sal_evening_brief_time` | `19:00` | Delivery hour |
| `sal_accounts_snapshot_enabled` | `1` | Enable accounts snapshot |
| `sal_accounts_snapshot_time` | `09:00` | Delivery hour |
| `sal_telegram_chat_id` | _(empty)_ | SAL-specific chat ID; falls back to global |

---

## 10. Brief History

**Table:** `opb_sal_brief_history`

Logs every brief generation with:
- `brief_type`, `trigger_type` (`scheduled` / `manual`)
- `telegram_ok`, `used_fallback`, `timing_ms`
- `queue_id` (link to opsmail_queue row)
- `message_text` (full brief for audit)
- `error` (if delivery failed)

History is viewable via `GET /opb/v1/sal/history` and in the SAL admin UI.

---

## 11. Findings

**Factual compliance:**
- ✅ SAL reads live DB state only — no ML, no trend analysis, no predictions
- ✅ Gemini receives structured data and returns formatted text — not asked to analyse or forecast
- ✅ Deterministic fallback guarantees delivery without Gemini

**Functional completeness:**
- ✅ Morning Brief operational
- ✅ Evening Brief operational
- ✅ Accounts Snapshot operational
- ✅ Preview Mode (`/sal/generate`) functional — no delivery
- ✅ Diagnostics (`/sal/diagnostics`) functional
- ✅ Scheduler (`opb_cron_sal_check`) registered and idempotent
- ✅ Queue integration with `source_system = SAL`
- ✅ Delivery history logged
- ✅ Deactivation clears cron hook

**Known limitations (carried from v3.1.0):**
- Brief history has no automatic pruning (future release)
- All SAL briefs go to one Telegram destination (per-brief routing not yet supported)
- Brief timing is WP-Cron dependent — see Cron Reliability section

---

## Conclusion

SAL is fully operational, factual, and correctly scoped. It does not forecast, analyse, or provide business intelligence — it reports current database state. Gemini formatting is a convenience with a guaranteed fallback.
