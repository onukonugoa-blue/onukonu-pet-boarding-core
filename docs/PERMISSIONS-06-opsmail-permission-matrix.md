# OPB Permission Audit — Part 6: OPSMAIL Permission Matrix

**Plugin Version:** 3.1.0  
**Audit Date:** June 2026  
**Scope:** All OPSMAIL REST endpoints, the permission mechanism used, and role access

---

## 1. OPSMAIL Permission Mechanism

Every OPSMAIL endpoint uses the `super_admin_only()` method defined in `class-opb-opsmail-api.php`:

```php
public function super_admin_only( WP_REST_Request $r ): bool|WP_Error {
    return $this->permission_manage( 'manage_options', $r );
}
```

`permission_manage('manage_options', $r)` evaluates as:

```
1. Is the user logged in? (permission_check)
2. Does the user have any OPB role or manage_options? (has_opb_role check)
3. Does the user have manage_options OR manage_options? → Does the user have manage_options?
```

**Result: Only WordPress `administrator` users can access OPSMAIL.**

The `opb_super_admin` role does NOT hold `manage_options`. Unless a site administrator explicitly grants `manage_options` to an `opb_super_admin` user as an additional capability, that user cannot access any OPSMAIL endpoint.

---

## 2. OPSMAIL Endpoint Registry

All OPSMAIL routes are registered under the namespace `opb/v1` with the prefix `/opsmail/`.

| Endpoint | Method | Action | Permission |
|---|---|---|---|
| `/opsmail/queue` | GET | View message queue | `super_admin_only` |
| `/opsmail/stats` | GET | View queue statistics | `super_admin_only` |
| `/opsmail/queue/{id}/acknowledge` | POST | Acknowledge a queued message | `super_admin_only` |
| `/opsmail/process-mailbox` | POST | Trigger IMAP mailbox processor | `super_admin_only` |
| `/opsmail/process-telegram` | POST | Trigger Telegram consumer | `super_admin_only` |
| `/opsmail/test-telegram` | POST | Send test message to Telegram | `super_admin_only` |
| `/opsmail/test-gemini` | POST | Test Gemini API connection | `super_admin_only` |
| `/opsmail/gemini-run` | POST | Run Gemini classification on a message | `super_admin_only` |
| `/opsmail/test-mailbox` | POST | Test IMAP mailbox connection | `super_admin_only` |
| `/opsmail/cron-health` | GET | View WP Cron schedule health | `super_admin_only` |

---

## 3. OPSMAIL Role Access Matrix

| Action | Super Admin | Branch Manager | Reception | Staff | WP Administrator |
|---|:---:|:---:|:---:|:---:|:---:|
| View message queue | ❌ | ❌ | ❌ | ❌ | ✅ |
| View queue statistics | ❌ | ❌ | ❌ | ❌ | ✅ |
| Acknowledge message | ❌ | ❌ | ❌ | ❌ | ✅ |
| Run mailbox processor | ❌ | ❌ | ❌ | ❌ | ✅ |
| Run Telegram consumer | ❌ | ❌ | ❌ | ❌ | ✅ |
| Test Telegram connection | ❌ | ❌ | ❌ | ❌ | ✅ |
| Test Gemini connection | ❌ | ❌ | ❌ | ❌ | ✅ |
| Run Gemini classification | ❌ | ❌ | ❌ | ❌ | ✅ |
| Test IMAP mailbox | ❌ | ❌ | ❌ | ❌ | ✅ |
| View cron health | ❌ | ❌ | ❌ | ❌ | ✅ |

---

## 4. OPSMAIL-Specific Access Questions

| Question | Answer |
|---|---|
| **Can super admin access diagnostics?** | ❌ Only WP `administrator` can access `/opsmail/*` |
| **Can super admin configure Telegram?** | ❌ Telegram settings are in customization keys (`opb_manage_settings`), but OPSMAIL test/run endpoints require `manage_options` |
| **Can super admin configure Gemini?** | ❌ Same — Gemini API key is a customization key (super admin can set it), but OPSMAIL test/gemini-run require `manage_options` |
| **Can super admin view queue?** | ❌ |
| **Can super admin flush/acknowledge queue?** | ❌ |
| **Can super admin run tests?** | ❌ |
| **Can branch manager access any OPSMAIL endpoint?** | ❌ |
| **Can reception access any OPSMAIL endpoint?** | ❌ |
| **Can staff access any OPSMAIL endpoint?** | ❌ |

---

## 5. OPSMAIL Configuration — Where Settings Live

OPSMAIL configuration (API keys, recipient IDs, toggle flags) is stored via the OPB Customizations system, not in the OPSMAIL endpoints. Customization keys are read/written via `/opb/v1/customizations/*`, which requires `opb_manage_settings`.

| Setting Category | API | Permission Required |
|---|---|---|
| Telegram bot token / chat ID | `/customizations` | `opb_manage_settings` (super admin) |
| Gemini API key | `/customizations` | `opb_manage_settings` (super admin) |
| IMAP credentials | `/customizations` | `opb_manage_settings` (super admin) |
| OPSMAIL enabled/disabled flags | `/customizations` | `opb_manage_settings` (super admin) |
| Queue viewer / diagnostics | `/opsmail/*` | `manage_options` (WP admin only) |
| Run processors / tests | `/opsmail/*` | `manage_options` (WP admin only) |

**Key split:** An `opb_super_admin` user can configure OPSMAIL settings (keys, toggles) but cannot operate OPSMAIL (view queue, run processors, test connections).

---

## 6. OPSMAIL WP Cron Integration

OPSMAIL uses WordPress Cron for automated processing. Cron jobs run server-side and bypass all REST permission checks. The following cron hooks are registered:

| Hook | Interval | Action |
|---|---|---|
| `opb_opsmail_send_queue` | Scheduled | Process outbound OPSMAIL queue |
| `opb_mailbox_interval` | Hourly | Run IMAP mailbox processor |

Cron execution does not require any user session — it runs as the WP scheduler. Permission checks only apply to manual triggers via the REST API.

---

## 7. Queue Security Model

The OPSMAIL queue (`opb_opsmail_queue` table) is:
- **Written to** by various OPB modules via internal PHP calls (no REST endpoint for writing to the queue externally)
- **Read via REST** only by WP administrators through `/opsmail/queue`
- **Processed via REST** only by WP administrators through process-mailbox / process-telegram
- **Processed via WP Cron** automatically — no user required

No external party can inject into the OPSMAIL queue via the REST API.
