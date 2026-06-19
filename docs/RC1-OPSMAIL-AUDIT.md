# OPB RC1 — OPSMAIL Audit Summary

**Generated:** 2026-06-19  
**Phase:** 7 — OPSMAIL Audit  
**Subsystem:** OPSMAIL (Operational Messaging and Event Queue)

---

## 1. Overview

OPSMAIL is an internal operational subsystem of OPB. It provides:
- An additive-only event queue (`opb_opsmail_queue`)
- Email emission for significant business events
- Telegram delivery of queued events
- Mailbox processing (inbound email classification via Gemini)
- A queue viewer admin page

OPSMAIL is a subsystem of OPB. It is not the product name.

---

## 2. Component Inventory

| Component | File | Status |
|---|---|---|
| Queue engine | `includes/class-opb-opsmail.php` | ✅ Present |
| OPSMAIL REST API | `includes/api/class-opb-opsmail-api.php` | ✅ Present |
| Telegram consumer | `includes/class-opb-telegram-consumer.php` | ✅ Present |
| Mailbox processor | `includes/class-opb-mailbox-processor.php` | ✅ Present |
| Queue admin page (PHP-rendered) | Registered via `admin_menu` hook | ✅ Present |
| Queue React UI | `app/src/pages/admin/OpsmailQueue.tsx` | ✅ Present |
| Gemini Lab UI | `app/src/pages/admin/GeminiLab.tsx` | ✅ Present |
| Cron health monitor | `includes/class-opb-cron-health.php` | ✅ Present |

---

## 3. Queue System

**Table:** `opb_opsmail_queue`

| Field | Notes |
|---|---|
| `event_type` | Event category (booking, payment, expense, etc.) |
| `source_system` | `OPSMAIL` or `SAL` |
| `payload` | JSON event data |
| `status` | `PENDING` → `SENT` / `FAILED` / `ACKNOWLEDGED` |

**Push points wired (OPSMAIL):**
1. Large booking created
2. Invoice generated
3. Payment recorded
4. Large expense recorded (threshold: ₹5,000 configurable)
5. Check-in / check-out events

**Push points wired (SAL):**
- All SAL briefs queue into `opb_opsmail_queue` with `source_system = SAL`

**Safety guarantee:** All `OPB_Opsmail::push_*()` calls wrapped in `try/catch(\Throwable)` — never throws, never blocks any business logic.

---

## 4. REST Endpoints

All endpoints require `manage_options`.

| Method | Path | Purpose |
|---|---|---|
| `GET` | `/opb/v1/opsmail/queue` | Paginated queue with filters |
| `GET` | `/opb/v1/opsmail/stats` | Status counts, event type counts, health flags |
| `POST` | `/opb/v1/opsmail/queue/{id}/acknowledge` | Mark event acknowledged |
| `POST` | `/opb/v1/opsmail/process-telegram` | Manually trigger telegram consumer |
| `POST` | `/opb/v1/opsmail/test-telegram` | Send test message to configured chat |
| `POST` | `/opb/v1/opsmail/gemini-run` | Run text through Gemini (Lab) |
| `GET` | `/opb/v1/opsmail/cron-health` | Cron health status per component |
| `POST` | `/opb/v1/opsmail/process-text` | Process inbound email text via Gemini |
| `GET` | `/opb/v1/opsmail/diagnostics` | Telegram + system diagnostics |

---

## 5. Telegram Consumer

**File:** `includes/class-opb-telegram-consumer.php`

- Reads `PENDING` rows from `opb_opsmail_queue`
- Delivers to configured Telegram bot/chat
- Updates row status to `SENT` or `FAILED`
- **Direct delivery:** `send_telegram_to($chat_id, $message)` — used by SAL, bypasses queue consumer
- **Cron:** `opb_cron_process_telegram` (1-minute interval)
- **Manual trigger:** `POST /opb/v1/opsmail/process-telegram`

---

## 6. Mailbox Processor

**File:** `includes/class-opb-mailbox-processor.php`

- Reads inbound emails via IMAP (`@imap_open` with warning suppression)
- Classifies email content via Gemini (`responseMimeType: application/json`)
- Routes classified content to appropriate OPB handlers
- Skips `X-Ops-Version` header emails (avoids processing OPB's own outbound events)
- **Cron:** `opb_cron_process_mailbox` (configurable interval, default 5 min)

---

## 7. Gemini Integration

**Used by:** Mailbox processor (classification), SAL formatter (brief formatting)

- API key: stored in `opb_customizations` settings
- Response format: `application/json` for classification
- **Gemini Lab:** Interactive UI for testing Gemini text processing
- **Fallback:** SAL has deterministic fallback; mailbox processor handles API failure gracefully

---

## 8. Cron Health Monitoring

**File:** `includes/class-opb-cron-health.php`

| Component | Healthy threshold | Delayed threshold |
|---|---|---|
| Queue consumer | 3 minutes | 15 minutes |
| Mailbox processor | 15 minutes | 60 minutes |
| SAL check | 90 minutes | 3 hours |

**External cron detection:**
- Maintains ring buffer of last 12 execution timestamps
- Median interval < 8 minutes → external cron likely present
- `DISABLE_WP_CRON = true` → external cron confirmed
- UI displays warning when relying solely on WP-Cron visitor triggering

---

## 9. Settings (opb_customizations category: `opsmail`)

| Key | Description |
|---|---|
| `opsmail_enabled` | Master on/off switch (default: `0`) |
| `opsmail_inbox_email` | Email address for event emission |
| `opsmail_trusted_origins` | Trusted sender mailboxes (one per line) |
| `opsmail_expense_threshold` | Large-expense trigger amount (default: `5000`) |
| `telegram_bot_token` | Telegram bot token |
| `telegram_chat_id` | Global Telegram chat ID |
| `gemini_api_key` | Gemini API key |

---

## 10. Findings

- ✅ Queue system operational — additive-only, no business logic impact
- ✅ Telegram consumer processes queue on 1-minute cron
- ✅ Telegram test endpoint functional
- ✅ Gemini integration with JSON response mode
- ✅ Gemini Lab UI available for diagnostics
- ✅ Cron health monitoring with external cron detection
- ✅ Mailbox processor safely skips OPB's own outbound emails
- ✅ All API endpoints gated to `manage_options`
- ✅ External cron support added in HEAD commit

---

## Conclusion

OPSMAIL is fully operational as an internal subsystem. All components are present and wired. The cron health monitor provides visibility into scheduler reliability. External cron support is in place.
