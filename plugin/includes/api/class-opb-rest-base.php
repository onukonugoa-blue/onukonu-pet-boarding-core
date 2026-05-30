<?php
/**
 * Base class for all OPB REST controllers.
 */
abstract class OPB_REST_Base extends WP_REST_Controller {

    protected $namespace = 'opb/v1';

    public function permission_check( WP_REST_Request $request ): bool|WP_Error {
        if ( ! is_user_logged_in() ) {
            return new WP_Error( 'rest_forbidden', 'You must be logged in.', [ 'status' => 401 ] );
        }
        if ( ! OPB_Roles::has_opb_role() ) {
            return new WP_Error( 'rest_forbidden', 'Insufficient permissions.', [ 'status' => 403 ] );
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

    protected function branch_filter( int $branch_id ): int {
        $user_branch = OPB_Roles::get_user_branch_id();
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
