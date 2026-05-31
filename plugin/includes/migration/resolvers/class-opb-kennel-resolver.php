<?php
/**
 * OPB_Kennel_Resolver
 *
 * Normalises kennel identifiers. Kennels are free-text VARCHAR in
 * opb_booking_stays, so this resolver standardises formatting only.
 */
class OPB_Kennel_Resolver {

    /**
     * Normalise a kennel string.
     * "K3" → "K3", "3" → "K3", "kennel 3" → "K3", "" → null
     */
    public function resolve( string $raw ): ?string {
        $raw = trim($raw);
        if ( $raw === '' ) return null;

        if ( preg_match('/^K\d+$/i', $raw) ) return strtoupper($raw);
        if ( ctype_digit($raw) ) return 'K' . $raw;
        if ( preg_match('/kennel[\s\-_]*(\d+)/i', $raw, $m) ) return 'K' . $m[1];

        return $raw;
    }
}
