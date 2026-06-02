<?php
class OPB_Kennels_API extends OPB_REST_Base {

    public function register_routes(): void {
        register_rest_route( $this->namespace, '/settings/kennels', [
            [ 'methods' => 'GET',  'callback' => [ $this, 'get_items'   ], 'permission_callback' => [ $this, 'permission_check' ] ],
            [ 'methods' => 'POST', 'callback' => [ $this, 'create_item' ], 'permission_callback' => fn($r) => $this->permission_manage('opb_manage_settings', $r) ],
        ]);
        register_rest_route( $this->namespace, '/settings/kennels/reorder', [
            [ 'methods' => 'POST', 'callback' => [ $this, 'reorder' ], 'permission_callback' => fn($r) => $this->permission_manage('opb_manage_settings', $r) ],
        ]);
        register_rest_route( $this->namespace, '/settings/kennels/(?P<id>\d+)', [
            [ 'methods' => 'PUT',    'callback' => [ $this, 'update_item' ], 'permission_callback' => fn($r) => $this->permission_manage('opb_manage_settings', $r) ],
            [ 'methods' => 'DELETE', 'callback' => [ $this, 'delete_item' ], 'permission_callback' => fn($r) => $this->permission_manage('opb_manage_settings', $r) ],
        ]);
    }

    public function get_items( WP_REST_Request $r ): WP_REST_Response|WP_Error {
        $check = $this->permission_check($r); if(is_wp_error($check)) return $check;
        global $wpdb;

        $branch_id  = $this->branch_filter((int)($r->get_param('branch_id') ?? 0));
        $active_only = (bool)($r->get_param('active_only') ?? false);

        $where = ['1=1']; $args = [];
        if ($branch_id) { $where[] = 'k.branch_id=%d'; $args[] = $branch_id; }
        if ($active_only) { $where[] = 'k.is_active=1'; }
        $where_sql = implode(' AND ', $where);

        $sql = "SELECT k.*, b.code as branch_code, b.name as branch_name
                FROM {$wpdb->prefix}opb_kennels k
                JOIN {$wpdb->prefix}opb_branches b ON b.id=k.branch_id
                WHERE $where_sql
                ORDER BY k.branch_id, k.sort_order, k.code";

        $rows = empty($args)
            ? $wpdb->get_results($sql, ARRAY_A)
            : $wpdb->get_results($wpdb->prepare($sql, ...$args), ARRAY_A);

        return $this->success($rows);
    }

    public function create_item( WP_REST_Request $r ): WP_REST_Response|WP_Error {
        $check = $this->permission_manage('opb_manage_settings', $r); if(is_wp_error($check)) return $check;
        global $wpdb;
        $d = $r->get_json_params();

        if (empty($d['branch_id']) || empty($d['code']) || empty($d['name'])) {
            return $this->error('invalid', 'branch_id, code, name required');
        }

        $exists = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}opb_kennels WHERE branch_id=%d AND code=%s",
            (int)$d['branch_id'], sanitize_text_field($d['code'])
        ));
        if ($exists) {
            return $this->error('duplicate', 'Kennel code already exists in this branch', 409);
        }

        $max_order = (int)$wpdb->get_var($wpdb->prepare(
            "SELECT MAX(sort_order) FROM {$wpdb->prefix}opb_kennels WHERE branch_id=%d",
            (int)$d['branch_id']
        ));

        $wpdb->insert("{$wpdb->prefix}opb_kennels", [
            'branch_id'  => (int)$d['branch_id'],
            'code'       => sanitize_text_field($d['code']),
            'name'       => sanitize_text_field($d['name']),
            'status'     => sanitize_text_field($d['status'] ?? 'Available'),
            'notes'      => sanitize_textarea_field($d['notes'] ?? ''),
            'sort_order' => isset($d['sort_order']) ? (int)$d['sort_order'] : $max_order + 1,
            'is_active'  => 1,
        ]);

        $new_id = (int)$wpdb->insert_id;
        return $this->success($this->fetch_kennel($new_id), 201);
    }

    public function update_item( WP_REST_Request $r ): WP_REST_Response|WP_Error {
        $check = $this->permission_manage('opb_manage_settings', $r); if(is_wp_error($check)) return $check;
        global $wpdb;
        $d  = $r->get_json_params();
        $id = (int)$r['id'];

        $existing = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}opb_kennels WHERE id=%d", $id
        ), ARRAY_A);
        if (!$existing) return $this->error('not_found', 'Kennel not found', 404);

        // Check code uniqueness if code is being changed
        if (isset($d['code']) && $d['code'] !== $existing['code']) {
            $conflict = $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM {$wpdb->prefix}opb_kennels WHERE branch_id=%d AND code=%s AND id!=%d",
                (int)$existing['branch_id'], sanitize_text_field($d['code']), $id
            ));
            if ($conflict) return $this->error('duplicate', 'Kennel code already exists in this branch', 409);
        }

        $allowed = ['code', 'name', 'status', 'notes', 'sort_order', 'is_active'];
        $update  = [];
        foreach ($allowed as $k) {
            if (!array_key_exists($k, $d)) continue;
            $update[$k] = in_array($k, ['notes']) ? sanitize_textarea_field($d[$k]) : sanitize_text_field((string)$d[$k]);
            if (in_array($k, ['sort_order', 'is_active'])) $update[$k] = (int)$d[$k];
        }

        if ($update) $wpdb->update("{$wpdb->prefix}opb_kennels", $update, ['id' => $id]);
        return $this->success($this->fetch_kennel($id));
    }

    public function delete_item( WP_REST_Request $r ): WP_REST_Response|WP_Error {
        $check = $this->permission_manage('opb_manage_settings', $r); if(is_wp_error($check)) return $check;
        global $wpdb;
        $id = (int)$r['id'];
        $wpdb->update("{$wpdb->prefix}opb_kennels", ['is_active' => 0], ['id' => $id]);
        return $this->success(['disabled' => true]);
    }

    public function reorder( WP_REST_Request $r ): WP_REST_Response|WP_Error {
        $check = $this->permission_manage('opb_manage_settings', $r); if(is_wp_error($check)) return $check;
        global $wpdb;
        $d = $r->get_json_params();
        // Expects: [{ id: 1, sort_order: 0 }, { id: 2, sort_order: 1 }, ...]
        foreach ((array)($d['items'] ?? []) as $item) {
            $wpdb->update("{$wpdb->prefix}opb_kennels",
                ['sort_order' => (int)$item['sort_order']],
                ['id'         => (int)$item['id']]
            );
        }
        return $this->success(['reordered' => true]);
    }

    private function fetch_kennel( int $id ): array|null {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare(
            "SELECT k.*, b.code as branch_code, b.name as branch_name
             FROM {$wpdb->prefix}opb_kennels k
             JOIN {$wpdb->prefix}opb_branches b ON b.id=k.branch_id
             WHERE k.id=%d", $id
        ), ARRAY_A);
    }
}
