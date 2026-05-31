<?php
/**
 * OPB_Migration_Engine
 *
 * Orchestrates all import runs:
 *   1. Reads CSV or XLSX files into normalised row arrays
 *   2. Dispatches to the registered OPB_Import_Adapter for the entity
 *   3. On live runs, logs each run to migration history (wp_options)
 *   4. Exposes history retrieval and status counts
 */
class OPB_Migration_Engine {

    const HISTORY_OPTION = 'opb_migration_history';
    const HISTORY_LIMIT  = 100;

    /** @var OPB_Import_Adapter[] keyed by entity */
    private array $adapters = [];

    // ─────────────────────────────────────────────────────────────────────────

    public function register( OPB_Import_Adapter $adapter ): void {
        $this->adapters[ $adapter->entity() ] = $adapter;
    }

    /**
     * Run an import (or dry-run) from a file on disk.
     *
     * @param  string $path    Absolute path to uploaded file (.csv or .xlsx).
     * @param  string $entity  Entity key, e.g. 'clients', 'bookings'.
     * @param  bool   $dry     true = validate only, no DB writes.
     * @param  array  $ctx     Extra context forwarded to the adapter, e.g. ['branch_id'=>3].
     */
    public function run( string $path, string $entity, bool $dry, array $ctx = [] ): array {
        if ( ! isset($this->adapters[$entity]) ) {
            return [
                'error'    => "No adapter registered for entity '$entity'. Available: " . implode(', ', array_keys($this->adapters)),
                'imported' => 0, 'skipped' => 0, 'errors' => [], 'total' => 0, 'dry_run' => $dry,
            ];
        }

        $ext    = strtolower( pathinfo($path, PATHINFO_EXTENSION) );
        $parsed = match ( $ext ) {
            'xlsx'  => OPB_Xlsx_Reader::to_rows($path),
            'csv'   => $this->read_csv($path),
            default => [ 'headers' => [], 'rows' => [], 'error' => "Unsupported file type: .$ext — use .csv or .xlsx" ],
        };

        if ( isset($parsed['error']) && empty($parsed['rows']) ) {
            return array_merge(
                [ 'imported' => 0, 'skipped' => 0, 'errors' => [], 'total' => 0, 'dry_run' => $dry ],
                $parsed
            );
        }

        $result = $this->adapters[$entity]->run( $parsed['rows'], $dry, $parsed['headers'], $ctx );

        if ( ! $dry ) {
            $this->log_history($entity, $ctx, $result);
        }

        return $result;
    }

    /** Retrieve migration history, most-recent first. */
    public function get_history(): array {
        return get_option(self::HISTORY_OPTION, []);
    }

    /** Clear all migration history. */
    public function clear_history(): void {
        delete_option(self::HISTORY_OPTION);
    }

    /** List registered entity keys. */
    public function registered_entities(): array {
        return array_keys($this->adapters);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // File readers
    // ─────────────────────────────────────────────────────────────────────────

    private function read_csv( string $path ): array {
        if ( ($fh = fopen($path, 'r')) === false ) {
            return [ 'headers' => [], 'rows' => [], 'error' => "Cannot open CSV file." ];
        }

        $headers     = [];
        $rows        = [];
        $raw_headers = null;

        while ( ($line = fgetcsv($fh, 0, ',')) !== false ) {
            if ( ! $raw_headers ) {
                $raw_headers = array_map('trim', $line);
                if ( isset($raw_headers[0]) && str_starts_with($raw_headers[0], "\xEF\xBB\xBF") ) {
                    $raw_headers[0] = substr($raw_headers[0], 3);
                }
                $headers = $raw_headers;
                continue;
            }
            if ( count($line) < count($headers) ) {
                $line = array_pad($line, count($headers), '');
            }
            $row = array_combine($headers, array_slice($line, 0, count($headers)));
            if ( ! array_filter($row, fn($v) => trim($v) !== '') ) continue;
            $rows[] = $row;
        }
        fclose($fh);

        return [ 'headers' => $headers, 'rows' => $rows ];
    }

    // ─────────────────────────────────────────────────────────────────────────
    // History
    // ─────────────────────────────────────────────────────────────────────────

    private function log_history( string $entity, array $ctx, array $result ): void {
        $history = get_option(self::HISTORY_OPTION, []);

        array_unshift($history, [
            'entity'    => $entity,
            'timestamp' => current_time('mysql'),
            'user_id'   => get_current_user_id(),
            'context'   => $ctx,
            'imported'  => $result['imported'] ?? 0,
            'skipped'   => $result['skipped']  ?? 0,
            'total'     => $result['total']    ?? 0,
            'errors'    => array_slice($result['errors'] ?? [], 0, 10),
        ]);

        if ( count($history) > self::HISTORY_LIMIT ) {
            $history = array_slice($history, 0, self::HISTORY_LIMIT);
        }

        update_option(self::HISTORY_OPTION, $history, false);
    }
}
