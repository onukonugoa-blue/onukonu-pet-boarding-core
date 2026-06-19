---
name: SAL v3.1.0 architecture
description: Situational Awareness Layer design decisions and non-obvious constraints
---

## Core architecture

OPB_SAL_Snapshot → OPB_SAL_Formatter → OPB_SAL_Scheduler::run_brief() → opsmail_queue + direct Telegram

## Key decisions

**Cron approach**: uses a single hourly `opb_cron_sal_check` hook rather than one daily-at-specific-time event per brief type. The handler checks `current_time('G') === configured_hour`. Deduplication is a WP option keyed `opb_sal_sent_today_{type}_{date}` (auto-expires by key rotation).

**Why**: WP Cron on shared hosting cannot reliably target a specific minute of a specific hour. Hourly check + date-sent guard avoids missing or duplicating briefs.

**Telegram delivery**: SAL uses `OPB_Telegram_Consumer::send_telegram_to($token, $chat_id, $text)` (added in this version) to deliver directly to the SAL chat ID rather than going through the queue consumer. The queue entry is written first for audit trail (source_system='SAL'), then the queue row is updated SENT/FAILED after direct delivery.

**Why**: The queue consumer delivers to the single default chat_id. SAL has a separate configurable reporting destination (sal_telegram_chat_id). Using send_telegram_to() avoids modifying the consumer's routing logic.

**Gemini as formatter only**: `OPB_SAL_Formatter::format()` sends the snapshot as a structured data block in a tightly constrained prompt. The prompt explicitly forbids forecasting, KPIs, and recommendations. Gemini returns plain HTML (not JSON). If Gemini fails for any reason, `deterministic_fallback()` generates the same brief from the same snapshot data without AI.

**No brief is ever silently discarded**: Both Gemini success and failure paths produce a telegram_message.

**Preview mode**: `POST /sal/generate` runs the full pipeline (snapshot → format) and returns all four stages: snapshot_json, prompt, gemini_output, telegram_message. Does NOT queue or deliver.

**Config keys added to OPB_Customizations::REGISTRY**: sal_enabled, sal_morning_brief_enabled/time, sal_evening_brief_enabled/time, sal_accounts_snapshot_enabled/time, sal_telegram_chat_id (all category='sal').

**Diagnostics**: stored as WP options (opb_sal_last_run_{type}, opb_sal_last_success_{type}, opb_sal_last_failure_{type}, opb_sal_last_error_{type}) — no new table needed.

## Files

- `includes/class-opb-sal-snapshot.php` — DB snapshot engine (all data domains)
- `includes/class-opb-sal-formatter.php` — Gemini call + deterministic fallback
- `includes/class-opb-sal-scheduler.php` — cron scheduling + run_brief() orchestrator
- `includes/api/class-opb-sal-api.php` — REST endpoints (/sal/config, /generate, /send, /test-telegram, /diagnostics)
- `app/src/pages/admin/SalDashboard.tsx` — admin UI (schedule, telegram, ops, preview, diagnostics)

## How to apply

When extending SAL (new brief types, new data domains), follow this pattern:
1. Add domain query to OPB_SAL_Snapshot (returns plain associative arrays, no objects)
2. Add data_block lines in OPB_SAL_Formatter::build_data_block()
3. Add fallback rendering in OPB_SAL_Formatter::deterministic_fallback()
4. Register new brief type in OPB_SAL_Scheduler::BRIEF_TYPES
