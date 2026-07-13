<?php
/**
 * OPB_SAL_Formatter
 *
 * Situational Awareness Layer — Gemini Formatter + Deterministic Fallback (v3.3.0)
 *
 * Takes a structured snapshot from OPB_SAL_Snapshot and produces:
 *   1. A Telegram-ready situational awareness brief via Gemini.
 *   2. A deterministic fallback brief if Gemini is unavailable.
 *
 * PRINCIPLES:
 *   - Gemini is a FORMATTER only. It summarises facts already in the data.
 *   - No forecasting, no recommendations, no speculation.
 *   - If Gemini fails, the deterministic fallback delivers the brief anyway.
 *   - No briefing is ever silently discarded.
 *
 * SAFETY GUARANTEE:
 *   All public methods are wrapped in try/catch(\Throwable).
 */
class OPB_SAL_Formatter {

    // ── Entry point ────────────────────────────────────────────────────────────

    /**
     * Format a snapshot into a Telegram-ready brief.
     *
     * Returns full pipeline diagnostics for preview mode.
     *
     * @param  array  $snapshot   Structured snapshot from OPB_SAL_Snapshot::generate().
     * @return array  {
     *   ok: bool,
     *   prompt: string,
     *   gemini_output: string|null,
     *   telegram_message: string,
     *   used_fallback: bool,
     *   timing_ms: int,
     *   error?: string
     * }
     */
    public static function format( array $snapshot ): array {
        try {
            $prompt   = self::build_prompt( $snapshot );
            $start    = microtime( true );

            $gemini_output = self::call_gemini( $prompt );
            $timing_ms     = (int) round( ( microtime( true ) - $start ) * 1000 );

            if ( $gemini_output ) {
                $telegram_message = self::safe_truncate( self::clean_gemini_output( $gemini_output ) );
                return [
                    'ok'              => true,
                    'prompt'          => $prompt,
                    'gemini_output'   => $gemini_output,
                    'telegram_message'=> $telegram_message,
                    'used_fallback'   => false,
                    'timing_ms'       => $timing_ms,
                ];
            }

            // Gemini failed — use deterministic fallback
            $fallback = self::safe_truncate( self::deterministic_fallback( $snapshot ) );
            return [
                'ok'              => true,
                'prompt'          => $prompt,
                'gemini_output'   => null,
                'telegram_message'=> $fallback,
                'used_fallback'   => true,
                'timing_ms'       => $timing_ms,
            ];

        } catch ( \Throwable $e ) {
            error_log( '[OPB SAL] format() error: ' . $e->getMessage() );
            $fallback = self::safe_truncate( self::deterministic_fallback( $snapshot ) );
            return [
                'ok'              => true,
                'prompt'          => '',
                'gemini_output'   => null,
                'telegram_message'=> $fallback,
                'used_fallback'   => true,
                'timing_ms'       => 0,
                'error'           => $e->getMessage(),
            ];
        }
    }

    // ── Gemini call ────────────────────────────────────────────────────────────

    private static function call_gemini( string $prompt ): ?string {
        try {
            $api_key = trim( OPB_Customizations::get( 'gemini_api_key' ) );
            $model   = trim( OPB_Customizations::get( 'gemini_model' ) ) ?: 'gemini-2.5-flash';

            if ( ! $api_key ) {
                error_log( '[OPB SAL] Gemini API key not configured — using fallback.' );
                return null;
            }

            $url = 'https://generativelanguage.googleapis.com/v1beta/models/'
                 . rawurlencode( $model )
                 . ':generateContent?key=' . rawurlencode( $api_key );

            $response = wp_remote_post( $url, [
                'timeout' => 30,
                'headers' => [ 'Content-Type' => 'application/json' ],
                'body'    => wp_json_encode( [
                    'contents'         => [ [
                        'parts' => [ [ 'text' => $prompt ] ],
                    ] ],
                    'generationConfig' => [
                        'temperature'     => 0.1,
                        'maxOutputTokens' => 2048,
                        'thinkingConfig'  => [ 'thinkingBudget' => 0 ],
                    ],
                ] ),
            ] );

            if ( is_wp_error( $response ) ) {
                error_log( '[OPB SAL] Gemini request error: ' . $response->get_error_message() );
                return null;
            }

            $code = (int) wp_remote_retrieve_response_code( $response );
            if ( $code !== 200 ) {
                error_log( '[OPB SAL] Gemini HTTP ' . $code . ': ' . wp_remote_retrieve_body( $response ) );
                return null;
            }

            $api_body     = json_decode( wp_remote_retrieve_body( $response ), true );
            $finish_reason = $api_body['candidates'][0]['finishReason'] ?? 'UNKNOWN';

            if ( $finish_reason !== 'STOP' ) {
                error_log( '[OPB SAL] Gemini finishReason=' . $finish_reason . ' — output incomplete, using fallback.' );
                return null;
            }

            $text = $api_body['candidates'][0]['content']['parts'][0]['text'] ?? '';

            return $text ?: null;

        } catch ( \Throwable $e ) {
            error_log( '[OPB SAL] call_gemini() exception: ' . $e->getMessage() );
            return null;
        }
    }

    // ── Prompt builder ─────────────────────────────────────────────────────────

    /**
     * The hardcoded default prompt instructions.
     * Returned by get_default_prompt() and used as fallback when no custom prompt is stored.
     */
    public static function get_default_prompt( string $brief_type = 'morning' ): string {
        if ( $brief_type === 'accounts' ) {
            return self::get_accounts_prompt();
        }
        return <<<'DEFAULT'
You are an operations reporting assistant for a pet boarding facility.

You will receive structured operational data extracted from the OPB database.

Your responsibilities:
- Summarise facts already present in the data.
- Group related information logically.
- Highlight items that require attention (overstays, overdue tasks, unvaccinated pets).

You must NOT:
- Forecast or predict future events.
- Recommend business strategy.
- Assess performance or compare to targets.
- Infer facts not present in the data.
- Invent information.

TELEGRAM HTML RULES — follow exactly or message delivery will fail:
- Allowed tags ONLY: <b>, <i>, <code>. No other HTML tags at all.
- NO <ul>, <li>, <ol>, <p>, <br>, <h1>, <h2>, <h3>, <span>, <div> or any other tags.
- For bullet lists use the • character followed by a space. Never use HTML list tags.
- NO markdown (no **, no __, no ##, no ---).

Output format — use EXACTLY this structure:
🐾 <b>OPB {brief_label}</b>
<i>{date}</i>

<b>SUMMARY</b>
[2–3 sentences summarising the overall operational state for the day]

<b>ATTENTION REQUIRED</b>
[Items needing human action: overstays, overdue tasks, unvaccinated boarding pets. One • bullet per item. If nothing requires attention, write "None."]

<b>BOARDING</b>
[Per-branch: active count, arrivals, departures. One line per branch. Keep concise.]

<b>TASKS</b>
[Open, overdue, unassigned counts per branch. Overdue task titles using •.]

<b>MEDICATION & SPECIAL CARE</b>
[Pets currently boarded with ongoing medication or special care. One • per pet. If none, write "None."]

<b>OPERATIONAL EXCEPTIONS</b>
[Unvaccinated pets, no-shows, missing records. One • per item. If none, write "None."]

Important:
- Maximum 300 words total.
- Every fact must come from the data block below. Do not add context not present in the data.
DEFAULT;
    }

    private static function get_accounts_prompt(): string {
        return <<<'ACCOUNTS'
You are an operational reporting assistant for a pet boarding facility.

You receive structured accounts data. Produce a concise, management-ready Telegram briefing.

TELEGRAM HTML RULES — follow exactly or message delivery will fail:
- Allowed tags ONLY: <b>, <i>, <code>. No other HTML tags at all.
- NO <ul>, <li>, <ol>, <p>, <br>, <h1>, <h2>, <h3>, <span>, <div> or any other tags.
- For bullet items use the • character followed by a space. Never use HTML list tags.
- NO markdown (no **, no __, no ##, no ---).

CONTENT RULES:
- Maximum 350 words. Be concise and management-friendly.
- Every figure must come from the data block. Do not invent or infer.
- Express amounts ≥ ₹1 lakh as ₹X.XX lakh (e.g. ₹2.34 lakh).
- Do not list individual invoices. Do not list client names except one notable overdue balance per branch.
- Do not restate counts already summarised.

OUTPUT STRUCTURE — follow exactly, in this order:

🐾 <b>OPB {brief_label}</b>
<i>{date}</i>

<b>SUMMARY</b>
[2 sentences: total outstanding across all branches, payments received today, expenses today, total overdue invoice count across all branches.]

<b>ATTENTION REQUIRED</b>
[One • bullet per branch that has overdue invoices. Format strictly:
• BranchName: N overdue invoices · ₹X outstanding
If one client has a notably large individual balance (top overdue item), append: (largest: ClientName ₹X)
If no overdue invoices anywhere, write: None.]

<b>ACCOUNTS</b>
[One compact block per branch, separated by a blank line. For each branch:
<b>BranchName</b>
• Payments today: ₹X (N received)
• Unpaid: N invoices · ₹X outstanding
• Overdue >7d: N
• Expenses today: ₹X]

<i>⚙️ OPB SAL · {date}</i>
ACCOUNTS;
    }

    private static function build_prompt( array $snapshot ): string {
        $date       = $snapshot['date']       ?? current_time( 'Y-m-d' );
        $brief_type = $snapshot['brief_type'] ?? 'morning';

        $brief_label = match( $brief_type ) {
            'morning'  => 'Morning Operations Brief',
            'evening'  => 'Evening Closure Brief',
            'accounts' => 'Accounts Snapshot',
            default    => 'Situational Awareness Brief',
        };

        $data_block = self::build_data_block( $snapshot );

        // Check for a per-brief-type custom prompt stored in WordPress options.
        $custom_raw = trim( OPB_Customizations::get( "sal_{$brief_type}_prompt" ) );

        if ( $custom_raw !== '' ) {
            // Replace the two supported placeholders in the custom instructions.
            $instructions = str_replace(
                [ '{brief_label}', '{date}' ],
                [ $brief_label,   $date    ],
                $custom_raw
            );
        } else {
            // Use the hardcoded default, replacing its placeholders.
            $instructions = str_replace(
                [ '{brief_label}', '{date}' ],
                [ $brief_label,   $date    ],
                self::get_default_prompt( $brief_type )
            );
        }

        // The data block is always appended — users cannot accidentally omit it.
        return $instructions . "\n\n---\nOPERATIONAL DATA ({$brief_label} · {$date})\n\n{$data_block}\n---";
    }

    /**
     * Convert snapshot into a readable plain-text block for the Gemini prompt.
     */
    private static function build_data_block( array $snapshot ): string {
        $brief_type = $snapshot['brief_type'] ?? 'morning';
        $totals     = $snapshot['totals']     ?? [];
        $branches   = $snapshot['branches']   ?? [];
        $op_start   = $snapshot['op_start']   ?? '';
        $lines      = [];

        // Operational window header (accounts brief only)
        if ( $brief_type === 'accounts' && $op_start ) {
            $lines[] = "OPERATIONAL WINDOW: Since {$op_start} (figures exclude pre-operational invoices)";
            $lines[] = '';
        }

        // Totals
        if ( isset( $totals['total_active'] ) ) {
            $lines[] = "TOTAL ACTIVE BOARDING: {$totals['total_active']}";
        }
        if ( isset( $totals['total_arrivals_today'] ) ) {
            $lines[] = "ARRIVALS TODAY: {$totals['total_arrivals_today']}";
        }
        if ( isset( $totals['total_departures_today'] ) ) {
            $lines[] = "DEPARTURES TODAY: {$totals['total_departures_today']}";
        }
        if ( isset( $totals['total_arrivals_tomorrow'] ) ) {
            $lines[] = "ARRIVALS TOMORROW: {$totals['total_arrivals_tomorrow']}";
        }
        if ( isset( $totals['total_departures_tomorrow'] ) ) {
            $lines[] = "DEPARTURES TOMORROW: {$totals['total_departures_tomorrow']}";
        }
        if ( isset( $totals['total_overstays'] ) ) {
            $lines[] = "OVERSTAYS: {$totals['total_overstays']}";
        }
        if ( isset( $totals['total_on_medication'] ) ) {
            $lines[] = "PETS ON MEDICATION: {$totals['total_on_medication']}";
        }
        if ( isset( $totals['total_special_care'] ) ) {
            $lines[] = "SPECIAL CARE PETS: {$totals['total_special_care']}";
        }
        if ( isset( $totals['total_open_tasks'] ) ) {
            $lines[] = "OPEN TASKS: {$totals['total_open_tasks']}";
        }
        if ( isset( $totals['total_overdue_tasks'] ) ) {
            $lines[] = "OVERDUE TASKS: {$totals['total_overdue_tasks']}";
        }
        if ( isset( $totals['total_unassigned_tasks'] ) ) {
            $lines[] = "UNASSIGNED TASKS: {$totals['total_unassigned_tasks']}";
        }
        if ( isset( $totals['total_unvaccinated'] ) ) {
            $lines[] = "UNVACCINATED PETS BOARDED: {$totals['total_unvaccinated']}";
        }
        if ( isset( $totals['total_arrived_today'] ) ) {
            $lines[] = "PETS CHECKED IN TODAY: {$totals['total_arrived_today']}";
        }
        if ( isset( $totals['total_departed_today'] ) ) {
            $lines[] = "PETS CHECKED OUT TODAY: {$totals['total_departed_today']}";
        }
        if ( isset( $totals['total_unpaid'] ) ) {
            $lines[] = "UNPAID INVOICES: {$totals['total_unpaid']}";
        }
        if ( isset( $totals['total_overdue'] ) ) {
            $lines[] = "OVERDUE INVOICES (>7 days): {$totals['total_overdue']}";
        }
        if ( isset( $totals['total_outstanding'] ) ) {
            $lines[] = 'TOTAL OUTSTANDING: ₹' . number_format( (float) $totals['total_outstanding'], 2 );
        }
        if ( isset( $totals['total_payments_today'] ) ) {
            $lines[] = 'PAYMENTS RECEIVED TODAY: ₹' . number_format( (float) $totals['total_payments_today'], 2 );
        }
        if ( isset( $totals['total_expenses_today'] ) ) {
            $lines[] = 'EXPENSES TODAY: ₹' . number_format( (float) $totals['total_expenses_today'], 2 );
        }
        if ( isset( $totals['total_partial'] ) ) {
            $lines[] = "PARTIAL PAYMENTS: {$totals['total_partial']}";
        }

        $lines[] = '';

        // Per-branch details
        foreach ( $branches as $branch ) {
            $bname = $branch['branch_name'] ?? 'Branch';
            $lines[] = "=== {$bname} ===";

            $boarding = $branch['boarding'] ?? null;
            if ( $boarding ) {
                $active = $boarding['active_count'];
                $cap    = $boarding['kennel_capacity'];
                $lines[] = "Active boarders: {$active}" . ( $cap > 0 ? " / {$cap} kennels" : '' );

                if ( ! empty( $boarding['arrivals_today'] ) ) {
                    $names = implode( ', ', array_column( $boarding['arrivals_today'], 'pet_name' ) );
                    $lines[] = "Arrivals today: " . count( $boarding['arrivals_today'] ) . " ({$names})";
                } else {
                    $lines[] = "Arrivals today: 0";
                }

                if ( ! empty( $boarding['departures_today'] ) ) {
                    $names = implode( ', ', array_column( $boarding['departures_today'], 'pet_name' ) );
                    $lines[] = "Departures today: " . count( $boarding['departures_today'] ) . " ({$names})";
                } else {
                    $lines[] = "Departures today: 0";
                }

                if ( ! empty( $boarding['arrivals_tomorrow'] ) ) {
                    $lines[] = "Arrivals tomorrow: " . count( $boarding['arrivals_tomorrow'] );
                }
                if ( ! empty( $boarding['departures_tomorrow'] ) ) {
                    $lines[] = "Departures tomorrow: " . count( $boarding['departures_tomorrow'] );
                }

                if ( ! empty( $boarding['overstays'] ) ) {
                    foreach ( $boarding['overstays'] as $o ) {
                        $lines[] = "OVERSTAY: {$o['pet_name']} ({$o['client_name']}) — expected out {$o['check_out_date']}";
                    }
                }

                if ( ! empty( $boarding['on_medication'] ) ) {
                    foreach ( $boarding['on_medication'] as $m ) {
                        $detail = $m['medication_detail'] ? mb_substr( $m['medication_detail'], 0, 80 ) : 'details on file';
                        $lines[] = "MEDICATION: {$m['pet_name']} — {$detail}";
                    }
                }

                if ( ! empty( $boarding['special_care'] ) ) {
                    foreach ( $boarding['special_care'] as $sc ) {
                        $note = $sc['preferences_or_allergies'] ?? $sc['major_illness_history'] ?? '';
                        $note = mb_substr( $note, 0, 80 );
                        $lines[] = "SPECIAL CARE: {$sc['pet_name']} — {$note}";
                    }
                }

                // Evening only
                if ( ! empty( $boarding['arrived_today_actual'] ) ) {
                    $lines[] = "Checked in today: " . count( $boarding['arrived_today_actual'] );
                }
                if ( ! empty( $boarding['departed_today_actual'] ) ) {
                    $lines[] = "Checked out today: " . count( $boarding['departed_today_actual'] );
                }
            }

            $tasks = $branch['tasks'] ?? null;
            if ( $tasks ) {
                $lines[] = "Open tasks: {$tasks['open_count']} | Overdue: {$tasks['overdue_count']} | Due today: {$tasks['due_today_count']} | Unassigned: {$tasks['unassigned_count']}";
                foreach ( $tasks['overdue_tasks'] as $t ) {
                    $due = $t['due_date'] ?? 'no date';
                    $lines[] = "OVERDUE TASK: {$t['title']} (due {$due}, {$t['priority']})";
                }
                foreach ( ( $tasks['due_today_tasks'] ?? [] ) as $t ) {
                    $lines[] = "DUE TODAY: {$t['title']} ({$t['priority']})";
                }
            }

            $exc = $branch['exceptions'] ?? null;
            if ( $exc ) {
                foreach ( $exc['unvaccinated_boarded'] as $u ) {
                    $lines[] = "UNVACCINATED BOARDED: {$u['pet_name']} ({$u['vaccination_status']}) — owner {$u['client_name']}";
                }
                foreach ( ( $exc['potential_no_shows'] ?? [] ) as $ns ) {
                    $lines[] = "POTENTIAL NO-SHOW: {$ns['pet_name']} — expected today, not checked in";
                }
            }

            $invoices = $branch['invoices'] ?? null;
            if ( $invoices ) {
                $lines[] = "Invoices generated today: {$invoices['generated_today']}";
                $lines[] = "Unpaid invoices: {$invoices['unpaid_count']} (₹" . number_format( $invoices['total_outstanding'], 2 ) . " outstanding)";
                $lines[] = "Overdue invoices (>7d): {$invoices['overdue_count']}";
                foreach ( array_slice( $invoices['overdue_invoices'] ?? [], 0, 5 ) as $inv ) {
                    $lines[] = "OVERDUE: #{$inv['id']} — {$inv['client_name']} — ₹" . number_format( (float) $inv['due'], 2 ) . " due since {$inv['invoice_date']}";
                }
            }

            $payments = $branch['payments'] ?? null;
            if ( $payments ) {
                $lines[] = "Payments received today: {$payments['received_today_count']} (₹" . number_format( $payments['received_today_total'], 2 ) . ")";
                $lines[] = "Partial payments pending: {$payments['partial_count']}";
            }

            $expenses = $branch['expenses'] ?? null;
            if ( $expenses ) {
                $lines[] = "Expenses today: {$expenses['today_count']} (₹" . number_format( $expenses['today_total'], 2 ) . ")";
                foreach ( array_slice( $expenses['today_list'] ?? [], 0, 5 ) as $exp ) {
                    $cat = $exp['category'] ? " [{$exp['category']}]" : '';
                    $lines[] = "EXPENSE: ₹" . number_format( (float) $exp['amount'], 2 ) . "{$cat} — {$exp['description']}";
                }
            }

            $lines[] = '';
        }

        return implode( "\n", $lines );
    }

    // ── Deterministic fallback ─────────────────────────────────────────────────

    /**
     * Generate a plain factual brief without Gemini.
     * Uses the same data — no AI involvement.
     */
    public static function deterministic_fallback( array $snapshot ): string {
        $date       = $snapshot['date']       ?? current_time( 'Y-m-d' );
        $brief_type = $snapshot['brief_type'] ?? 'morning';
        $totals     = $snapshot['totals']     ?? [];
        $branches   = $snapshot['branches']   ?? [];

        $label = match( $brief_type ) {
            'morning'  => 'Morning Operations Brief',
            'evening'  => 'Evening Closure Brief',
            'accounts' => 'Accounts Snapshot',
            default    => 'SAL Brief',
        };

        $esc = fn( string $s ) => htmlspecialchars( $s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' );

        $lines   = [];
        $lines[] = "🐾 <b>OPB {$esc($label)}</b>";
        $lines[] = "<i>{$esc($date)}</i>";
        $lines[] = '';

        if ( in_array( $brief_type, [ 'morning', 'evening' ], true ) ) {
            $lines[] = '<b>BOARDING</b>';
            foreach ( $branches as $b ) {
                $bn = $esc( $b['branch_name'] ?? 'Branch' );
                $bo = $b['boarding'] ?? [];
                $cap  = (int) ( $bo['kennel_capacity'] ?? 0 );
                $act  = (int) ( $bo['active_count'] ?? 0 );
                $capStr = $cap > 0 ? "/{$cap}" : '';
                $lines[] = "<b>{$bn}</b>: {$act}{$capStr} active · "
                         . count( $bo['arrivals_today'] ?? [] ) . " in · "
                         . count( $bo['departures_today'] ?? [] ) . " out today";
                if ( ! empty( $bo['overstays'] ) ) {
                    foreach ( $bo['overstays'] as $o ) {
                        $lines[] = "⚠️ Overstay: {$esc($o['pet_name'])} (was out {$esc($o['check_out_date'])})";
                    }
                }
            }
            $lines[] = '';

            // Medication
            $med_lines = [];
            foreach ( $branches as $b ) {
                foreach ( ( $b['boarding']['on_medication'] ?? [] ) as $m ) {
                    $detail = ! empty( $m['medication_detail'] )
                        ? ': ' . $esc( mb_substr( $m['medication_detail'], 0, 60 ) )
                        : '';
                    $med_lines[] = "• {$esc($m['pet_name'])} ({$esc($b['branch_name'])}){$detail}";
                }
            }
            if ( $med_lines ) {
                $lines[] = '<b>MEDICATION & SPECIAL CARE</b>';
                foreach ( $med_lines as $ml ) $lines[] = $ml;
                $lines[] = '';
            }

            // Tasks
            $lines[] = '<b>TASKS</b>';
            foreach ( $branches as $b ) {
                $bn   = $esc( $b['branch_name'] ?? 'Branch' );
                $t    = $b['tasks'] ?? [];
                $open = (int) ( $t['open_count'] ?? 0 );
                $ov   = (int) ( $t['overdue_count'] ?? 0 );
                $ua   = (int) ( $t['unassigned_count'] ?? 0 );
                $lines[] = "<b>{$bn}</b>: {$open} open · {$ov} overdue · {$ua} unassigned";
                foreach ( array_slice( $t['overdue_tasks'] ?? [], 0, 3 ) as $ot ) {
                    $lines[] = "⚠️ Overdue: {$esc($ot['title'])} (due {$esc($ot['due_date'])})";
                }
            }
            $lines[] = '';

            // Exceptions
            $exc_lines = [];
            foreach ( $branches as $b ) {
                $exc = $b['exceptions'] ?? [];
                foreach ( ( $exc['unvaccinated_boarded'] ?? [] ) as $u ) {
                    $exc_lines[] = "⚠️ Unvaccinated boarded: {$esc($u['pet_name'])} ({$esc($b['branch_name'])})";
                }
                foreach ( ( $exc['potential_no_shows'] ?? [] ) as $ns ) {
                    $exc_lines[] = "⚠️ Potential no-show: {$esc($ns['pet_name'])} ({$esc($b['branch_name'])})";
                }
            }
            if ( $exc_lines ) {
                $lines[] = '<b>OPERATIONAL EXCEPTIONS</b>';
                foreach ( $exc_lines as $el ) $lines[] = $el;
                $lines[] = '';
            }

            // Evening extras
            if ( $brief_type === 'evening' ) {
                $ci = (int) ( $totals['total_arrived_today']  ?? 0 );
                $co = (int) ( $totals['total_departed_today'] ?? 0 );
                $lines[] = "<b>DAY SUMMARY</b>";
                $lines[] = "Check-ins completed: {$ci} · Check-outs completed: {$co}";
                $lines[] = '';
            }
        }

        if ( $brief_type === 'accounts' ) {
            $op_start = $snapshot['op_start'] ?? '';
            $win_label = $op_start
                ? ' <i>(since ' . $esc( $op_start ) . ')</i>'
                : '';
            $lines[] = "<b>ACCOUNTS</b>{$win_label}";
            foreach ( $branches as $b ) {
                $bn  = $esc( $b['branch_name'] ?? 'Branch' );
                $inv = $b['invoices']  ?? [];
                $pay = $b['payments']  ?? [];
                $exp = $b['expenses']  ?? [];
                $lines[] = "<b>{$bn}</b>";
                $lines[] = "Payments today: ₹" . number_format( (float) ( $pay['received_today_total'] ?? 0 ), 2 );
                $lines[] = "Unpaid invoices: " . ( $inv['unpaid_count'] ?? 0 ) . " (₹" . number_format( (float) ( $inv['total_outstanding'] ?? 0 ), 2 ) . " outstanding)";
                $lines[] = "Overdue invoices (>7d): " . ( $inv['overdue_count'] ?? 0 );
                $lines[] = "Expenses today: ₹" . number_format( (float) ( $exp['today_total'] ?? 0 ), 2 );
                $lines[] = '';
            }
        }

        $lines[] = '<i>⚙️ OPB SAL · ' . $esc( current_time( 'H:i' ) ) . '</i>';

        return implode( "\n", $lines );
    }

    // ── Utility ────────────────────────────────────────────────────────────────

    /**
     * Strip markdown code fences then run the Telegram normalization layer.
     */
    private static function clean_gemini_output( string $text ): string {
        $text = preg_replace( '/^```(?:html)?\s*/i', '', trim( $text ) ) ?? $text;
        $text = preg_replace( '/\s*```$/i', '', $text ) ?? $text;
        return self::normalize_for_telegram( trim( $text ) );
    }

    // ── Telegram HTML normalization ─────────────────────────────────────────────

    /**
     * Converts Gemini output to the Telegram HTML subset.
     *
     * Telegram HTML parse_mode accepts ONLY: <b>, <strong>, <i>, <em>, <u>,
     * <s>, <code>, <pre>, <a>. Everything else causes a 400 Bad Request.
     *
     * Steps:
     *  1. Convert <li>…</li> → • bullet
     *  2. Remove <ul>, <ol> wrapper tags
     *  3. Convert <br> → newline
     *  4. Strip <p> tags, preserve their content
     *  5. strip_tags() keeping only Telegram-safe tags
     *  6. Collapse 3+ consecutive newlines → 2
     */
    private static function normalize_for_telegram( string $text ): string {
        // 1. Convert list items to bullet points (handle multiline content).
        $text = preg_replace_callback(
            '/<li[^>]*>(.*?)<\/li>/is',
            static function ( array $m ): string {
                $content = trim( strip_tags( $m[1], '<b><strong><i><em><u><s><code><pre><a>' ) );
                return "\n• " . $content;
            },
            $text
        ) ?? $text;

        // 2. Remove list wrapper tags (content is already extracted above).
        $text = preg_replace( '/<\/?(ul|ol)[^>]*>/i', '', $text ) ?? $text;

        // 3. Convert <br> to newline.
        $text = preg_replace( '/<br\s*\/?>/i', "\n", $text ) ?? $text;

        // 4. Strip <p> tags but keep their text content.
        $text = preg_replace( '/<\/p>/i', "\n", $text ) ?? $text;
        $text = preg_replace( '/<p[^>]*>/i', '', $text ) ?? $text;

        // 5. Strip all remaining unsupported HTML — keep only Telegram subset.
        $text = strip_tags( $text, '<b><strong><i><em><u><s><strike><del><code><pre><a>' );

        // 6. Collapse 3+ consecutive blank lines → 2.
        $text = preg_replace( '/\n{3,}/', "\n\n", $text ) ?? $text;

        return trim( $text );
    }

    // ── Length safeguard ───────────────────────────────────────────────────────

    /**
     * Ensure the message fits within Telegram's 4096-character limit.
     *
     * Truncates at a line boundary — never mid-sentence, never mid-tag.
     * Lower-priority trailing lines are removed first; a one-line notice
     * is appended so the reader knows the brief was shortened.
     *
     * @param  string $text  Message to guard.
     * @param  int    $limit Character budget (default 4096).
     * @return string
     */
    private static function safe_truncate( string $text, int $limit = 4096 ): string {
        if ( mb_strlen( $text ) <= $limit ) {
            return $text;
        }

        $notice = "\n<i>⚠️ Brief truncated — see OPB dashboard for full detail.</i>";
        $budget = $limit - mb_strlen( $notice );

        $lines = explode( "\n", $text );
        $out   = '';

        foreach ( $lines as $line ) {
            $candidate = $out === '' ? $line : $out . "\n" . $line;
            if ( mb_strlen( $candidate ) > $budget ) {
                break;
            }
            $out = $candidate;
        }

        error_log( '[OPB SAL] safe_truncate: message shortened from ' . mb_strlen( $text ) . ' to ' . mb_strlen( $out . $notice ) . ' chars.' );

        return $out . $notice;
    }
}
