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

    public function __construct( WSSP_Config $config, WSSP_Audit_Log $audit, WSSP_Session_Meta $session_meta = null, WSSP_Formidable $formidable = null ) {
        $this->config       = $config;
        $this->audit        = $audit;
        $this->session_meta = $session_meta ?: new WSSP_Session_Meta();
        $this->formidable   = $formidable  ?: new WSSP_Formidable();

        global $wpdb;
        $this->sessions_table = $wpdb->prefix . 'wssp_sessions';
        $this->status_table   = $wpdb->prefix . 'wssp_task_status';

        add_action( 'admin_menu', array( $this, 'register_menus' ), 20 );
        add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
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
}
