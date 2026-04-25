<?php
/**
 * Sponsor login tracker.
 *
 * Hooks WordPress's `wp_login` action and writes one audit-log row per
 * sponsor session for each login event. Logistics can then filter the
 * existing per-session audit log on action = 'login' to see who has been
 * accessing each session, and a future cross-session "Sponsor Activity"
 * report can roll the same rows up by user.
 *
 * Scope:
 *   - Skips administrators and editors. Their `get_user_sessions()` call
 *     returns every session in the system, which would generate hundreds
 *     of audit rows per admin login. Admin activity isn't sponsor activity
 *     and shouldn't pollute per-session logs.
 *   - De-duplicates within a 5-minute window. Some sponsors will log in,
 *     close the tab, and log in again ten seconds later. We don't want
 *     that to look like two distinct engagement events.
 *   - Records IP and user role in the meta JSON for future forensic /
 *     reporting needs without bloating the indexed columns.
 *
 * Dependencies are constructor-injected to match the rest of the plugin.
 *
 * @package WSSP
 */

defined( 'ABSPATH' ) || exit;

class WSSP_Login_Tracker {

    /** @var WSSP_Session_Access */
    private $access;

    /** @var WSSP_Audit_Log */
    private $audit;

    /**
     * De-dup window (in minutes). A second login by the same user to the
     * same session within this many minutes is silently skipped.
     */
    const DEDUP_MINUTES = 5;

    public function __construct( WSSP_Session_Access $access, WSSP_Audit_Log $audit ) {
        $this->access = $access;
        $this->audit  = $audit;

        // wp_login fires after a successful authentication, with the
        // username and full user object. Priority 10, two args, matches
        // WordPress core convention.
        add_action( 'wp_login', array( $this, 'on_login' ), 10, 2 );
    }

    /**
     * Handle a successful login.
     *
     * @param string  $user_login The username (unused; kept for hook signature).
     * @param WP_User $user       The authenticated user.
     */
    public function on_login( $user_login, $user ) {
        // Bail if the hook fired with something unexpected.
        if ( ! $user instanceof WP_User || ! $user->ID ) {
            return;
        }

        // Skip admins and editors. See class docblock for rationale.
        // A `wssp_track_admin_logins` filter exists for environments that
        // genuinely want admin login data — flip it true to opt in.
        $skip_admins = ! apply_filters( 'wssp_track_admin_logins', false );
        if ( $skip_admins && $this->access->is_admin_or_editor( $user->ID ) ) {
            return;
        }

        $sessions = $this->access->get_user_sessions( $user->ID );
        if ( empty( $sessions ) ) {
            // User has portal access in theory but no sessions assigned.
            // Nothing useful to log — every audit row needs a session_id.
            return;
        }

        $ip = $this->client_ip();

        foreach ( $sessions as $link ) {
            $session_id = (int) ( $link['session_id'] ?? 0 );
            if ( ! $session_id ) {
                continue;
            }

            if ( $this->recently_logged( $user->ID, $session_id ) ) {
                continue;
            }

            $this->audit->log( array(
                'session_id'  => $session_id,
                // event_type is per-row in the audit schema; we don't know
                // it from the link row, so let the audit class default to
                // 'satellite'. If you ever add non-satellite event types,
                // pass it through here instead.
                'user_id'     => $user->ID,
                'action'      => 'login',
                'entity_type' => 'session',
                'entity_id'   => (string) $session_id,
                'meta'        => array(
                    'role' => $link['role'] ?? '',
                    'ip'   => $ip,
                ),
            ));
        }
    }

    /**
     * Has this user logged in to this session within the de-dup window?
     *
     * @param int $user_id    WordPress user ID.
     * @param int $session_id WSSP session ID.
     * @return bool
     */
    private function recently_logged( $user_id, $session_id ) {
        global $wpdb;
        $table = $wpdb->prefix . 'wssp_audit_log';

        // EXISTS query bounded by the indexed (user_id) column with an
        // additional filter on action and created_at. With the current
        // table size this returns in well under a millisecond.
        $found = $wpdb->get_var( $wpdb->prepare(
            "SELECT 1 FROM {$table}
             WHERE user_id    = %d
               AND session_id = %d
               AND action     = 'login'
               AND created_at > ( UTC_TIMESTAMP() - INTERVAL %d MINUTE )
             LIMIT 1",
            $user_id,
            $session_id,
            self::DEDUP_MINUTES
        ) );

        return (bool) $found;
    }

    /**
     * Best-effort client IP capture. Defers to REMOTE_ADDR; we deliberately
     * do not trust X-Forwarded-For without an explicit reverse-proxy
     * configuration on the host. Sites behind Cloudflare etc. may want to
     * filter this method via `wssp_login_client_ip` to read CF-Connecting-IP.
     *
     * @return string
     */
    private function client_ip() {
        $ip = isset( $_SERVER['REMOTE_ADDR'] ) ? (string) $_SERVER['REMOTE_ADDR'] : '';
        $ip = apply_filters( 'wssp_login_client_ip', $ip );

        // sanitize_text_field would strip the colons in IPv6, so just
        // length-cap and validate with the WP-bundled function.
        if ( strlen( $ip ) > 45 ) {
            $ip = substr( $ip, 0, 45 );
        }
        return rest_is_ip_address( $ip ) ? $ip : '';
    }
}
