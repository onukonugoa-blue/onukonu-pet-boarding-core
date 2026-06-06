---
name: wpdb::update null/format bug
description: Silent update failures when null values are passed to $wpdb->update() without explicit format arrays — discovered while fixing invoice doc persistence on Hostinger.
---

## The rule
Always pass explicit `$data_formats` and `$where_formats` arrays to `$wpdb->update()` when any data value could be `null` or a mix of string/int types.

**Why:** WordPress auto-detects PHP types to choose `%s`/`%d`. When a value is `null`, WordPress picks `%s`, which in MySQL strict mode (default on modern MariaDB/Hostinger) produces an implicit empty-string cast for integer/bigint columns, causing the entire UPDATE to fail silently — `$wpdb->update()` returns `false` with no exception thrown.

**How to apply:**
- For every `$wpdb->update()` call with nullable fields, supply the 4th and 5th arguments explicitly: `[ '%s', '%d', ... ]` and `[ '%d' ]`.
- Normalize `null` user IDs to `0` (`$user_id ?? 0`) before passing as `%d` format.
- Always capture the return value; throw a `RuntimeException` with `$wpdb->last_error` if it's `false`.

## Symptom pattern
- Operation succeeds immediately (token returned from memory).
- On page refresh, record shows no data (token is NULL in DB).
- Audit log (separate table, separate INSERT) persists correctly — so "the DB works" but the specific UPDATE silently failed.
