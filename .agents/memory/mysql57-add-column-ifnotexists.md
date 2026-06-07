---
name: MySQL 5.7 ADD COLUMN IF NOT EXISTS incompatibility
description: ALTER TABLE ... ADD COLUMN IF NOT EXISTS fails silently on MySQL 5.7; use INFORMATION_SCHEMA-guarded ALTERs instead.
---

# MySQL 5.7 ADD COLUMN IF NOT EXISTS incompatibility

## The rule
Never use `ADD COLUMN IF NOT EXISTS`, `CREATE INDEX IF NOT EXISTS`, or `DROP INDEX IF EXISTS` in `$wpdb->query()` calls. These are MariaDB / MySQL 8.0.3+ syntax. On MySQL 5.7 (Hostinger shared hosting), `$wpdb->query()` returns `false` silently and execution continues — leaving columns permanently absent.

**Why:** This was the root cause of the Invoice PDF Engine schema migration failure (v2.0.4). The `opb_invoice_audit` table was created (standard SQL, always works) but the four `doc_*` columns on `opb_invoices` were never added because every ALTER TABLE returned false silently.

## How to apply
Use INFORMATION_SCHEMA helper methods in `OPB_Activator`:

```php
private static function col_exists( string $table, string $col ): bool {
    global $wpdb;
    return (bool) $wpdb->get_var( $wpdb->prepare(
        "SELECT COUNT(*) FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND COLUMN_NAME = %s",
        $table, $col
    ) );
}

private static function add_col( string $table, string $col, string $def ): void {
    global $wpdb;
    if ( ! self::col_exists( $table, $col ) ) {
        $wpdb->query( "ALTER TABLE `{$table}` ADD COLUMN `{$col}` {$def}" );
    }
}
```

Similarly for indexes: check `information_schema.STATISTICS` before running `ALTER TABLE ... ADD/DROP INDEX`.

## What is safe on MySQL 5.6+
- `CREATE TABLE IF NOT EXISTS` — standard SQL, always safe
- `$wpdb->get_var(information_schema query)` — read-only, always safe
- Plain `ALTER TABLE ... ADD COLUMN col_name ...` (without IF NOT EXISTS) — safe after existence check
