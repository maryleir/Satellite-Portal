<?php
/**
 * Smartsheet API sync.
 *
 * Handles bi-directional sync between the portal and Smartsheet:
 *   - Pull: SS → portal (admin-entered session data)
 *   - Push: portal → SS (sponsor-entered form data)
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

    /** @var string API base URL. */
    private $api_base = 'https://api.smartsheet.com/2.0';

    /** @var string API token (stored in wp_options). */
    private $api_token;

    /** @var array Field mapping config. */
    private $field_map;

    public function __construct( WSSP_Config $config, WSSP_Session_Meta $session_meta ) {
        $this->config       = $config;
        $this->session_meta = $session_meta;
        $this->api_token = defined( 'WSSP_SMARTSHEET_TOKEN' ) ? WSSP_SMARTSHEET_TOKEN : '';
        $this->field_map    = $this->config->get_smartsheet_map();
    }

    /* ───────────────────────────────────────────
     * PULL: Smartsheet → Portal
     * ─────────────────────────────────────────── */

    /**
     * Pull data from Smartsheet for a single session.
     *
     * Finds the row matching the session_code, reads all pull-direction
     * columns, and writes to session meta (or session table).
     *
     * @param int $session_id
     * @return array  [ 'success' => bool, 'message' => string, 'updated' => int ]
     */
    public function pull_session( $session_id ) {
        if ( empty( $this->api_token ) ) {
            return array( 'success' => false, 'message' => 'Smartsheet API token not configured.', 'updated' => 0 );
        }

        if ( empty( $this->field_map ) ) {
            return array( 'success' => false, 'message' => 'Smartsheet field mapping not configured.', 'updated' => 0 );
        }

        // Get the session
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

        // Build column ID → index lookup
        $col_lookup = array();
        foreach ( $sheet_data['columns'] ?? array() as $col ) {
            $col_lookup[ $col['id'] ] = $col;
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

        // Build cell value lookup: column_id => value
        $cell_values = array();
        foreach ( $target_row['cells'] as $cell ) {
            $cell_values[ $cell['columnId'] ] = $cell;
        }

        // Process each pull-direction mapping
        $columns    = $this->field_map['columns'] ?? array();
        $meta_updates   = array();
        $session_updates = array();
        $updated = 0;

        foreach ( $columns as $mapping ) {
            if ( ! in_array( $mapping['direction'], array( 'pull', 'both' ), true ) ) {
                continue;
            }
            if ( $mapping['portal_store'] === 'skip' ) {
                continue;
            }

            $col_id = $mapping['ss_column_id'];
            $cell   = $cell_values[ $col_id ] ?? null;
            $value  = $this->extract_cell_value( $cell, $mapping );

            if ( $mapping['portal_store'] === 'meta' ) {
                $meta_updates[ $mapping['portal_key'] ] = $value;
            } elseif ( $mapping['portal_store'] === 'session' ) {
                $session_updates[ $mapping['portal_key'] ] = $value;
            }
        }

        // Write meta
        if ( ! empty( $meta_updates ) ) {
            $updated += $this->session_meta->update_many( $session_id, $meta_updates );
        }

        // Write session table fields
        if ( ! empty( $session_updates ) ) {
            $wpdb->update(
                $wpdb->prefix . 'wssp_sessions',
                $session_updates,
                array( 'id' => $session_id )
            );
            $updated += count( $session_updates );
        }

        return array(
            'success' => true,
            'message' => "Pulled {$updated} fields from Smartsheet.",
            'updated' => $updated,
            'row_id'  => $target_row['id'] ?? null,
        );
    }

    /**
     * Pull data for ALL sessions at once.
     *
     * Fetches the sheet once and syncs all matching sessions.
     *
     * @return array  [ 'success' => bool, 'message' => string, 'results' => array ]
     */
    public function pull_all_sessions() {
        if ( empty( $this->api_token ) || empty( $this->field_map ) ) {
            return array( 'success' => false, 'message' => 'Not configured.', 'results' => array() );
        }

        $sheet_id  = $this->field_map['sheet_id'] ?? '';
        $match_col = $this->field_map['match_column'] ?? array();

        // Fetch entire sheet
        $sheet_data = $this->api_get( "/sheets/{$sheet_id}" );
        if ( is_wp_error( $sheet_data ) ) {
            return array( 'success' => false, 'message' => 'API error: ' . $sheet_data->get_error_message(), 'results' => array() );
        }

        // Get all sessions
        global $wpdb;
        $sessions = $wpdb->get_results(
            "SELECT * FROM {$wpdb->prefix}wssp_sessions",
            ARRAY_A
        );

        // Index sessions by session_code
        $sessions_by_code = array();
        foreach ( $sessions as $s ) {
            $sessions_by_code[ strtoupper( $s['session_code'] ) ] = $s;
        }

        // Index rows by match column value
        $match_col_id = $match_col['ss_column_id'];
        $columns      = $this->field_map['columns'] ?? array();
        $results      = array();
        $created_count = 0;

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

            // If no matching session exists, create one
            if ( ! isset( $sessions_by_code[ $row_code ] ) ) {
                // Extract sponsor name from this row for the short_name
                $sponsor_col_id = null;
                foreach ( $columns as $mapping ) {
                    if ( $mapping['portal_key'] === 'short_name' && $mapping['portal_store'] === 'session' ) {
                        $sponsor_col_id = $mapping['ss_column_id'];
                        break;
                    }
                }

                $short_name = '';
                if ( $sponsor_col_id ) {
                    foreach ( $row['cells'] as $cell ) {
                        if ( $cell['columnId'] == $sponsor_col_id ) {
                            $short_name = trim( $cell['displayValue'] ?? $cell['value'] ?? '' );
                            break;
                        }
                    }
                }

                // Generate a unique session key
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

                // Add to lookup so the sync below can process it
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

            $session = $sessions_by_code[ $row_code ];
            $session_id = $session['id'];

            // Store row ID
            if ( ! empty( $row['id'] ) ) {
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

            // Process pull columns
            $meta_updates = array();
            $session_updates = array();

            foreach ( $columns as $mapping ) {
                if ( ! in_array( $mapping['direction'], array( 'pull', 'both' ), true ) ) continue;
                if ( $mapping['portal_store'] === 'skip' ) continue;

                $cell  = $cell_values[ $mapping['ss_column_id'] ] ?? null;
                $value = $this->extract_cell_value( $cell, $mapping );

                if ( $mapping['portal_store'] === 'meta' ) {
                    $meta_updates[ $mapping['portal_key'] ] = $value;
                } elseif ( $mapping['portal_store'] === 'session' ) {
                    $session_updates[ $mapping['portal_key'] ] = $value;
                }
            }

            $count = 0;
            if ( ! empty( $meta_updates ) ) {
                $count += $this->session_meta->update_many( $session_id, $meta_updates );
            }
            if ( ! empty( $session_updates ) ) {
                $wpdb->update( $wpdb->prefix . 'wssp_sessions', $session_updates, array( 'id' => $session_id ) );
                $count += count( $session_updates );
            }

            $results[] = array(
                'session_code' => $session['session_code'],
                'updated'      => $count,
            );
        }

        $msg = 'Synced ' . count( $results ) . ' sessions.';
        if ( $created_count > 0 ) {
            $msg .= " Created {$created_count} new session(s).";
        }

        return array(
            'success' => true,
            'message' => $msg,
            'results' => $results,
            'created' => $created_count,
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
            // Try to find the row first
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

        foreach ( $columns as $mapping ) {
            if ( ! in_array( $mapping['direction'], array( 'push', 'both' ), true ) ) continue;
            if ( $mapping['portal_store'] === 'skip' ) continue;

            $value = null;

            if ( $mapping['portal_store'] === 'meta' ) {
                $value = $meta[ $mapping['portal_key'] ] ?? '';
            } elseif ( $mapping['portal_store'] === 'session' ) {
                $value = $session[ $mapping['portal_key'] ] ?? '';
            } elseif ( $mapping['portal_store'] === 'formidable' ) {
                // Look up the Formidable field value for this session
                $value = $this->get_formidable_value( $session, $mapping['portal_key'] );
            }

            if ( $value !== null ) {
                $cells[] = array(
                    'columnId' => $mapping['ss_column_id'],
                    'value'    => $this->format_for_smartsheet( $value, $mapping ),
                );
            }
        }

        if ( empty( $cells ) ) {
            return array( 'success' => true, 'message' => 'No fields to push.' );
        }

        // Update the row
        $result = $this->api_put( "/sheets/{$sheet_id}/rows", array(
            array(
                'id'    => (int) $row_id,
                'cells' => $cells,
            ),
        ));

        if ( is_wp_error( $result ) ) {
            return array( 'success' => false, 'message' => 'API error: ' . $result->get_error_message() );
        }

        return array( 'success' => true, 'message' => 'Pushed ' . count( $cells ) . ' fields to Smartsheet.' );
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
                // Smartsheet checkboxes are true/false
                $raw = $cell['value'] ?? false;
                return $raw ? 'yes' : '';

            case 'picklist':
                // Apply value_map if defined
                if ( ! empty( $mapping['value_map'] ) && isset( $mapping['value_map'][ $value ] ) ) {
                    return $mapping['value_map'][ $value ];
                }
                return (string) $value;

            case 'date':
                // Normalize any date format (MM/DD/YY, YYYY-MM-DD, etc.) to Y-m-d
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
                // Reverse value_map if defined
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

        // Find the session's Formidable entry via session_key
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

    /**
     * Check if Smartsheet sync is configured.
     */
    public function is_configured() {
        return ! empty( $this->api_token ) && ! empty( $this->field_map );
    }

    /**
     * Save the API token.
     */
    public static function save_api_token( $token ) {
        update_option( 'wssp_smartsheet_api_token', sanitize_text_field( $token ) );
    }
}