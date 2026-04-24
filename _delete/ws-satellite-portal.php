<?php
/**
 * Plugin Name: WORLDSymposium Satellite Portal
 * Plugin URI:  https://worldsymposia.org
 * Description: Date- and task-driven portal for satellite symposium sponsors, with file versioning, audit logging, status workflows, and Smartsheet sync.
 * Version:     1.0.0
 * Author:      WORLDSymposium
 * Text Domain: wssp
 * Requires PHP: 7.4
 * Requires at least: 6.0
 *
 * @package WSSP
 */

defined( 'ABSPATH' ) || exit;

/* ───────────────────────────────────────────
 * CONSTANTS
 * ─────────────────────────────────────────── */
define( 'WSSP_VERSION',     '1.0.0' );
define( 'WSSP_PLUGIN_FILE', __FILE__ );
define( 'WSSP_PLUGIN_DIR',  plugin_dir_path( __FILE__ ) );
define( 'WSSP_PLUGIN_URL',  plugin_dir_url( __FILE__ ) );
define( 'WSSP_UPLOADS_DIR', WSSP_PLUGIN_DIR . 'uploads/' );

/* ───────────────────────────────────────────
 * AUTOLOADER
 * Load classes from /includes, /admin, /public
 * Naming convention: class-wssp-{name}.php → WSSP_{Name}
 * ─────────────────────────────────────────── */
spl_autoload_register( function ( $class ) {
    // Only handle our classes
    if ( 0 !== strpos( $class, 'WSSP_' ) ) {
        return;
    }

    // WSSP_Session_Access → class-wssp-session-access.php
    $file_name = 'class-' . strtolower( str_replace( '_', '-', $class ) ) . '.php';

    $directories = array(
        WSSP_PLUGIN_DIR . 'includes/',
        WSSP_PLUGIN_DIR . 'admin/',
        WSSP_PLUGIN_DIR . 'public/',
    );

    foreach ( $directories as $dir ) {
        $file = $dir . $file_name;
        if ( file_exists( $file ) ) {
            require_once $file;
            return;
        }
    }
});

/* ───────────────────────────────────────────
 * HELPERS (non-class functions)
 * ─────────────────────────────────────────── */
require_once WSSP_PLUGIN_DIR . 'includes/wssp-render-helpers.php';
require_once WSSP_PLUGIN_DIR . 'wssp-diagnostic.php';



/* ───────────────────────────────────────────
 * ACTIVATION
 * ─────────────────────────────────────────── */
register_activation_hook( __FILE__, function () {
    require_once WSSP_PLUGIN_DIR . 'includes/class-wssp-activator.php';
    WSSP_Activator::activate();
});

/* ───────────────────────────────────────────
 * DEACTIVATION
 * ─────────────────────────────────────────── */
register_deactivation_hook( __FILE__, function () {
    // Remove custom roles on deactivation
    remove_role( 'wssp_sponsor' );
    remove_role( 'wssp_vendor' );
});

/* ───────────────────────────────────────────
 * BOOTSTRAP
 * ─────────────────────────────────────────── */
add_action( 'plugins_loaded', function () {

    // ─── Dependency: WSSP Task Content plugin must be active ───
    if ( ! function_exists( 'wssp_tc' ) ) {
        add_action( 'admin_notices', function () {
            echo '<div class="notice notice-error"><p>'
               . '<strong>Satellite Portal:</strong> '
               . 'The <em>WSSP Task Content</em> plugin is required but not active. '
               . 'Please install and activate it before using the Satellite Portal.'
               . '</p></div>';
        });
        return; // Bail — nothing works without task content.
    }
    
    // Register session-aware shortcodes early so they're available
    // before any template renders call do_shortcode()
    WSSP_Session_Shortcodes::register();
    
    // === Load dates/deadlines config and register conference shortcodes ===
    $dates_config = array();
    $dates_config_file = WSSP_PLUGIN_DIR . 'config/dates-deadlines-field-map.php';
    if ( file_exists( $dates_config_file ) ) {
        $dates_config = require $dates_config_file;
    }
    $dates_smartsheet = new WSSP_Dates_Deadlines_Smartsheet( $dates_config );

    // Register conference date/deadline shortcodes from synced data
    // (these won't clobber $world_data shortcodes — shortcode_exists() is checked)
    //WSSP_Conference_Shortcodes::register();



// Core services
    $config       = new WSSP_Config();
    $access       = new WSSP_Session_Access();
    $audit        = new WSSP_Audit_Log();

    // Notifier + logger (must come after $audit, before anything that logs).
    $notifier     = new WSSP_Notifier();
    $logger       = new WSSP_Logger( $audit, $notifier );
    $logger->register();   // Install PHP error handlers

    $loader       = new WSSP_Loader( $config, $access, $audit );
    $dashboard    = new WSSP_Dashboard( $config, $access, $audit );
    $task_content = new WSSP_Task_Content( $config );
    $session_meta = new WSSP_Session_Meta();
    $smartsheet   = new WSSP_Smartsheet( $config, $session_meta, $audit );
    $file_uploads = new WSSP_REST_File_Uploads( $access, $config, $audit, $notifier );


    // Formidable service — always instantiated early because it registers hooks
    // Receives $smartsheet so it can auto-push form values to the master sheet.
    // $notifier is passed so it can send change-notification emails.
    $formidable   = new WSSP_Formidable( $audit, $config, $dashboard, $smartsheet, $notifier );

    // Back-reference: Smartsheet needs the Formidable service to resolve
    // derived portal keys (e.g. the Contacts-for-Logistics concatenations,
    // which live in repeater child entries and can't be looked up as
    // Formidable field_keys). Done as a setter because WSSP_Formidable
    // already depends on WSSP_Smartsheet in its constructor.
    $smartsheet->set_formidable( $formidable );

    // Back-reference: Smartsheet needs the Dashboard service to write
    // task status rows during addon pulls (so admin reactivate has a
    // row to operate on). Done as a setter to avoid constructor cycles.
    $smartsheet->set_dashboard( $dashboard );

    // Contacts-for-Logistics → session access sync.
    // Must come after $formidable so its hooks are registered in the
    // right order; the sync hooks in at priority 37, after Formidable's
    // session linkage (30) and audit logging (35).
    new WSSP_Contacts_Sync( $access, $formidable, $audit );
    
    new WSSP_REST_Meeting_Planners( $access );



    // Admin — task content editing now lives in the WSSP Task Content plugin
    if ( is_admin() ) {
        new WSSP_Admin( $config, $access, $audit, $session_meta, $smartsheet, $formidable, $dates_smartsheet );
        new WSSP_Reports( $config, $audit, $session_meta, $formidable );
        new WSSP_Notification_Settings(); 
    }


    // Public-facing — pass $formidable so session-overview.php, task surfacing, progress tracking, etc. can merge data cleanly
    new WSSP_Public( $config, $access, $dashboard, $task_content, $session_meta, $formidable );

    // REST API — pass if you expose any Formidable-backed endpoints
    new WSSP_REST( $config, $access, $audit, $formidable, $smartsheet );
});