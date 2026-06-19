<?php
/**
 * Base class for all OPB REST controllers.
 */
abstract class OPB_REST_Base extends WP_REST_Controller {

    protected $namespace = 'opb/v1';

    /**
     * Gate: logged in + holds an OPB role (or manage_options).
     *
     * Also enforces the branch-assignment rule: branch-scoped users
     * (Branch Manager, Reception, Staff) must have a valid opb_branch_id.
     * If they do not, OPB_Roles::get_user_branch_id() returns -1 and we
     * return a 403 configuration-error response rather than granting access.
     */
    public function permission_check( WP_REST_Request $request ): bool|WP_Error {
        if ( ! is_user_logged_in() ) {
            return new WP_Error( 'rest_forbidden', 'You must be logged in.', [ 'status' => 401 ] );
        }
        if ( ! OPB_Roles::has_opb_role() ) {
            return new WP_Error( 'rest_forbidden', 'Insufficient permissions.', [ 'status' => 403 ] );
        }
        // Configuration guard: branch-scoped users must have a branch assignment.
        if ( OPB_Roles::get_user_branch_id() === -1 ) {
            return new WP_Error(
                'opb_no_branch',
                'Your account has no branch assignment. Please contact an administrator.',
                [ 'status' => 403 ]
            );
        }
        return true;
    }

    public function permission_manage( string $cap, WP_REST_Request $request ): bool|WP_Error {
        $check = $this->permission_check( $request );
        if ( is_wp_error( $check ) ) return $check;
        if ( ! current_user_can( $cap ) && ! current_user_can( 'manage_options' ) ) {
            return new WP_Error( 'rest_forbidden', 'Insufficient permissions.', [ 'status' => 403 ] );
        }
        return true;
    }

    protected function paginate( array $results, int $total, int $page, int $per_page ): array {
        return [
            'data'        => $results,
            'total'       => $total,
            'page'        => $page,
            'per_page'    => $per_page,
            'total_pages' => (int) ceil( $total / $per_page ),
        ];
    }

    /**
     * Returns the branch_id to use in queries.
     *
     *  - Unrestricted users (return 0 from get_user_branch_id): pass through
     *    the requested $branch_id parameter (0 = all branches).
     *  - Branch-scoped users (return >0): always override with their branch.
     *  - Denied sentinel (-1): should never reach here because permission_check()
     *    returns 403 first, but treated as an impossible branch to be safe.
     */
    protected function branch_filter( int $branch_id ): int {
        $user_branch = OPB_Roles::get_user_branch_id();
        if ( $user_branch === -1 ) {
            // Denied sentinel — permission_check() should have blocked this.
            // Return an impossible branch_id so queries return nothing.
            return PHP_INT_MAX;
        }
        if ( $user_branch !== 0 ) {
            return $user_branch;
        }
        return $branch_id;
    }

    protected function success( mixed $data, int $status = 200 ): WP_REST_Response {
        return new WP_REST_Response( $data, $status );
    }

    protected function error( string $code, string $message, int $status = 400 ): WP_Error {
        return new WP_Error( $code, $message, [ 'status' => $status ] );
    }
}
