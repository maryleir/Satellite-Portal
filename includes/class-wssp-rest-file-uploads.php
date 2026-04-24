<?php
/**
 * File Upload CRUD — REST endpoints for managing material upload entries.
 *
 * Each file upload creates a new Formidable entry in the
 * 'wssp-sat-material-upload' form, linked to a session via the
 * 'wssp_material_session_key' hidden field. Version numbers are
 * auto-incremented per session + file_type. Files are renamed using
 * the session naming convention before Formidable saves them.
 *
 * Comments are stored in a separate 'wssp-sat-material-comment' form,
 * linked to specific material entries by entry ID.
 *
 * Endpoints:
 *   GET  /file-uploads                          — List files + dropzone HTML
 *   POST /file-uploads                          — Upload new file version
 *   POST /file-uploads/(?P<id>\d+)/status       — Update status (logistics)
 *   GET  /file-uploads/(?P<id>\d+)/comments     — List comments for entry
 *   POST /file-uploads/(?P<id>\d+)/comments     — Add comment to entry
 *
 * @package WSSP
 */

defined( 'ABSPATH' ) || exit;

class WSSP_REST_File_Uploads {

    /** @var string REST namespace. */
    private $namespace = 'wssp/v1';

    /** @var WSSP_Session_Access */
    private $access;

    /** @var WSSP_Config */
    private $config;

    /** @var WSSP_Audit_Log */
    private $audit;

    /** @var WSSP_Notifier|null Optional; emails on upload/review/comment events. */
    private $notifier;

    /** @var string Formidable form key for material uploads. */
    private $form_key = 'wssp-sat-material-upload';

    /** @var string Formidable form key for comments. */
    private $comment_form_key = 'wssp-sat-material-comment';

    /** @var string Field key that links upload entries to sessions. */
    private $session_key_field = 'wssp_material_session_key';

    /** Field keys for the material upload form. */
    private $fields = array(
        'wssp_material_session_key',
        'wssp_material_file_type',
        'wssp_material_file',
        'wssp_material_version',
        'wssp_material_user_id',
        'wssp_material_original_name',
        'wssp_material_change_requested',
        'wssp_admin_material_status',
        'wssp_admin_material_date_approved',
    );

    /** Field keys for the comment form. */
    private $comment_fields = array(
        'wssp_comment_material_id',
        'wssp_comment_session_key',
        'wssp_comment_user_id',
        'wssp_comment_text',
        'wssp_comment_type',
    );

    public function __construct( WSSP_Session_Access $access, WSSP_Config $config, WSSP_Audit_Log $audit, ?WSSP_Notifier $notifier = null ) {
        $this->access   = $access;
        $this->config   = $config;
        $this->audit    = $audit;
        $this->notifier = $notifier;

        add_action( 'rest_api_init', array( $this, 'register_routes' ) );
    }

    /* ═══════════════════════════════════════════
     * ROUTE REGISTRATION
     * ═══════════════════════════════════════════ */

    public function register_routes() {

        // GET /file-uploads — List files + panel HTML
        register_rest_route( $this->namespace, '/file-uploads', array(
            'methods'             => 'GET',
            'callback'            => array( $this, 'get_files' ),
            'permission_callback' => array( $this, 'check_logged_in' ),
            'args'                => array(
                'session_id' => array( 'required' => true, 'sanitize_callback' => 'absint' ),
                'file_type'  => array( 'required' => true, 'sanitize_callback' => 'sanitize_text_field' ),
            ),
        ));

        // POST /file-uploads — Upload new file version
        register_rest_route( $this->namespace, '/file-uploads', array(
            'methods'             => 'POST',
            'callback'            => array( $this, 'upload_file' ),
            'permission_callback' => array( $this, 'check_logged_in' ),
        ));

        // POST /file-uploads/{id}/status — Update status (logistics only)
        register_rest_route( $this->namespace, '/file-uploads/(?P<id>\d+)/status', array(
            'methods'             => 'POST',
            'callback'            => array( $this, 'update_status' ),
            'permission_callback' => array( $this, 'check_logged_in' ),
        ));

        // GET /file-uploads/{id}/comments — List comments
        register_rest_route( $this->namespace, '/file-uploads/(?P<id>\d+)/comments', array(
            'methods'             => 'GET',
            'callback'            => array( $this, 'get_comments' ),
            'permission_callback' => array( $this, 'check_logged_in' ),
        ));

        // POST /file-uploads/{id}/comments — Add comment
        register_rest_route( $this->namespace, '/file-uploads/(?P<id>\d+)/comments', array(
            'methods'             => 'POST',
            'callback'            => array( $this, 'add_comment' ),
            'permission_callback' => array( $this, 'check_logged_in' ),
        ));

        // POST /file-uploads/vbi — Update VBI selection (saves to session-data form)
        register_rest_route( $this->namespace, '/file-uploads/vbi', array(
            'methods'             => 'POST',
            'callback'            => array( $this, 'update_vbi' ),
            'permission_callback' => array( $this, 'check_logged_in' ),
        ));
    }

    public function check_logged_in() {
        return is_user_logged_in();
    }

    /* ═══════════════════════════════════════════
     * GET — List files + panel HTML
     * ═══════════════════════════════════════════ */

    public function get_files( $request ) {
        $session_id = $request->get_param( 'session_id' );
        $file_type  = $request->get_param( 'file_type' );
        $user_id    = get_current_user_id();

        if ( ! $this->access->user_can_access( $user_id, $session_id ) ) {
            return new WP_REST_Response( array( 'success' => false, 'message' => 'Access denied.' ), 403 );
        }

        $session = $this->get_session( $session_id );
        if ( ! $session ) {
            return new WP_REST_Response( array( 'success' => false, 'message' => 'Session not found.' ), 404 );
        }

        $can_edit   = $this->can_edit( $user_id, $session_id );
        $is_admin   = $this->access->user_can_review( $user_id );
        $entries    = $this->query_entries( $session['session_key'], $file_type );
        $event_type = $session['event_type'] ?? 'satellite';

        // Load comments for all entries in one pass
        $entry_ids  = array_column( $entries, 'id' );
        $comments   = ! empty( $entry_ids ) ? $this->query_comments_for_entries( $entry_ids, $session['session_key'] ) : array();

        // Get accepted extensions from config
        $file_type_config = $this->config->get_file_type( $event_type, $file_type );
        $allowed_ext      = $file_type_config ? ( $file_type_config['ext'] ?? array() ) : array();

        $html = $this->render_upload_panel( $entries, $comments, $session_id, $file_type, $allowed_ext, $can_edit, $is_admin, $this->load_session_data( $session['session_key'] ) );

        return new WP_REST_Response( array(
            'success' => true,
            'html'    => $html,
            'count'   => count( $entries ),
        ), 200 );
    }

    /* ═══════════════════════════════════════════
     * POST — Upload new file version
     * ═══════════════════════════════════════════ */

    public function upload_file( $request ) {
        $session_id   = absint( $request->get_param( 'session_id' ) );
        $file_type    = sanitize_text_field( $request->get_param( 'file_type' ) ?? '' );
        $upload_note  = sanitize_textarea_field( $request->get_param( 'note' ) ?? '' );
        $user_id      = get_current_user_id();

        // ─── Validate session access ───
        if ( ! $this->access->user_can_edit( $user_id, $session_id ) ) {
            return new WP_REST_Response( array( 'success' => false, 'message' => 'Access denied.' ), 403 );
        }

        $session = $this->get_session( $session_id );
        if ( ! $session ) {
            return new WP_REST_Response( array( 'success' => false, 'message' => 'Session not found.' ), 404 );
        }

        $event_type = $session['event_type'] ?? 'satellite';

        // ─── Validate file_type ───
        $file_type_config = $this->config->get_file_type( $event_type, $file_type );
        if ( ! $file_type_config ) {
            return new WP_REST_Response( array( 'success' => false, 'message' => 'Unknown file type.' ), 400 );
        }

        // ─── Validate file was sent ───
        $files = $request->get_file_params();
        if ( empty( $files['file'] ) || $files['file']['error'] !== UPLOAD_ERR_OK ) {
            return new WP_REST_Response( array( 'success' => false, 'message' => 'No file uploaded.' ), 400 );
        }

        $file = $files['file'];

        // ─── Validate extension ───
        $ext = strtolower( pathinfo( $file['name'], PATHINFO_EXTENSION ) );
        $allowed_ext = $file_type_config['ext'] ?? array();
        if ( ! in_array( $ext, $allowed_ext, true ) ) {
            return new WP_REST_Response( array(
                'success' => false,
                'message' => 'Invalid file type. Allowed: ' . implode( ', ', array_map( 'strtoupper', $allowed_ext ) ) . '.',
            ), 400 );
        }

        // ─── Determine next version ───
        $existing = $this->query_entries( $session['session_key'], $file_type );
        $next_version = count( $existing ) + 1;

        // ─── Guard: reject upload if latest version is already approved ───
        if ( ! empty( $existing ) ) {
            $latest_status = $existing[0]['wssp_admin_material_status'] ?? '';
            if ( strpos( $latest_status, 'Approved' ) !== false ) {
                $latest_version = $existing[0]['wssp_material_version'] ?? '1';
                $planner_name   = do_shortcode( '[satellite-planner-name]' );
                $planner_email  = do_shortcode( '[satellite-planner-email]' );
                return new WP_REST_Response( array(
                    'success'  => false,
                    'approved' => true,
                    'message'  => sprintf(
                        'Version %s has been approved. If you need to upload a new file, please contact %s at %s.',
                        $latest_version,
                        wp_strip_all_tags( $planner_name ),
                        wp_strip_all_tags( $planner_email )
                    ),
                ), 403 );
            }
        }

        // ─── Build convention name for pre-filter ───
        // Preserve original case and hyphens from session fields.
        // Only strip characters that are unsafe for filenames.
        // Uses hyphens as the separator throughout.
        $session_code = sanitize_file_name( $session['session_code'] );
        $short_name   = preg_replace( '/[^A-Za-z0-9\-]/', '', $session['short_name'] );
        $type_key     = str_replace( '_', '-', $file_type );
        $convention_name = sprintf( '%s-%s-%s-v%d.%s', $session_code, $short_name, $type_key, $next_version, $ext );

        // Convention name used by inline upload filters below

        // ─── Get form and field IDs ───
        $form_id   = $this->get_form_id( $this->form_key );
        $field_map = $this->get_field_id_map( $form_id );

        if ( ! $form_id || empty( $field_map ) ) {
            return new WP_REST_Response( array( 'success' => false, 'message' => 'Upload form not configured.' ), 500 );
        }

        // ─── Handle file upload into Formidable's directory ───
        require_once ABSPATH . 'wp-admin/includes/file.php';

        // Temporarily redirect uploads to Formidable's directory structure
        $upload_dir_filter = function( $dirs ) use ( $form_id ) {
            $frm_subdir = '/formidable/' . $form_id;
            $dirs['path']   = $dirs['basedir'] . $frm_subdir;
            $dirs['url']    = $dirs['baseurl'] . $frm_subdir;
            $dirs['subdir'] = $frm_subdir;
            return $dirs;
        };
        add_filter( 'upload_dir', $upload_dir_filter );

        // Rename the file before WordPress saves it
        $rename_filter = function( $file_data ) use ( $convention_name ) {
            $file_data['name'] = $convention_name;
            return $file_data;
        };
        add_filter( 'wp_handle_upload_prefilter', $rename_filter );

        $upload_overrides = array( 'test_form' => false );
        $uploaded = wp_handle_upload( $file, $upload_overrides );

        // Remove filters immediately
        remove_filter( 'upload_dir', $upload_dir_filter );
        remove_filter( 'wp_handle_upload_prefilter', $rename_filter );
        delete_transient( 'wssp_upload_rename_' . $user_id );

        if ( isset( $uploaded['error'] ) ) {
            return new WP_REST_Response( array( 'success' => false, 'message' => $uploaded['error'] ), 500 );
        }

        // ─── Build item_meta for Formidable entry ───
        $item_meta = array();

        if ( isset( $field_map['wssp_material_session_key'] ) ) {
            $item_meta[ $field_map['wssp_material_session_key'] ] = $session['session_key'];
        }
        if ( isset( $field_map['wssp_material_file_type'] ) ) {
            $item_meta[ $field_map['wssp_material_file_type'] ] = $file_type;
        }
        if ( isset( $field_map['wssp_material_file'] ) ) {
            $item_meta[ $field_map['wssp_material_file'] ] = $uploaded['url'];
        }
        if ( isset( $field_map['wssp_material_version'] ) ) {
            $item_meta[ $field_map['wssp_material_version'] ] = $next_version;
        }
        if ( isset( $field_map['wssp_material_user_id'] ) ) {
            $item_meta[ $field_map['wssp_material_user_id'] ] = $user_id;
        }
        if ( isset( $field_map['wssp_material_original_name'] ) ) {
            $item_meta[ $field_map['wssp_material_original_name'] ] = sanitize_file_name( $file['name'] );
        }
        if ( isset( $field_map['wssp_admin_material_status'] ) ) {
            $item_meta[ $field_map['wssp_admin_material_status'] ] = 'Pending, Not Reviewed';
        }

        // ─── Create Formidable entry ───
        $entry_id = FrmEntry::create( array(
            'form_id'   => $form_id,
            'item_meta' => $item_meta,
        ) );

        if ( ! $entry_id || is_wp_error( $entry_id ) ) {
            return new WP_REST_Response( array( 'success' => false, 'message' => 'Could not create entry.' ), 500 );
        }

        // ─── Auto-create upload note ───
        $note_text = ! empty( $upload_note ) ? $upload_note : ( $next_version === 1 ? 'Initial upload' : 'New version uploaded' );
        $this->create_note( $entry_id, $session['session_key'], $user_id, $note_text, 'sponsor' );

        // ─── Audit log ───
        $this->audit->log( array(
            'session_id'  => $session_id,
            'event_type'  => $event_type,
            'user_id'     => $user_id,
            'action'      => 'file_upload',
            'entity_type' => 'material',
            'entity_id'   => (string) $entry_id,
            'field_name'  => $file_type,
            'new_value'   => $convention_name,
            'meta'        => array(
                'version'       => $next_version,
                'original_name' => $file['name'],
                'note'          => $note_text,
            ),
        ));

        // ─── Notify (email) ───
        // Sponsor upload → review-team inbox. Routed by event_type; see
        // WSSP_Notifier for mode and recipient handling. Uses upload_note
        // (not the auto "Initial upload"/"New version" label) because that
        // text is the sponsor's own commentary and is what the reader
        // actually wants to see in the email.
        if ( $this->notifier ) {
            $this->notifier->notify_file_uploaded(
                $session,
                $entry_id,
                $file_type,
                $next_version,
                $upload_note,
                $user_id
            );
        }

        // ─── Mark upload task as in_progress ───
        // The upload itself means the sponsor has started this task.
        // Completion happens when logistics approves the file.
        $this->mark_upload_task_in_progress( $session_id, $file_type );

        // ─── Return re-rendered panel ───
        $can_edit   = $this->can_edit( $user_id, $session_id );
        $is_admin   = $this->access->user_can_review( $user_id );
        $entries    = $this->query_entries( $session['session_key'], $file_type );
        $entry_ids  = array_column( $entries, 'id' );
        $comments   = ! empty( $entry_ids ) ? $this->query_comments_for_entries( $entry_ids, $session['session_key'] ) : array();
        $allowed_ext = $file_type_config['ext'] ?? array();

        $html = $this->render_upload_panel( $entries, $comments, $session_id, $file_type, $allowed_ext, $can_edit, $is_admin, $this->load_session_data( $session['session_key'] ) );

        return new WP_REST_Response( array(
            'success' => true,
            'html'    => $html,
            'count'   => count( $entries ),
        ), 201 );
    }

    /* ═══════════════════════════════════════════
     * POST — Update status (logistics only)
     * ═══════════════════════════════════════════ */

    public function update_status( $request ) {
        $entry_id    = absint( $request->get_param( 'id' ) );
        $new_status  = sanitize_text_field( $request->get_param( 'status' ) ?? '' );
        $change_note = sanitize_textarea_field( $request->get_param( 'change_note' ) ?? '' );
        $session_id  = absint( $request->get_param( 'session_id' ) );
        $user_id     = get_current_user_id();

        if ( ! $this->access->user_can_review( $user_id ) ) {
            return new WP_REST_Response( array( 'success' => false, 'message' => 'Only logistics staff can update status.' ), 403 );
        }

        $session = $this->get_session( $session_id );
        if ( ! $session ) {
            return new WP_REST_Response( array( 'success' => false, 'message' => 'Session not found.' ), 404 );
        }

        if ( ! $this->entry_belongs_to_session( $entry_id, $session['session_key'] ) ) {
            return new WP_REST_Response( array( 'success' => false, 'message' => 'Entry not found.' ), 404 );
        }

        $valid_statuses = array( 'Pending, Not Reviewed', 'Reviewed, Changes Required (See Notes)', 'Approved' );
        if ( ! in_array( $new_status, $valid_statuses, true ) ) {
            return new WP_REST_Response( array( 'success' => false, 'message' => 'Invalid status.' ), 400 );
        }

        // Require a change note when requesting revisions
        $is_changes_required = strpos( $new_status, 'Changes Required' ) !== false;
        if ( $is_changes_required && empty( $change_note ) ) {
            return new WP_REST_Response( array( 'success' => false, 'message' => 'Please describe what changes are needed.' ), 400 );
        }

        $form_id   = $this->get_form_id( $this->form_key );
        $field_map = $this->get_field_id_map( $form_id );

        // Update status
        if ( isset( $field_map['wssp_admin_material_status'] ) ) {
            FrmEntryMeta::update_entry_meta( $entry_id, $field_map['wssp_admin_material_status'], null, $new_status );
        }

        // Set approval date if approved
        if ( $new_status === 'Approved' && isset( $field_map['wssp_admin_material_date_approved'] ) ) {
            FrmEntryMeta::update_entry_meta( $entry_id, $field_map['wssp_admin_material_date_approved'], null, current_time( 'Y-m-d' ) );
        }

        // Save change request note on the entry (drives the "Changes Requested"
        // banner in render_upload_panel) AND create a logistics-type comment
        // row so the full review history lives in the comment thread.
        //
        // Plumbing note: FrmEntryMeta::update_entry_meta only updates an
        // existing meta row — it silently no-ops if no row exists yet. The
        // upload handler does not seed this field when the entry is created,
        // so the first rejection on a given entry has no row to update. We
        // call add_entry_meta first (safe no-op if a row already exists from
        // a prior rejection or approval-clear) then update_entry_meta to
        // ensure the latest value sticks in all cases.
        if ( $is_changes_required && ! empty( $change_note ) && isset( $field_map['wssp_material_change_requested'] ) ) {
            $reviewer       = get_userdata( $user_id );
            $reviewer_name  = $reviewer ? $reviewer->display_name : 'Logistics';
            $timestamped    = sprintf( '[%s — %s] %s', current_time( 'M j, Y g:i a' ), $reviewer_name, $change_note );
            $cr_field_id    = $field_map['wssp_material_change_requested'];

            FrmEntryMeta::add_entry_meta( $entry_id, $cr_field_id, null, $timestamped );
            FrmEntryMeta::update_entry_meta( $entry_id, $cr_field_id, null, $timestamped );

            // Append to the comment thread as a logistics note so history is
            // preserved even after approval clears the banner field above.
            $this->create_note( $entry_id, $session['session_key'], $user_id, $change_note, 'logistics' );
        }

        // Clear the change request field on approval (clean slate)
        if ( $new_status === 'Approved' && isset( $field_map['wssp_material_change_requested'] ) ) {
            FrmEntryMeta::update_entry_meta( $entry_id, $field_map['wssp_material_change_requested'], null, '' );
        }

        wp_cache_delete( $entry_id, 'frm_entry' );

        // ─── Sync task completion status ───
        // Same logic as the frm_after_update_entry hook, but triggered
        // directly since FrmEntryMeta::update_entry_meta doesn't fire
        // Formidable's entry update hooks.
        $file_type   = $this->get_entry_field( $entry_id, 'wssp_material_file_type', $field_map );
        $is_approved = strpos( $new_status, 'Approved' ) !== false;

        // Resolve the task_key from file_type for audit logging
        $task_key_for_audit = '';
        $event_config_      = $this->config->get_event_type( 'satellite' );
        $task_behavior_     = $event_config_['task_behavior'] ?? array();
        foreach ( $task_behavior_ as $tk => $overrides ) {
            if ( ( $overrides['type'] ?? '' ) === 'upload' && ( $overrides['file_type'] ?? '' ) === $file_type ) {
                $task_key_for_audit = $tk;
                break;
            }
        }

        // Capture old task status BEFORE sync changes it
        $old_task_status = '';
        if ( $task_key_for_audit ) {
            global $wpdb;
            $old_task_status = $wpdb->get_var( $wpdb->prepare(
                "SELECT status FROM {$wpdb->prefix}wssp_task_status WHERE session_id = %d AND task_key = %s",
                $session_id, $task_key_for_audit
            ) ) ?? 'in_progress';
        }

        // Only sync if this is the latest version
        $entries     = $this->query_entries( $session['session_key'], $file_type );
        $is_latest   = ! empty( $entries ) && (int) $entries[0]['id'] === (int) $entry_id;

        if ( $is_latest ) {
            $this->sync_task_status( $session_id, $file_type, $is_approved, $user_id );
        }

        // ─── Audit log ───
        $task_status_value = $is_approved ? 'approved' : 'revision_requested';

        $audit_meta = array(
            'trigger'           => 'logistics_file_review',
            'entry_id'          => $entry_id,
            'file_type'         => $file_type,
            'formidable_status' => $new_status,
        );
        if ( $change_note ) {
            $audit_meta['change_note'] = $change_note;
        }

        $this->audit->log( array(
            'session_id'  => $session_id,
            'event_type'  => $session['event_type'] ?? 'satellite',
            'user_id'     => $user_id,
            'action'      => 'status_change',
            'entity_type' => 'task',
            'entity_id'   => $task_key_for_audit ?: (string) $entry_id,
            'field_name'  => 'status',
            'old_value'   => $old_task_status,
            'new_value'   => $task_status_value,
            'meta'        => $audit_meta,
        ));

        // ─── Notify (email) ───
        // One event per logistics review action. For "Changes Required", the
        // change_note is carried in the payload so the renderer can show the
        // rejection text inline — collapsing the banner-field + logistics-
        // comment plumbing into a single human-readable event.
        if ( $this->notifier ) {
            $version_for_email = 0;
            foreach ( $entries as $e ) {
                if ( (int) ( $e['id'] ?? 0 ) === (int) $entry_id ) {
                    $version_for_email = (int) ( $e['wssp_material_version'] ?? 0 );
                    break;
                }
            }
            $this->notifier->notify_file_status_changed(
                $session,
                $entry_id,
                $file_type,
                $version_for_email,
                $new_status,
                $change_note,
                $user_id
            );
        }

        // Re-render
        $can_edit   = $this->can_edit( $user_id, $session_id );
        $is_admin   = true;
        // Re-query entries to get updated status
        $entries    = $this->query_entries( $session['session_key'], $file_type );
        $entry_ids  = array_column( $entries, 'id' );
        $comments   = ! empty( $entry_ids ) ? $this->query_comments_for_entries( $entry_ids, $session['session_key'] ) : array();

        $event_type       = $session['event_type'] ?? 'satellite';
        $file_type_config = $this->config->get_file_type( $event_type, $file_type );
        $allowed_ext      = $file_type_config ? ( $file_type_config['ext'] ?? array() ) : array();

        $html = $this->render_upload_panel( $entries, $comments, $session_id, $file_type, $allowed_ext, $can_edit, $is_admin, $this->load_session_data( $session['session_key'] ) );

        return new WP_REST_Response( array( 'success' => true, 'html' => $html ), 200 );
    }

    /* ═══════════════════════════════════════════
     * COMMENTS — GET + POST
     * ═══════════════════════════════════════════ */

    public function get_comments( $request ) {
        $entry_id   = absint( $request->get_param( 'id' ) );
        $session_id = absint( $request->get_param( 'session_id' ) );
        $user_id    = get_current_user_id();

        if ( ! $this->access->user_can_access( $user_id, $session_id ) ) {
            return new WP_REST_Response( array( 'success' => false, 'message' => 'Access denied.' ), 403 );
        }

        $session  = $this->get_session( $session_id );
        $comments = $this->query_comments_for_entry( $entry_id, $session['session_key'] ?? '' );

        return new WP_REST_Response( array( 'success' => true, 'comments' => $comments ), 200 );
    }

    public function add_comment( $request ) {
        $entry_id     = absint( $request->get_param( 'id' ) );
        $session_id   = absint( $request->get_param( 'session_id' ) );
        $comment_text = sanitize_textarea_field( $request->get_param( 'comment' ) ?? '' );
        $user_id      = get_current_user_id();

        if ( ! $this->access->user_can_access( $user_id, $session_id ) ) {
            return new WP_REST_Response( array( 'success' => false, 'message' => 'Access denied.' ), 403 );
        }

        if ( empty( $comment_text ) ) {
            return new WP_REST_Response( array( 'success' => false, 'message' => 'Comment cannot be empty.' ), 400 );
        }

        $session = $this->get_session( $session_id );
        if ( ! $session ) {
            return new WP_REST_Response( array( 'success' => false, 'message' => 'Session not found.' ), 404 );
        }

        if ( ! $this->entry_belongs_to_session( $entry_id, $session['session_key'] ) ) {
            return new WP_REST_Response( array( 'success' => false, 'message' => 'Entry not found.' ), 404 );
        }

        // Determine note type based on user role
        $is_admin  = $this->access->user_can_review( $user_id );
        $note_type = $is_admin ? 'logistics' : 'sponsor';

        $note_id = $this->create_note( $entry_id, $session['session_key'], $user_id, $comment_text, $note_type );

        if ( ! $note_id ) {
            return new WP_REST_Response( array( 'success' => false, 'message' => 'Could not save note.' ), 500 );
        }

        // Re-render the full panel
        $file_type  = $this->get_entry_field_by_key( $entry_id, 'wssp_material_file_type' );
        $can_edit   = $this->can_edit( $user_id, $session_id );
        $entries    = $this->query_entries( $session['session_key'], $file_type );
        $entry_ids  = array_column( $entries, 'id' );
        $comments   = ! empty( $entry_ids ) ? $this->query_comments_for_entries( $entry_ids, $session['session_key'] ) : array();

        // ─── Notify (email) ───
        // Only the user-initiated Post-button flow reaches this path. The
        // logistics comment that is auto-created inside update_status() is
        // already covered by notify_file_status_changed there, so notifying
        // here would double-count rejections in the digest.
        if ( $this->notifier ) {
            $version_for_email = 0;
            foreach ( $entries as $e ) {
                if ( (int) ( $e['id'] ?? 0 ) === (int) $entry_id ) {
                    $version_for_email = (int) ( $e['wssp_material_version'] ?? 0 );
                    break;
                }
            }
            $this->notifier->notify_comment_added(
                $session,
                $entry_id,
                $file_type,
                $version_for_email,
                $comment_text,
                $note_type,
                $user_id
            );
        }

        $event_type       = $session['event_type'] ?? 'satellite';
        $file_type_config = $this->config->get_file_type( $event_type, $file_type );
        $allowed_ext      = $file_type_config ? ( $file_type_config['ext'] ?? array() ) : array();

        $html = $this->render_upload_panel( $entries, $comments, $session_id, $file_type, $allowed_ext, $can_edit, $is_admin, $this->load_session_data( $session['session_key'] ) );

        return new WP_REST_Response( array( 'success' => true, 'html' => $html ), 201 );
    }

    /* ═══════════════════════════════════════════
     * VBI UPDATE — Save VBI selection on existing entry
     * ═══════════════════════════════════════════ */

    public function update_vbi( $request ) {
        $session_id = absint( $request->get_param( 'session_id' ) );
        $vbi_value  = sanitize_text_field( $request->get_param( 'vbi_value' ) ?? '' );
        $user_id    = get_current_user_id();

        if ( ! $this->access->user_can_edit( $user_id, $session_id ) ) {
            return new WP_REST_Response( array( 'success' => false, 'message' => 'Access denied.' ), 403 );
        }

        $session = $this->get_session( $session_id );
        if ( ! $session ) {
            return new WP_REST_Response( array( 'success' => false, 'message' => 'Session not found.' ), 404 );
        }

        // ─── Save VBI value to the session-data Formidable entry ───
        $session_entry_id = $session['frm_entry_id'] ?? null;
        if ( ! $session_entry_id ) {
            return new WP_REST_Response( array( 'success' => false, 'message' => 'Session form entry not found.' ), 500 );
        }

        $session_form_id   = $this->get_form_id( 'wssp-sat-session-data' );
        $session_field_map = $this->get_field_id_map( $session_form_id );
        $vbi_field_id      = $session_field_map['wssp_virtual_bag_insert_file'] ?? null;

        if ( ! $vbi_field_id ) {
            return new WP_REST_Response( array( 'success' => false, 'message' => 'VBI field not found in session form.' ), 500 );
        }
        
        $existing = FrmEntryMeta::get_entry_meta_by_field( $session_entry_id, $vbi_field_id );
        if ( is_null( $existing ) ) {
            FrmEntryMeta::add_entry_meta( $session_entry_id, $vbi_field_id, '', $vbi_value );
        } else {
            FrmEntryMeta::update_entry_meta( $session_entry_id, $vbi_field_id, null, $vbi_value );
        }

        wp_cache_delete( $session_entry_id, 'frm_entry' );

        // Re-render the upload panel
        $file_type  = 'invite';
        $can_edit   = $this->can_edit( $user_id, $session_id );
        $is_admin   = $this->access->user_can_review( $user_id );
        $entries    = $this->query_entries( $session['session_key'], $file_type );
        $entry_ids  = array_column( $entries, 'id' );
        $comments   = ! empty( $entry_ids ) ? $this->query_comments_for_entries( $entry_ids, $session['session_key'] ) : array();

        $event_type       = $session['event_type'] ?? 'satellite';
        $file_type_config = $this->config->get_file_type( $event_type, $file_type );
        $allowed_ext      = $file_type_config ? ( $file_type_config['ext'] ?? array() ) : array();

        // Reload session data to get the updated VBI value for rendering
        $formidable   = new WSSP_Formidable();
        $session_data = $formidable->get_full_session_data( $session['session_key'] );

        $html = $this->render_upload_panel( $entries, $comments, $session_id, $file_type, $allowed_ext, $can_edit, $is_admin, $session_data );

        return new WP_REST_Response( array( 'success' => true, 'html' => $html ), 200 );
    }

    /* ═══════════════════════════════════════════
     * RENDERING — Server-side HTML
     * ═══════════════════════════════════════════ */

    /**
     * Render the full upload panel: dropzone + version history + comments.
     */
    private function render_upload_panel( $entries, $comments, $session_id, $file_type, $allowed_ext, $can_edit, $is_admin, $session_data = array() ) {
        $ext_display = implode( ', ', array_map( 'strtoupper', $allowed_ext ) );
        $accept_attr = implode( ',', array_map( function( $e ) { return '.' . $e; }, $allowed_ext ) );
        $show_vbi    = ( $file_type === 'invite' );

        // Check if latest version is approved — hide upload controls if so
        $latest_approved = false;
        if ( ! empty( $entries ) ) {
            $latest_status = $entries[0]['wssp_admin_material_status'] ?? '';
            if ( strpos( $latest_status, 'Approved' ) !== false ) {
                $latest_approved = true;
            }
        }
        $show_upload = $can_edit && ! $latest_approved;

        ob_start();
        ?>
        <div class="wssp-upload" data-session-id="<?php echo esc_attr( $session_id ); ?>" data-file-type="<?php echo esc_attr( $file_type ); ?>">

            <?php // ─── Editable area (sponsors) ─── ?>
            <?php if ( $can_edit ) : ?>

                <?php // ─── VBI field (only for invite — shown above dropzone) ─── ?>
                <?php if ( $show_vbi && $show_upload ) : ?>
                    <div class="wssp-upload__vbi-field">
                        <label class="wssp-upload__label">Virtual Bag Insert</label>
                        <p class="wssp-upload__field-hint">Will you use this invitation PDF as your Virtual Bag Insert, or upload a separate file?</p>
                        <div class="wssp-upload__radio-group">
                            <?php
                            $current_vbi = $session_data['wssp_virtual_bag_insert_file'] ?? '';
                            if ( is_array( $current_vbi ) ) {
                                $current_vbi = reset( $current_vbi );
                            }
                            $current_vbi = (string) $current_vbi;
                            ?>
                            <label class="wssp-upload__radio">
                                <input type="radio" name="wssp_virtual_bag_insert_file" value="Yes - existing"
                                       <?php checked( $current_vbi, 'Yes - existing' ); ?>>
                                <span>Yes — use the Invitation PDF</span>
                            </label>
                            <label class="wssp-upload__radio">
                                <input type="radio" name="wssp_virtual_bag_insert_file" value="No - separate"
                                       <?php checked( $current_vbi, 'No - separate' ); ?>>
                                <span>No — I will upload a separate file</span>
                            </label>
                        </div>
                        <button class="wssp-btn wssp-btn--sm wssp-btn--primary wssp-upload__vbi-save"
                                style="margin-top: 8px;">
                            Save Selection
                        </button>
                    </div>
                <?php endif; ?>

                <?php if ( $show_upload ) : ?>
                    <?php // ─── Dropzone ─── ?>
                    <div class="wssp-upload__dropzone" id="wssp-dropzone">
                        <div class="wssp-upload__dropzone-content">
                            <svg class="wssp-upload__dropzone-icon" width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
                                <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/>
                                <polyline points="17 8 12 3 7 8"/>
                                <line x1="12" y1="3" x2="12" y2="15"/>
                            </svg>
                            <p class="wssp-upload__dropzone-text">Drag &amp; drop your file here, or <span class="wssp-upload__browse">browse</span></p>
                            <p class="wssp-upload__dropzone-hint">Accepted formats: <?php echo esc_html( $ext_display ); ?></p>
                            <input type="file" class="wssp-upload__file-input" id="wssp-file-input" accept="<?php echo esc_attr( $accept_attr ); ?>" style="display:none;" />
                        </div>
                        <div class="wssp-upload__progress" style="display:none;">
                            <div class="wssp-upload__progress-bar"><div class="wssp-upload__progress-fill"></div></div>
                            <p class="wssp-upload__progress-text">Uploading…</p>
                        </div>
                    </div>

                    <?php // ─── Staged upload confirmation (shown after file selection via JS) ─── ?>
                    <div class="wssp-upload__staged" id="wssp-staged" style="display:none;">
                        <div class="wssp-upload__staged-file">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/>
                                <polyline points="14 2 14 8 20 8"/>
                            </svg>
                            <span class="wssp-upload__staged-filename" id="wssp-staged-filename"></span>
                            <button class="wssp-upload__staged-remove" id="wssp-staged-remove" title="Remove file">&times;</button>
                        </div>
                        <div class="wssp-upload__note-area">
                            <label class="wssp-upload__label" for="wssp-upload-note">Note <span class="wssp-upload__optional">(optional)</span></label>
                            <input type="text" class="wssp-upload__note-input" id="wssp-upload-note"
                                   placeholder="<?php echo empty( $entries ) ? 'Initial upload' : 'What changed in this version?'; ?>" />
                        </div>
                        <button class="wssp-btn wssp-btn--primary wssp-upload__staged-submit" id="wssp-staged-submit">
                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/>
                                <polyline points="17 8 12 3 7 8"/>
                                <line x1="12" y1="3" x2="12" y2="15"/>
                            </svg>
                            Upload File
                        </button>
                    </div>

                <?php elseif ( $latest_approved ) : ?>
                    <?php // ─── Approved message — no more uploads ─── ?>
                    <div class="wssp-upload__approved-notice">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/>
                            <polyline points="22 4 12 14.01 9 11.01"/>
                        </svg>
                        <p>This file has been approved. If you need to upload a new version, please contact
                            <?php echo do_shortcode( '[satellite-planner-name]' ); ?> at
                            <?php echo do_shortcode( '[satellite-planner-email]' ); ?>.</p>
                    </div>
                <?php endif; ?>

            <?php endif; ?>

            <?php // ─── Version History ─── ?>
            <?php if ( ! empty( $entries ) ) : ?>
                <div class="wssp-upload__history">
                    <h4 class="wssp-upload__history-title">Version History</h4>
                    <div class="wssp-upload__versions">
                        <?php foreach ( $entries as $i => $entry ) :
                            $is_latest     = ( $i === 0 );
                            $version       = $entry['wssp_material_version'] ?? ( count( $entries ) - $i );
                            $original_name = $entry['wssp_material_original_name'] ?? '';
                            $file_url      = $entry['wssp_material_file'] ?? '';
                            $status        = $entry['wssp_admin_material_status'] ?? 'Pending, Not Reviewed';
                            $uploader_id   = $entry['wssp_material_user_id'] ?? 0;
                            $uploader      = $uploader_id ? get_userdata( $uploader_id ) : null;
                            $uploader_name = $uploader ? $uploader->display_name : 'Unknown';
                            $created_at    = $entry['created_at'] ?? '';
                            $entry_id      = $entry['id'];
                            $change_requested = $entry['wssp_material_change_requested'] ?? '';

                            // Status CSS class
                            $status_class = 'pending';
                            if ( strpos( $status, 'Approved' ) !== false ) $status_class = 'approved';
                            elseif ( strpos( $status, 'Changes Required' ) !== false ) $status_class = 'changes-required';

                            // Notes for this entry
                            $entry_notes = $comments[ (int) $entry_id ] ?? array();
                        ?>
                            <div class="wssp-upload__version-row <?php echo $is_latest ? 'wssp-upload__version-row--active' : 'wssp-upload__version-row--inactive'; ?>"
                                 data-entry-id="<?php echo esc_attr( $entry_id ); ?>">

                                <div class="wssp-upload__version-header">
                                    <span class="wssp-upload__version-badge <?php echo $is_latest ? 'wssp-upload__version-badge--active' : ''; ?>">
                                        v<?php echo esc_html( $version ); ?>
                                    </span>
                                    <span class="wssp-upload__version-meta">
                                        <?php echo esc_html( $uploader_name ); ?> &middot; <?php echo esc_html( $this->format_date( $created_at ) ); ?>
                                    </span>
                                    <span class="wssp-upload__status wssp-upload__status--<?php echo esc_attr( $status_class ); ?>">
                                        <?php echo esc_html( $status ); ?>
                                    </span>
                                    <?php if ( $file_url ) : ?>
                                        <a href="<?php echo esc_url( $file_url ); ?>" class="wssp-btn wssp-btn--sm wssp-btn--outline" target="_blank" title="View file">
                                            <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                                <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/>
                                                <polyline points="7 10 12 15 17 10"/>
                                                <line x1="12" y1="15" x2="12" y2="3"/>
                                            </svg>
                                        </a>
                                    <?php endif; ?>
                                </div>

                                <?php // ─── Change Request Banner ─── ?>
                                <?php if ( $change_requested && strpos( $status, 'Changes Required' ) !== false ) : ?>
                                    <div class="wssp-upload__change-request">
                                        <div class="wssp-upload__change-request-label">
                                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                                <circle cx="12" cy="12" r="10"/>
                                                <line x1="12" y1="8" x2="12" y2="12"/>
                                                <line x1="12" y1="16" x2="12.01" y2="16"/>
                                            </svg>
                                            Changes Requested
                                        </div>
                                        <div class="wssp-upload__change-request-text"><?php echo esc_html( $change_requested ); ?></div>
                                    </div>
                                <?php endif; ?>

                                <?php // ─── Status change (logistics only) ─── ?>
                                <?php if ( $is_admin && $is_latest ) : ?>
                                    <div class="wssp-upload__admin-actions">
                                        <select class="wssp-upload__status-select" data-entry-id="<?php echo esc_attr( $entry_id ); ?>">
                                            <option value="Pending, Not Reviewed" <?php selected( $status, 'Pending, Not Reviewed' ); ?>>Pending, Not Reviewed</option>
                                            <option value="Reviewed, Changes Required (See Notes)" <?php selected( $status, 'Reviewed, Changes Required (See Notes)' ); ?>>Changes Required</option>
                                            <option value="Approved" <?php selected( $status, 'Approved' ); ?>>Approved</option>
                                        </select>
                                        <textarea class="wssp-upload__change-note" data-entry-id="<?php echo esc_attr( $entry_id ); ?>"
                                                  placeholder="Describe what changes are needed…"
                                                  style="display: none;"></textarea>
                                        <button class="wssp-btn wssp-btn--sm wssp-btn--primary wssp-upload__status-save" data-entry-id="<?php echo esc_attr( $entry_id ); ?>">
                                            Update
                                        </button>
                                    </div>
                                <?php endif; ?>

                                <?php // ─── Notes ─── ?>
                                <?php if ( ! empty( $entry_notes ) ) : ?>
                                    <div class="wssp-upload__notes">
                                        <?php foreach ( $entry_notes as $note ) :
                                            $c_user_id = $note['wssp_comment_user_id'] ?? 0;
                                            $c_user    = $c_user_id ? get_userdata( $c_user_id ) : null;
                                            $c_name    = $c_user ? $c_user->display_name : 'Unknown';
                                            $c_type    = $note['wssp_comment_type'] ?? 'general';
                                            $c_text    = $note['wssp_comment_text'] ?? '';
                                            $c_date    = $note['created_at'] ?? '';
                                        ?>
                                            <div class="wssp-upload__note wssp-upload__note--<?php echo esc_attr( $c_type ); ?>">
                                                <div class="wssp-upload__note-header">
                                                    <span class="wssp-upload__note-author"><?php echo esc_html( $c_name ); ?></span>
                                                    <span class="wssp-upload__note-role"><?php echo esc_html( ucfirst( $c_type ) ); ?></span>
                                                    <span class="wssp-upload__note-date"><?php echo esc_html( $this->format_date( $c_date ) ); ?></span>
                                                </div>
                                                <div class="wssp-upload__note-body"><?php echo esc_html( $c_text ); ?></div>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                <?php endif; ?>

                                <?php // ─── Add note ─── ?>
                                <div class="wssp-upload__add-note">
                                    <div class="wssp-upload__note-input-row">
                                        <input type="text" class="wssp-upload__note-add-input" data-entry-id="<?php echo esc_attr( $entry_id ); ?>"
                                               placeholder="Add a note…" />
                                        <button class="wssp-btn wssp-btn--sm wssp-btn--primary wssp-upload__note-submit" data-entry-id="<?php echo esc_attr( $entry_id ); ?>">
                                            Post
                                        </button>
                                    </div>
                                </div>

                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php elseif ( ! $can_edit ) : ?>
                <div class="wssp-upload__empty">
                    <p>No files have been uploaded for this task.</p>
                </div>
            <?php endif; ?>

        </div>
        <?php
        return ob_get_clean();
    }


    /* ═══════════════════════════════════════════
     * DATA ACCESS
     * ═══════════════════════════════════════════ */

    /**
     * Query material upload entries for a session + file_type.
     * Returns newest first (highest version first).
     */
    private function query_entries( $session_key, $file_type = null ) {
        global $wpdb;

        $form_id = $this->get_form_id( $this->form_key );
        if ( ! $form_id ) return array();

        $field_map = $this->get_field_id_map( $form_id );
        if ( empty( $field_map ) ) return array();

        $sk_field_id = $field_map[ $this->session_key_field ] ?? null;
        if ( ! $sk_field_id ) return array();

        // Base query: entries matching session_key
        $query = "SELECT e.id, e.created_at
                  FROM {$wpdb->prefix}frm_items e
                  INNER JOIN {$wpdb->prefix}frm_item_metas m ON (e.id = m.item_id)
                  WHERE e.form_id = %d
                    AND m.field_id = %d
                    AND m.meta_value = %s
                    AND e.is_draft = 0";
        $params = array( $form_id, $sk_field_id, $session_key );

        // Filter by file_type if specified
        if ( $file_type ) {
            $ft_field_id = $field_map['wssp_material_file_type'] ?? null;
            if ( $ft_field_id ) {
                $query .= " AND e.id IN (
                    SELECT item_id FROM {$wpdb->prefix}frm_item_metas
                    WHERE field_id = %d AND meta_value = %s
                )";
                $params[] = $ft_field_id;
                $params[] = $file_type;
            }
        }

        $query .= " ORDER BY e.created_at DESC";

        $entry_rows = $wpdb->get_results( $wpdb->prepare( $query, ...$params ), ARRAY_A );

        if ( empty( $entry_rows ) ) return array();

        $entry_ids = array_column( $entry_rows, 'id' );
        $id_to_key = array_flip( $field_map );

        // Bulk load all meta for these entries
        $placeholders = implode( ',', array_fill( 0, count( $entry_ids ), '%d' ) );
        $meta_rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT item_id, field_id, meta_value
             FROM {$wpdb->prefix}frm_item_metas
             WHERE item_id IN ({$placeholders})",
            ...$entry_ids
        ), ARRAY_A );

        // Build entries with field keys
        $entries_data = array();
        foreach ( $entry_rows as $row ) {
            $entries_data[ $row['id'] ] = array(
                'id'         => $row['id'],
                'created_at' => $row['created_at'],
            );
        }

        foreach ( $meta_rows as $row ) {
            $eid       = $row['item_id'];
            $field_id  = $row['field_id'];
            $field_key = $id_to_key[ $field_id ] ?? null;

            if ( $field_key && isset( $entries_data[ $eid ] ) ) {
                $entries_data[ $eid ][ $field_key ] = $row['meta_value'];
            }
        }

        return array_values( $entries_data );
    }

    /**
     * Query comments for multiple material entries at once.
     * Returns array keyed by material_entry_id.
     */
    private function query_comments_for_entries( $entry_ids, $session_key ) {
        global $wpdb;

        $comment_form_id = $this->get_form_id( $this->comment_form_key );
        if ( ! $comment_form_id ) return array();

        $comment_field_map = $this->get_field_id_map( $comment_form_id );
        $sk_field_id       = $comment_field_map['wssp_comment_session_key'] ?? null;
        $mat_field_id      = $comment_field_map['wssp_comment_material_id'] ?? null;

        if ( ! $sk_field_id || ! $mat_field_id ) return array();

        // Get all comment entries for this session
        $comment_entry_ids = $wpdb->get_col( $wpdb->prepare(
            "SELECT e.id
             FROM {$wpdb->prefix}frm_items e
             INNER JOIN {$wpdb->prefix}frm_item_metas m ON (e.id = m.item_id)
             WHERE e.form_id = %d
               AND m.field_id = %d
               AND m.meta_value = %s
               AND e.is_draft = 0
             ORDER BY e.created_at ASC",
            $comment_form_id,
            $sk_field_id,
            $session_key
        ));

        if ( empty( $comment_entry_ids ) ) return array();

        // Bulk load meta
        $id_to_key    = array_flip( $comment_field_map );
        $placeholders = implode( ',', array_fill( 0, count( $comment_entry_ids ), '%d' ) );
        $meta_rows    = $wpdb->get_results( $wpdb->prepare(
            "SELECT item_id, field_id, meta_value
             FROM {$wpdb->prefix}frm_item_metas
             WHERE item_id IN ({$placeholders})",
            ...$comment_entry_ids
        ), ARRAY_A );

        // Build comment data
        $comments_raw = array();
        foreach ( $comment_entry_ids as $cid ) {
            $comments_raw[ $cid ] = array( 'id' => $cid );
        }
        // Add created_at from frm_items
        $items = $wpdb->get_results( $wpdb->prepare(
            "SELECT id, created_at FROM {$wpdb->prefix}frm_items WHERE id IN ({$placeholders})",
            ...$comment_entry_ids
        ), ARRAY_A );
        foreach ( $items as $item ) {
            if ( isset( $comments_raw[ $item['id'] ] ) ) {
                $comments_raw[ $item['id'] ]['created_at'] = $item['created_at'];
            }
        }

        foreach ( $meta_rows as $row ) {
            $cid       = $row['item_id'];
            $field_key = $id_to_key[ $row['field_id'] ] ?? null;
            if ( $field_key && isset( $comments_raw[ $cid ] ) ) {
                $comments_raw[ $cid ][ $field_key ] = $row['meta_value'];
            }
        }

        // Group by material_entry_id
        $grouped = array();
        foreach ( $comments_raw as $c ) {
            $mat_id = $c['wssp_comment_material_id'] ?? null;
            if ( $mat_id && in_array( (int) $mat_id, array_map( 'intval', $entry_ids ), true ) ) {
                $grouped[ (int) $mat_id ][] = $c;
            }
        }

        return $grouped;
    }

    /**
     * Query comments for a single material entry.
     */
    private function query_comments_for_entry( $entry_id, $session_key ) {
        $grouped = $this->query_comments_for_entries( array( $entry_id ), $session_key );
        return $grouped[ $entry_id ] ?? array();
    }

    /* ═══════════════════════════════════════════
     * HELPERS
     * ═══════════════════════════════════════════ */

    private function get_form_id( $form_key ) {
        if ( ! class_exists( 'FrmForm' ) ) return null;
        $form = FrmForm::getOne( $form_key );
        return $form ? (int) $form->id : null;
    }

    private function get_field_id_map( $form_id ) {
        global $wpdb;
        if ( ! $form_id ) return array();

        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT id, field_key FROM {$wpdb->prefix}frm_fields WHERE form_id = %d",
            $form_id
        ), ARRAY_A );

        $map = array();
        foreach ( $rows as $row ) {
            $map[ $row['field_key'] ] = (int) $row['id'];
        }
        return $map;
    }

    private function entry_belongs_to_session( $entry_id, $session_key ) {
        $form_id     = $this->get_form_id( $this->form_key );
        $field_map   = $this->get_field_id_map( $form_id );
        $sk_field_id = $field_map[ $this->session_key_field ] ?? null;
        if ( ! $sk_field_id ) return false;

        global $wpdb;
        $value = $wpdb->get_var( $wpdb->prepare(
            "SELECT meta_value FROM {$wpdb->prefix}frm_item_metas
             WHERE item_id = %d AND field_id = %d",
            $entry_id, $sk_field_id
        ));

        return $value === $session_key;
    }

    /**
     * Get a single field value from a material entry using the field map.
     */
    private function get_entry_field( $entry_id, $field_key, $field_map ) {
        $field_id = $field_map[ $field_key ] ?? null;
        if ( ! $field_id ) return '';

        global $wpdb;
        return $wpdb->get_var( $wpdb->prepare(
            "SELECT meta_value FROM {$wpdb->prefix}frm_item_metas
             WHERE item_id = %d AND field_id = %d",
            $entry_id, $field_id
        )) ?: '';
    }

    /**
     * Get a single field value from a material entry by field key (no field map required).
     */
    private function get_entry_field_by_key( $entry_id, $field_key ) {
        $form_id   = $this->get_form_id( $this->form_key );
        $field_map = $this->get_field_id_map( $form_id );
        return $this->get_entry_field( $entry_id, $field_key, $field_map );
    }

    private function get_session( $session_id ) {
        global $wpdb;
        return $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}wssp_sessions WHERE id = %d",
            $session_id
        ), ARRAY_A );
    }

    private function can_edit( $user_id, $session_id ) {
        return $this->access->user_can_edit( $user_id, $session_id );
    }

    private function format_date( $date_str ) {
        if ( ! $date_str ) return '';
        $ts = strtotime( $date_str );
        if ( ! $ts ) return $date_str;
        return date( 'M j, Y', $ts );
    }

    /**
     * Load full session data (session table + meta + Formidable fields).
     * Used to read session-level fields like VBI selection for rendering.
     *
     * @param string $session_key
     * @return array
     */
    private function load_session_data( $session_key ) {
        $formidable = new WSSP_Formidable();
        return $formidable->get_full_session_data( $session_key );
    }

    /**
     * Create a note entry linked to a material upload entry.
     *
     * @param int    $material_entry_id  The material upload Formidable entry ID.
     * @param string $session_key        Session key.
     * @param int    $user_id            Who is creating the note.
     * @param string $text               Note text.
     * @param string $type               Note type: 'sponsor' or 'logistics'.
     * @return int|false  Created entry ID or false.
     */
    private function create_note( $material_entry_id, $session_key, $user_id, $text, $type = 'sponsor' ) {
        $comment_form_id = $this->get_form_id( $this->comment_form_key );
        $comment_fields  = $this->get_field_id_map( $comment_form_id );

        if ( ! $comment_form_id || empty( $comment_fields ) ) {
            return false;
        }

        $item_meta = array();
        if ( isset( $comment_fields['wssp_comment_material_id'] ) ) {
            $item_meta[ $comment_fields['wssp_comment_material_id'] ] = $material_entry_id;
        }
        if ( isset( $comment_fields['wssp_comment_session_key'] ) ) {
            $item_meta[ $comment_fields['wssp_comment_session_key'] ] = $session_key;
        }
        if ( isset( $comment_fields['wssp_comment_user_id'] ) ) {
            $item_meta[ $comment_fields['wssp_comment_user_id'] ] = $user_id;
        }
        if ( isset( $comment_fields['wssp_comment_text'] ) ) {
            $item_meta[ $comment_fields['wssp_comment_text'] ] = $text;
        }
        if ( isset( $comment_fields['wssp_comment_type'] ) ) {
            $item_meta[ $comment_fields['wssp_comment_type'] ] = $type;
        }

        $entry_id = FrmEntry::create( array(
            'form_id'   => $comment_form_id,
            'item_meta' => $item_meta,
        ) );

        return ( $entry_id && ! is_wp_error( $entry_id ) ) ? $entry_id : false;
    }

    /**
     * Mark an upload task as in_progress when a file is uploaded.
     *
     * Only transitions not_started → in_progress.  Tasks already in
     * a later state (approved, revision_requested) are left alone —
     * uploading a new version after a revision request keeps the
     * revision_requested status until logistics reviews again.
     *
     * @param int    $session_id WSSP session ID.
     * @param string $file_type  File type slug (e.g. 'invite', 'fts').
     */
    private function mark_upload_task_in_progress( $session_id, $file_type ) {
        // Map file_type → task_key
        $event_config  = $this->config->get_event_type( 'satellite' );
        $task_behavior = $event_config['task_behavior'] ?? array();

        $task_key = null;
        foreach ( $task_behavior as $tk => $overrides ) {
            if ( ( $overrides['type'] ?? '' ) === 'upload' && ( $overrides['file_type'] ?? '' ) === $file_type ) {
                $task_key = $tk;
                break;
            }
        }

        if ( ! $task_key ) return;

        global $wpdb;
        $table = $wpdb->prefix . 'wssp_task_status';

        $current = $wpdb->get_var( $wpdb->prepare(
            "SELECT status FROM {$table} WHERE session_id = %d AND task_key = %s",
            $session_id, $task_key
        ));

        // Only transition from not_started/acknowledged (or no row) → in_progress
        if ( $current === null || in_array( $current, array( 'not_started', 'acknowledged' ), true ) ) {
            if ( $current === null ) {
                $wpdb->insert( $table, array(
                    'session_id' => $session_id,
                    'task_key'   => $task_key,
                    'status'     => 'in_progress',
                ), array( '%d', '%s', '%s' ) );
            } else {
                $wpdb->update( $table,
                    array( 'status' => 'in_progress' ),
                    array( 'session_id' => $session_id, 'task_key' => $task_key ),
                    array( '%s' ),
                    array( '%d', '%s' )
                );
            }
        }
    }

    /**
     * Sync the upload task's completion status based on file approval state.
     *
     * Maps file_type → task_key via portal config, then sets the task
     * status to 'approved' or resets to 'not_started'.
     *
     * @param int    $session_id  WSSP session ID.
     * @param string $file_type   File type slug.
     * @param bool   $is_approved Whether the file is approved.
     * @param int    $user_id     Who made the change.
     */
    private function sync_task_status( $session_id, $file_type, $is_approved, $user_id ) {
        // Map file_type to task_key via portal config
        $event_config  = $this->config->get_event_type( 'satellite' );
        $task_behavior = $event_config['task_behavior'] ?? array();

        $task_key = null;
        foreach ( $task_behavior as $tk => $overrides ) {
            if ( ( $overrides['type'] ?? '' ) === 'upload' && ( $overrides['file_type'] ?? '' ) === $file_type ) {
                $task_key = $tk;
                break;
            }
        }

        if ( ! $task_key ) return;

        global $wpdb;
        $table      = $wpdb->prefix . 'wssp_task_status';
        $new_status = $is_approved ? 'approved' : 'revision_requested';
        $now        = current_time( 'mysql' );

        $existing = $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM {$table} WHERE session_id = %d AND task_key = %s",
            $session_id, $task_key
        ));

        if ( $existing ) {
            $update_data = array( 'status' => $new_status );
            $update_fmt  = array( '%s' );

            if ( $is_approved ) {
                $update_data['submitted_at'] = $now;
                $update_data['submitted_by'] = $user_id;
                $update_fmt[] = '%s';
                $update_fmt[] = '%d';
            }

            $wpdb->update( $table, $update_data, array( 'id' => $existing ), $update_fmt, array( '%d' ) );
        } elseif ( $is_approved ) {
            $wpdb->insert(
                $table,
                array(
                    'session_id'   => $session_id,
                    'task_key'     => $task_key,
                    'status'       => 'approved',
                    'submitted_at' => $now,
                    'submitted_by' => $user_id,
                ),
                array( '%d', '%s', '%s', '%s', '%d' )
            );
        }
    }
}