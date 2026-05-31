<?php
/**
 * OPB_Breed_Resolver
 *
 * Normalises breed names and infers breed_size when not explicitly provided.
 */
class OPB_Breed_Resolver {

    private static array $size_map = [
        'Small' => [
            'chihuahua','pomeranian','yorkshire terrier','yorkie','maltese','shih tzu',
            'pug','dachshund','beagle','cocker spaniel','cavalier','bichon frise',
            'miniature pinscher','min pin','toy poodle','spitz','indian spitz',
            'jack russell','lhasa apso','pekinese','pekingese',
        ],
        'Medium' => [
            'labrador retriever','labrador','lab','golden retriever','golden',
            'bulldog','border collie','australian shepherd','husky','siberian husky',
            'dalmatian','standard poodle','poodle','boxer','springer spaniel',
            'shetland sheepdog','sheltie','cocker','whippet','basenji',
            'indian pariah','indie','mongrel','mixed breed',
        ],
        'Large' => [
            'german shepherd','gsd','rottweiler','great dane','saint bernard','mastiff',
            'dobermann','doberman','weimaraner','irish wolfhound','newfoundland',
            'bernese mountain dog','alaskan malamute','great pyrenees','belgian malinois',
        ],
    ];

    /** Basic title-case + whitespace normalisation. */
    public function normalise( string $raw ): string {
        return ucwords( strtolower( trim( preg_replace('/\s+/', ' ', $raw) ) ) );
    }

    /**
     * Infer breed size from breed name. Returns null when unknown.
     */
    public function infer_size( string $breed ): ?string {
        $key = strtolower(trim($breed));
        foreach ( self::$size_map as $size => $breeds ) {
            foreach ( $breeds as $b ) {
                if ( str_contains($key, $b) || str_contains($b, $key) ) {
                    return $size;
                }
            }
        }
        return null;
    }
}
