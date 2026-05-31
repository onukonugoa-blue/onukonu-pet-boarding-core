<?php
/**
 * OPB_Import_Adapter — abstract base for all import adapters.
 *
 * Each concrete adapter declares:
 *   entity()        — the short key used in API calls ("clients", "bookings", …)
 *   column_groups() — field label → CSV column aliases (prefix '*' for required)
 *   process_row()   — validate + optionally insert one row; return status array
 *
 * The base class handles:
 *   • The import loop with skip / imported accounting
 *   • Header-level diagnostics (analyse_headers)
 *   • Skip-reason tallying and per-row detail (capped at 50)
 *   • Shared helpers: col(), parse_date(), parse_datetime(), normalise_mode()
 */
abstract class OPB_Import_Adapter {

    // ─────────────────────────────────────────────────────────────────────────
    // Must-implement contract
    // ─────────────────────────────────────────────────────────────────────────

    abstract public function entity(): string;

    /**
     * @return array<string, string[]>  field_label => [alias1, alias2, …]
     *         Prefix the label with '*' to mark it as required for diagnostics.
     */
    abstract public function column_groups(): array;

    /**
     * Validate and (on live run) insert a single row.
     *
     * @param  array  $row     Associative: CSV header → value (already trimmed by col()).
     * @param  int    $row_num 1-indexed; header row = 1, first data = 2.
     * @param  bool   $dry     true = validate only, no DB writes.
     * @param  array  $ctx     Optional context, e.g. ['branch_id' => 3].
     *
     * @return array{ status: 'imported'|'skipped', reason_code: string, detail: string }
     */
    abstract protected function process_row( array $row, int $row_num, bool $dry, array $ctx ): array;

    // ─────────────────────────────────────────────────────────────────────────
    // Engine entry point
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * @param  array[]  $rows
     * @param  bool     $dry
     * @param  string[] $headers  Raw header list (for diagnostics).
     * @param  array    $ctx      Passed through to every process_row() call.
     */
    public function run( array $rows, bool $dry, array $headers, array $ctx = [] ): array {
        $imported     = 0;
        $skipped      = 0;
        $errors       = [];
        $skip_reasons = [];
        $skipped_rows = [];

        foreach ( $rows as $i => $row ) {
            $row_num = $i + 2;
            $result  = $this->process_row( $row, $row_num, $dry, $ctx );

            if ( $result['status'] === 'imported' ) {
                $imported++;
            } else {
                $skipped++;
                $code                = $result['reason_code'] ?? 'unknown';
                $skip_reasons[$code] = ($skip_reasons[$code] ?? 0) + 1;
                $errors[]            = "Row {$row_num}: [{$code}] {$result['detail']}";
                if ( count($skipped_rows) < 50 ) {
                    $skipped_rows[] = [
                        'row'    => $row_num,
                        'reason' => $code,
                        'detail' => $result['detail'],
                    ];
                }
            }
        }

        $response = [
            'imported' => $imported,
            'skipped'  => $skipped,
            'errors'   => array_slice($errors, 0, 50),
            'total'    => count($rows),
            'dry_run'  => $dry,
        ];

        if ( $dry ) {
            $response['diagnostics'] = [
                'headers'      => $this->analyse_headers($headers),
                'skip_reasons' => $skip_reasons,
                'skipped_rows' => $skipped_rows,
                'note'         => count($skipped_rows) < $skipped
                    ? 'skipped_rows shows first ' . count($skipped_rows) . ' of ' . $skipped . ' skipped rows'
                    : 'all skipped rows shown',
            ];
        }

        return $response;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Diagnostics
    // ─────────────────────────────────────────────────────────────────────────

    public function analyse_headers( array $headers ): array {
        $groups   = $this->column_groups();
        $analysis = [];
        $missing  = [];

        foreach ( $groups as $label => $aliases ) {
            $required = str_starts_with($label, '*');
            $key      = ltrim($label, '*');
            $matched  = null;

            foreach ( $aliases as $alias ) {
                if ( in_array($alias, $headers, true) ) { $matched = $alias; break; }
            }

            $analysis[$key] = [
                'found'    => $matched !== null,
                'matched'  => $matched,
                'searched' => $aliases,
            ];

            if ( $required && ! $matched ) {
                $missing[] = "$key (searched: " . implode(', ', $aliases) . ')';
            }
        }

        $resolver = OPB_Branch_Resolver::from_db();

        return [
            'headers_detected'   => $headers,
            'header_count'       => count($headers),
            'column_analysis'    => $analysis,
            'missing_required'   => $missing,
            'branch_codes_in_db' => $resolver->available_codes(),
            'branches_in_db'     => $resolver->describe_branches(),
        ];
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Shared helpers — available to every adapter
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Return the first non-empty trimmed value from $row matching any key in $keys.
     */
    protected function col( array $row, array $keys, string $default = '' ): string {
        foreach ( $keys as $key ) {
            if ( isset($row[$key]) && trim($row[$key]) !== '' ) {
                return trim($row[$key]);
            }
        }
        return $default;
    }

    /**
     * Parse a flexible date string → 'Y-m-d', or null.
     * Handles: "Nov 27, 2023" | "01-05-2026" | "2023-11-27" | "27/11/2023" | "1 May 2026"
     */
    protected function parse_date( string $raw ): ?string {
        $raw = trim($raw);
        if ( ! $raw ) return null;
        foreach ( ['M j, Y', 'd-m-Y', 'Y-m-d', 'd/m/Y', 'j M Y', 'd M Y', 'Y/m/d', 'n/j/Y', 'j/n/Y'] as $fmt ) {
            $dt = DateTime::createFromFormat($fmt, $raw);
            if ( $dt && $dt->format($fmt) === $raw || $dt ) return $dt->format('Y-m-d');
        }
        return null;
    }

    /**
     * Parse a flexible datetime string → 'Y-m-d H:i:s', or null.
     * Handles: "11:49 AM, 1 May 2026" | "12:00 AM, 24 Sep 2025"
     */
    protected function parse_datetime( string $raw ): ?string {
        $raw = trim($raw);
        if ( ! $raw ) return null;
        foreach ( ['g:i A, j M Y', 'g:i A, d M Y', 'Y-m-d H:i:s', 'd-m-Y H:i:s', 'Y-m-d\TH:i:s'] as $fmt ) {
            $dt = DateTime::createFromFormat($fmt, $raw);
            if ( $dt ) return $dt->format('Y-m-d H:i:s');
        }
        // Fall back to date-only
        return $this->parse_date($raw) ? $this->parse_date($raw) . ' 00:00:00' : null;
    }

    /**
     * Normalise a payment mode string to the DB ENUM: 'Cash' | 'UPI' | 'Other'.
     */
    protected function normalise_mode( string $raw ): string {
        return match ( strtolower(trim($raw)) ) {
            'cash'                                        => 'Cash',
            'upi','gpay','phonepe','paytm','neft','rtgs',
            'imps','online','bank transfer','net banking' => 'UPI',
            default                                       => 'Other',
        };
    }
}
