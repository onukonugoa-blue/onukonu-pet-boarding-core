<?php
/**
 * OPB_Food_Type_Resolver
 *
 * Maps arbitrary meal-type strings to the DB ENUM:
 *   BOARDING_MEALS | PARENT_SUPPLIED_MEAL
 */
class OPB_Food_Type_Resolver {

    private static array $map = [
        'boarding_meals'       => 'BOARDING_MEALS',
        'boarding meals'       => 'BOARDING_MEALS',
        'boarding'             => 'BOARDING_MEALS',
        'facility food'        => 'BOARDING_MEALS',
        'provided'             => 'BOARDING_MEALS',
        'facility'             => 'BOARDING_MEALS',
        'parent_supplied_meal' => 'PARENT_SUPPLIED_MEAL',
        'parent supplied meal' => 'PARENT_SUPPLIED_MEAL',
        'parent supplied'      => 'PARENT_SUPPLIED_MEAL',
        'home food'            => 'PARENT_SUPPLIED_MEAL',
        'owner food'           => 'PARENT_SUPPLIED_MEAL',
        'own food'             => 'PARENT_SUPPLIED_MEAL',
        'self'                 => 'PARENT_SUPPLIED_MEAL',
        'home'                 => 'PARENT_SUPPLIED_MEAL',
    ];

    public function resolve( string $raw ): string {
        $key = strtolower(trim($raw));
        return self::$map[$key] ?? 'PARENT_SUPPLIED_MEAL';
    }
}
