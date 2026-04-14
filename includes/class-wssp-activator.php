<?php
/**
 * Plugin activation: create database tables and custom roles.
 *
 * @package WSSP
 */

defined( 'ABSPATH' ) || exit;

class WSSP_Activator {

    /**
     * Run on plugin activation.
     */
    public static function activate() {
        self::create_tables();
        self::create_roles();
        self::create_uploads_dir();
        flush_rewrite_rules();
    }

    /* ───────────────────────────────────────────
     * DATABASE TABLES
     * ─────────────────────────────────────────── */
    private static function create_tables() {
        global $wpdb;
        $charset = $wpdb->get_charset_collate();
        $prefix  = $wpdb->prefix;

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        /* 1. Sessions ──────────────────────────── */
        $sql_sessions = "CREATE TABLE {$prefix}wssp_sessions (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            session_key varchar(12) NOT NULL,
            event_type varchar(50) NOT NULL DEFAULT 'satellite',
            session_code varchar(20) NOT NULL,
            short_name varchar(100) NOT NULL DEFAULT '',
            frm_entry_id bigint(20) unsigned DEFAULT NULL,
            smartsheet_row_id varchar(50) DEFAULT NULL,
            rollup_status varchar(30) NOT NULL DEFAULT 'not_started',
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY session_key (session_key),
            UNIQUE KEY session_code (session_code),
            KEY event_type (event_type),
            KEY rollup_status (rollup_status)
        ) $charset;";
        dbDelta( $sql_sessions );

        /* 2. Session ↔ User links ─────────────── */
        $sql_session_users = "CREATE TABLE {$prefix}wssp_session_users (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            session_id bigint(20) unsigned NOT NULL,
            user_id bigint(20) unsigned NOT NULL,
            role varchar(30) NOT NULL DEFAULT 'sponsor_primary',
            added_by bigint(20) unsigned DEFAULT NULL,
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY session_user_role (session_id, user_id, role),
            KEY user_id (user_id),
            KEY session_id (session_id)
        ) $charset;";
        dbDelta( $sql_session_users );

        /* 3. File version registry ─────────────── */
        $sql_files = "CREATE TABLE {$prefix}wssp_files (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            session_id bigint(20) unsigned NOT NULL,
            file_type varchar(50) NOT NULL,
            version int(11) NOT NULL DEFAULT 1,
            original_name varchar(255) NOT NULL,
            stored_name varchar(255) NOT NULL,
            stored_path varchar(500) NOT NULL,
            file_size bigint(20) unsigned NOT NULL DEFAULT 0,
            mime_type varchar(100) NOT NULL DEFAULT '',
            uploaded_by bigint(20) unsigned NOT NULL,
            uploaded_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            status varchar(30) NOT NULL DEFAULT 'uploaded',
            status_note text DEFAULT NULL,
            approved_by bigint(20) unsigned DEFAULT NULL,
            approved_at datetime DEFAULT NULL,
            print_approved tinyint(1) NOT NULL DEFAULT 0,
            print_approved_by bigint(20) unsigned DEFAULT NULL,
            print_approved_at datetime DEFAULT NULL,
            is_active tinyint(1) NOT NULL DEFAULT 1,
            deleted_at datetime DEFAULT NULL,
            PRIMARY KEY (id),
            KEY session_file_type (session_id, file_type),
            KEY status (status),
            KEY is_active (is_active)
        ) $charset;";
        dbDelta( $sql_files );

        /* 4. Audit log (append-only) ──────────── */
        $sql_audit = "CREATE TABLE {$prefix}wssp_audit_log (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            session_id bigint(20) unsigned NOT NULL,
            event_type varchar(50) NOT NULL DEFAULT 'satellite',
            user_id bigint(20) unsigned NOT NULL,
            action varchar(50) NOT NULL,
            source varchar(30) NOT NULL DEFAULT 'portal',
            entity_type varchar(50) NOT NULL,
            entity_id varchar(100) NOT NULL DEFAULT '',
            field_name varchar(100) DEFAULT NULL,
            old_value text DEFAULT NULL,
            new_value text DEFAULT NULL,
            meta longtext DEFAULT NULL,
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY session_id (session_id),
            KEY event_type (event_type),
            KEY user_id (user_id),
            KEY action (action),
            KEY source (source),
            KEY created_at (created_at)
        ) $charset;";
        dbDelta( $sql_audit );

        /* 5. Notifications ─────────────────────── */
        $sql_notifications = "CREATE TABLE {$prefix}wssp_notifications (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            user_id bigint(20) unsigned NOT NULL,
            session_id bigint(20) unsigned DEFAULT NULL,
            type varchar(50) NOT NULL,
            message text NOT NULL,
            link varchar(500) DEFAULT NULL,
            is_read tinyint(1) NOT NULL DEFAULT 0,
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY user_id_read (user_id, is_read),
            KEY session_id (session_id)
        ) $charset;";
        dbDelta( $sql_notifications );

        /* 6. Task status tracking ──────────────── */
        $sql_task_status = "CREATE TABLE {$prefix}wssp_task_status (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            session_id bigint(20) unsigned NOT NULL,
            task_key varchar(50) NOT NULL,
            status varchar(30) NOT NULL DEFAULT 'not_started',
            acknowledged_at datetime DEFAULT NULL,
            acknowledged_by bigint(20) unsigned DEFAULT NULL,
            submitted_at datetime DEFAULT NULL,
            submitted_by bigint(20) unsigned DEFAULT NULL,
            reviewed_at datetime DEFAULT NULL,
            reviewed_by bigint(20) unsigned DEFAULT NULL,
            review_note text DEFAULT NULL,
            updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY session_task (session_id, task_key),
            KEY status (status)
        ) $charset;";
        dbDelta( $sql_task_status );

        /* 7. Session meta (key-value) ─────────── */
        $sql_session_meta = "CREATE TABLE {$prefix}wssp_session_meta (
            meta_id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            session_id bigint(20) unsigned NOT NULL,
            meta_key varchar(191) NOT NULL,
            meta_value longtext DEFAULT NULL,
            PRIMARY KEY (meta_id),
            UNIQUE KEY session_meta_key (session_id, meta_key),
            KEY session_id (session_id),
            KEY meta_key (meta_key)
        ) $charset;";
        dbDelta( $sql_session_meta );

        // Store the DB version for future migrations
        update_option( 'wssp_db_version', WSSP_VERSION );
    }

    /**
     * CUSTOM ROLES
     *
     * These roles are available but NOT required for portal access.
     * Access is controlled by the wssp_session_users table — any user
     * (including subscribers) linked to a session gets portal access.
     *
     * These roles exist as an option if you want to assign them
     * explicitly, e.g. for vendor accounts that shouldn't have
     * subscriber capabilities. But for sponsors, leaving them as
     * subscribers is fine — the session link is what matters.
     */
    private static function create_roles() {
        // Sponsor role: can read, but no wp-admin access
        add_role( 'wssp_sponsor', 'Satellite Sponsor', array(
            'read' => true,
        ));

        // Vendor role: read-only portal access
        add_role( 'wssp_vendor', 'Satellite Vendor', array(
            'read' => true,
        ));
    }

    /* ───────────────────────────────────────────
     * UPLOADS DIRECTORY
     * ─────────────────────────────────────────── */
    private static function create_uploads_dir() {
        if ( ! file_exists( WSSP_UPLOADS_DIR ) ) {
            wp_mkdir_p( WSSP_UPLOADS_DIR );
        }

        // Protect the directory from direct access
        $htaccess = WSSP_UPLOADS_DIR . '.htaccess';
        if ( ! file_exists( $htaccess ) ) {
            file_put_contents( $htaccess, "Deny from all\n" );
        }

        // Also add an index.php for servers that ignore .htaccess
        $index = WSSP_UPLOADS_DIR . 'index.php';
        if ( ! file_exists( $index ) ) {
            file_put_contents( $index, "<?php // Silence is golden.\n" );
        }
    }
}