<?php
/**
 * Add-on services import adapter.
 * CSV headers: Name, Description, Type, Base Amount, Visibility Status,
 *              Applicable Services, Distance Up To, Distance Slab Amount
 * Requires context: branch_id
 * Duplicate detection: branch_id + name (case-insensitive)
 */
class OPB_Addons_Adapter extends OPB_Import_Adapter {

    public function entity(): string { return 'addons'; }

    public function column_groups(): array {
        return [
            '*Name'              => ['Name','name','Service Name'],
            'Description'        => ['Description','description'],
            'Type'               => ['Type','service_type'],
            'Base Amount'        => ['Base Amount','base_amount','Amount'],
            'Visibility'         => ['Visibility Status','visibility','Status'],
            'Applicable Services'=> ['Applicable Services','applicable_services'],
            'Distance Up To'     => ['Distance Up To','distance_up_to'],
            'Distance Slab Amt'  => ['Distance Slab Amount','distance_slab_amount'],
        ];
    }

    protected function process_row( array $row, int $row_num, bool $dry, array $ctx ): array {
        global $wpdb;

        $branch_id = (int)($ctx['branch_id'] ?? 0);
        if ( ! $branch_id ) {
            return ['status'=>'skipped','reason_code'=>'missing_branch','detail'=>"branch_id required in context"];
        }

        $name = $this->col($row, ['Name','name','Service Name']);
        if ( ! $name ) {
            return ['status'=>'skipped','reason_code'=>'missing_name','detail'=>"Row $row_num: Name is empty"];
        }

        if ( ! $dry ) {
            $exists = (int)$wpdb->get_var($wpdb->prepare(
                "SELECT id FROM {$wpdb->prefix}opb_addon_services
                  WHERE branch_id=%d AND LOWER(name)=LOWER(%s) LIMIT 1",
                $branch_id, trim($name)
            ));
            if ( $exists ) {
                return ['status'=>'skipped','reason_code'=>'duplicate',
                    'detail'=>"Add-on '$name' already exists for this branch (id=$exists)"];
            }

            $stype = strtoupper($this->col($row, ['Type','service_type'], 'FLAT'));
            $stype = in_array($stype, ['FLAT','DISTANCE_SLAB']) ? $stype : 'FLAT';
            $vis   = strtoupper($this->col($row, ['Visibility Status','visibility','Status'], 'PUBLIC'));
            $vis   = in_array($vis, ['PUBLIC','PRIVATE']) ? $vis : 'PUBLIC';

            $dist_up = $this->col($row, ['Distance Up To','distance_up_to']);
            $dist_slab = $this->col($row, ['Distance Slab Amount','distance_slab_amount']);

            $wpdb->insert("{$wpdb->prefix}opb_addon_services", [
                'branch_id'            => $branch_id,
                'name'                 => sanitize_text_field(trim($name)),
                'description'          => sanitize_textarea_field($this->col($row,['Description','description'])),
                'service_type'         => $stype,
                'base_amount'          => (float)$this->col($row,['Base Amount','base_amount','Amount'], '0'),
                'visibility'           => $vis,
                'applicable_services'  => sanitize_text_field($this->col($row,['Applicable Services','applicable_services'])) ?: null,
                'distance_up_to'       => $dist_up   !== '' ? (float)$dist_up   : null,
                'distance_slab_amount' => $dist_slab !== '' ? (float)$dist_slab : null,
                'is_active'            => 1,
                'sort_order'           => $row_num,
            ]);
        }

        return ['status'=>'imported','reason_code'=>'','detail'=>''];
    }
}
