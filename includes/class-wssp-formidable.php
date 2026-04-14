<?php
/**
 * WSSP_Formidable Class
 *
 * Centralized handler for all Formidable Forms interactions in the
 * Satellite Symposium sponsor portal.
 *
 * This class supports the consolidated "Satellite Session Data" form
 * while preserving the multi-entry forms (Meeting Planner & Material Upload).
 *
 * Part of the sophisticated task management portal for pharmaceutical sponsors
 * managing satellite symposia at scientific conferences — with intelligent
 * date-based task surfacing and comprehensive progress tracking.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit; // Exit if accessed directly
}

class WSSP_Formidable {

    /**
     * Form Keys — Single Source of Truth
     *
     * Only keeping forms that are still actively used.
     */
    const FORM_SATELLITE_SESSION_DATA   = 'wssp-sat-session-data';     // Core consolidated form (replaced multiple older forms)
    const FORM_SATELLITE_MEETING_PLANNER = 'wssp-sat-meeting-planner'; // Supports multiple planners
    const FORM_SATELLITE_MATERIAL_UPLOAD = 'wssp-sat-material-upload'; // Supports multiple file uploads

    /**
     * Common Field Keys used across forms
     */
    const FIELD_SESSION_KEY = 'wssp_session_key';

    /**
     * Form ID cache (populated on first use for performance)
     *
     * @var array
     */
    private $form_ids = array();

    /**
     * Audit logger instance.
     *
     * @var WSSP_Audit_Log|null
     */
    private $audit;

    /**
     * Portal configuration.
     *
     * @var WSSP_Config|null
     */
    private $config;

    /**
     * Dashboard instance for task status writes.
     *
     * @var WSSP_Dashboard|null
     */
    private $dashboard;

    /**
     * Pre-update entry snapshot for diffing.
     *
     * Captured by the frm_pre_update_entry hook so the
     * frm_after_update_entry callback can compare old → new.
     *
     * @var array  Keyed by entry_id => [ field_key => value ].
     */
    private $pre_update_snapshots = array();

    /**
     * Field keys that are internal plumbing and should never appear
     * in audit log diffs.  Add any hidden/system fields here.
     */
    private static $audit_skip_fields = array(
        'wssp_session_key',
    );

    /**
     * Constructor
     *
     * @param WSSP_Audit_Log|null  $audit     Optional audit logger.
     * @param WSSP_Config|null     $config    Portal config (needed for field→task mapping).
     * @param WSSP_Dashboard|null  $dashboard Dashboard (needed for task status writes).
     */
    public function __construct( WSSP_Audit_Log $audit = null, WSSP_Config $config = null, WSSP_Dashboard $dashboard = null ) {
        $this->audit     = $audit;
        $this->config    = $config;
        $this->dashboard = $dashboard;
        $this->init_hooks();
    }

    /**
     * Register hooks for Formidable integration
     */
    private function init_hooks() {
        // Automatically link the core Satellite Session Data form back to the sessions table
        add_action( 'frm_after_create_entry', array( $this, 'link_session_data_entry' ), 30, 2 );
        add_action( 'frm_after_update_entry', array( $this, 'link_session_data_entry' ), 30, 2 );

        // Auto-complete upload tasks when logistics approves a material file
        add_action( 'frm_after_update_entry', array( $this, 'maybe_complete_upload_task_on_approval' ), 40, 2 );

        // Bypass required field validation in wp-admin so logistics can
        // save partial entries without filling every sponsor-facing field.
        add_filter( 'frm_validate_entry', array( $this, 'bypass_required_in_admin' ), 10, 2 );

        // ─── Audit logging for form submissions ───
        // Snapshot old values BEFORE update so we can diff afterward.
        // frm_pre_update_entry is a FILTER (must return $values), not an action.
        // Priority 5 = well before any other callbacks on this hook.
        add_filter( 'frm_pre_update_entry', array( $this, 'snapshot_before_update' ), 5, 2 );

        // Log the actual change AFTER create/update.
        // Priority 35 = just after link_session_data_entry (30) so
        // the session linkage is established and we can resolve session_id.
        add_action( 'frm_after_create_entry', array( $this, 'audit_log_form_create' ), 35, 2 );
        add_action( 'frm_after_update_entry', array( $this, 'audit_log_form_update' ), 35, 2 );
    }

    /**
     * Clear validation errors for WSSP forms when editing in wp-admin.
     *
     * Sponsors fill forms on the front-end where validation is enforced.
     * When an admin edits an entry in the back-end, required fields
     * shouldn't block the save — the admin may only be updating one
     * field and doesn't need to fill the entire form.
     *
     * @param array $errors Validation errors.
     * @param array $values Submitted form values (includes 'form_id').
     * @return array
     */
    public function bypass_required_in_admin( $errors, $values ) {
        if ( ! is_admin() ) {
            return $errors;
        }

        // Only bypass for WSSP forms
        $wssp_form_keys = array(
            self::FORM_SATELLITE_SESSION_DATA,
            self::FORM_SATELLITE_MEETING_PLANNER,
            self::FORM_SATELLITE_MATERIAL_UPLOAD,
        );

        $submitted_form_id = (int) ( $values['form_id'] ?? 0 );

        foreach ( $wssp_form_keys as $form_key ) {
            $wssp_form_id = $this->get_form_id( $form_key );
            if ( $wssp_form_id && $submitted_form_id === $wssp_form_id ) {
                return array(); // Clear all validation errors
            }
        }

        return $errors;
    }

    /**
     * Get Form ID by form key (with caching)
     *
     * @param string $form_key
     * @return int|null
     */
    public function get_form_id( $form_key ) {
        if ( isset( $this->form_ids[ $form_key ] ) ) {
            return $this->form_ids[ $form_key ];
        }

        if ( ! class_exists( 'FrmForm' ) ) {
            return null;
        }

        $form = FrmForm::getOne( array( 'form_key' => $form_key ) );
        $form_id = $form ? (int) $form->id : null;

        $this->form_ids[ $form_key ] = $form_id;

        return $form_id;
    }

    /**
     * Link the consolidated "Satellite Session Data" Formidable entry 
     * back to the wp5g_wssp_sessions table using the hidden session_key field.
     *
     * This is the critical link that allows session-overview.php and all
     * task/progress tracking to reliably merge custom table + Formidable data.
     *
     * @param int $entry_id
     * @param int $form_id
     */
    public function link_session_data_entry( $entry_id, $form_id ) {
        $target_form_id = $this->get_form_id( self::FORM_SATELLITE_SESSION_DATA );

        if ( empty( $target_form_id ) || (int) $form_id !== $target_form_id ) {
            return; // Not the core session data form
        }

        if ( ! class_exists( 'FrmEntryMeta' ) ) {
            return;
        }

        global $wpdb;

        // Retrieve the session_key from the hidden field in this Formidable entry
        $session_key = FrmEntryMeta::get_entry_meta_by_field( $entry_id, self::FIELD_SESSION_KEY, true );

        if ( empty( $session_key ) ) {
            return; // Safety: entry does not belong to a satellite session
        }

        // Write the frm_entry_id back into the sessions table
        $updated = $wpdb->update(
            $wpdb->prefix . 'wssp_sessions',
            array( 'frm_entry_id' => (int) $entry_id ),
            array( 'session_key'  => sanitize_text_field( $session_key ) ),
            array( '%d' ),
            array( '%s' )
        );

        if ( $updated !== false ) {
            // Optional debug logging (comment out in production if desired)
            // error_log( sprintf( 'WSSP: Linked Formidable entry #%d → session_key %s', $entry_id, $session_key ) );
        } else {
            error_log( sprintf( 'WSSP: FAILED linking Formidable entry #%d (session_key: %s)', $entry_id, $session_key ) );
        }
    }

    /* ───────────────────────────────────────────
     * AUDIT LOGGING — Form Submissions
     * ─────────────────────────────────────────── */

    /**
     * Snapshot the current field values BEFORE Formidable overwrites them.
     *
     * Fires on frm_pre_update_entry (priority 5) — well before any
     * after-update hooks.  The snapshot is consumed once by
     * audit_log_form_update() and then discarded.
     *
     * Only snapshots forms we care about (session data form).
     *
     * IMPORTANT: frm_pre_update_entry is a FILTER, not an action.
     * Formidable passes ( $values, $entry_id ) and expects $values
     * back.  Failing to return $values breaks all downstream
     * callbacks on this hook.
     *
     * @param array $values   The entry values Formidable is about to save.
     * @param int   $entry_id Formidable entry ID.
     * @return array $values  Passed through unchanged.
     */
    public function snapshot_before_update( $values, $entry_id ) {
        if ( ! $this->audit ) {
            return $values;
        }

        $form_id        = (int) ( $values['form_id'] ?? 0 );
        $target_form_id = $this->get_form_id( self::FORM_SATELLITE_SESSION_DATA );

        if ( empty( $target_form_id ) || $form_id !== $target_form_id ) {
            return $values;
        }

        // Use get_entry_data() to read current values before the save.
        $this->pre_update_snapshots[ $entry_id ] = $this->get_entry_data( $entry_id );

        return $values;
    }

    /**
     * Audit-log the initial creation of a Session Data entry.
     *
     * Fires on frm_after_create_entry (priority 35).  No old values
     * exist, so we log a single "form_submitted" event with the
     * form key and field count in meta.
     *
     * @param int $entry_id Formidable entry ID.
     * @param int $form_id  Formidable form ID.
     */
    public function audit_log_form_create( $entry_id, $form_id ) {
        if ( ! $this->audit ) {
            return;
        }

        $target_form_id = $this->get_form_id( self::FORM_SATELLITE_SESSION_DATA );
        if ( empty( $target_form_id ) || (int) $form_id !== $target_form_id ) {
            return;
        }

        $session = $this->resolve_session_for_entry( $entry_id );
        if ( ! $session ) {
            return;
        }

        // Read the just-created entry to capture which fields were filled.
        $new_data      = $this->get_entry_data( $entry_id );
        $filled_fields = array_keys( array_filter( $new_data, function ( $v ) {
            return $v !== '' && $v !== null;
        } ) );

        // Remove system/hidden fields from the summary.
        $filled_fields = array_values( array_diff( $filled_fields, self::$audit_skip_fields ) );

        $this->audit->log( array(
            'session_id'  => (int) $session['id'],
            'event_type'  => $session['event_type'] ?? 'satellite',
            'action'      => 'form_submitted',
            'entity_type' => 'form',
            'entity_id'   => self::FORM_SATELLITE_SESSION_DATA,
            'meta'        => array(
                'form_key'      => self::FORM_SATELLITE_SESSION_DATA,
                'entry_id'      => $entry_id,
                'fields_filled' => $filled_fields,
            ),
        ) );

        // ─── Mark affected tasks as in_progress ───
        if ( ! empty( $filled_fields ) && $this->config && $this->dashboard ) {
            $this->mark_tasks_in_progress(
                (int) $session['id'],
                $session['event_type'] ?? 'satellite',
                $filled_fields,
                $new_data
            );
        }
    }

    /**
     * Audit-log each changed field when a Session Data entry is updated.
     *
     * Fires on frm_after_update_entry (priority 35).  Compares the
     * pre-update snapshot captured by snapshot_before_update() against
     * the freshly-saved entry and logs one audit row per changed field.
     *
     * @param int $entry_id Formidable entry ID.
     * @param int $form_id  Formidable form ID.
     */
    public function audit_log_form_update( $entry_id, $form_id ) {
        if ( ! $this->audit ) {
            return;
        }

        $target_form_id = $this->get_form_id( self::FORM_SATELLITE_SESSION_DATA );
        if ( empty( $target_form_id ) || (int) $form_id !== $target_form_id ) {
            return;
        }

        $session = $this->resolve_session_for_entry( $entry_id );
        if ( ! $session ) {
            return;
        }

        // Retrieve and consume the pre-update snapshot.
        $old_data = $this->pre_update_snapshots[ $entry_id ] ?? array();
        unset( $this->pre_update_snapshots[ $entry_id ] );

        // Read the freshly-saved values.
        // Clear Formidable's entry cache first so we see the new data.
        wp_cache_delete( $entry_id, 'frm_entry' );
        $new_data = $this->get_entry_data( $entry_id );

        // Build the union of all field keys across old + new.
        $all_keys = array_unique( array_merge( array_keys( $old_data ), array_keys( $new_data ) ) );

        $session_id = (int) $session['id'];
        $event_type = $session['event_type'] ?? 'satellite';
        $changes    = array();

        foreach ( $all_keys as $field_key ) {
            // Skip internal plumbing fields.
            if ( in_array( $field_key, self::$audit_skip_fields, true ) ) {
                continue;
            }

            $old = $old_data[ $field_key ] ?? '';
            $new = $new_data[ $field_key ] ?? '';

            // Normalize for comparison: serialize arrays, cast scalars to string.
            $old_cmp = is_array( $old ) ? wp_json_encode( $old ) : (string) $old;
            $new_cmp = is_array( $new ) ? wp_json_encode( $new ) : (string) $new;

            if ( $old_cmp === $new_cmp ) {
                continue;
            }

            $changes[] = $field_key;

            $this->audit->log( array(
                'session_id'  => $session_id,
                'event_type'  => $event_type,
                'action'      => 'field_edit',
                'entity_type' => 'form',
                'entity_id'   => self::FORM_SATELLITE_SESSION_DATA,
                'field_name'  => $field_key,
                'old_value'   => $old_cmp,
                'new_value'   => $new_cmp,
                'meta'        => array( 'entry_id' => $entry_id ),
            ) );
        }

        // If nothing actually changed (e.g. sponsor re-saved without edits),
        // don't log anything — keep the audit log free of noise.

        // ─── Mark affected tasks as in_progress ───
        // For each changed field, find which task(s) it belongs to and
        // transition them from not_started → in_progress.  Tasks already
        // in a later state (complete, approved, etc.) are left alone.
        if ( ! empty( $changes ) && $this->config && $this->dashboard ) {
            $this->mark_tasks_in_progress( $session_id, $event_type, $changes, $new_data );
        }
    }

    /**
     * Resolve the WSSP session record for a Formidable entry.
     *
     * Looks up the session_key hidden field in the entry, then finds
     * the matching session row. Returns null if either lookup fails.
     *
     * @param int $entry_id Formidable entry ID.
     * @return array|null   Session row (assoc) or null.
     */
    private function resolve_session_for_entry( $entry_id ) {
        if ( ! class_exists( 'FrmEntryMeta' ) ) {
            return null;
        }

        $session_key = FrmEntryMeta::get_entry_meta_by_field( $entry_id, self::FIELD_SESSION_KEY, true );
        if ( empty( $session_key ) ) {
            return null;
        }

        global $wpdb;
        return $wpdb->get_row( $wpdb->prepare(
            "SELECT id, event_type, session_key FROM {$wpdb->prefix}wssp_sessions WHERE session_key = %s",
            $session_key
        ), ARRAY_A );
    }

    /**
     * Mark tasks as in_progress when their form fields receive data.
     *
     * For each changed field key, finds which task(s) include that field
     * in their field_keys list, then transitions the task from not_started
     * to in_progress.  Tasks already in a later state are left alone.
     *
     * Respects the condition evaluator — if a task has a visibility
     * condition that isn't met (e.g. CE task when Non-CE is selected),
     * it won't be marked in_progress even if shared fields have data.
     *
     * @param int    $session_id          WSSP session ID.
     * @param string $event_type          Event type slug.
     * @param array  $changed_field_keys  Field keys that were changed.
     * @param array  $session_data        Current Formidable entry data (for condition evaluation).
     */
    private function mark_tasks_in_progress( $session_id, $event_type, $changed_field_keys, $session_data = array() ) {
        $all_tasks = $this->config->get_all_tasks( $event_type );

        // Build a set of task keys affected by the changed fields.
        $affected_tasks = array();
        foreach ( $all_tasks as $task ) {
            $task_field_keys = $task['field_keys'] ?? array();
            if ( empty( $task_field_keys ) ) {
                continue;
            }
            // Skip non-form tasks (uploads, info, etc.)
            $type = $task['type'] ?? 'form';
            if ( ! in_array( $type, array( 'form', 'review_approval' ), true ) ) {
                continue;
            }
            // Skip tasks whose visibility condition is not met.
            $condition = $task['condition'] ?? null;
            if ( $condition && ! WSSP_Condition_Evaluator::is_visible( $condition, $session_data ) ) {
                continue;
            }
            // Check if any of this task's field_keys were changed.
            if ( array_intersect( $changed_field_keys, $task_field_keys ) ) {
                $affected_tasks[] = $task['key'];
            }
        }

        if ( empty( $affected_tasks ) ) {
            return;
        }

        // For each affected task, transition not_started/acknowledged → in_progress.
        foreach ( $affected_tasks as $task_key ) {
            $current = $this->dashboard->get_task_status( $session_id, $task_key );
            if ( in_array( $current, array( 'not_started', 'acknowledged' ), true ) ) {
                $this->dashboard->set_task_status( $session_id, $task_key, 'in_progress' );
            }
        }
    }

    /**
     * Get all field values from a Formidable entry, keyed by field_key.
     *
     * @param int $entry_id
     * @return array
     */
    public function get_entry_data( $entry_id ) {
        $data = array();

        if ( ! class_exists( 'FrmEntry' ) || empty( $entry_id ) ) {
            return $data;
        }

        $entry = FrmEntry::getOne( $entry_id, true ); // true = include metas

        if ( ! $entry || empty( $entry->metas ) ) {
            return $data;
        }

        // Bulk-load all fields for this form in a single query
        global $wpdb;
        $field_rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT id, field_key FROM {$wpdb->prefix}frm_fields WHERE form_id = %d",
            $entry->form_id
        ), ARRAY_A );

        $field_id_to_key = array();
        foreach ( $field_rows as $row ) {
            $field_id_to_key[ (int) $row['id'] ] = $row['field_key'];
        }

        // Map meta values using the bulk-loaded field keys
        foreach ( $entry->metas as $field_id => $value ) {
            $field_key = $field_id_to_key[ (int) $field_id ] ?? null;
            if ( $field_key ) {
                $data[ $field_key ] = $value;
            }
        }

        return $data;
    }

    /**
     * Find Formidable entry data by session_key (useful for fallback / legacy cases)
     *
     * @param string $session_key
     * @return array|null
     */
    public function get_entry_by_session_key( $session_key ) {
        if ( empty( $session_key ) || ! class_exists( 'FrmField' ) ) {
            return null;
        }

        $field_id = FrmField::get_id_by_key( self::FIELD_SESSION_KEY );
        if ( ! $field_id ) {
            return null;
        }

        $entry_ids = FrmEntryMeta::getEntryIds( array(
            'field_id'   => $field_id,
            'meta_value' => $session_key,
        ) );

        if ( empty( $entry_ids ) ) {
            return null;
        }

        $entry_id = (int) reset( $entry_ids );
        return $this->get_entry_data( $entry_id );
    }
    
    /**
     * Get complete merged session data: sessions table + session_meta + Formidable
     * Formidable fields take priority over meta, which take priority over base session.
     */
    public function get_full_session_data( $session_key ) {
        global $wpdb;

        if ( empty( $session_key ) ) {
            return array();
        }

        // 1. Base session record
        $session = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}wssp_sessions WHERE session_key = %s",
            $session_key
        ), ARRAY_A );

        if ( ! $session ) {
            return array();
        }

        $data = $session;   // start with base

        // 2. Add session_meta (Smartsheet fields like topic, sponsor, sponsor_name, etc.)
        $meta_rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT meta_key, meta_value FROM {$wpdb->prefix}wssp_session_meta 
             WHERE session_id = %d",
            $session['id']
        ), ARRAY_A );

        foreach ( $meta_rows as $row ) {
            $data[ $row['meta_key'] ] = $row['meta_value'];
        }

        // 3. Add Formidable fields (highest priority — sponsor overrides)
        if ( ! empty( $session['frm_entry_id'] ) ) {
            $form_data = $this->get_entry_data( $session['frm_entry_id'] );
            $data = array_merge( $data, $form_data );
        } else {
            // fallback lookup by session_key
            $form_data = $this->get_entry_by_session_key( $session_key );
            if ( $form_data ) {
                $data = array_merge( $data, $form_data );
            }
        }

        return $data;
    }

    /**
     * Sync upload task status with material file approval state.
     *
     * Fires on frm_after_update_entry for the material upload form.
     * - When wssp_admin_material_status changes to "Approved" on the latest
     *   version, marks the corresponding upload task as complete.
     * - When status changes away from "Approved" (e.g. back to "Changes
     *   Required"), resets the task to not_started so the sponsor can act.
     *
     * Only affects the latest version — changing status on older versions
     * has no effect on the task.
     *
     * @param int $entry_id Formidable entry ID.
     * @param int $form_id  Formidable form ID.
     */
    public function maybe_complete_upload_task_on_approval( $entry_id, $form_id ) {
        $target_form_id = $this->get_form_id( self::FORM_SATELLITE_MATERIAL_UPLOAD );
        if ( ! $target_form_id || (int) $form_id !== $target_form_id ) {
            return;
        }

        // Get the status value
        $status = (string) FrmEntryMeta::get_entry_meta_by_field( $entry_id, 'wssp_admin_material_status', true );
        $is_approved = strpos( $status, 'Approved' ) !== false;

        // Get session_key and file_type from this entry
        $session_key = FrmEntryMeta::get_entry_meta_by_field( $entry_id, 'wssp_material_session_key', true );
        $file_type   = FrmEntryMeta::get_entry_meta_by_field( $entry_id, 'wssp_material_file_type', true );

        if ( empty( $session_key ) || empty( $file_type ) ) {
            return;
        }

        // Verify this is the latest version for this file_type
        // (don't auto-complete if an older version was approved)
        global $wpdb;
        $field_rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT id, field_key FROM {$wpdb->prefix}frm_fields WHERE form_id = %d",
            $target_form_id
        ), ARRAY_A );

        $field_map = array();
        foreach ( $field_rows as $row ) {
            $field_map[ $row['field_key'] ] = (int) $row['id'];
        }

        $sk_field_id = $field_map['wssp_material_session_key'] ?? null;
        $ft_field_id = $field_map['wssp_material_file_type'] ?? null;
        if ( ! $sk_field_id || ! $ft_field_id ) return;

        $latest_id = $wpdb->get_var( $wpdb->prepare(
            "SELECT e.id
             FROM {$wpdb->prefix}frm_items e
             INNER JOIN {$wpdb->prefix}frm_item_metas sk ON (e.id = sk.item_id AND sk.field_id = %d)
             INNER JOIN {$wpdb->prefix}frm_item_metas ft ON (e.id = ft.item_id AND ft.field_id = %d)
             WHERE e.form_id = %d
               AND sk.meta_value = %s
               AND ft.meta_value = %s
               AND e.is_draft = 0
             ORDER BY e.created_at DESC
             LIMIT 1",
            $sk_field_id,
            $ft_field_id,
            $target_form_id,
            $session_key,
            $file_type
        ));

        if ( (int) $latest_id !== (int) $entry_id ) {
            return; // This is not the latest version — don't auto-complete
        }

        // Look up the session ID from session_key
        $session = $wpdb->get_row( $wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}wssp_sessions WHERE session_key = %s",
            $session_key
        ), ARRAY_A );

        if ( ! $session ) return;
        $session_id = (int) $session['id'];

        // Map file_type to task_key using portal config
        $config      = new WSSP_Config();
        $event_type  = 'satellite';
        $event_config = $config->get_event_type( $event_type );
        $task_behavior = $event_config['task_behavior'] ?? array();

        $task_key = null;
        foreach ( $task_behavior as $tk => $overrides ) {
            if ( ( $overrides['type'] ?? '' ) === 'upload' && ( $overrides['file_type'] ?? '' ) === $file_type ) {
                $task_key = $tk;
                break;
            }
        }

        if ( ! $task_key ) return;

        // Set the task status based on approval state
        $table       = $wpdb->prefix . 'wssp_task_status';
        $new_status  = $is_approved ? 'approved' : 'revision_requested';
        $old_status  = $wpdb->get_var( $wpdb->prepare(
            "SELECT status FROM {$table} WHERE session_id = %d AND task_key = %s",
            $session_id, $task_key
        ));
        $existing    = ( $old_status !== null );

        $now = current_time( 'mysql' );

        if ( $existing ) {
            $update_data = array( 'status' => $new_status );
            $update_fmt  = array( '%s' );

            if ( $is_approved ) {
                $update_data['submitted_at'] = $now;
                $update_data['submitted_by'] = get_current_user_id();
                $update_fmt[] = '%s';
                $update_fmt[] = '%d';
            }

            $wpdb->update( $table, $update_data, array( 'session_id' => $session_id, 'task_key' => $task_key ), $update_fmt, array( '%d', '%s' ) );
        } elseif ( $is_approved ) {
            // Only insert a new row for approvals — no need to insert a not_started row
            $wpdb->insert(
                $table,
                array(
                    'session_id'   => $session_id,
                    'task_key'     => $task_key,
                    'status'       => 'approved',
                    'submitted_at' => $now,
                    'submitted_by' => get_current_user_id(),
                ),
                array( '%d', '%s', '%s', '%s', '%d' )
            );
        }

        // ─── Audit log: record the auto-status-change ───
        if ( $this->audit && $old_status !== $new_status ) {
            $this->audit->log( array(
                'session_id'  => $session_id,
                'event_type'  => $event_type,
                'action'      => 'status_change',
                'source'      => 'formidable',
                'entity_type' => 'task',
                'entity_id'   => $task_key,
                'field_name'  => 'status',
                'old_value'   => $old_status ?? '',
                'new_value'   => $new_status,
                'meta'        => array(
                    'trigger'   => 'material_approval_sync',
                    'entry_id'  => $entry_id,
                    'file_type' => $file_type,
                ),
            ) );
        }
    }

    /**
     * Get the latest material upload entry per file_type for a session.
     *
     * Queries the material upload form for all non-draft entries matching
     * the session key, bulk-loads their meta, and returns the latest entry
     * per file_type with status, version, and file info.
     *
     * Used by WSSP_Public and WSSP_REST to populate $file_summary for
     * upload task cards.
     *
     * @param string $session_key Session key.
     * @return array file_type => ['id', 'version', 'status', 'original_name', 'file_url'] or empty array.
     */
    public function get_material_file_summary( $session_key ) {
        global $wpdb;

        $form = FrmForm::getOne( self::FORM_SATELLITE_MATERIAL_UPLOAD );
        if ( ! $form ) return array();

        $form_id = (int) $form->id;

        // Build field_key → field_id map
        $field_rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT id, field_key FROM {$wpdb->prefix}frm_fields WHERE form_id = %d",
            $form_id
        ), ARRAY_A );

        $field_map = array();
        foreach ( $field_rows as $row ) {
            $field_map[ $row['field_key'] ] = (int) $row['id'];
        }

        $sk_field_id = $field_map['wssp_material_session_key'] ?? null;
        if ( ! $sk_field_id ) return array();

        // Get all non-draft entries for this session, newest first
        $entry_ids = $wpdb->get_col( $wpdb->prepare(
            "SELECT e.id
             FROM {$wpdb->prefix}frm_items e
             INNER JOIN {$wpdb->prefix}frm_item_metas m ON (e.id = m.item_id)
             WHERE e.form_id = %d
               AND m.field_id = %d
               AND m.meta_value = %s
               AND e.is_draft = 0
             ORDER BY e.created_at DESC",
            $form_id,
            $sk_field_id,
            $session_key
        ));

        if ( empty( $entry_ids ) ) return array();

        // Bulk load meta for all entries
        $id_to_key    = array_flip( $field_map );
        $placeholders = implode( ',', array_fill( 0, count( $entry_ids ), '%d' ) );
        $meta_rows    = $wpdb->get_results( $wpdb->prepare(
            "SELECT item_id, field_id, meta_value
             FROM {$wpdb->prefix}frm_item_metas
             WHERE item_id IN ({$placeholders})",
            ...$entry_ids
        ), ARRAY_A );

        // Build entry data keyed by entry ID
        $entries = array();
        foreach ( $entry_ids as $eid ) {
            $entries[ $eid ] = array( 'id' => $eid );
        }
        foreach ( $meta_rows as $row ) {
            $field_key = $id_to_key[ (int) $row['field_id'] ] ?? null;
            if ( $field_key && isset( $entries[ $row['item_id'] ] ) ) {
                $entries[ $row['item_id'] ][ $field_key ] = $row['meta_value'];
            }
        }

        // Group by file_type, keep only the latest (first) per type
        $summary = array();
        foreach ( $entries as $entry ) {
            $ft = $entry['wssp_material_file_type'] ?? '';
            if ( $ft && ! isset( $summary[ $ft ] ) ) {
                $summary[ $ft ] = array(
                    'id'            => (int) $entry['id'],
                    'version'       => (int) ( $entry['wssp_material_version'] ?? 1 ),
                    'status'        => $entry['wssp_admin_material_status'] ?? 'Pending, Not Reviewed',
                    'original_name' => $entry['wssp_material_original_name'] ?? '',
                    'file_url'      => $entry['wssp_material_file'] ?? '',
                );
            }
        }

        return $summary;
    }
}