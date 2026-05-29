<?php
class OPB_Branches_API extends OPB_REST_Base {

    public function register_routes(): void {
        register_rest_route( $this->namespace, '/branches', [
            [ 'methods' => 'GET',  'callback' => [ $this, 'get_items'  ], 'permission_callback' => [ $this, 'permission_check' ] ],
            [ 'methods' => 'POST', 'callback' => [ $this, 'create_item'], 'permission_callback' => fn($r) => $this->permission_manage('opb_manage_settings',$r) ],
        ] );
        register_rest_route( $this->namespace, '/branches/(?P<id>\d+)', [
            [ 'methods' => 'GET', 'callback' => [ $this, 'get_item'   ], 'permission_callback' => [ $this, 'permission_check' ] ],
            [ 'methods' => 'PUT', 'callback' => [ $this, 'update_item'], 'permission_callback' => fn($r) => $this->permission_manage('opb_manage_settings',$r) ],
        ] );
    }

    public function get_items( WP_REST_Request $r ): WP_REST_Response|WP_Error {
        $check = $this->permission_check($r); if(is_wp_error($check)) return $check;
        global $wpdb;
        $rows = $wpdb->get_results("SELECT * FROM {$wpdb->prefix}opb_branches WHERE is_active=1 ORDER BY id", ARRAY_A);
        return $this->success($rows);
    }

    public function get_item( WP_REST_Request $r ): WP_REST_Response|WP_Error {
        $check = $this->permission_check($r); if(is_wp_error($check)) return $check;
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}opb_branches WHERE id=%d",$r['id']),ARRAY_A);
        if(!$row) return $this->error('not_found','Branch not found',404);
        return $this->success($row);
    }

    public function create_item( WP_REST_Request $r ): WP_REST_Response|WP_Error {
        $check = $this->permission_manage('opb_manage_settings',$r); if(is_wp_error($check)) return $check;
        global $wpdb;
        $data = $r->get_json_params();
        if(empty($data['code'])||empty($data['name'])||empty($data['location'])) return $this->error('invalid','code, name, location required');
        $wpdb->insert("{$wpdb->prefix}opb_branches",[
            'code'     => sanitize_text_field($data['code']),
            'name'     => sanitize_text_field($data['name']),
            'location' => sanitize_text_field($data['location']),
            'address'  => sanitize_textarea_field($data['address']??''),
            'phone'    => sanitize_text_field($data['phone']??''),
            'email'    => sanitize_email($data['email']??''),
        ]);
        return $this->success($wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}opb_branches WHERE id=%d",$wpdb->insert_id),ARRAY_A),201);
    }

    public function update_item( WP_REST_Request $r ): WP_REST_Response|WP_Error {
        $check = $this->permission_manage('opb_manage_settings',$r); if(is_wp_error($check)) return $check;
        global $wpdb;
        $data = $r->get_json_params();
        $allowed = ['name','location','address','phone','email','is_active','whatsapp_templates'];
        $update  = [];
        foreach($allowed as $k){ if(isset($data[$k])) $update[$k] = $data[$k]; }
        if(empty($update)) return $this->error('invalid','No fields to update');
        $wpdb->update("{$wpdb->prefix}opb_branches",$update,['id'=>(int)$r['id']]);
        return $this->success($wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}opb_branches WHERE id=%d",$r['id']),ARRAY_A));
    }
}
