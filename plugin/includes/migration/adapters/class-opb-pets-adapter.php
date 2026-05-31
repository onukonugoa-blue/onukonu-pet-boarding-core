<?php
/**
 * Pets import adapter.
 * Imports standalone pet records for already-imported clients.
 * Client is identified by phone number.
 * Duplicate detection: client_id + pet name.
 */
class OPB_Pets_Adapter extends OPB_Import_Adapter {

    public function entity(): string { return 'pets'; }

    public function column_groups(): array {
        return [
            '*phone'     => ['Phone Number','phone number','Phone','phone','Mobile','mobile'],
            '*pet_name'  => ['Pet Name','pet_name','Name','name'],
            'pet_type'   => ['Pet Type','pet_type'],
            'breed'      => ['Breed','breed'],
            'breed_size' => ['Breed Size','breed_size'],
            'gender'     => ['Gender','gender'],
            'birthday'   => ['Birthday','birthday','DOB','date_of_birth'],
            'weight'     => ['Weight','weight','Weight (kg)'],
            'legacy_id'  => ['Pet ID','pet_id','Legacy ID','legacy_id'],
            'microchip'  => ['Microchip Number','microchip_number','Microchip'],
            'neutered'   => ['Neutered/Spayed','neutered_or_spayed','Spayed','Neutered'],
        ];
    }

    protected function process_row( array $row, int $row_num, bool $dry, array $ctx ): array {
        global $wpdb;

        $phone = $this->col($row, ['Phone Number','phone number','Phone','phone','Mobile','mobile']);
        if ( ! $phone ) {
            return ['status'=>'skipped','reason_code'=>'missing_phone','detail'=>"No phone value to identify owner"];
        }

        $pet_name = $this->col($row, ['Pet Name','pet_name','Name','name']);
        if ( ! $pet_name ) {
            return ['status'=>'skipped','reason_code'=>'missing_pet_name','detail'=>"Phone $phone: no pet name"];
        }

        $client_id = (int)$wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}opb_clients WHERE phone=%s", $phone
        ));
        if ( ! $client_id ) {
            return ['status'=>'skipped','reason_code'=>'client_not_found',
                'detail'=>"No client found with phone $phone — import clients first"];
        }

        $exists = (int)$wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}opb_pets WHERE client_id=%d AND name=%s", $client_id, $pet_name
        ));
        if ( $exists ) {
            return ['status'=>'skipped','reason_code'=>'duplicate',
                'detail'=>"Pet '$pet_name' already exists for client $client_id (pet id=$exists)"];
        }

        if ( ! $dry ) {
            $breed_res  = new OPB_Breed_Resolver();
            $raw_breed  = $this->col($row, ['Breed','breed']);
            $breed_size = $this->col($row, ['Breed Size','breed_size']);
            if ( ! $breed_size ) $breed_size = $breed_res->infer_size($raw_breed) ?? '';
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
            $legacy_id = $this->col($row, ['Pet ID','pet_id','Legacy ID','legacy_id']);
            $birthday  = $this->col($row, ['Birthday','birthday','DOB','date_of_birth']);
            $weight    = $this->col($row, ['Weight','weight','Weight (kg)']);
            $microchip = $this->col($row, ['Microchip Number','microchip_number','Microchip']);
            $neutered_raw = strtolower($this->col($row, ['Neutered/Spayed','neutered_or_spayed','Spayed','Neutered']));
            $neutered  = in_array($neutered_raw, ['yes','1','true','y']) ? 1 : 0;

            $wpdb->insert("{$wpdb->prefix}opb_pets", [
                'client_id'        => $client_id,
                'name'             => sanitize_text_field($pet_name),
                'pet_type'         => $pet_type,
                'breed'            => sanitize_text_field($breed_res->normalise($raw_breed)),
                'breed_size'       => $breed_size,
                'gender'           => $gender,
                'birthday'         => $birthday ? $this->parse_date($birthday) : null,
                'weight_kg'        => $weight !== '' ? (float)$weight : null,
                'microchip_number' => sanitize_text_field($microchip) ?: null,
                'neutered_or_spayed' => $neutered,
                'legacy_id'        => $legacy_id !== '' ? (int)$legacy_id : null,
            ]);
        }

        return ['status'=>'imported','reason_code'=>'','detail'=>''];
    }
}
