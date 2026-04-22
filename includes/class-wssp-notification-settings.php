<?php
/**
 * Admin settings page for WSSP notifications.
 *
 * Provides UI for:
 *   - Delivery mode toggle (immediate vs daily digest)
 *   - Per-event_type recipient lists + global fallback
 *   - Error-notification recipient + email level threshold
 *   - Throttle window in seconds
 *
 * All values live in WP options and are read by WSSP_Notifier / WSSP_Logger.
 *
 * @package WSSP
 */

defined( 'ABSPATH' ) || exit;

class WSSP_Notification_Settings {

    /** Admin page slug. */
    const PAGE_SLUG = 'wssp-notification-settings';

    /**
     * Event types the portal knows about. Extend here when new
     * event_type slugs are added to wssp_sessions.
     */
    public static $event_types = array(
        'satellite' => 'Satellite Symposium',
        'iet'       => 'Industry Expert Theater',
    );

    public function __construct() {
        add_action( 'admin_menu',                                         array( $this, 'register_menu' ) );
        add_action( 'admin_post_wssp_save_notification_settings',         array( $this, 'handle_save' ) );
    }

    public function register_menu() {
        add_submenu_page(
            'wssp-dashboard',
            'Notification Settings',
            'Notifications',
            'manage_options',
            self::PAGE_SLUG,
            array( $this, 'render' )
        );
    }

    /* ───────────────────────────────────────────
     * RENDER
     * ─────────────────────────────────────────── */

    public function render() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'Unauthorized' );
        }

        // Pull current values for the template.
        $mode            = get_option( WSSP_Notifier::OPT_MODE,            WSSP_Notifier::MODE_IMMEDIATE );
        $recipients      = get_option( WSSP_Notifier::OPT_RECIPIENTS,      array() );
        $error_to        = get_option( WSSP_Notifier::OPT_ERROR_RECIPIENT, '' );
        $email_level     = get_option( WSSP_Logger::OPT_EMAIL_LEVEL,       WSSP_Logger::DEFAULT_EMAIL_LEVEL );
        $throttle_secs   = get_option( WSSP_Logger::OPT_THROTTLE_SECS,     WSSP_Logger::DEFAULT_THROTTLE_SECS );
        $saved           = isset( $_GET['saved'] );
        $event_types     = self::$event_types;

        // Normalize recipient arrays into newline-separated strings for the textareas.
        $recipients_text = array();
        foreach ( array_merge( array_keys( $event_types ), array( 'global' ) ) as $k ) {
            $list = $recipients[ $k ] ?? array();
            $recipients_text[ $k ] = is_array( $list ) ? implode( "\n", $list ) : '';
        }
        // Ensure 'global' key always exists for the template.
        if ( ! isset( $recipients_text['global'] ) ) {
            $recipients_text['global'] = '';
        }

        $view = WSSP_PLUGIN_DIR . 'admin/views/notification-settings.php';
        if ( file_exists( $view ) ) {
            include $view;
        }
    }

    /* ───────────────────────────────────────────
     * SAVE HANDLER
     * ─────────────────────────────────────────── */

    public function handle_save() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'Unauthorized' );
        }
        check_admin_referer( 'wssp_save_notification_settings' );

        // Mode.
        $mode = isset( $_POST['mode'] ) && $_POST['mode'] === WSSP_Notifier::MODE_DIGEST
            ? WSSP_Notifier::MODE_DIGEST
            : WSSP_Notifier::MODE_IMMEDIATE;
        update_option( WSSP_Notifier::OPT_MODE, $mode );

        // Recipients (per event_type + global fallback).
        $recipients = array();
        $keys = array_merge( array_keys( self::$event_types ), array( 'global' ) );
        foreach ( $keys as $k ) {
            $raw = isset( $_POST['recipients'][ $k ] ) ? (string) wp_unslash( $_POST['recipients'][ $k ] ) : '';
            $emails = preg_split( '/[\s,;]+/', $raw, -1, PREG_SPLIT_NO_EMPTY );
            $emails = array_values( array_filter( array_map( 'sanitize_email', $emails ), 'is_email' ) );
            if ( ! empty( $emails ) ) {
                $recipients[ $k ] = $emails;
            }
        }
        update_option( WSSP_Notifier::OPT_RECIPIENTS, $recipients );

        // Error recipient.
        $err_to = sanitize_email( wp_unslash( $_POST['error_recipient'] ?? '' ) );
        update_option( WSSP_Notifier::OPT_ERROR_RECIPIENT, is_email( $err_to ) ? $err_to : '' );

        // Email level threshold.
        $allowed_levels = array( 'off', 'fatal', 'error', 'warning', 'notice' );
        $level = $_POST['email_level'] ?? WSSP_Logger::DEFAULT_EMAIL_LEVEL;
        if ( ! in_array( $level, $allowed_levels, true ) ) {
            $level = WSSP_Logger::DEFAULT_EMAIL_LEVEL;
        }
        update_option( WSSP_Logger::OPT_EMAIL_LEVEL, $level );

        // Throttle seconds.
        $throttle = absint( $_POST['throttle_secs'] ?? WSSP_Logger::DEFAULT_THROTTLE_SECS );
        update_option( WSSP_Logger::OPT_THROTTLE_SECS, $throttle );

        wp_safe_redirect( admin_url( 'admin.php?page=' . self::PAGE_SLUG . '&saved=1' ) );
        exit;
    }
}
