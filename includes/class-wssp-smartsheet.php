<?php
/**
 * Smartsheet API sync.
 *
 * Handles bi-directional sync between the portal and Smartsheet:
 *   - Pull: SS → portal (admin-entered session data)
 *   - Push: portal → SS (sponsor-entered form data)
 *
 * Safety features:
 *   - Dry-run mode: preview what will change before committing
 *   - Empty value protection: blank SS cells won't overwrite existing portal data
 *   - Per-field audit trail: every pull/push logs what changed, old → new
 *   - Session creation logging: auto-created sessions are audit-logged
 *
 * Uses the field mapping from config/smartsheet-field-map.php.
 *
 * @package WSSP
 */

defined( 'ABSPATH' ) || exit;

class WSSP_Smartsheet {

    /** @var WSSP_Config */
    private $config;

    /** @var WSSP_Session_Meta */
    private $session_meta;

    /** @var WSSP_Audit_Log|null */
    private $audit;

    /**
     * Formidable service — set post-construction because WSSP_Formidable
     * depends on WSSP_Smartsheet via its own constructor. Used by the
     * auto-push path to resolve derived portal keys (e.g. the concatenated
     * Contacts-for-Logistics values) that live in repeater child entries
     * and can't be read via FrmField::get_id_by_key().
     *
     * @var WSSP_Formidable|null
     */
    private $formidable;

    /**
     * Dashboard service — set post-construction. Used by apply_pull_changes()
     * to write task status rows when addon meta is latched by a pull, so
     * that every "done" addon task has a real row in wssp_task_status.
     *
     * @var WSSP_Dashboard|null
     */
    private $dashboard;

    /** @var string API base URL. */
    private $api_base = 'https://api.smartsheet.com/2.0';

    /** @var string API token (stored in wp_options). */
    private $api_token;

    /** @var array Field mapping config. */
    private $field_map;

    public function __construct( WSSP_Config $config, WSSP_Session_Meta $session_meta, ?WSSP_Audit_Log $audit = null ) {
        $this->config       = $config;
        $this->session_meta = $session_meta;
        $this->audit        = $audit;
        $this->api_token    = defined( 'WSSP_SMARTSHEET_TOKEN' ) ? WSSP_SMARTSHEET_TOKEN : '';
        $this->field_map    = $this->config->get_smartsheet_map();
    }

    /**
     * Inject the Formidable service. Called from the bootstrap after
     * both Smartsheet and Formidable have been instantiated.
     *
     * @param WSSP_Formidable $formidable
     */
    public function set_formidable( WSSP_Formidable $formidable ) {
        $this->formidable = $formidable;
    }

    /**
     * Inject the Dashboard service. Called from the bootstrap after both
     * Smartsheet and Dashboard have been instantiated. Used by
     * apply_pull_changes() to write task status rows alongside addon meta.
     *
     * @param WSSP_Dashboard $dashboard
     */
    public function set_dashboard( WSSP_Dashboard $dashboard ) {
        $this->dashboard = $dashboard;
    }

    /* ───────────────────────────────────────────
     * PULL: Smartsheet → Portal
     * ─────────────────────────────────────────── */

    /**
     * Pull data from Smartsheet for a single session.
     *
     * In dry_run mode, returns a diff of what would change without
     * writing anything. This lets the admin preview before committing.
     *
     * @param int  $session_id
     * @param bool $dry_run  If true, compute diff only — don't write.
     * @return array {
     *     @type bool   $success
     *     @type string $message
     *     @type int    $updated      Number of fields changed (0 in dry_run).
     *     @type array  $diff         Field-level changes: [ { field, old, new, store, ss_title } ]
     *     @type bool   $dry_run      Whether this was a preview.
     *     @type string $row_id       Smartsheet row ID.
     *     @type int    $skipped      Fields skipped due to empty value protection.
     * }
     */
    public function pull_session( $session_id, $dry_run = false ) {
        if ( empty( $this->api_token ) ) {
            return array( 'success' => false, 'message' => 'Smartsheet API token not configured.', 'updated' => 0 );
        }

        if ( empty( $this->field_map ) ) {
            return array( 'success' => false, 'message' => 'Smartsheet field mapping not configured.', 'updated' => 0 );
        }

        global $wpdb;
        $session = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}wssp_sessions WHERE id = %d",
            $session_id
        ), ARRAY_A );

        if ( ! $session ) {
            return array( 'success' => false, 'message' => 'Session not found.', 'updated' => 0 );
        }

        $session_code = $session['session_code'];
        $sheet_id     = $this->field_map['sheet_id'] ?? '';
        $match_col    = $this->field_map['match_column'] ?? array();

        if ( ! $sheet_id || ! $match_col ) {
            return array( 'success' => false, 'message' => 'Sheet ID or match column not configured.', 'updated' => 0 );
        }

        // Fetch the sheet data
        $sheet_data = $this->api_get( "/sheets/{$sheet_id}" );
        if ( is_wp_error( $sheet_data ) ) {
            return array( 'success' => false, 'message' => 'API error: ' . $sheet_data->get_error_message(), 'updated' => 0 );
        }

        // Find the row matching this session_code
        $match_col_id = $match_col['ss_column_id'];
        $target_row   = null;

        foreach ( $sheet_data['rows'] ?? array() as $row ) {
            foreach ( $row['cells'] ?? array() as $cell ) {
                if ( $cell['columnId'] == $match_col_id ) {
                    $cell_value = trim( $cell['displayValue'] ?? $cell['value'] ?? '' );
                    if ( strcasecmp( $cell_value, $session_code ) === 0 ) {
                        $target_row = $row;
                        break 2;
                    }
                }
            }
        }

        if ( ! $target_row ) {
            return array( 'success' => false, 'message' => "No row found for session code: {$session_code}", 'updated' => 0 );
        }

        // Build cell value lookup
        $cell_values = array();
        foreach ( $target_row['cells'] as $cell ) {
            $cell_values[ $cell['columnId'] ] = $cell;
        }

        // Build proposed changes with diff
        $columns = $this->field_map['columns'] ?? array();
        $result  = $this->compute_pull_diff( $session_id, $session, $columns, $cell_values );

        if ( $dry_run ) {
            return array(
                'success'        => true,
                'dry_run'        => true,
                'message'        => count( $result['diff'] ) . ' field(s) would change. ' . $result['skipped'] . ' skipped (empty value protection).',
                'updated'        => 0,
                'diff'           => $result['diff'],
                'skipped'        => $result['skipped'],
                'skipped_fields' => $result['skipped_fields'],
                'raw_values'     => $result['raw_values'],
                'row_id'         => $target_row['id'] ?? null,
            );
        }

        // ─── Commit the changes ───

        // Store the row ID for future push operations
        if ( ! empty( $target_row['id'] ) ) {
            $wpdb->update(
                $wpdb->prefix . 'wssp_sessions',
                array( 'smartsheet_row_id' => (string) $target_row['id'] ),
                array( 'id' => $session_id ),
                array( '%s' ),
                array( '%d' )
            );
        }

        $updated = $this->apply_pull_changes( $session_id, $session, $result );

        return array(
            'success'  => true,
            'dry_run'  => false,
            'message'  => "Pulled {$updated} fields from Smartsheet." . ( $result['skipped'] > 0 ? " {$result['skipped']} skipped (empty protection)." : '' ),
            'updated'  => $updated,
            'diff'     => $result['diff'],
            'skipped'  => $result['skipped'],
            'row_id'   => $target_row['id'] ?? null,
        );
    }

    /**
     * Pull data for ALL sessions at once.
     *
     * In dry_run mode, returns what would change per session plus
     * which new sessions would be created.
     *
     * @param bool $dry_run  If true, preview only.
     * @return array
     */
    public function pull_all_sessions( $dry_run = false ) {
        if ( empty( $this->api_token ) || empty( $this->field_map ) ) {
            return array( 'success' => false, 'message' => 'Not configured.', 'results' => array() );
        }

        $sheet_id  = $this->field_map['sheet_id'] ?? '';
        $match_col = $this->field_map['match_column'] ?? array();

        $sheet_data = $this->api_get( "/sheets/{$sheet_id}" );
        if ( is_wp_error( $sheet_data ) ) {
            return array( 'success' => false, 'message' => 'API error: ' . $sheet_data->get_error_message(), 'results' => array() );
        }

        global $wpdb;
        $sessions = $wpdb->get_results(
            "SELECT * FROM {$wpdb->prefix}wssp_sessions",
            ARRAY_A
        );

        $sessions_by_code = array();
        foreach ( $sessions as $s ) {
            $sessions_by_code[ strtoupper( $s['session_code'] ) ] = $s;
        }

        $match_col_id  = $match_col['ss_column_id'];
        $columns       = $this->field_map['columns'] ?? array();
        $results       = array();
        $created_count = 0;
        $would_create  = array();

        foreach ( $sheet_data['rows'] ?? array() as $row ) {
            $row_code = '';
            foreach ( $row['cells'] as $cell ) {
                if ( $cell['columnId'] == $match_col_id ) {
                    $row_code = strtoupper( trim( $cell['displayValue'] ?? $cell['value'] ?? '' ) );
                    break;
                }
            }

            if ( ! $row_code ) {
                continue;
            }

            // ─── Handle new sessions ───
            if ( ! isset( $sessions_by_code[ $row_code ] ) ) {
                // Extract sponsor name for context
                $short_name = $this->extract_short_name_from_row( $row, $columns );

                if ( $dry_run ) {
                    $would_create[] = array(
                        'session_code' => $row_code,
                        'short_name'   => $short_name,
                    );
                    continue;
                }

                // Create the session
                $session_key = substr( bin2hex( random_bytes( 4 ) ), 0, 8 );

                $wpdb->insert(
                    $wpdb->prefix . 'wssp_sessions',
                    array(
                        'session_code'      => $row_code,
                        'session_key'       => $session_key,
                        'short_name'        => $short_name,
                        'event_type'        => 'satellite',
                        'rollup_status'     => 'not_started',
                        'smartsheet_row_id' => (string) ( $row['id'] ?? '' ),
                    ),
                    array( '%s', '%s', '%s', '%s', '%s', '%s' )
                );

                $new_id = $wpdb->insert_id;
                if ( ! $new_id ) continue;

                // Audit log: session auto-created
                if ( $this->audit ) {
                    $this->audit->log( array(
                        'session_id'  => $new_id,
                        'event_type'  => 'satellite',
                        'action'      => 'session_created',
                        'source'      => 'smartsheet',
                        'entity_type' => 'session',
                        'entity_id'   => $new_id,
                        'new_value'   => $row_code,
                        'meta'        => array(
                            'trigger'    => 'pull_all_auto_create',
                            'short_name' => $short_name,
                        ),
                    ));
                }

                $session = array(
                    'id'                => $new_id,
                    'session_code'      => $row_code,
                    'session_key'       => $session_key,
                    'short_name'        => $short_name,
                    'event_type'        => 'satellite',
                    'smartsheet_row_id' => (string) ( $row['id'] ?? '' ),
                );
                $sessions_by_code[ $row_code ] = $session;
                $created_count++;
            }

            $session    = $sessions_by_code[ $row_code ];
            $session_id = $session['id'];

            // Store row ID
            if ( ! $dry_run && ! empty( $row['id'] ) ) {
                $wpdb->update(
                    $wpdb->prefix . 'wssp_sessions',
                    array( 'smartsheet_row_id' => (string) $row['id'] ),
                    array( 'id' => $session_id ),
                    array( '%s' ),
                    array( '%d' )
                );
            }

            // Build cell values
            $cell_values = array();
            foreach ( $row['cells'] as $cell ) {
                $cell_values[ $cell['columnId'] ] = $cell;
            }

            // Compute diff
            $diff_result = $this->compute_pull_diff( $session_id, $session, $columns, $cell_values );

            if ( $dry_run ) {
                $results[] = array(
                    'session_code'   => $session['session_code'],
                    'changes'        => count( $diff_result['diff'] ),
                    'skipped'        => $diff_result['skipped'],
                    'skipped_fields' => $diff_result['skipped_fields'],
                    'diff'           => $diff_result['diff'],
                );
                continue;
            }

            // Apply changes
            $count = $this->apply_pull_changes( $session_id, $session, $diff_result );

            $results[] = array(
                'session_code' => $session['session_code'],
                'updated'      => $count,
                'skipped'      => $diff_result['skipped'],
            );
        }

        if ( $dry_run ) {
            $total_changes = array_sum( array_column( $results, 'changes' ) );
            return array(
                'success'      => true,
                'dry_run'      => true,
                'message'      => "{$total_changes} field(s) would change across " . count( $results ) . ' sessions.',
                'results'      => $results,
                'would_create' => $would_create,
                'created'      => 0,
            );
        }

        $msg = 'Synced ' . count( $results ) . ' sessions.';
        if ( $created_count > 0 ) {
            $msg .= " Created {$created_count} new session(s).";
        }

        return array(
            'success'      => true,
            'dry_run'      => false,
            'message'      => $msg,
            'results'      => $results,
            'created'      => $created_count,
            'would_create' => array(),
        );
    }

    /* ───────────────────────────────────────────
     * PUSH: Portal → Smartsheet
     * ─────────────────────────────────────────── */

    /**
     * Push data to Smartsheet for a session.
     *
     * Two modes:
     *
     *   'changed_keys' — auto-push after a sponsor form save.
     *      Only pushes portal_keys whose underlying Formidable field was in
     *      the provided $args['changed_field_keys'] list. Trusts the
     *      Formidable diff; no GET to Smartsheet.  Used to scope each save
     *      to exactly what the sponsor touched, so untouched SS cells
     *      (entered by logistics) are never overwritten.
     *
     *   'diff_with_ss' — manual admin push from the session-manager page.
     *      Fetches the current SS row, compares every 'both'-direction
     *      field's portal value against the current SS cell, and proposes
     *      the diff. Empty-value protection: if portal is blank but SS has
     *      a value, the field is SKIPPED (A2 semantics) — the admin sees
     *      it listed as protected in the preview but the SS cell is not
     *      touched.  Use this mode when the admin corrected a logistics
     *      field in the portal and wants it pushed back to SS.
     *
     * @param int   $session_id
     * @param bool  $dry_run   If true, compute the diff and return; don't PUT.
     * @param array $args {
     *     @type string $mode                Required: 'changed_keys' or 'diff_with_ss'.
     *                                       Legacy callers with no $args default to 'diff_with_ss'.
     *     @type array  $changed_field_keys  For 'changed_keys' mode: Formidable
     *                                       field keys that actually changed in
     *                                       this save.
     *     @type array  $changed_meta_keys   For 'changed_keys' mode: meta keys
     *                                       that were written during this save
     *                                       (e.g. by task-behavior latch triggers).
     *                                       Either or both lists may be empty.
     * }
     * @return array {
     *     @type bool   $success
     *     @type string $message
     *     @type bool   $dry_run
     *     @type array  $diff          Field-level changes to push.
     *     @type array  $skipped_fields Fields that were intentionally not pushed
     *                                  (e.g. portal empty while SS has value).
     *     @type int    $field_count   Number of cells sent (0 in dry_run).
     *     @type string $row_id
     *     @type string $mode          The mode used.
     * }
     */
    public function push_session( $session_id, $dry_run = false, $args = array() ) {
        if ( empty( $this->api_token ) || empty( $this->field_map ) ) {
            return array( 'success' => false, 'message' => 'Not configured.' );
        }

        $mode = $args['mode'] ?? 'diff_with_ss';
        if ( ! in_array( $mode, array( 'changed_keys', 'diff_with_ss' ), true ) ) {
            return array( 'success' => false, 'message' => "Invalid push mode: {$mode}" );
        }

        global $wpdb;
        $session = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}wssp_sessions WHERE id = %d",
            $session_id
        ), ARRAY_A );

        if ( ! $session ) {
            return array( 'success' => false, 'message' => 'Session not found.' );
        }

        $row_id   = $session['smartsheet_row_id'] ?? '';
        $sheet_id = $this->field_map['sheet_id'] ?? '';

        // Resolve row ID if not yet linked (link it via a dry-run pull).
        if ( ! $row_id ) {
            $pull_result = $this->pull_session( $session_id, true );
            $row_id = $pull_result['row_id'] ?? '';

            if ( $row_id ) {
                $wpdb->update(
                    $wpdb->prefix . 'wssp_sessions',
                    array( 'smartsheet_row_id' => (string) $row_id ),
                    array( 'id' => $session_id ),
                    array( '%s' ),
                    array( '%d' )
                );
                $session['smartsheet_row_id'] = (string) $row_id;
            } else {
                return array( 'success' => false, 'message' => 'No Smartsheet row ID. Pull first to link the row.' );
            }
        }

        // Dispatch to the right diff-building path.
        if ( $mode === 'changed_keys' ) {
            $changed_field_keys = $args['changed_field_keys'] ?? array();
            $changed_meta_keys  = $args['changed_meta_keys']  ?? array();
            $prepared = $this->compute_push_cells_changed_keys(
                $session_id, $session, $changed_field_keys, $changed_meta_keys
            );
        } else {
            $prepared = $this->compute_push_cells_diff_with_ss( $session_id, $session, $sheet_id, $row_id );
            if ( is_wp_error( $prepared ) ) {
                return array( 'success' => false, 'message' => 'API error: ' . $prepared->get_error_message() );
            }
        }

        $cells          = $prepared['cells'];
        $diff           = $prepared['diff'];
        $skipped_fields = $prepared['skipped_fields'];
        $pushed_fields  = array_column( $diff, 'field' );

        if ( empty( $cells ) ) {
            $msg = empty( $skipped_fields )
                ? 'No fields to push.'
                : sprintf( 'No fields to push (%d skipped by empty-value protection).', count( $skipped_fields ) );
            return array(
                'success'        => true,
                'dry_run'        => $dry_run,
                'message'        => $msg,
                'field_count'    => 0,
                'diff'           => array(),
                'skipped_fields' => $skipped_fields,
                'row_id'         => $row_id,
                'mode'           => $mode,
            );
        }

        // Dry run: return diff without PUTting.
        if ( $dry_run ) {
            $msg = sprintf( '%d field(s) would be pushed to Smartsheet.', count( $cells ) );
            if ( ! empty( $skipped_fields ) ) {
                $msg .= sprintf( ' %d skipped (empty-value protection).', count( $skipped_fields ) );
            }
            return array(
                'success'        => true,
                'dry_run'        => true,
                'message'        => $msg,
                'field_count'    => count( $cells ),
                'diff'           => $diff,
                'skipped_fields' => $skipped_fields,
                'row_id'         => $row_id,
                'mode'           => $mode,
            );
        }

        // Commit.
        return $this->commit_push( $session, $session_id, $sheet_id, $row_id, $cells, $diff, $pushed_fields, $skipped_fields, $mode );
    }

    /**
     * Build the push diff for auto-push after a sponsor form save.
     *
     * Considers two sources, scoped by what actually changed:
     *
     *   1. 'push' direction + 'formidable' store: portal_key must appear in
     *      $changed_field_keys (Formidable fields the sponsor edited).
     *   2. 'push' direction + 'meta' store: portal_key must appear in
     *      $changed_meta_keys (meta fields flipped by task-behavior triggers
     *      during this same save, e.g. av_request_submitted latch).
     *
     * No GET to SS — we trust the Formidable before/after diff and the
     * task-behavior trigger output.
     *
     * 'both'-direction fields are EXCLUDED — those are logistics-owned and
     * are only pushed via the manual admin flow (diff_with_ss mode).
     *
     * @param int   $session_id
     * @param array $session
     * @param array $changed_field_keys List of Formidable field_keys that changed.
     * @param array $changed_meta_keys  List of meta keys written during this save.
     * @return array { cells, diff, skipped_fields }
     */
    private function compute_push_cells_changed_keys( $session_id, $session, $changed_field_keys, $changed_meta_keys = array() ) {
        $cells = array();
        $diff  = array();

        if ( empty( $changed_field_keys ) && empty( $changed_meta_keys ) ) {
            return array( 'cells' => $cells, 'diff' => $diff, 'skipped_fields' => array() );
        }

        $changed_field_set = array_fill_keys( $changed_field_keys, true );
        $changed_meta_set  = array_fill_keys( $changed_meta_keys, true );
        $columns           = $this->field_map['columns'] ?? array();

        // Preload meta once if we have any meta-backed push fields to consider.
        $meta = null;
        if ( ! empty( $changed_meta_set ) ) {
            $meta = $this->session_meta->get_all( $session_id );
        }

        foreach ( $columns as $mapping ) {
            // Direction filter:
            //   'push' + 'formidable' store → sponsor-edited field, scoped by changed_field_keys
            //   'push' + 'meta' store        → task-behavior meta trigger, scoped by changed_meta_keys
            //   'both' + 'meta' store        → add-on request latch, scoped by changed_meta_keys.
            //       'both' is also read by manual admin push (diff_with_ss),
            //       which is safe because only meta keys that a trigger
            //       explicitly wrote this save appear in changed_meta_keys —
            //       logistics-owned 'both' fields like session_location never
            //       get into that set via auto-push, so we never touch them here.
            //   anything else                 → not auto-push's job
            $direction = $mapping['direction'] ?? '';
            if ( ! in_array( $direction, array( 'push', 'both' ), true ) ) continue;

            $store      = $mapping['portal_store'] ?? '';
            $portal_key = $mapping['portal_key'] ?? '';
            if ( ! $portal_key ) continue;

            $value  = null;
            $reason = '';

            if ( $direction === 'push' && $store === 'formidable' ) {
                if ( empty( $changed_field_set[ $portal_key ] ) ) continue;

                // Derived portal keys (concatenations of repeater child rows)
                // can't be read via FrmField::get_id_by_key() because they're
                // not Formidable fields — they're composite values we build
                // by walking child entries. Route them through the Formidable
                // service's snapshot helper.
                if ( $this->formidable && in_array( $portal_key, array(
                    WSSP_Formidable::CONTACTS_PORTAL_KEY,
                    WSSP_Formidable::EMAILS_PORTAL_KEY,
                ), true ) ) {
                    $value = $this->resolve_contacts_derived_value( $session, $portal_key );
                } else {
                    $value = $this->get_formidable_value( $session, $portal_key );
                }
                $reason = 'changed_by_sponsor';
            } elseif ( $store === 'meta' ) {
                // Both 'push' + meta and 'both' + meta are gated by changed_meta_keys.
                if ( empty( $changed_meta_set[ $portal_key ] ) ) continue;
                $value  = $meta[ $portal_key ] ?? '';
                $reason = ( $direction === 'both' ) ? 'addon_latch' : 'meta_trigger';
            } else {
                // direction=both with non-meta store isn't produced by any
                // current trigger — skip defensively.
                continue;
            }

            if ( $value === null ) continue;

            $formatted = $this->format_for_smartsheet( $value, $mapping );

            $cells[] = array(
                'columnId' => $mapping['ss_column_id'],
                'value'    => $formatted,
            );
            $diff[] = array(
                'field'     => $portal_key,
                'value'     => $value,
                'ss_value'  => $formatted,
                'ss_before' => null, // auto-push does not fetch current SS
                'ss_title'  => $mapping['ss_title'] ?? $portal_key,
                'store'     => $store,
                'type'      => $mapping['type'] ?? 'text',
                'reason'    => $reason,
            );
        }

        return array( 'cells' => $cells, 'diff' => $diff, 'skipped_fields' => array() );
    }

    /**
     * Build the push diff for a manual admin push.
     *
     * Fetches the current SS row, iterates every 'both'-direction field,
     * and proposes cells where portal value differs from the current SS cell.
     *
     * Empty-value protection (A2 semantics): if the portal value is empty
     * but the SS cell has a value, the field is reported in skipped_fields
     * and NOT pushed. This protects logistics' SS edits from being wiped by
     * a stale (blank) portal value.
     *
     * Pure 'push' fields are intentionally excluded here — those are the
     * sponsor's Formidable data and must only flow through auto-push so
     * they're scoped to what actually changed.
     *
     * @param int    $session_id
     * @param array  $session
     * @param string $sheet_id
     * @param string $row_id
     * @return array|WP_Error { cells, diff, skipped_fields }
     */
    private function compute_push_cells_diff_with_ss( $session_id, $session, $sheet_id, $row_id ) {
        // Fetch the current SS row so we can compare per-field.
        $row_data = $this->api_get( "/sheets/{$sheet_id}/rows/{$row_id}" );
        if ( is_wp_error( $row_data ) ) {
            return $row_data;
        }

        $ss_cells_by_col = array();
        foreach ( $row_data['cells'] ?? array() as $cell ) {
            $ss_cells_by_col[ $cell['columnId'] ] = $cell;
        }

        $meta    = $this->session_meta->get_all( $session_id );
        $columns = $this->field_map['columns'] ?? array();

        $cells          = array();
        $diff           = array();
        $skipped_fields = array();

        foreach ( $columns as $mapping ) {
            if ( ( $mapping['direction'] ?? '' ) !== 'both' ) continue;
            if ( ( $mapping['portal_store'] ?? '' ) === 'skip' ) continue;

            $portal_key = $mapping['portal_key'] ?? '';
            if ( ! $portal_key ) continue;

            // Read portal value from the appropriate store.
            $portal_value = '';
            if ( $mapping['portal_store'] === 'meta' ) {
                $portal_value = $meta[ $portal_key ] ?? '';
            } elseif ( $mapping['portal_store'] === 'session' ) {
                $portal_value = $session[ $portal_key ] ?? '';
            }
            // 'formidable' store is never 'both' in our current map, but if
            // it ever is, we'd read it here; skipping for now for clarity.

            $portal_str = is_array( $portal_value ) ? wp_json_encode( $portal_value ) : (string) $portal_value;
            $portal_str = trim( $portal_str );

            // Normalize portal dates to Y-m-d so comparison matches extract_cell_value().
            if ( ( $mapping['type'] ?? '' ) === 'date' && $portal_str !== '' ) {
                $ts = strtotime( $portal_str );
                if ( $ts ) {
                    $portal_str = date( 'Y-m-d', $ts );
                }
            }

            // Normalize picklist values through the value_map the same way pull does.
            if ( ( $mapping['type'] ?? '' ) === 'checkbox' ) {
                // Portal stores 'yes' / '' — match the 'yes' / '' shape that extract_cell_value returns.
                $portal_str = in_array( strtolower( $portal_str ), array( 'yes', '1', 'true' ), true ) ? 'yes' : '';
            }

            // Extract current SS value using the same conversion used for pull,
            // so the comparison is apples-to-apples (portal format vs portal format).
            $ss_cell = $ss_cells_by_col[ $mapping['ss_column_id'] ] ?? null;
            $ss_portal_equiv = $this->extract_cell_value( $ss_cell, $mapping );
            $ss_portal_equiv_str = trim( (string) $ss_portal_equiv );

            // No change — skip silently.
            if ( $portal_str === $ss_portal_equiv_str ) {
                continue;
            }

            // Empty-value protection (A2): portal is empty but SS has a value.
            if ( $portal_str === '' && $ss_portal_equiv_str !== '' ) {
                $skipped_fields[] = array(
                    'field'           => $portal_key,
                    'ss_title'        => $mapping['ss_title'] ?? $portal_key,
                    'store'           => $mapping['portal_store'],
                    'ss_value'        => $ss_portal_equiv,
                    'reason'          => 'portal_empty_ss_has_value',
                );
                continue;
            }

            // Real diff — push.
            $formatted = $this->format_for_smartsheet( $portal_value, $mapping );
            $cells[] = array(
                'columnId' => $mapping['ss_column_id'],
                'value'    => $formatted,
            );
            $diff[] = array(
                'field'     => $portal_key,
                'value'     => $portal_value,
                'ss_value'  => $formatted,
                'ss_before' => $ss_portal_equiv, // what SS currently holds, in portal format
                'ss_title'  => $mapping['ss_title'] ?? $portal_key,
                'store'     => $mapping['portal_store'],
                'type'      => $mapping['type'] ?? 'text',
                'reason'    => 'portal_differs_from_ss',
            );
        }

        return array( 'cells' => $cells, 'diff' => $diff, 'skipped_fields' => $skipped_fields );
    }

    /**
     * Perform the PUT and write the audit log.
     *
     * Shared by both push modes so the PUT + audit logic lives in one place.
     *
     * @return array Result array matching push_session()'s contract.
     */
    private function commit_push( $session, $session_id, $sheet_id, $row_id, $cells, $diff, $pushed_fields, $skipped_fields, $mode ) {
        // Smartsheet row IDs are 18-19 digit integers that can exceed 32-bit int
        // range. Cast to (int) would silently truncate on 32-bit PHP and would
        // yield 0 on an empty string, producing a confusing "row.id is missing"
        // error from the API. Validate the stored string and pass it through —
        // Smartsheet accepts numeric string IDs ("id": "6572427401553796").
        $row_id_str = trim( (string) $row_id );
        if ( $row_id_str === '' || ! ctype_digit( $row_id_str ) ) {
            return array(
                'success' => false,
                'message' => 'Missing or invalid Smartsheet row ID for this session. Pull from Smartsheet first to link the row.',
            );
        }

        // PUT /sheets/{id}/rows expects a BARE JSON ARRAY of row objects as the
        // body — NOT an object wrapped in a "rows" key. Wrapping it causes the
        // API to treat the whole body as a single row object and report
        // "Required object attribute(s) are missing from your request: row.id"
        // because the top-level "rows" key doesn't match any row field.
        // Reference: https://developers.smartsheet.com/api/smartsheet/openapi/rows
        $row_payload = array(
            array(
                'id'    => $row_id_str,
                'cells' => $cells,
            ),
        );

        $result = $this->api_put( "/sheets/{$sheet_id}/rows", $row_payload );

        if ( is_wp_error( $result ) ) {
            return array( 'success' => false, 'message' => 'API error: ' . $result->get_error_message() );
        }

        // Per-field audit log — one entry per pushed field, with old (SS before) and new (pushed) values.
        if ( $this->audit ) {
            foreach ( $diff as $change ) {
                $old_value = $change['ss_before'];
                if ( $old_value === null ) {
                    // changed_keys mode doesn't fetch SS; record what we sent only.
                    $old_value = '';
                }
                $this->audit->log( array(
                    'session_id'  => $session_id,
                    'event_type'  => $session['event_type'] ?? 'satellite',
                    'action'      => 'field_edit',
                    'source'      => 'smartsheet',
                    'entity_type' => $change['store'] === 'session' ? 'session' : 'meta',
                    'entity_id'   => (string) $session_id,
                    'field_name'  => $change['field'],
                    'old_value'   => is_array( $old_value ) ? wp_json_encode( $old_value ) : (string) $old_value,
                    'new_value'   => is_array( $change['value'] ) ? wp_json_encode( $change['value'] ) : (string) $change['value'],
                    'meta'        => array(
                        'trigger'  => 'smartsheet_push',
                        'mode'     => $mode,
                        'ss_title' => $change['ss_title'],
                        'reason'   => $change['reason'] ?? '',
                        'row_id'   => $row_id,
                    ),
                ));
            }

            // Roll-up entry so a single push is still one-line-findable in the log.
            $this->audit->log( array(
                'session_id'  => $session_id,
                'event_type'  => $session['event_type'] ?? 'satellite',
                'action'      => 'smartsheet_push',
                'source'      => 'smartsheet',
                'entity_type' => 'session',
                'entity_id'   => $session_id,
                'new_value'   => wp_json_encode( $pushed_fields ),
                'meta'        => array(
                    'fields_pushed' => count( $cells ),
                    'skipped'       => count( $skipped_fields ),
                    'mode'          => $mode,
                    'row_id'        => $row_id,
                ),
            ));
        }

        $msg = sprintf( 'Pushed %d field(s) to Smartsheet.', count( $cells ) );
        if ( ! empty( $skipped_fields ) ) {
            $msg .= sprintf( ' %d skipped (empty-value protection).', count( $skipped_fields ) );
        }

        return array(
            'success'        => true,
            'dry_run'        => false,
            'message'        => $msg,
            'field_count'    => count( $cells ),
            'diff'           => $diff,
            'skipped_fields' => $skipped_fields,
            'row_id'         => $row_id,
            'mode'           => $mode,
        );
    }

    /* ───────────────────────────────────────────
     * DIFF + APPLY — Core pull logic
     * ─────────────────────────────────────────── */

    /**
     * Compute a field-level diff between Smartsheet values and portal state.
     *
     * Returns the proposed changes without writing anything.
     *
     * Empty value protection is scoped to 'both' direction fields only.
     * For pure 'pull' fields, Smartsheet is the source of truth — if
     * logistics clears a value there, the portal should reflect that.
     * For 'both' direction fields (editable in either system), a blank
     * Smartsheet cell won't overwrite a non-empty portal value.
     *
     * @param int   $session_id
     * @param array $session       Session row from DB.
     * @param array $columns       Field mapping columns.
     * @param array $cell_values   column_id => cell data from Smartsheet.
     * @return array {
     *     @type array $diff           Changes to apply: [ { field, old, new, store, ss_title } ]
     *     @type array $meta_updates   meta key => new value (only changed fields).
     *     @type array $session_updates session column => new value (only changed fields).
     *     @type int   $skipped        Number of fields skipped by empty protection.
     * }
     */
    private function compute_pull_diff( $session_id, $session, $columns, $cell_values ) {
        // Load current meta values for comparison
        $current_meta = $this->session_meta->get_all( $session_id );

        $diff            = array();
        $meta_updates    = array();
        $session_updates = array();
        $skipped         = 0;
        $skipped_fields  = array();
        $raw_values      = array(); // Debug: capture every pull-direction field's raw + mapped values

        foreach ( $columns as $mapping ) {
            if ( ! in_array( $mapping['direction'], array( 'pull', 'both' ), true ) ) {
                continue;
            }
            if ( $mapping['portal_store'] === 'skip' ) {
                continue;
            }

            $col_id    = $mapping['ss_column_id'];
            $cell      = $cell_values[ $col_id ] ?? null;
            $new_value = $this->extract_cell_value( $cell, $mapping );
            $key       = $mapping['portal_key'];
            $store     = $mapping['portal_store'];
            $ss_title  = $mapping['ss_title'] ?? $key;

            // Get current portal value
            $old_value = '';
            if ( $store === 'meta' ) {
                $old_value = (string) ( $current_meta[ $key ] ?? '' );
            } elseif ( $store === 'session' ) {
                $old_value = (string) ( $session[ $key ] ?? '' );
            }

            // Capture raw API data for debugging
            $raw_values[] = array(
                'field'        => $key,
                'ss_title'     => $ss_title,
                'type'         => $mapping['type'] ?? 'text',
                'raw_value'    => $cell['value'] ?? null,
                'display_value' => $cell['displayValue'] ?? null,
                'mapped_value' => $new_value,
                'portal_value' => $old_value,
                'outcome'      => '', // filled below
            );
            $raw_idx = count( $raw_values ) - 1;

            // ─── Empty value protection ───
            // Only applies to 'both' direction fields where either system
            // may have the newer value.  For pure 'pull' fields, Smartsheet
            // is the source of truth — if logistics clears a value there,
            // the portal should reflect that.
            if ( $new_value === '' && $old_value !== '' && $mapping['direction'] === 'both' ) {
                $skipped++;
                $skipped_fields[] = array(
                    'field'          => $key,
                    'ss_title'       => $ss_title,
                    'protected_value' => $old_value,
                    'direction'      => $mapping['direction'],
                );
                $raw_values[ $raw_idx ]['outcome'] = 'skipped (empty protection — both direction)';
                continue;
            }

            // Skip if no actual change
            if ( (string) $new_value === $old_value ) {
                $raw_values[ $raw_idx ]['outcome'] = 'no change';
                continue;
            }

            $raw_values[ $raw_idx ]['outcome'] = 'changed';

            $diff[] = array(
                'field'    => $key,
                'old'      => $old_value,
                'new'      => $new_value,
                'store'    => $store,
                'ss_title' => $ss_title,
            );

            if ( $store === 'meta' ) {
                $meta_updates[ $key ] = $new_value;
            } elseif ( $store === 'session' ) {
                $session_updates[ $key ] = $new_value;
            }
        }

        return array(
            'diff'            => $diff,
            'meta_updates'    => $meta_updates,
            'session_updates' => $session_updates,
            'skipped'         => $skipped,
            'skipped_fields'  => $skipped_fields,
            'raw_values'      => $raw_values,
        );
    }

    /**
     * Apply pull changes to the portal and log each field change.
     *
     * @param int   $session_id
     * @param array $session      Session row.
     * @param array $diff_result  Output from compute_pull_diff().
     * @return int  Number of fields updated.
     */
    private function apply_pull_changes( $session_id, $session, $diff_result ) {
        global $wpdb;
        $updated = 0;

        // Write meta
        if ( ! empty( $diff_result['meta_updates'] ) ) {
            $updated += $this->session_meta->update_many( $session_id, $diff_result['meta_updates'] );
        }

        // Write session table fields
        if ( ! empty( $diff_result['session_updates'] ) ) {
            $wpdb->update(
                $wpdb->prefix . 'wssp_sessions',
                $diff_result['session_updates'],
                array( 'id' => $session_id )
            );
            $updated += count( $diff_result['session_updates'] );
        }

        // ─── Addon task status sync: imported-affirmative → complete row ───
        $this->sync_addon_task_statuses( $session_id, $session, $diff_result );

        // ─── Addon blank-pull carve-out: blank SS cell → clear latched addon ───
        // Intentionally bypasses the empty-protection skip in compute_pull_diff()
        // for addon fields only. See method docblock for rationale.
        $this->clear_addons_on_blank_pull( $session_id, $session, $diff_result );

        // ─── Audit log: one entry per changed field ───
        if ( $this->audit && ! empty( $diff_result['diff'] ) ) {
            foreach ( $diff_result['diff'] as $change ) {
                $this->audit->log( array(
                    'session_id'  => $session_id,
                    'event_type'  => $session['event_type'] ?? 'satellite',
                    'action'      => 'field_edit',
                    'source'      => 'smartsheet',
                    'entity_type' => $change['store'] === 'session' ? 'session' : 'meta',
                    'entity_id'   => (string) $session_id,
                    'field_name'  => $change['field'],
                    'old_value'   => $change['old'],
                    'new_value'   => $change['new'],
                    'meta'        => array(
                        'trigger'  => 'smartsheet_pull',
                        'ss_title' => $change['ss_title'],
                    ),
                ));
            }
        }

        return $updated;
    }

    /**
     * Sync wssp_task_status rows for addon tasks whose meta was just
     * written by a Smartsheet pull.
     *
     * For each changed meta entry that corresponds to an `addon_{slug}`
     * key whose slug matches a registered addon task:
     *   - affirmative value ('yes' / '1' / 'true') AND task is not
     *     already in a terminal status → write 'complete' with
     *     submitted_by=0 (system marker).
     *   - any other value → no-op here (declines don't originate from
     *     SS, and blanks are handled by clear_addons_on_blank_pull).
     *
     * System-completed rows use submitted_by=0 so the reactivate path
     * can distinguish them from sponsor-completed rows.
     *
     * @param int   $session_id
     * @param array $session      Session row.
     * @param array $diff_result  Output from compute_pull_diff().
     * @return void
     */
    private function sync_addon_task_statuses( $session_id, $session, $diff_result ) {
        if ( ! $this->dashboard || empty( $diff_result['meta_updates'] ) ) {
            return;
        }

        $event_type = $session['event_type'] ?? 'satellite';
        $addons     = $this->config->get_addons( $event_type );
        if ( empty( $addons ) ) {
            return;
        }

        // Build lookup: 'addon_{slug}' meta key → task_key.
        $meta_key_to_task = array();
        foreach ( $addons as $addon_slug => $addon ) {
            if ( ! empty( $addon['task_key'] ) ) {
                $meta_key_to_task[ 'addon_' . $addon_slug ] = $addon['task_key'];
            }
        }

        foreach ( $diff_result['meta_updates'] as $meta_key => $new_value ) {
            $task_key = $meta_key_to_task[ $meta_key ] ?? null;
            if ( ! $task_key ) continue;

            $normalized = strtolower( trim( (string) $new_value ) );
            $is_affirmative = in_array( $normalized, array( 'yes', '1', 'true' ), true );
            if ( ! $is_affirmative ) {
                continue; // declines don't occur on import; blanks handled separately.
            }

            // Only write if task isn't already terminal. Never downgrade
            // a sponsor-completed or approved row.
            $current = $this->dashboard->get_task_status( $session_id, $task_key );
            if ( in_array( $current, array( 'complete', 'approved' ), true ) ) {
                continue;
            }

            $this->dashboard->set_task_status( $session_id, $task_key, 'complete', array(
                'submitted_by' => 0,
            ) );

            // Supplementary audit entry tagging this completion as
            // Smartsheet-sourced. set_task_status() logs a generic
            // status_change entry using the current user; this extra
            // entry preserves the provenance so admins reviewing the
            // trail can see the completion came from a pull, not a
            // manual action.
            if ( $this->audit ) {
                $this->audit->log( array(
                    'session_id'  => $session_id,
                    'event_type'  => $event_type,
                    'action'      => 'status_change',
                    'source'      => 'smartsheet',
                    'entity_type' => 'task',
                    'entity_id'   => $task_key,
                    'field_name'  => 'status',
                    'old_value'   => $current,
                    'new_value'   => 'complete',
                    'meta'        => array(
                        'trigger'  => 'addon_imported_from_smartsheet',
                        'meta_key' => $meta_key,
                    ),
                ) );
            }
        }
    }

    /**
     * Clear addon meta + status when a Smartsheet pull returns a blank
     * cell for an addon field that the portal had latched.
     *
     * Scoped carve-out around the empty-protection guard in
     * compute_pull_diff(). Addon fields are mapped direction='both'
     * (because the sponsor's form latch pushes them to SS), which means
     * the default pull path protects them from blank overwrites. For
     * addons specifically we want SS to be authoritative: a cleared
     * cell means "no longer latched."
     *
     * Reads $diff_result['raw_values'] to see every pull-direction
     * field with its mapped_value and portal_value, including the ones
     * that were skipped by empty protection.
     *
     * @param int   $session_id
     * @param array $session      Session row.
     * @param array $diff_result  Output from compute_pull_diff().
     * @return void
     */
    private function clear_addons_on_blank_pull( $session_id, $session, $diff_result ) {
        if ( ! $this->dashboard || empty( $diff_result['raw_values'] ) ) {
            return;
        }

        $event_type = $session['event_type'] ?? 'satellite';
        $addons     = $this->config->get_addons( $event_type );
        if ( empty( $addons ) ) {
            return;
        }

        // Build lookup: 'addon_{slug}' meta key → task_key.
        $meta_key_to_task = array();
        foreach ( $addons as $addon_slug => $addon ) {
            if ( ! empty( $addon['task_key'] ) ) {
                $meta_key_to_task[ 'addon_' . $addon_slug ] = $addon['task_key'];
            }
        }

        global $wpdb;
        $status_table = $wpdb->prefix . 'wssp_task_status';
        $meta_table   = $wpdb->prefix . 'wssp_session_meta';

        foreach ( $diff_result['raw_values'] as $raw ) {
            $meta_key = $raw['field'] ?? '';
            $task_key = $meta_key_to_task[ $meta_key ] ?? null;
            if ( ! $task_key ) continue;

            $ss_empty       = ( $raw['mapped_value'] === '' || $raw['mapped_value'] === null );
            $portal_latched = ( $raw['portal_value'] !== '' && $raw['portal_value'] !== null );
            if ( ! ( $ss_empty && $portal_latched ) ) {
                continue;
            }

            // Delete meta row.
            $wpdb->delete(
                $meta_table,
                array( 'session_id' => $session_id, 'meta_key' => $meta_key ),
                array( '%d', '%s' )
            );

            // Delete status row (if any).
            $wpdb->delete(
                $status_table,
                array( 'session_id' => $session_id, 'task_key' => $task_key ),
                array( '%d', '%s' )
            );

            // Audit entry — deliberately loud so a disappeared addon is
            // traceable back to the exact Smartsheet pull that cleared it.
            if ( $this->audit ) {
                $this->audit->log( array(
                    'session_id'  => $session_id,
                    'event_type'  => $event_type,
                    'action'      => 'status_change',
                    'source'      => 'smartsheet',
                    'entity_type' => 'task',
                    'entity_id'   => $task_key,
                    'field_name'  => 'status',
                    'old_value'   => (string) $raw['portal_value'],
                    'new_value'   => 'not_started',
                    'meta'        => array(
                        'trigger'  => 'addon_cleared_by_smartsheet_blank',
                        'meta_key' => $meta_key,
                        'ss_title' => $raw['ss_title'] ?? '',
                    ),
                ) );
            }

            $this->dashboard->update_rollup_status( $session_id );
        }
    }

    /**
     * Extract the short_name value from a Smartsheet row.
     *
     * Used when auto-creating sessions from unmatched rows.
     *
     * @param array $row     Smartsheet row data.
     * @param array $columns Field mapping columns.
     * @return string
     */
    private function extract_short_name_from_row( $row, $columns ) {
        $sponsor_col_id = null;
        foreach ( $columns as $mapping ) {
            if ( $mapping['portal_key'] === 'short_name' && $mapping['portal_store'] === 'session' ) {
                $sponsor_col_id = $mapping['ss_column_id'];
                break;
            }
        }

        if ( ! $sponsor_col_id ) return '';

        foreach ( $row['cells'] as $cell ) {
            if ( $cell['columnId'] == $sponsor_col_id ) {
                return trim( $cell['displayValue'] ?? $cell['value'] ?? '' );
            }
        }

        return '';
    }

    /* ───────────────────────────────────────────
     * VALUE CONVERSION
     * ─────────────────────────────────────────── */

    /**
     * Extract a portal-friendly value from a Smartsheet cell.
     */
    private function extract_cell_value( $cell, $mapping ) {
        if ( ! $cell ) return '';

        $type  = $mapping['type'] ?? 'text';
        $value = $cell['displayValue'] ?? $cell['value'] ?? '';

        switch ( $type ) {
            case 'checkbox':
                $raw = $cell['value'] ?? false;
                return $raw ? 'yes' : '';

            case 'picklist':
                $value_str = (string) $value;

                // Empty cell → empty portal value, no lookup needed.
                if ( trim( $value_str ) === '' ) {
                    return '';
                }

                if ( ! empty( $mapping['value_map'] ) ) {
                    // Try direct match first (fast path, exact case).
                    if ( isset( $mapping['value_map'][ $value_str ] ) ) {
                        return $mapping['value_map'][ $value_str ];
                    }
                    // Fall back to case-insensitive match. Avoids silent
                    // failures when SS uses 'Yes' but someone typed the
                    // value_map key as 'yes' (or vice versa).
                    $needle = strtolower( trim( $value_str ) );
                    foreach ( $mapping['value_map'] as $ss_label => $portal_val ) {
                        if ( strtolower( trim( (string) $ss_label ) ) === $needle ) {
                            return $portal_val;
                        }
                    }

                    // Unmapped picklist value: the SS column has a value
                    // that isn't in our config's value_map. Log a warning
                    // so this can be fixed, and return empty rather than
                    // storing the raw string (which would cause case-
                    // sensitivity mismatches downstream like 'Yes' vs 'yes').
                    error_log( sprintf(
                        'WSSP: Unmapped picklist value "%s" for column "%s" (portal_key=%s). Update value_map in smartsheet-field-map.php.',
                        $value_str,
                        $mapping['ss_title'] ?? '(unknown)',
                        $mapping['portal_key'] ?? '(unknown)'
                    ) );
                    return '';
                }

                // No value_map configured → return raw string as before.
                return $value_str;

            case 'date':
                $value = trim( (string) $value );
                if ( empty( $value ) ) return '';
                $ts = strtotime( $value );
                return $ts ? date( 'Y-m-d', $ts ) : (string) $value;

            default:
                return (string) $value;
        }
    }

    /**
     * Format a portal value for Smartsheet.
     */
    private function format_for_smartsheet( $value, $mapping ) {
        $type = $mapping['type'] ?? 'text';

        switch ( $type ) {
            case 'checkbox':
                // Portal values for push-to-checkbox can arrive in several shapes:
                //   - bool true/false or 1/0 (meta written programmatically)
                //   - 'yes'/'' (meta written by latch triggers)
                //   - 'Yes'/'No' (Formidable radio buttons, case varies)
                //   - '1'/'0' or ['Yes'] arrays (Formidable checkbox fields)
                // Normalize all truthy shapes to boolean true; everything
                // else (including 'no', '0', '', null, 'decline') to false.
                if ( is_array( $value ) ) {
                    // Multi-value field (e.g. checkbox group). Treat as checked
                    // if at least one truthy selection is present.
                    foreach ( $value as $v ) {
                        if ( $this->is_truthy_checkbox_value( $v ) ) return true;
                    }
                    return false;
                }
                return $this->is_truthy_checkbox_value( $value );

            case 'picklist':
                // Prefer an explicit push_value_map when present, for
                // asymmetric maps where multiple SS values share one
                // portal value and we need to pick a specific one on
                // push. No current mapping uses this, but the mechanism
                // stays available.
                if ( ! empty( $mapping['push_value_map'] ) && isset( $mapping['push_value_map'][ $value ] ) ) {
                    return $mapping['push_value_map'][ $value ];
                }
                // Fallback: derive push mapping by flipping value_map.
                // Safe for symmetric 1:1 maps (like add-ons with Yes↔yes,
                // No↔declined and CE status).
                if ( ! empty( $mapping['value_map'] ) ) {
                    $reverse = array_flip( $mapping['value_map'] );
                    if ( isset( $reverse[ $value ] ) ) {
                        return $reverse[ $value ];
                    }
                }
                return (string) $value;

            default:
                return (string) $value;
        }
    }

    /**
     * Is this scalar value an affirmative checkbox/radio signal?
     *
     * Used by the push-side checkbox formatter to tolerate the variety of
     * value shapes that can arrive from Formidable, session meta, and
     * programmatic writes. Case-insensitive.
     *
     * @param mixed $value
     * @return bool
     */
    private function is_truthy_checkbox_value( $value ) {
        if ( $value === true ) return true;
        if ( $value === false || $value === null ) return false;

        $v = strtolower( trim( (string) $value ) );
        if ( $v === '' ) return false;

        // Explicit negatives — always false.
        static $negatives = array( 'no', '0', 'false', 'decline', 'declined', 'not interested', 'off' );
        if ( in_array( $v, $negatives, true ) ) return false;

        // Explicit affirmatives.
        static $affirmatives = array( 'yes', '1', 'true', 'approved', 'on', 'checked' );
        if ( in_array( $v, $affirmatives, true ) ) return true;

        // Anything else — ambiguous; default to false for safety.
        // (Better to leave an SS checkbox unchecked than to check it in error.)
        return false;
    }

    /**
     * Resolve a derived contacts portal_key to a cell value.
     *
     * Looks up the session's Formidable parent entry, asks the Formidable
     * service for the concatenated names/emails of the repeater rows,
     * and returns the slice matching the requested portal_key.
     *
     * @param array  $session     Row from wp_wssp_sessions.
     * @param string $portal_key  Either CONTACTS_PORTAL_KEY or EMAILS_PORTAL_KEY.
     * @return string Comma-joined string (may be empty).
     */
    private function resolve_contacts_derived_value( $session, $portal_key ) {
        if ( ! $this->formidable ) {
            return '';
        }

        $entry_id = (int) ( $session['frm_entry_id'] ?? 0 );
        if ( ! $entry_id ) {
            return '';
        }

        $snapshot = $this->formidable->get_contacts_snapshot( $entry_id );

        if ( $portal_key === WSSP_Formidable::CONTACTS_PORTAL_KEY ) {
            return $snapshot['contacts'] ?? '';
        }
        if ( $portal_key === WSSP_Formidable::EMAILS_PORTAL_KEY ) {
            return $snapshot['emails'] ?? '';
        }
        return '';
    }

    /**
     * Get a Formidable field value for a session.
     */
    private function get_formidable_value( $session, $field_key ) {
        if ( ! class_exists( 'FrmField' ) || ! class_exists( 'FrmEntryMeta' ) ) {
            return '';
        }

        $field_id = FrmField::get_id_by_key( $field_key );
        if ( ! $field_id ) return '';

        $session_key_field_id = FrmField::get_id_by_key( 'wssp_session_key' );
        if ( ! $session_key_field_id ) return '';

        global $wpdb;
        $entry_id = $wpdb->get_var( $wpdb->prepare(
            "SELECT e.id FROM {$wpdb->prefix}frm_items e
             INNER JOIN {$wpdb->prefix}frm_item_metas m ON (e.id = m.item_id)
             WHERE m.field_id = %d AND m.meta_value = %s AND e.is_draft = 0
             ORDER BY e.id DESC LIMIT 1",
            $session_key_field_id,
            $session['session_key']
        ));

        if ( ! $entry_id ) return '';

        return FrmEntryMeta::get_entry_meta_by_field( $entry_id, $field_id ) ?: '';
    }

    /* ───────────────────────────────────────────
     * API HELPERS
     * ─────────────────────────────────────────── */

    private function api_get( $endpoint ) {
        $response = wp_remote_get( $this->api_base . $endpoint, array(
            'headers' => array(
                'Authorization' => 'Bearer ' . $this->api_token,
                'Content-Type'  => 'application/json',
            ),
            'timeout' => 30,
        ));

        return $this->parse_response( $response );
    }

    private function api_put( $endpoint, $rows ) {
        $response = wp_remote_request( $this->api_base . $endpoint, array(
            'method'  => 'PUT',
            'headers' => array(
                'Authorization' => 'Bearer ' . $this->api_token,
                'Content-Type'  => 'application/json',
            ),
            'body'    => wp_json_encode( $rows ),
            'timeout' => 30,
        ));

        return $this->parse_response( $response );
    }

    private function parse_response( $response ) {
        if ( is_wp_error( $response ) ) {
            return $response;
        }

        $code = wp_remote_retrieve_response_code( $response );
        $body = wp_remote_retrieve_body( $response );
        $data = json_decode( $body, true );

        if ( $code >= 400 ) {
            $error_msg = $data['message'] ?? "HTTP {$code}";
            return new WP_Error( 'smartsheet_api_error', $error_msg );
        }

        return $data;
    }

    /* ───────────────────────────────────────────
     * SETTINGS
     * ─────────────────────────────────────────── */

    public function is_configured() {
        return ! empty( $this->api_token ) && ! empty( $this->field_map );
    }

    public static function save_api_token( $token ) {
        update_option( 'wssp_smartsheet_api_token', sanitize_text_field( $token ) );
    }
}