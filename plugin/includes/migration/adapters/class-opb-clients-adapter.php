<?php
/**
 * Clients import adapter.
 * Imports pet-parent records (+ one pet per row when Pet Name is present).
 * Duplicate detection: phone number.
 */
class OPB_Clients_Adapter extends OPB_Import_Adapter {

    public function entity(): string { return 'clients'; }

    public function column_groups(): array {
        return [
            '*phone'          => ['Phone Number','phone number','Phone','phone','Mobile','mobile'],
            '*name'           => ['Name','name','Client Name','client_name','Full Name','full_name'],
            'branch'          => ['Home Outlet','home_outlet','Branch','branch','Home Branch'],
            'email'           => ['Email','email'],
            'address'         => ['Address','address'],
            'onboarding_date' => ['Onboarding Date','onboarding_date'],
            'pet_name'        => ['Pet Name','pet_name'],
            'pet_type'        => ['Pet Type','pet_type'],
            'gender'          => ['Gender','gender'],
            'breed'           => ['Breed','breed'],
            'breed_size'      => ['Breed Size','breed_size'],
            'legacy_id'       => ['Pet ID','pet_id','Legacy ID','legacy_id'],
        ];
    }

    protected function process_row( array $row, int $row_num, bool $dry, array $ctx ): array {
        global $wpdb;

        $phone = $this->col($row, ['Phone Number','phone number','Phone','phone','Mobile','mobile']);
        if ( ! $phone ) {
            return ['status'=>'skipped','reason_code'=>'missing_phone','detail'=>"No value in any phone column alias"];
        }

        $name = $this->col($row, ['Name','name','Client Name','client_name','Full Name','full_name']);
        if ( ! $name ) {
            return ['status'=>'skipped','reason_code'=>'missing_name','detail'=>"Phone $phone has no name value"];
        }

        // Branch resolution
        $resolver  = OPB_Branch_Resolver::from_db();
        $raw_outlet = $this->col($row, ['Home Outlet','home_outlet','Branch','branch','Home Branch']);

        // Allow branch from context (used when CSV has no branch column)
        if ( ! $raw_outlet && isset($ctx['branch_id']) ) {
            $branch_id = (int)$ctx['branch_id'];
        } else {
            $resolved  = $resolver->resolve($raw_outlet ?: '');
            $branch_id = $resolved['branch'] ? (int)$resolved['branch']->id : 0;
            if ( ! $branch_id ) {
                $avail = implode(', ', $resolver->describe_branches());
                return ['status'=>'skipped','reason_code'=>'branch_not_found',
                    'detail'=>"Branch '$raw_outlet' not resolved. Available: $avail"];
            }
        }

        // Duplicate check
        $existing = (int)$wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}opb_clients WHERE phone=%s", $phone
        ));
        if ( $existing ) {
            return ['status'=>'skipped','reason_code'=>'duplicate',
                'detail'=>"Phone $phone already in opb_clients (id=$existing)"];
        }

        if ( ! $dry ) {
            $legacy_id = $this->col($row, ['Pet ID','pet_id','Legacy ID','legacy_id']);
            $od        = $this->col($row, ['Onboarding Date','onboarding_date']);

            $wpdb->insert("{$wpdb->prefix}opb_clients", [
                'home_branch_id'  => $branch_id,
                'name'            => sanitize_text_field($name),
                'phone'           => sanitize_text_field($phone),
                'email'           => sanitize_email($this->col($row,['Email','email'])),
                'address'         => sanitize_textarea_field($this->col($row,['Address','address'])),
                'onboarding_date' => $od ? $this->parse_date($od) : null,
                'tc_accepted'     => 1,
                'legacy_id'       => $legacy_id !== '' ? (int)$legacy_id : null,
                'status'          => 'active',
            ]);
            $client_id = (int)$wpdb->insert_id;

            $pet_name = $this->col($row, ['Pet Name','pet_name']);
            if ( $pet_name && $client_id ) {
                $breed_res  = new OPB_Breed_Resolver();
                $raw_breed  = $this->col($row, ['Breed','breed']);
                $breed_size = $this->col($row, ['Breed Size','breed_size']);
                if ( ! $breed_size ) {
                    $breed_size = $breed_res->infer_size($raw_breed) ?? '';
                }
                $breed_size = in_array($breed_size, ['Small','Medium','Large']) ? $breed_size : null;

                $pet_type = match(strtolower($this->col($row,['Pet Type','pet_type'],'dog'))) {
                    'cat'  => 'Cat',
                    'other'=> 'Other',
                    default=> 'Dog',
                };
                $gender = match(strtolower($this->col($row,['Gender','gender'],'unknown'))) {
                    'male','m'   => 'Male',
                    'female','f' => 'Female',
                    default      => 'Unknown',
                };

                $wpdb->insert("{$wpdb->prefix}opb_pets", [
                    'client_id'  => $client_id,
                    'name'       => sanitize_text_field($pet_name),
                    'pet_type'   => $pet_type,
                    'breed'      => sanitize_text_field($breed_res->normalise($raw_breed)),
                    'breed_size' => $breed_size,
                    'gender'     => $gender,
                    'legacy_id'  => $legacy_id !== '' ? (int)$legacy_id : null,
                ]);
            }
        }

        return ['status'=>'imported','reason_code'=>'','detail'=>''];
    }
}
