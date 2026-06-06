<?php
/**
 * OPB_Invoice_Document — v2.0
 *
 * PDF invoice engine: generates, stores, and serves branded PDF invoice documents.
 * Handles email delivery (PDF attached) and WhatsApp link generation.
 * Maintains a full audit trail.
 *
 * Storage  : wp-content/uploads/opb-invoices/{id}/invoice.html  (legacy HTML preview)
 *          : wp-content/uploads/opb-invoices/{id}/invoice.pdf   (primary authoritative document)
 * Public   : /opb-invoice/{64-char-token}/   (summary page + PDF download; no auth required)
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
     * Generate (or regenerate) the invoice PDF document.
     * Also writes an HTML preview file for legacy compatibility.
     * Persists token, pdf_path, generated_at, generated_by on the invoice row.
     *
     * @return array{ token: string, url: string, pdf_url: string|null, generated_at: string }
     * @throws RuntimeException on failure.
     */
    public static function generate( int $invoice_id ): array {
        global $wpdb;

        $data = self::get_invoice_data( $invoice_id );
        if ( ! $data ) {
            throw new \RuntimeException( 'Invoice not found.' );
        }

        $existing = $wpdb->get_row( $wpdb->prepare(
            "SELECT doc_token, doc_pdf_path FROM {$wpdb->prefix}opb_invoices WHERE id = %d",
            $invoice_id
        ), ARRAY_A );

        $upload   = wp_upload_dir();
        $dir      = trailingslashit( $upload['basedir'] ) . self::UPLOAD_SUBDIR . '/' . $invoice_id;
        $is_regen = ! empty( $existing['doc_pdf_path'] )
            && file_exists( $dir . '/invoice.pdf' );

        $token = ! empty( $existing['doc_token'] ) ? $existing['doc_token'] : bin2hex( random_bytes( 32 ) );

        if ( ! wp_mkdir_p( $dir ) ) {
            throw new \RuntimeException( 'Could not create invoice upload directory.' );
        }

        // HTML preview (legacy / fallback)
        if ( false === file_put_contents( $dir . '/invoice.html', self::build_html( $data ) ) ) { // phpcs:ignore
            throw new \RuntimeException( 'Could not write invoice HTML file.' );
        }

        // PDF — primary document
        $pdf_rel_path = null;
        try {
            self::generate_pdf( $invoice_id, $data, $dir );
            $pdf_rel_path = self::UPLOAD_SUBDIR . '/' . $invoice_id . '/invoice.pdf';
        } catch ( \Throwable $e ) {
            throw new \RuntimeException( 'PDF generation failed: ' . $e->getMessage() );
        }

        $generated_at = current_time( 'mysql' );
        $user_id      = get_current_user_id() ?: null;

        $updated = $wpdb->update(
            "{$wpdb->prefix}opb_invoices",
            [
                'doc_token'        => $token,
                'doc_generated_at' => $generated_at,
                'doc_generated_by' => $user_id ?? 0,
                'doc_pdf_path'     => $pdf_rel_path,
            ],
            [ 'id' => $invoice_id ],
            [ '%s', '%s', '%d', '%s' ],
            [ '%d' ]
        );

        if ( $updated === false ) {
            throw new \RuntimeException(
                'Failed to persist invoice document metadata.'
                . ( $wpdb->last_error ? ' DB: ' . $wpdb->last_error : '' )
            );
        }

        self::log_audit_event( $invoice_id, $is_regen ? 'regenerated' : 'generated', [
            'user_id' => $user_id,
        ] );

        $pdf_url = $pdf_rel_path
            ? trailingslashit( $upload['baseurl'] ) . $pdf_rel_path
            : null;

        return [
            'token'        => $token,
            'url'          => self::get_public_url( $token ),
            'pdf_url'      => $pdf_url,
            'generated_at' => $generated_at,
        ];
    }

    /**
     * Return stored document metadata, or null if no document has been generated.
     *
     * @return array{ token: string, url: string, pdf_url: string|null, generated_at: string, generated_by: int|null }|null
     */
    public static function get_info( int $invoice_id ): ?array {
        global $wpdb;

        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT doc_token, doc_generated_at, doc_generated_by, doc_pdf_path
             FROM {$wpdb->prefix}opb_invoices WHERE id = %d",
            $invoice_id
        ), ARRAY_A );

        if ( ! $row || ! $row['doc_token'] ) {
            return null;
        }

        $upload  = wp_upload_dir();
        $pdf_url = $row['doc_pdf_path']
            ? trailingslashit( $upload['baseurl'] ) . $row['doc_pdf_path']
            : null;

        return [
            'token'        => $row['doc_token'],
            'url'          => self::get_public_url( $row['doc_token'] ),
            'pdf_url'      => $pdf_url,
            'generated_at' => $row['doc_generated_at'],
            'generated_by' => $row['doc_generated_by'] ? (int) $row['doc_generated_by'] : null,
        ];
    }

    // ── PDF engine ─────────────────────────────────────────────────────────────

    /**
     * Generate the branded PDF using mPDF and write it to disk.
     *
     * @throws \Throwable on any mPDF error.
     */
    private static function generate_pdf( int $invoice_id, array $data, string $dir ): void {
        $pdf_path = $dir . '/invoice.pdf';
        $temp_dir = trailingslashit( get_temp_dir() ) . 'opb-mpdf';
        wp_mkdir_p( $temp_dir );

        $mpdf = new \Mpdf\Mpdf( [
            'mode'           => 'utf-8',
            'format'         => 'A4',
            'margin_top'     => 6,
            'margin_right'   => 12,
            'margin_bottom'  => 18,
            'margin_left'    => 12,
            'margin_footer'  => 5,
            'tempDir'        => $temp_dir,
        ] );

        $inv_num = $data['invoice']['legacy_invoice_number'] ?? ( '#' . $invoice_id );
        $mpdf->SetTitle( 'Invoice ' . $inv_num . ' — ' . OPB_Customizations::facility_name() );
        $mpdf->SetAuthor( OPB_Customizations::facility_name() );
        $mpdf->SetCreator( 'Onukonu Pet Boarding Core v' . OPB_VERSION );
        $mpdf->SetHTMLFooter(
            '<table width="100%" style="border-top:1px solid #e2e8f0;padding-top:5px;font-size:7pt;color:#a0aec0">'
            . '<tr><td>' . esc_html( OPB_Customizations::facility_name() ) . '</td>'
            . '<td style="text-align:right">Page {PAGENO} of {nbpg}</td></tr>'
            . '</table>'
        );

        $mpdf->WriteHTML( self::build_pdf_html( $data ) );
        $mpdf->Output( $pdf_path, \Mpdf\Output\Destination::FILE );
    }

    /**
     * Convert a media customization key to an image src suitable for mPDF.
     * Uses a base64 data URI when the file is locally accessible; falls back to URL.
     */
    private static function img_src( string $key ): string {
        $file = OPB_Customizations::get_media_path( $key );
        if ( $file ) {
            $mime = mime_content_type( $file );
            if ( $mime && str_starts_with( $mime, 'image/' ) ) {
                $enc = base64_encode( file_get_contents( $file ) ); // phpcs:ignore
                return 'data:' . $mime . ';base64,' . $enc;
            }
        }
        return OPB_Customizations::get_media_url( $key );
    }

    // ── PDF HTML builder ───────────────────────────────────────────────────────

    private static function build_pdf_html( array $data ): string {
        $inv        = $data['invoice'];
        $line_items = $data['line_items'];
        $payments   = $data['payments'];
        $pets       = $data['pets'];

        $facility       = OPB_Customizations::facility_name();
        $facility_phone = OPB_Customizations::get( 'facility_phone' );
        $facility_email = OPB_Customizations::get( 'facility_email' );
        $facility_addr  = trim( (string) ( $inv['branch_address'] ?? '' ) );

        $inv_num  = $inv['legacy_invoice_number'] ?? ( '#' . $inv['id'] );
        $inv_date = self::fmt_date( $inv['invoice_date'] );
        $gen_on   = wp_date( 'd M Y, g:i A' );

        $status_map = [
            'Paid'           => [ '#276749', '#f0fff4', '#9ae6b4' ],
            'Partially paid' => [ '#c05621', '#fffaf0', '#f6ad55' ],
            'Unpaid'         => [ '#c53030', '#fff5f5', '#fc8181' ],
            'Overpaid'       => [ '#2b6cb0', '#ebf8ff', '#90cdf4' ],
            'No bill'        => [ '#718096', '#f7fafc', '#e2e8f0' ],
        ];
        [ $s_color, $s_bg, $s_border ] = $status_map[ $inv['payment_status'] ] ?? [ '#718096', '#f7fafc', '#e2e8f0' ];

        $h_facility = esc_html( $facility );
        $h_inv_num  = esc_html( $inv_num );
        $h_inv_date = esc_html( $inv_date );
        $h_status   = esc_html( $inv['payment_status'] );
        $h_c_name   = esc_html( $inv['client_name']  ?? '' );
        $h_c_phone  = esc_html( $inv['client_phone'] ?? '' );
        $h_c_email  = esc_html( $inv['client_email'] ?? '' );
        $h_br_name  = esc_html( $inv['branch_name']  ?? '' );

        // Branding assets
        $banner_src = self::img_src( 'invoice_banner_image' );
        $logo_src   = self::img_src( 'invoice_logo_image' );
        $qr_src     = self::img_src( 'invoice_payment_qr_image' );

        // Branding text
        $upi_id       = OPB_Customizations::get( 'invoice_upi_id' );
        $bank_details = OPB_Customizations::get( 'invoice_bank_details' );
        $pay_instr    = OPB_Customizations::get( 'invoice_payment_instructions' );
        $footer_text  = OPB_Customizations::get( 'invoice_footer_text' )
                        ?: OPB_Customizations::get( 'invoice_footer_note' );
        $thank_you    = OPB_Customizations::render( 'invoice_thank_you_message', [ 'FACILITY_NAME' => $facility ] );
        $terms_raw    = OPB_Customizations::get( 'invoice_terms_text' );
        $payment_note = OPB_Customizations::get( 'invoice_payment_note' );

        // Financial
        $base    = (float) $inv['base_amount'];
        $addon   = (float) $inv['addon_amount'];
        $disc    = (float) $inv['discount_amount'];
        $addl    = (float) $inv['additional_amount'];
        $revenue = (float) $inv['revenue'];
        $paid    = (float) $inv['paid'];
        $due     = (float) $inv['due'];

        ob_start(); ?>
<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<style>
* { box-sizing: border-box; margin: 0; padding: 0; }
body { font-family: Arial, Helvetica, sans-serif; font-size: 10pt; color: #2d3748; }
table { border-collapse: collapse; }
p { margin-bottom: 6px; }

.section-label {
    font-size: 7pt;
    font-weight: bold;
    letter-spacing: 1px;
    text-transform: uppercase;
    color: #a0aec0;
    margin-bottom: 5px;
}

.divider-line {
    border: none;
    border-top: 1px solid #e2e8f0;
    margin: 14px 0;
}

.badge-base     { background: #ebf4ff; color: #2b6cb0; padding: 2px 7px; border-radius: 8px; font-size: 8pt; font-weight: bold; }
.badge-addon    { background: #f0fff4; color: #276749; padding: 2px 7px; border-radius: 8px; font-size: 8pt; font-weight: bold; }
.badge-discount { background: #fff5f5; color: #c53030; padding: 2px 7px; border-radius: 8px; font-size: 8pt; font-weight: bold; }
.badge-addl     { background: #fffaf0; color: #c05621; padding: 2px 7px; border-radius: 8px; font-size: 8pt; font-weight: bold; }
.badge-other    { background: #f7fafc; color: #718096; padding: 2px 7px; border-radius: 8px; font-size: 8pt; font-weight: bold; }

.th-style {
    background: #f7fafc;
    padding: 7px 8px;
    text-align: left;
    font-size: 7.5pt;
    font-weight: bold;
    letter-spacing: 0.5px;
    text-transform: uppercase;
    color: #718096;
    border-bottom: 2px solid #e2e8f0;
}

.td-style {
    padding: 7px 8px;
    border-bottom: 1px solid #f1f5f9;
    font-size: 9.5pt;
    color: #4a5568;
    vertical-align: top;
}

.payment-box {
    background: #f7fafc;
    border: 1px solid #e2e8f0;
    padding: 14px 16px;
}
</style>
</head>
<body>

<?php /* ── BANNER ─────────────────────────────────────────────────────────── */ ?>
<?php if ( $banner_src ) : ?>
<img src="<?php echo $banner_src; ?>" width="100%" style="display:block;margin-bottom:0;max-height:120px" />
<?php endif; ?>

<?php /* ── HEADER BAR ─────────────────────────────────────────────────────── */ ?>
<table width="100%" cellpadding="0" cellspacing="0" style="background:#1a365d;color:#fff">
<tr>
    <td style="padding:16px 20px;vertical-align:middle">
        <?php if ( $logo_src ) : ?>
        <img src="<?php echo $logo_src; ?>" height="46" style="display:inline-block;vertical-align:middle;margin-right:10px" />
        <?php endif; ?>
        <span style="font-size:16pt;font-weight:bold;color:#fff;vertical-align:middle"><?php echo $h_facility; ?></span>
        <div style="font-size:8pt;color:#90cdf4;margin-top:4px">
            <?php
            $contact_parts = array_filter( [ $facility_phone, $facility_email ] );
            echo esc_html( implode( '  ·  ', $contact_parts ) );
            ?>
        </div>
        <?php if ( $facility_addr ) : ?>
        <div style="font-size:8pt;color:#90cdf4;margin-top:2px"><?php echo nl2br( esc_html( $facility_addr ) ); ?></div>
        <?php endif; ?>
    </td>
    <td style="padding:16px 20px;text-align:right;vertical-align:middle;white-space:nowrap">
        <div style="font-size:7pt;font-weight:bold;letter-spacing:1.5px;color:#90cdf4;text-transform:uppercase">Invoice</div>
        <div style="font-size:20pt;font-weight:800;color:#fff;margin:2px 0"><?php echo $h_inv_num; ?></div>
        <div style="font-size:8.5pt;color:#90cdf4;margin-bottom:8px"><?php echo $h_inv_date; ?></div>
        <span style="background:<?php echo esc_attr( $s_bg ); ?>;color:<?php echo esc_attr( $s_color ); ?>;border:1px solid <?php echo esc_attr( $s_border ); ?>;padding:3px 10px;border-radius:10px;font-size:9pt;font-weight:bold"><?php echo $h_status; ?></span>
    </td>
</tr>
</table>

<?php /* ── CLIENT + BRANCH ─────────────────────────────────────────────────── */ ?>
<table width="100%" cellpadding="0" cellspacing="0" style="border:1px solid #e2e8f0;border-top:none">
<tr>
    <td width="50%" style="padding:14px 20px;vertical-align:top;border-right:1px solid #e2e8f0">
        <div class="section-label">Billed To</div>
        <div style="font-size:13pt;font-weight:bold;color:#1a202c;margin-bottom:3px"><?php echo $h_c_name; ?></div>
        <div style="font-size:9pt;color:#718096"><?php echo $h_c_phone; ?></div>
        <?php if ( $h_c_email ) : ?>
        <div style="font-size:8.5pt;color:#718096"><?php echo $h_c_email; ?></div>
        <?php endif; ?>
    </td>
    <td width="50%" style="padding:14px 20px;vertical-align:top">
        <div class="section-label">Branch</div>
        <div style="font-size:12pt;font-weight:bold;color:#1a202c;margin-bottom:3px"><?php echo $h_br_name; ?></div>
        <?php if ( $inv['branch_phone'] ) : ?>
        <div style="font-size:9pt;color:#718096"><?php echo esc_html( $inv['branch_phone'] ); ?></div>
        <?php endif; ?>
        <div style="font-size:8pt;color:#a0aec0;margin-top:6px">Booking #<?php echo esc_html( $inv['booking_id'] ?? '' ); ?></div>
    </td>
</tr>
</table>

<?php /* ── PETS + STAY ─────────────────────────────────────────────────────── */ ?>
<?php if ( $pets ) :
    $pet_strings = array_map( function( $p ) {
        $s = esc_html( $p['pet_name'] );
        if ( $p['breed'] ) $s .= ' <span style="color:#718096;font-size:8.5pt">(' . esc_html( $p['breed'] ) . ')</span>';
        return $s;
    }, $pets );
?>
<table width="100%" cellpadding="0" cellspacing="0" style="border:1px solid #e2e8f0;border-top:none;background:#f7fafc">
<tr>
    <td style="padding:8px 20px;font-size:9.5pt;color:#4a5568">
        <span class="section-label" style="margin-right:8px;display:inline">Pets</span>
        <?php echo implode( ' &nbsp;·&nbsp; ', $pet_strings ); ?>
    </td>
</tr>
<?php if ( ! empty( $pets[0]['check_in_date'] ) ) : ?>
<tr>
    <td style="padding:6px 20px;font-size:9pt;color:#718096;border-top:1px solid #e2e8f0">
        <span class="section-label" style="margin-right:8px;display:inline">Stay</span>
        <?php
        echo esc_html( self::fmt_date( $pets[0]['check_in_date'] ) );
        echo ' &nbsp;&#8594;&nbsp; ';
        echo esc_html( self::fmt_date( $pets[0]['check_out_date'] ) );
        if ( ! empty( $pets[0]['boarding_type'] ) ) {
            echo ' &nbsp;·&nbsp; <span style="color:#a0aec0">' . esc_html( $pets[0]['boarding_type'] ) . '</span>';
        }
        ?>
    </td>
</tr>
<?php endif; ?>
</table>
<?php endif; ?>

<?php /* ── LINE ITEMS ──────────────────────────────────────────────────────── */ ?>
<div style="margin-top:18px">
    <div class="section-label" style="margin-bottom:8px">Line Items</div>
    <table width="100%" cellpadding="0" cellspacing="0">
        <thead>
        <tr>
            <th class="th-style" width="80">Section</th>
            <th class="th-style">Description</th>
            <th class="th-style" style="text-align:right;width:40px">Qty</th>
            <th class="th-style" style="text-align:right;width:85px">Rate</th>
            <th class="th-style" style="text-align:right;width:90px">Total</th>
        </tr>
        </thead>
        <tbody>
        <?php
        $sec_badge_class = [
            'Base'       => 'badge-base',
            'Add-on'     => 'badge-addon',
            'Discount'   => 'badge-discount',
            'Additional' => 'badge-addl',
        ];
        if ( $line_items ) {
            foreach ( $line_items as $li ) {
                $badge_class = $sec_badge_class[ $li['bill_section'] ] ?? 'badge-other';
                $row_color   = $li['is_return'] ? ' color:#c53030;' : '';
                ?>
                <tr>
                    <td class="td-style"><span class="<?php echo esc_attr( $badge_class ); ?>"><?php echo esc_html( $li['bill_section'] ); ?></span></td>
                    <td class="td-style" style="<?php echo $row_color; ?>"><?php echo esc_html( $li['bill_item_name'] ?? '' ); ?></td>
                    <td class="td-style" style="text-align:right;<?php echo $row_color; ?>"><?php echo esc_html( $li['quantity'] ); ?></td>
                    <td class="td-style" style="text-align:right;<?php echo $row_color; ?>"><?php echo self::fmt_inr( (float) $li['amount'] ); ?></td>
                    <td class="td-style" style="text-align:right;font-weight:bold;color:#1a202c;<?php echo $row_color; ?>"><?php echo self::fmt_inr( (float) $li['total'] ); ?></td>
                </tr>
                <?php
            }
        } else {
            echo '<tr><td colspan="5" class="td-style" style="text-align:center;color:#a0aec0">No line items recorded</td></tr>';
        }
        ?>
        </tbody>
    </table>
</div>

<?php /* ── FINANCIAL SUMMARY ─────────────────────────────────────────────── */ ?>
<table width="100%" cellpadding="0" cellspacing="0" style="margin-top:16px">
<tr>
    <td width="55%">&nbsp;</td>
    <td width="45%">
        <table width="100%" cellpadding="0" cellspacing="0" style="border:1px solid #e2e8f0">
        <?php
        $ts_l = 'style="padding:6px 10px;font-size:9.5pt;color:#4a5568"';
        $ts_r = 'style="padding:6px 10px;font-size:9.5pt;text-align:right;font-weight:500;color:#1a202c"';
        echo "<tr><td $ts_l>Base Amount</td><td $ts_r>" . self::fmt_inr( $base ) . '</td></tr>';
        if ( $addon > 0 )
            echo "<tr><td $ts_l>Add-ons</td><td $ts_r>" . self::fmt_inr( $addon ) . '</td></tr>';
        if ( $disc > 0 )
            echo '<tr><td ' . $ts_l . '>Discount</td><td style="padding:6px 10px;font-size:9.5pt;text-align:right;font-weight:500;color:#c53030">&minus; ' . self::fmt_inr( $disc ) . '</td></tr>';
        if ( $addl > 0 )
            echo "<tr><td $ts_l>Additional</td><td $ts_r>" . self::fmt_inr( $addl ) . '</td></tr>';
        ?>
        <tr><td colspan="2" style="padding:0;border-top:2px solid #1a365d"></td></tr>
        <tr>
            <td style="padding:8px 10px;font-size:12pt;font-weight:800;color:#1a365d">Total</td>
            <td style="padding:8px 10px;font-size:12pt;font-weight:800;color:#1a365d;text-align:right"><?php echo self::fmt_inr( $revenue ); ?></td>
        </tr>
        <?php if ( $paid > 0 ) : ?>
        <tr>
            <td style="padding:5px 10px;font-size:9.5pt;color:#276749">Paid</td>
            <td style="padding:5px 10px;font-size:9.5pt;text-align:right;font-weight:600;color:#276749"><?php echo self::fmt_inr( $paid ); ?></td>
        </tr>
        <?php endif; ?>
        <?php if ( $due > 0 ) : ?>
        <tr style="background:#fff5f5">
            <td style="padding:7px 10px;font-size:10.5pt;font-weight:700;color:#c53030">Balance Due</td>
            <td style="padding:7px 10px;font-size:10.5pt;font-weight:800;color:#c53030;text-align:right"><?php echo self::fmt_inr( $due ); ?></td>
        </tr>
        <?php elseif ( $paid > 0 ) : ?>
        <tr style="background:#f0fff4">
            <td colspan="2" style="padding:6px 10px;font-size:9pt;font-weight:700;color:#276749;text-align:right">&#10003; Fully Settled</td>
        </tr>
        <?php endif; ?>
        </table>
    </td>
</tr>
</table>

<?php /* ── PAYMENT HISTORY ─────────────────────────────────────────────────── */ ?>
<?php if ( $payments ) : ?>
<div style="margin-top:20px">
    <div class="section-label" style="margin-bottom:8px">Payment History</div>
    <table width="100%" cellpadding="0" cellspacing="0">
        <thead>
        <tr>
            <th class="th-style">Date</th>
            <th class="th-style">Mode</th>
            <th class="th-style" style="text-align:right">Amount</th>
            <th class="th-style">Transaction ID</th>
        </tr>
        </thead>
        <tbody>
        <?php foreach ( $payments as $p ) : ?>
        <tr>
            <td class="td-style"><?php echo esc_html( self::fmt_datetime( $p['paid_at'] ) ); ?></td>
            <td class="td-style"><?php echo esc_html( $p['mode'] ); ?></td>
            <td class="td-style" style="text-align:right;font-weight:600;color:#276749"><?php echo self::fmt_inr( (float) $p['amount'] ); ?></td>
            <td class="td-style" style="color:#a0aec0;font-size:8.5pt"><?php echo esc_html( $p['transaction_id'] ?? '—' ); ?></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php endif; ?>

<?php /* ── PAYMENT SECTION (QR + UPI + Bank + Instructions) ──────────────── */ ?>
<?php
$has_payment_section = $qr_src || $upi_id || $bank_details || $pay_instr || $payment_note;
if ( $has_payment_section ) :
?>
<div style="margin-top:20px">
    <div class="section-label" style="margin-bottom:8px">Payment Information</div>
    <div class="payment-box">
        <table width="100%" cellpadding="0" cellspacing="0">
        <tr>
            <?php if ( $qr_src ) : ?>
            <td width="120" style="vertical-align:top;padding-right:16px">
                <img src="<?php echo $qr_src; ?>" width="110" height="110" style="display:block" />
                <?php if ( $upi_id ) : ?>
                <div style="font-size:7.5pt;color:#718096;margin-top:4px;text-align:center"><?php echo esc_html( $upi_id ); ?></div>
                <?php endif; ?>
            </td>
            <?php endif; ?>
            <td style="vertical-align:top">
                <?php if ( $upi_id && ! $qr_src ) : ?>
                <div style="font-size:9.5pt;color:#1a202c;margin-bottom:8px">
                    <strong>UPI ID:</strong> <?php echo esc_html( $upi_id ); ?>
                </div>
                <?php endif; ?>
                <?php if ( $pay_instr ) : ?>
                <div style="font-size:9.5pt;color:#4a5568;margin-bottom:8px;line-height:1.5">
                    <?php echo nl2br( esc_html( $pay_instr ) ); ?>
                </div>
                <?php endif; ?>
                <?php if ( $bank_details ) : ?>
                <div style="font-size:9pt;color:#4a5568;line-height:1.6">
                    <strong style="color:#1a202c">Bank Details:</strong><br/>
                    <?php echo nl2br( esc_html( $bank_details ) ); ?>
                </div>
                <?php endif; ?>
                <?php if ( $payment_note && ! $pay_instr ) : ?>
                <div style="font-size:9pt;color:#718096;margin-top:6px;line-height:1.5">
                    <?php echo nl2br( esc_html( $payment_note ) ); ?>
                </div>
                <?php endif; ?>
            </td>
        </tr>
        </table>
    </div>
</div>
<?php endif; ?>

<?php /* ── FOOTER ─────────────────────────────────────────────────────────── */ ?>
<div style="margin-top:22px;border-top:1px solid #e2e8f0;padding-top:14px">
    <?php if ( $thank_you ) : ?>
    <div style="font-size:11pt;font-weight:bold;color:#1a365d;margin-bottom:8px;text-align:center"><?php echo esc_html( $thank_you ); ?></div>
    <?php endif; ?>

    <?php if ( $footer_text ) : ?>
    <p style="font-size:9pt;color:#4a5568;line-height:1.6;margin-bottom:6px"><?php echo nl2br( esc_html( $footer_text ) ); ?></p>
    <?php endif; ?>

    <?php if ( $terms_raw ) : ?>
    <div style="margin-top:12px;border-top:1px solid #f1f5f9;padding-top:10px">
        <div class="section-label" style="margin-bottom:6px">Terms</div>
        <div style="font-size:8pt;color:#718096;line-height:1.6"><?php echo wp_kses_post( $terms_raw ); ?></div>
    </div>
    <?php endif; ?>

    <div style="margin-top:12px;font-size:7.5pt;color:#a0aec0;text-align:right">
        Generated on <?php echo esc_html( $gen_on ); ?> &nbsp;·&nbsp; <?php echo $h_facility; ?>
    </div>
</div>

</body>
</html>
        <?php
        return (string) ob_get_clean();
    }

    // ── Email delivery ─────────────────────────────────────────────────────────

    /**
     * Send the invoice via email with the PDF as an attachment.
     *
     * @param  string $to  Recipient; defaults to the client's email.
     * @return array{ sent: bool, to: string }
     * @throws RuntimeException if no valid address, invoice not found, or no PDF exists.
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

        $doc_info = self::get_info( $invoice_id );
        $doc_url  = $doc_info['url'] ?? '';

        // Require a generated PDF
        $upload   = wp_upload_dir();
        $pdf_path = trailingslashit( $upload['basedir'] ) . self::UPLOAD_SUBDIR . '/' . $invoice_id . '/invoice.pdf';
        if ( ! file_exists( $pdf_path ) ) {
            throw new \RuntimeException( 'No PDF found for this invoice. Generate the document first.' );
        }

        $inv_num = $inv['legacy_invoice_number'] ?? ( '#' . $inv['id'] );
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

        $sent = wp_mail(
            $recipient,
            $subject,
            $body,
            [ 'Content-Type: text/html; charset=UTF-8' ],
            [ $pdf_path ]
        );

        if ( $sent ) {
            self::log_audit_event( $invoice_id, 'email_sent', [
                'to'      => $recipient,
                'user_id' => get_current_user_id(),
            ] );
        }

        return [ 'sent' => (bool) $sent, 'to' => $recipient ];
    }

    // ── WhatsApp link ──────────────────────────────────────────────────────────

    /**
     * Build the WhatsApp sharing link for this invoice.
     * {{INVOICE_LINK}} in the template points to the public summary page,
     * which offers a PDF download button.
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

        if ( ! $doc_info ) {
            throw new \RuntimeException( 'No invoice document has been generated yet. Generate the document first, then share via WhatsApp.' );
        }

        $doc_url  = $doc_info['url'];

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

        self::log_audit_event( $invoice_id, 'whatsapp_shared', [
            'phone'   => $phone,
            'user_id' => get_current_user_id(),
        ] );

        return [ 'url' => $url, 'message' => $message, 'phone' => $phone ];
    }

    // ── Audit trail ────────────────────────────────────────────────────────────

    /**
     * Return all audit events for an invoice, newest first.
     *
     * @return array<int, array{ id: int, event: string, performed_by: int|null, performed_at: string, meta: array|null }>
     */
    public static function get_audit( int $invoice_id ): array {
        global $wpdb;
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT id, event, performed_by, performed_at, meta
             FROM {$wpdb->prefix}opb_invoice_audit
             WHERE invoice_id = %d
             ORDER BY performed_at DESC",
            $invoice_id
        ), ARRAY_A );

        return array_map( function ( array $row ): array {
            return [
                'id'           => (int) $row['id'],
                'event'        => $row['event'],
                'performed_by' => $row['performed_by'] ? (int) $row['performed_by'] : null,
                'performed_at' => $row['performed_at'],
                'meta'         => $row['meta'] ? json_decode( $row['meta'], true ) : null,
            ];
        }, $rows ?: [] );
    }

    private static function log_audit_event( int $invoice_id, string $event, array $meta = [] ): void {
        global $wpdb;
        $wpdb->insert(
            "{$wpdb->prefix}opb_invoice_audit",
            [
                'invoice_id'   => $invoice_id,
                'event'        => $event,
                'performed_by' => get_current_user_id() ?: null,
                'performed_at' => current_time( 'mysql' ),
                'meta'         => $meta ? wp_json_encode( $meta ) : null,
            ]
        );
    }

    // ── Public serve ───────────────────────────────────────────────────────────

    private static function serve( string $token ): void {
        global $wpdb;

        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT id, doc_pdf_path FROM {$wpdb->prefix}opb_invoices WHERE doc_token = %s",
            $token
        ), ARRAY_A );

        if ( ! $row ) {
            wp_die( 'Invoice not found or link has expired.', 'Not Found', [ 'response' => 404 ] );
        }

        $invoice_id = (int) $row['id'];
        $data       = self::get_invoice_data( $invoice_id );
        if ( ! $data ) {
            wp_die( 'Invoice data could not be loaded.', 'Error', [ 'response' => 500 ] );
        }

        $upload  = wp_upload_dir();
        $pdf_url = $row['doc_pdf_path']
            ? trailingslashit( $upload['baseurl'] ) . $row['doc_pdf_path']
            : null;

        header( 'Content-Type: text/html; charset=UTF-8' );
        header( 'X-Robots-Tag: noindex' );
        header( 'Cache-Control: no-store' );

        echo self::build_public_html( $data, $pdf_url ); // phpcs:ignore WordPress.Security.EscapeOutput
    }

    // ── Public summary page ────────────────────────────────────────────────────

    private static function build_public_html( array $data, ?string $pdf_url ): string {
        $inv      = $data['invoice'];
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

        $h_facility = esc_html( $facility );
        $h_inv_num  = esc_html( $inv_num );
        $h_c_name   = esc_html( $inv['client_name'] ?? '' );

        $download_btn = $pdf_url
            ? '<a href="' . esc_url( $pdf_url ) . '" style="display:inline-block;background:#1a365d;color:#fff;padding:13px 32px;border-radius:8px;text-decoration:none;font-weight:700;font-size:15px;margin-top:8px">&#8595; Download Invoice PDF</a>'
            : '<div style="background:#fff5f5;border:1px solid #fed7d7;border-radius:8px;padding:14px;color:#c53030;font-size:13px;margin-top:8px">No PDF available yet. Please ask the facility to regenerate your invoice.</div>';

        $html  = '<!DOCTYPE html><html lang="en"><head>';
        $html .= '<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1.0">';
        $html .= '<title>Invoice ' . $h_inv_num . ' — ' . $h_facility . '</title>';
        $html .= '<style>';
        $html .= '*{box-sizing:border-box;margin:0;padding:0}';
        $html .= 'body{font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Arial,sans-serif;background:#f0f4f8;color:#2d3748;line-height:1.5}';
        $html .= '.wrap{max-width:560px;margin:40px auto;padding:0 16px 60px}';
        $html .= '.card{background:#fff;border-radius:10px;overflow:hidden;box-shadow:0 2px 8px rgba(0,0,0,.08);margin-bottom:16px}';
        $html .= '.hdr{background:#1a365d;color:#fff;padding:22px 24px}';
        $html .= '.body{padding:24px}';
        $html .= 'table{width:100%;border-collapse:collapse;font-size:14px}';
        $html .= 'td{padding:9px 12px;border-bottom:1px solid #f1f5f9;color:#4a5568}';
        $html .= 'td:last-child{text-align:right;font-weight:600;color:#1a202c}';
        $html .= 'tr:last-child td{border-bottom:none}';
        $html .= '</style></head><body>';
        $html .= '<div class="wrap">';

        $html .= '<div class="card">';
        $html .= '<div class="hdr">';
        $html .=   '<div style="font-size:20px;font-weight:800;margin-bottom:4px">🐾 ' . $h_facility . '</div>';
        $html .=   '<div style="font-size:13px;color:#90cdf4">Invoice ' . $h_inv_num . ' &nbsp;·&nbsp; ' . esc_html( $inv_date ) . '</div>';
        $html .= '</div>';
        $html .= '<div class="body">';
        $html .=   '<div style="font-size:16px;font-weight:700;color:#1a202c;margin-bottom:4px">' . $h_c_name . '</div>';
        $html .=   '<div style="font-size:13px;color:#718096;margin-bottom:20px">Billed to: ' . $h_c_name . '</div>';

        $html .= '<table>';
        $html .= '<tr><td>Status</td><td style="color:' . esc_attr( $status_color ) . ';font-weight:700">' . esc_html( $inv['payment_status'] ) . '</td></tr>';
        $html .= '<tr><td>Total</td><td style="font-size:15px;font-weight:800;color:#1a202c">' . self::fmt_inr( $revenue ) . '</td></tr>';
        if ( $paid > 0 ) $html .= '<tr><td>Paid</td><td style="color:#276749">' . self::fmt_inr( $paid ) . '</td></tr>';
        if ( $due  > 0 ) $html .= '<tr><td>Balance Due</td><td style="color:#c53030;font-size:15px;font-weight:800">' . self::fmt_inr( $due ) . '</td></tr>';
        $html .= '</table>';

        $html .= '<div style="text-align:center;margin-top:24px">' . $download_btn . '</div>';
        $html .= '<div style="font-size:11px;color:#a0aec0;text-align:center;margin-top:10px">Secure invoice link — ' . $h_facility . '</div>';
        $html .= '</div>'; // .body
        $html .= '</div>'; // .card
        $html .= '</div>'; // .wrap
        $html .= '</body></html>';

        return $html;
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

    // ── Legacy HTML builder (retained for HTML preview file) ──────────────────

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

        $contact_parts = array_filter( [ $facility_phone, $facility_email, $facility_website ] );
        $contact_line  = $contact_parts
            ? '<div style="font-size:12px;color:#90cdf4;margin-top:4px">' . esc_html( implode( '  ·  ', $contact_parts ) ) . '</div>'
            : '';

        $branch_addr_raw  = trim( (string) ( $inv['branch_address'] ?? '' ) );
        $branch_addr_html = $branch_addr_raw
            ? '<div style="font-size:12px;color:#718096;margin-top:3px">' . nl2br( esc_html( $branch_addr_raw ) ) . '</div>'
            : '';

        // Pets band
        $pets_band_html = '';
        $stay_band_html = '';
        if ( $pets ) {
            $pet_strs = array_map( function( $p ) {
                $s = esc_html( $p['pet_name'] );
                if ( $p['breed'] ) $s .= ' <span style="color:#718096;font-size:12px">(' . esc_html( $p['breed'] ) . ')</span>';
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

        // Line items
        $sec_colors     = [
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

        // Summary
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

        // Payment history
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

        // Footer
        $payment_note = OPB_Customizations::render( 'invoice_payment_note', [ 'FACILITY_NAME' => $facility ] );
        $footer_note  = OPB_Customizations::render( 'invoice_footer_note',  [ 'FACILITY_NAME' => $facility ] );
        $footer_html  = '';
        if ( $payment_note ) $footer_html .= '<p style="font-size:12px;color:#718096;margin-bottom:6px;line-height:1.6">' . nl2br( esc_html( $payment_note ) ) . '</p>';
        if ( $footer_note  ) $footer_html .= '<p style="font-size:13px;color:#4a5568;font-weight:500;margin-bottom:10px">' . nl2br( esc_html( $footer_note ) ) . '</p>';

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
        $html .= '.page{max-width:800px;margin:28px auto;padding:0 16px 60px}';
        $html .= '@media print{body{background:#fff}.page{margin:0;padding:0;max-width:100%}}';
        $html .= '</style></head><body>';

        $html .= '<div class="page">';
        $html .= '<div style="background:#1e3a5f;color:#fff;border-radius:10px 10px 0 0;padding:24px 28px">';
        $html .=   '<div style="display:flex;justify-content:space-between;align-items:flex-start;gap:16px">';
        $html .=     '<div>';
        $html .=       '<div style="font-size:20px;font-weight:800;letter-spacing:-.3px;margin-bottom:3px">' . $h_facility . '</div>';
        $html .=       $contact_line;
        $html .=     '</div>';
        $html .=     '<div style="text-align:right">';
        $html .=       '<div style="font-size:10px;font-weight:700;letter-spacing:1.5px;color:#90cdf4;text-transform:uppercase">Invoice</div>';
        $html .=       '<div style="font-size:22px;font-weight:800;margin:2px 0">' . $h_inv_num . '</div>';
        $html .=       '<div style="font-size:12px;color:#90cdf4;margin-bottom:8px">' . $h_inv_date . '</div>';
        $html .=       '<span style="display:inline-block;padding:4px 12px;border-radius:20px;font-size:11px;font-weight:700;border:1px solid ' . esc_attr( $s_border ) . ';background:' . esc_attr( $s_bg ) . ';color:' . esc_attr( $s_color ) . '">' . $h_status . '</span>';
        $html .=     '</div>';
        $html .=   '</div>';
        $html .= '</div>';

        $html .= '<div style="background:#fff;border:1px solid #e2e8f0;border-top:none;padding:20px 28px">';
        $html .=   '<div style="display:grid;grid-template-columns:1fr 1fr;gap:20px">';
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

        $html .= $pets_band_html . $stay_band_html;

        $html .= '<div style="background:#fff;border:1px solid #e2e8f0;border-top:none;border-radius:0 0 10px 10px;padding:0 28px 28px">';
        $html .= '<div style="font-size:11px;font-weight:700;letter-spacing:.8px;text-transform:uppercase;color:#718096;padding:20px 0 8px;border-bottom:1px solid #e2e8f0">Line Items</div>';
        $html .= '<table width="100%" style="border-collapse:collapse"><tbody>' . $line_rows_html . '</tbody></table>';
        $html .= '<div style="display:flex;justify-content:flex-end;margin-top:20px"><table style="width:280px;border-collapse:collapse"><tbody>' . $sum_html . '</tbody></table></div>';
        if ( $pay_section_html ) $html .= '<div style="margin-top:20px">' . $pay_section_html . '</div>';
        $html .= '<div style="margin-top:24px;padding-top:16px;border-top:1px solid #e2e8f0">' . $footer_html;
        $html .= '<div style="font-size:10px;color:#a0aec0;margin-top:8px">Generated on ' . $h_gen_on . ' &nbsp;·&nbsp; ' . $h_facility . '</div>';
        $html .= '</div></div></div>';
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
            ? '<div style="text-align:center;margin:24px 0"><a href="' . esc_url( $doc_url ) . '" style="background:#1e3a5f;color:#fff;padding:12px 28px;border-radius:8px;text-decoration:none;font-weight:700;font-size:14px;display:inline-block">View Invoice &rarr;</a></div>'
            : '';

        $intro_html = nl2br( esc_html( $intro ) );
        $h_facility = esc_html( $facility );
        $h_inv_num  = esc_html( $inv_num );
        $h_inv_date = esc_html( $inv_date );

        $td_l = 'style="padding:10px 16px;background:#f7fafc;font-weight:600;font-size:13px;color:#1a202c;border-top:1px solid #e2e8f0"';
        $td_r = 'style="padding:10px 16px;text-align:right;font-size:13px;color:#4a5568;border-top:1px solid #e2e8f0"';

        $html  = '<!DOCTYPE html><html><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1.0"></head>';
        $html .= '<body style="margin:0;padding:0;background:#f0f4f8;font-family:-apple-system,BlinkMacSystemFont,\'Segoe UI\',Roboto,Arial,sans-serif;color:#2d3748">';
        $html .= '<div style="max-width:600px;margin:24px auto;background:#fff;border-radius:10px;overflow:hidden;box-shadow:0 2px 8px rgba(0,0,0,.08)">';

        $html .= '<div style="background:#1e3a5f;color:#fff;padding:24px 28px">';
        $html .=   '<div style="font-size:19px;font-weight:800;margin-bottom:4px">🐾 ' . $h_facility . '</div>';
        $html .=   '<div style="font-size:13px;color:#90cdf4">Invoice ' . $h_inv_num . ' &nbsp;·&nbsp; ' . $h_inv_date . '</div>';
        $html .= '</div>';

        $html .= '<div style="padding:24px 28px">';
        $html .=   '<p style="font-size:14px;color:#4a5568;line-height:1.7;margin-bottom:20px">' . $intro_html . '</p>';

        $html .= '<p style="font-size:13px;color:#4a5568;line-height:1.6;margin-bottom:16px;background:#ebf8ff;border:1px solid #bee3f8;border-radius:6px;padding:12px 14px">Your invoice is attached as a <strong>PDF file</strong>. Please open the attachment to view the full invoice details.</p>';

        $html .= '<table width="100%" cellspacing="0" cellpadding="0" style="border:1px solid #e2e8f0;border-radius:8px;overflow:hidden;font-size:14px;margin-bottom:20px">';
        $html .= '<tr><td style="padding:10px 16px;background:#f7fafc;font-weight:600;font-size:13px;color:#1a202c">Invoice #</td><td style="padding:10px 16px;text-align:right;font-size:13px;color:#4a5568">' . $h_inv_num . '</td></tr>';
        $html .= '<tr><td ' . $td_l . '>Date</td><td ' . $td_r . '>' . $h_inv_date . '</td></tr>';
        $html .= '<tr><td ' . $td_l . '>Status</td><td style="padding:10px 16px;text-align:right;font-size:13px;font-weight:700;color:' . esc_attr( $status_color ) . ';border-top:1px solid #e2e8f0">' . esc_html( $inv['payment_status'] ) . '</td></tr>';
        $html .= '<tr><td ' . $td_l . '>Total</td><td style="padding:10px 16px;text-align:right;font-size:13px;font-weight:700;color:#1a202c;border-top:1px solid #e2e8f0">' . self::fmt_inr( $revenue ) . '</td></tr>';
        if ( $paid > 0 )
            $html .= '<tr><td ' . $td_l . '>Paid</td><td style="padding:10px 16px;text-align:right;font-size:13px;font-weight:600;color:#276749;border-top:1px solid #e2e8f0">' . self::fmt_inr( $paid ) . '</td></tr>';
        if ( $due > 0 )
            $html .= '<tr><td ' . $td_l . '>Balance Due</td><td style="padding:10px 16px;text-align:right;font-size:14px;font-weight:800;color:#c53030;border-top:1px solid #e2e8f0">' . self::fmt_inr( $due ) . '</td></tr>';
        $html .= '</table>';

        $html .= $view_btn;

        $html .= '<div style="margin-top:20px;padding-top:16px;border-top:1px solid #e2e8f0;font-size:11px;color:#a0aec0">' . $h_facility . ' &nbsp;·&nbsp; Sent via pet management system</div>';
        $html .= '</div>';
        $html .= '</div>';
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

    private static function get_public_url( string $token ): string {
        return home_url( '/opb-invoice/' . $token . '/' );
    }
}
