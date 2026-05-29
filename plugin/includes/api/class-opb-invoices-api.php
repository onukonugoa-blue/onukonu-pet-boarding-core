<?php
class OPB_Invoices_API extends OPB_REST_Base {

    public function register_routes(): void {
        register_rest_route( $this->namespace, '/invoices', [
            [ 'methods' => 'GET', 'callback' => [ $this, 'get_items' ], 'permission_callback' => [ $this, 'permission_check' ] ],
        ]);
        register_rest_route( $this->namespace, '/invoices/(?P<id>\d+)', [
            [ 'methods' => 'GET', 'callback' => [ $this, 'get_item' ], 'permission_callback' => [ $this, 'permission_check' ] ],
        ]);
        register_rest_route( $this->namespace, '/invoices/(?P<id>\d+)/adjust', [
            [ 'methods' => 'PUT', 'callback' => [ $this, 'adjust' ], 'permission_callback' => fn($r)=>$this->permission_manage('opb_manage_invoices',$r) ],
        ]);
        register_rest_route( $this->namespace, '/invoices/(?P<id>\d+)/payments', [
            [ 'methods' => 'POST', 'callback' => [ $this, 'record_payment' ], 'permission_callback' => fn($r)=>$this->permission_manage('opb_record_payments',$r) ],
        ]);
    }

    public function get_items( WP_REST_Request $r ): WP_REST_Response|WP_Error {
        $check = $this->permission_check($r); if(is_wp_error($check)) return $check;
        global $wpdb;

        $branch_id  = $this->branch_filter((int)($r->get_param('branch_id')??0));
        $date_from  = sanitize_text_field($r->get_param('date_from')??'');
        $date_to    = sanitize_text_field($r->get_param('date_to')??'');
        $pay_status = sanitize_text_field($r->get_param('payment_status')??'');
        $page       = max(1,(int)($r->get_param('page')??1));
        $per_page   = min(100,(int)($r->get_param('per_page')??50));
        $offset     = ($page-1)*$per_page;

        $where = ['1=1']; $args = [];
        if($branch_id){ $where[]='i.branch_id=%d'; $args[]=$branch_id; }
        if($date_from){ $where[]='i.invoice_date>=%s'; $args[]=$date_from; }
        if($date_to){   $where[]='i.invoice_date<=%s'; $args[]=$date_to;   }
        if($pay_status){ $where[]='i.payment_status=%s'; $args[]=$pay_status; }
        $where_sql = implode(' AND ',$where);

        $total = (int)$wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}opb_invoices i WHERE $where_sql",...$args
        ));
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT i.*, c.name as client_name, c.phone as client_phone, b.name as branch_name,
                    bk.booking_date,
                    GROUP_CONCAT(DISTINCT p.name ORDER BY p.name SEPARATOR ', ') as pet_names
             FROM {$wpdb->prefix}opb_invoices i
             JOIN {$wpdb->prefix}opb_bookings bk ON bk.id=i.booking_id
             JOIN {$wpdb->prefix}opb_clients c ON c.id=bk.client_id
             JOIN {$wpdb->prefix}opb_branches b ON b.id=i.branch_id
             LEFT JOIN {$wpdb->prefix}opb_booking_stays bs ON bs.booking_id=bk.id
             LEFT JOIN {$wpdb->prefix}opb_pets p ON p.id=bs.pet_id
             WHERE $where_sql
             GROUP BY i.id
             ORDER BY i.invoice_date DESC, i.id DESC
             LIMIT %d OFFSET %d",
            ...[...$args,$per_page,$offset]
        ),ARRAY_A);

        return $this->success($this->paginate($rows,$total,$page,$per_page));
    }

    public function get_item( WP_REST_Request $r ): WP_REST_Response|WP_Error {
        $check = $this->permission_check($r); if(is_wp_error($check)) return $check;
        global $wpdb;
        $id = (int)$r['id'];

        $invoice = $wpdb->get_row($wpdb->prepare(
            "SELECT i.*, c.name as client_name, c.phone as client_phone, c.email as client_email,
                    b.name as branch_name, bk.booking_date
             FROM {$wpdb->prefix}opb_invoices i
             JOIN {$wpdb->prefix}opb_bookings bk ON bk.id=i.booking_id
             JOIN {$wpdb->prefix}opb_clients c ON c.id=bk.client_id
             JOIN {$wpdb->prefix}opb_branches b ON b.id=i.branch_id
             WHERE i.id=%d",$id
        ),ARRAY_A);
        if(!$invoice) return $this->error('not_found','Invoice not found',404);

        $invoice['line_items'] = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}opb_invoice_line_items WHERE invoice_id=%d ORDER BY bill_section,id",$id
        ),ARRAY_A);
        $invoice['payments'] = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}opb_payments WHERE invoice_id=%d ORDER BY paid_at",$id
        ),ARRAY_A);
        $invoice['stays'] = $wpdb->get_results($wpdb->prepare(
            "SELECT bs.*, p.name as pet_name, p.breed, p.breed_size
             FROM {$wpdb->prefix}opb_booking_stays bs
             JOIN {$wpdb->prefix}opb_pets p ON p.id=bs.pet_id
             WHERE bs.booking_id=%d",(int)$invoice['booking_id']
        ),ARRAY_A);

        return $this->success($invoice);
    }

    public function adjust( WP_REST_Request $r ): WP_REST_Response|WP_Error {
        $check = $this->permission_manage('opb_manage_invoices',$r); if(is_wp_error($check)) return $check;
        global $wpdb;
        $d  = $r->get_json_params();
        $id = (int)$r['id'];

        $amount      = (float)($d['amount']??0);
        $description = sanitize_text_field($d['description']??'Manual adjustment');
        $is_discount = !empty($d['is_discount']);

        // Add a manual line item
        $wpdb->insert("{$wpdb->prefix}opb_invoice_line_items",[
            'invoice_id'     => $id,
            'bill_section'   => $is_discount ? 'Discount' : 'Additional',
            'bill_item_name' => $description,
            'quantity'       => 1,
            'amount'         => $is_discount ? -abs($amount) : abs($amount),
            'subtotal'       => $is_discount ? -abs($amount) : abs($amount),
            'total'          => $is_discount ? -abs($amount) : abs($amount),
            'is_return'      => $is_discount ? 1 : 0,
        ]);

        // Recompute invoice totals from line items
        $sums = $wpdb->get_row($wpdb->prepare(
            "SELECT
                SUM(CASE WHEN bill_section='Base' THEN total ELSE 0 END) as base_amount,
                SUM(CASE WHEN bill_section='Add-on' THEN total ELSE 0 END) as addon_amount,
                SUM(CASE WHEN bill_section='Discount' THEN ABS(total) ELSE 0 END) as discount_amount,
                SUM(CASE WHEN bill_section='Additional' THEN total ELSE 0 END) as additional_amount,
                SUM(total) as revenue
             FROM {$wpdb->prefix}opb_invoice_line_items WHERE invoice_id=%d",$id
        ),ARRAY_A);

        $paid    = (float)$wpdb->get_var($wpdb->prepare("SELECT COALESCE(SUM(amount),0) FROM {$wpdb->prefix}opb_payments WHERE invoice_id=%d",$id));
        $revenue = (float)$sums['revenue'];
        $due     = round($revenue-$paid,2);

        $wpdb->update("{$wpdb->prefix}opb_invoices",[
            'revenue'          => $revenue,
            'base_amount'      => $sums['base_amount'],
            'addon_amount'     => $sums['addon_amount'],
            'discount_amount'  => $sums['discount_amount'],
            'additional_amount'=> $sums['additional_amount'],
            'due'              => $due,
            'payment_status'   => OPB_Invoice_Generator::resolve_payment_status($revenue,$paid),
        ],['id'=>$id]);

        return $this->get_item($r);
    }

    public function record_payment( WP_REST_Request $r ): WP_REST_Response|WP_Error {
        $check = $this->permission_manage('opb_record_payments',$r); if(is_wp_error($check)) return $check;
        global $wpdb;
        $d  = $r->get_json_params();
        $id = (int)$r['id'];

        if(empty($d['amount'])||$d['amount']<=0) return $this->error('invalid','amount must be > 0');

        $branch_id = (int)$wpdb->get_var($wpdb->prepare("SELECT branch_id FROM {$wpdb->prefix}opb_invoices WHERE id=%d",$id));

        $wpdb->insert("{$wpdb->prefix}opb_payments",[
            'invoice_id'    => $id,
            'branch_id'     => $branch_id,
            'amount'        => (float)$d['amount'],
            'mode'          => sanitize_text_field($d['mode']??'Cash'),
            'source'        => 'Manual',
            'transaction_id'=> sanitize_text_field($d['transaction_id']??''),
            'paid_at'       => sanitize_text_field($d['paid_at']??current_time('Y-m-d H:i:s')),
            'recorded_by'   => get_current_user_id(),
            'notes'         => sanitize_textarea_field($d['notes']??''),
        ]);

        OPB_Invoice_Generator::sync_payment_totals($id);
        return $this->get_item($r);
    }
}
