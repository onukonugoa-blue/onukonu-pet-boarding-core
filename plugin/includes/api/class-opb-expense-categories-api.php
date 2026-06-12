<?php
class OPB_Expense_Categories_API extends OPB_REST_Base {

    public function register_routes(): void {
        register_rest_route( $this->namespace, '/expense-categories', [
            [ 'methods' => 'GET',  'callback' => [ $this, 'get_items'  ], 'permission_callback' => [ $this, 'permission_check' ] ],
            [ 'methods' => 'POST', 'callback' => [ $this, 'create_item'], 'permission_callback' => fn($r)=>$this->permission_manage('opb_manage_settings',$r) ],
        ]);
        register_rest_route( $this->namespace, '/expense-categories/(?P<id>\d+)', [
            [ 'methods' => 'PUT',    'callback' => [ $this, 'update_item' ], 'permission_callback' => fn($r)=>$this->permission_manage('opb_manage_settings',$r) ],
            [ 'methods' => 'DELETE', 'callback' => [ $this, 'archive_item'], 'permission_callback' => fn($r)=>$this->permission_manage('opb_manage_settings',$r) ],
        ]);
    }

    public function get_items( $r ) {
        $check = $this->permission_check($r); if(is_wp_error($check)) return $check;
        global $wpdb;
        $include_archived = filter_var($r->get_param('include_archived') ?? false, FILTER_VALIDATE_BOOLEAN);
        $where = $include_archived ? '' : 'WHERE is_active=1';
        $rows  = $wpdb->get_results(
            "SELECT id, name, is_active, sort_order, created_at
             FROM {$wpdb->prefix}opb_expense_categories
             $where
             ORDER BY sort_order ASC, name ASC",
            ARRAY_A
        );
        foreach ( $rows as &$row ) { $row['is_active'] = (int)$row['is_active']; $row['sort_order'] = (int)$row['sort_order']; }
        return $this->success( $rows );
    }

    public function create_item( $r ) {
        $check = $this->permission_manage('opb_manage_settings',$r); if(is_wp_error($check)) return $check;
        global $wpdb;
        $d    = $r->get_json_params();
        $name = trim( sanitize_text_field( $d['name'] ?? '' ) );
        if ( ! $name ) return $this->error('invalid', 'name is required', 400);

        $exists = $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}opb_expense_categories WHERE name=%s LIMIT 1", $name
        ));
        if ( $exists ) return $this->error('duplicate', 'A category with this name already exists.', 409);

        $wpdb->insert( "{$wpdb->prefix}opb_expense_categories", [
            'name'       => $name,
            'is_active'  => 1,
            'sort_order' => (int)($d['sort_order'] ?? 0),
        ], ['%s','%d','%d'] );

        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT id, name, is_active, sort_order, created_at FROM {$wpdb->prefix}opb_expense_categories WHERE id=%d",
            $wpdb->insert_id
        ), ARRAY_A );
        $row['is_active'] = (int)$row['is_active'];
        return $this->success( $row, 201 );
    }

    public function update_item( $r ) {
        $check = $this->permission_manage('opb_manage_settings',$r); if(is_wp_error($check)) return $check;
        global $wpdb;
        $id = (int)$r['id'];
        $d  = $r->get_json_params();

        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}opb_expense_categories WHERE id=%d", $id
        ), ARRAY_A );
        if ( ! $row ) return $this->error('not_found', 'Category not found', 404);

        $update  = [];
        $formats = [];

        if ( array_key_exists('name', $d) ) {
            $name = trim( sanitize_text_field( $d['name'] ) );
            if ( ! $name ) return $this->error('invalid', 'name cannot be empty', 400);
            $dup = $wpdb->get_var( $wpdb->prepare(
                "SELECT id FROM {$wpdb->prefix}opb_expense_categories WHERE name=%s AND id!=%d LIMIT 1", $name, $id
            ));
            if ( $dup ) return $this->error('duplicate', 'A category with this name already exists.', 409);
            $update['name'] = $name; $formats[] = '%s';
        }
        if ( array_key_exists('sort_order', $d) ) { $update['sort_order'] = (int)$d['sort_order']; $formats[] = '%d'; }
        if ( array_key_exists('is_active',  $d) ) { $update['is_active']  = $d['is_active'] ? 1 : 0; $formats[] = '%d'; }

        if ( empty($update) ) return $this->error('invalid', 'Nothing to update', 400);

        $wpdb->update( "{$wpdb->prefix}opb_expense_categories", $update, ['id'=>$id], $formats, ['%d'] );
        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT id, name, is_active, sort_order, created_at FROM {$wpdb->prefix}opb_expense_categories WHERE id=%d", $id
        ), ARRAY_A );
        $row['is_active'] = (int)$row['is_active'];
        return $this->success( $row );
    }

    public function archive_item( $r ) {
        $check = $this->permission_manage('opb_manage_settings',$r); if(is_wp_error($check)) return $check;
        global $wpdb;
        $id = (int)$r['id'];
        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}opb_expense_categories WHERE id=%d", $id
        ), ARRAY_A );
        if ( ! $row ) return $this->error('not_found', 'Category not found', 404);
        $wpdb->update( "{$wpdb->prefix}opb_expense_categories", ['is_active'=>0], ['id'=>$id], ['%d'], ['%d'] );
        return $this->success(['archived'=>true, 'id'=>$id]);
    }
}
