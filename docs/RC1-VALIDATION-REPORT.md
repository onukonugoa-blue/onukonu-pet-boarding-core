# OPB RC1 — Validation Report

**Product:** Onukonu Pet Boarding Core (OPB)
**Release:** RC1
**Plugin version:** 3.3.0
**Validation date:** 2026-06-19

---

## Overview

This report documents the RC1 validation checklist. Each item confirms a production-readiness criterion for the `onukonu-pet-boarding-rc1.zip` deliverable.

Validation is performed against repository source code (HEAD `1606363` on `main`). Runtime tests must be executed on a live WordPress installation with credentials configured.

---

## 1. Plugin Activation Test

**What it tests:** `register_activation_hook` → `OPB_Activator::activate()` → `create_tables()` → `flush_rewrite_rules()`

**Expected result:**
- All 33 `wp_opb_*` database tables created
- OPB roles registered (`opb_super_admin`, `opb_branch_manager`, `opb_reception`, `opb_staff`)
- All WP Cron hooks scheduled
- No PHP fatal errors
- Plugin appears as active in WordPress admin

**Upgrade path:** `opb_db_version` option is checked on every `init`. If it doesn't match `OPB_VERSION`, `create_tables()` runs automatically — safe for upgrades without deactivate/activate cycle.

**How to run:**
1. Upload `onukonu-pet-boarding-rc1.zip` via WP Admin → Plugins → Upload Plugin
2. Click Activate
3. Check for any admin notices or PHP errors
4. Verify `/wp-json/opb/v1/branches` returns a valid response when logged in as OPB Super Admin

---

## 2. Telegram Test Message

**Endpoint:** `POST /wp-json/opb/v1/opsmail/test-telegram`

**Prerequisites:**
- `telegram_bot_token` set in OPB Customizations
- `telegram_chat_id` set in OPB Customizations

**Expected result:**
- Returns `{ "ok": true, "result": {...} }`
- Test message appears in configured Telegram group within seconds

**Via OPSMAIL admin UI:** Settings → OPSMAIL → Telegram → Test Telegram Message

---

## 3. Queue Processing Test

**Endpoint:** `POST /wp-json/opb/v1/opsmail/process-telegram`

**Expected result:**
```json
{
  "log": [
    { "status": "ok", "reason": "No pending Telegram entries", "delivered": 0 }
  ]
}
```
(or delivery confirmation if pending items exist)

**Via OPSMAIL admin UI:** Settings → OPSMAIL → Queue → Flush Queue

---

## 4. Gemini Test

**Endpoint:** `POST /wp-json/opb/v1/opsmail/test-gemini`

**Prerequisites:** `gemini_api_key` set in OPB Customizations

**Request body:**
```json
{ "text": "Hello from OPB RC1 validation test." }
```

**Expected result:**
```json
{
  "ok": true,
  "classification": "...",
  "intent": "...",
  "sentiment": "...",
  "summary": "...",
  "timing_ms": 800
}
```

**Via OPSMAIL admin UI:** Settings → OPSMAIL → Gemini → Test Gemini

---

## 5. SAL Morning Brief — Preview

**Endpoint:** `POST /wp-json/opb/v1/sal/generate`

**Request body:**
```json
{ "brief_type": "morning" }
```

**Expected result:**
```json
{
  "ok": true,
  "brief_type": "morning",
  "prompt": "...",
  "gemini_output": "...",
  "telegram_message": "...",
  "used_fallback": false,
  "timing_ms": 1200
}
```

If Gemini is not configured, `used_fallback: true` and `telegram_message` contains the deterministic brief.

**Via SAL admin UI:** Settings → SAL → Morning Brief → Preview

---

## 6. SAL Morning Brief — Delivery

**Endpoint:** `POST /wp-json/opb/v1/sal/send`

**Request body:**
```json
{ "brief_type": "morning" }
```

**Expected result:**
```json
{
  "ok": true,
  "queue_id": 42,
  "telegram_ok": true,
  "used_fallback": false,
  "timing_ms": 1400
}
```

**Expected delivery:** Morning brief appears in the configured SAL Telegram chat within seconds.

Note: Manual "Send Now" does NOT write the idempotency flag — the brief can be resent from the UI without waiting until tomorrow.

**Via SAL admin UI:** Settings → SAL → Morning Brief → Send Now

---

## 7. SAL Evening Brief — Preview

Same as Morning Brief preview with `"brief_type": "evening"`.

**Via SAL admin UI:** Settings → SAL → Evening Brief → Preview

---

## 8. SAL Accounts Snapshot — Preview

Same as Morning Brief preview with `"brief_type": "accounts"`.

**Via SAL admin UI:** Settings → SAL → Accounts Snapshot → Preview

---

## 9. Cron Registration Test

**Endpoint:** `GET /wp-json/opb/v1/opsmail/cron-health`

**Expected result:**
```json
{
  "site_url": "https://your-domain.com",
  "wp_cron_url": "https://your-domain.com/wp-cron.php?doing_wp_cron",
  "wp_cron_disabled": false,
  "components": {
    "queue":   { "status": "healthy" | "delayed" | "not_running", ... },
    "mailbox": { "status": "healthy" | "delayed" | "not_running", ... },
    "sal":     { "status": "healthy" | "delayed" | "not_running", ... }
  },
  "external_cron": "detected" | "unknown" | "not_detected",
  "overall_status": "healthy" | "delayed" | "not_running",
  "cron_active": true,
  "recommended_cron_command": "curl -s 'https://your-domain.com/wp-cron.php?doing_wp_cron' >/dev/null 2>&1",
  "recommended_frequency": "*/5 * * * *"
}
```

`cron_active: true` confirms the Telegram consumer hook is scheduled.

**Via OPSMAIL admin UI:** Settings → OPSMAIL → Scheduler Health

---

## 10. End-to-End Pipeline Test

**Confirms:** Gemini → OPSMAIL Queue → Telegram

1. Trigger a SAL Morning Brief via `POST /opb/v1/sal/send`
2. Check the queue via `GET /opb/v1/opsmail/queue` — brief should appear with `telegram_status: "SENT"`
3. Confirm brief appears in SAL Telegram group
4. Check delivery history via `GET /opb/v1/sal/history`

---

## 11. Build Validation

| Step | Command | Expected output |
|---|---|---|
| Install SPA dependencies | `cd plugin/app && npm install` | Clean install |
| Build SPA | `cd plugin/app && npm run build` | `plugin/assets/dist/assets/index.js` + `main.css` |
| Generate RC1 ZIP | `node build-rc1.js` | `onukonu-pet-boarding-rc1.zip` |

**No Node runtime required for production.** Only the compiled `plugin/assets/dist/` files are included in the ZIP. The `plugin/app/` source directory is excluded.

---

## 12. Validation Summary

| Test | Automatable | Requires live WP |
|---|---|---|
| Plugin activation | No | Yes |
| Telegram test message | No | Yes |
| Queue flush | No | Yes |
| Gemini test | No | Yes |
| SAL preview (all types) | No | Yes |
| SAL delivery (Morning) | No | Yes |
| Cron registration | No | Yes |
| Build pipeline | Yes | No |
| ZIP generation | Yes | No |

All automated build steps confirmed passing. All live WordPress tests require a WordPress installation with OPB credentials configured.
