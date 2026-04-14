<?php
/**
 * Admin pages and session management.
 *
 * Provides the wp-admin interface for:
 * - Viewing all sessions
 * - Creating/editing sessions
 * - Linking users to sessions
 * - Viewing audit logs and sync status
 *
 * @package WSSP
 */

defined( 'ABSPATH' ) || exit;

class WSSP_Admin {

    /** @var WSSP_Config */
    private $config;

    /** @var WSSP_Session_Access */
    private $access;

    /** @var WSSP_Audit_Log */
    private $audit;

    /** @var string Sessions table name. */
    private $sessions_table;
    
    /** @var WSSP_Session_Meta */
    private $session_meta;
    
    /** @var WSSP_Smartsheet */
    private $smartsheet;
    
    /** @var WSSP_Formidable */
    private $formidable;
    
    /** @var WSSP_Dates_Deadlines_Smartsheet */
    private $dates_smartsheet;

    

    public function __construct( WSSP_Config $config, WSSP_Session_Access $access, 
                                 WSSP_Audit_Log $audit,
                                 WSSP_Session_Meta $session_meta, WSSP_Smartsheet $smartsheet,
                                 WSSP_Formidable $formidable,
                                 WSSP_Dates_Deadlines_Smartsheet $dates_smartsheet  ) {
    
        $this->config  = $config;
        $this->access  = $access;
        $this->audit   = $audit;
        $this->session_meta = $session_meta;
        $this->smartsheet = $smartsheet;
        $this->formidable = $formidable;
        $this->dates_smartsheet = $dates_smartsheet;

        global $wpdb;
        $this->sessions_table = $wpdb->prefix . 'wssp_sessions';

        add_action( 'admin_menu',            array( $this, 'register_menus' ) );
        add_action( 'admin_init',            array( $this, 'redirect_portal_link' ) );
        add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
        add_action( 'admin_post_wssp_save_session', array( $this, 'handle_save_session' ) );
        add_action( 'admin_post_wssp_link_user',    array( $this, 'handle_link_user' ) );
        add_action( 'admin_post_wssp_unlink_user',  array( $this, 'handle_unlink_user' ) );
        add_action( 'admin_post_wssp_save_session_meta', array( $this, 'handle_save_session_meta' ) );
        add_action( 'admin_post_wssp_smartsheet_pull',      array( $this, 'handle_smartsheet_pull' ) );
        add_action( 'admin_post_wssp_smartsheet_pull_all',  array( $this, 'handle_smartsheet_pull_all' ) );
        add_action( 'admin_post_wssp_smartsheet_push',      array( $this, 'handle_smartsheet_push' ) );
        
        // Add the sync handler and report page
        add_action( 'admin_post_wssp_sync_dates', array( $this, 'handle_sync_dates' ) );


    }

    /* ───────────────────────────────────────────
     * MENUS
     * ─────────────────────────────────────────── */

    public function register_menus() {
        add_menu_page(
            'Satellite Portal',
            'Satellite Portal',
            'edit_posts',    // editors and above
            'wssp-dashboard',
            array( $this, 'render_dashboard' ),
            'dashicons-calendar-alt',
            30
        );

        add_submenu_page(
            'wssp-dashboard',
            'All Sessions',
            'All Sessions',
            'edit_posts',
            'wssp-dashboard',
            array( $this, 'render_dashboard' )
        );

        add_submenu_page(
            'wssp-dashboard',
            'Add Session',
            'Add Session',
            'manage_options',    // admin only
            'wssp-add-session',
            array( $this, 'render_add_session' )
        );
        
        add_submenu_page(
            'wssp-dashboard',
            'Manage Session',
            '',                  // Hidden from menu (accessed via link)
            'edit_posts',
            'wssp-manage-session',
            array( $this, 'render_manage_session' )
        );
        
        add_submenu_page(
            'wssp-dashboard',                    // parent slug (adjust to your actual menu slug)
            'Shortcode Report',
            'Shortcode Report',
            'manage_options',
            'wssp-shortcode-report',
            array( $this, 'render_shortcode_report' )
        );

        


        // Link to the frontend portal page
        $portal_url = $this->get_portal_url();
        if ( $portal_url ) {
            add_submenu_page(
                'wssp-dashboard',
                'View Portal',
                'View Portal ↗',
                'edit_posts',
                'wssp-view-portal',
                '__return_null'
            );
        }
    }

    /**
     * Redirect the "View Portal" menu item to the frontend page.
     */
    public function redirect_portal_link() {
        if ( isset( $_GET['page'] ) && $_GET['page'] === 'wssp-view-portal' ) {
            $url = $this->get_portal_url();
            wp_redirect( $url ?: home_url() );
            exit;
        }
    }

    /**
     * Get the frontend portal page URL.
     */
    private function get_portal_url() {
        $page_id = get_option( 'wssp_dashboard_page_id', 0 );
        if ( $page_id ) {
            return get_permalink( $page_id );
        }
        global $wpdb;
        $found_id = $wpdb->get_var(
            "SELECT ID FROM {$wpdb->posts}
             WHERE post_type = 'page' AND post_status = 'publish'
             AND post_content LIKE '%[wssp_dashboard%'
             LIMIT 1"
        );
        if ( $found_id ) {
            update_option( 'wssp_dashboard_page_id', $found_id );
            return get_permalink( $found_id );
        }
        return '';
    }

    public function enqueue_assets( $hook ) {
        if ( strpos( $hook, 'wssp-' ) === false ) {
            return;
        }
        wp_enqueue_style( 'wssp-admin', WSSP_PLUGIN_URL . 'admin/css/admin.css', array(), WSSP_VERSION );
        wp_enqueue_script( 'wssp-admin', WSSP_PLUGIN_URL . 'admin/js/admin.js', array(), WSSP_VERSION, true );

    }

    /* ───────────────────────────────────────────
     * ADMIN DASHBOARD — All Sessions
     * ─────────────────────────────────────────── */

    public function render_dashboard() {
        global $wpdb;

        $sessions = $wpdb->get_results(
            "SELECT s.*, 
                    (SELECT COUNT(*) FROM {$wpdb->prefix}wssp_session_users su WHERE su.session_id = s.id) AS user_count
             FROM {$this->sessions_table} s
             ORDER BY s.session_code ASC",
            ARRAY_A
        );

        include WSSP_PLUGIN_DIR . 'admin/views/admin-dashboard.php';
    }

    /* ───────────────────────────────────────────
     * ADD / EDIT SESSION
     * ─────────────────────────────────────────── */

    public function render_add_session() {
        $event_types = $this->config->get_event_types();
        $editing     = false;
        $session     = null;

        // If editing an existing session
        if ( ! empty( $_GET['session_id'] ) ) {
            $editing = true;
            $session = $this->get_session( absint( $_GET['session_id'] ) );
        }

        include WSSP_PLUGIN_DIR . 'admin/views/add-session.php';
    }

    public function handle_save_session() {
        check_admin_referer( 'wssp_save_session' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'Unauthorized.' );
        }

        global $wpdb;

        $session_id   = absint( $_POST['session_id'] ?? 0 );
        $session_code = sanitize_text_field( $_POST['session_code'] ?? '' );
        $short_name   = sanitize_text_field( $_POST['short_name'] ?? '' );
        $event_type   = sanitize_text_field( $_POST['event_type'] ?? 'satellite' );

        // Sanitize short_name: preserve case and hyphens, strip file-unsafe characters
        // Allowed: letters, numbers, hyphens, underscores. Spaces become hyphens.
        $short_name = str_replace( ' ', '-', $short_name );
        $short_name = preg_replace( '/[^A-Za-z0-9\-_]/', '', $short_name );

        if ( empty( $session_code ) ) {
            wp_safe_redirect( admin_url( 'admin.php?page=wssp-add-session&error=missing_code' ) );
            exit;
        }

        $data = array(
            'session_code' => $session_code,
            'short_name'   => $short_name,
            'event_type'   => $event_type,
        );
        $formats = array( '%s', '%s', '%s' );

        if ( $session_id ) {
            // Update existing (session_key never changes after creation)
            $wpdb->update( $this->sessions_table, $data, array( 'id' => $session_id ), $formats, array( '%d' ) );

            $this->audit->log( array(
                'session_id'  => $session_id,
                'event_type'  => $event_type,
                'action'      => 'session_updated',
                'entity_type' => 'session',
                'entity_id'   => $session_id,
            ));
        } else {
            // Create new — generate a unique random session key
            $data['session_key'] = self::generate_session_key();
            $formats[] = '%s';

            $wpdb->insert( $this->sessions_table, $data, $formats );
            $session_id = $wpdb->insert_id;

            $this->audit->log( array(
                'session_id'  => $session_id,
                'event_type'  => $event_type,
                'action'      => 'session_created',
                'entity_type' => 'session',
                'entity_id'   => $session_id,
            ));
        }

        wp_safe_redirect( admin_url( 'admin.php?page=wssp-manage-session&session_id=' . $session_id . '&saved=1' ) );
        exit;
    }

    /* ───────────────────────────────────────────
     * MANAGE SESSION (detail view + user linking)
     * ─────────────────────────────────────────── */

    public function render_manage_session() {
        $session_id = absint( $_GET['session_id'] ?? 0 );
        if ( ! $session_id ) {
            echo '<div class="notice notice-error"><p>No session specified.</p></div>';
            return;
        }

        $session = $this->get_session( $session_id );
        if ( ! $session ) {
            echo '<div class="notice notice-error"><p>Session not found.</p></div>';
            return;
        }

        $session_users = $this->access->get_session_users( $session_id );

        // Get all assignable users for the dropdown (subscribers, sponsors, any non-admin)
        // Access is controlled by wssp_session_users, not by WP role.
        $all_users = get_users( array(
            'role__not_in' => array( 'administrator' ),
            'orderby'      => 'display_name',
            'order'        => 'ASC',
        ));

        $config     = $this->config;
        $event_type = $session['event_type'];
        //$phases     = $config->get_phases( $event_type );

        // Session meta for the details/add-ons section
        $session_meta = $this->session_meta;

        include WSSP_PLUGIN_DIR . 'admin/views/session-manager.php';
    }

    /* ───────────────────────────────────────────
     * LINK / UNLINK USER
     * ─────────────────────────────────────────── */

    public function handle_link_user() {
        check_admin_referer( 'wssp_link_user' );

        $session_id = absint( $_POST['session_id'] ?? 0 );
        $user_id    = absint( $_POST['user_id'] ?? 0 );
        $role       = sanitize_text_field( $_POST['role'] ?? 'sponsor_primary' );

        if ( ! $session_id || ! $user_id ) {
            wp_die( 'Missing required fields.' );
        }

        $this->access->link_user( $session_id, $user_id, $role );

        $session = $this->get_session( $session_id );
        $this->audit->log( array(
            'session_id'  => $session_id,
            'event_type'  => $session['event_type'] ?? 'satellite',
            'action'      => 'team_change',
            'entity_type' => 'team',
            'entity_id'   => $user_id,
            'new_value'   => "Added with role: {$role}",
        ));

        wp_safe_redirect( admin_url( 'admin.php?page=wssp-manage-session&session_id=' . $session_id . '&linked=1' ) );
        exit;
    }

    public function handle_unlink_user() {
        check_admin_referer( 'wssp_unlink_user' );

        $session_id = absint( $_POST['session_id'] ?? 0 );
        $user_id    = absint( $_POST['user_id'] ?? 0 );

        if ( ! $session_id || ! $user_id ) {
            wp_die( 'Missing required fields.' );
        }

        $role = $this->access->get_user_role( $user_id, $session_id );
        $this->access->unlink_user( $session_id, $user_id );

        $session = $this->get_session( $session_id );
        $this->audit->log( array(
            'session_id'  => $session_id,
            'event_type'  => $session['event_type'] ?? 'satellite',
            'action'      => 'team_change',
            'entity_type' => 'team',
            'entity_id'   => $user_id,
            'old_value'   => "Removed (was: {$role})",
        ));

        wp_safe_redirect( admin_url( 'admin.php?page=wssp-manage-session&session_id=' . $session_id . '&unlinked=1' ) );
        exit;
    }

    /* ───────────────────────────────────────────
     * SAVE SESSION META HANDLER / Handle session meta save (and delete) from the editor form
     * ─────────────────────────────────────────── */
    /**
     * Handle session meta save from the admin session manager.
     */
    public function handle_save_session_meta() {
        check_admin_referer( 'wssp_save_session_meta' );
 
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'Unauthorized.' );
        }
 
        $session_id = absint( $_POST['session_id'] ?? 0 );
        if ( ! $session_id ) {
            wp_die( 'Missing session ID.' );
        }
 
        $meta_input = $_POST['meta'] ?? array();
        if ( ! is_array( $meta_input ) ) {
            wp_die( 'Invalid data.' );
        }
 
        // Define which keys are checkbox fields (unchecked = not in POST)
        $checkbox_keys = array(
            'lead_report_sent',
        );
 
        // Add-on checkboxes
        $addons_config = $this->config->get_addons(
            $this->get_session( $session_id )['event_type'] ?? 'satellite'
        );
        foreach ( $addons_config as $slug => $addon ) {
            $checkbox_keys[] = 'addon_' . $slug;
        }
 
        // Process each meta value
        $clean = array();
        foreach ( $meta_input as $key => $value ) {
            $key = sanitize_key( $key );
            if ( is_array( $value ) ) {
                $clean[ $key ] = array_map( 'sanitize_text_field', $value );
            } else {
                $clean[ $key ] = sanitize_text_field( $value );
            }
        }
 
        // Handle unchecked checkboxes (they won't be in POST)
        foreach ( $checkbox_keys as $cb_key ) {
            if ( ! isset( $clean[ $cb_key ] ) ) {
                $clean[ $cb_key ] = '';
            }
        }
 
        // Save all meta
        $this->session_meta->update_many( $session_id, $clean );
 
        // Audit log
        $session = $this->get_session( $session_id );
        $this->audit->log( array(
            'session_id'  => $session_id,
            'event_type'  => $session['event_type'] ?? 'satellite',
            'action'      => 'session_meta_updated',
            'entity_type' => 'session',
            'entity_id'   => $session_id,
            'new_value'   => wp_json_encode( array_keys( $clean ) ),
        ));
 
        wp_safe_redirect( admin_url(
            'admin.php?page=wssp-manage-session&session_id=' . $session_id . '&meta_saved=1'
        ));
        exit;
    }
    
    /* ───────────────────────────────────────────
     * SMARTSHEET FUNCTIONS
     * ─────────────────────────────────────────── */
    
    /**
     * Pull a single session from Smartsheet.
     */
    public function handle_smartsheet_pull() {
        check_admin_referer( 'wssp_smartsheet_pull' );
        if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Unauthorized.' );
 
        $session_id = absint( $_POST['session_id'] ?? 0 );
        $result = $this->smartsheet->pull_session( $session_id );
 
        $status = $result['success'] ? 'ss_pulled' : 'ss_error';
        $msg    = urlencode( $result['message'] );
 
        wp_safe_redirect( admin_url(
            "admin.php?page=wssp-manage-session&session_id={$session_id}&{$status}=1&ss_msg={$msg}"
        ));
        exit;
    }
 
    /**
     * Pull ALL sessions from Smartsheet.
     */
    public function handle_smartsheet_pull_all() {
        check_admin_referer( 'wssp_smartsheet_pull_all' );
        if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Unauthorized.' );
 
        $result = $this->smartsheet->pull_all_sessions();
 
        $status = $result['success'] ? 'ss_pulled_all' : 'ss_error';
        $count  = count( $result['results'] ?? array() );
        $msg    = urlencode( $result['message'] );
 
        wp_safe_redirect( admin_url(
            "admin.php?page=wssp-dashboard&{$status}=1&ss_count={$count}&ss_msg={$msg}"
        ));
        exit;
    }
 
    /**
     * Push a single session to Smartsheet.
     */
    public function handle_smartsheet_push() {
        check_admin_referer( 'wssp_smartsheet_push' );
        if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Unauthorized.' );
 
        $session_id = absint( $_POST['session_id'] ?? 0 );
        $result = $this->smartsheet->push_session( $session_id );
 
        $status = $result['success'] ? 'ss_pushed' : 'ss_error';
        $msg    = urlencode( $result['message'] );
 
        wp_safe_redirect( admin_url(
            "admin.php?page=wssp-manage-session&session_id={$session_id}&{$status}=1&ss_msg={$msg}"
        ));
        exit;
    }
    
    public function handle_sync_dates() {
        check_admin_referer( 'wssp_sync_dates' );
        if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Unauthorized.' );

        $result = $this->dates_smartsheet->pull();
        WSSP_Conference_Shortcodes::bust_cache();

        $status = $result['success'] ? 'synced' : 'sync_error';
        wp_safe_redirect( admin_url(
            "admin.php?page=wssp-shortcode-report&{$status}=1&msg=" . urlencode( $result['message'] )
        ) );
        exit;
    }
    
    public function render_shortcode_report() {
    // 1. Date/deadline entries from Smartsheet sync
    $dates_entries   = WSSP_Dates_Deadlines_Smartsheet::get_entries();
    $dates_sync_info = WSSP_Dates_Deadlines_Smartsheet::get_sync_info();

    // 2. Session shortcode map (read the static property via reflection or
    //    use the get_available_shortcodes method to derive the map)
    $session_map = self::get_session_shortcode_map();

    // 3. Conference identity shortcodes from $world_data
    $world_shortcodes = self::get_world_shortcodes();

    // 4. Scan task content for usage
    $portal_slug = 'satellite';
    $usage_map   = class_exists( 'WS_CS_Usage_Scanner' ) ? WS_CS_Usage_Scanner::scan( $portal_slug ) : array();

    // 5. Deadline health check
    $deadline_health = $this->config->get_deadline_health( $portal_slug );

    include WSSP_PLUGIN_DIR . 'admin/views/shortcode-report.php';
}

/**
 * Get the session shortcode map for the report.
 * Uses the public get_available_shortcodes() to derive the map.
 */
private static function get_session_shortcode_map() {
    // Use reflection to read the private static $shortcode_map,
    // or reconstruct from the available_shortcodes output.
    // Simplest approach: maintain a public accessor.
    //
    // For now, hardcode the known map keys. Alternatively, add
    // a public static method to WSSP_Session_Shortcodes:
    //   public static function get_shortcode_map() { return self::$shortcode_map; }

    return array(
        'title'                    => array( 'wssp_program_title', 'topic' ),
        'name'                     => array( 'wssp_data_company_name', 'sponsor_name', 'short_name' ),
        'topic'                    => 'topic',
        'session-code'             => 'session_code',
        'assigned-room'            => 'session_location',
        'assigned-room-floor-plan' => 'session_floor_plan_url',
        'av-contact'               => 'av_contact_name',
        'av-email'                 => 'av_contact_email',
        'session-date'             => 'session_date',
        'session-time'             => 'session_time',
        'session-day'              => 'session_day',
        'rehearsal-day'            => 'rehearsal_day',
        'rehearsal-date'           => 'rehearsal_date',
        'rehearsal-time'           => 'rehearsal_time',
        'video-backplate'          => 'backplate_template_url',
    );
}

/**
 * Get $world_data shortcodes for the report.
 */
private static function get_world_shortcodes() {
    // Read from the conference shortcodes plugin (replaced legacy $world_data)
    if ( class_exists( 'WS_CS_Identity_Shortcodes' ) ) {
        $all = WS_CS_Identity_Shortcodes::get_all();
        $report = array();
        foreach ( $all as $key => $value ) {
            if ( preg_match( '/^[a-z][a-z0-9-]+$/', $key ) ) {
                $report[ $key ] = is_string( $value ) ? $value : '';
            }
        }
        return $report;
    }

    return array();
}


  

    /* ───────────────────────────────────────────
     * HELPERS
     * ─────────────────────────────────────────── */

    /**
     * Get a session by ID.
     *
     * @param int $session_id
     * @return array|null
     */
    private function get_session( $session_id ) {
        global $wpdb;
        return $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$this->sessions_table} WHERE id = %d",
            $session_id
        ), ARRAY_A );
    }

    /**
     * Generate a unique random session key (8 chars, lowercase alphanumeric).
     *
     * @return string e.g. 'a3f9x2k7'
     */
    private static function generate_session_key() {
        global $wpdb;
        $table = $wpdb->prefix . 'wssp_sessions';

        // Keep generating until we get a unique one (collision is extremely unlikely)
        do {
            $key = substr( bin2hex( random_bytes( 4 ) ), 0, 8 );
            $exists = $wpdb->get_var( $wpdb->prepare(
                "SELECT id FROM {$table} WHERE session_key = %s", $key
            ));
        } while ( $exists );

        return $key;
    }
}