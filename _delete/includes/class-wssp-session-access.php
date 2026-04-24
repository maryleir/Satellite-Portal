<?php
/**
 * Session access control.
 *
 * Every page load and AJAX request should check permissions
 * through this class. It answers: does this user have access
 * to this session, and with what role?
 *
 * @package WSSP
 */

defined( 'ABSPATH' ) || exit;

class WSSP_Session_Access {

    /** @var string Custom table name (set in constructor). */
    private $table;

    /**
     * Constructor.
     */
    public function __construct() {
        global $wpdb;
        $this->table = $wpdb->prefix . 'wssp_session_users';
    }

    /* ───────────────────────────────────────────
     * PERMISSION CHECKS
     * ─────────────────────────────────────────── */

    /**
     * Check if a user has any access to a session.
     *
     * @param int $user_id    WordPress user ID.
     * @param int $session_id WSSP session ID.
     * @return bool
     */
    public function user_can_access( $user_id, $session_id ) {
        // Admins and editors always have access
        if ( $this->is_admin_or_editor( $user_id ) ) {
            return true;
        }
        return (bool) $this->get_user_role( $user_id, $session_id );
    }

    /**
     * Get the user's role for a specific session.
     *
     * @param int $user_id    WordPress user ID.
     * @param int $session_id WSSP session ID.
     * @return string|null Role string or null if no access.
     */
    public function get_user_role( $user_id, $session_id ) {
        global $wpdb;

        $role = $wpdb->get_var( $wpdb->prepare(
            "SELECT role FROM {$this->table} WHERE user_id = %d AND session_id = %d LIMIT 1",
            $user_id,
            $session_id
        ));

        return $role ?: null;
    }

    /**
     * Get all sessions a user has access to.
     *
     * @param int $user_id WordPress user ID.
     * @return array [ ['session_id' => int, 'role' => string], ... ]
     */
    public function get_user_sessions( $user_id ) {
        global $wpdb;

        // Admins/editors see all sessions
        if ( $this->is_admin_or_editor( $user_id ) ) {
            return $wpdb->get_results(
                "SELECT id AS session_id, 'logistics' AS role FROM {$wpdb->prefix}wssp_sessions ORDER BY session_code",
                ARRAY_A
            );
        }

        return $wpdb->get_results( $wpdb->prepare(
            "SELECT session_id, role FROM {$this->table} WHERE user_id = %d",
            $user_id
        ), ARRAY_A );
    }

    /**
     * Get all users linked to a session.
     *
     * @param int $session_id WSSP session ID.
     * @return array [ ['user_id' => int, 'role' => string, 'added_by' => int, 'created_at' => string], ... ]
     */
    public function get_session_users( $session_id ) {
        global $wpdb;

        return $wpdb->get_results( $wpdb->prepare(
            "SELECT su.user_id, su.role, su.added_by, su.created_at,
                    u.display_name, u.user_email
             FROM {$this->table} su
             JOIN {$wpdb->users} u ON u.ID = su.user_id
             WHERE su.session_id = %d
             ORDER BY su.role ASC, su.created_at ASC",
            $session_id
        ), ARRAY_A );
    }

    /* ───────────────────────────────────────────
     * ROLE-BASED PERMISSION CHECKS
     * ─────────────────────────────────────────── */

    /**
     * Can the user edit forms/upload files for this session?
     *
     * @param int $user_id    WordPress user ID.
     * @param int $session_id WSSP session ID.
     * @return bool
     */
    public function user_can_edit( $user_id, $session_id ) {
        if ( $this->is_admin_or_editor( $user_id ) ) {
            return true;
        }
        $role = $this->get_user_role( $user_id, $session_id );
        return in_array( $role, array( 'sponsor_primary', 'sponsor_collaborator' ), true );
    }

    /**
     * Can the user manage the team (add/remove collaborators)?
     * Only primary sponsors and admins.
     *
     * @param int $user_id    WordPress user ID.
     * @param int $session_id WSSP session ID.
     * @return bool
     */
    public function user_can_manage_team( $user_id, $session_id ) {
        if ( $this->is_admin_or_editor( $user_id ) ) {
            return true;
        }
        return $this->get_user_role( $user_id, $session_id ) === 'sponsor_primary';
    }

    /**
     * Can the user manage add-ons?
     * Only primary sponsors (before cutoff) and admins.
     *
     * @param int $user_id    WordPress user ID.
     * @param int $session_id WSSP session ID.
     * @return bool
     */
    public function user_can_manage_addons( $user_id, $session_id ) {
        return $this->user_can_manage_team( $user_id, $session_id );
    }

    /**
     * Can the user change statuses (approve, request revision)?
     * Only logistics/admins.
     *
     * @param int $user_id WordPress user ID.
     * @return bool
     */
    public function user_can_review( $user_id ) {
        return $this->is_admin_or_editor( $user_id );
    }

    /* ───────────────────────────────────────────
     * LINK / UNLINK USERS
     * ─────────────────────────────────────────── */

    /**
     * Link a user to a session with a role.
     *
     * @param int    $session_id WSSP session ID.
     * @param int    $user_id    WordPress user ID.
     * @param string $role       Role slug.
     * @param int    $added_by   Who is adding this link.
     * @param string $source     Where the link originated. Defaults to 'admin'
     *                           (preserving legacy callers). Automated syncs
     *                           should pass their own source slug (e.g.
     *                           'contacts_repeater') so reconciliation can
     *                           distinguish their rows from admin-created ones.
     * @return int|false Insert ID or false on failure.
     */
    public function link_user( $session_id, $user_id, $role, $added_by = 0, $source = 'admin' ) {
        global $wpdb;

        // Prevent duplicate links with the same role
        $exists = $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM {$this->table} WHERE session_id = %d AND user_id = %d AND role = %s",
            $session_id, $user_id, $role
        ));
        if ( $exists ) {
            return (int) $exists;
        }

        $result = $wpdb->insert( $this->table, array(
            'session_id' => $session_id,
            'user_id'    => $user_id,
            'role'       => $role,
            'source'     => $source,
            'added_by'   => $added_by ?: get_current_user_id(),
        ), array( '%d', '%d', '%s', '%s', '%d' ) );

        return $result ? $wpdb->insert_id : false;
    }

    /**
     * Get user IDs linked to a session via a specific source.
     *
     * Used by automated syncs to reconcile their own rows without
     * touching links created by other sources (e.g. admin-linked users).
     *
     * @param int    $session_id WSSP session ID.
     * @param string $source     Source slug.
     * @return int[] User IDs.
     */
    public function get_session_user_ids_by_source( $session_id, $source ) {
        global $wpdb;

        $rows = $wpdb->get_col( $wpdb->prepare(
            "SELECT user_id FROM {$this->table} WHERE session_id = %d AND source = %s",
            $session_id,
            $source
        ));

        return array_map( 'intval', $rows );
    }

    /**
     * Remove a user from a session, scoped to a specific source.
     *
     * Syncs use this to delete their own rows without risk of
     * removing admin-created links for the same user.
     *
     * @param int    $session_id WSSP session ID.
     * @param int    $user_id    WordPress user ID.
     * @param string $source     Source slug.
     * @return int Number of rows deleted.
     */
    public function unlink_user_by_source( $session_id, $user_id, $source ) {
        global $wpdb;

        return (int) $wpdb->delete(
            $this->table,
            array(
                'session_id' => $session_id,
                'user_id'    => $user_id,
                'source'     => $source,
            ),
            array( '%d', '%d', '%s' )
        );
    }

    /**
     * Remove a user from a session.
     *
     * @param int $session_id WSSP session ID.
     * @param int $user_id    WordPress user ID.
     * @param string|null $role  Optional: only remove a specific role.
     * @return int Number of rows deleted.
     */
    public function unlink_user( $session_id, $user_id, $role = null ) {
        global $wpdb;

        $where  = array( 'session_id' => $session_id, 'user_id' => $user_id );
        $format = array( '%d', '%d' );

        if ( $role ) {
            $where['role'] = $role;
            $format[]      = '%s';
        }

        return $wpdb->delete( $this->table, $where, $format );
    }

    /* ───────────────────────────────────────────
     * HELPERS
     * ─────────────────────────────────────────── */

    /**
     * Check if a user is an administrator or editor.
     *
     * @param int $user_id WordPress user ID.
     * @return bool
     */
    public function is_admin_or_editor( $user_id ) {
        $user = get_userdata( $user_id );
        if ( ! $user ) {
            return false;
        }
        return (
            in_array( 'administrator', $user->roles, true ) ||
            in_array( 'editor', $user->roles, true )
        );
    }

    /**
     * Look up a session by its random session_key.
     *
     * @param string $session_key The 8-char random key.
     * @return array|null Session row or null.
     */
    public function get_session_by_key( $session_key ) {
        global $wpdb;
        return $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}wssp_sessions WHERE session_key = %s",
            $session_key
        ), ARRAY_A );
    }

    /**
     * Look up a session by its numeric ID.
     *
     * @param int $session_id
     * @return array|null Session row or null.
     */
    public function get_session_by_id( $session_id ) {
        global $wpdb;
        return $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}wssp_sessions WHERE id = %d",
            $session_id
        ), ARRAY_A );
    }
}