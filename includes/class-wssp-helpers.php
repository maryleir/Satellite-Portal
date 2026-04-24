<?php
/**
 * Shared static helpers for the Satellite Portal plugin.
 *
 * A home for small, generally-useful functions that don't belong to any
 * one subsystem. Kept deliberately narrow — this is utility code, not
 * business logic. Anything that needs state (database access, config
 * lookups beyond trivial defaults) belongs on its own class.
 *
 * All methods are static so callers don't need to instantiate the class.
 *
 * @package WSSP
 */

defined( 'ABSPATH' ) || exit;

class WSSP_Helpers {

    /**
     * Build the sponsor-facing portal deep link for a session.
     *
     * Returns a URL of the form:
     *   https://<site>/satellite-portal/?session_key=<key>
     *
     * Used everywhere we need to link a logistics user (or a sponsor) to
     * the file drawer — the admin session-manager page has no file review
     * UI, so every "open this session" link in the admin tooling and in
     * outbound emails goes through the public portal instead.
     *
     * Host comes from home_url() so the link works unchanged across
     * local / staging / production. The path is filterable via the
     * 'wssp_portal_page_path' hook for installs that mount the portal
     * under a non-default slug.
     *
     * When session_key is missing (shouldn't happen in practice; belt-
     * and-suspenders against malformed callers), falls back to the
     * site's home URL rather than producing a broken deep link.
     *
     * @param array $session Session row or array with at least 'session_key'.
     * @return string Absolute URL.
     */
    public static function session_portal_url( $session ) {
        $session_key = is_array( $session ) ? ( $session['session_key'] ?? '' ) : '';
        if ( '' === $session_key ) {
            return home_url( '/' );
        }
        $portal_path = apply_filters( 'wssp_portal_page_path', 'satellite-portal' );
        return home_url( '/' . trim( $portal_path, '/' ) . '/?session_key=' . rawurlencode( $session_key ) );
    }

    /**
     * Short "Code — Short Name" label for a session, with fallback.
     *
     * @param array $session Session row with session_code and/or short_name.
     * @return string Display label; never empty.
     */
    public static function session_label( $session ) {
        $code       = is_array( $session ) ? ( $session['session_code'] ?? '' ) : '';
        $short_name = is_array( $session ) ? ( $session['short_name']   ?? '' ) : '';
        $label      = trim( $code . ( $short_name ? ' — ' . $short_name : '' ) );
        if ( '' === $label ) {
            $id = is_array( $session ) ? ( $session['id'] ?? '?' ) : '?';
            return 'Session #' . $id;
        }
        return $label;
    }
}
