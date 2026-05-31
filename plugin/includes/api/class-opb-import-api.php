<?php
/**
 * OPB_Import_API
 *
 * REST endpoints for the migration engine.
 * All heavy lifting is delegated to OPB_Migration_Engine + adapters.
 *
 * Endpoints:
 *   POST /opb/v1/import/dry-run   — validate file, return diagnostics
 *   POST /opb/v1/import/run       — live import
 *   GET  /opb/v1/import/status    — current DB counts
 *   GET  /opb/v1/import/history   — migration history log
 */
class OPB_Import_API extends OPB_REST_Base {

    public function register_routes(): void {
        $perm = fn($r) => $this->permission_manage('opb_run_import', $r);

        register_rest_route($this->namespace, '/import/dry-run', [
            ['methods' => 'POST', 'callback' => [$this,'dry_run'], 'permission_callback' => $perm],
        ]);
        register_rest_route($this->namespace, '/import/run', [
            ['methods' => 'POST', 'callback' => [$this,'run'],     'permission_callback' => $perm],
        ]);
        register_rest_route($this->namespace, '/import/status', [
            ['methods' => 'GET',  'callback' => [$this,'status'],  'permission_callback' => $perm],
        ]);
        register_rest_route($this->namespace, '/import/history', [
            ['methods' => 'GET',  'callback' => [$this,'history'], 'permission_callback' => $perm],
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────────

    public function dry_run( WP_REST_Request $r ): WP_REST_Response|WP_Error {
        [ $path, $entity, $ctx, $err ] = $this->prepare_upload($r);
        if ( $err ) return $err;

        $result = $this->build_engine()->run($path, $entity, true, $ctx);
        @unlink($path);
        return $this->success($result);
    }

    public function run( WP_REST_Request $r ): WP_REST_Response|WP_Error {
        [ $path, $entity, $ctx, $err ] = $this->prepare_upload($r);
        if ( $err ) return $err;

        @set_time_limit(300);
        $result = $this->build_engine()->run($path, $entity, false, $ctx);
        @unlink($path);
        return $this->success($result);
    }

    public function status( WP_REST_Request $r ): WP_REST_Response|WP_Error {
        global $wpdb;
        $p = $wpdb->prefix;
        return $this->success([
            'branches' => (int)$wpdb->get_var("SELECT COUNT(*) FROM {$p}opb_branches"),
            'clients'  => (int)$wpdb->get_var("SELECT COUNT(*) FROM {$p}opb_clients"),
            'pets'     => (int)$wpdb->get_var("SELECT COUNT(*) FROM {$p}opb_pets"),
            'bookings' => (int)$wpdb->get_var("SELECT COUNT(*) FROM {$p}opb_bookings"),
            'invoices' => (int)$wpdb->get_var("SELECT COUNT(*) FROM {$p}opb_invoices"),
            'payments' => (int)$wpdb->get_var("SELECT COUNT(*) FROM {$p}opb_payments"),
            'expenses' => (int)$wpdb->get_var("SELECT COUNT(*) FROM {$p}opb_expenses"),
            'services' => (int)$wpdb->get_var("SELECT COUNT(*) FROM {$p}opb_boarding_services"),
            'addons'   => (int)$wpdb->get_var("SELECT COUNT(*) FROM {$p}opb_addon_services"),
        ]);
    }

    public function history( WP_REST_Request $r ): WP_REST_Response|WP_Error {
        return $this->success($this->build_engine()->get_history());
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Helpers
    // ─────────────────────────────────────────────────────────────────────────

    /** Handle file upload and extract common params. Returns [path, entity, ctx, err|null]. */
    private function prepare_upload( WP_REST_Request $r ): array {
        if ( ! function_exists('wp_handle_upload') ) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
        }
        $files = $r->get_file_params();
        if ( empty($files['file']) ) {
            return [null, null, null, $this->error('invalid', 'No file uploaded')];
        }

        $entity    = sanitize_text_field($r->get_param('entity') ?? 'clients');
        $overrides = [
            'test_form' => false,
            'test_type' => false,
            'mimes'     => [
                'csv'  => 'text/csv',
                'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            ],
        ];
        $_FILES['file'] = $files['file'];
        $uploaded = wp_handle_upload($_FILES['file'], $overrides);
        if ( isset($uploaded['error']) ) {
            return [null, null, null, $this->error('upload_error', $uploaded['error'])];
        }

        // Resolve branch context from branch_id (int) or branch (code string)
        $branch_id = (int)($r->get_param('branch_id') ?? 0);
        if ( ! $branch_id ) {
            $code = sanitize_text_field($r->get_param('branch') ?? '');
            if ( $code ) {
                $resolver  = OPB_Branch_Resolver::from_db();
                $resolved  = $resolver->resolve($code);
                $branch_id = $resolved['branch'] ? (int)$resolved['branch']->id : 0;
            }
        }
        $ctx = $branch_id ? ['branch_id' => $branch_id] : [];

        return [$uploaded['file'], $entity, $ctx, null];
    }

    /** Build the migration engine with all registered adapters. */
    private function build_engine(): OPB_Migration_Engine {
        $engine = new OPB_Migration_Engine();
        $engine->register( new OPB_Clients_Adapter()  );
        $engine->register( new OPB_Pets_Adapter()     );
        $engine->register( new OPB_Bookings_Adapter() );
        $engine->register( new OPB_Invoices_Adapter() );
        $engine->register( new OPB_Payments_Adapter() );
        $engine->register( new OPB_Expenses_Adapter() );
        $engine->register( new OPB_Services_Adapter() );
        $engine->register( new OPB_Addons_Adapter()   );
        return $engine;
    }
}
