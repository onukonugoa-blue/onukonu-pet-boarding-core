<?php
class OPB_Bookings_API extends OPB_REST_Base {

    public function register_routes(): void {
        register_rest_route( $this->namespace, '/bookings', [
            [ 'methods' => 'GET',  'callback' => [ $this, 'get_items'  ], 'permission_callback' => [ $this, 'permission_check' ] ],
            [ 'methods' => 'POST', 'callback' => [ $this, 'create_item'], 'permission_callback' => fn($r)=>$this->permission_manage('opb_manage_bookings',$r) ],
        ]);
        register_rest_route( $this->namespace, '/bookings/(?P<id>\d+)', [
            [ 'methods' => 'GET', 'callback' => [ $this, 'get_item'   ], 'permission_callback' => [ $this, 'permission_check' ] ],
            [ 'methods' => 'PUT', 'callback' => [ $this, 'update_item'], 'permission_callback' => fn($r)=>$this->permission_manage('opb_manage_bookings',$r) ],
        ]);
        register_rest_route( $this->namespace, '/bookings/(?P<id>\d+)/checkin', [
            [ 'methods' => 'POST', 'callback' => [ $this, 'checkin' ], 'permission_callback' => fn($r)=>$this->permission_manage('opb_manage_bookings',$r) ],
        ]);
        register_rest_route( $this->namespace, '/bookings/(?P<id>\d+)/checkout', [
            [ 'methods' => 'POST', 'callback' => [ $this, 'checkout' ], 'permission_callback' => fn($r)=>$this->permission_manage('opb_manage_bookings',$r) ],
        ]);
        register_rest_route( $this->namespace, '/bookings/(?P<id>\d+)/addons', [
            [ 'methods' => 'POST',   'callback' => [ $this, 'add_addon'    ], 'permission_callback' => fn($r)=>$this->permission_manage('opb_manage_bookings',$r) ],
            [ 'methods' => 'DELETE', 'callback' => [ $this, 'remove_addon' ], 'permission_callback' => fn($r)=>$this->permission_manage('opb_manage_bookings',$r) ],
        ]);
        register_rest_route( $this->namespace, '/kennel-board', [
            [ 'methods' => 'GET', 'callback' => [ $this, 'kennel_board' ], 'permission_callback' => [ $this, 'permission_check' ] ],
        ]);
        register_rest_route( $this->namespace, '/stays/(?P<stay_id>\d+)/assign-kennel', [
            [ 'methods' => 'POST', 'callback' => [ $this, 'assign_kennel' ], 'permission_callback' => fn($r)=>$this->permission_manage('opb_manage_bookings',$r) ],
        ]);
    }

    public function get_items( $r ) {
        $check = $this->permission_check($r); if(is_wp_error($check)) return $check;
        global $wpdb;

        $branch_id  = $this->branch_filter((int)($r->get_param('branch_id')??0));
        $date_from  = sanitize_text_field($r->get_param('date_from')??'');
        $date_to    = sanitize_text_field($r->get_param('date_to')??'');
        $status     = sanitize_text_field($r->get_param('status')??'');
        $pay_status = sanitize_text_field($r->get_param('payment_status')??'');
        $search     = sanitize_text_field($r->get_param('search')??'');
        $page       = max(1,(int)($r->get_param('page')??1));
        $per_page   = min(100,(int)($r->get_param('per_page')??50));
        $offset     = ($page-1)*$per_page;

        $where = ['1=1']; $args = [];

        if($branch_id){ $where[]='bk.branch_id=%d'; $args[]=$branch_id; }
        if($date_from){ $where[]='bk.booking_date>=%s'; $args[]=$date_from; }
        if($date_to){   $where[]='bk.booking_date<=%s'; $args[]=$date_to;   }
        if($pay_status){ $where[]='bk.payment_status=%s'; $args[]=$pay_status; }
        if($search){
            $like='%'.esc_sql($search).'%';
            $where[]='(c.name LIKE %s OR c.phone LIKE %s OR p.name LIKE %s)';
            $args=array_merge($args,[$like,$like,$like]);
        }
        if($status){
            if($status==='Cancelled'){
                // Filter by booking-level cancellation status
                $where[]="bk.status='Cancelled'";
            } else {
                // Filter by stay-level operational status
                $where[]='EXISTS(SELECT 1 FROM '.$wpdb->prefix.'opb_booking_stays bs2 WHERE bs2.booking_id=bk.id AND bs2.status=%s)';
                $args[]=$status;
            }
        }

        $join  = "JOIN {$wpdb->prefix}opb_clients c ON c.id=bk.client_id";
        $join .= " LEFT JOIN {$wpdb->prefix}opb_booking_stays bsj ON bsj.booking_id=bk.id";
        $join .= " LEFT JOIN {$wpdb->prefix}opb_pets p ON p.id=bsj.pet_id";
        $join .= " LEFT JOIN {$wpdb->prefix}opb_branches br ON br.id=bk.branch_id";
        $where_sql = implode(' AND ',$where);

        $total = (int)$wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(DISTINCT bk.id) FROM {$wpdb->prefix}opb_bookings bk $join WHERE $where_sql",
            ...$args
        ));
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT bk.*, c.name as client_name, c.phone as client_phone,
                    br.name as branch_name, br.code as branch_code,
                    GROUP_CONCAT(DISTINCT p.name ORDER BY p.name SEPARATOR ', ') as pet_names,
                    MIN(bsj.check_in_date) as check_in_date,
                    MAX(bsj.check_out_date) as check_out_date,
                    MAX(bsj.status) as stay_status
             FROM {$wpdb->prefix}opb_bookings bk $join
             WHERE $where_sql
             GROUP BY bk.id
             ORDER BY bk.booking_date DESC, bk.id DESC
             LIMIT %d OFFSET %d",
            ...[...$args,$per_page,$offset]
        ),ARRAY_A);

        return $this->success($this->paginate($rows,$total,$page,$per_page));
    }

    public function get_item( $r ) {
        $check = $this->permission_check($r); if(is_wp_error($check)) return $check;
        global $wpdb;
        $id = (int)$r['id'];

        $booking = $wpdb->get_row($wpdb->prepare(
            "SELECT bk.*, c.name as client_name, c.phone as client_phone, c.email as client_email,
                    b.name as branch_name, b.code as branch_code
             FROM {$wpdb->prefix}opb_bookings bk
             JOIN {$wpdb->prefix}opb_clients c ON c.id=bk.client_id
             JOIN {$wpdb->prefix}opb_branches b ON b.id=bk.branch_id
             WHERE bk.id=%d",$id
        ),ARRAY_A);
        if(!$booking) return $this->error('not_found','Booking not found',404);

        $stays = $wpdb->get_results($wpdb->prepare(
            "SELECT bs.*, p.name as pet_name, p.breed, p.breed_size, p.gender, p.pet_type
             FROM {$wpdb->prefix}opb_booking_stays bs
             JOIN {$wpdb->prefix}opb_pets p ON p.id=bs.pet_id
             WHERE bs.booking_id=%d ORDER BY bs.id",$id
        ),ARRAY_A);

        $addons = $wpdb->get_results($wpdb->prepare(
            "SELECT ba.*, a.name, a.service_type, a.base_amount as unit_price
             FROM {$wpdb->prefix}opb_booking_addons ba
             JOIN {$wpdb->prefix}opb_addon_services a ON a.id=ba.addon_id
             WHERE ba.booking_id=%d",$id
        ),ARRAY_A);

        $invoice = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}opb_invoices WHERE booking_id=%d",$id
        ),ARRAY_A);

        $payments = [];
        if($invoice){
            $payments = $wpdb->get_results($wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}opb_payments WHERE invoice_id=%d ORDER BY paid_at",(int)$invoice['id']
            ),ARRAY_A);
            $line_items = $wpdb->get_results($wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}opb_invoice_line_items WHERE invoice_id=%d ORDER BY bill_section,id",(int)$invoice['id']
            ),ARRAY_A);
            $invoice['line_items'] = $line_items;
            $invoice['payments']   = $payments;
        }

        $booking['stays']    = $stays;
        $booking['addons']   = $addons;
        $booking['invoice']  = $invoice;
        return $this->success($booking);
    }

    public function create_item( $r ) {
        $check = $this->permission_manage('opb_manage_bookings',$r); if(is_wp_error($check)) return $check;
        global $wpdb;
        $d = $r->get_json_params();

        if(empty($d['client_id'])||empty($d['branch_id'])||empty($d['stays'])){
            return $this->error('invalid','client_id, branch_id, stays[] required');
        }

        $wpdb->insert("{$wpdb->prefix}opb_bookings",[
            'branch_id'            => (int)$d['branch_id'],
            'client_id'            => (int)$d['client_id'],
            'booking_date'         => $d['booking_date'] ?? current_time('Y-m-d'),
            'payment_status'       => 'Unpaid',
            'service_types'        => sanitize_text_field($d['service_types']??''),
            'notes'                => sanitize_textarea_field($d['notes']??''),
            'additional_instruction'=> sanitize_textarea_field($d['additional_instruction']??''),
            'created_by'           => get_current_user_id(),
        ]);
        $booking_id = (int)$wpdb->insert_id;

        foreach((array)$d['stays'] as $stay){
            $wpdb->insert("{$wpdb->prefix}opb_booking_stays",[
                'booking_id'          => $booking_id,
                'pet_id'              => (int)$stay['pet_id'],
                'boarding_service_id' => (int)($stay['boarding_service_id']??0) ?: null,
                'boarding_type'       => sanitize_text_field($stay['boarding_type']??'OVERNIGHT'),
                'check_in_date'       => sanitize_text_field($stay['check_in_date']),
                'check_out_date'      => sanitize_text_field($stay['check_out_date']),
                'check_in_slot'       => sanitize_text_field($stay['check_in_slot']??''),
                'check_out_slot'      => sanitize_text_field($stay['check_out_slot']??''),
                'meal_type'           => sanitize_text_field($stay['meal_type']??'PARENT_SUPPLIED_MEAL'),
                'status'              => 'Upcoming',
            ]);
        }

        foreach((array)($d['addons']??[]) as $addon){
            $unit = (float)$wpdb->get_var($wpdb->prepare(
                "SELECT base_amount FROM {$wpdb->prefix}opb_addon_services WHERE id=%d",(int)$addon['addon_id']
            ));
            $count = (int)($addon['count']??1);
            $wpdb->insert("{$wpdb->prefix}opb_booking_addons",[
                'booking_id'  => $booking_id,
                'addon_id'    => (int)$addon['addon_id'],
                'count'       => $count,
                'final_amount'=> isset($addon['final_amount']) ? (float)$addon['final_amount'] : $unit*$count,
                'notes'       => sanitize_textarea_field($addon['notes']??''),
            ]);
        }

        OPB_Invoice_Generator::create_for_booking($booking_id);

        OPB_Opsmail::push_booking_confirmed( $booking_id, (int)$d['branch_id'], (int)$d['client_id'] );

        $req = new WP_REST_Request('GET'); $req['id'] = $booking_id;
        return $this->get_item($req);
    }

    public function update_item( $r ) {
        $check = $this->permission_manage('opb_manage_bookings',$r); if(is_wp_error($check)) return $check;
        global $wpdb;
        $d = $r->get_json_params();
        $allowed = ['notes','additional_instruction','booking_source','service_types'];
        $update  = [];
        foreach($allowed as $k){ if(array_key_exists($k,$d)) $update[$k]=$d[$k]; }
        if($update) $wpdb->update("{$wpdb->prefix}opb_bookings",$update,['id'=>(int)$r['id']]);
        return $this->get_item($r);
    }

    public function checkin( WP_REST_Request $r ): WP_REST_Response|WP_Error {
        $check = $this->permission_manage('opb_manage_bookings',$r); if(is_wp_error($check)) return $check;
        global $wpdb;
        $d  = $r->get_json_params();
        $id = (int)$r['id'];

        // stay_id is required — which pet to check in
        $stay_id = (int)($d['stay_id']??0);
        $where = $stay_id ? ['id'=>$stay_id,'booking_id'=>$id] : ['booking_id'=>$id,'status'=>'Upcoming'];
        $stay  = $wpdb->get_row("SELECT id FROM {$wpdb->prefix}opb_booking_stays WHERE ".implode(' AND ',array_map(fn($k)=>"$k=%s",array_keys($where))),ARRAY_A,...array_values($where));

        $stay_id = $stay_id ?: ($stay['id']??0);
        if(!$stay_id) return $this->error('invalid','No upcoming stay found');

        $update_data = [
            'status'             => 'Active',
            'actual_check_in_at' => sanitize_text_field($d['actual_check_in_at']??current_time('Y-m-d H:i:s')),
            'weight_at_checkin'  => isset($d['weight_at_checkin'])?(float)$d['weight_at_checkin']:null,
            'meal_type'          => sanitize_text_field($d['meal_type']??'PARENT_SUPPLIED_MEAL'),
            'companion_name'     => sanitize_text_field($d['companion_name']??''),
            'companion_phone'    => sanitize_text_field($d['companion_phone']??''),
            'notes'              => sanitize_textarea_field($d['notes']??''),
        ];
        // Support kennel assignment via kennel_id (structured) or legacy free-text kennel
        if (!empty($d['kennel_id'])) {
            $kennel_row = $wpdb->get_row($wpdb->prepare(
                "SELECT code, name, branch_id FROM {$wpdb->prefix}opb_kennels WHERE id=%d AND is_active=1",
                (int)$d['kennel_id']
            ), ARRAY_A);
            if ($kennel_row) {
                // Branch boundary enforcement: kennel must belong to the same branch as the booking.
                $booking_branch_id = (int)$wpdb->get_var($wpdb->prepare(
                    "SELECT branch_id FROM {$wpdb->prefix}opb_bookings WHERE id=%d", $id
                ));
                if ( (int)$kennel_row['branch_id'] !== $booking_branch_id ) {
                    return $this->error(
                        'cross_branch',
                        'This kennel belongs to a different branch than the booking. Cross-branch kennel assignment is not permitted.',
                        422
                    );
                }
                $update_data['kennel_id'] = (int)$d['kennel_id'];
                $update_data['kennel']    = $kennel_row['code'];
            }
        } elseif (isset($d['kennel'])) {
            $update_data['kennel']    = sanitize_text_field($d['kennel']);
            $update_data['kennel_id'] = null;
        }
        $wpdb->update("{$wpdb->prefix}opb_booking_stays", $update_data, ['id'=>$stay_id]);

        return $this->get_item($r);
    }

    public function checkout( WP_REST_Request $r ): WP_REST_Response|WP_Error {
        $check = $this->permission_manage('opb_manage_bookings',$r); if(is_wp_error($check)) return $check;
        global $wpdb;
        $d  = $r->get_json_params();
        $id = (int)$r['id'];

        $stay_id = (int)($d['stay_id']??0);
        $wpdb->update("{$wpdb->prefix}opb_booking_stays",[
            'status'             => 'Completed',
            'actual_check_out_at'=> sanitize_text_field($d['actual_check_out_at']??current_time('Y-m-d H:i:s')),
            'weight_at_checkout' => isset($d['weight_at_checkout'])?(float)$d['weight_at_checkout']:null,
            'late_checkout_fees' => isset($d['late_checkout_fees'])?(float)$d['late_checkout_fees']:0,
            'notes'              => sanitize_textarea_field($d['notes']??''),
        ],['id'=>$stay_id,'booking_id'=>$id]);

        // Check if all stays are completed
        $open = (int)$wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}opb_booking_stays WHERE booking_id=%d AND status NOT IN ('Completed','No show')",$id
        ));

        // Recalculate invoice
        $inv_id = (int)$wpdb->get_var($wpdb->prepare("SELECT id FROM {$wpdb->prefix}opb_invoices WHERE booking_id=%d",$id));
        if($inv_id) OPB_Invoice_Generator::recalculate($inv_id);

        return $this->get_item($r);
    }

    public function add_addon( WP_REST_Request $r ): WP_REST_Response|WP_Error {
        $check = $this->permission_manage('opb_manage_bookings',$r); if(is_wp_error($check)) return $check;
        global $wpdb;
        $d  = $r->get_json_params();
        $id = (int)$r['id'];
        if(empty($d['addon_id'])) return $this->error('invalid','addon_id required');
        $unit  = (float)$wpdb->get_var($wpdb->prepare("SELECT base_amount FROM {$wpdb->prefix}opb_addon_services WHERE id=%d",(int)$d['addon_id']));
        $count = (int)($d['count']??1);
        $wpdb->insert("{$wpdb->prefix}opb_booking_addons",[
            'booking_id'  => $id,
            'addon_id'    => (int)$d['addon_id'],
            'count'       => $count,
            'final_amount'=> isset($d['final_amount'])?(float)$d['final_amount']:$unit*$count,
            'notes'       => sanitize_textarea_field($d['notes']??''),
        ]);
        $inv_id = (int)$wpdb->get_var($wpdb->prepare("SELECT id FROM {$wpdb->prefix}opb_invoices WHERE booking_id=%d",$id));
        if($inv_id) OPB_Invoice_Generator::recalculate($inv_id);
        return $this->get_item($r);
    }

    public function remove_addon( WP_REST_Request $r ): WP_REST_Response|WP_Error {
        $check = $this->permission_manage('opb_manage_bookings',$r); if(is_wp_error($check)) return $check;
        global $wpdb;
        $d     = $r->get_json_params();
        $id    = (int)$r['id'];
        $ba_id = (int)($d['booking_addon_id']??0);
        if($ba_id) $wpdb->delete("{$wpdb->prefix}opb_booking_addons",['id'=>$ba_id,'booking_id'=>$id]);
        $inv_id = (int)$wpdb->get_var($wpdb->prepare("SELECT id FROM {$wpdb->prefix}opb_invoices WHERE booking_id=%d",$id));
        if($inv_id) OPB_Invoice_Generator::recalculate($inv_id);
        return $this->get_item($r);
    }

    public function assign_kennel( WP_REST_Request $r ): WP_REST_Response|WP_Error {
        $check = $this->permission_manage('opb_manage_bookings',$r); if(is_wp_error($check)) return $check;
        global $wpdb;

        $stay_id   = (int)$r['stay_id'];
        $d         = $r->get_json_params();
        $kennel_id = isset($d['kennel_id']) && $d['kennel_id'] !== null && $d['kennel_id'] !== '' ? (int)$d['kennel_id'] : null;

        // Verify stay exists, is assignable, and fetch dates + booking branch for validation
        $stay = $wpdb->get_row($wpdb->prepare(
            "SELECT bs.id, bs.status, bs.check_in_date, bs.check_out_date, bk.branch_id AS booking_branch_id
             FROM {$wpdb->prefix}opb_booking_stays bs
             JOIN {$wpdb->prefix}opb_bookings bk ON bk.id = bs.booking_id
             WHERE bs.id = %d", $stay_id
        ), ARRAY_A);
        if (!$stay) return $this->error('not_found', 'Stay not found', 404);
        if (in_array($stay['status'], ['Completed', 'No show'])) {
            return $this->error('invalid', 'Cannot assign a kennel to a completed or no-show stay');
        }

        $update = ['kennel_id' => $kennel_id, 'kennel' => null];

        if ($kennel_id) {
            // Validate kennel is active and operational
            $kennel_row = $wpdb->get_row($wpdb->prepare(
                "SELECT id, code, status, branch_id FROM {$wpdb->prefix}opb_kennels WHERE id=%d AND is_active=1", $kennel_id
            ), ARRAY_A);
            if (!$kennel_row) return $this->error('not_found', 'Kennel not found or inactive', 404);
            if (in_array($kennel_row['status'], ['Maintenance', 'Blocked'])) {
                return $this->error('invalid', 'Cannot assign a Maintenance or Blocked kennel to a stay', 422);
            }

            // Branch boundary enforcement: kennel must belong to the same branch as the booking.
            if ( (int)$kennel_row['branch_id'] !== (int)$stay['booking_branch_id'] ) {
                return $this->error(
                    'cross_branch',
                    'This kennel belongs to a different branch than the booking. Cross-branch kennel assignment is not permitted.',
                    422
                );
            }

            // Conflict check: is this kennel already allocated to another stay
            // whose dates overlap this stay's dates?
            // Overlap condition: A.check_in < B.check_out AND A.check_out > B.check_in
            $conflict = $wpdb->get_row($wpdb->prepare(
                "SELECT bs.id, p.name AS pet_name, bs.check_in_date, bs.check_out_date
                 FROM {$wpdb->prefix}opb_booking_stays bs
                 JOIN {$wpdb->prefix}opb_pets p ON p.id = bs.pet_id
                 WHERE bs.kennel_id = %d
                   AND bs.id != %d
                   AND bs.check_in_date < %s
                   AND bs.check_out_date > %s
                   AND bs.status NOT IN ('Completed', 'No show')",
                $kennel_id,
                $stay_id,
                $stay['check_out_date'],
                $stay['check_in_date']
            ), ARRAY_A);

            if ($conflict) {
                return $this->error(
                    'conflict',
                    sprintf(
                        'Kennel is already assigned to %s (%s → %s). Please choose a different kennel or resolve the existing assignment first.',
                        $conflict['pet_name'],
                        $conflict['check_in_date'],
                        $conflict['check_out_date']
                    ),
                    409
                );
            }

            $update['kennel'] = $kennel_row['code'];
        }

        $wpdb->update("{$wpdb->prefix}opb_booking_stays", $update, ['id' => $stay_id]);

        return $this->success([
            'stay_id'   => $stay_id,
            'kennel_id' => $kennel_id,
            'kennel'    => $update['kennel'],
        ]);
    }

    public function kennel_board( WP_REST_Request $r ): WP_REST_Response|WP_Error {
        $check = $this->permission_check($r); if(is_wp_error($check)) return $check;
        global $wpdb;

        $branch_id = $this->branch_filter((int)($r->get_param('branch_id') ?? 0));
        $from      = sanitize_text_field($r->get_param('from') ?? date('Y-m-d'));
        $to        = sanitize_text_field($r->get_param('to')   ?? date('Y-m-d', strtotime('+13 days')));

        // Correct overlap condition: stay overlaps [from,to] when
        //   check_in_date <= $to  AND  check_out_date >= $from
        $args  = [$to, $from];
        $b_sql = '';
        if ($branch_id) { $b_sql = ' AND bk.branch_id=%d'; $args[] = $branch_id; }

        $stays = $wpdb->get_results($wpdb->prepare(
            "SELECT bs.id, bs.kennel, bs.kennel_id, bs.status, bs.check_in_date, bs.check_out_date,
                    bs.actual_check_in_at, bs.actual_check_out_at,
                    p.name as pet_name, p.breed, p.pet_type,
                    c.name as client_name, c.phone as client_phone,
                    bk.id as booking_id, bk.branch_id
             FROM {$wpdb->prefix}opb_booking_stays bs
             JOIN {$wpdb->prefix}opb_bookings bk ON bk.id = bs.booking_id
             JOIN {$wpdb->prefix}opb_pets p ON p.id = bs.pet_id
             JOIN {$wpdb->prefix}opb_clients c ON c.id = bk.client_id
             WHERE bs.check_in_date <= %s
               AND bs.check_out_date >= %s
               AND bs.status NOT IN ('No show')
               AND bk.status != 'Cancelled'
               $b_sql
             ORDER BY bs.kennel, bs.check_in_date",
            ...$args
        ), ARRAY_A);

        // Build day-range array
        $days = [];
        $dt   = new DateTime($from);
        $end  = new DateTime($to);
        while ($dt <= $end) { $days[] = $dt->format('Y-m-d'); $dt->modify('+1 day'); }

        return $this->success(['days' => $days, 'stays' => $stays, 'from' => $from, 'to' => $to]);
    }
}
