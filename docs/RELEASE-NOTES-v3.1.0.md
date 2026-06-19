# OPB / OPSMAIL Production Release — v3.1.0

**Release label:** `opsmail-production-v3.1.0`
**Plugin slug:** `onukonu-pet-boarding-core`
**Plugin version constant:** `OPB_VERSION = '3.1.0'`
**Release date:** 2026-06-19
**PHP requirement:** 8.2+
**WordPress requirement:** 6.4+
**MySQL requirement:** 5.7+ (Hostinger shared hosting compatible)

---

## 1. What Changed

### New Feature: Situational Awareness Layer (SAL)

A scheduled Telegram briefing engine that converts OPB database state into
concise factual briefs. Gemini is used as a formatter only — no forecasting,
no analysis, no speculation. A deterministic fallback guarantees brief delivery
even if Gemini is unavailable.

**Three brief types:**

| Brief | Default time | Contents |
|---|---|---|
| Morning Operations Brief | 07:00 | Check-ins/check-outs today, tasks due, exceptions |
| Evening Closure Brief | 19:00 | End-of-day boarding count, tasks completed/pending |
| Accounts Snapshot | 09:00 | Invoices raised/unpaid, payments received, expenses |

All times are configurable per brief type.

---

## 2. Files Modified

### New files (v3.1.0)

| File | Purpose |
|---|---|
| `includes/class-opb-sal-snapshot.php` | DB snapshot engine — sole data source for SAL briefs |
| `includes/class-opb-sal-formatter.php` | Gemini formatter + deterministic fallback |
| `includes/class-opb-sal-scheduler.php` | Hourly WP Cron handler + run_brief() orchestrator |
| `includes/api/class-opb-sal-api.php` | REST API (config, generate, send, test-telegram, diagnostics, history) |
| `app/src/pages/admin/SalDashboard.tsx` | React admin UI |

### Modified files (v3.1.0)

| File | Change |
|---|---|
| `onukonu-pet-boarding-core.php` | Version → 3.1.0; 4 new requires; SAL cron hook wiring |
| `includes/class-opb-activator.php` | New table: `opb_sal_brief_history`; creates on activate/upgrade |
| `includes/class-opb-deactivator.php` | Now clears SAL + OPSMAIL cron hooks on deactivation |
| `includes/class-opb-customizations.php` | 8 new SAL configuration keys in registry |
| `includes/class-opb-telegram-consumer.php` | New `send_telegram_to()` static method for direct SAL delivery |
| `admin/class-opb-admin-page.php` | SAL submenu item added |
| `app/src/App.tsx` | `/admin/sal` route added |
| `app/tsconfig.json` | Target updated ES2020 → ES2022 (required for `.at()` Array method) |

---

## 3. Database Changes

### New table: `opb_sal_brief_history`

Created by the activator on plugin activation or when `opb_db_version` differs
from `OPB_VERSION`. **Safe for existing installations** — `CREATE TABLE IF NOT
EXISTS` is used; no destructive operations.

```sql
CREATE TABLE opb_sal_brief_history (
    id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    brief_type    VARCHAR(20)  NOT NULL,
    trigger_type  VARCHAR(20)  NOT NULL DEFAULT 'scheduled',
    sent_at       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    telegram_ok   TINYINT(1)   NOT NULL DEFAULT 0,
    used_fallback TINYINT(1)   NOT NULL DEFAULT 0,
    timing_ms     INT UNSIGNED NOT NULL DEFAULT 0,
    queue_id      INT UNSIGNED NULL,
    message_text  MEDIUMTEXT   NULL,
    error         VARCHAR(500) NULL,
    PRIMARY KEY (id),
    KEY idx_brief_type (brief_type),
    KEY idx_sent_at    (sent_at)
) ENGINE=InnoDB
```

### No other schema changes

All other tables are unchanged from v3.0.3. The `opb_opsmail_queue` table
accepts SAL rows via the existing `source_system` VARCHAR column (`'SAL'`).

---

## 4. New WP Cron Schedules

| Hook | Schedule | Handler |
|---|---|---|
| `opb_cron_sal_check` | `opb_sal_hourly` (60 min) | `OPB_SAL_Scheduler::check_and_run()` |

### Existing cron schedules (unchanged)

| Hook | Schedule | Handler |
|---|---|---|
| `opb_cron_process_mailbox` | `opb_mailbox_interval` (configurable, default 5 min) | `OPB_Mailbox_Processor::process()` |
| `opb_cron_process_telegram` | `opb_telegram_interval` (1 min) | `OPB_Telegram_Consumer::process_queue()` |

**No scheduling conflicts.** All three hooks use distinct schedule keys and
distinct action hook names.

**Deactivation:** All three hooks are now correctly cleared on plugin
deactivation (`OPB_Deactivator::deactivate()`).

---

## 5. New REST Endpoints

All endpoints require `manage_options` capability (super-admin only).

| Method | Path | Purpose |
|---|---|---|
| `GET` | `/opb/v1/sal/config` | Read schedule + Telegram config |
| `POST` | `/opb/v1/sal/config` | Save schedule + Telegram config |
| `POST` | `/opb/v1/sal/generate` | Run full pipeline (preview — no delivery) |
| `POST` | `/opb/v1/sal/send` | Generate + queue + deliver immediately |
| `POST` | `/opb/v1/sal/test-telegram` | Send test message to SAL chat ID |
| `GET` | `/opb/v1/sal/diagnostics` | Last run metadata per brief type |
| `GET` | `/opb/v1/sal/history` | Brief delivery history log |

---

## 6. Configuration Keys (wp_opb_customizations)

Eight new keys added to the SAL category:

| Key | Default | Description |
|---|---|---|
| `sal_enabled` | `1` | Master on/off switch |
| `sal_morning_brief_enabled` | `1` | Enable morning brief |
| `sal_morning_brief_time` | `07:00` | Hour to send morning brief |
| `sal_evening_brief_enabled` | `1` | Enable evening brief |
| `sal_evening_brief_time` | `19:00` | Hour to send evening brief |
| `sal_accounts_snapshot_enabled` | `1` | Enable accounts snapshot |
| `sal_accounts_snapshot_time` | `09:00` | Hour to send accounts snapshot |
| `sal_telegram_chat_id` | _(empty)_ | SAL-specific chat ID; falls back to global |

---

## 7. Known Limitations

1. **WP Cron dependency** — Briefs fire when WP Cron runs, not at clock-exact
   times. On low-traffic sites, consider a server cron that hits `/?doing_wp_cron`
   on a schedule aligned to the configured brief hours.

2. **Gemini rate limits** — If all three briefs are configured for the same hour,
   three Gemini API calls fire within the same cron tick. Each has its own timeout
   and fallback; no brief will be silently dropped.

3. **SAL brief history retention** — History is never automatically pruned.
   A future release will add a configurable retention window.

4. **Single Telegram destination** — All SAL briefs go to one chat ID
   (`sal_telegram_chat_id` or the global fallback). Per-brief-type routing is
   not supported in this release.

---

## 8. Deployment Instructions

### Fresh installation

1. Upload `opsmail-production-v3.1.0.zip` via **Plugins → Add New → Upload Plugin**.
2. Click **Activate Plugin**.
3. Navigate to **Operations → OPSMAIL** and complete initial configuration:
   - Telegram Bot Token
   - Telegram Chat ID
   - Gemini API Key
4. Navigate to **Operations → SAL** and configure:
   - Enable/disable individual brief types
   - Set delivery hours
   - Optionally set a dedicated SAL chat ID
5. Click **Test Telegram** to verify connectivity.
6. Click **Send Now** for any brief type to confirm end-to-end delivery.

### Upgrade from v3.0.x

1. Deactivate the existing plugin.
2. Upload and activate `opsmail-production-v3.1.0.zip`.
3. The activator runs automatically and creates `opb_sal_brief_history`.
4. No manual SQL required.
5. All existing OPSMAIL configuration (Telegram, Gemini, mailbox) is preserved.
6. Navigate to **Operations → SAL** and configure the new SAL settings.

### Post-activation verification

```
1. Plugins → SAL → Test Telegram       → expect "Test message delivered"
2. Plugins → SAL → Preview Mode        → select Morning Brief → Generate
3. Plugins → SAL → Operations → Send Now (morning) → check Telegram
4. Plugins → OPSMAIL → Test Telegram   → expect success
5. Plugins → OPSMAIL → Test Gemini     → expect classification result
6. Plugins → OPSMAIL → Queue           → confirm rows appear after send
```
