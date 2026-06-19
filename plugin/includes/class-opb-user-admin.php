<?php
/**
 * OPB User Admin — WP Admin integration for branch assignment.
 *
 * Responsibilities:
 *  - Adds an "OPB Branch" field to the Edit User and Add New User screens.
 *  - Validates that branch-scoped roles (Branch Manager, Reception, Staff)
 *    always have a branch assignment before the user record is saved.
 *  - Saves the opb_branch_id user meta on profile save and user_register.
 *  - Shows an admin_notices warning when branch-scoped users exist without
 *    a branch assignment (visible to WP administrators on all admin pages).
 */
class OPB_User_Admin {

    public static function register(): void {
        // Edit User profile field and save.
        add_action( 'show_user_profile',        [ self::class, 'render_branch_field'     ] );
        add_action( 'edit_user_profile',         [ self::class, 'render_branch_field'     ] );
        add_action( 'personal_options_update',   [ self::class, 'save_branch_field'       ] );
        add_action( 'edit_user_profile_update',  [ self::class, 'save_branch_field'       ] );
        add_action( 'user_profile_update_errors',[ self::class, 'validate_branch_on_edit'], 10, 3 );

        // Add New User form field and save.
        add_action( 'user_new_form',             [ self::class, 'render_branch_field_new' ] );
        add_action( 'user_register',             [ self::class, 'save_branch_on_register'], 10, 2 );

        // Admin notice for unassigned users (WP admin only).
        add_action( 'admin_notices',             [ self::class, 'maybe_show_unassigned_notice' ] );
    }

    // ── Branch options helper ──────────────────────────────────────────────────

    /**
     * Returns an array of ['id' => int, 'name' => string] branch rows.
     */
    private static function get_branches(): array {
        global $wpdb;
        return $wpdb->get_results(
            "SELECT id, name FROM {$wpdb->prefix}opb_branches WHERE is_active = 1 ORDER BY name",
            ARRAY_A
        ) ?: [];
    }

    // ── Edit User profile field ────────────────────────────────────────────────

    public static function render_branch_field( WP_User $user ): void {
        if ( ! current_user_can( 'manage_options' ) && ! current_user_can( 'opb_manage_users' ) ) {
            return;
        }
        $branches    = self::get_branches();
        $current_bid = (int) get_user_meta( $user->ID, 'opb_branch_id', true );
        $is_scoped   = OPB_Roles::user_is_branch_scoped( $user );
        ?>
        <h3>OPB Branch Assignment</h3>
        <table class="form-table">
            <tr>
                <th><label for="opb_branch_id">
                    Branch
                    <?php if ( $is_scoped ) : ?>
                        <span style="color:#dc2626" title="Required for this role">*</span>
                    <?php endif; ?>
                </label></th>
                <td>
                    <?php if ( empty( $branches ) ) : ?>
                        <p style="color:#6b7280">No active branches found. Please create branches first.</p>
                    <?php else : ?>
                        <select name="opb_branch_id" id="opb_branch_id">
                            <option value="">— Not Assigned —</option>
                            <?php foreach ( $branches as $b ) : ?>
                                <option value="<?php echo (int) $b['id']; ?>"
                                    <?php selected( $current_bid, (int) $b['id'] ); ?>>
                                    <?php echo esc_html( $b['name'] ); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    <?php endif; ?>
                    <?php wp_nonce_field( 'opb_save_branch_' . $user->ID, 'opb_branch_nonce' ); ?>
                    <p class="description">
                        <?php if ( $is_scoped ) : ?>
                            <strong>Required.</strong> Branch Manager, Reception and Staff users must have a branch.
                        <?php else : ?>
                            Not required for global roles (WP Administrator, OPB Super Admin).
                        <?php endif; ?>
                    </p>
                </td>
            </tr>
        </table>
        <?php
    }

    // ── Edit User validation ───────────────────────────────────────────────────

    /**
     * Fires during Edit User save. Adds an error to the WP_Error object if
     * a branch-scoped role is set without a branch.
     *
     * @param WP_Error $errors Passed by reference.
     * @param bool     $update True on update, false on create (always true here).
     * @param stdClass $user   The user object being saved.
     */
    public static function validate_branch_on_edit( WP_Error $errors, bool $update, stdClass $user ): void {
        $role      = sanitize_text_field( $_POST['role'] ?? '' );
        $branch_id = (int) ( $_POST['opb_branch_id'] ?? 0 );

        if ( OPB_Roles::is_branch_scoped_role( $role ) && $branch_id < 1 ) {
            $errors->add(
                'opb_branch_required',
                '<strong>Branch assignment is required for Branch Manager, Reception and Staff roles.</strong> Please select a branch.'
            );
        }
    }

    // ── Edit User save ─────────────────────────────────────────────────────────

    public static function save_branch_field( int $user_id ): void {
        if ( ! isset( $_POST['opb_branch_nonce'] ) ) {
            return;
        }
        if ( ! wp_verify_nonce( $_POST['opb_branch_nonce'], 'opb_save_branch_' . $user_id ) ) {
            return;
        }
        if ( ! current_user_can( 'manage_options' ) && ! current_user_can( 'opb_manage_users' ) ) {
            return;
        }

        $branch_id = (int) ( $_POST['opb_branch_id'] ?? 0 );

        if ( $branch_id > 0 ) {
            update_user_meta( $user_id, 'opb_branch_id', $branch_id );
        } else {
            delete_user_meta( $user_id, 'opb_branch_id' );
        }
    }

    // ── Add New User field ─────────────────────────────────────────────────────

    public static function render_branch_field_new( string $type ): void {
        if ( ! current_user_can( 'manage_options' ) && ! current_user_can( 'opb_manage_users' ) ) {
            return;
        }
        $branches = self::get_branches();
        $scoped   = OPB_Roles::BRANCH_SCOPED_ROLES;
        ?>
        <table class="form-table" id="opb-branch-row" style="display:none">
            <tr>
                <th><label for="opb_branch_id_new">Branch <span style="color:#dc2626">*</span></label></th>
                <td>
                    <?php if ( empty( $branches ) ) : ?>
                        <p style="color:#6b7280">No active branches found.</p>
                    <?php else : ?>
                        <select name="opb_branch_id" id="opb_branch_id_new">
                            <option value="">— Select a Branch —</option>
                            <?php foreach ( $branches as $b ) : ?>
                                <option value="<?php echo (int) $b['id']; ?>">
                                    <?php echo esc_html( $b['name'] ); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    <?php endif; ?>
                    <?php wp_nonce_field( 'opb_save_branch_new', 'opb_branch_nonce_new' ); ?>
                    <p class="description">Required for Branch Manager, Reception and Staff roles.</p>
                </td>
            </tr>
        </table>
        <script>
        (function() {
            var scopedRoles = <?php echo wp_json_encode( $scoped ); ?>;
            var roleSelect  = document.getElementById('role');
            var branchRow   = document.getElementById('opb-branch-row');
            var branchSel   = document.getElementById('opb_branch_id_new');
            if ( ! roleSelect || ! branchRow ) return;

            function toggle() {
                var role = roleSelect.value;
                if ( scopedRoles.indexOf(role) !== -1 ) {
                    branchRow.style.display = '';
                    if ( branchSel ) branchSel.setAttribute('required', 'required');
                } else {
                    branchRow.style.display = 'none';
                    if ( branchSel ) branchSel.removeAttribute('required');
                }
            }

            roleSelect.addEventListener('change', toggle);
            toggle(); // run on load in case of page restore
        })();
        </script>
        <?php
    }

    // ── Add New User save ──────────────────────────────────────────────────────

    /**
     * Fires after a new user is registered from the WP admin Add New User screen.
     * Saves opb_branch_id if the nonce is present and a branch was selected.
     *
     * @param int   $user_id  Newly created user ID.
     * @param array $userdata The data passed to wp_insert_user() (WP 5.8+).
     *                        On older hooks this may be absent; we use $_POST directly.
     */
    public static function save_branch_on_register( int $user_id, mixed $userdata = null ): void {
        if ( ! isset( $_POST['opb_branch_nonce_new'] ) ) {
            return;
        }
        if ( ! wp_verify_nonce( $_POST['opb_branch_nonce_new'], 'opb_save_branch_new' ) ) {
            return;
        }

        $branch_id = (int) ( $_POST['opb_branch_id'] ?? 0 );
        if ( $branch_id > 0 ) {
            update_user_meta( $user_id, 'opb_branch_id', $branch_id );
        }

        // Server-side check: if role is branch-scoped and no branch was set,
        // add a transient admin notice so the administrator is alerted.
        $role = sanitize_text_field( $_POST['role'] ?? '' );
        if ( OPB_Roles::is_branch_scoped_role( $role ) && $branch_id < 1 ) {
            $user = get_user_by( 'id', $user_id );
            set_transient(
                'opb_branch_missing_notice_' . get_current_user_id(),
                sprintf(
                    'OPB Warning: The user <strong>%s</strong> was created with role <em>%s</em> but has no branch assignment. This user cannot access OPB until a branch is assigned. <a href="%s">Edit user</a>.',
                    esc_html( $user ? $user->display_name : "#{$user_id}" ),
                    esc_html( OPB_Roles::ROLES[ $role ] ?? $role ),
                    esc_url( get_edit_user_link( $user_id ) )
                ),
                60
            );
        }
    }

    // ── Admin notice: unassigned users warning ─────────────────────────────────

    public static function maybe_show_unassigned_notice(): void {
        // Show transient one-time notice from register.
        $transient_key = 'opb_branch_missing_notice_' . get_current_user_id();
        $transient_msg = get_transient( $transient_key );
        if ( $transient_msg ) {
            delete_transient( $transient_key );
            echo '<div class="notice notice-error"><p>' . $transient_msg . '</p></div>';
        }

        // Only show the unassigned-users warning on OPB admin pages to admins.
        $screen = get_current_screen();
        if ( ! $screen ) {
            return;
        }
        $is_opb_page = str_contains( $screen->id ?? '', 'opb' );
        if ( ! $is_opb_page ) {
            return;
        }
        if ( ! current_user_can( 'manage_options' ) && ! current_user_can( 'opb_manage_users' ) ) {
            return;
        }

        $unassigned = OPB_Roles::get_unassigned_branch_scoped_users();
        if ( empty( $unassigned ) ) {
            return;
        }

        $user_management_url = admin_url( 'admin.php?page=opb-user-management' );
        echo '<div class="notice notice-warning" style="padding:12px 16px">';
        echo '<p><strong>⚠ OPB: ' . count( $unassigned ) . ' user' . ( count( $unassigned ) > 1 ? 's' : '' )
             . ' require a branch assignment.</strong> '
             . 'Branch-scoped users without a branch cannot access OPB. '
             . '<a href="' . esc_url( $user_management_url ) . '">Review in User Management →</a></p>';
        echo '</div>';
    }
}
