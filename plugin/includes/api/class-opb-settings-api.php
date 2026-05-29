<?php
class OPB_Settings_API extends OPB_REST_Base {

    public function register_routes(): void {
        register_rest_route( $this->namespace, '/settings/boarding', [
            [ 'methods' => 'GET',  'callback' => [ $this, 'get_boarding'   ], 'permission_callback' => [ $this, 'permission_check' ] ],
            [ 'methods' => 'POST', 'callback' => [ $this, 'create_boarding'], 'permission_callback' => fn($r)=>$this->permission_manage('opb_manage_settings',$r) ],
        ]);
        register_rest_route( $this->namespace, '/settings/boarding/(?P<id>\d+)', [
            [ 'methods' => 'PUT',    'callback' => [ $this, 'update_boarding'], 'permission_callback' => fn($r)=>$this->permission_manage('opb_manage_settings',$r) ],
            [ 'methods' => 'DELETE', 'callback' => [ $this, 'delete_boarding'], 'permission_callback' => fn($r)=>$this->permission_manage('opb_manage_settings',$r) ],
        ]);
        register_rest_route( $this->namespace, '/settings/addons', [
            [ 'methods' => 'GET',  'callback' => [ $this, 'get_addons'   ], 'permission_callback' => [ $this, 'permission_check' ] ],
            [ 'methods' => 'POST', 'callback' => [ $this, 'create_addon' ], 'permission_callback' => fn($r)=>$this->permission_manage('opb_manage_settings',$r) ],
        ]);
        register_rest_route( $this->namespace, '/settings/addons/(?P<id>\d+)', [
            [ 'methods' => 'PUT',    'callback' => [ $this, 'update_addon'], 'permission_callback' => fn($r)=>$this->permission_manage('opb_manage_settings',$r) ],
            [ 'methods' => 'DELETE', 'callback' => [ $this, 'delete_addon'], 'permission_callback' => fn($r)=>$this->permission_manage('opb_manage_settings',$r) ],
        ]);
        register_rest_route( $this->namespace, '/settings/staff', [
            [ 'methods' => 'GET', 'callback' => [ $this, 'get_staff' ], 'permission_callback' => fn($r)=>$this->permission_manage('opb_manage_users',$r) ],
        ]);
        register_rest_route( $this->namespace, '/settings/staff/(?P<id>\d+)', [
            [ 'methods' => 'PUT', 'callback' => [ $this, 'update_staff' ], 'permission_callback' => fn($r)=>$this->permission_manage('opb_manage_users',$r) ],
        ]);
    }

    public function get_boarding( WP_REST_Request $r ): WP_REST_Response|WP_Error {
        $check = $this->permission_check($r); if(is_wp_error($check)) return $check;
        global $wpdb;
        $branch_id = $this->branch_filter((int)($r->get_param('branch_id')??0));
        $where='1=1'; $args=[];
        if($branch_id){ $where.=' AND branch_id=%d'; $args[]=$branch_id; }
        $rows=$wpdb->get_results(empty($args)?
            "SELECT * FROM {$wpdb->prefix}opb_boarding_services WHERE $where ORDER BY branch_id,catalogue_name,sort_order":
            $wpdb->prepare("SELECT * FROM {$wpdb->prefix}opb_boarding_services WHERE $where ORDER BY branch_id,catalogue_name,sort_order",...$args),ARRAY_A);
        return $this->success($rows);
    }

    public function create_boarding( WP_REST_Request $r ): WP_REST_Response|WP_Error {
        $check = $this->permission_manage('opb_manage_settings',$r); if(is_wp_error($check)) return $check;
        global $wpdb;
        $d=$r->get_json_params();
        if(empty($d['branch_id'])||empty($d['catalogue_name'])||empty($d['boarding_type'])||empty($d['pet_type'])||empty($d['row_type'])){
            return $this->error('invalid','branch_id, catalogue_name, boarding_type, pet_type, row_type required');
        }
        $allowed=['branch_id','catalogue_name','boarding_type','pet_type','row_type','amount','discount_type',
                  'breed_size','kennel_category','meal_name','meal_type','price_type','modifies_base_bill',
                  'min_pets','days','min_age_months','max_age_months','breed','extra_info','is_active','sort_order'];
        $insert=[];
        foreach($allowed as $k){ if(isset($d[$k])) $insert[$k]=$d[$k]; }
        $wpdb->insert("{$wpdb->prefix}opb_boarding_services",$insert);
        return $this->success($wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}opb_boarding_services WHERE id=%d",$wpdb->insert_id),ARRAY_A),201);
    }

    public function update_boarding( WP_REST_Request $r ): WP_REST_Response|WP_Error {
        $check = $this->permission_manage('opb_manage_settings',$r); if(is_wp_error($check)) return $check;
        global $wpdb;
        $d=$r->get_json_params();
        $allowed=['catalogue_name','boarding_type','pet_type','row_type','amount','discount_type','breed_size',
                  'kennel_category','meal_name','meal_type','price_type','modifies_base_bill','min_pets',
                  'days','min_age_months','max_age_months','breed','extra_info','is_active','sort_order'];
        $update=[];
        foreach($allowed as $k){ if(array_key_exists($k,$d)) $update[$k]=$d[$k]; }
        if($update) $wpdb->update("{$wpdb->prefix}opb_boarding_services",$update,['id'=>(int)$r['id']]);
        return $this->success($wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}opb_boarding_services WHERE id=%d",(int)$r['id']),ARRAY_A));
    }

    public function delete_boarding( WP_REST_Request $r ): WP_REST_Response|WP_Error {
        $check = $this->permission_manage('opb_manage_settings',$r); if(is_wp_error($check)) return $check;
        global $wpdb;
        $wpdb->update("{$wpdb->prefix}opb_boarding_services",['is_active'=>0],['id'=>(int)$r['id']]);
        return $this->success(['deleted'=>true]);
    }

    public function get_addons( WP_REST_Request $r ): WP_REST_Response|WP_Error {
        $check = $this->permission_check($r); if(is_wp_error($check)) return $check;
        global $wpdb;
        $branch_id=$this->branch_filter((int)($r->get_param('branch_id')??0));
        $where='is_active=1'; $args=[];
        if($branch_id){ $where.=' AND branch_id=%d'; $args[]=$branch_id; }
        $rows=$wpdb->get_results(empty($args)?
            "SELECT * FROM {$wpdb->prefix}opb_addon_services WHERE $where ORDER BY sort_order,name":
            $wpdb->prepare("SELECT * FROM {$wpdb->prefix}opb_addon_services WHERE $where ORDER BY sort_order,name",...$args),ARRAY_A);
        return $this->success($rows);
    }

    public function create_addon( WP_REST_Request $r ): WP_REST_Response|WP_Error {
        $check = $this->permission_manage('opb_manage_settings',$r); if(is_wp_error($check)) return $check;
        global $wpdb;
        $d=$r->get_json_params();
        if(empty($d['branch_id'])||empty($d['name'])) return $this->error('invalid','branch_id, name required');
        $wpdb->insert("{$wpdb->prefix}opb_addon_services",[
            'branch_id'            => (int)$d['branch_id'],
            'name'                 => sanitize_text_field($d['name']),
            'description'          => sanitize_textarea_field($d['description']??''),
            'service_type'         => sanitize_text_field($d['service_type']??'FLAT'),
            'base_amount'          => (float)($d['base_amount']??0),
            'visibility'           => sanitize_text_field($d['visibility']??'PUBLIC'),
            'applicable_services'  => sanitize_text_field($d['applicable_services']??''),
            'distance_up_to'       => isset($d['distance_up_to'])?(float)$d['distance_up_to']:null,
            'distance_slab_amount' => isset($d['distance_slab_amount'])?(float)$d['distance_slab_amount']:null,
            'sort_order'           => (int)($d['sort_order']??0),
        ]);
        return $this->success($wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}opb_addon_services WHERE id=%d",$wpdb->insert_id),ARRAY_A),201);
    }

    public function update_addon( WP_REST_Request $r ): WP_REST_Response|WP_Error {
        $check = $this->permission_manage('opb_manage_settings',$r); if(is_wp_error($check)) return $check;
        global $wpdb;
        $d=$r->get_json_params();
        $allowed=['name','description','service_type','base_amount','visibility','applicable_services',
                  'distance_up_to','distance_slab_amount','is_active','sort_order'];
        $update=[];
        foreach($allowed as $k){ if(array_key_exists($k,$d)) $update[$k]=$d[$k]; }
        if($update) $wpdb->update("{$wpdb->prefix}opb_addon_services",$update,['id'=>(int)$r['id']]);
        return $this->success($wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}opb_addon_services WHERE id=%d",(int)$r['id']),ARRAY_A));
    }

    public function delete_addon( WP_REST_Request $r ): WP_REST_Response|WP_Error {
        $check = $this->permission_manage('opb_manage_settings',$r); if(is_wp_error($check)) return $check;
        global $wpdb;
        $wpdb->update("{$wpdb->prefix}opb_addon_services",['is_active'=>0],['id'=>(int)$r['id']]);
        return $this->success(['deleted'=>true]);
    }

    public function get_staff( WP_REST_Request $r ): WP_REST_Response|WP_Error {
        $check = $this->permission_manage('opb_manage_users',$r); if(is_wp_error($check)) return $check;
        $opb_roles = array_keys(OPB_Roles::ROLES);
        $users = get_users(['role__in' => array_merge($opb_roles, ['administrator'])]);
        $out = array_map(function($u) {
            return [
                'id'        => $u->ID,
                'name'      => $u->display_name,
                'email'     => $u->user_email,
                'roles'     => $u->roles,
                'branch_id' => (int)get_user_meta($u->ID,'opb_branch_id',true),
            ];
        }, $users);
        return $this->success($out);
    }

    public function update_staff( WP_REST_Request $r ): WP_REST_Response|WP_Error {
        $check = $this->permission_manage('opb_manage_users',$r); if(is_wp_error($check)) return $check;
        $d    = $r->get_json_params();
        $uid  = (int)$r['id'];
        $user = get_user_by('id',$uid);
        if(!$user) return $this->error('not_found','User not found',404);
        if(isset($d['role'])){
            $valid = array_keys(OPB_Roles::ROLES);
            if(in_array($d['role'],$valid,true)){ $user->set_role($d['role']); }
        }
        if(isset($d['branch_id'])){
            update_user_meta($uid,'opb_branch_id',(int)$d['branch_id']);
        }
        return $this->success(['id'=>$uid,'name'=>$user->display_name,'roles'=>$user->roles,
                               'branch_id'=>(int)get_user_meta($uid,'opb_branch_id',true)]);
    }
}
