<?php
/**
 * Registers OPB roles and capabilities.
 *
 * Roles:
 *   opb_super_admin   – full access to all branches
 *   opb_branch_manager – full access to assigned branch
 *   opb_reception      – bookings, clients, invoices, payments
 *   opb_staff          – read + task updates
 */
class OPB_Roles {

    const ROLES = [
        'opb_super_admin'    => 'OPB Super Admin',
        'opb_branch_manager' => 'OPB Branch Manager',
        'opb_reception'      => 'OPB Reception',
        'opb_staff'          => 'OPB Staff',
    ];

    const CAPS = [
        'opb_manage_settings',
        'opb_manage_users',
        'opb_view_all_branches',
        'opb_manage_clients',
        'opb_manage_pets',
        'opb_manage_bookings',
        'opb_manage_invoices',
        'opb_record_payments',
        'opb_manage_tasks',
        'opb_manage_expenses',
        'opb_run_import',
        'opb_view_reports',
    ];

    public static function register(): void {
        self::maybe_add_roles();
    }

    private static function maybe_add_roles(): void {
        if ( get_option( 'opb_roles_version' ) === OPB_VERSION ) {
            return;
        }

        // Remove old roles first
        foreach ( array_keys( self::ROLES ) as $role ) {
            remove_role( $role );
        }

        add_role( 'opb_super_admin', 'OPB Super Admin', array_fill_keys( self::CAPS, true ) );

        add_role( 'opb_branch_manager', 'OPB Branch Manager', [
            'opb_manage_clients'  => true,
            'opb_manage_pets'     => true,
            'opb_manage_bookings' => true,
            'opb_manage_invoices' => true,
            'opb_record_payments' => true,
            'opb_manage_tasks'    => true,
            'opb_manage_expenses' => true,
            'opb_view_reports'    => true,
        ] );

        add_role( 'opb_reception', 'OPB Reception', [
            'opb_manage_clients'  => true,
            'opb_manage_pets'     => true,
            'opb_manage_bookings' => true,
            'opb_manage_invoices' => true,
            'opb_record_payments' => true,
            'opb_manage_tasks'    => true,
        ] );

        add_role( 'opb_staff', 'OPB Staff', [
            'opb_manage_tasks'    => true,
        ] );

        update_option( 'opb_roles_version', OPB_VERSION );
    }

    public static function remove(): void {
        foreach ( array_keys( self::ROLES ) as $role ) {
            remove_role( $role );
        }
        delete_option( 'opb_roles_version' );
    }

    public static function current_user_can_access_branch( int $branch_id ): bool {
        $user = wp_get_current_user();
        if ( $user->has_cap( 'opb_view_all_branches' ) ) {
            return true;
        }
        $assigned = get_user_meta( $user->ID, 'opb_branch_id', true );
        return (int) $assigned === $branch_id;
    }

    public static function get_user_branch_id(): int {
        $user = wp_get_current_user();
        if ( $user->has_cap( 'opb_view_all_branches' ) ) {
            return 0; // 0 = all branches
        }
        return (int) get_user_meta( $user->ID, 'opb_branch_id', true );
    }

    public static function has_opb_role(): bool {
        $user = wp_get_current_user();
        foreach ( array_keys( self::ROLES ) as $role ) {
            if ( in_array( $role, (array) $user->roles, true ) ) {
                return true;
            }
        }
        return user_can( $user, 'manage_options' );
    }
}
