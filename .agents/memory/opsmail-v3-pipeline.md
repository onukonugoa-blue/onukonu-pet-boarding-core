---
name: OPSMAIL v3.0.0 pipeline
description: Telegram consumer + mailbox processor + Gemini classifier architecture, routing rules, and integration points
---

## Architecture rule (non-negotiable)
OPB events flow: `push_event()` → `opb_opsmail_queue` (telegram_status=PENDING) → `OPB_Telegram_Consumer::process_queue()` → Telegram Bot API.
The mailbox processor is NEVER in the path for OPB-generated events.

**Why:** Routing structured events through the mailbox would add IMAP latency, risk duplicate queue entries, and couple delivery to email round-trip timing. The queue is the single source of truth.

**How to apply:** Any new OPB event hook (`push_event()` + `emit()`) does not touch the mailbox. The Telegram consumer runs on `opb_cron_process_telegram` (hourly WP Cron) and can be triggered manually via `POST /opb/v1/opsmail/process-telegram`.

## Routing rule (mailbox processor)
Sole routing signal: presence of `X-Ops-Version:` header in raw email headers.
- Present → STRUCTURED → SKIP (already in queue via push_event)
- Absent  → UNSTRUCTURED → Gemini classify → INSERT queue → immediate Telegram delivery

**Why:** Routing by sender/subject/mailbox is fragile. The header is injected at emission time by OPB_Opsmail::emit() and is reliable.

## Gemini API call
- Endpoint: `https://generativelanguage.googleapis.com/v1beta/models/{model}:generateContent?key={api_key}`
- Add `"responseMimeType": "application/json"` to generationConfig — reduces but doesn't eliminate markdown fences in responses
- Always strip markdown fences before json_decode() as a fallback
- Required response fields: event_type, priority, summary, classification, confidence
- Validate all 5 fields present before accepting response; log and return null on failure

## IMAP connection
- Use `@imap_open()` to suppress PHP warnings on auth failure — check return value instead
- Options array: `['DISABLE_AUTHENTICATOR' => 'GSSAPI']` — prevents GSSAPI negotiation on Hostinger
- Mark emails `\Seen` in `finally` block regardless of processing outcome — prevents reprocessing on any failure path

## Idempotency guards
- Telegram: re-read `telegram_status` from DB before each delivery; skip if SENT
- Mailbox (unstructured): `content_hash = md5(sender:subject:body_excerpt[0:500])`; check before INSERT

## New settings keys (v3.0.0) — all under opsmail category
mailbox_processing_enabled, mailbox_imap_host, mailbox_imap_port (default 993),
mailbox_imap_username, mailbox_imap_password (password type),
mailbox_poll_interval (default 5 min),
telegram_bot_token (password type), telegram_chat_id,
gemini_api_key (password type), gemini_model (default gemini-2.5-flash)

## Schema additions (v3.0.0)
- `classification` VARCHAR(100) NULL — Gemini classification string
- `confidence` DECIMAL(4,3) NULL — Gemini confidence 0.000–1.000
- `origin_type` ENUM extended: added 'MAILBOX' via MODIFY COLUMN (MySQL 5.7 safe)
- Migration uses INFORMATION_SCHEMA to check COLUMN_TYPE for MAILBOX before altering ENUM

## New REST endpoints (v3.0.0, all POST, manage_options)
/opsmail/process-mailbox, /opsmail/process-telegram, /opsmail/test-telegram,
/opsmail/test-gemini (accepts {text} param), /opsmail/test-mailbox

## WP Cron
- `opb_cron_process_mailbox` — custom schedule `opb_mailbox_interval` (reads poll_interval setting)
- `opb_cron_process_telegram` — hourly (WP built-in)
- Both handlers fully wrapped in try/catch; log to error_log only, never surface to WP

## Class load order
customizations → opsmail → telegram-consumer → mailbox-processor → (rest of includes)
Mailbox processor depends on OPB_Telegram_Consumer::deliver_event() so consumer must load first.
