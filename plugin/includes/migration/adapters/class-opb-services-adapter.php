<?php
/**
 * Boarding Catalogue (services) import adapter.
 * Each CSV row maps 1:1 to one opb_boarding_services record.
 *
 * CSV headers:
 *   Catalogue Name, Boarding Type Split Factor, Min Age (Months), Max Age (Months),
 *   Pet Type Split Factor, Row Type, Amount, Discount Type, Breed Size, Kennel Category,
 *   Meal Name, Meal Type, Price Type, Modifies Base Bill, Min Pets, Days, Breed, Extra Info
 *
 * Requires context: branch_id
 * Duplicate detection: branch_id + catalogue_name + boarding_type + row_type + breed_size + min_pets + days
 */
class OPB_Services_Adapter extends OPB_Import_Adapter {

    public function entity(): string { return 'services'; }

    public function column_groups(): array {
        return [
            '*Catalogue Name' => ['Catalogue Name','catalogue_name','Service Name','name'],
            '*Boarding Type'  => ['Boarding Type Split Factor','Boarding Type','boarding_type'],
            '*Row Type'       => ['Row Type','row_type'],
            'Pet Type'        => ['Pet Type Split Factor','Pet Type','pet_type'],
            'Amount'          => ['Amount','amount'],
            'Discount Type'   => ['Discount Type','discount_type'],
            'Breed Size'      => ['Breed Size','breed_size'],
            'Min Pets'        => ['Min Pets','min_pets'],
            'Days'            => ['Days','days'],
            'Min Age'         => ['Min Age (Months)','min_age_months'],
            'Max Age'         => ['Max Age (Months)','max_age_months'],
            'Extra Info'      => ['Extra Info','extra_info'],
        ];
    }

    protected function process_row( array $row, int $row_num, bool $dry, array $ctx ): array {
        global $wpdb;

        $branch_id = (int)($ctx['branch_id'] ?? 0);
        if ( ! $branch_id ) {
            return ['status'=>'skipped','reason_code'=>'missing_branch','detail'=>"branch_id required in context"];
        }

        $catalogue = $this->col($row, ['Catalogue Name','catalogue_name','Service Name','name']);
        if ( ! $catalogue ) {
            return ['status'=>'skipped','reason_code'=>'missing_catalogue_name','detail'=>"Row $row_num: Catalogue Name is empty"];
        }

        $row_type = $this->col($row, ['Row Type','row_type']);
        if ( ! $row_type ) {
            return ['status'=>'skipped','reason_code'=>'missing_row_type','detail'=>"Row $row_num: Row Type is empty"];
        }

        $boarding = strtoupper($this->col($row, ['Boarding Type Split Factor','Boarding Type','boarding_type']));
        if ( ! in_array($boarding, ['DAY','OVERNIGHT']) ) {
            return ['status'=>'skipped','reason_code'=>'invalid_boarding_type',
                'detail'=>"Row $row_num: boarding_type '$boarding' must be DAY or OVERNIGHT"];
        }

        $pet_type = strtoupper($this->col($row, ['Pet Type Split Factor','Pet Type','pet_type'], 'ANY'));
        $pet_type = in_array($pet_type, ['DOG','CAT','ANY']) ? $pet_type : 'ANY';

        $breed_size = $this->col($row, ['Breed Size','breed_size']);
        $breed_size = in_array($breed_size, ['Small','Medium','Large']) ? $breed_size : null;

        $min_pets = $this->col($row, ['Min Pets','min_pets']);
        $days     = $this->col($row, ['Days','days']);
        $amount   = $this->col($row, ['Amount','amount']);

        if ( ! $dry ) {
            // Duplicate check
            $exists = $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM {$wpdb->prefix}opb_boarding_services
                  WHERE branch_id=%d AND catalogue_name=%s AND boarding_type=%s AND row_type=%s
                    AND COALESCE(breed_size,'')=%s
                    AND COALESCE(min_pets,0)=%d
                    AND COALESCE(days,0)=%d
                  LIMIT 1",
                $branch_id,
                trim($catalogue),
                $boarding,
                $row_type,
                $breed_size ?? '',
                $min_pets !== '' ? (int)$min_pets : 0,
                $days     !== '' ? (int)$days     : 0
            ));
            if ( $exists ) {
                return ['status'=>'skipped','reason_code'=>'duplicate',
                    'detail'=>"Service row already exists (id=$exists)"];
            }

            $wpdb->insert("{$wpdb->prefix}opb_boarding_services", [
                'branch_id'         => $branch_id,
                'catalogue_name'    => sanitize_text_field(trim($catalogue)),
                'boarding_type'     => $boarding,
                'pet_type'          => $pet_type,
                'row_type'          => sanitize_text_field($row_type),
                'amount'            => $amount !== '' ? (float)$amount : null,
                'discount_type'     => sanitize_text_field($this->col($row,['Discount Type','discount_type'])) ?: null,
                'breed_size'        => $breed_size,
                'kennel_category'   => sanitize_text_field($this->col($row,['Kennel Category','kennel_category'])) ?: null,
                'meal_name'         => sanitize_text_field($this->col($row,['Meal Name','meal_name'])) ?: null,
                'meal_type'         => sanitize_text_field($this->col($row,['Meal Type','meal_type'])) ?: null,
                'price_type'        => sanitize_text_field($this->col($row,['Price Type','price_type'])) ?: null,
                'modifies_base_bill'=> (int)(trim($this->col($row,['Modifies Base Bill','modifies_base_bill'])) === '1'),
                'min_pets'          => $min_pets !== '' ? (int)$min_pets : null,
                'days'              => $days     !== '' ? (int)$days     : null,
                'min_age_months'    => ($v=$this->col($row,['Min Age (Months)','min_age_months'])) !== '' ? (int)$v : null,
                'max_age_months'    => ($v=$this->col($row,['Max Age (Months)','max_age_months'])) !== '' ? (int)$v : null,
                'breed'             => sanitize_text_field($this->col($row,['Breed','breed'])) ?: null,
                'extra_info'        => sanitize_textarea_field($this->col($row,['Extra Info','extra_info'])) ?: null,
                'is_active'         => 1,
                'sort_order'        => $row_num,
            ]);
        }

        return ['status'=>'imported','reason_code'=>'','detail'=>''];
    }
}
