<?php
class OPB_Pets_API extends OPB_REST_Base {

    public function register_routes(): void {
        register_rest_route( $this->namespace, '/pets/(?P<id>\d+)', [
            [ 'methods' => 'GET', 'callback' => [ $this, 'get_item'   ], 'permission_callback' => [ $this, 'permission_check' ] ],
            [ 'methods' => 'PUT', 'callback' => [ $this, 'update_item'], 'permission_callback' => fn($r)=>$this->permission_manage('opb_manage_pets',$r) ],
        ]);
        register_rest_route( $this->namespace, '/pets/(?P<id>\d+)/documents', [
            [ 'methods' => 'GET',  'callback' => [ $this, 'get_documents'   ], 'permission_callback' => [ $this, 'permission_check' ] ],
            [ 'methods' => 'POST', 'callback' => [ $this, 'upload_document' ], 'permission_callback' => fn($r)=>$this->permission_manage('opb_manage_pets',$r) ],
        ]);
        register_rest_route( $this->namespace, '/pets/(?P<id>\d+)/documents/(?P<doc_id>\d+)', [
            [ 'methods' => 'DELETE', 'callback' => [ $this, 'delete_document' ], 'permission_callback' => fn($r)=>$this->permission_manage('opb_manage_pets',$r) ],
        ]);
    }

    public function get_item( $r ) {
        $check = $this->permission_check($r); if(is_wp_error($check)) return $check;
        global $wpdb;
        $pet = $wpdb->get_row($wpdb->prepare(
            "SELECT p.*, c.name as client_name, c.phone as client_phone, b.name as branch_name
             FROM {$wpdb->prefix}opb_pets p
             JOIN {$wpdb->prefix}opb_clients c ON c.id=p.client_id
             JOIN {$wpdb->prefix}opb_branches b ON b.id=c.home_branch_id
             WHERE p.id=%d",(int)$r['id']
        ),ARRAY_A);
        if(!$pet) return $this->error('not_found','Pet not found',404);
        $docs = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}opb_pet_documents WHERE pet_id=%d ORDER BY doc_type,seq_number",(int)$r['id']
        ),ARRAY_A);
        $pet['documents'] = $docs;
        return $this->success($pet);
    }

    public function update_item( $r ) {
        $check = $this->permission_manage('opb_manage_pets',$r); if(is_wp_error($check)) return $check;
        global $wpdb;
        $d = $r->get_json_params();
        $allowed = ['name','pet_type','breed','gender','breed_size','coat','weight_kg','birthday',
                    'microchip_number','neutered_or_spayed','last_heat_month','last_heat_year',
                    'adoption_status','social_media_handle','consent_photos','special_occasion',
                    'special_occasion_date','vaccination_status','anti_rabies_date','dhppil_date',
                    'corona_date','kennel_cough_date','tick_prevention','last_tick_prevention_date',
                    'tick_prevention_method','ongoing_medication','medication_detail','major_illness_history',
                    'deworming_date','vet_name','vet_contact','dietary_preference','additional_meals',
                    'preferences_or_allergies','first_walk_schedule','second_walk_schedule','third_walk_schedule','is_active'];
        $update = [];
        foreach($allowed as $k){ if(array_key_exists($k,$d)) $update[$k]=$d[$k]; }
        if($update) $wpdb->update("{$wpdb->prefix}opb_pets",$update,['id'=>(int)$r['id']]);
        return $this->get_item($r);
    }

    public function get_documents( WP_REST_Request $r ): WP_REST_Response|WP_Error {
        $check = $this->permission_check($r); if(is_wp_error($check)) return $check;
        global $wpdb;
        $docs = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}opb_pet_documents WHERE pet_id=%d ORDER BY doc_type,seq_number",(int)$r['id']
        ),ARRAY_A);
        return $this->success($docs);
    }

    public function upload_document( WP_REST_Request $r ): WP_REST_Response|WP_Error {
        $check = $this->permission_manage('opb_manage_pets',$r); if(is_wp_error($check)) return $check;
        global $wpdb;

        if(!function_exists('wp_handle_upload')) require_once ABSPATH.'wp-admin/includes/file.php';
        if(!function_exists('wp_generate_attachment_metadata')) require_once ABSPATH.'wp-admin/includes/image.php';
        if(!function_exists('media_handle_upload')) require_once ABSPATH.'wp-admin/includes/media.php';

        $files = $r->get_file_params();
        if(empty($files['file'])) return $this->error('invalid','No file uploaded');

        $_FILES['file'] = $files['file'];
        $attachment_id  = media_handle_upload('file',0);
        if(is_wp_error($attachment_id)) return $attachment_id;

        $file_url  = wp_get_attachment_url($attachment_id);
        $file_mime = get_post_mime_type($attachment_id);
        $doc_type  = sanitize_text_field($r->get_param('doc_type')?:'photo');
        $label     = sanitize_text_field($r->get_param('label')?:'');

        // Next sequence number
        $seq = (int)$wpdb->get_var($wpdb->prepare(
            "SELECT COALESCE(MAX(seq_number),0)+1 FROM {$wpdb->prefix}opb_pet_documents WHERE pet_id=%d AND doc_type=%s",
            (int)$r['id'],$doc_type
        ));

        $wpdb->insert("{$wpdb->prefix}opb_pet_documents",[
            'pet_id'      => (int)$r['id'],
            'doc_type'    => $doc_type,
            'label'       => $label,
            'file_url'    => $file_url,
            'file_mime'   => $file_mime,
            'seq_number'  => $seq,
            'uploaded_by' => get_current_user_id(),
        ]);
        $doc = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}opb_pet_documents WHERE id=%d",$wpdb->insert_id
        ),ARRAY_A);
        return $this->success($doc,201);
    }

    public function delete_document( WP_REST_Request $r ): WP_REST_Response|WP_Error {
        $check = $this->permission_manage('opb_manage_pets',$r); if(is_wp_error($check)) return $check;
        global $wpdb;
        $wpdb->delete("{$wpdb->prefix}opb_pet_documents",['id'=>(int)$r['doc_id'],'pet_id'=>(int)$r['id']]);
        return $this->success(['deleted'=>true]);
    }
}
