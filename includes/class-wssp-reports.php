<?php
/**
 * Reports — admin-facing reports for logistics team.
 *
 * Provides:
 *   - Logistics Dashboard:   cross-session activity, attention items, phase progress
 *   - Session Audit Log:     per-session timeline of who changed what, when
 *   - Task Completion Matrix: all sessions × all tasks, with status at a glance
 *                             + phase drill-down showing actual responses
 *
 * Designed as a standalone class so new reports can be added without
 * touching WSSP_Admin.  Registered as a submenu under the existing
 * "Satellite Portal" admin menu.
 *
 * @package WSSP
 */

defined( 'ABSPATH' ) || exit;

class WSSP_Reports {

    /** @var WSSP_Config */
    private $config;

    /** @var WSSP_Audit_Log */
    private $audit;

    /** @var WSSP_Session_Meta */
    private $session_meta;

    /** @var WSSP_Formidable */
    private $formidable;

    /** @var string Sessions table name. */
    private $sessions_table;

    /** @var string Task status table name. */
    private $status_table;

    public function __construct( WSSP_Config $config, WSSP_Audit_Log $audit, ?WSSP_Session_Meta $session_meta = null, ?WSSP_Formidable $formidable = null ) {
        $this->config       = $config;
        $this->audit        = $audit;
        $this->session_meta = $session_meta ?: new WSSP_Session_Meta();
        $this->formidable   = $formidable  ?: new WSSP_Formidable();

        global $wpdb;
        $this->sessions_table = $wpdb->prefix . 'wssp_sessions';
        $this->status_table   = $wpdb->prefix . 'wssp_task_status';

        add_action( 'admin_menu', array( $this, 'register_menus' ), 20 );
        add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
        add_action( 'admin_init', array( $this, 'maybe_handle_file_queue_export' ) );
    }

    /* ───────────────────────────────────────────
     * ASSETS
     * ─────────────────────────────────────────── */

    public function enqueue_assets( $hook ) {
        if ( strpos( $hook, 'wssp-report' ) === false ) {
            return;
        }
        wp_enqueue_style( 'wssp-admin', WSSP_PLUGIN_URL . 'admin/css/admin.css', array(), WSSP_VERSION );
        wp_enqueue_style( 'wssp-reports', WSSP_PLUGIN_URL . 'admin/css/reports.css', array( 'wssp-admin' ), WSSP_VERSION );
    }

    /* ───────────────────────────────────────────
     * MENUS
     * ─────────────────────────────────────────── */

    public function register_menus() {

        add_submenu_page(
            'wssp-dashboard',
            'Logistics Dashboard',
            'Logistics Dashboard',
            'edit_posts',
            'wssp-report-logistics',
            array( $this, 'render_logistics_dashboard' )
        );

        add_submenu_page(
            'wssp-dashboard',
            'Session Audit Log',
            'Audit Log',
            'edit_posts',
            'wssp-report-audit',
            array( $this, 'render_audit_log' )
        );

        add_submenu_page(
            'wssp-dashboard',
            'Task Completion',
            'Task Completion',
            'edit_posts',
            'wssp-report-tasks',
            array( $this, 'render_task_completion' )
        );

        add_submenu_page(
            'wssp-dashboard',
            'File Review Queue',
            'File Review Queue',
            'edit_posts',
            'wssp-report-files',
            array( $this, 'render_file_review_queue' )
        );
    }

    /* ═══════════════════════════════════════════
     * REPORT — Logistics Dashboard
     * Cross-session overview: attention items,
     * activity feed, and phase progress.
     * ═══════════════════════════════════════════ */

    public function render_logistics_dashboard() {
        global $wpdb;

        $sessions = $wpdb->get_results(
            "SELECT id, session_code, short_name, event_type, rollup_status
             FROM {$this->sessions_table}
             ORDER BY session_code ASC",
            ARRAY_A
        );

        if ( empty( $sessions ) ) {
            include WSSP_PLUGIN_DIR . 'admin/views/report-logistics-dashboard.php';
            return;
        }

        $event_type   = $sessions[0]['event_type'] ?? 'satellite';
        $phases       = $this->config->get_phases( $event_type );
        $task_columns = $this->build_task_columns( $phases );
        $session_ids  = array_column( $sessions, 'id' );
        $status_map   = $this->load_status_map( $session_ids );

        $this->recompute_rollups( $sessions, $task_columns, $status_map );

        // ─── Section A: Attention Required ───
        $attention_sessions = array();
        $today = current_time( 'Y-m-d' );

        foreach ( $sessions as $s ) {
            if ( $s['rollup_status'] !== 'action_needed' ) {
                continue;
            }

            $sid    = (int) $s['id'];
            $issues = array();

            foreach ( $task_columns as $tc ) {
                $st       = $status_map[ $sid ][ $tc['key'] ] ?? 'not_started';
                $task_def = $this->config->get_task( $s['event_type'], $tc['key'] );
                $deadline = $task_def['deadline'] ?? null;

                if ( $st === 'revision_requested' ) {
                    $issues[] = array(
                        'task'  => $tc['label'],
                        'phase' => $tc['phase_label'],
                        'type'  => 'revision',
                        'label' => 'Revision requested',
                    );
                } elseif ( $deadline && $deadline < $today && ! in_array( $st, array( 'in_progress', 'complete', 'approved' ), true ) ) {
                    $issues[] = array(
                        'task'  => $tc['label'],
                        'phase' => $tc['phase_label'],
                        'type'  => 'overdue',
                        'label' => 'Overdue (' . date( 'M j', strtotime( $deadline ) ) . ')',
                    );
                }
            }

            $attention_sessions[] = array(
                'session' => $s,
                'issues'  => $issues,
            );
        }

        // ─── Section B: Recent Activity ───
        $days_back   = absint( $_GET['days'] ?? 7 );
        $since_date  = date( 'Y-m-d H:i:s', strtotime( "-{$days_back} days" ) );
        $audit_table = $wpdb->prefix . 'wssp_audit_log';

        $recent_activity = $wpdb->get_results( $wpdb->prepare(
            "SELECT al.*, s.session_code, s.short_name, u.display_name
             FROM {$audit_table} al
             LEFT JOIN {$this->sessions_table} s ON s.id = al.session_id
             LEFT JOIN {$wpdb->users} u ON u.ID = al.user_id
             WHERE al.created_at > %s
             ORDER BY al.created_at DESC
             LIMIT 100",
            $since_date
        ), ARRAY_A );

        $activity_by_date = array();
        foreach ( $recent_activity as $entry ) {
            $date_key = date( 'Y-m-d', strtotime( $entry['created_at'] ) );
            $activity_by_date[ $date_key ][] = $entry;
        }

        // ─── Section C: Phase Progress ───
        $session_count  = count( $sessions );
        $phase_progress = array();

        foreach ( $phases as $phase ) {
            $phase_tasks = array();

            foreach ( $phase['tasks'] ?? array() as $task ) {
                if ( ( $task['owner'] ?? 'sponsor' ) === 'logistics' && ( $task['type'] ?? 'form' ) === 'info' ) {
                    continue;
                }

                $completed = 0;
                $active    = 0;
                $revision  = 0;

                foreach ( $sessions as $s ) {
                    $st = $status_map[ (int) $s['id'] ][ $task['key'] ] ?? 'not_started';
                    if ( in_array( $st, array( 'approved', 'complete' ), true ) ) {
                        $completed++;
                    }
                    if ( $st !== 'not_started' ) {
                        $active++;
                    }
                    if ( $st === 'revision_requested' ) {
                        $revision++;
                    }
                }

                $phase_tasks[] = array(
                    'key'       => $task['key'],
                    'label'     => $task['label'],
                    'completed' => $completed,
                    'active'    => $active,
                    'revision'  => $revision,
                    'total'     => $session_count,
                );
            }

            if ( ! empty( $phase_tasks ) ) {
                $total_done  = array_sum( array_column( $phase_tasks, 'completed' ) );
                $total_cells = array_sum( array_column( $phase_tasks, 'total' ) );
                $pct         = $total_cells > 0 ? round( ( $total_done / $total_cells ) * 100 ) : 0;

                $phase_progress[] = array(
                    'key'   => $phase['key'],
                    'label' => $phase['label'],
                    'tasks' => $phase_tasks,
                    'pct'   => $pct,
                    'done'  => $total_done,
                    'total' => $total_cells,
                );
            }
        }

        $action_labels = self::action_labels();

        include WSSP_PLUGIN_DIR . 'admin/views/report-logistics-dashboard.php';
    }

    /* ═══════════════════════════════════════════
     * REPORT — Session Audit Log
     * ═══════════════════════════════════════════ */

    public function render_audit_log() {
        global $wpdb;

        $sessions = $wpdb->get_results(
            "SELECT id, session_code, short_name, event_type
             FROM {$this->sessions_table}
             ORDER BY session_code ASC",
            ARRAY_A
        );

        $session_id    = absint( $_GET['session_id'] ?? 0 );
        $action_filter = sanitize_text_field( $_GET['action_filter'] ?? '' );
        $date_from     = sanitize_text_field( $_GET['date_from'] ?? '' );
        $date_to       = sanitize_text_field( $_GET['date_to'] ?? '' );
        $paged         = max( 1, absint( $_GET['paged'] ?? 1 ) );
        $per_page      = 50;

        $entries     = array();
        $total       = 0;
        $total_pages = 0;

        if ( $session_id ) {
            $args = array(
                'limit'  => $per_page,
                'offset' => ( $paged - 1 ) * $per_page,
            );

            if ( $action_filter ) {
                $args['action'] = $action_filter;
            }
            if ( $date_from ) {
                $args['since'] = $date_from . ' 00:00:00';
            }

            $entries = $this->audit->get_entries( $session_id, $args );
            $total   = $this->count_audit_entries( $session_id, $action_filter, $date_from, $date_to );
            $total_pages = ceil( $total / $per_page );

            if ( $date_to ) {
                $cutoff  = $date_to . ' 23:59:59';
                $entries = array_filter( $entries, function ( $e ) use ( $cutoff ) {
                    return $e['created_at'] <= $cutoff;
                } );
            }
        }

        $action_slugs  = $this->get_distinct_actions( $session_id );
        $action_labels = self::action_labels();

        include WSSP_PLUGIN_DIR . 'admin/views/report-audit-log.php';
    }

    /* ═══════════════════════════════════════════
     * REPORT — Task Completion Matrix
     * + Phase drill-down when ?phase= is present
     * ═══════════════════════════════════════════ */

    public function render_task_completion() {
        global $wpdb;

        $sessions = $wpdb->get_results(
            "SELECT id, session_code, short_name, event_type, rollup_status
             FROM {$this->sessions_table}
             ORDER BY session_code ASC",
            ARRAY_A
        );

        if ( empty( $sessions ) ) {
            include WSSP_PLUGIN_DIR . 'admin/views/report-task-completion.php';
            return;
        }

        $event_type   = $sessions[0]['event_type'] ?? 'satellite';
        $phases       = $this->config->get_phases( $event_type );
        $task_columns = $this->build_task_columns( $phases );
        $session_ids  = array_column( $sessions, 'id' );
        $status_map   = $this->load_status_map( $session_ids );

        $this->recompute_rollups( $sessions, $task_columns, $status_map );

        // ─── Phase drill-down ───
        $phase_detail_key  = sanitize_text_field( $_GET['phase'] ?? '' );
        $phase_detail_data = null;

        if ( $phase_detail_key ) {
            $phase_detail_data = $this->build_phase_detail(
                $phase_detail_key, $phases, $sessions, $status_map, $event_type
            );
        }

        include WSSP_PLUGIN_DIR . 'admin/views/report-task-completion.php';
    }

    /* ═══════════════════════════════════════════
     * PHASE DETAIL — drill-down data builder
     * ═══════════════════════════════════════════ */

    /**
     * Build the phase detail data for a specific phase.
     *
     * Returns task definitions, per-session response values,
     * statuses, and who-changed-what timestamps.
     *
     * @param string $phase_key    Phase slug to drill into.
     * @param array  $phases       All phase definitions.
     * @param array  $sessions     All session rows.
     * @param array  $status_map   session_id => task_key => status.
     * @param string $event_type   Event type slug.
     * @return array|null
     */
    private function build_phase_detail( $phase_key, $phases, $sessions, $status_map, $event_type ) {
        global $wpdb;

        // Find the target phase
        $target_phase = null;
        foreach ( $phases as $phase ) {
            if ( $phase['key'] === $phase_key ) {
                $target_phase = $phase;
                break;
            }
        }
        if ( ! $target_phase ) {
            return null;
        }

        // Filter tasks
        $phase_tasks = array();
        foreach ( $target_phase['tasks'] ?? array() as $task ) {
            if ( ( $task['owner'] ?? 'sponsor' ) === 'logistics' && ( $task['type'] ?? 'form' ) === 'info' ) {
                continue;
            }
            $phase_tasks[] = $task;
        }
        if ( empty( $phase_tasks ) ) {
            return null;
        }

        $task_keys   = array_column( $phase_tasks, 'key' );
        $session_ids = array_column( $sessions, 'id' );

        // Batch load audit + status detail
        $audit_latest  = $this->batch_load_latest_audit( $session_ids, $task_keys );
        $status_detail = $this->batch_load_status_detail( $session_ids, $task_keys );

        $rows = array();
        foreach ( $sessions as $s ) {
            $sid       = (int) $s['id'];
            $task_data = array();

            // Load session record for session_key
            $session_full = $wpdb->get_row( $wpdb->prepare(
                "SELECT * FROM {$this->sessions_table} WHERE id = %d", $sid
            ), ARRAY_A );

            $frm_data     = null;
            $file_summary = null;

            foreach ( $phase_tasks as $task ) {
                $tk        = $task['key'];
                $status    = $status_map[ $sid ][ $tk ] ?? 'not_started';
                $task_type = $task['type'] ?? 'form';
                $response  = '';

                // Response value by task type
                if ( in_array( $task_type, array( 'form', 'review_approval' ), true ) ) {
                    if ( $frm_data === null && ! empty( $session_full['session_key'] ) ) {
                        $frm_data = $this->formidable->get_full_session_data( $session_full['session_key'] );
                    }
                    foreach ( $task['field_keys'] ?? array() as $fk ) {
                        $val = $frm_data[ $fk ] ?? '';
                        if ( is_array( $val ) ) {
                            $val = implode( ', ', $val );
                        }
                        if ( $val !== '' ) {
                            $response = $val;
                            break;
                        }
                    }
                } elseif ( $task_type === 'upload' ) {
                    if ( $file_summary === null && ! empty( $session_full['session_key'] ) ) {
                        $file_summary = $this->formidable->get_material_file_summary( $session_full['session_key'] );
                    }
                    $ft = $task['file_type'] ?? '';
                    if ( $ft && ! empty( $file_summary[ $ft ] ) ) {
                        $fs       = $file_summary[ $ft ];
                        $response = sprintf( 'v%d — %s', $fs['version'], $fs['status'] );
                    }
                }

                // Who/when from status detail or audit fallback
                $detail       = $status_detail[ $sid ][ $tk ] ?? array();
                $last_changed = $detail['submitted_at'] ?? $detail['reviewed_at'] ?? '';
                $changed_by   = '';

                if ( ! empty( $detail['reviewed_by'] ) ) {
                    $user         = get_userdata( (int) $detail['reviewed_by'] );
                    $changed_by   = $user ? $user->display_name : '';
                    $last_changed = $detail['reviewed_at'] ?? $last_changed;
                } elseif ( ! empty( $detail['submitted_by'] ) ) {
                    $user       = get_userdata( (int) $detail['submitted_by'] );
                    $changed_by = $user ? $user->display_name : '';
                }

                if ( ! $last_changed && isset( $audit_latest[ $sid ][ $tk ] ) ) {
                    $last_changed = $audit_latest[ $sid ][ $tk ]['created_at'] ?? '';
                    $changed_by   = $audit_latest[ $sid ][ $tk ]['display_name'] ?? '';
                }

                $task_data[ $tk ] = array(
                    'status'       => $status,
                    'response'     => $response,
                    'last_changed' => $last_changed,
                    'changed_by'   => $changed_by,
                );
            }

            $rows[ $sid ] = $task_data;
        }

        return array(
            'phase_key'   => $phase_key,
            'phase_label' => $target_phase['label'],
            'tasks'       => $phase_tasks,
            'rows'        => $rows,
        );
    }

    /* ───────────────────────────────────────────
     * BATCH LOADERS
     * ─────────────────────────────────────────── */

    private function batch_load_latest_audit( $session_ids, $task_keys ) {
        if ( empty( $session_ids ) || empty( $task_keys ) ) return array();

        global $wpdb;
        $audit_table      = $wpdb->prefix . 'wssp_audit_log';
        $sid_placeholders = implode( ',', array_fill( 0, count( $session_ids ), '%d' ) );
        $tk_placeholders  = implode( ',', array_fill( 0, count( $task_keys ), '%s' ) );
        $args             = array_merge( $session_ids, $task_keys );

        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT al.session_id, al.entity_id AS task_key, al.created_at, u.display_name
             FROM {$audit_table} al
             LEFT JOIN {$wpdb->users} u ON u.ID = al.user_id
             WHERE al.session_id IN ({$sid_placeholders})
               AND al.entity_id IN ({$tk_placeholders})
               AND al.entity_type = 'task'
             ORDER BY al.created_at DESC",
            ...$args
        ), ARRAY_A );

        $result = array();
        foreach ( $rows as $row ) {
            $sid = (int) $row['session_id'];
            $tk  = $row['task_key'];
            if ( ! isset( $result[ $sid ][ $tk ] ) ) {
                $result[ $sid ][ $tk ] = $row;
            }
        }
        return $result;
    }

    private function batch_load_status_detail( $session_ids, $task_keys ) {
        if ( empty( $session_ids ) || empty( $task_keys ) ) return array();

        global $wpdb;
        $sid_placeholders = implode( ',', array_fill( 0, count( $session_ids ), '%d' ) );
        $tk_placeholders  = implode( ',', array_fill( 0, count( $task_keys ), '%s' ) );
        $args             = array_merge( $session_ids, $task_keys );

        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT session_id, task_key, status, submitted_at, submitted_by, reviewed_at, reviewed_by
             FROM {$this->status_table}
             WHERE session_id IN ({$sid_placeholders})
               AND task_key IN ({$tk_placeholders})",
            ...$args
        ), ARRAY_A );

        $result = array();
        foreach ( $rows as $row ) {
            $result[ (int) $row['session_id'] ][ $row['task_key'] ] = $row;
        }
        return $result;
    }

    /* ───────────────────────────────────────────
     * SHARED HELPERS
     * ─────────────────────────────────────────── */

    private function build_task_columns( $phases ) {
        $cols = array();
        foreach ( $phases as $phase ) {
            foreach ( $phase['tasks'] ?? array() as $task ) {
                if ( ( $task['owner'] ?? 'sponsor' ) === 'logistics' && ( $task['type'] ?? 'form' ) === 'info' ) {
                    continue;
                }
                $cols[] = array(
                    'key'         => $task['key'],
                    'label'       => $task['label'],
                    'phase_key'   => $phase['key'],
                    'phase_label' => $phase['label'],
                );
            }
        }
        return $cols;
    }

    private function load_status_map( $session_ids ) {
        if ( empty( $session_ids ) ) return array();

        global $wpdb;
        $placeholders = implode( ',', array_fill( 0, count( $session_ids ), '%d' ) );
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT session_id, task_key, status FROM {$this->status_table}
             WHERE session_id IN ({$placeholders})",
            ...$session_ids
        ), ARRAY_A );

        $map = array();
        foreach ( $rows as $row ) {
            $map[ (int) $row['session_id'] ][ $row['task_key'] ] = $row['status'];
        }
        return $map;
    }

    private function recompute_rollups( &$sessions, $task_columns, $status_map ) {
        $today = current_time( 'Y-m-d' );
        foreach ( $sessions as &$s ) {
            $sid = (int) $s['id'];
            $total = $done = 0;
            $has_action = $has_overdue = $any_activity = false;

            foreach ( $task_columns as $tc ) {
                $total++;
                $st       = $status_map[ $sid ][ $tc['key'] ] ?? 'not_started';
                $task_def = $this->config->get_task( $s['event_type'], $tc['key'] );
                $deadline = $task_def['deadline'] ?? null;

                if ( in_array( $st, array( 'approved', 'complete' ), true ) ) {
                    $done++;
                } elseif ( $st === 'revision_requested' ) {
                    $has_action = true;
                } elseif ( $deadline && $deadline < $today && ! in_array( $st, array( 'in_progress', 'complete', 'approved' ), true ) ) {
                    $has_overdue = true;
                }
                if ( $st === 'in_progress' ) {
                    $any_activity = true;
                }
            }

            if ( $total > 0 && $done === $total ) {
                $s['rollup_status'] = 'complete';
            } elseif ( $has_overdue || $has_action ) {
                $s['rollup_status'] = 'action_needed';
            } elseif ( $done > 0 ) {
                $s['rollup_status'] = 'in_progress';
            } else {
                $s['rollup_status'] = $any_activity ? 'in_progress' : 'not_started';
            }
        }
        unset( $s );
    }

    private function count_audit_entries( $session_id, $action = '', $date_from = '', $date_to = '' ) {
        global $wpdb;
        $table  = $wpdb->prefix . 'wssp_audit_log';
        $where  = array( 'session_id = %d' );
        $values = array( $session_id );
        if ( $action )    { $where[] = 'action = %s';       $values[] = $action; }
        if ( $date_from ) { $where[] = 'created_at >= %s';  $values[] = $date_from . ' 00:00:00'; }
        if ( $date_to )   { $where[] = 'created_at <= %s';  $values[] = $date_to . ' 23:59:59'; }
        return (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$table} WHERE " . implode( ' AND ', $where ), ...$values
        ) );
    }

    private function get_distinct_actions( $session_id ) {
        if ( ! $session_id ) return array();
        global $wpdb;
        return $wpdb->get_col( $wpdb->prepare(
            "SELECT DISTINCT action FROM {$wpdb->prefix}wssp_audit_log WHERE session_id = %d ORDER BY action ASC",
            $session_id
        ) );
    }

    public static function action_labels() {
        return array(
            'field_edit'           => 'Field Edit',
            'form_submitted'       => 'Form Submitted',
            'status_change'        => 'Status Change',
            'task_acknowledged'    => 'Task Acknowledged',
            'task_reactivated'     => 'Task Reactivated',
            'file_upload'          => 'File Upload',
            'file_status_change'   => 'File Status Change',
            'session_created'      => 'Session Created',
            'session_updated'      => 'Session Updated',
            'session_meta_updated' => 'Session Meta Updated',
            'team_change'          => 'Team Change',
            'planner_added'        => 'Meeting Planner Added',
            'planner_updated'      => 'Meeting Planner Updated',
            'planner_deleted'      => 'Meeting Planner Deleted',
        );
    }

    public static function format_value( $value, $max = 80 ) {
        if ( $value === null || $value === '' ) return '—';
        $decoded = json_decode( $value, true );
        if ( is_array( $decoded ) ) {
            $value = implode( ', ', array_map( function ( $v ) {
                return is_array( $v ) ? wp_json_encode( $v ) : (string) $v;
            }, $decoded ) );
        }
        if ( mb_strlen( $value ) > $max ) return mb_substr( $value, 0, $max ) . '…';
        return $value;
    }

    /**
     * Describe an audit action in plain English for the activity feed.
     */
    public static function describe_action( $entry, $action_labels = array() ) {
        $action = $entry['action'] ?? '';
        $field  = $entry['field_name'] ?? '';
        $meta   = $entry['meta'] ? json_decode( $entry['meta'], true ) : null;

        switch ( $action ) {
            case 'field_edit':
                return 'updated <strong>' . esc_html( ucwords( str_replace( array( 'wssp_', '_' ), array( '', ' ' ), $field ) ) ) . '</strong>';
            case 'form_submitted':
                return 'submitted session data form';
            case 'status_change':
                $new = ucwords( str_replace( '_', ' ', $entry['new_value'] ?? '' ) );
                return 'changed <strong>' . esc_html( $entry['entity_id'] ?? '' ) . '</strong> → ' . esc_html( $new );
            case 'task_acknowledged':
                return 'acknowledged <strong>' . esc_html( $entry['entity_id'] ?? '' ) . '</strong>';
            case 'task_reactivated':
                return 'reactivated <strong>' . esc_html( $entry['entity_id'] ?? '' ) . '</strong>';
            case 'file_upload':
                $ft = $field ?: ( $meta['file_type'] ?? '' );
                $v  = $meta['version'] ?? '';
                return 'uploaded <strong>' . esc_html( $ft ) . '</strong>' . ( $v ? ' (v' . esc_html( $v ) . ')' : '' );
            case 'session_created':  return 'created this session';
            case 'session_updated':  return 'updated session details';
            case 'session_meta_updated': return 'updated session configuration';
            case 'team_change':      return esc_html( $entry['new_value'] ?? $entry['old_value'] ?? 'modified team' );
            default:                 return esc_html( $action_labels[ $action ] ?? ucwords( str_replace( '_', ' ', $action ) ) );
        }
    }

    public static function status_badge( $status ) {
        if ( ! $status || $status === 'not_started' ) {
            return '<span class="wssp-status wssp-status--not_started">Not Started</span>';
        }
        $label = ucwords( str_replace( '_', ' ', $status ) );
        return '<span class="wssp-status wssp-status--' . esc_attr( $status ) . '">' . esc_html( $label ) . '</span>';
    }

    /* ═══════════════════════════════════════════
     * REPORT — File Review Queue
     * Cross-session list of every material upload's
     * latest version with its current review status.
     * ═══════════════════════════════════════════ */

    /** Formidable form key for material uploads — matches WSSP_REST_File_Uploads. */
    const FILE_QUEUE_FORM_KEY       = 'wssp-sat-material-upload';
    const FILE_QUEUE_SESSION_FIELD  = 'wssp_material_session_key';

    /** Aging threshold: files pending review longer than this are flagged. */
    const FILE_QUEUE_AGING_DAYS     = 3;

    /** Status dropdown values — must match WSSP_REST_File_Uploads::$valid_statuses. */
    const FILE_STATUS_PENDING       = 'Pending, Not Reviewed';
    const FILE_STATUS_CHANGES       = 'Reviewed, Changes Required (See Notes)';
    const FILE_STATUS_APPROVED      = 'Approved';

    /**
     * Render the File Review Queue admin page.
     *
     * Loads the latest version per (session × file_type), applies any
     * request-level filters, and hands the view file a clean set of
     * arrays. Filters are reflected in the querystring so planners can
     * bookmark their view.
     */
    public function render_file_review_queue() {
        $filters = $this->parse_file_queue_filters();
        $rows    = $this->load_file_review_queue( $filters );

        // Count badges run against the *unfiltered-by-status* set so the
        // top counter pills stay stable as the user clicks through them.
        $filters_for_counts              = $filters;
        $filters_for_counts['status']    = '';
        $rows_for_counts                 = $this->load_file_review_queue( $filters_for_counts );
        $counts                          = $this->compute_file_queue_counts( $rows_for_counts );

        // Phase-filter options come from get_phases() which merges TC data
        // with behavior overrides. file_types come from the raw portal config.
        $event_type   = 'satellite';
        $event_config = $this->config->get_event_type( $event_type );
        $phases       = $this->config->get_phases( $event_type );
        $file_types   = isset( $event_config['file_types'] ) ? $event_config['file_types'] : array();

        // Recent file-related activity, last 14 days. Scoped by action so this
        // block stays distinct from the Logistics Dashboard's all-actions feed.
        $recent_activity = $this->load_file_queue_activity( 14 );

        $base_url   = admin_url( 'admin.php?page=wssp-report-files' );
        $export_url = add_query_arg( array_merge( $filters, array( 'export' => 'csv' ) ), $base_url );

        include WSSP_PLUGIN_DIR . 'admin/views/report-file-review-queue.php';
    }

    /**
     * Read and sanitize querystring filters for the file queue.
     */
    private function parse_file_queue_filters() {
        $status = isset( $_GET['status'] ) ? sanitize_text_field( wp_unslash( $_GET['status'] ) ) : '';
        if ( ! in_array( $status, array( '', 'pending', 'changes', 'approved' ), true ) ) {
            $status = '';
        }
        return array(
            'status'    => $status,
            'phase'     => isset( $_GET['phase'] )     ? sanitize_key( $_GET['phase'] )     : '',
            'file_type' => isset( $_GET['file_type'] ) ? sanitize_key( $_GET['file_type'] ) : '',
            'search'    => isset( $_GET['search'] )    ? sanitize_text_field( wp_unslash( $_GET['search'] ) ) : '',
            'aging'     => ! empty( $_GET['aging'] )   ? '1' : '',
        );
    }

    /**
     * CSV export handler.
     *
     * Fires on admin_init so we can send headers before any output. Only
     * runs when we're actually on the file-queue page with export=csv.
     */
    public function maybe_handle_file_queue_export() {
        if ( ! is_admin() )                                      return;
        if ( ( $_GET['page']   ?? '' ) !== 'wssp-report-files' ) return;
        if ( ( $_GET['export'] ?? '' ) !== 'csv' )               return;
        if ( ! current_user_can( 'edit_posts' ) )                return;

        $filters = $this->parse_file_queue_filters();
        $rows    = $this->load_file_review_queue( $filters );

        $filename = 'file-review-queue-' . current_time( 'Y-m-d' ) . '.csv';
        nocache_headers();
        header( 'Content-Type: text/csv; charset=UTF-8' );
        header( 'Content-Disposition: attachment; filename="' . $filename . '"' );

        $out = fopen( 'php://output', 'w' );
        fputcsv( $out, array(
            'Session Code', 'Session Name', 'File Type', 'Version', 'Status',
            'Uploaded By', 'Uploaded At', 'Days in Status', 'Aging',
        ) );
        foreach ( $rows as $r ) {
            fputcsv( $out, array(
                $r['session_code'],
                $r['short_name'],
                $r['file_type_label'],
                $r['version'],
                $this->normalize_status_label( $r['status'] ),
                $r['uploader_name'],
                $r['uploaded_at'],
                $r['days_in_status'],
                $r['is_aging'] ? 'YES' : '',
            ) );
        }
        fclose( $out );
        exit;
    }

    /**
     * Load the File Review Queue dataset.
     *
     * One row per (session × file_type) at its latest version, joined with
     * session metadata and author info. Returns an ordered array with
     * oldest-pending first so the planner naturally sees what's been
     * waiting longest at the top.
     */
    private function load_file_review_queue( $filters ) {
        global $wpdb;

        $form_id = $this->file_queue_form_id();
        if ( ! $form_id ) {
            return array();
        }
        $field_map = $this->file_queue_field_map( $form_id );
        if ( empty( $field_map ) ) {
            return array();
        }

        $ft_field   = $field_map['wssp_material_file_type']          ?? 0;
        $sk_field   = $field_map[ self::FILE_QUEUE_SESSION_FIELD ]   ?? 0;
        $ver_field  = $field_map['wssp_material_version']            ?? 0;
        $st_field   = $field_map['wssp_admin_material_status']       ?? 0;
        $user_field = $field_map['wssp_material_user_id']            ?? 0;

        if ( ! $ft_field || ! $sk_field || ! $ver_field ) {
            return array();
        }

        // Pull every non-draft material entry with its identifying meta in
        // one query. Older versions are filtered out per (session, file_type)
        // below in PHP — doing that group-max in SQL on Formidable's meta
        // structure is ugly, and the dataset here is small enough that in-PHP
        // aggregation is clearer and fast enough.
        $entries_sql = $wpdb->prepare(
            "SELECT i.id, i.created_at, i.user_id,
                    MAX(CASE WHEN m.field_id = %d THEN m.meta_value END) AS file_type,
                    MAX(CASE WHEN m.field_id = %d THEN m.meta_value END) AS session_key,
                    MAX(CASE WHEN m.field_id = %d THEN m.meta_value END) AS version,
                    MAX(CASE WHEN m.field_id = %d THEN m.meta_value END) AS status,
                    MAX(CASE WHEN m.field_id = %d THEN m.meta_value END) AS uploader_user_id
             FROM {$wpdb->prefix}frm_items i
             INNER JOIN {$wpdb->prefix}frm_item_metas m ON m.item_id = i.id
             WHERE i.form_id = %d AND i.is_draft = 0
             GROUP BY i.id, i.created_at, i.user_id",
            $ft_field, $sk_field, $ver_field, $st_field, $user_field, $form_id
        );

        $entries = $wpdb->get_results( $entries_sql, ARRAY_A );
        if ( empty( $entries ) ) {
            return array();
        }

        // Keep only the latest version per (session_key, file_type).
        $latest = array();
        foreach ( $entries as $e ) {
            $sk = $e['session_key'] ?: '';
            $ft = $e['file_type']   ?: '';
            if ( '' === $sk || '' === $ft ) {
                continue;
            }
            $key = $sk . '|' . $ft;
            if ( ! isset( $latest[ $key ] ) || (int) $e['version'] > (int) $latest[ $key ]['version'] ) {
                $latest[ $key ] = $e;
            }
        }
        if ( empty( $latest ) ) {
            return array();
        }

        // Join session info in bulk.
        $session_keys = array_unique( array_column( $latest, 'session_key' ) );
        $placeholders = implode( ',', array_fill( 0, count( $session_keys ), '%s' ) );
        $sessions     = $wpdb->get_results( $wpdb->prepare(
            "SELECT id, session_code, short_name, event_type, session_key
             FROM {$this->sessions_table}
             WHERE session_key IN ({$placeholders})",
            ...$session_keys
        ), ARRAY_A );
        $sessions_by_key = array();
        foreach ( $sessions as $s ) {
            $sessions_by_key[ $s['session_key'] ] = $s;
        }

        // Join user display names in bulk.
        $user_ids = array_unique( array_filter( array_map( 'intval', array_column( $latest, 'uploader_user_id' ) ) ) );
        $users    = array();
        if ( ! empty( $user_ids ) ) {
            $uid_placeholders = implode( ',', array_fill( 0, count( $user_ids ), '%d' ) );
            $user_rows = $wpdb->get_results( $wpdb->prepare(
                "SELECT ID, display_name FROM {$wpdb->users} WHERE ID IN ({$uid_placeholders})",
                ...$user_ids
            ), ARRAY_A );
            foreach ( $user_rows as $u ) {
                $users[ (int) $u['ID'] ] = $u['display_name'];
            }
        }

        // Resolve phase labels per file_type through task_behavior config.
        // Phases must come from get_phases() — it merges TC phase data with
        // behavior overrides. The raw portal_config returned by
        // get_event_type() does NOT include a 'phases' key; that lookup
        // silently returns empty and leaves every row with a blank phase.
        $event_type      = 'satellite';
        $event_config    = $this->config->get_event_type( $event_type );
        $file_types_cfg  = $event_config['file_types']    ?? array();
        $task_behavior   = $event_config['task_behavior'] ?? array();
        $phases_cfg      = $this->config->get_phases( $event_type );

        $file_type_to_phase = array();
        foreach ( $task_behavior as $task_key => $overrides ) {
            if ( ( $overrides['type'] ?? '' ) !== 'upload' ) continue;
            $ft = $overrides['file_type'] ?? '';
            if ( ! $ft ) continue;
            // Resolve phase by scanning phases for a task with this key.
            foreach ( $phases_cfg as $p ) {
                $phase_task_keys = array_column( $p['tasks'] ?? array(), 'key' );
                if ( in_array( $task_key, $phase_task_keys, true ) ) {
                    $file_type_to_phase[ $ft ] = array(
                        'key'   => $p['key'],
                        'label' => $p['label'],
                    );
                    break;
                }
            }
        }

        // Build final rows with derived fields.
        $now_ts = current_time( 'timestamp' );
        $rows   = array();
        foreach ( $latest as $e ) {
            $session = $sessions_by_key[ $e['session_key'] ] ?? null;
            if ( ! $session ) {
                // Orphaned entry — session row deleted but Formidable data lingers. Skip.
                continue;
            }
            $status  = $e['status'] ?: self::FILE_STATUS_PENDING;
            $ft      = $e['file_type'];

            // created_at on the entry row is when the latest version was
            // uploaded, which is also when the status last changed to
            // "Pending, Not Reviewed". For review/approval, we don't have
            // a separate timestamp column — close-enough approximation is
            // the entry's own modified timestamp via updated_at if present.
            $anchor_ts      = strtotime( $e['created_at'] );
            $days_in_status = $anchor_ts ? floor( ( $now_ts - $anchor_ts ) / DAY_IN_SECONDS ) : 0;

            $is_pending  = ( strpos( $status, 'Pending' )          !== false );
            $is_aging    = $is_pending && $days_in_status >= self::FILE_QUEUE_AGING_DAYS;

            $ft_label    = $file_types_cfg[ $ft ]['label'] ?? ucwords( str_replace( array( '_', '-' ), ' ', $ft ) );
            $phase       = $file_type_to_phase[ $ft ] ?? array( 'key' => '', 'label' => '—' );

            $uploader_id   = (int) $e['uploader_user_id'] ?: (int) $e['user_id'];
            $uploader_name = $users[ $uploader_id ] ?? '';

            $rows[] = array(
                'entry_id'         => (int) $e['id'],
                'session_id'       => (int) $session['id'],
                'session_key'      => $session['session_key'],
                'session_code'     => $session['session_code'],
                'short_name'       => $session['short_name'],
                'file_type'        => $ft,
                'file_type_label'  => $ft_label,
                'phase_key'        => $phase['key'],
                'phase_label'      => $phase['label'],
                'version'          => (int) $e['version'],
                'status'           => $status,
                'status_class'     => $this->status_css_class( $status ),
                'status_short'     => $this->normalize_status_label( $status ),
                'uploader_name'    => $uploader_name ?: '(unknown)',
                'uploaded_at'      => $e['created_at'],
                'days_in_status'   => $days_in_status,
                'is_aging'         => $is_aging,
            );
        }

        // Apply filters in PHP.
        $rows = array_filter( $rows, function ( $r ) use ( $filters ) {
            if ( $filters['status'] === 'pending'  && strpos( $r['status'], 'Pending' )          === false ) return false;
            if ( $filters['status'] === 'changes'  && strpos( $r['status'], 'Changes Required' ) === false ) return false;
            if ( $filters['status'] === 'approved' && strpos( $r['status'], 'Approved' )         === false ) return false;
            if ( $filters['phase']     && $r['phase_key'] !== $filters['phase'] )         return false;
            if ( $filters['file_type'] && $r['file_type'] !== $filters['file_type'] )     return false;
            if ( $filters['aging']     && ! $r['is_aging'] )                              return false;
            if ( $filters['search'] ) {
                $needle = strtolower( $filters['search'] );
                $hay    = strtolower( $r['session_code'] . ' ' . $r['short_name'] . ' ' . $r['file_type_label'] );
                if ( strpos( $hay, $needle ) === false ) return false;
            }
            return true;
        } );

        // Sort: oldest pending first, then oldest Changes Required, then the rest by session_code.
        usort( $rows, function ( $a, $b ) {
            $pri = function ( $r ) {
                if ( strpos( $r['status'], 'Pending' )          !== false ) return 0;
                if ( strpos( $r['status'], 'Changes Required' ) !== false ) return 1;
                return 2;
            };
            $pa = $pri( $a );
            $pb = $pri( $b );
            if ( $pa !== $pb ) return $pa - $pb;
            // Within the same priority, oldest-first for pending/changes, newest-first for approved.
            return $pa < 2
                ? strcmp( $a['uploaded_at'], $b['uploaded_at'] )
                : strcmp( $b['uploaded_at'], $a['uploaded_at'] );
        } );

        return array_values( $rows );
    }

    /**
     * Compute the four counter pills at the top of the queue.
     */
    private function compute_file_queue_counts( $rows ) {
        $counts = array( 'total' => 0, 'pending' => 0, 'changes' => 0, 'approved' => 0 );
        foreach ( $rows as $r ) {
            $counts['total']++;
            if ( strpos( $r['status'], 'Pending' )          !== false ) { $counts['pending']++; }
            if ( strpos( $r['status'], 'Changes Required' ) !== false ) { $counts['changes']++; }
            if ( strpos( $r['status'], 'Approved' )         !== false ) { $counts['approved']++; }
        }
        return $counts;
    }

    /**
     * Pull the last N days of file-related audit entries for the bottom feed.
     */
    private function load_file_queue_activity( $days = 14 ) {
        global $wpdb;
        $audit_table = $wpdb->prefix . 'wssp_audit_log';
        $since       = date( 'Y-m-d H:i:s', strtotime( "-{$days} days" ) );
        return $wpdb->get_results( $wpdb->prepare(
            "SELECT al.action, al.created_at, al.user_id, al.entity_id, al.field_name,
                    al.old_value, al.new_value, al.meta, al.session_id,
                    s.session_code, s.short_name, s.session_key,
                    u.display_name
             FROM {$audit_table} al
             LEFT JOIN {$this->sessions_table} s ON s.id = al.session_id
             LEFT JOIN {$wpdb->users} u            ON u.ID = al.user_id
             WHERE al.created_at > %s
               AND al.action IN ('file_upload', 'status_change')
             ORDER BY al.created_at DESC
             LIMIT 200",
            $since
        ), ARRAY_A );
    }

    private function file_queue_form_id() {
        static $id = null;
        if ( null !== $id ) return $id;
        if ( ! class_exists( 'FrmForm' ) ) { $id = 0; return $id; }
        $form = FrmForm::getOne( self::FILE_QUEUE_FORM_KEY );
        $id = $form ? (int) $form->id : 0;
        return $id;
    }

    private function file_queue_field_map( $form_id ) {
        static $cache = array();
        if ( isset( $cache[ $form_id ] ) ) return $cache[ $form_id ];
        global $wpdb;
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT id, field_key FROM {$wpdb->prefix}frm_fields WHERE form_id = %d",
            $form_id
        ), ARRAY_A );
        $map = array();
        foreach ( $rows as $r ) {
            $map[ $r['field_key'] ] = (int) $r['id'];
        }
        $cache[ $form_id ] = $map;
        return $map;
    }

    private function status_css_class( $status ) {
        if ( strpos( $status, 'Approved' )         !== false ) return 'approved';
        if ( strpos( $status, 'Changes Required' ) !== false ) return 'changes-required';
        return 'pending';
    }

    /**
     * Short, display-friendly form of a full Formidable status string.
     */
    private function normalize_status_label( $status ) {
        if ( strpos( $status, 'Approved' )         !== false ) return 'Approved';
        if ( strpos( $status, 'Changes Required' ) !== false ) return 'Changes Required';
        if ( strpos( $status, 'Pending' )          !== false ) return 'Pending Review';
        return $status;
    }
}
