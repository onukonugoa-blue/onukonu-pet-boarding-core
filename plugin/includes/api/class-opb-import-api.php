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
        if ( $ext !== 'csv' ) {
            return [
                'error'    => 'XLSX import is not yet supported. Please export your spreadsheet as CSV (.csv) and re-upload.',
                'imported' => 0,
                'skipped'  => 0,
                'errors'   => [],
            ];
        }

        [ 'headers' => $headers, 'rows' => $rows ] = $this->read_csv($path);

        return match($entity) {
            'clients'  => $this->import_clients($rows,$dry,$headers),
            'bookings' => $this->import_bookings($rows,$dry),
            'expenses' => $this->import_expenses($rows,$dry),
            default    => ['error'=>"Unknown entity: $entity",'imported'=>0,'skipped'=>0,'errors'=>[]],
        };
    }

    /**
     * Resolve a CSV column value from a prioritised list of possible header names.
     */
    private function col( array $row, array $keys, string $default = '' ): string {
        foreach ( $keys as $key ) {
            if ( isset( $row[ $key ] ) && trim( $row[ $key ] ) !== '' ) {
                return trim( $row[ $key ] );
            }
        }
        return $default;
    }

    /**
     * Returns ['headers' => string[], 'rows' => array[]].
     * Headers are trimmed and stripped of UTF-8 BOM.
     */
    private function read_csv( string $path ): array {
        $headers = [];
        $rows    = [];

        if ( ($h = fopen($path, 'r')) !== false ) {
            $raw_headers = null;
            while ( ($line = fgetcsv($h, 0, ',')) !== false ) {
                if ( !$raw_headers ) {
                    $raw_headers = array_map('trim', $line);
                    // Strip UTF-8 BOM from first header
                    if ( isset($raw_headers[0]) && str_starts_with($raw_headers[0], "\xEF\xBB\xBF") ) {
                        $raw_headers[0] = substr($raw_headers[0], 3);
                    }
                    $headers = $raw_headers;
                    continue;
                }
                if ( count($line) < count($headers) ) {
                    $line = array_pad($line, count($headers), '');
                }
                $rows[] = array_combine($headers, $line);
            }
            fclose($h);
        }

        return ['headers' => $headers, 'rows' => $rows];
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Diagnostics helpers (dry-run only)
    // ─────────────────────────────────────────────────────────────────────────

    private function analyse_headers( array $headers, array $groups ): array {
        $report = [];
        foreach ( $groups as $label => $aliases ) {
            $matched = null;
            foreach ( $aliases as $alias ) {
                if ( in_array($alias, $headers, true) ) { $matched = $alias; break; }
            }
            $report[$label] = ['matched'=>$matched,'found'=>$matched!==null,'searched'=>$aliases];
        }
        return $report;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Client importer — uses OPB_Branch_Resolver for all branch lookups
    // ─────────────────────────────────────────────────────────────────────────

    private function import_clients( array $rows, bool $dry, array $headers = [] ): array {
        global $wpdb;
        $imported     = 0;
        $skipped      = 0;
        $errors       = [];
        $skipped_rows = [];
        $skip_reasons = [
            'missing_phone'    => 0,
            'missing_name'     => 0,
            'branch_not_found' => 0,
            'duplicate'        => 0,
        ];

        // ── Branch resolver (single DB load for the whole import) ─────────────
        $resolver = OPB_Branch_Resolver::from_db();

        if ( $resolver->is_empty() ) {
            return [
                'error'    => 'No active branches found in the database. Please seed branch records (H2, H3, H4) before importing.',
                'imported' => 0,
                'skipped'  => count($rows),
                'errors'   => [],
                'total'    => count($rows),
                'dry_run'  => $dry,
            ];
        }

        // ── Dry-run: header-level diagnostics ─────────────────────────────────
        $header_diagnostics = null;
        if ( $dry ) {
            $col_analysis = $this->analyse_headers($headers, [
                'phone'          => ['Phone Number','phone number','Phone','phone','Mobile','mobile'],
                'name'           => ['Name','name','Client Name','client_name','Full Name','full_name'],
                'branch'         => ['Home Outlet','home_outlet','Branch','branch','Home Branch'],
                'email'          => ['Email','email'],
                'pet_name'       => ['Pet Name','pet_name'],
                'pet_type'       => ['Pet Type','pet_type'],
                'gender'         => ['Gender','gender'],
                'breed'          => ['Breed','breed'],
                'legacy_id'      => ['Pet ID','pet_id','Legacy ID','legacy_id'],
                'onboarding_date'=> ['Onboarding Date','onboarding_date'],
            ]);

            $missing_required = [];
            foreach ( ['phone','name'] as $req ) {
                if ( !$col_analysis[$req]['found'] ) {
                    $missing_required[] = $req.' (searched: '.implode(', ',$col_analysis[$req]['searched']).')';
                }
            }

            $header_diagnostics = [
                'headers_detected'   => $headers,
                'header_count'       => count($headers),
                'column_analysis'    => $col_analysis,
                'missing_required'   => $missing_required,
                'branch_codes_in_db' => $resolver->available_codes(),
                'branches_in_db'     => $resolver->describe_branches(),
            ];
        }

        // ── Row-by-row processing ─────────────────────────────────────────────
        foreach ( $rows as $i => $row ) {
            $row_num = $i + 2;

            // Required: phone
            $phone = $this->col($row, ['Phone Number','phone number','Phone','phone','Mobile','mobile']);
            if ( !$phone ) {
                $msg = "Row $row_num: missing phone (searched: Phone Number, Phone, Mobile)";
                $errors[] = $msg;
                if ( $dry && count($skipped_rows) < 50 ) {
                    $skipped_rows[] = [
                        'row'    => $row_num,
                        'reason' => 'missing_phone',
                        'detail' => 'No value found in any phone column alias. Columns present: '.implode(', ',array_keys(array_filter($row,fn($v)=>trim($v)!==''))),
                    ];
                }
                $skip_reasons['missing_phone']++; $skipped++; continue;
            }

            // Required: name
            $name = $this->col($row, ['Name','name','Client Name','client_name','Full Name','full_name']);
            if ( !$name ) {
                $msg = "Row $row_num: missing name (phone: $phone)";
                $errors[] = $msg;
                if ( $dry && count($skipped_rows) < 50 ) {
                    $skipped_rows[] = [
                        'row'    => $row_num,
                        'reason' => 'missing_name',
                        'detail' => "Phone $phone has no value in any name column alias.",
                    ];
                }
                $skip_reasons['missing_name']++; $skipped++; continue;
            }

            // Branch resolution via OPB_Branch_Resolver
            $raw_outlet = $this->col($row, ['Home Outlet','home_outlet','Branch','branch','Home Branch']);
            $resolved   = $resolver->resolve($raw_outlet);

            if ( !$resolved['branch'] ) {
                $diag_detail = $resolved['diagnostics']['failure_reason']
                    ?? "No match for '$raw_outlet'";
                $available = implode(', ', $resolver->describe_branches());
                $msg = "Row $row_num: branch not resolved — '$raw_outlet'. Available: $available";
                $errors[] = $msg;
                if ( $dry && count($skipped_rows) < 50 ) {
                    $skipped_rows[] = [
                        'row'    => $row_num,
                        'reason' => 'branch_not_found',
                        'detail' => "CSV value: '$raw_outlet'. $diag_detail. Available: $available",
                        'resolver_diagnostics' => $resolved['diagnostics'],
                    ];
                }
                $skip_reasons['branch_not_found']++; $skipped++; continue;
            }
            $branch_id = (int)$resolved['branch']->id;

            // Duplicate check
            $existing = (int)$wpdb->get_var($wpdb->prepare(
                "SELECT id FROM {$wpdb->prefix}opb_clients WHERE phone=%s", $phone
            ));
            if ( $existing ) {
                if ( $dry ) {
                    $errors[] = "Row $row_num: duplicate — phone $phone already exists as client #$existing";
                    if ( count($skipped_rows) < 50 ) {
                        $skipped_rows[] = [
                            'row'    => $row_num,
                            'reason' => 'duplicate',
                            'detail' => "Phone $phone already exists in opb_clients as record #$existing. Name in CSV: $name.",
                        ];
                    }
                }
                $skip_reasons['duplicate']++; $skipped++; continue;
            }

            // Insert (live run only)
            if ( !$dry ) {
                $legacy_id = $this->col($row, ['Pet ID','pet_id','Legacy ID','legacy_id']);

                $wpdb->insert("{$wpdb->prefix}opb_clients",[
                    'home_branch_id'  => $branch_id,
                    'name'            => sanitize_text_field($name),
                    'phone'           => sanitize_text_field($phone),
                    'email'           => sanitize_email($this->col($row,['Email','email'])),
                    'address'         => sanitize_textarea_field($this->col($row,['Address','address'])),
                    'onboarding_date' => sanitize_text_field($this->col($row,['Onboarding Date','onboarding_date'])) ?: null,
                    'tc_accepted'     => 1,
                    'legacy_id'       => $legacy_id !== '' ? (int)$legacy_id : null,
                    'status'          => 'active',
                ]);
                $client_id = (int)$wpdb->insert_id;

                $pet_name = $this->col($row, ['Pet Name','pet_name']);
                if ( $pet_name && $client_id ) {
                    $pet_type = match(strtolower($this->col($row,['Pet Type','pet_type'],'dog'))){
                        'dog'   => 'Dog',
                        'cat'   => 'Cat',
                        default => 'Other',
                    };
                    $gender = match(strtolower($this->col($row,['Gender','gender'],'unknown'))){
                        'male','m'   => 'Male',
                        'female','f' => 'Female',
                        default      => 'Unknown',
                    };
                    $wpdb->insert("{$wpdb->prefix}opb_pets",[
                        'client_id'  => $client_id,
                        'name'       => sanitize_text_field($pet_name),
                        'pet_type'   => $pet_type,
                        'breed'      => sanitize_text_field($this->col($row,['Breed','breed'])),
                        'breed_size' => sanitize_text_field($this->col($row,['Breed Size','breed_size'])),
                        'gender'     => $gender,
                        'legacy_id'  => $legacy_id !== '' ? (int)$legacy_id : null,
                    ]);
                }
            }
            $imported++;
        }

        // ── Response ──────────────────────────────────────────────────────────
        $response = [
            'imported'  => $imported,
            'skipped'   => $skipped,
            'errors'    => array_slice($errors, 0, 50),
            'total'     => count($rows),
            'dry_run'   => $dry,
        ];

        if ( $dry ) {
            $response['diagnostics'] = [
                'headers'      => $header_diagnostics,
                'skip_reasons' => $skip_reasons,
                'skipped_rows' => $skipped_rows,
                'note'         => count($skipped_rows) < $skipped
                    ? 'skipped_rows shows first '.count($skipped_rows).' of '.$skipped.' skipped rows'
                    : 'all skipped rows shown',
            ];
        }

        return $response;
    }

    private function import_bookings( array $rows, bool $dry ): array {
        global $wpdb;
        $imported=0; $skipped=0; $errors=[];
        $resolver = OPB_Branch_Resolver::from_db();

        foreach($rows as $i=>$row){
            $row_num = $i + 2;
            $phone = trim($row['Phone']??'');
            if(!$phone){ $errors[]="Row $row_num: missing phone"; $skipped++; continue; }

            $client_id=(int)$wpdb->get_var($wpdb->prepare(
                "SELECT id FROM {$wpdb->prefix}opb_clients WHERE phone=%s",$phone
            ));
            if(!$client_id){ $errors[]="Row $row_num: client with phone $phone not found"; $skipped++; continue; }

            if(!$dry){
                $raw_branch = trim($row['Branch']??$row['branch_id']??'');
                $resolved   = $resolver->resolve($raw_branch);
                $branch_id  = $resolved['branch'] ? (int)$resolved['branch']->id : 1;

                $wpdb->insert("{$wpdb->prefix}opb_bookings",[
                    'client_id'             => $client_id,
                    'branch_id'             => $branch_id,
                    'booking_date'          => sanitize_text_field($row['Booking Date']??date('Y-m-d')),
                    'payment_status'        => sanitize_text_field($row['Payment Status']??'Unpaid'),
                    'total_billing_amount'  => (float)($row['Total']??0),
                    'legacy_id'             => isset($row['ID'])?(int)$row['ID']:null,
                ]);
            }
            $imported++;
        }
        return ['imported'=>$imported,'skipped'=>$skipped,'errors'=>array_slice($errors,0,50),'total'=>count($rows),'dry_run'=>$dry];
    }

    private function import_expenses( array $rows, bool $dry ): array {
        global $wpdb;
        $imported=0; $skipped=0; $errors=[];
        $resolver = OPB_Branch_Resolver::from_db();

        foreach($rows as $i=>$row){
            $row_num = $i + 2;
            $desc=trim($row['Description']??$row['description']??'');
            if(!$desc){ $errors[]="Row $row_num: missing description"; $skipped++; continue; }

            if(!$dry){
                $raw_branch = trim($row['Branch']??$row['branch']??'');
                $resolved   = $resolver->resolve($raw_branch);
                $branch_id  = $resolved['branch'] ? (int)$resolved['branch']->id : 1;

                $wpdb->insert("{$wpdb->prefix}opb_expenses",[
                    'branch_id'   => $branch_id,
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
