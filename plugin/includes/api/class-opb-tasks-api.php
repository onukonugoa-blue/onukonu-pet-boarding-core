<?php
class OPB_Tasks_API extends OPB_REST_Base {

    public function register_routes(): void {
        register_rest_route( $this->namespace, '/tasks', [
            [ 'methods' => 'GET',  'callback' => [ $this, 'get_items'  ], 'permission_callback' => [ $this, 'permission_check' ] ],
            [ 'methods' => 'POST', 'callback' => [ $this, 'create_item'], 'permission_callback' => fn($r)=>$this->permission_manage('opb_manage_tasks',$r) ],
        ]);
        register_rest_route( $this->namespace, '/tasks/(?P<id>\d+)', [
            [ 'methods' => 'GET', 'callback' => [ $this, 'get_item'   ], 'permission_callback' => [ $this, 'permission_check' ] ],
            [ 'methods' => 'PUT', 'callback' => [ $this, 'update_item'], 'permission_callback' => fn($r)=>$this->permission_manage('opb_manage_tasks',$r) ],
            [ 'methods' => 'DELETE', 'callback' => [ $this, 'delete_item'], 'permission_callback' => fn($r)=>$this->permission_manage('opb_manage_tasks',$r) ],
        ]);
    }

    public function get_items( $r ) {
        $check = $this->permission_check($r); if(is_wp_error($check)) return $check;
        global $wpdb;

        $branch_id = $this->branch_filter((int)($r->get_param('branch_id')??0));
        $status    = sanitize_text_field($r->get_param('status')??'');
        $priority  = sanitize_text_field($r->get_param('priority')??'');
        $assignee  = sanitize_text_field($r->get_param('assignee')??'');
        $page      = max(1,(int)($r->get_param('page')??1));
        $per_page  = min(100,(int)($r->get_param('per_page')??50));
        $offset    = ($page-1)*$per_page;

        $where=['1=1']; $args=[];
        if($branch_id){ $where[]='t.branch_id=%d'; $args[]=$branch_id; }
        if($status){    $where[]='t.status=%s';     $args[]=$status;    }
        if($priority){  $where[]='t.priority=%s';   $args[]=$priority;  }
        if($assignee){  $where[]='t.assignee=%s';   $args[]=$assignee;  }
        $where_sql=implode(' AND ',$where);

        $total=(int)$wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}opb_tasks t WHERE $where_sql",...$args
        ));
        $rows=$wpdb->get_results($wpdb->prepare(
            "SELECT t.*, c.name as client_name, b.name as branch_name
             FROM {$wpdb->prefix}opb_tasks t
             LEFT JOIN {$wpdb->prefix}opb_clients c ON c.id=t.client_id
             LEFT JOIN {$wpdb->prefix}opb_branches b ON b.id=t.branch_id
             WHERE $where_sql
             ORDER BY FIELD(t.priority,'High','Medium','Low'), t.due_date ASC, t.id DESC
             LIMIT %d OFFSET %d",
            ...[...$args,$per_page,$offset]
        ),ARRAY_A);

        return $this->success($this->paginate($rows,$total,$page,$per_page));
    }

    public function get_item( $r ) {
        $check = $this->permission_check($r); if(is_wp_error($check)) return $check;
        global $wpdb;
        $row=$wpdb->get_row($wpdb->prepare(
            "SELECT t.*, c.name as client_name, b.name as branch_name
             FROM {$wpdb->prefix}opb_tasks t
             LEFT JOIN {$wpdb->prefix}opb_clients c ON c.id=t.client_id
             LEFT JOIN {$wpdb->prefix}opb_branches b ON b.id=t.branch_id
             WHERE t.id=%d",(int)$r['id']
        ),ARRAY_A);
        if(!$row) return $this->error('not_found','Task not found',404);
        return $this->success($row);
    }

    public function create_item( $r ) {
        $check = $this->permission_manage('opb_manage_tasks',$r); if(is_wp_error($check)) return $check;
        global $wpdb;
        $d=$r->get_json_params();
        if(empty($d['title'])||empty($d['branch_id'])) return $this->error('invalid','title, branch_id required');
        $wpdb->insert("{$wpdb->prefix}opb_tasks",[
            'branch_id'   => (int)$d['branch_id'],
            'client_id'   => isset($d['client_id'])?(int)$d['client_id']:null,
            'title'       => sanitize_text_field($d['title']),
            'description' => sanitize_textarea_field($d['description']??''),
            'status'      => sanitize_text_field($d['status']??'Open'),
            'priority'    => sanitize_text_field($d['priority']??'Medium'),
            'due_date'    => sanitize_text_field($d['due_date']??'') ?: null,
            'assignee'    => sanitize_text_field($d['assignee']??''),
            'assigned_by' => wp_get_current_user()->display_name,
        ]);
        $req=new WP_REST_Request('GET'); $req['id']=(int)$wpdb->insert_id;
        return $this->get_item($req);
    }

    public function update_item( $r ) {
        $check = $this->permission_manage('opb_manage_tasks',$r); if(is_wp_error($check)) return $check;
        global $wpdb;
        $d=$r->get_json_params();
        $allowed=['title','description','status','priority','due_date','assignee','comments','client_id'];
        $update=[];
        foreach($allowed as $k){ if(array_key_exists($k,$d)) $update[$k]=$d[$k]; }
        if($update) $wpdb->update("{$wpdb->prefix}opb_tasks",$update,['id'=>(int)$r['id']]);
        return $this->get_item($r);
    }

    public function delete_item( $r ) {
        $check = $this->permission_manage('opb_manage_tasks',$r); if(is_wp_error($check)) return $check;
        global $wpdb;
        $wpdb->delete("{$wpdb->prefix}opb_tasks",['id'=>(int)$r['id']]);
        return $this->success(['deleted'=>true]);
    }
}
