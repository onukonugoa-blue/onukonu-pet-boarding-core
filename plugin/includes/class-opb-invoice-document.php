<?php
/**
 * OPB_Invoice_Document
 *
 * Generates, stores, and serves branded HTML invoice documents.
 * Handles email delivery and WhatsApp link generation.
 *
 * Storage  : wp-content/uploads/opb-invoices/{id}/invoice.html
 * Public   : /opb-invoice/{64-char-token}/   (no auth required)
 * Print PDF: /opb-invoice/{token}/?print=1   (triggers auto-print dialog)
 */
class OPB_Invoice_Document {

    const UPLOAD_SUBDIR = 'opb-invoices';

    // ── WordPress hooks ────────────────────────────────────────────────────────

    public static function register(): void {
        add_action( 'init',              [ self::class, 'add_rewrite_rules' ] );
        add_filter( 'query_vars',        [ self::class, 'add_query_vars'    ] );
        add_action( 'template_redirect', [ self::class, 'maybe_serve'       ] );
    }

    public static function add_rewrite_rules(): void {
        add_rewrite_rule(
            '^opb-invoice/([a-f0-9]{64})/?$',
            'index.php?opb_invoice=$matches[1]',
            'top'
        );
    }

    public static function add_query_vars( array $vars ): array {
        $vars[] = 'opb_invoice';
        return $vars;
    }

    public static function maybe_serve(): void {
        $token = get_query_var( 'opb_invoice' );
        if ( $token && preg_match( '/^[a-f0-9]{64}$/', $token ) ) {
            self::serve( $token );
            exit;
        }
    }

    // ── Document generation ────────────────────────────────────────────────────

    /**
     * Generate (or regenerate) the invoice HTML document.
     * Stores to disk and persists token + timestamp on the invoice row.
     *
     * @return array{ token: string, url: string, generated_at: string }
     * @throws RuntimeException on failure.
     */
    public static function generate( int $invoice_id ): array {
        global $wpdb;

        $data = self::get_invoice_data( $invoice_id );
        if ( ! $data ) {
            throw new \RuntimeException( 'Invoice not found.' );
        }

        $token = $data['invoice']['doc_token'] ?? null;
        if ( ! $token ) {
            $token = bin2hex( random_bytes( 32 ) );
        }

        $html = self::build_html( $data );

        $upload    = wp_upload_dir();
        $dir       = trailingslashit( $upload['basedir'] ) . self::UPLOAD_SUBDIR . '/' . $invoice_id;
        $file_path = $dir . '/invoice.html';

        if ( ! wp_mkdir_p( $dir ) ) {
            throw new \RuntimeException( 'Could not create invoice upload directory.' );
        }

        if ( false === file_put_contents( $file_path, $html ) ) { // phpcs:ignore
            throw new \RuntimeException( 'Could not write invoice file.' );
        }

        $generated_at = current_time( 'mysql' );
        $wpdb->update(
            "{$wpdb->prefix}opb_invoices",
            [ 'doc_token' => $token, 'doc_generated_at' => $generated_at ],
            [ 'id'        => $invoice_id ]
        );

        return [
            'token'        => $token,
            'url'          => self::get_public_url( $token ),
            'generated_at' => $generated_at,
        ];
    }

    /**
     * Return stored document metadata, or null if no document has been generated.
     *
     * @return array{ token: string, url: string, generated_at: string }|null
     */
    public static function get_info( int $invoice_id ): ?array {
        global $wpdb;

        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT doc_token, doc_generated_at
             FROM {$wpdb->prefix}opb_invoices
             WHERE id = %d",
            $invoice_id
        ), ARRAY_A );

        if ( ! $row || ! $row['doc_token'] ) {
            return null;
        }

        return [
            'token'        => $row['doc_token'],
            'url'          => self::get_public_url( $row['doc_token'] ),
            'generated_at' => $row['doc_generated_at'],
        ];
    }

    // ── Email delivery ─────────────────────────────────────────────────────────

    /**
     * Send the invoice via email.
     *
     * @param  string $to  Recipient; defaults to the client's email.
     * @return array{ sent: bool, to: string }
     * @throws RuntimeException if no valid address or invoice not found.
     */
    public static function send_email( int $invoice_id, string $to = '' ): array {
        $data = self::get_invoice_data( $invoice_id );
        if ( ! $data ) {
            throw new \RuntimeException( 'Invoice not found.' );
        }

        $inv       = $data['invoice'];
        $recipient = trim( $to ?: ( $inv['client_email'] ?? '' ) );

        if ( ! $recipient || ! is_email( $recipient ) ) {
            throw new \RuntimeException( 'No valid email address. Provide a recipient or add a client email.' );
        }

        $inv_num  = $inv['legacy_invoice_number'] ?? ( '#' . $inv['id'] );
        $doc_info = self::get_info( $invoice_id );
        $doc_url  = $doc_info['url'] ?? '';

        $context = [
            'CLIENT_NAME'    => $inv['client_name']  ?? '',
            'FACILITY_NAME'  => OPB_Customizations::facility_name(),
            'INVOICE_NUMBER' => $inv_num,
            'INVOICE_TOTAL'  => self::fmt_inr( (float) $inv['revenue'] ),
            'INVOICE_PAID'   => self::fmt_inr( (float) $inv['paid']    ),
            'INVOICE_DUE'    => self::fmt_inr( (float) $inv['due']     ),
            'INVOICE_LINK'   => $doc_url,
        ];

        $subject = OPB_Customizations::render( 'invoice_email_subject', $context );
        $intro   = OPB_Customizations::render( 'invoice_email_intro',   $context );
        $body    = self::build_email_html( $data, $intro, $doc_url );

        $sent = wp_mail( $recipient, $subject, $body, [ 'Content-Type: text/html; charset=UTF-8' ] );

        return [ 'sent' => (bool) $sent, 'to' => $recipient ];
    }

    // ── WhatsApp link ──────────────────────────────────────────────────────────

    /**
     * Build the WhatsApp sharing link for this invoice.
     *
     * @return array{ url: string, message: string, phone: string }
     * @throws RuntimeException if invoice not found.
     */
    public static function get_whatsapp_link( int $invoice_id ): array {
        $data = self::get_invoice_data( $invoice_id );
        if ( ! $data ) {
            throw new \RuntimeException( 'Invoice not found.' );
        }

        $inv      = $data['invoice'];
        $inv_num  = $inv['legacy_invoice_number'] ?? ( '#' . $inv['id'] );
        $doc_info = self::get_info( $invoice_id );
        $doc_url  = $doc_info['url'] ?? '';

        $context = [
            'CLIENT_NAME'    => $inv['client_name']  ?? '',
            'FACILITY_NAME'  => OPB_Customizations::facility_name(),
            'INVOICE_NUMBER' => $inv_num,
            'INVOICE_TOTAL'  => self::fmt_inr( (float) $inv['revenue'] ),
            'INVOICE_PAID'   => self::fmt_inr( (float) $inv['paid']    ),
            'INVOICE_DUE'    => self::fmt_inr( (float) $inv['due']     ),
            'INVOICE_LINK'   => $doc_url,
        ];

        $message = OPB_Customizations::render( 'invoice_whatsapp_message', $context );
        $phone   = preg_replace( '/\D/', '', $inv['client_phone'] ?? '' );
        $url     = $phone
            ? 'https://wa.me/' . $phone . '?text=' . rawurlencode( $message )
            : 'https://wa.me/?text=' . rawurlencode( $message );

        return [ 'url' => $url, 'message' => $message, 'phone' => $phone ];
    }

    // ── Public serve ───────────────────────────────────────────────────────────

    private static function serve( string $token ): void {
        global $wpdb;

        $invoice_id = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}opb_invoices WHERE doc_token = %s",
            $token
        ) );

        if ( ! $invoice_id ) {
            wp_die( 'Invoice not found or link has expired.', 'Not Found', [ 'response' => 404 ] );
        }

        $data = self::get_invoice_data( $invoice_id );
        if ( ! $data ) {
            wp_die( 'Invoice data could not be loaded.', 'Error', [ 'response' => 500 ] );
        }

        header( 'Content-Type: text/html; charset=UTF-8' );
        header( 'X-Robots-Tag: noindex' );
        header( 'Cache-Control: no-store' );

        echo self::build_html( $data ); // phpcs:ignore WordPress.Security.EscapeOutput
    }

    // ── Data layer ─────────────────────────────────────────────────────────────

    private static function get_invoice_data( int $invoice_id ): ?array {
        global $wpdb;

        $invoice = $wpdb->get_row( $wpdb->prepare(
            "SELECT i.*,
                    c.name     AS client_name,
                    c.phone    AS client_phone,
                    c.email    AS client_email,
                    br.name    AS branch_name,
                    br.address AS branch_address,
                    br.phone   AS branch_phone
             FROM {$wpdb->prefix}opb_invoices  i
             JOIN {$wpdb->prefix}opb_bookings  bk ON bk.id  = i.booking_id
             JOIN {$wpdb->prefix}opb_clients   c  ON c.id   = bk.client_id
             JOIN {$wpdb->prefix}opb_branches  br ON br.id  = i.branch_id
             WHERE i.id = %d",
            $invoice_id
        ), ARRAY_A );

        if ( ! $invoice ) {
            return null;
        }

        $line_items = $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}opb_invoice_line_items
             WHERE invoice_id = %d ORDER BY id ASC",
            $invoice_id
        ), ARRAY_A );

        $payments = $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}opb_payments
             WHERE invoice_id = %d ORDER BY paid_at ASC",
            $invoice_id
        ), ARRAY_A );

        $pets = $wpdb->get_results( $wpdb->prepare(
            "SELECT DISTINCT p.name AS pet_name, p.breed, p.pet_type,
                    bs.check_in_date, bs.check_out_date, bs.boarding_type
             FROM {$wpdb->prefix}opb_booking_stays bs
             JOIN {$wpdb->prefix}opb_pets p ON p.id = bs.pet_id
             WHERE bs.booking_id = %d
             ORDER BY p.name ASC",
            $invoice['booking_id']
        ), ARRAY_A );

        return compact( 'invoice', 'line_items', 'payments', 'pets' );
    }

    // ── HTML invoice builder ───────────────────────────────────────────────────

    private static function build_html( array $data ): string {
        $inv        = $data['invoice'];
        $line_items = $data['line_items'];
        $payments   = $data['payments'];
        $pets       = $data['pets'];

        $facility         = OPB_Customizations::facility_name();
        $facility_phone   = OPB_Customizations::get( 'facility_phone' );
        $facility_email   = OPB_Customizations::get( 'facility_email' );
        $facility_website = OPB_Customizations::get( 'facility_website' );

        $inv_num      = $inv['legacy_invoice_number'] ?? ( '#' . $inv['id'] );
        $inv_date_fmt = self::fmt_date( $inv['invoice_date'] );
        $generated_on = wp_date( 'd M Y, g:i A' );

        $status_map = [
            'Paid'           => [ '#276749', '#f0fff4', '#9ae6b4' ],
            'Partially paid' => [ '#c05621', '#fffaf0', '#f6ad55' ],
            'Unpaid'         => [ '#c53030', '#fff5f5', '#fc8181' ],
            'Overpaid'       => [ '#2b6cb0', '#ebf8ff', '#90cdf4' ],
            'No bill'        => [ '#718096', '#f7fafc', '#e2e8f0' ],
        ];
        [ $s_color, $s_bg, $s_border ] = $status_map[ $inv['payment_status'] ] ?? [ '#718096', '#f7fafc', '#e2e8f0' ];

        $auto_print_js = ( isset( $_GET['print'] ) && '1' === $_GET['print'] ) // phpcs:ignore
            ? '<script>window.onload=function(){window.print();}</script>' : '';

        // ── Facility contact ───────────────────────────────────────────────────
        $contact_parts = array_filter( [ $facility_phone, $facility_email, $facility_website ] );
        $contact_line  = $contact_parts
            ? '<div style="font-size:12px;color:#90cdf4;margin-top:4px">' . esc_html( implode( '  ·  ', $contact_parts ) ) . '</div>'
            : '';

        // ── Branch address ─────────────────────────────────────────────────────
        $branch_addr_raw = trim( (string) ( $inv['branch_address'] ?? '' ) );
        $branch_addr_html = $branch_addr_raw
            ? '<div style="font-size:12px;color:#718096;margin-top:3px">' . nl2br( esc_html( $branch_addr_raw ) ) . '</div>'
            : '';

        // ── Pets ───────────────────────────────────────────────────────────────
        $pets_band_html = '';
        $stay_band_html = '';
        if ( $pets ) {
            $pet_strs = array_map( function ( $p ) {
                $s = esc_html( $p['pet_name'] );
                if ( $p['breed'] ) {
                    $s .= ' <span style="color:#718096;font-size:12px">(' . esc_html( $p['breed'] ) . ')</span>';
                }
                return $s;
            }, $pets );
            $pets_band_html =
                '<div style="background:#f7fafc;border:1px solid #e2e8f0;border-top:none;padding:10px 28px;font-size:13px;color:#4a5568">'
                . '<span style="font-size:10px;font-weight:700;letter-spacing:.8px;text-transform:uppercase;color:#a0aec0;margin-right:8px">Pets</span>'
                . implode( ' &nbsp;·&nbsp; ', $pet_strs )
                . '</div>';

            if ( ! empty( $pets[0]['check_in_date'] ) ) {
                $ci = self::fmt_date( $pets[0]['check_in_date'] );
                $co = self::fmt_date( $pets[0]['check_out_date'] );
                $stay_band_html =
                    '<div style="background:#f7fafc;border:1px solid #e2e8f0;border-top:none;padding:8px 28px;font-size:12px;color:#718096">'
                    . '<span style="font-size:10px;font-weight:700;letter-spacing:.8px;text-transform:uppercase;color:#a0aec0;margin-right:8px">Stay</span>'
                    . esc_html( $ci ) . ' &nbsp;→&nbsp; ' . esc_html( $co )
                    . '</div>';
            }
        }

        // ── Line items ─────────────────────────────────────────────────────────
        $sec_colors = [
            'Base'       => [ '#ebf4ff', '#2b6cb0' ],
            'Add-on'     => [ '#f0fff4', '#276749' ],
            'Discount'   => [ '#fff5f5', '#c53030' ],
            'Additional' => [ '#fffaf0', '#c05621' ],
        ];
        $line_rows_html = '';
        foreach ( $line_items as $li ) {
            [ $bg, $fg ] = $sec_colors[ $li['bill_section'] ] ?? [ '#f7fafc', '#718096' ];
            $row_style   = $li['is_return'] ? ' style="color:#c53030"' : '';
            $badge       = '<span style="background:' . esc_attr( $bg ) . ';color:' . esc_attr( $fg ) . ';padding:2px 8px;border-radius:10px;font-size:11px;font-weight:600">' . esc_html( $li['bill_section'] ) . '</span>';
            $line_rows_html .= sprintf(
                '<tr%s><td style="padding:9px 10px;border-bottom:1px solid #f1f5f9;vertical-align:top">%s</td>'
                . '<td style="padding:9px 10px;border-bottom:1px solid #f1f5f9;color:#4a5568">%s</td>'
                . '<td style="padding:9px 10px;border-bottom:1px solid #f1f5f9;text-align:right;color:#4a5568">%s</td>'
                . '<td style="padding:9px 10px;border-bottom:1px solid #f1f5f9;text-align:right;color:#4a5568">%s</td>'
                . '<td style="padding:9px 10px;border-bottom:1px solid #f1f5f9;text-align:right;font-weight:700;color:#1a202c">%s</td></tr>',
                $row_style,
                $badge,
                esc_html( $li['bill_item_name'] ?? '' ),
                esc_html( $li['quantity'] ),
                self::fmt_inr( (float) $li['amount'] ),
                self::fmt_inr( (float) $li['total']  )
            );
        }
        if ( ! $line_rows_html ) {
            $line_rows_html = '<tr><td colspan="5" style="padding:20px;text-align:center;color:#a0aec0">No line items recorded</td></tr>';
        }

        // ── Summary ────────────────────────────────────────────────────────────
        $base    = (float) $inv['base_amount'];
        $addon   = (float) $inv['addon_amount'];
        $disc    = (float) $inv['discount_amount'];
        $addl    = (float) $inv['additional_amount'];
        $revenue = (float) $inv['revenue'];
        $paid    = (float) $inv['paid'];
        $due     = (float) $inv['due'];

        $td_l = 'style="padding:5px 6px;font-size:13px;color:#4a5568;white-space:nowrap"';
        $td_r = 'style="padding:5px 6px;font-size:13px;text-align:right;font-weight:500;color:#1a202c;white-space:nowrap"';

        $sum_html  = '<tr><td ' . $td_l . '>Base Amount</td><td ' . $td_r . '>' . self::fmt_inr( $base ) . '</td></tr>';
        if ( $addon > 0 ) $sum_html .= '<tr><td ' . $td_l . '>Add-on Amount</td><td ' . $td_r . '>' . self::fmt_inr( $addon ) . '</td></tr>';
        if ( $disc  > 0 ) $sum_html .= '<tr><td ' . $td_l . '>Discount</td><td style="padding:5px 6px;font-size:13px;text-align:right;font-weight:500;color:#c53030;white-space:nowrap">&minus; ' . self::fmt_inr( $disc ) . '</td></tr>';
        if ( $addl  > 0 ) $sum_html .= '<tr><td ' . $td_l . '>Additional</td><td ' . $td_r . '>' . self::fmt_inr( $addl ) . '</td></tr>';
        $sum_html .= '<tr><td colspan="2" style="padding:4px 0"><div style="height:1px;background:#e2e8f0"></div></td></tr>';
        $sum_html .= '<tr><td style="padding:8px 6px;font-size:15px;font-weight:800;color:#1a202c">Total</td><td style="padding:8px 6px;font-size:15px;font-weight:800;color:#1a202c;text-align:right">' . self::fmt_inr( $revenue ) . '</td></tr>';
        if ( $paid > 0 ) $sum_html .= '<tr><td style="padding:4px 6px;font-size:13px;color:#276749">Paid</td><td style="padding:4px 6px;font-size:13px;text-align:right;font-weight:600;color:#276749">' . self::fmt_inr( $paid ) . '</td></tr>';
        if ( $due  > 0 ) $sum_html .= '<tr><td style="padding:4px 6px;font-size:14px;font-weight:700;color:#c53030">Balance Due</td><td style="padding:4px 6px;font-size:14px;text-align:right;font-weight:800;color:#c53030">' . self::fmt_inr( $due ) . '</td></tr>';
        if ( $due <= 0 && $paid > 0 ) $sum_html .= '<tr><td colspan="2" style="padding:4px 6px;font-size:12px;font-weight:700;color:#276749;text-align:right">✓ Fully Settled</td></tr>';

        // ── Payment history ────────────────────────────────────────────────────
        $pay_section_html = '';
        if ( $payments ) {
            $pay_rows = '';
            foreach ( $payments as $p ) {
                $pay_rows .= sprintf(
                    '<tr><td style="padding:8px 10px;border-bottom:1px solid #f1f5f9;font-size:13px;color:#4a5568">%s</td>'
                    . '<td style="padding:8px 10px;border-bottom:1px solid #f1f5f9;font-size:13px;color:#4a5568">%s</td>'
                    . '<td style="padding:8px 10px;border-bottom:1px solid #f1f5f9;font-size:13px;font-weight:600;color:#276749;text-align:right">%s</td>'
                    . '<td style="padding:8px 10px;border-bottom:1px solid #f1f5f9;font-size:12px;color:#a0aec0">%s</td></tr>',
                    esc_html( self::fmt_datetime( $p['paid_at'] ) ),
                    esc_html( $p['mode'] ),
                    self::fmt_inr( (float) $p['amount'] ),
                    esc_html( $p['transaction_id'] ?? '—' )
                );
            }
            $pay_section_html =
                '<div style="font-size:11px;font-weight:700;letter-spacing:.8px;text-transform:uppercase;color:#718096;padding:20px 0 8px;border-bottom:1px solid #e2e8f0">Payment History</div>'
                . '<table width="100%" style="border-collapse:collapse;margin-top:0">'
                . '<thead><tr>'
                . '<th style="padding:8px 10px;background:#f7fafc;text-align:left;font-size:11px;font-weight:700;letter-spacing:.5px;text-transform:uppercase;color:#718096;border-bottom:2px solid #e2e8f0">Date</th>'
                . '<th style="padding:8px 10px;background:#f7fafc;text-align:left;font-size:11px;font-weight:700;letter-spacing:.5px;text-transform:uppercase;color:#718096;border-bottom:2px solid #e2e8f0">Mode</th>'
                . '<th style="padding:8px 10px;background:#f7fafc;text-align:right;font-size:11px;font-weight:700;letter-spacing:.5px;text-transform:uppercase;color:#718096;border-bottom:2px solid #e2e8f0">Amount</th>'
                . '<th style="padding:8px 10px;background:#f7fafc;text-align:left;font-size:11px;font-weight:700;letter-spacing:.5px;text-transform:uppercase;color:#718096;border-bottom:2px solid #e2e8f0">Txn ID</th>'
                . '</tr></thead>'
                . '<tbody>' . $pay_rows . '</tbody></table>';
        }

        // ── Footer notes ───────────────────────────────────────────────────────
        $payment_note = OPB_Customizations::render( 'invoice_payment_note', [ 'FACILITY_NAME' => $facility ] );
        $footer_note  = OPB_Customizations::render( 'invoice_footer_note',  [ 'FACILITY_NAME' => $facility ] );
        $footer_html  = '';
        if ( $payment_note ) {
            $footer_html .= '<p style="font-size:12px;color:#718096;margin-bottom:6px;line-height:1.6">' . nl2br( esc_html( $payment_note ) ) . '</p>';
        }
        if ( $footer_note ) {
            $footer_html .= '<p style="font-size:13px;color:#4a5568;font-weight:500;margin-bottom:10px">' . nl2br( esc_html( $footer_note ) ) . '</p>';
        }

        // ── Assemble ───────────────────────────────────────────────────────────
        $h_facility  = esc_html( $facility );
        $h_inv_num   = esc_html( $inv_num );
        $h_inv_date  = esc_html( $inv_date_fmt );
        $h_status    = esc_html( $inv['payment_status'] );
        $h_c_name    = esc_html( $inv['client_name']   ?? '' );
        $h_c_phone   = esc_html( $inv['client_phone']  ?? '' );
        $h_c_email   = esc_html( $inv['client_email']  ?? '' );
        $h_br_name   = esc_html( $inv['branch_name']   ?? '' );
        $h_gen_on    = esc_html( $generated_on );
        $c_email_row = $h_c_email ? '<div style="font-size:12px;color:#718096;margin-top:2px">' . $h_c_email . '</div>' : '';

        $html  = '<!DOCTYPE html><html lang="en"><head>';
        $html .= '<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1.0">';
        $html .= '<title>Invoice ' . $h_inv_num . ' &mdash; ' . $h_facility . '</title>';
        $html .= '<style>';
        $html .= '*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}';
        $html .= 'body{font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Helvetica,Arial,sans-serif;background:#f0f4f8;color:#2d3748;font-size:14px;line-height:1.5}';
        $html .= '.action-bar{background:#fff;border-bottom:1px solid #e2e8f0;padding:12px 20px;display:flex;align-items:center;justify-content:space-between;gap:12px;position:sticky;top:0;z-index:10;box-shadow:0 1px 4px rgba(0,0,0,.06)}';
        $html .= '.btn-print{background:#1e3a5f;color:#fff;border:none;border-radius:7px;padding:9px 18px;font-size:13px;font-weight:600;cursor:pointer;transition:background .15s}';
        $html .= '.btn-print:hover{background:#2d5a8e}';
        $html .= '.page{max-width:800px;margin:28px auto;padding:0 16px 60px}';
        $html .= '@media(max-width:540px){.inv-grid{grid-template-columns:1fr!important}.inv-right{text-align:left!important}.page{padding:0 8px 40px}}';
        $html .= '@media print{';
        $html .=   '.action-bar{display:none!important}';
        $html .=   'body{background:#fff}';
        $html .=   '.page{margin:0;padding:0;max-width:100%}';
        $html .=   '.inv-top{border-radius:0!important;-webkit-print-color-adjust:exact;print-color-adjust:exact}';
        $html .=   '@page{margin:15mm 15mm 20mm}';
        $html .= '}';
        $html .= '</style>';
        $html .= '</head><body>';
        $html .= $auto_print_js;

        // Action bar
        $html .= '<div class="action-bar">';
        $html .=   '<div>';
        $html .=     '<div style="font-weight:700;color:#1e3a5f;font-size:15px">🐾 ' . $h_facility . '</div>';
        $html .=     '<div style="font-size:12px;color:#718096;margin-top:1px">Invoice ' . $h_inv_num . ' &nbsp;·&nbsp; ' . $h_inv_date . '</div>';
        $html .=   '</div>';
        $html .=   '<div style="text-align:right">';
        $html .=     '<button class="btn-print" onclick="window.print()">&#8595; Download / Print PDF</button>';
        $html .=     '<div style="font-size:11px;color:#a0aec0;margin-top:3px">Select "Save as PDF" in the print dialog</div>';
        $html .=   '</div>';
        $html .= '</div>';

        // Page wrapper
        $html .= '<div class="page">';

        // Invoice header
        $html .= '<div class="inv-top" style="background:#1e3a5f;color:#fff;border-radius:10px 10px 0 0;padding:24px 28px">';
        $html .=   '<div class="inv-grid" style="display:grid;grid-template-columns:1fr auto;gap:16px;align-items:start">';
        $html .=     '<div>';
        $html .=       '<div style="font-size:20px;font-weight:800;letter-spacing:-.3px;margin-bottom:3px">' . $h_facility . '</div>';
        $html .=       $contact_line;
        $html .=     '</div>';
        $html .=     '<div class="inv-right" style="text-align:right">';
        $html .=       '<div style="font-size:10px;font-weight:700;letter-spacing:1.5px;color:#90cdf4;text-transform:uppercase">Invoice</div>';
        $html .=       '<div style="font-size:22px;font-weight:800;margin:2px 0">' . $h_inv_num . '</div>';
        $html .=       '<div style="font-size:12px;color:#90cdf4;margin-bottom:8px">' . $h_inv_date . '</div>';
        $html .=       '<span style="display:inline-block;padding:4px 12px;border-radius:20px;font-size:11px;font-weight:700;border:1px solid ' . esc_attr( $s_border ) . ';background:' . esc_attr( $s_bg ) . ';color:' . esc_attr( $s_color ) . '">' . $h_status . '</span>';
        $html .=     '</div>';
        $html .=   '</div>';
        $html .= '</div>';

        // Bill to + branch info
        $html .= '<div style="background:#fff;border:1px solid #e2e8f0;border-top:none;padding:20px 28px">';
        $html .=   '<div class="inv-grid" style="display:grid;grid-template-columns:1fr 1fr;gap:20px">';
        $html .=     '<div>';
        $html .=       '<div style="font-size:10px;font-weight:700;letter-spacing:1px;color:#a0aec0;text-transform:uppercase;margin-bottom:6px">Billed To</div>';
        $html .=       '<div style="font-size:15px;font-weight:700;color:#1a202c;margin-bottom:2px">' . $h_c_name . '</div>';
        $html .=       '<div style="font-size:12px;color:#718096">' . $h_c_phone . '</div>';
        $html .=       $c_email_row;
        $html .=     '</div>';
        $html .=     '<div>';
        $html .=       '<div style="font-size:10px;font-weight:700;letter-spacing:1px;color:#a0aec0;text-transform:uppercase;margin-bottom:6px">Branch</div>';
        $html .=       '<div style="font-size:15px;font-weight:700;color:#1a202c;margin-bottom:2px">' . $h_br_name . '</div>';
        $html .=       $branch_addr_html;
        $html .=     '</div>';
        $html .=   '</div>';
        $html .= '</div>';

        $html .= $pets_band_html;
        $html .= $stay_band_html;

        // Body
        $html .= '<div style="background:#fff;border:1px solid #e2e8f0;border-top:none;border-radius:0 0 10px 10px;padding:0 28px 28px">';

        // Line items
        $html .= '<div style="font-size:11px;font-weight:700;letter-spacing:.8px;text-transform:uppercase;color:#718096;padding:20px 0 8px;border-bottom:1px solid #e2e8f0">Line Items</div>';
        $html .= '<table width="100%" style="border-collapse:collapse">';
        $html .= '<thead><tr>';
        $th = 'style="padding:9px 10px;background:#f7fafc;text-align:left;font-size:11px;font-weight:700;letter-spacing:.5px;text-transform:uppercase;color:#718096;border-bottom:2px solid #e2e8f0"';
        $html .= '<th ' . $th . ' width="90">Section</th>';
        $html .= '<th ' . $th . '>Description</th>';
        $html .= '<th ' . $th . ' style="padding:9px 10px;background:#f7fafc;text-align:right;font-size:11px;font-weight:700;letter-spacing:.5px;text-transform:uppercase;color:#718096;border-bottom:2px solid #e2e8f0;width:50px">Qty</th>';
        $html .= '<th ' . $th . ' style="padding:9px 10px;background:#f7fafc;text-align:right;font-size:11px;font-weight:700;letter-spacing:.5px;text-transform:uppercase;color:#718096;border-bottom:2px solid #e2e8f0;width:90px">Rate</th>';
        $html .= '<th ' . $th . ' style="padding:9px 10px;background:#f7fafc;text-align:right;font-size:11px;font-weight:700;letter-spacing:.5px;text-transform:uppercase;color:#718096;border-bottom:2px solid #e2e8f0;width:100px">Total</th>';
        $html .= '</tr></thead>';
        $html .= '<tbody>' . $line_rows_html . '</tbody>';
        $html .= '</table>';

        // Summary (right-aligned)
        $html .= '<div style="display:flex;justify-content:flex-end;margin-top:20px">';
        $html .=   '<table style="width:280px;border-collapse:collapse">';
        $html .=     '<tbody>' . $sum_html . '</tbody>';
        $html .=   '</table>';
        $html .= '</div>';

        // Payment history
        if ( $pay_section_html ) {
            $html .= '<div style="margin-top:20px">' . $pay_section_html . '</div>';
        }

        // Footer
        $html .= '<div style="margin-top:24px;padding-top:16px;border-top:1px solid #e2e8f0">';
        $html .= $footer_html;
        $html .= '<div style="font-size:10px;color:#a0aec0;margin-top:8px">Generated on ' . $h_gen_on . ' &nbsp;·&nbsp; ' . $h_facility . '</div>';
        $html .= '</div>';

        $html .= '</div>'; // body
        $html .= '</div>'; // page
        $html .= '</body></html>';

        return $html;
    }

    // ── Email HTML builder ─────────────────────────────────────────────────────

    private static function build_email_html( array $data, string $intro, string $doc_url ): string {
        $inv     = $data['invoice'];
        $facility = OPB_Customizations::facility_name();
        $inv_num  = $inv['legacy_invoice_number'] ?? ( '#' . $inv['id'] );
        $inv_date = self::fmt_date( $inv['invoice_date'] );
        $revenue  = (float) $inv['revenue'];
        $paid     = (float) $inv['paid'];
        $due      = (float) $inv['due'];

        $status_color = match( $inv['payment_status'] ) {
            'Paid'           => '#276749',
            'Partially paid' => '#c05621',
            'Unpaid'         => '#c53030',
            default          => '#718096',
        };

        $view_btn = $doc_url
            ? '<div style="text-align:center;margin:28px 0"><a href="' . esc_url( $doc_url ) . '" style="background:#1e3a5f;color:#fff;padding:13px 32px;border-radius:8px;text-decoration:none;font-weight:700;font-size:14px;display:inline-block">View Invoice &rarr;</a></div>'
            : '';

        $footer_note = OPB_Customizations::render( 'invoice_footer_note', [ 'FACILITY_NAME' => $facility ] );
        $intro_html  = nl2br( esc_html( $intro ) );
        $h_facility  = esc_html( $facility );
        $h_inv_num   = esc_html( $inv_num );
        $h_inv_date  = esc_html( $inv_date );

        $td_lbl = 'style="padding:10px 16px;background:#f7fafc;font-weight:600;font-size:13px;color:#1a202c;border-top:1px solid #e2e8f0"';
        $td_val = 'style="padding:10px 16px;text-align:right;font-size:13px;color:#4a5568;border-top:1px solid #e2e8f0"';

        $html  = '<!DOCTYPE html><html><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1.0"></head>';
        $html .= '<body style="margin:0;padding:0;background:#f0f4f8;font-family:-apple-system,BlinkMacSystemFont,\'Segoe UI\',Roboto,Arial,sans-serif;color:#2d3748">';
        $html .= '<div style="max-width:600px;margin:24px auto;background:#fff;border-radius:10px;overflow:hidden;box-shadow:0 2px 8px rgba(0,0,0,.08)">';

        // Header
        $html .= '<div style="background:#1e3a5f;color:#fff;padding:24px 28px">';
        $html .=   '<div style="font-size:19px;font-weight:800;margin-bottom:4px">🐾 ' . $h_facility . '</div>';
        $html .=   '<div style="font-size:13px;color:#90cdf4">Invoice ' . $h_inv_num . ' &nbsp;·&nbsp; ' . $h_inv_date . '</div>';
        $html .= '</div>';

        // Body
        $html .= '<div style="padding:24px 28px">';
        $html .=   '<p style="font-size:14px;color:#4a5568;line-height:1.7;margin-bottom:20px">' . $intro_html . '</p>';

        // Summary table
        $html .= '<table width="100%" cellspacing="0" cellpadding="0" style="border:1px solid #e2e8f0;border-radius:8px;overflow:hidden;font-size:14px;margin-bottom:20px">';
        $html .= '<tr><td style="padding:10px 16px;background:#f7fafc;font-weight:600;font-size:13px;color:#1a202c">Invoice #</td><td style="padding:10px 16px;text-align:right;font-size:13px;color:#4a5568">' . $h_inv_num . '</td></tr>';
        $html .= '<tr><td ' . $td_lbl . '>Date</td><td ' . $td_val . '>' . $h_inv_date . '</td></tr>';
        $html .= '<tr><td ' . $td_lbl . '>Status</td><td style="padding:10px 16px;text-align:right;font-size:13px;font-weight:700;color:' . esc_attr( $status_color ) . ';border-top:1px solid #e2e8f0">' . esc_html( $inv['payment_status'] ) . '</td></tr>';
        $html .= '<tr><td ' . $td_lbl . '>Total</td><td style="padding:10px 16px;text-align:right;font-size:13px;font-weight:700;color:#1a202c;border-top:1px solid #e2e8f0">' . self::fmt_inr( $revenue ) . '</td></tr>';
        if ( $paid > 0 ) {
            $html .= '<tr><td ' . $td_lbl . '>Paid</td><td style="padding:10px 16px;text-align:right;font-size:13px;font-weight:600;color:#276749;border-top:1px solid #e2e8f0">' . self::fmt_inr( $paid ) . '</td></tr>';
        }
        if ( $due > 0 ) {
            $html .= '<tr><td ' . $td_lbl . '>Balance Due</td><td style="padding:10px 16px;text-align:right;font-size:14px;font-weight:800;color:#c53030;border-top:1px solid #e2e8f0">' . self::fmt_inr( $due ) . '</td></tr>';
        }
        $html .= '</table>';

        $html .= $view_btn;

        if ( $footer_note ) {
            $html .= '<p style="font-size:13px;color:#4a5568;line-height:1.6;margin-top:16px">' . nl2br( esc_html( $footer_note ) ) . '</p>';
        }

        $html .= '<div style="margin-top:20px;padding-top:16px;border-top:1px solid #e2e8f0;font-size:11px;color:#a0aec0">' . $h_facility . ' &nbsp;·&nbsp; Sent via pet management system</div>';
        $html .= '</div>'; // body padding
        $html .= '</div>'; // wrapper
        $html .= '</body></html>';

        return $html;
    }

    // ── Helpers ────────────────────────────────────────────────────────────────

    private static function fmt_inr( float $amount ): string {
        return '&#8377;' . number_format( $amount, 2 );
    }

    private static function fmt_date( string $date ): string {
        if ( ! $date ) return '';
        $ts = strtotime( $date );
        return $ts ? date( 'd M Y', $ts ) : $date;
    }

    private static function fmt_datetime( string $dt ): string {
        if ( ! $dt ) return '';
        $ts = strtotime( $dt );
        return $ts ? date( 'd M Y, g:i A', $ts ) : $dt;
    }

    private static function summary_row( string $label, string $value, string $class = '' ): string {
        return sprintf(
            '<tr class="%s"><td style="padding:5px 6px;font-size:13px;color:#4a5568">%s</td><td style="padding:5px 6px;text-align:right;font-size:13px;color:#1a202c;font-weight:500">%s</td></tr>',
            esc_attr( $class ),
            esc_html( $label ),
            $value
        );
    }

    private static function get_public_url( string $token ): string {
        return home_url( '/opb-invoice/' . $token . '/' );
    }
}
