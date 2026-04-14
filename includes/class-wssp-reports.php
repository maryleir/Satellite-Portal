<?php
/**
 * Reports — admin-facing reports for logistics team.
 *
 * Provides:
 *   - Session Audit Log:     per-session timeline of who changed what, when
 *   - Task Completion Matrix: all sessions × all tasks, with status at a glance
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

    /** @var string Sessions table name. */
    private $sessions_table;

    /** @var string Task status table name. */
    private $status_table;

    public function __construct( WSSP_Config $config, WSSP_Audit_Log $audit ) {
        $this->config = $config;
        $this->audit  = $audit;

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
     * REPORT 1 — Session Audit Log
     * ═══════════════════════════════════════════ */

    public function render_audit_log() {
        global $wpdb;

        // ─── All sessions for the dropdown ───
        $sessions = $wpdb->get_results(
            "SELECT id, session_code, short_name, event_type
             FROM {$this->sessions_table}
             ORDER BY session_code ASC",
            ARRAY_A
        );

        // ─── Filters ───
        $session_id  = absint( $_GET['session_id'] ?? 0 );
        $action_filter = sanitize_text_field( $_GET['action_filter'] ?? '' );
        $date_from   = sanitize_text_field( $_GET['date_from'] ?? '' );
        $date_to     = sanitize_text_field( $_GET['date_to'] ?? '' );
        $paged       = max( 1, absint( $_GET['paged'] ?? 1 ) );
        $per_page    = 50;

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

            // Total count for pagination
            $total = $this->count_audit_entries( $session_id, $action_filter, $date_from, $date_to );
            $total_pages = ceil( $total / $per_page );

            // If date_to is set, filter client-side (get_entries only supports 'since')
            if ( $date_to ) {
                $cutoff = $date_to . ' 23:59:59';
                $entries = array_filter( $entries, function ( $e ) use ( $cutoff ) {
                    return $e['created_at'] <= $cutoff;
                } );
            }
        }

        // ─── Distinct action slugs for the filter dropdown ───
        $action_slugs = $this->get_distinct_actions( $session_id );

        // ─── Human-readable action labels ───
        $action_labels = self::action_labels();

        include WSSP_PLUGIN_DIR . 'admin/views/report-audit-log.php';
    }

    /* ═══════════════════════════════════════════
     * REPORT 2 — Task Completion Matrix
     * ═══════════════════════════════════════════ */

    public function render_task_completion() {
        global $wpdb;

        // ─── All sessions ───
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

        // ─── Use the first session's event_type to get task definitions ───
        $event_type = $sessions[0]['event_type'] ?? 'satellite';
        $phases     = $this->config->get_phases( $event_type );

        // ─── Build a flat task list ───
        $task_columns = array();
        foreach ( $phases as $phase ) {
            foreach ( $phase['tasks'] ?? array() as $task ) {
                // Skip info/logistics-only tasks — sponsors don't complete these
                if ( ( $task['owner'] ?? 'sponsor' ) === 'logistics' && ( $task['type'] ?? 'form' ) === 'info' ) {
                    continue;
                }
                $task_columns[] = array(
                    'key'         => $task['key'],
                    'label'       => $task['label'],
                    'phase_key'   => $phase['key'],
                    'phase_label' => $phase['label'],
                );
            }
        }

        // ─── Load all task statuses in one query ───
        $session_ids  = array_column( $sessions, 'id' );
        $placeholders = implode( ',', array_fill( 0, count( $session_ids ), '%d' ) );

        $status_rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT session_id, task_key, status FROM {$this->status_table}
             WHERE session_id IN ({$placeholders})",
            ...$session_ids
        ), ARRAY_A );

        // Index: session_id => task_key => status
        // The status table now stores the correct state directly —
        // no normalization or Formidable lookups needed.
        $status_map = array();
        foreach ( $status_rows as $row ) {
            $status_map[ (int) $row['session_id'] ][ $row['task_key'] ] = $row['status'];
        }

        // ─── Compute live rollup status for each session ───
        $today = current_time( 'Y-m-d' );
        foreach ( $sessions as &$s ) {
            $sid       = (int) $s['id'];
            $total     = 0;
            $done      = 0;
            $has_action = false;
            $has_overdue = false;
            $any_activity = false;

            foreach ( $task_columns as $tc ) {
                $total++;
                $st       = $status_map[ $sid ][ $tc['key'] ] ?? 'not_started';
                $deadline = null;

                $task_def = $this->config->get_task( $s['event_type'], $tc['key'] );
                if ( $task_def ) {
                    $deadline = $task_def['deadline'] ?? null;
                }

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

        include WSSP_PLUGIN_DIR . 'admin/views/report-task-completion.php';
    }

    /* ───────────────────────────────────────────
     * HELPERS
     * ─────────────────────────────────────────── */

    /**
     * Count total audit entries for pagination.
     */
    private function count_audit_entries( $session_id, $action = '', $date_from = '', $date_to = '' ) {
        global $wpdb;
        $table = $wpdb->prefix . 'wssp_audit_log';

        $where  = array( 'session_id = %d' );
        $values = array( $session_id );

        if ( $action ) {
            $where[]  = 'action = %s';
            $values[] = $action;
        }
        if ( $date_from ) {
            $where[]  = 'created_at >= %s';
            $values[] = $date_from . ' 00:00:00';
        }
        if ( $date_to ) {
            $where[]  = 'created_at <= %s';
            $values[] = $date_to . ' 23:59:59';
        }

        $sql = sprintf(
            "SELECT COUNT(*) FROM {$table} WHERE %s",
            implode( ' AND ', $where )
        );

        return (int) $wpdb->get_var( $wpdb->prepare( $sql, ...$values ) );
    }

    /**
     * Get distinct action slugs for a session (for the filter dropdown).
     */
    private function get_distinct_actions( $session_id ) {
        if ( ! $session_id ) {
            return array();
        }

        global $wpdb;
        $table = $wpdb->prefix . 'wssp_audit_log';

        return $wpdb->get_col( $wpdb->prepare(
            "SELECT DISTINCT action FROM {$table} WHERE session_id = %d ORDER BY action ASC",
            $session_id
        ) );
    }

    /**
     * Human-readable labels for action slugs.
     */
    public static function action_labels() {
        return array(
            'field_edit'           => 'Field Edit',
            'form_submitted'       => 'Form Submitted',
            'status_change'        => 'Status Change',
            'task_acknowledged'    => 'Task Acknowledged',
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

    /**
     * Format an audit value for display.
     *
     * Truncates long values, decodes JSON arrays, and handles
     * empty/null gracefully.
     *
     * @param string|null $value Raw value from the audit log.
     * @param int         $max   Max display length.
     * @return string
     */
    public static function format_value( $value, $max = 80 ) {
        if ( $value === null || $value === '' ) {
            return '—';
        }

        // Try to decode JSON for nicer display
        $decoded = json_decode( $value, true );
        if ( is_array( $decoded ) ) {
            $value = implode( ', ', array_map( function ( $v ) {
                return is_array( $v ) ? wp_json_encode( $v ) : (string) $v;
            }, $decoded ) );
        }

        if ( mb_strlen( $value ) > $max ) {
            return mb_substr( $value, 0, $max ) . '…';
        }

        return $value;
    }

    /**
     * Status badge HTML (reuses the existing admin CSS classes).
     */
    public static function status_badge( $status ) {
        if ( ! $status || $status === 'not_started' ) {
            return '<span class="wssp-status wssp-status--not_started">Not Started</span>';
        }

        $label = ucwords( str_replace( '_', ' ', $status ) );
        $class = 'wssp-status wssp-status--' . esc_attr( $status );

        return '<span class="' . $class . '">' . esc_html( $label ) . '</span>';
    }
}
