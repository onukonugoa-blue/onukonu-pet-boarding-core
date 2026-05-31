<?php
/**
 * Standalone unit tests for OPB_Branch_Resolver.
 *
 * Run with:  php plugin/tests/test-opb-branch-resolver.php
 *
 * No WordPress or PHPUnit required — pure PHP 8.2.
 */

require_once __DIR__ . '/../includes/services/class-opb-branch-resolver.php';

// ─── Minimal test harness ────────────────────────────────────────────────────

$passed = 0;
$failed = 0;

function expect( string $label, mixed $actual, mixed $expected ): void {
    global $passed, $failed;
    if ( $actual === $expected ) {
        echo "  PASS  $label\n";
        $passed++;
    } else {
        $exp_str = is_null($expected) ? 'null' : var_export($expected, true);
        $act_str = is_null($actual)   ? 'null' : var_export($actual,   true);
        echo "  FAIL  $label\n        expected: $exp_str\n        got:      $act_str\n";
        $failed++;
    }
}

// ─── Mock branch map (keyed by code, matching from_db() output format) ───────

function make_branch( int $id, string $code, string $name ): object {
    return (object)[ 'id' => $id, 'code' => $code, 'name' => $name ];
}

$branches = [
    'H2' => make_branch( 2, 'H2', 'H2 Succoro' ),
    'H3' => make_branch( 3, 'H3', 'H3 Colvale' ),
    'H4' => make_branch( 4, 'H4', 'H4 Moira'   ),
];

$r = new OPB_Branch_Resolver( $branches );

// ─── Strategy A: exact branch ID ─────────────────────────────────────────────
echo "\nStrategy A — Exact branch ID\n";
expect( '"2" resolves to H2',  $r->resolve('2')['branch']?->code, 'H2' );
expect( '"3" resolves to H3',  $r->resolve('3')['branch']?->code, 'H3' );
expect( '"4" resolves to H4',  $r->resolve('4')['branch']?->code, 'H4' );
expect( '"99" returns null',   $r->resolve('99')['branch'],        null  );

// ─── Strategy B: exact code ───────────────────────────────────────────────────
echo "\nStrategy B — Exact code\n";
expect( '"H2" resolves to H2', $r->resolve('H2')['branch']?->code, 'H2' );
expect( '"H3" resolves to H3', $r->resolve('H3')['branch']?->code, 'H3' );
expect( '"H4" resolves to H4', $r->resolve('H4')['branch']?->code, 'H4' );

// ─── Strategy C: exact name ───────────────────────────────────────────────────
echo "\nStrategy C — Exact name\n";
expect( '"H2 Succoro" resolves to H2', $r->resolve('H2 Succoro')['branch']?->code, 'H2' );
expect( '"H3 Colvale" resolves to H3', $r->resolve('H3 Colvale')['branch']?->code, 'H3' );
expect( '"H4 Moira" resolves to H4',   $r->resolve('H4 Moira')['branch']?->code,   'H4' );

// ─── Strategy D: normalised match ─────────────────────────────────────────────
echo "\nStrategy D — Normalised (case-insensitive, whitespace-collapsed)\n";
expect( '"h2" resolves to H2',           $r->resolve('h2')['branch']?->code,           'H2' );
expect( '"h2 succoro" resolves to H2',   $r->resolve('h2 succoro')['branch']?->code,   'H2' );
expect( '"H3  Colvale" resolves to H3',  $r->resolve('H3  Colvale')['branch']?->code,  'H3' ); // double space
expect( '"  H4 Moira  " resolves to H4', $r->resolve('  H4 Moira  ')['branch']?->code, 'H4' ); // leading/trailing

// ─── Strategy E: H-code extraction ───────────────────────────────────────────
echo "\nStrategy E — H-code extraction from arbitrary text\n";
expect(
    '"Onukonu pet homestyle boarding - H2 succoro" → H2',
    $r->resolve('Onukonu pet homestyle boarding - H2 succoro')['branch']?->code,
    'H2'
);
expect(
    '"Onukonu pet homestyle boarding - H3 Colvale" → H3',
    $r->resolve('Onukonu pet homestyle boarding - H3 Colvale')['branch']?->code,
    'H3'
);
expect(
    '"Onukonu pet homestyle boarding - H4 Moira" → H4',
    $r->resolve('Onukonu pet homestyle boarding - H4 Moira')['branch']?->code,
    'H4'
);
expect(
    '"Visit H3 for grooming" → H3',
    $r->resolve('Visit H3 for grooming')['branch']?->code,
    'H3'
);
expect(
    '"h4-branch" → H4 (lowercase extraction)',
    $r->resolve('h4-branch')['branch']?->code,
    'H4'
);

// ─── Strategy F: alias table ──────────────────────────────────────────────────
echo "\nStrategy F — Alias table\n";
expect( '"Succoro" → H2',  $r->resolve('Succoro')['branch']?->code,  'H2' );
expect( '"succoro" → H2',  $r->resolve('succoro')['branch']?->code,  'H2' );
expect( '"Colvale" → H3',  $r->resolve('Colvale')['branch']?->code,  'H3' );
expect( '"COLVALE" → H3',  $r->resolve('COLVALE')['branch']?->code,  'H3' );
expect( '"Moira" → H4',    $r->resolve('Moira')['branch']?->code,    'H4' );
expect(
    'Full legacy label H2 via alias',
    $r->resolve('Onukonu Pet Homestyle Boarding - H2 Succoro')['branch']?->code,
    'H2'
);
expect(
    'Full legacy label H3 via alias',
    $r->resolve('Onukonu Pet Homestyle Boarding - H3 Colvale')['branch']?->code,
    'H3'
);
expect(
    'Full legacy label H4 via alias',
    $r->resolve('Onukonu Pet Homestyle Boarding - H4 Moira')['branch']?->code,
    'H4'
);

// ─── matched_by field ─────────────────────────────────────────────────────────
echo "\nmatched_by field\n";
expect( 'strategy A sets matched_by',    str_contains($r->resolve('2')['matched_by'] ?? '', 'exact ID'), true );
expect( 'strategy B sets matched_by',    str_contains($r->resolve('H2')['matched_by'] ?? '', 'exact code'), true );
expect( 'strategy E sets matched_by',    str_contains($r->resolve('boarding - H3 thing')['matched_by'] ?? '', 'H3'), true );

// ─── Failure diagnostics ──────────────────────────────────────────────────────
echo "\nFailure diagnostics\n";
$fail = $r->resolve('nonexistent branch xyz');
expect( 'unmatched returns null branch',       $fail['branch'],                  null  );
expect( 'unmatched has failure_reason',        isset($fail['diagnostics']['failure_reason']), true  );
expect( 'unmatched lists available branches',  isset($fail['diagnostics']['available']),      true  );
expect( 'unmatched has normalised input',      $fail['diagnostics']['normalised'], 'nonexistent branch xyz' );

// ─── Edge cases ───────────────────────────────────────────────────────────────
echo "\nEdge cases\n";
expect( 'empty string returns null',   $r->resolve('')['branch'],     null );
expect( 'whitespace-only returns null',$r->resolve('   ')['branch'],  null );
expect( 'is_empty() false on loaded',  $r->is_empty(),                false );
expect( 'available_codes()',           $r->available_codes(),         ['H2','H3','H4'] );

$empty = new OPB_Branch_Resolver([]);
expect( 'is_empty() true when no branches', $empty->is_empty(), true );
expect( 'resolve on empty map returns null', $empty->resolve('H2')['branch'], null );

// ─── Custom alias table ───────────────────────────────────────────────────────
echo "\nCustom alias table\n";
$custom = new OPB_Branch_Resolver( $branches, [
    'H2' => ['Main Branch', 'HQ'],
    'H3' => ['North'],
] );
expect( '"Main Branch" → H2 via custom alias', $custom->resolve('Main Branch')['branch']?->code, 'H2' );
expect( '"HQ" → H2 via custom alias',          $custom->resolve('HQ')['branch']?->code,          'H2' );
expect( '"North" → H3 via custom alias',       $custom->resolve('North')['branch']?->code,       'H3' );
// Default aliases NOT present in custom table
expect( '"Succoro" → null (no default alias)', $custom->resolve('Succoro')['branch'],             null );

// ─── Summary ──────────────────────────────────────────────────────────────────
echo "\n────────────────────────────────────────\n";
echo "  Passed: $passed\n";
echo "  Failed: $failed\n";
echo "────────────────────────────────────────\n";
exit( $failed > 0 ? 1 : 0 );
