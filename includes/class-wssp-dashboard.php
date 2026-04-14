<?php
/**
 * Dashboard phase engine.
 *
 * Reads task config + stored task statuses and computes what the
 * sponsor dashboard should display: overdue tasks, active tasks,
 * upcoming tasks, and completed tasks.
 *
 * Also handles task status transitions and provides REST endpoints
 * for the submit actions.
 *
 * @package WSSP
 */

defined( 'ABSPATH' ) || exit;

class WSSP_Dashboard {

    /** @var WSSP_Config */
    private $config;

    /** @var WSSP_Session_Access */
    private $access;

    /** @var WSSP_Audit_Log */
    private $audit;

    /** @var string */
    private $status_table;

    public function __construct( WSSP_Config $config, WSSP_Session_Access $access, WSSP_Audit_Log $audit ) {
        $this->config = $config;
        $this->access = $access;
        $this->audit  = $audit;

        global $wpdb;
        $this->status_table = $wpdb->prefix . 'wssp_task_status';

    }


    /* ───────────────────────────────────────────
     * TASK STATUS READS
     * ─────────────────────────────────────────── */

    /**
     * Get all task statuses for a session.
     *
     * @param int $session_id
     * @return array Keyed by task_key => status row.
     */
    public function get_task_statuses( $session_id ) {
        global $wpdb;

        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM {$this->status_table} WHERE session_id = %d",
            $session_id
        ), ARRAY_A );

        $statuses = array();
        foreach ( $rows as $row ) {
            $statuses[ $row['task_key'] ] = $row;
        }
        return $statuses;
    }

    /**
     * Get status for a single task. Returns 'not_started' if no row exists.
     *
     * @param int    $session_id
     * @param string $task_key
     * @return string
     */
    public function get_task_status( $session_id, $task_key ) {
        global $wpdb;

        $status = $wpdb->get_var( $wpdb->prepare(
            "SELECT status FROM {$this->status_table} WHERE session_id = %d AND task_key = %s",
            $session_id, $task_key
        ));

        return $status ?: 'not_started';
    }

    /* ───────────────────────────────────────────
     * TASK STATUS WRITES
     * ─────────────────────────────────────────── */

    /**
     * Set a task's status. Creates the row if it doesn't exist.
     *
     * @param int    $session_id
     * @param string $task_key
     * @param string $new_status
     * @param array  $extra  Optional: submitted_by, reviewed_by, review_note.
     * @return bool
     */
    public function set_task_status( $session_id, $task_key, $new_status, $extra = array() ) {
        global $wpdb;

        $old_status = $this->get_task_status( $session_id, $task_key );

        $data = array(
            'session_id' => $session_id,
            'task_key'   => $task_key,
            'status'     => $new_status,
        );
        $formats = array( '%d', '%s', '%s' );

        // Record who performed this action and when.
        // submitted_at/submitted_by = sponsor-initiated actions
        // reviewed_at/reviewed_by   = logistics-initiated actions
        if ( in_array( $new_status, array( 'in_progress', 'acknowledged', 'submitted_by_sponsor', 'complete' ), true ) ) {
            $data['submitted_at'] = current_time( 'mysql' );
            $data['submitted_by'] = get_current_user_id();
            $formats[] = '%s';
            $formats[] = '%d';
        }

        if ( in_array( $new_status, array( 'approved', 'revision_requested' ), true ) ) {
            $data['reviewed_at'] = current_time( 'mysql' );
            $data['reviewed_by'] = get_current_user_id();
            $formats[] = '%s';
            $formats[] = '%d';
            if ( ! empty( $extra['review_note'] ) ) {
                $data['review_note'] = sanitize_textarea_field( $extra['review_note'] );
                $formats[] = '%s';
            }
        }

        // Upsert: insert or update
        $exists = $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM {$this->status_table} WHERE session_id = %d AND task_key = %s",
            $session_id, $task_key
        ));

        if ( $exists ) {
            unset( $data['session_id'], $data['task_key'] );
            $result = $wpdb->update(
                $this->status_table,
                $data,
                array( 'session_id' => $session_id, 'task_key' => $task_key ),
                array_slice( $formats, 2 ),
                array( '%d', '%s' )
            );
        } else {
            $result = $wpdb->insert( $this->status_table, $data, $formats );
        }

        // Audit log
        if ( $old_status !== $new_status ) {
            $session = $this->get_session( $session_id );
            $this->audit->log( array(
                'session_id'  => $session_id,
                'event_type'  => $session['event_type'] ?? 'satellite',
                'action'      => 'status_change',
                'entity_type' => 'task',
                'entity_id'   => $task_key,
                'field_name'  => 'status',
                'old_value'   => $old_status,
                'new_value'   => $new_status,
            ));

            // Recalculate rollup
            $this->update_rollup_status( $session_id );
        }

        return (bool) $result;
    }

    /* ───────────────────────────────────────────
     * PHASE ENGINE
     * Computes the dashboard view data structure.
     * ─────────────────────────────────────────── */

    /**
     * Build the full dashboard data for a session.
     *
     * @param int    $session_id
     * @param string $event_type
     * @return array {
     *     @type array $overdue    Tasks past deadline and not completed.
     *     @type array $active     Tasks in the current phase, not yet submitted.
     *     @type array $upcoming   Next 3 tasks with future deadlines.
     *     @type array $completed  Tasks that have been approved.
     *     @type array $phases     Full phase structure with enriched task data.
     *     @type string $current_phase_key  Key of the current phase.
     * }
     */
    public function get_dashboard_data( $session_id, $event_type, $session_data = array(), $addon_states = array() ) {
        $phases   = $this->config->get_phases( $event_type );
        $statuses = $this->get_task_statuses( $session_id );
        $today    = WSSP_Date_Override::get_today();

        $overdue   = array();
        $active    = array();
        $upcoming  = array();
        $completed = array();
        $current_phase_key = null;

        // Enrich each task with its stored status and computed state
        $enriched_phases = array();
        foreach ( $phases as $phase ) {
            $phase_key   = $phase['key'];
            $phase_scope = $phase['submit_scope'] ?? 'task';
            $phase_has_active = false;
            $enriched_tasks = array();

            foreach ( $phase['tasks'] ?? array() as $task ) {
                // ─── Condition check: flag tasks that fail visibility rules ───
                // We still include them in enriched_tasks so the HTML renders them
                // (hidden via display:none), allowing JS to toggle visibility
                // without a page reload. But we exclude them from categorization
                // (overdue/active/upcoming/completed) so they don't affect counts.
                $condition       = $task['condition'] ?? null;
                $condition_met   = ! $condition || WSSP_Condition_Evaluator::is_visible( $condition, $session_data );

                $task_key = $task['key'];
                $stored   = $statuses[ $task_key ] ?? null;
                $status   = $stored ? $stored['status'] : 'not_started';
                $deadline = $task['deadline'] ?? $task['date'] ?? null;

                // Inherit submit_scope from phase if not on task
                $submit_scope = $task['submit_scope'] ?? $phase_scope;

                // Compute state
                $is_done      = in_array( $status, array( 'approved', 'complete' ), true );
                $is_submitted = $status === 'submitted_by_sponsor';

                // Add-on tasks: responded (active or declined) = done
                $is_addon_task = (bool) preg_match( '/-addon$/', $task_key );
                if ( $is_addon_task && ! $is_done ) {
                    $addon_slug  = str_replace( '-', '_', preg_replace( '/-addon$/', '', $task_key ) );
                    $addon_state = $addon_states[ $addon_slug ] ?? 'available';
                    if ( in_array( $addon_state, array( 'active', 'declined' ), true ) ) {
                        $is_done = true;
                    }
                }

                // Non-completable tasks are never done/overdue
                $completable = $task['completable'] ?? true;
                if ( ! $completable ) {
                    $is_done = false;
                }

                // Submitted counts as done for overdue/upcoming purposes
                $effectively_done = $is_done || $is_submitted;

                $is_overdue  = $deadline && $deadline < $today && ! $effectively_done && $completable;
                $is_upcoming = $deadline && $deadline >= $today && ! $is_done;

                $enriched = array_merge( $task, array(
                    'status'            => $status,
                    'status_row'        => $stored,
                    'submit_scope'      => $submit_scope,
                    'is_overdue'        => $is_overdue,
                    'is_done'           => $is_done,
                    'is_submitted'      => $is_submitted,
                    'is_upcoming'       => $is_upcoming,
                    'is_hidden'         => ! $condition_met,
                    'deadline'          => $deadline,
                    'phase_key'         => $phase_key,
                    'phase_label'       => $phase['label'],
                ));

                $enriched_tasks[] = $enriched;

                // Only categorize visible tasks — hidden tasks don't affect counts
                if ( ! $condition_met ) {
                    continue;
                }

                // Categorize
                if ( $is_done || $is_submitted ) {
                    $completed[] = $enriched;
                } elseif ( $is_overdue ) {
                    $overdue[] = $enriched;
                    $phase_has_active = true;
                } elseif ( $is_upcoming && ! $is_submitted ) {
                    $phase_has_active = true;
                }
            }

            // ─── Compute phase status from task states ───
            $phase_total       = 0;
            $phase_done        = 0;
            $phase_has_overdue = false;

            foreach ( $enriched_tasks as $et ) {
                if ( $et['type'] === 'info' ) continue;
                if ( ! empty( $et['is_hidden'] ) ) continue;
                if ( ! ( $et['completable'] ?? true ) ) continue;
                $phase_total++;
                if ( $et['is_done'] || $et['is_submitted'] ) $phase_done++;
                if ( $et['is_overdue'] ) $phase_has_overdue = true;
            }

            if ( $phase_total > 0 && $phase_done === $phase_total ) {
                $phase_status = 'completed';
            } elseif ( $phase_has_overdue ) {
                $phase_status = 'overdue';
            } else {
                $phase_status = '';  // No badge — phase is in progress or upcoming
            }

            $enriched_phases[] = array_merge( $phase, array(
                'tasks'      => $enriched_tasks,
                'has_active' => $phase_has_active,
                'status'     => $phase_status,
            ));

            // Current phase = first phase that still has active (non-done) tasks
            if ( $phase_has_active && ! $current_phase_key ) {
                $current_phase_key = $phase_key;
            }
        }

        // Build the upcoming list: next 3 tasks with future deadlines, not done
        $all_future = array();
        foreach ( $enriched_phases as $phase ) {
            foreach ( $phase['tasks'] as $task ) {
                if ( $task['is_upcoming'] && ! $task['is_done'] && ! $task['is_submitted'] ) {
                    $all_future[] = $task;
                }
            }
        }
        usort( $all_future, function ( $a, $b ) {
            return strcmp( $a['deadline'] ?? '9999', $b['deadline'] ?? '9999' );
        });
        $upcoming = array_slice( $all_future, 0, 3 );

        // Build active: tasks in current phase that aren't done
        if ( $current_phase_key ) {
            foreach ( $enriched_phases as $phase ) {
                if ( $phase['key'] === $current_phase_key ) {
                    foreach ( $phase['tasks'] as $task ) {
                        if ( ! $task['is_done'] ) {
                            $active[] = $task;
                        }
                    }
                    break;
                }
            }
        }

        return array(
            'overdue'           => $overdue,
            'active'            => $active,
            'upcoming'          => $upcoming,
            'completed'         => $completed,
            'phases'            => $enriched_phases,
            'current_phase_key' => $current_phase_key,
            'statuses'          => $statuses,
        );
    }

    /* ───────────────────────────────────────────
     * FORM URL BUILDER
     * Builds links to Formidable forms using
     * form_key lookups (no hardcoded IDs).
     * ─────────────────────────────────────────── */

    /**
     * Get the URL for a task's action (form, upload, approval).
     *
     * @param array $task    Enriched task array.
     * @param array $session Session record.
     * @return string|null URL or null if no action.
     */
    public function get_task_url( $task, $session ) {
        $type     = $task['type'] ?? '';
        $form_key = $task['form_key'] ?? '';

        if ( ! $form_key || $type === 'info' ) {
            return null;
        }

        // Look up the Formidable form ID by key
        $form_id = $this->get_frm_form_id_by_key( $form_key );
        if ( ! $form_id ) {
            return null;
        }

        // Get the page that hosts this form
        // For now, we'll use the portal page with form parameters
        // The portal page will need a [wssp_form] shortcode or
        // we build the URL to a page containing the Formidable shortcode
        $base_url = $this->get_portal_page_url();
        if ( ! $base_url ) {
            return null;
        }

        $params = array(
            'wssp_action'  => 'form',
            'form_key'     => $form_key,
            'session_key'  => $session['session_key'],
            'satellite'    => $this->get_session_entry_key( $session ),
        );

        // For upload tasks, add the file type
        if ( $type === 'upload' && ! empty( $task['file_type'] ) ) {
            $params['wssp_action'] = 'upload';
            $params['file-type']   = $task['file_type'];
        }

        // For review_approval tasks
        if ( $type === 'review_approval' ) {
            $params['wssp_action'] = 'review';
        }

        return add_query_arg( $params, $base_url );
    }

    /**
     * Look up a Formidable form ID by its form_key.
     *
     * @param string $form_key
     * @return int|null
     */
    private function get_frm_form_id_by_key( $form_key ) {
        // Use Formidable's API if available
        if ( function_exists( 'FrmForm' ) || class_exists( 'FrmForm' ) ) {
            $form = FrmForm::getOne( $form_key );
            if ( $form ) {
                return (int) $form->id;
            }
        }

        // Fallback: direct DB query
        global $wpdb;
        $table = $wpdb->prefix . 'frm_forms';
        if ( $wpdb->get_var( "SHOW TABLES LIKE '{$table}'" ) === $table ) {
            return $wpdb->get_var( $wpdb->prepare(
                "SELECT id FROM {$table} WHERE form_key = %s AND status = 'published' LIMIT 1",
                $form_key
            ));
        }

        return null;
    }

    /**
     * Get the Formidable entry key for a session (used as the satellite URL param).
     */
    private function get_session_entry_key( $session ) {
        if ( ! empty( $session['frm_entry_id'] ) && class_exists( 'FrmEntry' ) ) {
            $entry = FrmEntry::getOne( $session['frm_entry_id'] );
            if ( $entry ) {
                return $entry->item_key;
            }
        }
        // Fallback: use session code as identifier
        return $session['session_code'];
    }

    /**
     * Get the portal page URL.
     */
    private function get_portal_page_url() {
        $page_id = get_option( 'wssp_dashboard_page_id', 0 );
        if ( $page_id ) {
            return get_permalink( $page_id );
        }
        return null;
    }

    /* ───────────────────────────────────────────
     * SUBMIT HANDLERS (REST)
     * ─────────────────────────────────────────── */

    /**
     * Handle per-task submit.
     */
    public function handle_task_submit( $request ) {
        $session_id = absint( $request->get_param( 'session_id' ) );
        $task_key   = sanitize_text_field( $request->get_param( 'task_key' ) );
        $user_id    = get_current_user_id();

        if ( ! $session_id || ! $task_key ) {
            return new WP_Error( 'missing_params', 'Missing session_id or task_key.', array( 'status' => 400 ) );
        }

        if ( ! $this->access->user_can_edit( $user_id, $session_id ) ) {
            return new WP_Error( 'forbidden', 'You do not have permission to submit this task.', array( 'status' => 403 ) );
        }

        $current = $this->get_task_status( $session_id, $task_key );
        if ( in_array( $current, array( 'approved', 'complete' ), true ) ) {
            return new WP_Error( 'already_submitted', 'This task has already been submitted.', array( 'status' => 400 ) );
        }

        // Determine the correct completion status based on task type.
        $session    = $this->get_session( $session_id );
        $event_type = $session['event_type'] ?? 'satellite';
        $task_def   = $this->config->get_task( $event_type, $task_key );
        $task_type  = $task_def['type'] ?? 'form';

        // Upload tasks are completed by logistics approval, not by checkbox.
        if ( $task_type === 'upload' ) {
            return new WP_Error( 'upload_task', 'Upload tasks are completed when logistics approves the file.', array( 'status' => 400 ) );
        }

        $this->set_task_status( $session_id, $task_key, 'complete' );

        return rest_ensure_response( array(
            'success'  => true,
            'task_key' => $task_key,
            'status'   => 'complete',
        ));
    }

    /**
     * Handle per-phase submit (submits all non-done tasks in the phase).
     */
    public function handle_phase_submit( $request ) {
        $session_id = absint( $request->get_param( 'session_id' ) );
        $phase_key  = sanitize_text_field( $request->get_param( 'phase_key' ) );
        $user_id    = get_current_user_id();

        if ( ! $session_id || ! $phase_key ) {
            return new WP_Error( 'missing_params', 'Missing session_id or phase_key.', array( 'status' => 400 ) );
        }

        if ( ! $this->access->user_can_edit( $user_id, $session_id ) ) {
            return new WP_Error( 'forbidden', 'You do not have permission.', array( 'status' => 403 ) );
        }

        // Get the session to find event_type
        $session = $this->get_session( $session_id );
        if ( ! $session ) {
            return new WP_Error( 'not_found', 'Session not found.', array( 'status' => 404 ) );
        }

        $phases = $this->config->get_phases( $session['event_type'] );
        $submitted = array();

        foreach ( $phases as $phase ) {
            if ( $phase['key'] !== $phase_key ) continue;

            foreach ( $phase['tasks'] ?? array() as $task ) {
                $current = $this->get_task_status( $session_id, $task['key'] );
                // Only submit tasks that are in progress or not started
                if ( in_array( $current, array( 'not_started', 'in_progress', 'revision_requested' ), true ) ) {
                    // Skip info tasks — nothing to submit
                    if ( ( $task['type'] ?? '' ) === 'info' ) continue;
                    // Skip upload tasks — completed by logistics approval
                    if ( ( $task['type'] ?? '' ) === 'upload' ) continue;

                    $this->set_task_status( $session_id, $task['key'], 'complete' );
                    $submitted[] = $task['key'];
                }
            }
            break;
        }

        return rest_ensure_response( array(
            'success'   => true,
            'phase_key' => $phase_key,
            'submitted' => $submitted,
        ));
    }

    /* ───────────────────────────────────────────
     * ROLLUP STATUS
     * ─────────────────────────────────────────── */

    /**
     * Recalculate the session's rollup_status.
     */
    public function update_rollup_status( $session_id ) {
        global $wpdb;

        $session = $this->get_session( $session_id );
        if ( ! $session ) return;

        $all_tasks = $this->config->get_all_tasks( $session['event_type'] );
        $statuses  = $this->get_task_statuses( $session_id );
        $today     = current_time( 'Y-m-d' );

        $total      = 0;
        $done       = 0;
        $has_overdue = false;
        $has_action  = false;

        foreach ( $all_tasks as $task ) {
            if ( ( $task['type'] ?? '' ) === 'info' ) continue; // Info tasks don't count
            $total++;

            $status   = $statuses[ $task['key'] ]['status'] ?? 'not_started';
            $deadline = $task['deadline'] ?? $task['date'] ?? null;

            if ( in_array( $status, array( 'approved', 'complete' ), true ) ) {
                $done++;
            } elseif ( $status === 'revision_requested' ) {
                $has_action = true;
            } elseif ( $deadline && $deadline < $today && ! in_array( $status, array( 'in_progress', 'complete', 'approved' ), true ) ) {
                $has_overdue = true;
            }
        }

        if ( $total > 0 && $done === $total ) {
            $rollup = 'complete';
        } elseif ( $has_overdue || $has_action ) {
            $rollup = 'action_needed';
        } elseif ( $done > 0 ) {
            $rollup = 'in_progress';
        } else {
            // Check for any tasks with in_progress status
            $any_activity = false;
            foreach ( $all_tasks as $task ) {
                if ( ( $task['type'] ?? '' ) === 'info' ) continue;
                $st = $statuses[ $task['key'] ]['status'] ?? 'not_started';
                if ( $st === 'in_progress' ) {
                    $any_activity = true;
                    break;
                }
            }
            $rollup = $any_activity ? 'in_progress' : 'not_started';
        }

        $wpdb->update(
            $wpdb->prefix . 'wssp_sessions',
            array( 'rollup_status' => $rollup ),
            array( 'id' => $session_id ),
            array( '%s' ),
            array( '%d' )
        );
    }

    /* ───────────────────────────────────────────
     * DASHBOARD STATISTICS
     * ─────────────────────────────────────────── */

    /**
     * Compute dashboard statistics from enriched phase data.
     *
     * Uses the enriched task states from get_dashboard_data() which already
     * account for condition visibility. Add-on gated tasks (tasks in other
     * phases with an 'addon' key that hasn't been purchased) are excluded
     * from the total so the count matches what the sponsor actually sees
     * as actionable.
     *
     * @param array $phases          Enriched phases from get_dashboard_data().
     * @param array $purchased_addons List of active add-on slugs.
     * @param array $addon_states    addon_slug => 'active'|'declined'|'available'.
     * @return array {
     *     @type int $completed     Tasks that are done or submitted.
     *     @type int $total         Total actionable tasks.
     *     @type int $due_this_week Tasks due within 7 days.
     *     @type int $overdue       Tasks past deadline and not done.
     * }
     */
    public function compute_dashboard_stats( $phases, $purchased_addons = array(), $addon_states = array() ) {
        $completed     = 0;
        $total         = 0;
        $due_this_week = 0;
        $overdue       = 0;

        $today    = WSSP_Date_Override::get_today();
        $week_end = date( 'Y-m-d', strtotime( '+7 days', strtotime( $today ) ) );

        foreach ( $phases as $phase ) {
            foreach ( $phase['tasks'] as $task ) {
                if ( $task['type'] === 'info' ) continue;
                if ( ! empty( $task['is_hidden'] ) ) continue;
                if ( ! ( $task['completable'] ?? true ) ) continue;

                // Skip add-on gated tasks that aren't purchased
                $addon = $task['addon'] ?? null;
                if ( $addon && ! in_array( $addon, $purchased_addons, true ) ) {
                    continue;
                }

                $total++;

                // Add-on selection tasks (slug ends in -addon) are "done" when responded to
                $is_addon = (bool) preg_match( '/-addon$/', $task['key'] );
                $addon_slug = $is_addon ? str_replace( '-', '_', preg_replace( '/-addon$/', '', $task['key'] ) ) : '';
                $addon_responded = $is_addon && in_array( $addon_states[ $addon_slug ] ?? '', array( 'active', 'declined' ), true );

                if ( $task['is_done'] || $task['is_submitted'] || $addon_responded ) {
                    $completed++;
                    continue;
                }

                $deadline = $task['deadline'] ?? null;
                if ( ! $deadline ) continue;

                if ( $deadline < $today ) {
                    $overdue++;
                } elseif ( $deadline <= $week_end ) {
                    $due_this_week++;
                }
            }
        }

        return array(
            'completed'     => $completed,
            'total'         => $total,
            'due_this_week' => $due_this_week,
            'overdue'       => $overdue,
        );
    }

    /* ───────────────────────────────────────────
     * HELPERS
     * ─────────────────────────────────────────── */

    private function get_session( $session_id ) {
        global $wpdb;
        return $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}wssp_sessions WHERE id = %d",
            $session_id
        ), ARRAY_A );
    }
}