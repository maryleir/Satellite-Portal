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

    /** @var string API base URL. */
    private $api_base = 'https://api.smartsheet.com/2.0';

    /** @var string API token (stored in wp_options). */
    private $api_token;

    /** @var array Field mapping config. */
    private $field_map;

    public function __construct( WSSP_Config $config, WSSP_Session_Meta $session_meta, WSSP_Audit_Log $audit = null ) {
        $this->config       = $config;
        $this->session_meta = $session_meta;
        $this->audit        = $audit;
        $this->api_token    = defined( 'WSSP_SMARTSHEET_TOKEN' ) ? WSSP_SMARTSHEET_TOKEN : '';
        $this->field_map    = $this->config->get_smartsheet_map();
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
     * Push sponsor data to Smartsheet for a session.
     *
     * @param int $session_id
     * @return array  [ 'success' => bool, 'message' => string ]
     */
    public function push_session( $session_id ) {
        if ( empty( $this->api_token ) || empty( $this->field_map ) ) {
            return array( 'success' => false, 'message' => 'Not configured.' );
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

        if ( ! $row_id ) {
            $pull_result = $this->pull_session( $session_id );
            $row_id = $pull_result['row_id'] ?? '';
            if ( ! $row_id ) {
                return array( 'success' => false, 'message' => 'No Smartsheet row ID. Pull first to link the row.' );
            }
        }

        // Gather push-direction values
        $cells    = array();
        $columns  = $this->field_map['columns'] ?? array();
        $meta     = $this->session_meta->get_all( $session_id );
        $pushed_fields = array();

        foreach ( $columns as $mapping ) {
            if ( ! in_array( $mapping['direction'], array( 'push', 'both' ), true ) ) continue;
            if ( $mapping['portal_store'] === 'skip' ) continue;

            $value = null;

            if ( $mapping['portal_store'] === 'meta' ) {
                $value = $meta[ $mapping['portal_key'] ] ?? '';
            } elseif ( $mapping['portal_store'] === 'session' ) {
                $value = $session[ $mapping['portal_key'] ] ?? '';
            } elseif ( $mapping['portal_store'] === 'formidable' ) {
                $value = $this->get_formidable_value( $session, $mapping['portal_key'] );
            }

            if ( $value !== null ) {
                $cells[] = array(
                    'columnId' => $mapping['ss_column_id'],
                    'value'    => $this->format_for_smartsheet( $value, $mapping ),
                );
                $pushed_fields[] = $mapping['portal_key'];
            }
        }

        if ( empty( $cells ) ) {
            return array( 'success' => true, 'message' => 'No fields to push.' );
        }

        $result = $this->api_put( "/sheets/{$sheet_id}/rows", array(
            array(
                'id'    => (int) $row_id,
                'cells' => $cells,
            ),
        ));

        if ( is_wp_error( $result ) ) {
            return array( 'success' => false, 'message' => 'API error: ' . $result->get_error_message() );
        }

        // Audit log
        if ( $this->audit ) {
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
                    'row_id'        => $row_id,
                ),
            ));
        }

        return array( 'success' => true, 'message' => 'Pushed ' . count( $cells ) . ' fields to Smartsheet.' );
    }

    /* ───────────────────────────────────────────
     * DIFF + APPLY — Core pull logic
     * ─────────────────────────────────────────── */

    /**
     * Compute a field-level diff between Smartsheet values and portal state.
     *
     * Returns the proposed changes without writing anything.
     * Also enforces empty value protection: if the Smartsheet cell is blank
     * and the portal already has a non-empty value, the field is skipped.
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
            // Don't overwrite existing portal data with a blank Smartsheet cell.
            if ( $new_value === '' && $old_value !== '' ) {
                $skipped++;
                $skipped_fields[] = array(
                    'field'          => $key,
                    'ss_title'       => $ss_title,
                    'protected_value' => $old_value,
                );
                $raw_values[ $raw_idx ]['outcome'] = 'skipped (empty protection)';
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
                if ( ! empty( $mapping['value_map'] ) && isset( $mapping['value_map'][ $value ] ) ) {
                    return $mapping['value_map'][ $value ];
                }
                return (string) $value;

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
                return in_array( $value, array( 'yes', '1', 'true', true ), true );

            case 'picklist':
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
