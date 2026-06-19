<?php
/**
 * Registers OPB roles and capabilities.
 *
 * Roles:
 *   opb_super_admin   – full access to all branches (global)
 *   opb_branch_manager – full access to assigned branch (branch-scoped)
 *   opb_reception      – bookings, clients, invoices, payments (branch-scoped)
 *   opb_staff          – read + task updates (branch-scoped)
 *
 * Branch-scoped roles MUST have a valid opb_branch_id user meta (>0).
 * A branch-scoped user without a branch assignment is treated as a
 * configuration error and receives HTTP 403 on all OPB REST endpoints.
 * get_user_branch_id() returns -1 (denied sentinel) in this case.
 */
class OPB_Roles {

    const ROLES = [
        'opb_super_admin'    => 'OPB Super Admin',
        'opb_branch_manager' => 'OPB Branch Manager',
        'opb_reception'      => 'OPB Reception',
        'opb_staff'          => 'OPB Staff',
    ];

    /**
     * Roles that require a branch assignment.
     * opb_super_admin and WP administrator are global — no branch required.
     */
    const BRANCH_SCOPED_ROLES = [
        'opb_branch_manager',
        'opb_reception',
        'opb_staff',
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

    // ── Branch scope helpers ───────────────────────────────────────────────────

    /**
     * Returns true if the given role slug is branch-scoped
     * (i.e. requires a branch assignment).
     */
    public static function is_branch_scoped_role( string $role ): bool {
        return in_array( $role, self::BRANCH_SCOPED_ROLES, true );
    }

    /**
     * Returns true if the given WP_User holds a branch-scoped OPB role.
     */
    public static function user_is_branch_scoped( WP_User $user ): bool {
        foreach ( self::BRANCH_SCOPED_ROLES as $role ) {
            if ( in_array( $role, (array) $user->roles, true ) ) {
                return true;
            }
        }
        return false;
    }

    /**
     * Returns the current user's branch restriction.
     *
     *  0  → unrestricted (WP administrator or opb_super_admin)
     * >0  → single branch (branch-scoped user with a valid assignment)
     * -1  → denied sentinel — branch-scoped user with NO branch assignment.
     *        This is a configuration error; permission_check() returns 403.
     */
    public static function get_user_branch_id(): int {
        $user = wp_get_current_user();

        // Global roles — unrestricted.
        if ( $user->has_cap( 'opb_view_all_branches' ) || $user->has_cap( 'manage_options' ) ) {
            return 0;
        }

        $branch_id = (int) get_user_meta( $user->ID, 'opb_branch_id', true );

        // Branch-scoped users must have a positive branch_id.
        // Missing or zero means a configuration error — return denied sentinel.
        if ( self::user_is_branch_scoped( $user ) && $branch_id < 1 ) {
            return -1;
        }

        return $branch_id;
    }

    public static function current_user_can_access_branch( int $branch_id ): bool {
        $user = wp_get_current_user();
        if ( $user->has_cap( 'opb_view_all_branches' ) ) {
            return true;
        }
        $assigned = get_user_meta( $user->ID, 'opb_branch_id', true );
        return (int) $assigned === $branch_id;
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

    /**
     * Returns all branch-scoped users who have no branch assignment.
     * Used by the admin warning panel.
     *
     * @return WP_User[]
     */
    public static function get_unassigned_branch_scoped_users(): array {
        $users      = get_users( [ 'role__in' => self::BRANCH_SCOPED_ROLES ] );
        $unassigned = [];
        foreach ( $users as $user ) {
            $branch_id = (int) get_user_meta( $user->ID, 'opb_branch_id', true );
            if ( $branch_id < 1 ) {
                $unassigned[] = $user;
            }
        }
        return $unassigned;
    }
}
