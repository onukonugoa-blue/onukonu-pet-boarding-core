<?php
/**
 * OPB_Branch_Resolver
 *
 * Generic branch resolution service for all import pipelines.
 * Accepts any raw string (branch code, branch name, legacy outlet label,
 * or arbitrary text containing a code) and returns the matching branch.
 *
 * Resolution order (stops at first match):
 *   A. Exact branch ID    — "2"                         → branch with id=2
 *   B. Exact code         — "H2"                        → branch with code=H2
 *   C. Exact name         — "H2 Succoro"                → branch with name="H2 Succoro"
 *   D. Normalised match   — case-insensitive, collapsed  → "h2 succoro" matches "H2 Succoro"
 *   E. H-code extraction  — first /H\d+/ token in text  → "…boarding - H2 succoro" → H2
 *   F. Alias table        — configurable per-branch list → "Succoro" → H2
 *
 * Usage:
 *   $resolver = OPB_Branch_Resolver::from_db();
 *   $r = $resolver->resolve('Onukonu pet homestyle boarding - H2 succoro');
 *   if ($r['branch']) { $branch_id = $r['branch']->id; }
 */
class OPB_Branch_Resolver {

    /**
     * Default alias table.  Keys are branch codes; values are lists of raw
     * strings (compared case-insensitively after whitespace normalisation)
     * that should map to that code.
     *
     * Extend via the constructor $alias_table parameter when instantiating
     * for a specific import job.
     */
    private static array $default_aliases = [
        'H2' => [
            'H2',
            'H2 Succoro',
            'Succoro',
            'Onukonu pet homestyle boarding - H2 succoro',
            'Onukonu Pet Homestyle Boarding - H2 Succoro',
        ],
        'H3' => [
            'H3',
            'H3 Colvale',
            'Colvale',
            'Onukonu pet homestyle boarding - H3 Colvale',
            'Onukonu Pet Homestyle Boarding - H3 Colvale',
        ],
        'H4' => [
            'H4',
            'H4 Moira',
            'Moira',
            'Onukonu pet homestyle boarding - H4 Moira',
            'Onukonu Pet Homestyle Boarding - H4 Moira',
        ],
    ];

    /**
     * Branch objects indexed by code (string).
     * Each object has at minimum ->id (int), ->code (string), ->name (string).
     *
     * @var object[]
     */
    private array $branch_map;

    /**
     * Alias table: code → string[].
     * Comparisons are done on normalised versions of both sides.
     *
     * @var array<string, string[]>
     */
    private array $alias_table;

    // ─────────────────────────────────────────────────────────────────────────
    // Construction
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * @param object[] $branch_map   Map of branch objects keyed by branch code.
     * @param array    $alias_table  Optional override; defaults to self::$default_aliases.
     */
    public function __construct( array $branch_map, array $alias_table = [] ) {
        $this->branch_map  = $branch_map;
        $this->alias_table = $alias_table ?: self::$default_aliases;
    }

    /**
     * Load active branches from the database and return a ready resolver.
     * The map is explicitly keyed by code to avoid wpdb OBJECT_K keying by id.
     */
    public static function from_db(): self {
        global $wpdb;
        $rows = $wpdb->get_results(
            "SELECT id, code, name FROM {$wpdb->prefix}opb_branches WHERE is_active = 1"
        ) ?: [];

        $map = [];
        foreach ( $rows as $row ) {
            $map[ $row->code ] = $row;   // keyed by code, e.g. "H2"
        }
        return new self( $map );
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Public API
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Resolve a raw string to a branch.
     *
     * Returns an array:
     *   'branch'     => object|null  — the matched branch object, or null
     *   'matched_by' => string|null  — human-readable description of the winning strategy
     *   'diagnostics'=> array        — full trace of what was attempted
     */
    public function resolve( string $raw ): array {
        $raw  = trim( $raw );
        $norm = $this->normalise( $raw );

        $diag = [
            'input'          => $raw,
            'normalised'     => $norm,
            'extracted_code' => null,
            'strategy'       => null,
            'alias_checked'  => false,
        ];

        // A. Exact branch ID (pure numeric string)
        if ( $raw !== '' && ctype_digit( $raw ) ) {
            foreach ( $this->branch_map as $b ) {
                if ( (int) $b->id === (int) $raw ) {
                    return $this->hit( $b, "exact ID match ($raw)", 'exact_id', $diag );
                }
            }
        }

        // B. Exact code match
        if ( isset( $this->branch_map[ $raw ] ) ) {
            return $this->hit( $this->branch_map[ $raw ], "exact code match '$raw'", 'exact_code', $diag );
        }

        // C. Exact name match
        foreach ( $this->branch_map as $b ) {
            if ( $b->name === $raw ) {
                return $this->hit( $b, "exact name match '{$b->name}'", 'exact_name', $diag );
            }
        }

        // D. Normalised match — code and name (lowercase, collapsed whitespace)
        foreach ( $this->branch_map as $b ) {
            if ( $this->normalise( $b->code ) === $norm || $this->normalise( $b->name ) === $norm ) {
                return $this->hit( $b, "normalised match '$raw' → {$b->code}", 'normalised', $diag );
            }
        }

        // E. H-code extraction — first /H\d+/ token from arbitrary text
        if ( preg_match( '/\b(H\d+)\b/i', $raw, $m ) ) {
            $extracted = strtoupper( $m[1] );
            $diag['extracted_code'] = $extracted;
            if ( isset( $this->branch_map[ $extracted ] ) ) {
                return $this->hit( $this->branch_map[ $extracted ], "H-code '$extracted' extracted from '$raw'", 'hcode_extraction', $diag );
            }
        }

        // F. Alias table — normalised comparison of each alias
        $diag['alias_checked'] = true;
        foreach ( $this->alias_table as $code => $aliases ) {
            if ( ! isset( $this->branch_map[ $code ] ) ) {
                continue;   // alias points to a code not in the DB — skip
            }
            foreach ( $aliases as $alias ) {
                if ( $this->normalise( $alias ) === $norm ) {
                    return $this->hit(
                        $this->branch_map[ $code ],
                        "alias match '$alias' → $code",
                        'alias_table',
                        $diag
                    );
                }
            }
        }

        // No match
        return [
            'branch'      => null,
            'matched_by'  => null,
            'diagnostics' => array_merge( $diag, [
                'failure_reason'  => "No match found for '$raw' after all six strategies.",
                'strategies_tried'=> ['exact_id','exact_code','exact_name','normalised','hcode_extraction','alias_table'],
                'available'       => $this->describe_branches(),
                'aliases_table'   => $this->alias_table,
            ]),
        ];
    }

    /** Returns true when no branches were loaded from the DB. */
    public function is_empty(): bool {
        return empty( $this->branch_map );
    }

    /** Returns branch codes available in the loaded map, e.g. ['H2','H3','H4']. */
    public function available_codes(): array {
        return array_keys( $this->branch_map );
    }

    /** Returns a human-readable description of each loaded branch. */
    public function describe_branches(): array {
        return array_map( fn( $b ) => "{$b->code} ({$b->name})", $this->branch_map );
    }

    /** Returns the raw branch map (keyed by code). */
    public function branch_map(): array {
        return $this->branch_map;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Private helpers
    // ─────────────────────────────────────────────────────────────────────────

    private function hit( object $branch, string $matched_by, string $strategy, array $diag ): array {
        $diag['strategy'] = $strategy;
        return [
            'branch'      => $branch,
            'matched_by'  => $matched_by,
            'diagnostics' => $diag,
        ];
    }

    /** Lowercase + collapse/trim whitespace. Used for all non-exact comparisons. */
    private function normalise( string $s ): string {
        return strtolower( preg_replace( '/\s+/', ' ', trim( $s ) ) );
    }
}
