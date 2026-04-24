<?php
/**
 * REST API endpoints.
 *
 * Registers all /wssp/v1/ REST routes. Each route validates
 * permissions via session access checks, not just login status.
 *
 * Current endpoints:
 *   POST /task/submit       — Mark a task as complete
 *   POST /task/acknowledge  — Record acknowledgment of a Review Required task
 *   GET  /task/visibility   — Get current conditional task visibility
 *   GET  /session/refresh   — Return HTML partials for all mutable UI regions
 *
 * @package WSSP
 */

defined( 'ABSPATH' ) || exit;

class WSSP_REST {

    /** @var WSSP_Config */
    private $config;

    /** @var WSSP_Session_Access */
    private $access;

    /** @var WSSP_Audit_Log */
    private $audit;
    
    /** @var WSSP_Formidable */
    private $formidable;
    
    /** @var WSSP_Smartsheet */
    private $smartsheet;



    /** @var string REST namespace. */
    private $namespace = 'wssp/v1';
    
    public function __construct( WSSP_Config $config, WSSP_Session_Access $access, WSSP_Audit_Log $audit, WSSP_Formidable $formidable, WSSP_Smartsheet $smartsheet ) {
        $this->config      = $config;
        $this->access      = $access;
        $this->audit       = $audit;
        $this->formidable  = $formidable;
        $this->smartsheet  = $smartsheet;
        add_action( 'rest_api_init', array( $this, 'register_routes' ) );
    }


    /* ───────────────────────────────────────────
     * ROUTE REGISTRATION
     * ─────────────────────────────────────────── */

    public function register_routes() {

        // POST /task/submit — Mark a task as complete
        register_rest_route( $this->namespace, '/task/submit', array(
            'methods'             => 'POST',
            'callback'            => array( $this, 'submit_task' ),
            'permission_callback' => array( $this, 'check_logged_in' ),
            'args'                => array(
                'session_id' => array(
                    'required'          => true,
                    'sanitize_callback' => 'absint',
                ),
                'task_key'   => array(
                    'required'          => true,
                    'sanitize_callback' => 'sanitize_text_field',
                ),
            ),
        ));

        // POST /task/acknowledge — Record acknowledgment
        register_rest_route( $this->namespace, '/task/acknowledge', array(
            'methods'             => 'POST',
            'callback'            => array( $this, 'acknowledge_task' ),
            'permission_callback' => array( $this, 'check_logged_in' ),
            'args'                => array(
                'session_id' => array(
                    'required'          => true,
                    'sanitize_callback' => 'absint',
                ),
                'task_key'   => array(
                    'required'          => true,
                    'sanitize_callback' => 'sanitize_text_field',
                ),
            ),
        ));

        // POST /task/reactivate — Admin-only: reset a completed task
        register_rest_route( $this->namespace, '/task/reactivate', array(
            'methods'             => 'POST',
            'callback'            => array( $this, 'reactivate_task' ),
            'permission_callback' => array( $this, 'check_logged_in' ),
            'args'                => array(
                'session_id' => array(
                    'required'          => true,
                    'sanitize_callback' => 'absint',
                ),
                'task_key'   => array(
                    'required'          => true,
                    'sanitize_callback' => 'sanitize_text_field',
                ),
            ),
        ));

        // GET /task/visibility — Get current conditional task visibility
        register_rest_route( $this->namespace, '/task/visibility', array(
            'methods'             => 'GET',
            'callback'            => array( $this, 'get_task_visibility' ),
            'permission_callback' => array( $this, 'check_logged_in' ),
            'args'                => array(
                'session_id' => array(
                    'required'          => true,
                    'sanitize_callback' => 'absint',
                ),
            ),
        ));

        // GET /session/refresh — Return HTML partials for all mutable UI regions
        register_rest_route( $this->namespace, '/session/refresh', array(
            'methods'             => 'GET',
            'callback'            => array( $this, 'refresh_session_partials' ),
            'permission_callback' => array( $this, 'check_logged_in' ),
            'args'                => array(
                'session_id' => array(
                    'required'          => true,
                    'sanitize_callback' => 'absint',
                ),
            ),
        ));
    }

    /* ───────────────────────────────────────────
     * PERMISSION CALLBACKS
     * ─────────────────────────────────────────── */

    /**
     * Basic check: user must be logged in.
     * Session-level access is verified inside each callback.
     */
    public function check_logged_in() {
        return is_user_logged_in();
    }

    /* ───────────────────────────────────────────
     * TASK SUBMIT
     * ─────────────────────────────────────────── */

    /**
     * Mark a single task as submitted/complete.
     *
     * @param WP_REST_Request $request
     * @return WP_REST_Response
     */
    public function submit_task( $request ) {
        $session_id = $request->get_param( 'session_id' );
        $task_key   = $request->get_param( 'task_key' );
        $user_id    = get_current_user_id();

        // Verify session access
        if ( ! $this->access->user_can_access( $user_id, $session_id ) ) {
            return new WP_REST_Response( array( 'success' => false, 'message' => 'Access denied.' ), 403 );
        }

        // Get session for event_type
        $session = $this->get_session( $session_id );
        if ( ! $session ) {
            return new WP_REST_Response( array( 'success' => false, 'message' => 'Session not found.' ), 404 );
        }

        $event_type = $session['event_type'] ?? 'satellite';

        // Verify task exists in config
        $task_config = $this->config->get_task( $event_type, $task_key );
        if ( ! $task_config ) {
            return new WP_REST_Response( array( 'success' => false, 'message' => 'Unknown task.' ), 400 );
        }

        // Determine the correct completion status based on task type.
        $task_type = $task_config['type'] ?? 'form';

        // Upload tasks are completed by logistics approval, not by checkbox.
        if ( $task_type === 'upload' ) {
            return new WP_REST_Response( array(
                'success' => false,
                'message' => 'Upload tasks are completed when logistics approves the file.',
            ), 400 );
        }

        // Delegate to Dashboard — handles upsert, audit log, and rollup recalculation
        $dashboard = new WSSP_Dashboard( $this->config, $this->access, $this->audit );
        $dashboard->set_task_status( $session_id, $task_key, 'complete' );

        return new WP_REST_Response( array(
            'success' => true,
            'status'  => 'complete',
        ), 200 );
    }

    /* ───────────────────────────────────────────
     * TASK ACKNOWLEDGE
     * ─────────────────────────────────────────── */

    /**
     * Record acknowledgment of a Review Required task.
     *
     * @param WP_REST_Request $request
     * @return WP_REST_Response
     */
    public function acknowledge_task( $request ) {
        $session_id = $request->get_param( 'session_id' );
        $task_key   = $request->get_param( 'task_key' );
        $user_id    = get_current_user_id();

        if ( ! $this->access->user_can_access( $user_id, $session_id ) ) {
            return new WP_REST_Response( array( 'success' => false, 'message' => 'Access denied.' ), 403 );
        }

        $session = $this->get_session( $session_id );
        if ( ! $session ) {
            return new WP_REST_Response( array( 'success' => false, 'message' => 'Session not found.' ), 404 );
        }

        $event_type = $session['event_type'] ?? 'satellite';

        // Write acknowledged_at timestamp
        global $wpdb;
        $table = $wpdb->prefix . 'wssp_task_status';

        $existing = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$table} WHERE session_id = %d AND task_key = %s",
            $session_id, $task_key
        ), ARRAY_A );

        // Idempotency: if already acknowledged, return success without
        // re-writing or creating another audit log entry.
        if ( $existing && ! empty( $existing['acknowledged_at'] ) ) {
            return new WP_REST_Response( array( 'success' => true ), 200 );
        }

        if ( $existing ) {
            $wpdb->update(
                $table,
                array(
                    'acknowledged_at' => current_time( 'mysql' ),
                    'acknowledged_by' => $user_id,
                ),
                array( 'id' => $existing['id'] ),
                array( '%s', '%d' ),
                array( '%d' )
            );
        } else {
            $wpdb->insert(
                $table,
                array(
                    'session_id'      => $session_id,
                    'task_key'        => $task_key,
                    'status'          => 'acknowledged',
                    'acknowledged_at' => current_time( 'mysql' ),
                    'acknowledged_by' => $user_id,
                ),
                array( '%d', '%s', '%s', '%s', '%d' )
            );
        }

        // Audit log
        $this->audit->log( array(
            'session_id'  => $session_id,
            'event_type'  => $event_type,
            'action'      => 'task_acknowledged',
            'entity_type' => 'task',
            'entity_id'   => $task_key,
            'user_id'     => $user_id,
        ));

        return new WP_REST_Response( array(
            'success' => true,
        ), 200 );
    }

    /* ───────────────────────────────────────────
     * TASK REACTIVATE (Admin only)
     * ─────────────────────────────────────────── */

    /**
     * Reactivate a completed task.
     *
     * Admin-only action. Only valid from a terminal status (complete /
     * approved / submitted_by_sponsor) — returns 400 otherwise.
     *
     * Behavior depends on who completed the task:
     *   - submitted_by = 0 (system, e.g. Smartsheet-imported addons) →
     *     delete the row. Task returns to not_started. No prior sponsor
     *     work to preserve.
     *   - submitted_by = real user ID (sponsor completed) → update the
     *     row to in_progress and null out submitted_at / submitted_by.
     *     Preserves the sponsor's prior progress so the task reappears
     *     in "in progress" rather than "get started" buckets.
     *
     * @param WP_REST_Request $request
     * @return WP_REST_Response
     */
    public function reactivate_task( $request ) {
        $session_id = $request->get_param( 'session_id' );
        $task_key   = $request->get_param( 'task_key' );
        $user_id    = get_current_user_id();

        // Admin-only check
        if ( ! current_user_can( 'edit_others_posts' ) ) {
            return new WP_REST_Response( array( 'success' => false, 'message' => 'Only administrators can reactivate tasks.' ), 403 );
        }

        $session = $this->get_session( $session_id );
        if ( ! $session ) {
            return new WP_REST_Response( array( 'success' => false, 'message' => 'Session not found.' ), 404 );
        }

        $event_type = $session['event_type'] ?? 'satellite';

        // Get current status row — we need both `status` and `submitted_by`
        // to decide whether to delete or downgrade to in_progress.
        global $wpdb;
        $table = $wpdb->prefix . 'wssp_task_status';
        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT status, submitted_by FROM {$table} WHERE session_id = %d AND task_key = %s",
            $session_id, $task_key
        ), ARRAY_A );

        if ( ! $row ) {
            return new WP_REST_Response( array( 'success' => false, 'message' => 'Task has no status to reactivate.' ), 400 );
        }

        $old_status = $row['status'];

        // Only allow reactivation from a terminal status. A task that's
        // in_progress or not_started is not a completion that can be
        // reversed — fail loudly rather than silently deleting the row.
        if ( ! in_array( $old_status, array( 'complete', 'approved', 'submitted_by_sponsor' ), true ) ) {
            return new WP_REST_Response( array(
                'success' => false,
                'message' => sprintf( 'Cannot reactivate a task in status "%s". Reactivate is only valid for completed tasks.', $old_status ),
            ), 400 );
        }

        // System-completed (submitted_by=0) vs sponsor-completed determines
        // the target. Sponsor work is preserved as in_progress.
        $was_system_completed = ( (int) $row['submitted_by'] ) === 0;
        $new_status = $was_system_completed ? 'not_started' : 'in_progress';

        if ( $was_system_completed ) {
            $wpdb->delete(
                $table,
                array( 'session_id' => $session_id, 'task_key' => $task_key ),
                array( '%d', '%s' )
            );
        } else {
            // Downgrade to in_progress; null out submission metadata so
            // a future completion records the new submitter cleanly.
            $wpdb->update(
                $table,
                array( 'status' => 'in_progress', 'submitted_at' => null, 'submitted_by' => null ),
                array( 'session_id' => $session_id, 'task_key' => $task_key ),
                array( '%s', '%s', '%d' ),
                array( '%d', '%s' )
            );
        }

        // Recalculate session rollup
        $dashboard = new WSSP_Dashboard( $this->config, $this->access, $this->audit );
        $dashboard->update_rollup_status( $session_id );

        // Audit log
        $this->audit->log( array(
            'session_id'  => $session_id,
            'event_type'  => $event_type,
            'action'      => 'task_reactivated',
            'entity_type' => 'task',
            'entity_id'   => $task_key,
            'user_id'     => $user_id,
            'old_value'   => $old_status,
            'new_value'   => $new_status,
            'meta'        => array(
                'trigger'              => 'admin_reactivate',
                'was_system_completed' => $was_system_completed,
            ),
        ));

        return new WP_REST_Response( array(
            'success' => true,
            'status'  => $new_status,
        ), 200 );
    }

    /* ───────────────────────────────────────────
     * TASK VISIBILITY (Condition Evaluator)
     * ─────────────────────────────────────────── */

    /**
     * Return the current visibility state of all conditional tasks.
     *
     * @param WP_REST_Request $request
     * @return WP_REST_Response
     */
    public function get_task_visibility( $request ) {
        $session_id = $request->get_param( 'session_id' );
        $user_id    = get_current_user_id();

        if ( ! $this->access->user_can_access( $user_id, $session_id ) ) {
            return new WP_REST_Response( array( 'success' => false, 'message' => 'Access denied.' ), 403 );
        }

        $session = $this->get_session( $session_id );
        if ( ! $session ) {
            return new WP_REST_Response( array( 'success' => false, 'message' => 'Session not found.' ), 404 );
        }

        $event_type = $session['event_type'] ?? 'satellite';

        // Load fresh session data (includes latest Formidable values)
        $session_data = $this->formidable->get_full_session_data( $session['session_key'] );

        // Load session meta once — used for both condition evaluation and addon states
        $session_meta_obj = new WSSP_Session_Meta();
        $session_meta     = $session_meta_obj->get_all( $session_id );

        // Merge session meta so condition rules can access addon_ keys
        $merged_data = array_merge( $session_data, $session_meta );

        // Evaluate all condition rules
        $conditions = WSSP_Condition_Evaluator::evaluate_all( $merged_data );

        // Build per-task visibility map from condition slugs
        $conditional_tasks = WSSP_Condition_Evaluator::get_conditional_tasks( $event_type, $this->config );
        $task_visibility   = array();
        foreach ( $conditional_tasks as $task_key => $condition_slug ) {
            $task_visibility[ $task_key ] = $conditions[ $condition_slug ] ?? true;
        }

        // Badge states (used by session overview header)
        $ce_status = $session_data['wssp_program_ce_status'] ?? '';
        $audience  = $session_data['wssp_program_audience_type'] ?? '';
        $badges = array(
            'ce'       => WSSP_Condition_Evaluator::is_visible( 'ce_path', $session_data ),
            'audience' => ! empty( $audience ) && stripos( $audience, 'All' ) === false,
        );

        // Add-on states (single source of truth on WSSP_Config)
        $addon_states = $this->config->compute_addon_states( $session_meta, $session_data, $event_type );

        return new WP_REST_Response( array(
            'success'      => true,
            'conditions'   => $conditions,
            'tasks'        => $task_visibility,
            'badges'       => $badges,
            'addon_states' => $addon_states,
        ), 200 );
    }

    /* ───────────────────────────────────────────
     * SESSION REFRESH — HTML Partials
     * ─────────────────────────────────────────── */

    /**
     * Return freshly rendered HTML partials for all mutable UI regions.
     *
     * This is the single "refresh everything" endpoint. After any mutation
     * (form submit, checkbox click, acknowledgment), the JS calls this and
     * swaps each DOM region with its server-rendered partial. Because the
     * partials use the same PHP templates as the full page render, the
     * client-side DOM always matches what a full page load would produce.
     *
     * Response shape:
     *   {
     *     "success": true,
     *     "partials": {
     *       "session_overview": "<div class=\"wssp-overview-row\">...",
     *       "task_cards": {
     *         "program-title": "<div class=\"wssp-task-card\" ...",
     *         "session-description": "<div class=\"wssp-task-card\" ..."
     *       },
     *       "phase_progress": {
     *         "program-development": { "done": 3, "total": 5, "html": "<span class...>" }
     *       }
     *     }
     *   }
     *
     * @param WP_REST_Request $request
     * @return WP_REST_Response
     */
    public function refresh_session_partials( $request ) {
        $session_id = $request->get_param( 'session_id' );
        $user_id    = get_current_user_id();

        if ( ! $this->access->user_can_access( $user_id, $session_id ) ) {
            return new WP_REST_Response( array( 'success' => false, 'message' => 'Access denied.' ), 403 );
        }

        $session = $this->get_session( $session_id );
        if ( ! $session ) {
            return new WP_REST_Response( array( 'success' => false, 'message' => 'Session not found.' ), 404 );
        }

        $event_type = $session['event_type'] ?? 'satellite';
        $is_admin   = current_user_can( 'edit_others_posts' );

        // ─── Load all data exactly as render_session_detail() does ───
        $session_data = $this->formidable->get_full_session_data( $session['session_key'] );

        $session_meta_obj = new WSSP_Session_Meta();
        $session_meta     = $session_meta_obj->get_all( $session_id );

        // Merge session meta into session data so condition rules can access
        // addon_ keys (e.g. addon_push_notification) alongside form fields.
        $merged_data = array_merge( $session_data, $session_meta );

        // Create dashboard instance — used for both status reads and dashboard data
        $dashboard = new WSSP_Dashboard( $this->config, $this->access, $this->audit );

        $task_statuses    = $dashboard->get_task_statuses( $session_id );

        // Compute add-on states (single source of truth on WSSP_Config)
        $addon_states     = $this->config->compute_addon_states( $session_meta, $session_data, $event_type );
        $purchased_addons = $this->config->get_purchased_addons( $session_meta, $session_data, $event_type );

        // Set session shortcode context for template rendering
        WSSP_Session_Shortcodes::set_context( $session_data, $session_meta );

        // Get enriched dashboard data
        $dashboard_data = $dashboard->get_dashboard_data( $session_id, $event_type, $merged_data, $addon_states );

        $phases            = $dashboard_data['phases'] ?? array();
        $current_phase_key = $dashboard_data['current_phase_key'] ?? '';

        // Load task content
        $task_content_obj = new WSSP_Task_Content( $this->config );
        $task_content     = $task_content_obj->get_for_session( $session_id, $event_type );

        // Compute stats
        $stats = $dashboard->compute_dashboard_stats( $phases, $purchased_addons, $addon_states );

        // ─── Load file summary for upload task cards ───
        $file_summary = array();
        if ( class_exists( 'FrmForm' ) ) {
            $file_summary = $this->formidable->get_material_file_summary( $session['session_key'] );
        }

        // Permission check for can_edit
        $all_sessions = ( new WSSP_Session_Access() )->get_user_sessions( $user_id );
        $user_role = '';
        foreach ( $all_sessions as $link ) {
            if ( (int) $link['session_id'] === (int) $session_id ) {
                $user_role = $link['role'] ?? '';
                break;
            }
        }
        $can_edit = $is_admin || in_array( $user_role, array( 'sponsor_primary', 'sponsor_collaborator' ), true );

        $partials = array();

        // ─── 1. Session overview card ───
        ob_start();
        include WSSP_PLUGIN_DIR . 'public/views/session-overview.php';
        $partials['session_overview'] = ob_get_clean();

        // ─── 2. Task cards — one per task ───
        $partials['task_cards'] = array();
        foreach ( $phases as $phase ) {
            foreach ( $phase['tasks'] ?? array() as $task ) {
                $task_display = array();
                ob_start();
                include WSSP_PLUGIN_DIR . 'public/views/task-card.php';
                $partials['task_cards'][ $task['key'] ] = ob_get_clean();
            }
        }

        // ─── 3. Phase progress counters ───
        $partials['phase_progress'] = array();
        foreach ( $phases as $phase ) {
            $phase_key = $phase['key'];
            $tasks     = $phase['tasks'] ?? array();

            $total_actionable = 0;
            $total_done       = 0;
            foreach ( $tasks as $t ) {
                if ( $t['type'] === 'info' ) continue;
                if ( ! empty( $t['is_hidden'] ) ) continue;
                if ( ! ( $t['completable'] ?? true ) ) continue;
                $t_addon = $t['addon'] ?? null;
                if ( $t_addon && ! in_array( $t_addon, $purchased_addons, true ) ) continue;

                $total_actionable++;

                // Done-state follows the standard rule: status row only.
                // Addon status is written by sync_addon_task_statuses
                // (SS import path) or apply_addon_request_triggers
                // (sponsor form latch path).
                $t_done = ! empty( $t['is_done'] ) || ! empty( $t['is_submitted'] );

                if ( $t_done ) $total_done++;
            }

            $progress_class = ( $total_done === $total_actionable && $total_actionable > 0 )
                ? 'wssp-phase__progress wssp-phase__progress--done'
                : 'wssp-phase__progress';

            $progress_html = '';
            if ( $total_actionable > 0 ) {
                $progress_html = '<span class="' . esc_attr( $progress_class ) . '">'
                    . '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">'
                    . '<path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/>'
                    . '<polyline points="22 4 12 14.01 9 11.01"/>'
                    . '</svg> '
                    . esc_html( $total_done . '/' . $total_actionable ) . ' completed'
                    . '</span>';
            }

            $partials['phase_progress'][ $phase_key ] = array(
                'done'  => $total_done,
                'total' => $total_actionable,
                'html'  => $progress_html,
            );
        }

        // ─── 4. Dashboard stats for the progress sidebar ───
        $partials['stats'] = $stats;

        // ─── 5. Task modals — re-render so modal type (review/info) stays in sync ───
        $partials['task_modals'] = array();
        if ( ! empty( $task_content ) ) {
            // Build task config lookup (same as task-modal.php)
            $_task_config_lookup = array();
            foreach ( $phases as $_phase ) {
                foreach ( $_phase['tasks'] ?? array() as $_t ) {
                    $_task_config_lookup[ $_t['key'] ] = $_t;
                }
            }

            foreach ( $task_content as $tc_task_key => $content ) {
                if ( empty( $content->sections ) ) continue;

                $is_review   = ! empty( $content->requires_acknowledgment );
                $is_acked    = ! empty( $task_statuses[ $tc_task_key ]['acknowledged_at'] );
                $modal_class = ( $is_review && ! $is_acked ) ? 'wssp-modal--review' : 'wssp-modal--info';

                $task_config = $_task_config_lookup[ $tc_task_key ] ?? array();
                $priority    = $task_config['priority'] ?? 'medium';
                $type_label  = '';
                if ( ! empty( $task_config['type'] ) ) {
                    $type_labels = array( 'form' => 'Form Required', 'upload' => 'Upload Required', 'approval' => 'Approval Required' );
                    $type_label  = $type_labels[ $task_config['type'] ] ?? '';
                }

                $deadline_raw = $content->deadline ?: ( $task_config['deadline'] ?? $task_config['date'] ?? '' );
                $deadline_display = '';
                if ( $deadline_raw ) {
                    $deadline_display = WSSP_TC_Task_Content::resolve_deadline( $deadline_raw );
                }

                // Render this single modal
                ob_start();
                ?>
                <div class="wssp-modal <?php echo esc_attr( $modal_class ); ?>"
                     data-task-key="<?php echo esc_attr( $tc_task_key ); ?>"
                     data-modal-type="<?php echo ( $is_review && ! $is_acked ) ? 'review_required' : 'more_info'; ?>"
                     style="display:none;">

                    <div class="wssp-modal__dialog">
                        <button class="wssp-modal__close" aria-label="Close">
                            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/>
                            </svg>
                        </button>

                        <div class="wssp-modal__header">
                            <?php if ( $is_review && ! $is_acked ) : ?>
                                <div class="wssp-modal__icon wssp-modal__icon--review">
                                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                        <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/>
                                        <polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/>
                                        <line x1="16" y1="17" x2="8" y2="17"/><polyline points="10 9 9 9 8 9"/>
                                    </svg>
                                </div>
                            <?php endif; ?>
                            <div class="wssp-modal__header-text">
                                <h2 class="wssp-modal__title"><?php echo wssp_render_field( $content->title, 'plain' ); ?></h2>
                                <div class="wssp-modal__tags">
                                    <?php if ( $priority === 'high' ) : ?>
                                        <span class="wssp-badge wssp-badge--priority-high">high priority</span>
                                    <?php endif; ?>
                                    <?php if ( $deadline_display ) : ?>
                                        <span class="wssp-badge wssp-badge--outline">Due: <?php echo wssp_render_field( $deadline_display, 'plain' ); ?></span>
                                    <?php endif; ?>
                                    <?php if ( $type_label ) : ?>
                                        <span class="wssp-badge wssp-badge--outline"><?php echo wssp_render_field( $type_label, 'plain' ); ?></span>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>

                        <hr class="wssp-modal__divider">

                        <div class="wssp-modal__body">
                            <?php if ( ! empty( $content->sections ) ) :
                                foreach ( $content->sections as $section ) :
                                    if ( empty( $section->content ) ) continue;
                            ?>
                                <div class="wssp-modal__section">
                                    <?php if ( ! empty( $section->heading ) ) : ?>
                                        <h3 class="wssp-modal__section-title"><?php echo wssp_render_field( $section->heading, 'plain' ); ?></h3>
                                    <?php endif; ?>
                                    <div class="wssp-modal__section-content wssp-modal__<?php echo esc_attr( $section->section_type ); ?>">
                                        <?php echo wssp_render_field( $section->content, 'rich' ); ?>
                                    </div>
                                </div>
                            <?php
                                endforeach;
                            endif;
                            ?>
                        </div>

                        <?php if ( $is_review && ! $is_acked ) : ?>
                            <hr class="wssp-modal__divider">
                            <div class="wssp-modal__acknowledgment">
                                <label class="wssp-modal__ack-label">
                                    <input type="checkbox" class="wssp-modal__ack-checkbox"
                                           data-task-key="<?php echo esc_attr( $tc_task_key ); ?>"
                                           data-session-id="<?php echo esc_attr( $session_id ); ?>">
                                    <span class="wssp-modal__ack-checkmark"></span>
                                    <span class="wssp-modal__ack-text">
                                        <?php echo wssp_render_field( $content->acknowledgment_text ?: 'I have reviewed the above requirements and understand the obligations', 'plain' ); ?>
                                    </span>
                                </label>
                            </div>
                        <?php elseif ( $is_review && $is_acked ) : ?>
                            <hr class="wssp-modal__divider">
                            <div class="wssp-modal__acknowledgment wssp-modal__acknowledgment--confirmed">
                                <div class="wssp-modal__ack-confirmed">
                                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
                                        <path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/>
                                        <polyline points="22 4 12 14.01 9 11.01"/>
                                    </svg>
                                    <span class="wssp-modal__ack-text">
                                        <?php echo wssp_render_field( $content->acknowledgment_text ?: 'I have reviewed the above requirements and understand the obligations', 'plain' ); ?>
                                    </span>
                                </div>
                            </div>
                        <?php endif; ?>

                        <div class="wssp-modal__footer">
                            <button class="wssp-btn wssp-btn--outline wssp-modal__close-btn">Close</button>
                        </div>
                    </div>
                </div>
                <?php
                $partials['task_modals'][ $tc_task_key ] = ob_get_clean();
            }
        }

        // Clear shortcode context
        WSSP_Session_Shortcodes::clear_context();

        return new WP_REST_Response( array(
            'success'  => true,
            'partials' => $partials,
        ), 200 );
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
            "SELECT * FROM {$wpdb->prefix}wssp_sessions WHERE id = %d",
            $session_id
        ), ARRAY_A );
    }

}