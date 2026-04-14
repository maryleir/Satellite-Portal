<?php
/**
 * Dates & Deadlines Smartsheet Sync.
 *
 * Pulls the conference-wide dates/deadlines sheet and stores each row
 * as a shortcode→date mapping in wp_options. These are conference-level
 * constants (not per-session), so they're stored globally and refreshed
 * on demand from the admin.
 *
 * Sheet layout (WORLD 2027 Dates and Deadlines):
 *   Col A: Item         — human label
 *   Col B: Category     — Sponsor / Abstracts / WORLD
 *   Col C: 2026 Date    — prior year reference (informational only)
 *   Col D: 2027 Date    — current year reference (informational only)
 *   Col E: shortcode    — the shortcode key(s) to register (may be multi-line)
 *   Col F: Date         — the canonical date value (DATE type in Smartsheet)
 *   Col G: Tags         — multi-line tags: Satellite, Exhibitors, Abstracts, etc.
 *   Col H: Notes        — editorial notes (not synced)
 *
 * Sync writes to:
 *   wp_option 'wssp_dates_deadlines' => [
 *       'synced_at' => '2026-04-08T15:30:00Z',
 *       'sheet_id'  => '3779755649224580',
 *       'entries'   => [
 *           'satellite-title-deadline' => [
 *               'date'     => '2026-11-04',
 *               'label'    => 'Satellite / IET Final Title, Description & Session Details',
 *               'category' => 'Sponsor',
 *               'tags'     => ['IETs', 'Satellite'],
 *               'notes'    => '...',
 *               'row_id'   => 12345678,
 *           ],
 *           ...
 *       ],
 *   ]
 *
 * @package WSSP
 */

defined( 'ABSPATH' ) || exit;

class WSSP_Dates_Deadlines_Smartsheet {

    /** @var string Option key for stored dates data. */
    private const OPTION_KEY = 'wssp_dates_deadlines';

    /** @var string API base URL. */
    private $api_base = 'https://api.smartsheet.com/2.0';

    /** @var string Sheet ID. */
    private $sheet_id;

    /** @var array Column ID mapping — set via config or auto-detected. */
    private $column_ids;

    /** @var string API token. */
    private $api_token;

    /**
     * Constructor.
     *
     * @param array $config Configuration from dates-deadlines-field-map.php.
     */
    public function __construct( array $config = array() ) {
        $this->sheet_id   = $config['sheet_id'] ?? '';
        $this->column_ids = $config['columns'] ?? array();
        $this->api_token  = defined( 'WSSP_SMARTSHEET_TOKEN' ) ? WSSP_SMARTSHEET_TOKEN : '';
    }

    /**
     * Pull all dates from the Smartsheet and store in wp_options.
     *
     * @return array [ 'success' => bool, 'message' => string, 'count' => int ]
     */
    public function pull() {
        if ( empty( $this->api_token ) ) {
            return array( 'success' => false, 'message' => 'Smartsheet API token not configured.', 'count' => 0 );
        }
        if ( empty( $this->sheet_id ) ) {
            return array( 'success' => false, 'message' => 'Dates & Deadlines sheet ID not configured.', 'count' => 0 );
        }

        $sheet_data = $this->api_get( "/sheets/{$this->sheet_id}" );
        if ( is_wp_error( $sheet_data ) ) {
            return array( 'success' => false, 'message' => 'API error: ' . $sheet_data->get_error_message(), 'count' => 0 );
        }

        // Build column ID → name lookup (auto-detect if column IDs not configured)
        $col_lookup = array();
        foreach ( $sheet_data['columns'] ?? array() as $col ) {
            $col_lookup[ $col['id'] ] = $col['title'];
        }

        // Resolve column IDs by title if not explicitly configured
        $col_ids = $this->resolve_column_ids( $sheet_data['columns'] ?? array() );
        if ( ! $col_ids ) {
            return array( 'success' => false, 'message' => 'Could not match required columns (shortcode, Date) in sheet.', 'count' => 0 );
        }

        // Process rows
        $entries = array();
        foreach ( $sheet_data['rows'] ?? array() as $row ) {
            $cells = array();
            foreach ( $row['cells'] as $cell ) {
                $cells[ $cell['columnId'] ] = $cell;
            }

            // Get the shortcode key(s)
            $shortcode_raw = $this->cell_text( $cells, $col_ids['shortcode'] );
            if ( empty( $shortcode_raw ) ) {
                continue; // Skip rows with no shortcode
            }

            // Extract other fields
            $item     = $this->cell_text( $cells, $col_ids['item'] );
            $category = $this->cell_text( $cells, $col_ids['category'] );
            $date_val = $this->cell_value( $cells, $col_ids['date'] );
            $tags_raw = $this->cell_text( $cells, $col_ids['tags'] );
            $notes    = $this->cell_text( $cells, $col_ids['notes'] );
            $row_id   = $row['id'] ?? null;

            // Normalize date to Y-m-d
            $date = $this->normalize_date( $date_val );

            // Parse tags (multi-line in Smartsheet)
            $tags = array_values( array_filter( array_map( 'trim', preg_split( '/[\n,]+/', $tags_raw ) ) ) );

            // Handle multi-line shortcode field (e.g. "rooming-lists-due\ngroup-housing-deadline")
            $shortcode_keys = array_values( array_filter( array_map( 'trim', explode( "\n", $shortcode_raw ) ) ) );

            foreach ( $shortcode_keys as $key ) {
                $key = sanitize_key( str_replace( ' ', '-', strtolower( $key ) ) );
                if ( empty( $key ) ) continue;

                $entries[ $key ] = array(
                    'date'     => $date,
                    'label'    => $item,
                    'category' => $category,
                    'tags'     => $tags,
                    'notes'    => $notes,
                    'row_id'   => $row_id,
                );
            }
        }

        // Store in wp_options
        $stored = array(
            'synced_at' => gmdate( 'Y-m-d\TH:i:s\Z' ),
            'sheet_id'  => $this->sheet_id,
            'entries'   => $entries,
        );
        update_option( self::OPTION_KEY, $stored, false );

        return array(
            'success' => true,
            'message' => sprintf( 'Synced %d date shortcodes from Smartsheet.', count( $entries ) ),
            'count'   => count( $entries ),
        );
    }

    /**
     * Get all stored date entries.
     *
     * @return array Keyed by shortcode: [ 'date' => 'Y-m-d', 'label' => '...', ... ]
     */
    public static function get_entries() {
        $stored = get_option( self::OPTION_KEY, array() );
        return $stored['entries'] ?? array();
    }

    /**
     * Get a single date value by shortcode key.
     *
     * @param string $key Shortcode key.
     * @return string|null Y-m-d date string, or null if not found.
     */
    public static function get_date( $key ) {
        $entries = self::get_entries();
        return $entries[ $key ]['date'] ?? null;
    }

    /**
     * Get sync metadata.
     *
     * @return array [ 'synced_at' => '...', 'sheet_id' => '...' ] or empty.
     */
    public static function get_sync_info() {
        $stored = get_option( self::OPTION_KEY, array() );
        return array(
            'synced_at' => $stored['synced_at'] ?? null,
            'sheet_id'  => $stored['sheet_id'] ?? null,
            'count'     => count( $stored['entries'] ?? array() ),
        );
    }

    /**
     * Get entries filtered by tag.
     *
     * @param string $tag Tag to filter by (e.g. 'Satellite', 'Exhibitors').
     * @return array Filtered entries keyed by shortcode.
     */
    public static function get_entries_by_tag( $tag ) {
        $entries = self::get_entries();
        return array_filter( $entries, function ( $entry ) use ( $tag ) {
            return in_array( $tag, $entry['tags'] ?? array(), true );
        });
    }

    /**
     * Get entries filtered by category.
     *
     * @param string $category Category to filter by (e.g. 'Sponsor', 'Abstracts').
     * @return array Filtered entries keyed by shortcode.
     */
    public static function get_entries_by_category( $category ) {
        $entries = self::get_entries();
        return array_filter( $entries, function ( $entry ) use ( $category ) {
            return ( $entry['category'] ?? '' ) === $category;
        });
    }

    /**
     * Check if the dates sheet is configured and has been synced.
     *
     * @return bool
     */
    public function is_configured() {
        return ! empty( $this->api_token ) && ! empty( $this->sheet_id );
    }

    /**
     * Check if data has been synced at least once.
     *
     * @return bool
     */
    public static function has_data() {
        $stored = get_option( self::OPTION_KEY, array() );
        return ! empty( $stored['entries'] );
    }

    /* ───────────────────────────────────────────
     * COLUMN RESOLUTION
     * ─────────────────────────────────────────── */

    /**
     * Resolve column IDs by matching column titles.
     *
     * If explicit column IDs are configured, use those.
     * Otherwise, auto-detect by matching title patterns.
     *
     * @param array $columns Sheet column definitions from API.
     * @return array|null [ 'item' => id, 'category' => id, ... ] or null on failure.
     */
    private function resolve_column_ids( $columns ) {
        // If explicitly configured, use those
        if ( ! empty( $this->column_ids['shortcode'] ) && ! empty( $this->column_ids['date'] ) ) {
            return $this->column_ids;
        }

        // Auto-detect by title
        $title_map = array(
            'item'      => array( 'Item', 'Name' ),
            'category'  => array( 'Category' ),
            'shortcode' => array( 'shortcode', 'Shortcode', 'shortcode key' ),
            'date'      => array( 'Date' ),
            'tags'      => array( 'Tags', 'Tag' ),
            'notes'     => array( 'Notes', 'Note' ),
        );

        $resolved = array();
        foreach ( $columns as $col ) {
            foreach ( $title_map as $field => $titles ) {
                if ( in_array( $col['title'], $titles, true ) && ! isset( $resolved[ $field ] ) ) {
                    $resolved[ $field ] = $col['id'];
                }
            }
        }

        // shortcode and date are required
        if ( empty( $resolved['shortcode'] ) || empty( $resolved['date'] ) ) {
            return null;
        }

        return $resolved;
    }

    /* ───────────────────────────────────────────
     * CELL VALUE HELPERS
     * ─────────────────────────────────────────── */

    private function cell_text( $cells, $col_id ) {
        if ( ! $col_id || ! isset( $cells[ $col_id ] ) ) return '';
        $cell = $cells[ $col_id ];
        return trim( (string) ( $cell['displayValue'] ?? $cell['value'] ?? '' ) );
    }

    private function cell_value( $cells, $col_id ) {
        if ( ! $col_id || ! isset( $cells[ $col_id ] ) ) return '';
        return $cells[ $col_id ]['value'] ?? '';
    }

    /**
     * Normalize a date value from Smartsheet to Y-m-d.
     *
     * Smartsheet DATE columns return ISO 8601 strings (YYYY-MM-DD or YYYY-MM-DDT...).
     * The displayValue may be a formatted string like "January 31, 2027".
     *
     * @param mixed $value Raw cell value.
     * @return string|null Y-m-d or null.
     */
    private function normalize_date( $value ) {
        if ( empty( $value ) ) return null;

        $value = trim( (string) $value );

        // Already Y-m-d or ISO 8601
        if ( preg_match( '/^\d{4}-\d{2}-\d{2}/', $value ) ) {
            return substr( $value, 0, 10 );
        }

        // Try strtotime for display formats
        $ts = strtotime( $value );
        if ( $ts !== false ) {
            return date( 'Y-m-d', $ts );
        }

        return null;
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
        ) );

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
}
