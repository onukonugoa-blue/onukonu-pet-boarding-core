<?php
class OPB_Import_API extends OPB_REST_Base {

    public function register_routes(): void {
        register_rest_route( $this->namespace, '/import/dry-run', [
            [ 'methods' => 'POST', 'callback' => [ $this, 'dry_run' ], 'permission_callback' => fn($r)=>$this->permission_manage('opb_run_import',$r) ],
        ]);
        register_rest_route( $this->namespace, '/import/run', [
            [ 'methods' => 'POST', 'callback' => [ $this, 'run' ], 'permission_callback' => fn($r)=>$this->permission_manage('opb_run_import',$r) ],
        ]);
        register_rest_route( $this->namespace, '/import/status', [
            [ 'methods' => 'GET', 'callback' => [ $this, 'status' ], 'permission_callback' => fn($r)=>$this->permission_manage('opb_run_import',$r) ],
        ]);
    }

    public function dry_run( WP_REST_Request $r ): WP_REST_Response|WP_Error {
        $check = $this->permission_manage('opb_run_import',$r); if(is_wp_error($check)) return $check;

        if(!function_exists('wp_handle_upload')) require_once ABSPATH.'wp-admin/includes/file.php';
        $files  = $r->get_file_params();
        $entity = sanitize_text_field($r->get_param('entity')??'clients');

        if(empty($files['file'])) return $this->error('invalid','No file uploaded');

        $overrides = ['test_form'=>false,'test_type'=>false,'mimes'=>['csv'=>'text/csv','xlsx'=>'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet']];
        $_FILES['file'] = $files['file'];
        $uploaded = wp_handle_upload($_FILES['file'],$overrides);
        if(isset($uploaded['error'])) return $this->error('upload_error',$uploaded['error']);

        $result = $this->parse_file($uploaded['file'],$entity,true);
        @unlink($uploaded['file']);
        return $this->success($result);
    }

    public function run( WP_REST_Request $r ): WP_REST_Response|WP_Error {
        $check = $this->permission_manage('opb_run_import',$r); if(is_wp_error($check)) return $check;

        if(!function_exists('wp_handle_upload')) require_once ABSPATH.'wp-admin/includes/file.php';
        $files  = $r->get_file_params();
        $entity = sanitize_text_field($r->get_param('entity')??'clients');

        if(empty($files['file'])) return $this->error('invalid','No file uploaded');

        $overrides = ['test_form'=>false,'test_type'=>false];
        $_FILES['file'] = $files['file'];
        $uploaded = wp_handle_upload($_FILES['file'],$overrides);
        if(isset($uploaded['error'])) return $this->error('upload_error',$uploaded['error']);

        @set_time_limit(300);
        $result = $this->parse_file($uploaded['file'],$entity,false);
        @unlink($uploaded['file']);

        update_option('opb_last_import_'.sanitize_key($entity), [
            'timestamp' => current_time('mysql'),
            'user'      => get_current_user_id(),
            'result'    => $result,
        ]);

        return $this->success($result);
    }

    public function status( WP_REST_Request $r ): WP_REST_Response|WP_Error {
        $check = $this->permission_manage('opb_run_import',$r); if(is_wp_error($check)) return $check;
        global $wpdb;
        return $this->success([
            'branches'  => (int)$wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}opb_branches"),
            'clients'   => (int)$wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}opb_clients"),
            'pets'      => (int)$wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}opb_pets"),
            'bookings'  => (int)$wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}opb_bookings"),
            'invoices'  => (int)$wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}opb_invoices"),
            'payments'  => (int)$wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}opb_payments"),
            'expenses'  => (int)$wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}opb_expenses"),
        ]);
    }

    private function parse_file( string $path, string $entity, bool $dry ): array {
        $ext = strtolower(pathinfo($path,PATHINFO_EXTENSION));
        $rows = $ext==='csv' ? $this->read_csv($path) : $this->read_csv($path);

        return match($entity) {
            'clients'  => $this->import_clients($rows,$dry),
            'bookings' => $this->import_bookings($rows,$dry),
            'expenses' => $this->import_expenses($rows,$dry),
            default    => ['error'=>"Unknown entity: $entity",'imported'=>0,'skipped'=>0,'errors'=>[]],
        };
    }

    private function read_csv( string $path ): array {
        $rows=[];
        if(($h=fopen($path,'r'))!==false){
            $headers=null;
            while(($line=fgetcsv($h,0,','))!==false){
                if(!$headers){ $headers=array_map('trim',$line); continue; }
                $rows[]=array_combine($headers,$line);
            }
            fclose($h);
        }
        return $rows;
    }

    private function import_clients( array $rows, bool $dry ): array {
        global $wpdb;
        $imported=0; $skipped=0; $errors=[];

        foreach($rows as $i=>$row){
            $phone = trim($row['Phone']??$row['phone']??'');
            $name  = trim($row['Name']??$row['name']??'');
            if(!$phone||!$name){ $errors[]="Row $i: missing name or phone"; $skipped++; continue; }

            $branch_code = trim($row['Home Outlet']??$row['home_outlet']??'H2');
            $branch_id   = (int)$wpdb->get_var($wpdb->prepare(
                "SELECT id FROM {$wpdb->prefix}opb_branches WHERE code=%s",$branch_code
            ));
            if(!$branch_id){ $errors[]="Row $i: branch '$branch_code' not found"; $skipped++; continue; }

            $existing = (int)$wpdb->get_var($wpdb->prepare(
                "SELECT id FROM {$wpdb->prefix}opb_clients WHERE phone=%s",$phone
            ));
            if($existing){ $skipped++; continue; }

            if(!$dry){
                $wpdb->insert("{$wpdb->prefix}opb_clients",[
                    'home_branch_id' => $branch_id,
                    'name'           => sanitize_text_field($name),
                    'phone'          => sanitize_text_field($phone),
                    'email'          => sanitize_email($row['Email']??''),
                    'address'        => sanitize_textarea_field($row['Address']??''),
                    'onboarding_date'=> sanitize_text_field($row['Onboarding Date']??'') ?: null,
                    'tc_accepted'    => 1,
                    'legacy_id'      => isset($row['Pet ID'])?(int)$row['Pet ID']:null,
                    'status'         => 'active',
                ]);
                // Create primary pet if pet name provided
                $pet_name = trim($row['Pet Name']??'');
                if($pet_name&&$wpdb->insert_id){
                    $client_id=(int)$wpdb->insert_id;
                    $wpdb->insert("{$wpdb->prefix}opb_pets",[
                        'client_id'  => $client_id,
                        'name'       => sanitize_text_field($pet_name),
                        'pet_type'   => sanitize_text_field($row['Pet Type']??'Dog'),
                        'breed'      => sanitize_text_field($row['Breed']??''),
                        'breed_size' => sanitize_text_field($row['Breed Size']??''),
                        'gender'     => sanitize_text_field($row['Gender']??'Unknown'),
                        'legacy_id'  => isset($row['Pet ID'])?(int)$row['Pet ID']:null,
                    ]);
                }
            }
            $imported++;
        }

        return ['imported'=>$imported,'skipped'=>$skipped,'errors'=>array_slice($errors,0,50),'total'=>count($rows),'dry_run'=>$dry];
    }

    private function import_bookings( array $rows, bool $dry ): array {
        global $wpdb;
        $imported=0; $skipped=0; $errors=[];
        foreach($rows as $i=>$row){
            $phone = trim($row['Phone']??'');
            if(!$phone){ $errors[]="Row $i: missing phone"; $skipped++; continue; }
            $client_id=(int)$wpdb->get_var($wpdb->prepare(
                "SELECT id FROM {$wpdb->prefix}opb_clients WHERE phone=%s",$phone
            ));
            if(!$client_id){ $errors[]="Row $i: client with phone $phone not found"; $skipped++; continue; }
            if(!$dry){
                $wpdb->insert("{$wpdb->prefix}opb_bookings",[
                    'client_id'    => $client_id,
                    'branch_id'    => (int)($row['branch_id']??1),
                    'booking_date' => sanitize_text_field($row['Booking Date']??date('Y-m-d')),
                    'payment_status'=>sanitize_text_field($row['Payment Status']??'Unpaid'),
                    'total_billing_amount'=>(float)($row['Total']??0),
                    'legacy_id'    => isset($row['ID'])?(int)$row['ID']:null,
                ]);
            }
            $imported++;
        }
        return ['imported'=>$imported,'skipped'=>$skipped,'errors'=>array_slice($errors,0,50),'total'=>count($rows),'dry_run'=>$dry];
    }

    private function import_expenses( array $rows, bool $dry ): array {
        global $wpdb;
        $imported=0; $skipped=0; $errors=[];
        foreach($rows as $i=>$row){
            $desc=(trim($row['Description']??$row['description']??''));
            if(!$desc){ $errors[]="Row $i: missing description"; $skipped++; continue; }
            if(!$dry){
                $branch_code=trim($row['Branch']??$row['branch']??'H2');
                $branch_id=(int)$wpdb->get_var($wpdb->prepare("SELECT id FROM {$wpdb->prefix}opb_branches WHERE code=%s",$branch_code));
                $wpdb->insert("{$wpdb->prefix}opb_expenses",[
                    'branch_id'   => $branch_id ?: 1,
                    'description' => sanitize_text_field($desc),
                    'amount'      => (float)($row['Amount']??$row['amount']??0),
                    'mode'        => sanitize_text_field($row['Mode']??$row['mode']??'Cash'),
                    'category'    => sanitize_text_field($row['Category']??$row['category']??''),
                    'expense_at'  => sanitize_text_field($row['Date']??$row['date']??current_time('Y-m-d H:i:s')),
                ]);
            }
            $imported++;
        }
        return ['imported'=>$imported,'skipped'=>$skipped,'errors'=>array_slice($errors,0,50),'total'=>count($rows),'dry_run'=>$dry];
    }
}
