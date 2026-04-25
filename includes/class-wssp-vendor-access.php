<?php
/**
 * Vendor account access — role capabilities, user fields, login flow.
 *
 * Provides everything needed to make the existing 'wssp_vendor' role
 * useful as an actual login identity for AV / Print / Hotel vendors:
 *
 *   - Adds the read_wssp_vendor_report capability to the role
 *     (idempotent; re-running is safe).
 *   - Adds a "Vendor type" select on the user-add and user-edit screens
 *     so logistics can pick av / print / hotel for each vendor user.
 *   - Redirects vendors to their report after login instead of the
 *     normal WP dashboard.
 *   - Strips the wp-admin menu down to just the vendor report for
 *     vendor users, hides the admin bar on the front-end.
 *
 * The actual report is rendered by WSSP_Reports::render_vendor_report().
 * This class is purely concerned with WHO can see it and HOW they get
 * there.
 *
 * @package WSSP
 */

defined( 'ABSPATH' ) || exit;

class WSSP_Vendor_Access {

    /** Custom capability — gates the vendor report. */
    const CAP_VIEW_REPORT = 'read_wssp_vendor_report';

    /** User meta key holding the vendor's type (av / print / hotel). */
    const META_VENDOR_TYPE = 'wssp_vendor_type';

    /** WordPress role slug. Pre-registered by WSSP_Activator. */
    const ROLE = 'wssp_vendor';

    /** Allowed vendor types. Source of truth: portal-config vendor_views. */
    const VENDOR_TYPES = array( 'av', 'print', 'hotel' );

    public function __construct() {
        // Cap install: idempotent, safe to run on every load.
        add_action( 'init', array( $this, 'ensure_capability' ) );

        // User-edit screen: vendor-type selector.
        add_action( 'show_user_profile',          array( $this, 'render_vendor_type_field' ) );
        add_action( 'edit_user_profile',          array( $this, 'render_vendor_type_field' ) );
        add_action( 'user_new_form',              array( $this, 'render_vendor_type_field_new' ) );
        add_action( 'personal_options_update',    array( $this, 'save_vendor_type_field' ) );
        add_action( 'edit_user_profile_update',   array( $this, 'save_vendor_type_field' ) );
        add_action( 'user_register',              array( $this, 'save_vendor_type_field' ) );

        // Login flow: send vendors to their report.
        add_filter( 'login_redirect', array( $this, 'redirect_after_login' ), 10, 3 );

        // Admin chrome: strip the menu for vendors.
        add_action( 'admin_menu',     array( $this, 'cleanup_admin_menu_for_vendors' ), 999 );
        add_action( 'admin_init',     array( $this, 'block_disallowed_admin_pages' ) );
        add_filter( 'show_admin_bar', array( $this, 'hide_admin_bar_for_vendors' ) );
    }

    /* ───────────────────────────────────────────
     * CAPABILITY MANAGEMENT
     * ─────────────────────────────────────────── */

    /**
     * Ensure the vendor role has the report-viewing capability.
     *
     * Activation hooks don't fire on plugin updates — only on activate.
     * Running this on init means an installed plugin that gains a new
     * cap picks it up on the next request, no manual deactivate cycle.
     */
    public function ensure_capability() {
        $role = get_role( self::ROLE );
        if ( ! $role ) {
            return; // Activator hasn't run; nothing to add to.
        }
        if ( ! $role->has_cap( self::CAP_VIEW_REPORT ) ) {
            $role->add_cap( self::CAP_VIEW_REPORT );
        }

        // Logistics also needs the cap (so they see the menu item).
        // We add it to the administrator role explicitly rather than
        // relying on edit_posts → because edit_posts editors might not
        // be running the plugin's logistics workflows.
        $admin = get_role( 'administrator' );
        if ( $admin && ! $admin->has_cap( self::CAP_VIEW_REPORT ) ) {
            $admin->add_cap( self::CAP_VIEW_REPORT );
        }
    }

    /* ───────────────────────────────────────────
     * VENDOR-TYPE USER FIELD
     * ─────────────────────────────────────────── */

    /**
     * Render the vendor-type select on existing-user profile screens.
     *
     * Only visible when editing a wssp_vendor user (to keep the field
     * out of the way for sponsors and admins). Logistics editing a
     * vendor sees the field; vendors editing themselves do not (their
     * type is set by logistics, not self-service).
     */
    public function render_vendor_type_field( $user ) {
        if ( ! current_user_can( 'edit_users' ) ) {
            return;
        }
        if ( ! in_array( self::ROLE, (array) $user->roles, true ) ) {
            return;
        }

        $current = (string) get_user_meta( $user->ID, self::META_VENDOR_TYPE, true );
        $this->render_vendor_type_table_row( $current );
    }

    /**
     * Render the vendor-type select on the Add New User screen.
     *
     * Always visible during user creation. If logistics picks a non-
     * vendor role, the saved value is ignored on save (see save handler).
     */
    public function render_vendor_type_field_new() {
        if ( ! current_user_can( 'create_users' ) ) {
            return;
        }
        $this->render_vendor_type_table_row( '' );
    }

    private function render_vendor_type_table_row( $current ) {
        ?>
        <h2>Satellite Vendor Settings</h2>
        <p class="description">
            For users with the <strong>Satellite Vendor</strong> role only.
            Determines which sessions and fields appear in their vendor report.
        </p>
        <table class="form-table" role="presentation">
            <tr>
                <th>
                    <label for="wssp_vendor_type">Vendor type</label>
                </th>
                <td>
                    <select name="wssp_vendor_type" id="wssp_vendor_type">
                        <option value="">— Not a vendor —</option>
                        <?php foreach ( self::VENDOR_TYPES as $slug ) : ?>
                            <option value="<?php echo esc_attr( $slug ); ?>"
                                    <?php selected( $current, $slug ); ?>>
                                <?php echo esc_html( self::vendor_label( $slug ) ); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </td>
            </tr>
        </table>
        <?php
    }

    /**
     * Save the vendor-type field. Validates against the allowed list,
     * silently drops invalid values (defense in depth — the select
     * should make this impossible already).
     */
    public function save_vendor_type_field( $user_id ) {
        if ( ! current_user_can( 'edit_user', $user_id ) ) {
            return;
        }

        $type = isset( $_POST['wssp_vendor_type'] ) ? sanitize_key( wp_unslash( $_POST['wssp_vendor_type'] ) ) : '';

        if ( $type === '' ) {
            delete_user_meta( $user_id, self::META_VENDOR_TYPE );
            return;
        }

        if ( ! in_array( $type, self::VENDOR_TYPES, true ) ) {
            return; // Drop invalid quietly.
        }

        update_user_meta( $user_id, self::META_VENDOR_TYPE, $type );
    }

    /* ───────────────────────────────────────────
     * LOGIN FLOW
     * ─────────────────────────────────────────── */

    /**
     * Redirect vendor users to their report after login.
     *
     * Other users (admins, sponsors, etc.) are unaffected — return the
     * incoming $redirect_to unchanged.
     */
    public function redirect_after_login( $redirect_to, $requested_redirect_to, $user ) {
        if ( ! $user instanceof WP_User || is_wp_error( $user ) ) {
            return $redirect_to;
        }
        if ( ! self::is_vendor( $user ) ) {
            return $redirect_to;
        }
        return admin_url( 'admin.php?page=wssp-report-vendors' );
    }

    /* ───────────────────────────────────────────
     * ADMIN CHROME CLEANUP
     * ─────────────────────────────────────────── */

    /**
     * Strip wp-admin menus for vendors down to just their report.
     *
     * Runs at priority 999 so it executes after every other menu has
     * been registered. WordPress's role caps already block most
     * unauthorized pages; this just hides the empty menu items so the
     * vendor doesn't see things they can't use.
     */
    public function cleanup_admin_menu_for_vendors() {
        if ( ! self::is_current_user_vendor() ) {
            return;
        }

        global $menu, $submenu;

        // Allowed top-level slugs. The vendor report menu is registered
        // by WSSP_Reports under the wssp-dashboard parent. We have to
        // keep wssp-dashboard so the parent shows up; it'll be aliased
        // to the vendor report (see the Reports class menu setup).
        $allowed_top = array( 'wssp-dashboard' );

        if ( is_array( $menu ) ) {
            foreach ( $menu as $idx => $item ) {
                $slug = $item[2] ?? '';
                if ( ! in_array( $slug, $allowed_top, true ) ) {
                    unset( $menu[ $idx ] );
                }
            }
        }

        // Inside wssp-dashboard, only allow the vendor report submenu.
        if ( isset( $submenu['wssp-dashboard'] ) ) {
            foreach ( $submenu['wssp-dashboard'] as $idx => $item ) {
                $slug = $item[2] ?? '';
                if ( $slug !== 'wssp-report-vendors' ) {
                    unset( $submenu['wssp-dashboard'][ $idx ] );
                }
            }
        }

        // Remove the "Profile" item from the user toolbar's wp-admin
        // menu — vendors editing their profile shouldn't be a thing.
        remove_menu_page( 'profile.php' );
    }

    /**
     * Block direct URL access to wp-admin pages a vendor shouldn't see.
     *
     * Menu hiding is cosmetic; this is the actual gate. A vendor who
     * types /wp-admin/users.php in the address bar gets redirected to
     * their report instead of seeing a permission error.
     */
    public function block_disallowed_admin_pages() {
        if ( ! self::is_current_user_vendor() ) {
            return;
        }

        // Allow wp-admin's own internal pages (admin-ajax, admin-post,
        // load-scripts, etc.) — those don't have a 'page' query arg
        // and the cap system handles them.
        $page = $_GET['page'] ?? '';
        if ( $page === 'wssp-report-vendors' ) {
            return;
        }

        // Allow the post-login landing if WP routes through wp-admin
        // briefly before the redirect filter fires.
        $script = basename( $_SERVER['SCRIPT_NAME'] ?? '' );
        $allowed_scripts = array( 'admin-ajax.php', 'admin-post.php' );
        if ( in_array( $script, $allowed_scripts, true ) ) {
            return;
        }

        // Anything else: bounce to the report.
        wp_safe_redirect( admin_url( 'admin.php?page=wssp-report-vendors' ) );
        exit;
    }

    /**
     * Hide the WP admin bar on the front-end for vendors. They have
     * no reason to interact with WP from a public page.
     */
    public function hide_admin_bar_for_vendors( $show ) {
        if ( self::is_current_user_vendor() ) {
            return false;
        }
        return $show;
    }

    /* ───────────────────────────────────────────
     * STATIC HELPERS
     * ─────────────────────────────────────────── */

    /**
     * Is this user a vendor (member of the wssp_vendor role)?
     *
     * @param WP_User|int $user_or_id
     * @return bool
     */
    public static function is_vendor( $user_or_id ) {
        $user = $user_or_id instanceof WP_User ? $user_or_id : get_userdata( (int) $user_or_id );
        if ( ! $user instanceof WP_User ) {
            return false;
        }
        return in_array( self::ROLE, (array) $user->roles, true );
    }

    /**
     * Convenience — is the *current* user a vendor?
     */
    public static function is_current_user_vendor() {
        return is_user_logged_in() && self::is_vendor( wp_get_current_user() );
    }

    /**
     * Get the vendor type ('av', 'print', 'hotel') for a user, or
     * empty string if not a vendor or type not set.
     */
    public static function get_vendor_type( $user_id ) {
        if ( ! self::is_vendor( (int) $user_id ) ) {
            return '';
        }
        $type = (string) get_user_meta( (int) $user_id, self::META_VENDOR_TYPE, true );
        if ( ! in_array( $type, self::VENDOR_TYPES, true ) ) {
            return '';
        }
        return $type;
    }

    /**
     * Human-readable label for a vendor type slug.
     */
    public static function vendor_label( $slug ) {
        $labels = array(
            'av'    => 'Audio / Visual (AV)',
            'print' => 'Print / Signage (Freeman)',
            'hotel' => 'Hotel / Setup',
        );
        return $labels[ $slug ] ?? ucfirst( $slug );
    }
}
