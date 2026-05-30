<?php
class OPB_Payments_API extends OPB_REST_Base {

    public function register_routes(): void {
        register_rest_route( $this->namespace, '/payments', [
            [ 'methods' => 'GET', 'callback' => [ $this, 'get_items' ], 'permission_callback' => [ $this, 'permission_check' ] ],
        ]);
        register_rest_route( $this->namespace, '/payments/(?P<id>\d+)', [
            [ 'methods' => 'DELETE', 'callback' => [ $this, 'delete_item' ], 'permission_callback' => fn($r)=>$this->permission_manage('opb_manage_invoices',$r) ],
        ]);
    }

    public function get_items( $r ) {
        $check = $this->permission_check($r); if(is_wp_error($check)) return $check;
        global $wpdb;

        $branch_id = $this->branch_filter((int)($r->get_param('branch_id')??0));
        $date_from = sanitize_text_field($r->get_param('date_from')??'');
        $date_to   = sanitize_text_field($r->get_param('date_to')??'');
        $mode      = sanitize_text_field($r->get_param('mode')??'');
        $page      = max(1,(int)($r->get_param('page')??1));
        $per_page  = min(100,(int)($r->get_param('per_page')??50));
        $offset    = ($page-1)*$per_page;

        $where=['1=1']; $args=[];
        if($branch_id){ $where[]='py.branch_id=%d'; $args[]=$branch_id; }
        if($date_from){ $where[]='DATE(py.paid_at)>=%s'; $args[]=$date_from; }
        if($date_to){   $where[]='DATE(py.paid_at)<=%s'; $args[]=$date_to;   }
        if($mode){      $where[]='py.mode=%s';           $args[]=$mode;      }
        $where_sql=implode(' AND ',$where);

        $total=(int)$wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}opb_payments py WHERE $where_sql",...$args
        ));
        $rows=$wpdb->get_results($wpdb->prepare(
            "SELECT py.*, i.booking_id, c.name as client_name, c.phone as client_phone,
                    b.name as branch_name,
                    GROUP_CONCAT(DISTINCT p.name ORDER BY p.name SEPARATOR ', ') as pet_names
             FROM {$wpdb->prefix}opb_payments py
             JOIN {$wpdb->prefix}opb_invoices i ON i.id=py.invoice_id
             JOIN {$wpdb->prefix}opb_bookings bk ON bk.id=i.booking_id
             JOIN {$wpdb->prefix}opb_clients c ON c.id=bk.client_id
             JOIN {$wpdb->prefix}opb_branches b ON b.id=py.branch_id
             LEFT JOIN {$wpdb->prefix}opb_booking_stays bs ON bs.booking_id=bk.id
             LEFT JOIN {$wpdb->prefix}opb_pets p ON p.id=bs.pet_id
             WHERE $where_sql
             GROUP BY py.id
             ORDER BY py.paid_at DESC
             LIMIT %d OFFSET %d",
            ...[...$args,$per_page,$offset]
        ),ARRAY_A);

        $total_amount=(float)$wpdb->get_var($wpdb->prepare(
            "SELECT COALESCE(SUM(py.amount),0) FROM {$wpdb->prefix}opb_payments py WHERE $where_sql",...$args
        ));

        return $this->success(['data'=>$rows,'total'=>$total,'total_amount'=>$total_amount,
                               'page'=>$page,'per_page'=>$per_page,'total_pages'=>(int)ceil($total/$per_page)]);
    }

    public function delete_item( $r ) {
        $check = $this->permission_manage('opb_manage_invoices',$r); if(is_wp_error($check)) return $check;
        global $wpdb;
        $id = (int)$r['id'];
        $inv_id=(int)$wpdb->get_var($wpdb->prepare("SELECT invoice_id FROM {$wpdb->prefix}opb_payments WHERE id=%d",$id));
        $wpdb->delete("{$wpdb->prefix}opb_payments",['id'=>$id]);
        if($inv_id) OPB_Invoice_Generator::sync_payment_totals($inv_id);
        return $this->success(['deleted'=>true]);
    }
}
