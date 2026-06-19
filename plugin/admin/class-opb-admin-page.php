<?php
class OPB_Admin_Page {

    public static function register_menu(): void {
        add_menu_page(
            'Pet Boarding',
            'Pet Boarding',
            'manage_options',
            'opb-dashboard',
            [ self::class, 'render' ],
            'dashicons-pets',
            30
        );

        $screens = [
            'opb-dashboard'  => 'Dashboard',
            'opb-clients'    => 'Clients',
            'opb-pets'       => 'Pets',
            'opb-bookings'   => 'Bookings',
            'opb-kennel'     => 'Kennel Board',
            'opb-invoices'   => 'Invoices',
            'opb-tasks'      => 'Tasks',
            'opb-expenses'   => 'Expenses',
            'opb-settings'   => 'Settings',
            'opb-import'     => 'Import',
        ];

        foreach ( $screens as $slug => $label ) {
            add_submenu_page(
                'opb-dashboard',
                $label,
                $label,
                'manage_options',
                $slug,
                [ self::class, 'render' ]
            );
        }

        add_submenu_page(
            'opb-dashboard',
            'OPSMAIL Queue',
            'OPSMAIL Queue',
            'manage_options',
            'opb-opsmail-queue',
            [ self::class, 'render_opsmail_queue' ]
        );

        add_submenu_page(
            'opb-dashboard',
            'SAL — Situational Awareness',
            'SAL',
            'manage_options',
            'opb-sal',
            [ self::class, 'render' ]
        );
    }

    public static function render(): void {
        echo '<div id="opb-root"></div>';
    }

    public static function render_opsmail_queue(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'Insufficient permissions.' );
        }

        global $wpdb;
        $table = "{$wpdb->prefix}opb_opsmail_queue";

        $page     = max( 1, (int) ( $_GET['paged'] ?? 1 ) );
        $per_page = 50;
        $offset   = ( $page - 1 ) * $per_page;

        $status     = sanitize_text_field( $_GET['opb_status']     ?? '' );
        $event_type = sanitize_text_field( $_GET['opb_event_type'] ?? '' );
        $search     = sanitize_text_field( $_GET['opb_search']     ?? '' );

        $where = [ '1=1' ];
        $args  = [];

        if ( $status ) {
            $where[] = 'q.mail_status = %s';
            $args[]  = $status;
        }
        if ( $event_type ) {
            $where[] = 'q.event_type = %s';
            $args[]  = $event_type;
        }
        if ( $search ) {
            $like    = '%' . $wpdb->esc_like( $search ) . '%';
            $where[] = '(q.subject LIKE %s OR q.event_type LIKE %s)';
            $args    = array_merge( $args, [ $like, $like ] );
        }

        $where_sql = implode( ' AND ', $where );

        $total = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$table} q WHERE {$where_sql}",
            ...$args
        ) );

        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT q.id, q.event_uuid, q.event_type, q.source_system,
                    q.entity_type, q.entity_id,
                    q.branch_id, q.origin_type, q.priority, q.subject,
                    q.recipient_email, q.mail_status, q.mail_attempts, q.last_error,
                    q.telegram_status, q.telegram_attempts, q.telegram_sent_at,
                    q.classification, q.confidence,
                    q.created_at, q.sent_at,
                    b.name AS branch_name
             FROM {$table} q
             LEFT JOIN {$wpdb->prefix}opb_branches b ON b.id = q.branch_id
             WHERE {$where_sql}
             ORDER BY q.id DESC
             LIMIT %d OFFSET %d",
            ...[ ...$args, $per_page, $offset ]
        ), ARRAY_A ) ?? [];

        $total_pages = (int) ceil( $total / $per_page );

        $status_badge = static function( string $s ): string {
            $colours = [
                'SENT'         => '#166534',
                'PENDING'      => '#92400e',
                'FAILED'       => '#991b1b',
                'ACKNOWLEDGED' => '#374151',
            ];
            $colour = $colours[ $s ] ?? '#374151';
            return '<span style="display:inline-block;padding:2px 8px;border-radius:4px;font-size:11px;font-weight:700;'
                . 'background:' . $colour . ';color:#fff;letter-spacing:.5px">' . esc_html( $s ) . '</span>';
        };

        $base_url = admin_url( 'admin.php?page=opb-opsmail-queue' );
        $filter_qs = http_build_query( array_filter( [
            'opb_status'     => $status,
            'opb_event_type' => $event_type,
            'opb_search'     => $search,
        ] ) );
        ?>
        <div class="wrap" style="font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',sans-serif">
            <h1 style="display:flex;align-items:center;gap:10px;margin-bottom:16px;flex-wrap:wrap">
                <span style="background:#1e3a8a;color:#fff;font-size:11px;font-weight:700;padding:3px 10px;border-radius:4px;letter-spacing:2px">OPSMAIL</span>
                Operational Event Queue
                <span style="font-size:13px;color:#6b7280;font-weight:400"><?php echo esc_html( $total ); ?> event<?php echo $total !== 1 ? 's' : ''; ?></span>

                <span style="margin-left:auto;display:flex;gap:8px;flex-wrap:wrap">
                    <button id="opb-poll-mailbox" class="button"
                        style="display:flex;align-items:center;gap:4px"
                        <?php echo OPB_Mailbox_Processor::is_configured() ? '' : 'disabled title="Mailbox not configured"'; ?>>
                        📬 Poll Mailbox
                    </button>
                    <button id="opb-flush-telegram" class="button button-primary"
                        style="display:flex;align-items:center;gap:4px"
                        <?php echo OPB_Telegram_Consumer::is_configured() ? '' : 'disabled title="Telegram not configured"'; ?>>
                        ✈️ Flush Telegram
                    </button>
                </span>
            </h1>

            <div id="opb-pipeline-result" style="display:none;background:#f0fdf4;border:1px solid #86efac;border-radius:6px;padding:10px 14px;margin-bottom:16px;font-size:13px;color:#166534"></div>

            <script>
            (function() {
                var nonce = <?php echo wp_json_encode( wp_create_nonce( 'wp_rest' ) ); ?>;
                var base  = <?php echo wp_json_encode( rest_url( 'opb/v1' ) ); ?>;
                var result = document.getElementById('opb-pipeline-result');

                function runAction(endpoint, label) {
                    result.style.display = 'block';
                    result.style.background = '#fef9c3';
                    result.style.borderColor = '#fde68a';
                    result.style.color = '#78350f';
                    result.textContent = '⏳ ' + label + ' …';

                    fetch(base + endpoint, {
                        method: 'POST',
                        headers: { 'X-WP-Nonce': nonce, 'Content-Type': 'application/json' }
                    })
                    .then(function(r) { return r.json(); })
                    .then(function(data) {
                        result.style.background = '#f0fdf4';
                        result.style.borderColor = '#86efac';
                        result.style.color = '#166534';
                        var log = data.data && data.data.log ? data.data.log : data;
                        result.textContent = '✅ ' + label + ' complete. ' + JSON.stringify(log, null, 2);
                    })
                    .catch(function(e) {
                        result.style.background = '#fef2f2';
                        result.style.borderColor = '#fca5a5';
                        result.style.color = '#991b1b';
                        result.textContent = '❌ ' + label + ' failed: ' + e.message;
                    });
                }

                var btnMailbox  = document.getElementById('opb-poll-mailbox');
                var btnTelegram = document.getElementById('opb-flush-telegram');
                if (btnMailbox)  btnMailbox.addEventListener('click',  function() { runAction('/opsmail/process-mailbox',  'Poll Mailbox'); });
                if (btnTelegram) btnTelegram.addEventListener('click', function() { runAction('/opsmail/process-telegram', 'Flush Telegram'); });
            })();
            </script>

            <?php
            $enabled    = OPB_Opsmail::is_enabled();
            $inbox_ok   = OPB_Opsmail::inbox_email() !== '';
            $tg_ok      = OPB_Telegram_Consumer::is_configured();
            $mailbox_ok = OPB_Mailbox_Processor::is_configured();

            $warnings = [];
            if ( ! $enabled )   $warnings[] = '<strong>OPSMAIL emission is disabled.</strong> Set <code>opsmail_enabled</code> to <code>1</code> in Customisation settings.';
            if ( ! $inbox_ok )  $warnings[] = '<strong>No inbox email configured.</strong> Set <code>opsmail_inbox_email</code> in Customisation → OPSMAIL.';
            if ( ! $tg_ok )     $warnings[] = '<strong>Telegram not configured.</strong> Set <code>telegram_bot_token</code> and <code>telegram_chat_id</code> in Customisation → OPSMAIL.';

            if ( ! empty( $warnings ) ) {
                echo '<div style="background:#fef3c7;border:1px solid #fcd34d;border-radius:6px;padding:12px 16px;margin-bottom:20px;color:#92400e">';
                echo implode( '<br>', $warnings );
                echo '</div>';
            }
            ?>

            <form method="get" style="margin-bottom:16px;display:flex;gap:10px;align-items:center;flex-wrap:wrap">
                <input type="hidden" name="page" value="opb-opsmail-queue">
                <input type="text" name="opb_search" value="<?php echo esc_attr( $search ); ?>"
                    placeholder="Search subject / event…"
                    style="padding:6px 10px;border:1px solid #d1d5db;border-radius:4px;font-size:13px;min-width:220px">
                <select name="opb_status" style="padding:6px 10px;border:1px solid #d1d5db;border-radius:4px;font-size:13px">
                    <option value="">All statuses</option>
                    <?php foreach ( [ 'PENDING', 'SENT', 'FAILED', 'ACKNOWLEDGED' ] as $s ) : ?>
                        <option value="<?php echo esc_attr( $s ); ?>" <?php selected( $status, $s ); ?>><?php echo esc_html( $s ); ?></option>
                    <?php endforeach; ?>
                </select>
                <select name="opb_event_type" style="padding:6px 10px;border:1px solid #d1d5db;border-radius:4px;font-size:13px">
                    <option value="">All events</option>
                    <?php foreach ( OPB_Opsmail::EVENT_TYPES as $et ) : ?>
                        <option value="<?php echo esc_attr( $et ); ?>" <?php selected( $event_type, $et ); ?>><?php echo esc_html( $et ); ?></option>
                    <?php endforeach; ?>
                </select>
                <button type="submit" class="button">Filter</button>
                <?php if ( $status || $event_type || $search ) : ?>
                    <a href="<?php echo esc_url( $base_url ); ?>" class="button">Clear</a>
                <?php endif; ?>
            </form>

            <?php if ( empty( $rows ) ) : ?>
                <p style="color:#6b7280;font-style:italic">No events found.</p>
            <?php else : ?>
                <table class="wp-list-table widefat striped" style="font-size:13px">
                    <thead>
                        <tr>
                            <th style="width:40px">#</th>
                            <th>Event Type</th>
                            <th>Subject / Classification</th>
                            <th>Entity</th>
                            <th>Branch</th>
                            <th>Origin</th>
                            <th>Priority</th>
                            <th>Mail</th>
                            <th>Telegram</th>
                            <th>Created</th>
                            <th>Sent</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ( $rows as $row ) : ?>
                            <tr title="<?php echo esc_attr( $row['last_error'] ?? '' ); ?>">
                                <td style="color:#9ca3af"><?php echo (int) $row['id']; ?></td>
                                <td><code style="font-size:11px"><?php echo esc_html( $row['event_type'] ); ?></code></td>
                                <td><?php echo esc_html( $row['subject'] ); ?>
                                    <?php if ( ! empty( $row['classification'] ) ) : ?>
                                        <br><small style="color:#4f46e5;font-size:10px">
                                            🏷 <?php echo esc_html( $row['classification'] ); ?>
                                            <?php if ( $row['confidence'] !== null ) : ?>
                                                (<?php echo round( (float) $row['confidence'] * 100 ); ?>%)
                                            <?php endif; ?>
                                        </small>
                                    <?php endif; ?>
                                    <?php if ( $row['last_error'] ) : ?>
                                        <br><small style="color:#dc2626" title="<?php echo esc_attr( $row['last_error'] ); ?>">
                                            ⚠ <?php echo esc_html( mb_substr( $row['last_error'], 0, 80 ) ); ?>
                                        </small>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo esc_html( $row['entity_type'] ); ?><?php if ( $row['entity_id'] ) echo ' #' . (int) $row['entity_id']; ?></td>
                                <td><?php echo esc_html( $row['branch_name'] ?? ( $row['branch_id'] ? '#' . $row['branch_id'] : '—' ) ); ?></td>
                                <td><span style="font-size:11px"><?php echo esc_html( $row['origin_type'] ); ?></span></td>
                                <td style="font-size:11px;font-weight:<?php echo $row['priority'] === 'HIGH' ? '700' : '400'; ?>;color:<?php echo $row['priority'] === 'HIGH' ? '#dc2626' : 'inherit'; ?>">
                                    <?php echo esc_html( $row['priority'] ); ?>
                                </td>
                                <td><?php echo $status_badge( $row['mail_status'] ); ?></td>
                                <td>
                                    <?php echo $status_badge( $row['telegram_status'] ?? 'PENDING' ); ?>
                                    <?php if ( ! empty( $row['telegram_attempts'] ) && (int) $row['telegram_attempts'] > 0 ) : ?>
                                        <br><small style="color:#6b7280;font-size:10px"><?php echo (int) $row['telegram_attempts']; ?>×</small>
                                    <?php endif; ?>
                                </td>
                                <td style="white-space:nowrap;font-size:11px"><?php echo esc_html( $row['created_at'] ); ?></td>
                                <td style="white-space:nowrap;font-size:11px">
                                    <?php echo esc_html( $row['telegram_sent_at'] ?? $row['sent_at'] ?? '—' ); ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>

                <?php if ( $total_pages > 1 ) : ?>
                    <div style="margin-top:16px;display:flex;gap:8px;align-items:center">
                        <span style="color:#6b7280;font-size:13px">Page <?php echo $page; ?> of <?php echo $total_pages; ?></span>
                        <?php if ( $page > 1 ) : ?>
                            <a class="button" href="<?php echo esc_url( $base_url . '&paged=' . ( $page - 1 ) . ( $filter_qs ? '&' . $filter_qs : '' ) ); ?>">← Prev</a>
                        <?php endif; ?>
                        <?php if ( $page < $total_pages ) : ?>
                            <a class="button" href="<?php echo esc_url( $base_url . '&paged=' . ( $page + 1 ) . ( $filter_qs ? '&' . $filter_qs : '' ) ); ?>">Next →</a>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            <?php endif; ?>
        </div>
        <?php
    }

    public static function enqueue_assets(): void {
        $dist = OPB_PLUGIN_URL . 'assets/dist/';

        $manifest_path = OPB_PLUGIN_DIR . 'assets/dist/.vite/manifest.json';
        $js_file  = 'assets/index.js';
        $css_file = 'assets/index.css';

        if ( file_exists( $manifest_path ) ) {
            $manifest = json_decode( file_get_contents( $manifest_path ), true );
            $entry    = $manifest['src/main.tsx'] ?? null;
            if ( $entry ) {
                $js_file  = $entry['file'] ?? $js_file;
                $css_file = $entry['css'][0] ?? $css_file;
            }
        }

        wp_enqueue_script(
            'opb-app',
            $dist . $js_file,
            [],
            OPB_VERSION,
            true
        );

        add_filter( 'script_loader_tag', static function ( string $tag, string $handle ) use ( $dist, $js_file ): string {
            if ( $handle === 'opb-app' ) {
                $tag = str_replace( '<script ', '<script type="module" ', $tag );
            }
            return $tag;
        }, 10, 2 );

        if ( $css_file ) {
            wp_enqueue_style( 'opb-app-style', $dist . $css_file, [], OPB_VERSION );
        }

        $user = wp_get_current_user();

        wp_localize_script( 'opb-app', 'OPB', [
            'apiBase'   => rest_url( 'opb/v1' ),
            'nonce'     => wp_create_nonce( 'wp_rest' ),
            'adminUrl'  => admin_url( 'admin.php' ),
            'logoutUrl' => wp_logout_url( admin_url() ),
            'user'      => [
                'id'       => $user->ID,
                'name'     => $user->display_name,
                'roles'    => $user->roles,
                'branchId' => (int) get_user_meta( $user->ID, 'opb_branch_id', true ),
            ],
        ] );
    }
}
