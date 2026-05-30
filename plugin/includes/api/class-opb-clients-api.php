<?php
class OPB_Clients_API extends OPB_REST_Base {

    public function register_routes(): void {
        register_rest_route( $this->namespace, '/clients', [
            [ 'methods' => 'GET',  'callback' => [ $this, 'get_items'  ], 'permission_callback' => [ $this, 'permission_check' ] ],
            [ 'methods' => 'POST', 'callback' => [ $this, 'create_item'], 'permission_callback' => fn($r)=>$this->permission_manage('opb_manage_clients',$r) ],
        ]);
        register_rest_route( $this->namespace, '/clients/(?P<id>\d+)', [
            [ 'methods' => 'GET', 'callback' => [ $this, 'get_item'   ], 'permission_callback' => [ $this, 'permission_check' ] ],
            [ 'methods' => 'PUT', 'callback' => [ $this, 'update_item'], 'permission_callback' => fn($r)=>$this->permission_manage('opb_manage_clients',$r) ],
        ]);
        register_rest_route( $this->namespace, '/clients/(?P<id>\d+)/pets', [
            [ 'methods' => 'GET',  'callback' => [ $this, 'get_pets'   ], 'permission_callback' => [ $this, 'permission_check' ] ],
            [ 'methods' => 'POST', 'callback' => [ $this, 'create_pet' ], 'permission_callback' => fn($r)=>$this->permission_manage('opb_manage_pets',$r) ],
        ]);
        register_rest_route( $this->namespace, '/clients/(?P<id>\d+)/bookings', [
            [ 'methods' => 'GET', 'callback' => [ $this, 'get_bookings' ], 'permission_callback' => [ $this, 'permission_check' ] ],
        ]);
    }

    public function get_items( $r ) {
        $check = $this->permission_check($r); if(is_wp_error($check)) return $check;
        global $wpdb;

        $branch_id = $this->branch_filter( (int)($r->get_param('branch_id')??0) );
        $search    = sanitize_text_field( $r->get_param('search') ?? '' );
        $status    = sanitize_text_field( $r->get_param('status') ?? '' );
        $page      = max(1,(int)($r->get_param('page')??1));
        $per_page  = min(100,(int)($r->get_param('per_page')??50));
        $offset    = ($page-1)*$per_page;

        $where = ['1=1'];
        $args  = [];

        if($branch_id){
            $where[] = 'c.home_branch_id = %d';
            $args[]  = $branch_id;
        }
        if($status){
            $where[] = 'c.status = %s';
            $args[]  = $status;
        }
        if($search){
            $like    = '%'.esc_sql($search).'%';
            $where[] = '(c.name LIKE %s OR c.phone LIKE %s OR c.email LIKE %s)';
            $args    = array_merge($args,[$like,$like,$like]);
        }

        $where_sql = implode(' AND ',$where);

        $total = (int)$wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}opb_clients c WHERE $where_sql",
            ...$args
        ));

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT c.*, b.name as branch_name,
                    (SELECT COUNT(*) FROM {$wpdb->prefix}opb_pets p WHERE p.client_id=c.id AND p.is_active=1) as pet_count,
                    (SELECT MAX(bk.booking_date) FROM {$wpdb->prefix}opb_bookings bk WHERE bk.client_id=c.id) as last_booking
             FROM {$wpdb->prefix}opb_clients c
             LEFT JOIN {$wpdb->prefix}opb_branches b ON b.id=c.home_branch_id
             WHERE $where_sql
             ORDER BY c.name ASC
             LIMIT %d OFFSET %d",
            ...[...$args,$per_page,$offset]
        ), ARRAY_A);

        return $this->success($this->paginate($rows,$total,$page,$per_page));
    }

    public function get_item( $r ) {
        $check = $this->permission_check($r); if(is_wp_error($check)) return $check;
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT c.*, b.name as branch_name, b.code as branch_code
             FROM {$wpdb->prefix}opb_clients c
             LEFT JOIN {$wpdb->prefix}opb_branches b ON b.id=c.home_branch_id
             WHERE c.id=%d",(int)$r['id']
        ),ARRAY_A);
        if(!$row) return $this->error('not_found','Client not found',404);
        return $this->success($row);
    }

    public function create_item( $r ) {
        $check = $this->permission_manage('opb_manage_clients',$r); if(is_wp_error($check)) return $check;
        global $wpdb;
        $d = $r->get_json_params();
        if(empty($d['name'])||empty($d['phone'])||empty($d['home_branch_id'])){
            return $this->error('invalid','name, phone, home_branch_id required');
        }
        $wpdb->insert("{$wpdb->prefix}opb_clients",[
            'home_branch_id'         => (int)$d['home_branch_id'],
            'name'                   => sanitize_text_field($d['name']),
            'phone'                  => sanitize_text_field($d['phone']),
            'email'                  => sanitize_email($d['email']??''),
            'address'                => sanitize_textarea_field($d['address']??''),
            'local_guardian_name'    => sanitize_text_field($d['local_guardian_name']??''),
            'local_guardian_contact' => sanitize_text_field($d['local_guardian_contact']??''),
            'onboarding_date'        => $d['onboarding_date'] ?? current_time('Y-m-d'),
            'tc_accepted'            => (int)($d['tc_accepted']??0),
            'notes'                  => sanitize_textarea_field($d['notes']??''),
            'status'                 => 'active',
        ]);
        $id = (int)$wpdb->insert_id;
        return $this->get_item( new WP_REST_Request('GET',"/opb/v1/clients/$id",['id'=>$id]) );
    }

    public function update_item( $r ) {
        $check = $this->permission_manage('opb_manage_clients',$r); if(is_wp_error($check)) return $check;
        global $wpdb;
        $d = $r->get_json_params();
        $allowed = ['home_branch_id','name','phone','email','address','local_guardian_name',
                    'local_guardian_contact','onboarding_date','tc_accepted','status','archive_reason','notes'];
        $update = [];
        foreach($allowed as $k){ if(array_key_exists($k,$d)) $update[$k]=$d[$k]; }
        if($update) $wpdb->update("{$wpdb->prefix}opb_clients",$update,['id'=>(int)$r['id']]);
        return $this->get_item($r);
    }

    public function get_pets( WP_REST_Request $r ): WP_REST_Response|WP_Error {
        $check = $this->permission_check($r); if(is_wp_error($check)) return $check;
        global $wpdb;
        $pets = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}opb_pets WHERE client_id=%d AND is_active=1 ORDER BY name",(int)$r['id']
        ),ARRAY_A);
        return $this->success($pets);
    }

    public function create_pet( WP_REST_Request $r ): WP_REST_Response|WP_Error {
        $check = $this->permission_manage('opb_manage_pets',$r); if(is_wp_error($check)) return $check;
        global $wpdb;
        $d = $r->get_json_params();
        if(empty($d['name'])||empty($d['pet_type'])){
            return $this->error('invalid','name, pet_type required');
        }
        $fields = ['client_id','name','pet_type','breed','gender','breed_size','coat','weight_kg','birthday',
                   'microchip_number','neutered_or_spayed','vaccination_status','anti_rabies_date','dhppil_date',
                   'corona_date','kennel_cough_date','tick_prevention','last_tick_prevention_date',
                   'tick_prevention_method','ongoing_medication','medication_detail','major_illness_history',
                   'deworming_date','vet_name','vet_contact','dietary_preference','additional_meals',
                   'preferences_or_allergies','first_walk_schedule','second_walk_schedule','third_walk_schedule',
                   'consent_photos','social_media_handle','special_occasion','special_occasion_date','last_heat_month','last_heat_year'];
        $insert = ['client_id' => (int)$r['id']];
        foreach($fields as $f){ if(isset($d[$f])) $insert[$f]=$d[$f]; }
        $wpdb->insert("{$wpdb->prefix}opb_pets",$insert);
        $pet_id = (int)$wpdb->insert_id;
        $pet = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}opb_pets WHERE id=%d",$pet_id),ARRAY_A);
        return $this->success($pet,201);
    }

    public function get_bookings( WP_REST_Request $r ): WP_REST_Response|WP_Error {
        $check = $this->permission_check($r); if(is_wp_error($check)) return $check;
        global $wpdb;
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT bk.*, b.name as branch_name,
                    GROUP_CONCAT(DISTINCT p.name ORDER BY p.name SEPARATOR ', ') as pet_names
             FROM {$wpdb->prefix}opb_bookings bk
             LEFT JOIN {$wpdb->prefix}opb_branches b ON b.id=bk.branch_id
             LEFT JOIN {$wpdb->prefix}opb_booking_stays bs ON bs.booking_id=bk.id
             LEFT JOIN {$wpdb->prefix}opb_pets p ON p.id=bs.pet_id
             WHERE bk.client_id=%d
             GROUP BY bk.id
             ORDER BY bk.booking_date DESC
             LIMIT 50",(int)$r['id']
        ),ARRAY_A);
        return $this->success($rows);
    }
}
