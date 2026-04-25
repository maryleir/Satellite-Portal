<?php
/**
 * Public-facing functionality.
 *
 * Registers shortcodes and renders the sponsor-facing portal
 * using the dashboard phase engine for task categorization.
 *
 * @package WSSP
 */

defined( 'ABSPATH' ) || exit;

class WSSP_Public {

    /** @var WSSP_Config */
    private $config;

    /** @var WSSP_Session_Access */
    private $access;

    /** @var WSSP_Dashboard */
    private $dashboard;

    /** @var WSSP_Task_Content */
    private $task_content;

    /** @var WSSP_Session_Meta */
    private $session_meta;

    /** @var WSSP_Formidable */
    private $formidable;

    public function __construct(
        WSSP_Config $config,
        WSSP_Session_Access $access,
        WSSP_Dashboard $dashboard,
        WSSP_Task_Content $task_content,
        WSSP_Session_Meta $session_meta,
        WSSP_Formidable $formidable
    ) {
        $this->config       = $config;
        $this->access       = $access;
        $this->dashboard    = $dashboard;
        $this->task_content = $task_content;
        $this->session_meta = $session_meta;
        $this->formidable   = $formidable;

        add_shortcode( 'wssp_dashboard', array( $this, 'render_dashboard' ) );
        add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_assets' ) );

        // Register the form embed page template
        add_filter( 'theme_page_templates', array( $this, 'register_form_template' ) );
        add_filter( 'template_include',     array( $this, 'load_form_template' ) );

        // Intercept ?wssp_report= requests early so the standalone report
        // view runs in place of the page template (no theme chrome, just
        // the print-friendly view + wp_head/wp_footer).
        add_action( 'template_redirect', array( $this, 'maybe_render_report' ) );
    }

    public function enqueue_assets() {
        global $post;

        // The report view loads its own stylesheet (see maybe_render_report).
        // Don't load the heavy portal/drawer assets when we're just printing.
        if ( ! empty( $_GET['wssp_report'] ) ) {
            wp_enqueue_style(
                'wssp-report-print',
                WSSP_PLUGIN_URL . 'public/css/report-print.css',
                array(),
                WSSP_VERSION
            );
            return;
        }

        if ( ! $post || ! has_shortcode( $post->post_content, 'wssp_dashboard' ) ) {
            return;
        }

        wp_enqueue_style( 'wssp-portal', WSSP_PLUGIN_URL . 'public/css/portal.css', array(), WSSP_VERSION );
        wp_enqueue_script( 'wssp-portal', WSSP_PLUGIN_URL . 'public/js/portal.js', array(), WSSP_VERSION, true );

        wp_localize_script( 'wssp-portal', 'wsspData', array(
            'restUrl'     => rest_url( 'wssp/v1/' ),
            'nonce'       => wp_create_nonce( 'wp_rest' ),
            'formPageUrl' => $this->get_form_page_url(),
            'sessionId'   => $this->get_current_session_id(),
        ));

        // Form drawer
        wp_enqueue_style( 'wssp-form-drawer', WSSP_PLUGIN_URL . 'public/css/form-drawer.css', array( 'wssp-portal' ), WSSP_VERSION );
        wp_enqueue_style( 'wssp-meeting-planners', WSSP_PLUGIN_URL . 'public/css/meeting-planners.css', array( 'wssp-portal' ), WSSP_VERSION );
        wp_enqueue_style( 'wssp-file-upload', WSSP_PLUGIN_URL . 'public/css/file-upload.css', array( 'wssp-portal' ), WSSP_VERSION );

        // Shared file-panel module — handles upload / status / comments
        // inside any container. The drawer delegates to it, and the admin
        // File Review Queue uses the same module. Enqueue BEFORE
        // form-drawer so the drawer can reference window.WSSPFilePanel.
        wp_enqueue_script(
            'wssp-file-panel',
            WSSP_PLUGIN_URL . 'public/js/wssp-file-panel.js',
            array( 'wssp-portal' ),
            WSSP_VERSION,
            true
        );

        wp_enqueue_script(
            'wssp-form-drawer',
            WSSP_PLUGIN_URL . 'public/js/form-drawer.js',
            array( 'wssp-portal', 'wssp-file-panel' ),
            WSSP_VERSION,
            true
        );
    }

    /*
     * ┌──────────────────────────────────────────────────────────┐
     * │  PAGE TEMPLATE REGISTRATION                              │
     * └──────────────────────────────────────────────────────────┘
     */
    public function register_form_template( $templates ) {
        $templates['wssp-form-embed'] = 'WSSP Form Embed';
        return $templates;
    }

    public function load_form_template( $template ) {
        if ( is_page() ) {
            $page_template = get_page_template_slug();
            if ( $page_template === 'wssp-form-embed' ) {
                $plugin_template = WSSP_PLUGIN_DIR . 'public/views/form-embed-template.php';
                if ( file_exists( $plugin_template ) ) {
                    return $plugin_template;
                }
            }
        }
        return $template;
    }

    private function get_form_page_url() {
        $page_id = get_option( 'wssp_form_page_id', 0 );
        if ( $page_id ) {
            $url = get_permalink( $page_id );
            if ( $url ) return $url;
        }

        global $wpdb;
        $found_id = $wpdb->get_var(
            "SELECT p.ID FROM {$wpdb->posts} p
             INNER JOIN {$wpdb->postmeta} pm ON (p.ID = pm.post_id)
             WHERE p.post_type = 'page' AND p.post_status = 'publish'
             AND pm.meta_key = '_wp_page_template' AND pm.meta_value = 'wssp-form-embed'
             LIMIT 1"
        );

        if ( $found_id ) {
            update_option( 'wssp_form_page_id', $found_id );
            return get_permalink( $found_id );
        }

        return '';
    }

    /* ───────────────────────────────────────────
     * REPORT HANDLER
     * ─────────────────────────────────────────── */

    /**
     * Intercept ?wssp_report=session-status&session_key=… and render
     * the standalone report view in place of the normal page template.
     *
     * Runs at template_redirect — early enough that exiting here skips
     * the theme entirely. The report view calls wp_head()/wp_footer()
     * itself so plugin scripts and the report stylesheet still load.
     */
    public function maybe_render_report() {
        if ( empty( $_GET['wssp_report'] ) ) {
            return;
        }

        $report = sanitize_key( $_GET['wssp_report'] );

        // Single dispatch point so future reports plug in cleanly.
        switch ( $report ) {
            case 'session-status':
                $this->render_session_status_report();
                exit;
        }
        // Unknown report key — fall through and let the page render normally.
    }

    private function render_session_status_report() {
        if ( ! is_user_logged_in() ) {
            wp_safe_redirect( wp_login_url( $this->current_url() ) );
            exit;
        }

        $user_id     = get_current_user_id();
        $session_key = isset( $_GET['session_key'] ) ? sanitize_text_field( $_GET['session_key'] ) : '';

        if ( ! $session_key ) {
            wp_die( 'Missing session_key parameter.', 'Session Status Report', array( 'response' => 400 ) );
        }

        $session = $this->access->get_session_by_key( $session_key );
        if ( ! $session ) {
            wp_die( 'Session not found.', 'Session Status Report', array( 'response' => 404 ) );
        }

        $session_id = (int) $session['id'];
        if ( ! $this->access->user_can_access( $user_id, $session_id ) ) {
            wp_die( 'You do not have access to this session.', 'Session Status Report', array( 'response' => 403 ) );
        }

        $context = $this->build_session_context( $session_id );
        if ( ! $context ) {
            wp_die( 'Could not build session report.', 'Session Status Report', array( 'response' => 500 ) );
        }

        // Extract context vars into the local scope so the view can use them
        // by their natural names ($session, $phases, $stats, …).
        extract( $context, EXTR_SKIP );

        include WSSP_PLUGIN_DIR . 'public/views/session-status-report.php';

        // Always clear shortcode context after rendering, mirroring detail view.
        WSSP_Session_Shortcodes::clear_context();
    }

    private function current_url() {
        $scheme = is_ssl() ? 'https' : 'http';
        $host   = isset( $_SERVER['HTTP_HOST'] ) ? $_SERVER['HTTP_HOST'] : '';
        $uri    = isset( $_SERVER['REQUEST_URI'] ) ? $_SERVER['REQUEST_URI'] : '';
        return esc_url_raw( $scheme . '://' . $host . $uri );
    }

    /* ───────────────────────────────────────────
     * SHORTCODE: [wssp_dashboard]
     * ─────────────────────────────────────────── */

    public function render_dashboard( $atts = array() ) {
        if ( ! is_user_logged_in() ) {
            return '<div class="wssp-notice wssp-notice--error">Please <a href="' . wp_login_url( get_permalink() ) . '">log in</a> to access the portal.</div>';
        }

        $user_id  = get_current_user_id();
        $sessions = $this->access->get_user_sessions( $user_id );

        if ( empty( $sessions ) ) {
            return '<div class="wssp-notice wssp-notice--info">You do not have any sessions assigned to your account. If you believe this is an error, please contact the WORLDSymposium team.</div>';
        }

        // Resolve session from URL param (session_key)
        $requested_key = isset( $_GET['session_key'] ) ? sanitize_text_field( $_GET['session_key'] ) : '';
        $requested_session_id = 0;

        if ( $requested_key ) {
            $session = $this->access->get_session_by_key( $requested_key );
            if ( $session ) {
                $requested_session_id = (int) $session['id'];
            }
        }

        // Single-session shortcut
        if ( count( $sessions ) === 1 && ! $requested_session_id ) {
            $requested_session_id = (int) $sessions[0]['session_id'];
        }

        if ( $requested_session_id && $this->access->user_can_access( $user_id, $requested_session_id ) ) {
            $all_sessions = $this->access->get_user_sessions( $user_id );
            return $this->render_session_detail( $requested_session_id, $all_sessions );
        }

        if ( $requested_key ) {
            return '<div class="wssp-notice wssp-notice--error">You do not have access to this session.</div>';
        }

        return $this->render_session_picker( $sessions, $user_id );
    }

    /* ───────────────────────────────────────────
     * SESSION PICKER
     * ─────────────────────────────────────────── */

    private function render_session_picker( $sessions, $user_id ) {
        global $wpdb;
        $table = $wpdb->prefix . 'wssp_sessions';

        // Batch-load all sessions in one query
        $session_ids  = array_column( $sessions, 'session_id' );
        $session_lookup = array();
        if ( ! empty( $session_ids ) ) {
            $placeholders = implode( ',', array_fill( 0, count( $session_ids ), '%d' ) );
            $rows = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT * FROM {$table} WHERE id IN ({$placeholders})",
                    ...$session_ids
                ),
                ARRAY_A
            );
            foreach ( $rows as $row ) {
                $session_lookup[ $row['id'] ] = $row;
            }
        }

        ob_start();
        ?>
        <div class="wssp-portal">
            <div class="wssp-header">
                <h2>Your Sessions</h2>
                <p>Select a session to view its dashboard.</p>
            </div>
            <div class="wssp-session-grid">
                <?php foreach ( $sessions as $link ) :
                    $session = $session_lookup[ $link['session_id'] ] ?? null;
                    if ( ! $session ) continue;

                    $event_label   = $this->config->get_event_label( $session['event_type'] );
                    $dashboard_url = add_query_arg( 'session_key', $session['session_key'], get_permalink() );
                ?>
                    <a href="<?php echo esc_url( $dashboard_url ); ?>" class="wssp-session-card">
                        <div class="wssp-session-card__code"><?php echo esc_html( $session['session_code'] ); ?></div>
                        <div class="wssp-session-card__name"><?php echo esc_html( $session['short_name'] ?: $event_label ); ?></div>
                        <div class="wssp-session-card__type"><?php echo esc_html( $event_label ); ?></div>
                        <div class="wssp-session-card__status wssp-status wssp-status--<?php echo esc_attr( $session['rollup_status'] ); ?>">
                            <?php echo esc_html( str_replace( '_', ' ', $session['rollup_status'] ) ); ?>
                        </div>
                        <div class="wssp-session-card__role">Your role: <?php echo esc_html( str_replace( '_', ' ', $link['role'] ) ); ?></div>
                    </a>
                <?php endforeach; ?>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }

    /* ───────────────────────────────────────────
     * SHARED CONTEXT BUILDER
     * ─────────────────────────────────────────── */

    /**
     * Build the full dashboard context for one session.
     *
     * Centralises the data-loading sequence used by both the detail view
     * and the printable status report, so they can never drift out of
     * sync. Returns an associative array of named pieces; null on failure.
     *
     * Side effect: calls WSSP_Session_Shortcodes::set_context(). Callers
     * are responsible for calling clear_context() when they're done
     * rendering — exactly as render_session_detail() already does.
     */
    private function build_session_context( $session_id ) {
        global $wpdb;

        $table_sessions = $wpdb->prefix . 'wssp_sessions';
        $session = $wpdb->get_row(
            $wpdb->prepare( "SELECT * FROM {$table_sessions} WHERE id = %d", $session_id ),
            ARRAY_A
        );
        if ( ! $session ) {
            return null;
        }

        $event_type   = $session['event_type'] ?? 'satellite';
        $session_data = $this->formidable->get_full_session_data( $session['session_key'] );

        $task_statuses    = $this->dashboard->get_task_statuses( $session_id );
        $session_meta     = $this->session_meta->get_all( $session_id );
        $addon_states     = $this->config->compute_addon_states( $session_meta, $session_data, $event_type );
        $purchased_addons = $this->config->get_purchased_addons( $session_meta, $session_data, $event_type );

        WSSP_Session_Shortcodes::set_context( $session_data, $session_meta );

        $merged_data = array_merge( $session_data, $session_meta );

        $dashboard_data    = $this->dashboard->get_dashboard_data( $session_id, $event_type, $merged_data, $addon_states );
        $phases            = $dashboard_data['phases'] ?? array();
        $current_phase_key = $dashboard_data['current_phase_key'] ?? '';

        $task_content = $this->task_content->get_for_session( $session_id, $event_type );
        $stats        = $this->dashboard->compute_dashboard_stats( $phases, $purchased_addons, $addon_states );

        $file_summary = array();
        if ( class_exists( 'FrmForm' ) ) {
            $file_summary = $this->formidable->get_material_file_summary( $session['session_key'] );
        }

        $event_config = $this->config->get_event_type( $event_type );
        $event_label  = $event_config['label'] ?? 'Satellite Symposium';

        return array(
            'session'           => $session,
            'event_type'        => $event_type,
            'event_label'       => $event_label,
            'session_data'      => $session_data,
            'session_meta'      => $session_meta,
            'merged_data'       => $merged_data,
            'task_statuses'     => $task_statuses,
            'addon_states'      => $addon_states,
            'purchased_addons'  => $purchased_addons,
            'phases'            => $phases,
            'current_phase_key' => $current_phase_key,
            'task_content'      => $task_content,
            'stats'             => $stats,
            'file_summary'      => $file_summary,
        );
    }

    /* ───────────────────────────────────────────
     * SESSION DETAIL — Dashboard View
     * ─────────────────────────────────────────── */

    private function render_session_detail( $session_id, $all_sessions ) {
        global $wpdb;

        $user_id  = get_current_user_id();
        $is_admin = current_user_can( 'edit_others_posts' );

        $context = $this->build_session_context( $session_id );
        if ( ! $context ) {
            return '<div class="wssp-notice wssp-notice--error">Session not found.</div>';
        }

        // Pull context values into local scope for the included views.
        extract( $context, EXTR_SKIP );

        $table_sessions = $wpdb->prefix . 'wssp_sessions';

        // Session lookup for dropdown
        $session_lookup = array();
        if ( count( $all_sessions ) > 1 || $is_admin ) {
            $session_ids_list = array_column( $all_sessions, 'session_id' );
            if ( ! empty( $session_ids_list ) ) {
                $placeholders = implode( ',', array_fill( 0, count( $session_ids_list ), '%d' ) );
                $rows = $wpdb->get_results(
                    $wpdb->prepare(
                        "SELECT * FROM {$table_sessions} WHERE id IN ({$placeholders})",
                        ...$session_ids_list
                    ),
                    ARRAY_A
                );
                foreach ( $rows as $row ) {
                    $session_lookup[ $row['id'] ] = $row;
                }
            }
        }
        $session_lookup[ $session['id'] ] = $session;

        // Permissions
        $user_role = '';
        foreach ( $all_sessions as $link ) {
            if ( (int) $link['session_id'] === (int) $session_id ) {
                $user_role = $link['role'] ?? '';
                break;
            }
        }
        $can_edit = $is_admin || in_array( $user_role, array( 'sponsor_primary', 'sponsor_collaborator' ), true );

        $permalink = get_permalink();

        // URL the "Download Status Report" button on the overview points at.
        // Same page, with the report query args appended; opens in a new tab
        // (target="_blank" is set in the view).
        $report_url = add_query_arg(
            array(
                'wssp_report' => 'session-status',
                'session_key' => $session['session_key'],
            ),
            $permalink
        );

        // Render the full dashboard
        ob_start();
        ?>
        <div class="wssp-portal">
            <?php
            include WSSP_PLUGIN_DIR . 'public/views/dashboard-header.php';
            include WSSP_PLUGIN_DIR . 'public/views/session-overview.php';   // Now receives merged $session_data
            include WSSP_PLUGIN_DIR . 'public/views/dashboard-phases.php';
            include WSSP_PLUGIN_DIR . 'public/views/task-modal.php';
            include WSSP_PLUGIN_DIR . 'public/views/form-drawer.php';
            ?>
        </div>
        <?php

        $html = ob_get_clean();
        WSSP_Session_Shortcodes::clear_context();
        return $html;
    }

    /* ───────────────────────────────────────────
     * HELPERS
     * ─────────────────────────────────────────── */

    private function format_task_type( $type ) {
        $labels = array(
            'form'            => 'Form',
            'upload'          => 'Upload',
            'review_approval' => 'Review',
            'approval'        => 'Approval',
            'info'            => 'Info',
        );
        return $labels[ $type ] ?? ucfirst( $type );
    }

    private function get_current_session_id() {
      $session_key = isset( $_GET['session_key'] ) ? sanitize_text_field( $_GET['session_key'] ) : '';
      if ( $session_key ) {
          $session = $this->access->get_session_by_key( $session_key );
          if ( $session ) {
              return (int) $session['id'];
          }
      }
      // Single-session fallback
      $user_id  = get_current_user_id();
      $sessions = $this->access->get_user_sessions( $user_id );
      if ( count( $sessions ) === 1 ) {
          return (int) $sessions[0]['session_id'];
      }
      return 0;
  }

}
