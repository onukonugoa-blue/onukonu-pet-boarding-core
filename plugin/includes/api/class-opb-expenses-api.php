<?php
class OPB_Expenses_API extends OPB_REST_Base {

    public function register_routes(): void {
        register_rest_route( $this->namespace, '/expenses', [
            [ 'methods' => 'GET',  'callback' => [ $this, 'get_items'  ], 'permission_callback' => [ $this, 'permission_check' ] ],
            [ 'methods' => 'POST', 'callback' => [ $this, 'create_item'], 'permission_callback' => fn($r)=>$this->permission_manage('opb_manage_expenses',$r) ],
        ]);
        register_rest_route( $this->namespace, '/expenses/(?P<id>\d+)', [
            [ 'methods' => 'DELETE', 'callback' => [ $this, 'delete_item'], 'permission_callback' => fn($r)=>$this->permission_manage('opb_manage_expenses',$r) ],
        ]);
    }

    public function get_items( $r ) {
        $check = $this->permission_check($r); if(is_wp_error($check)) return $check;
        global $wpdb;

        $branch_id = $this->branch_filter((int)($r->get_param('branch_id')??0));
        $date_from = sanitize_text_field($r->get_param('date_from')??'');
        $date_to   = sanitize_text_field($r->get_param('date_to')??'');
        $category  = sanitize_text_field($r->get_param('category')??'');
        $mode      = sanitize_text_field($r->get_param('mode')??'');
        $page      = max(1,(int)($r->get_param('page')??1));
        $per_page  = min(100,(int)($r->get_param('per_page')??50));
        $offset    = ($page-1)*$per_page;

        $where=['1=1']; $args=[];
        if($branch_id){ $where[]='e.branch_id=%d'; $args[]=$branch_id; }
        if($date_from){ $where[]='DATE(e.expense_at)>=%s'; $args[]=$date_from; }
        if($date_to){   $where[]='DATE(e.expense_at)<=%s'; $args[]=$date_to;   }
        if($category){  $where[]='e.category=%s';          $args[]=$category;  }
        if($mode){      $where[]='e.mode=%s';              $args[]=$mode;      }
        $where_sql=implode(' AND ',$where);

        $total=(int)$wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}opb_expenses e WHERE $where_sql",...$args
        ));
        $rows=$wpdb->get_results($wpdb->prepare(
            "SELECT e.*, b.name as branch_name
             FROM {$wpdb->prefix}opb_expenses e
             LEFT JOIN {$wpdb->prefix}opb_branches b ON b.id=e.branch_id
             WHERE $where_sql
             ORDER BY e.expense_at DESC
             LIMIT %d OFFSET %d",
            ...[...$args,$per_page,$offset]
        ),ARRAY_A);
        $total_amount=(float)$wpdb->get_var($wpdb->prepare(
            "SELECT COALESCE(SUM(e.amount),0) FROM {$wpdb->prefix}opb_expenses e WHERE $where_sql",...$args
        ));

        return $this->success(['data'=>$rows,'total'=>$total,'total_amount'=>$total_amount,
                               'page'=>$page,'per_page'=>$per_page,'total_pages'=>(int)ceil($total/$per_page)]);
    }

    public function create_item( $r ) {
        $check = $this->permission_manage('opb_manage_expenses',$r); if(is_wp_error($check)) return $check;
        global $wpdb;
        $d=$r->get_json_params();
        if(empty($d['branch_id'])||empty($d['description'])||!isset($d['amount'])) {
            return $this->error('invalid','branch_id, description, amount required');
        }
        $wpdb->insert("{$wpdb->prefix}opb_expenses",[
            'branch_id'     => (int)$d['branch_id'],
            'description'   => sanitize_text_field($d['description']),
            'amount'        => (float)$d['amount'],
            'amount_inc_tax'=> isset($d['amount_inc_tax'])?(float)$d['amount_inc_tax']:null,
            'mode'          => sanitize_text_field($d['mode']??'Cash'),
            'category'      => sanitize_text_field($d['category']??''),
            'expense_at'    => sanitize_text_field($d['expense_at']??current_time('Y-m-d H:i:s')),
            'recorded_by'   => get_current_user_id(),
            'notes'         => sanitize_textarea_field($d['notes']??''),
        ]);
        $row=$wpdb->get_row($wpdb->prepare(
            "SELECT e.*, b.name as branch_name FROM {$wpdb->prefix}opb_expenses e
             LEFT JOIN {$wpdb->prefix}opb_branches b ON b.id=e.branch_id WHERE e.id=%d",$wpdb->insert_id
        ),ARRAY_A);
        return $this->success($row,201);
    }

    public function delete_item( $r ) {
        $check = $this->permission_manage('opb_manage_expenses',$r); if(is_wp_error($check)) return $check;
        global $wpdb;
        $wpdb->delete("{$wpdb->prefix}opb_expenses",['id'=>(int)$r['id']]);
        return $this->success(['deleted'=>true]);
    }
}
