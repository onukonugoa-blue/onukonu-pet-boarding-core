<?php
class OPB_Expenses_API extends OPB_REST_Base {

    public function register_routes(): void {
        register_rest_route( $this->namespace, '/expenses', [
            [ 'methods' => 'GET',  'callback' => [ $this, 'get_items'  ], 'permission_callback' => [ $this, 'permission_check' ] ],
            [ 'methods' => 'POST', 'callback' => [ $this, 'create_item'], 'permission_callback' => fn($r)=>$this->permission_manage('opb_manage_expenses',$r) ],
        ]);
        register_rest_route( $this->namespace, '/expenses/categories', [
            [ 'methods' => 'GET', 'callback' => [ $this, 'get_categories'], 'permission_callback' => [ $this, 'permission_check' ] ],
        ]);
        register_rest_route( $this->namespace, '/expenses/(?P<id>\d+)', [
            [ 'methods' => 'DELETE', 'callback' => [ $this, 'delete_item'], 'permission_callback' => fn($r)=>$this->permission_manage('opb_manage_expenses',$r) ],
        ]);
    }

    public function get_items( $r ) {
        $check = $this->permission_check($r); if(is_wp_error($check)) return $check;
        global $wpdb;

        $branch_id = $this->branch_filter((int)($r->get_param('branch_id')??0));

        // Default to current month when no dates are supplied
        $today      = current_time('Y-m-d');
        $month_from = date('Y-m-01', strtotime($today));
        $date_from  = sanitize_text_field($r->get_param('date_from') ?: $month_from);
        $date_to    = sanitize_text_field($r->get_param('date_to')   ?: $today);

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
            "SELECT e.id, e.branch_id, e.description, e.expense_at, e.mode, e.category,
                    e.amount, e.amount_inc_tax, e.recorded_by, e.notes, e.created_at,
                    b.name as branch_name,
                    COALESCE(e.recorded_by_name, u.display_name) as recorded_by_name
             FROM {$wpdb->prefix}opb_expenses e
             LEFT JOIN {$wpdb->prefix}opb_branches b ON b.id=e.branch_id
             LEFT JOIN {$wpdb->prefix}users u ON u.ID=e.recorded_by
             WHERE $where_sql
             ORDER BY e.expense_at DESC
             LIMIT %d OFFSET %d",
            ...[...$args,$per_page,$offset]
        ),ARRAY_A);

        $total_amount=(float)$wpdb->get_var($wpdb->prepare(
            "SELECT COALESCE(SUM(e.amount),0) FROM {$wpdb->prefix}opb_expenses e WHERE $where_sql",...$args
        ));

        $top_category=$wpdb->get_var($wpdb->prepare(
            "SELECT COALESCE(NULLIF(e.category,''),'Uncategorised')
             FROM {$wpdb->prefix}opb_expenses e
             WHERE $where_sql
             GROUP BY COALESCE(NULLIF(e.category,''),'Uncategorised')
             ORDER BY COUNT(*) DESC
             LIMIT 1",
            ...$args
        )) ?: '';

        return $this->success([
            'data'         => $rows,
            'total'        => $total,
            'total_amount' => $total_amount,
            'top_category' => $top_category,
            'date_from'    => $date_from,
            'date_to'      => $date_to,
            'page'         => $page,
            'per_page'     => $per_page,
            'total_pages'  => (int)ceil($total/$per_page),
        ]);
    }

    public function get_categories( $r ) {
        $check = $this->permission_check($r); if(is_wp_error($check)) return $check;
        global $wpdb;
        $branch_id = $this->branch_filter((int)($r->get_param('branch_id')??0));
        $where = ['1=1']; $args = [];
        if($branch_id){ $where[]='branch_id=%d'; $args[]=$branch_id; }
        $where_sql = implode(' AND ', $where);
        $rows = $wpdb->get_col($wpdb->prepare(
            "SELECT DISTINCT COALESCE(NULLIF(category,''),'Uncategorised') as cat
             FROM {$wpdb->prefix}opb_expenses
             WHERE $where_sql
             ORDER BY cat ASC",
            ...$args
        ));
        return $this->success($rows);
    }

    public function create_item( $r ) {
        $check = $this->permission_manage('opb_manage_expenses',$r); if(is_wp_error($check)) return $check;
        global $wpdb;
        $d=$r->get_json_params();
        if(empty($d['branch_id'])||empty($d['description'])||!isset($d['amount'])) {
            return $this->error('invalid','branch_id, description, amount required');
        }
        $user_id   = get_current_user_id();
        $user_data = get_userdata($user_id);
        $user_name = $user_data ? $user_data->display_name : '';

        $wpdb->insert("{$wpdb->prefix}opb_expenses",[
            'branch_id'        => (int)$d['branch_id'],
            'description'      => sanitize_text_field($d['description']),
            'amount'           => (float)$d['amount'],
            'amount_inc_tax'   => isset($d['amount_inc_tax'])?(float)$d['amount_inc_tax']:null,
            'mode'             => sanitize_text_field($d['mode']??'Cash'),
            'category'         => sanitize_text_field($d['category']??''),
            'expense_at'       => sanitize_text_field($d['expense_at']??current_time('Y-m-d H:i:s')),
            'recorded_by'      => $user_id,
            'recorded_by_name' => $user_name,
            'notes'            => sanitize_textarea_field($d['notes']??''),
        ],[
            '%d','%s','%f','%f','%s','%s','%s','%d','%s','%s',
        ]);
        $row=$wpdb->get_row($wpdb->prepare(
            "SELECT e.id, e.branch_id, e.description, e.expense_at, e.mode, e.category,
                    e.amount, e.amount_inc_tax, e.recorded_by, e.recorded_by_name, e.notes, e.created_at,
                    b.name as branch_name
             FROM {$wpdb->prefix}opb_expenses e
             LEFT JOIN {$wpdb->prefix}opb_branches b ON b.id=e.branch_id
             WHERE e.id=%d",$wpdb->insert_id
        ),ARRAY_A);
        if ( $row ) {
            OPB_Opsmail::push_expense_if_large( $row );
        }
        return $this->success($row,201);
    }

    public function delete_item( $r ) {
        $check = $this->permission_manage('opb_manage_expenses',$r); if(is_wp_error($check)) return $check;
        global $wpdb;
        $wpdb->delete("{$wpdb->prefix}opb_expenses",['id'=>(int)$r['id']]);
        return $this->success(['deleted'=>true]);
    }
}
