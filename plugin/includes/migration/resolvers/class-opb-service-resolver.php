<?php
/**
 * OPB_Service_Resolver
 *
 * Resolves a catalogue name (+ optional boarding type) to a boarding_service id.
 * Loads the FLAGS rows for the given branch once on construction.
 */
class OPB_Service_Resolver {

    /** @var object[] keyed by id */
    private array $services = [];

    public function __construct( int $branch_id ) {
        global $wpdb;
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT id, catalogue_name, boarding_type, pet_type
               FROM {$wpdb->prefix}opb_boarding_services
              WHERE branch_id = %d AND row_type = 'FLAGS' AND is_active = 1",
            $branch_id
        ) ) ?: [];
        foreach ( $rows as $r ) {
            $this->services[$r->id] = $r;
        }
    }

    /**
     * Return the first matching service id, or null.
     * Pass $boarding_type = '' to skip the boarding-type filter.
     */
    public function resolve( string $catalogue_name, string $boarding_type = '' ): ?int {
        $norm_name = strtolower(trim($catalogue_name));
        $norm_type = strtoupper(trim($boarding_type));

        foreach ( $this->services as $s ) {
            if ( strtolower(trim($s->catalogue_name)) !== $norm_name ) continue;
            if ( $norm_type && $s->boarding_type !== $norm_type ) continue;
            return (int)$s->id;
        }
        return null;
    }

    public function available(): array {
        return array_map(fn($s) => "{$s->catalogue_name} ({$s->boarding_type})", $this->services);
    }
}
