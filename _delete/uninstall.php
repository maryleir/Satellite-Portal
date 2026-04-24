<?php
/**
 * Fired when the plugin is uninstalled.
 *
 * WARNING: This drops all custom tables and removes all plugin data.
 * Only uncomment the drop statements if you truly want to delete everything.
 *
 * @package WSSP
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

global $wpdb;

// Remove custom roles
remove_role( 'wssp_sponsor' );
remove_role( 'wssp_vendor' );

// Remove options
delete_option( 'wssp_db_version' );
delete_option( 'wssp_dashboard_page_id' );

// Remove usermeta
$wpdb->query( "DELETE FROM {$wpdb->usermeta} WHERE meta_key LIKE 'wssp_%'" );

/*
 * UNCOMMENT THE LINES BELOW TO DROP CUSTOM TABLES ON UNINSTALL.
 * This is intentionally commented out to prevent accidental data loss.
 *
 * $prefix = $wpdb->prefix;
 * $wpdb->query( "DROP TABLE IF EXISTS {$prefix}wssp_notifications" );
 * $wpdb->query( "DROP TABLE IF EXISTS {$prefix}wssp_audit_log" );
 * $wpdb->query( "DROP TABLE IF EXISTS {$prefix}wssp_files" );
 * $wpdb->query( "DROP TABLE IF EXISTS {$prefix}wssp_session_users" );
 * $wpdb->query( "DROP TABLE IF EXISTS {$prefix}wssp_sessions" );
 */
