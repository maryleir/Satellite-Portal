<?php
/**
 * Session-aware shortcodes.
 *
 * Resolves placeholders like [assigned-room], [av-contact], [title]
 * using the current session's merged data (session table + session_meta
 * + Formidable entry).
 *
 * The session context is set once per dashboard render by calling
 * WSSP_Session_Shortcodes::set_context( $session_data, $session_meta ).
 * All subsequent do_shortcode() calls in templates, render helpers,
 * and config resolution will automatically resolve these.
 *
 * Usage:
 *   // In render_session_detail():
 *   WSSP_Session_Shortcodes::set_context( $session_data, $session_meta );
 *   // ... render templates (all do_shortcode() calls now resolve session shortcodes)
 *   WSSP_Session_Shortcodes::clear_context();
 *
 * @package WSSP
 */

defined( 'ABSPATH' ) || exit;

class WSSP_Session_Shortcodes {

    /** @var array|null Merged session data for the current render. */
    private static $session_data = null;

    /** @var array|null Raw session meta for the current render. */
    private static $session_meta = null;

    /**
     * Shortcode map: shortcode tag => session_data key(s).
     *
     * Each entry is either:
     *   - A string key to look up in $session_data
     *   - An array of keys to try in order (first non-empty wins)
     *
     * The keys here must match what WSSP_Formidable::get_full_session_data()
     * returns: session table columns, session_meta keys, and Formidable
     * field_key values.
     *
     * @var array
     */
    private static $shortcode_map = array(
        // ─── Session identity ───
        'title'                    => array( 'wssp_program_title', 'topic' ),
        'name'                     => array( 'wssp_data_company_name', 'sponsor_name', 'short_name' ),
        'topic'                    => 'topic',
        'session-code'             => 'session_code',

        // ─── Venue / logistics (from Smartsheet via session_meta) ───
        'assigned-room'            => 'session_location',
        'assigned-room-floor-plan' => 'room_floor_plan_url',

        // ─── Contacts (from Smartsheet via session_meta) ───
        'av-contact'               => 'av_contact_name',
        'av-email'                 => 'av_contact_email',

        // ─── Date/time (from Smartsheet via session_meta) ───
        'session-date'             => 'session_date',
        'session-time'             => 'session_time',
        'session-day'              => 'session_day',
        
        'rehearsal-day'            => 'rehearsal_day', 
        'rehearsal-date'           => 'rehearsal_date',
        'rehearsal-time'           => 'rehearsal_time',

        // ─── On Demand / media ───
        'video-backplate'          => 'backplate_template_url',
    );

    /**
     * Register all session shortcodes with WordPress.
     *
     * Called once during plugin bootstrap (plugins_loaded).
     * The shortcodes return empty strings until set_context() is called.
     *
     * Only registers tags not already claimed by another plugin/theme
     * to avoid collisions.
     */
    public static function register() {
        foreach ( array_keys( self::$shortcode_map ) as $tag ) {
            if ( ! shortcode_exists( $tag ) ) {
                add_shortcode( $tag, array( __CLASS__, 'render_shortcode' ) );
            }
        }
    }

    /**
     * Set the session context for the current render.
     *
     * Called by WSSP_Public::render_session_detail() before including
     * any templates. All do_shortcode() calls within the render will
     * resolve session shortcodes using this data.
     *
     * @param array $session_data Merged session + meta + Formidable data.
     * @param array $session_meta Raw session meta (for add-on checks etc.).
     */
    public static function set_context( array $session_data, array $session_meta = array() ) {
        self::$session_data = $session_data;
        self::$session_meta = $session_meta;
    }

    /**
     * Clear the session context.
     *
     * Called after render_session_detail() completes to prevent
     * session data leaking into subsequent shortcode processing.
     */
    public static function clear_context() {
        self::$session_data = null;
        self::$session_meta = null;
    }

    /**
     * Check if a session context is currently active.
     *
     * Useful for templates that need to know whether session
     * shortcodes will resolve (e.g., to conditionally apply
     * do_shortcode on subtitle_html).
     *
     * @return bool
     */
    public static function has_context() {
        return self::$session_data !== null;
    }

    /**
     * Universal shortcode handler for all session shortcodes.
     *
     * @param array  $atts    Shortcode attributes (unused for now).
     * @param string $content Enclosed content (unused).
     * @param string $tag     The shortcode tag being rendered.
     * @return string Resolved value, or empty string if no context.
     */
    public static function render_shortcode( $atts, $content, $tag ) {
        // No session context → return empty (not the raw [shortcode] text)
        if ( self::$session_data === null ) {
            return '';
        }

        $mapping = self::$shortcode_map[ $tag ] ?? null;
        if ( $mapping === null ) {
            return '';
        }

        // Array of keys — try each in order, return first non-empty
        if ( is_array( $mapping ) ) {
            foreach ( $mapping as $key ) {
                $value = self::$session_data[ $key ] ?? '';
                if ( $value !== '' ) {
                    return self::format_value( $tag, $value );
                }
            }
            return '';
        }

        // Single key
        $value = self::$session_data[ $mapping ] ?? '';
        return self::format_value( $tag, $value );
    }

    /**
     * Format a value based on the shortcode tag type.
     *
     * Applies smart formatting:
     *   - Email tags → mailto link
     *   - Date tags → human-readable date
     *   - URL/plan tags → clickable link
     *   - Everything else → escaped text
     *
     * @param string $tag   Shortcode tag.
     * @param mixed  $value Raw value from session data.
     * @return string Formatted, escaped output.
     */
    private static function format_value( $tag, $value ) {
    //error_log("format_tag " . $tag . "    format_value " . $value );

        if ( empty( $value ) ) {
            return '';
        }

        // Email fields → mailto link
        if ( str_ends_with( $tag, '-email' ) ) {
            return '<a href="mailto:' . esc_attr( $value ) . '">' . esc_html( $value ) . '</a>';
        }

        // Date fields → human-readable format
        if ( str_ends_with( $tag, '-date' ) ) {
            $ts = strtotime( $value );
            if ( $ts !== false ) {
                return esc_html( date( 'F j, Y', $ts ) );
            }
        }

        // URL fields → clickable link
        if ( str_ends_with( $tag, '-url' ) || str_ends_with( $tag, '-floor-plan' ) ) {
              // Resolve [siteurl] placeholder to the actual site URL.
              if ( strpos( $value, '[siteurl]' ) !== false ) {
                  $value = str_replace( '[siteurl]', site_url(), $value );
              }

        
            if ( filter_var( $value, FILTER_VALIDATE_URL ) ) {
                $label = str_contains( $tag, 'floor-plan' ) ? 'View Floor Plan' : 'View';
                return '<a href="' . esc_url( $value ) . '" target="_blank" rel="noopener">' . $label . '</a>';
            }
        }

        // Video backplate — could be URL or description
        if ( $tag === 'video-backplate' ) {
        
            // Resolve [siteurl] placeholder to the actual site URL.
            if ( strpos( $value, '[siteurl]' ) !== false ) {
                $value = str_replace( '[siteurl]', site_url(), $value );
            }

            if ( filter_var( $value, FILTER_VALIDATE_URL ) ) {
                return '<a href="' . esc_url( $value ) . '" target="_blank" rel="noopener"><img class="video-backplate" src="' . esc_url( $value ) . '"></a>';
            }
        }

        // Default: escaped text
        return esc_html( $value );
    }

    /**
     * Get all session shortcodes for the TC admin picker.
     *
     * Returns shortcode definitions with preview values when a session
     * context exists, or "(set per session)" when outside a render.
     * Also provides category grouping for the picker UI.
     *
     * @return array [ ['key' => '...', 'value' => '...', 'category' => '...'], ... ]
     */
    public static function get_available_shortcodes() {
        $shortcodes = array();

        $categories = array(
            'Session Data' => array( 'title', 'name', 'topic', 'session-code' ),
            'Venue'        => array( 'assigned-room', 'room_floor_plan_url' ),
            'Contacts'     => array( 'av-contact', 'av-email' ),
            'Schedule'     => array( 'session-date', 'session-time', 'session-day' ),
            'On Demand'    => array( 'video-backplate' ),
        );

        foreach ( $categories as $category => $tags ) {
            foreach ( $tags as $tag ) {
                $value = '(set per session)';
                if ( self::$session_data !== null ) {
                    $resolved = self::render_shortcode( array(), '', $tag );
                    if ( $resolved !== '' ) {
                        $value = wp_strip_all_tags( $resolved );
                    }
                }
                $shortcodes[] = array(
                    'key'      => $tag,
                    'value'    => $value,
                    'category' => $category,
                );
            }
        }

        return $shortcodes;
    }

    /**
     * Add a custom shortcode mapping at runtime.
     *
     * Allows other code (e.g., Smartsheet sync, custom meta) to register
     * additional session shortcodes without modifying this class.
     *
     * @param string       $tag     Shortcode tag (e.g., 'custom-field').
     * @param string|array $mapping Session data key(s) to look up.
     */
    public static function add_mapping( $tag, $mapping ) {
        self::$shortcode_map[ $tag ] = $mapping;

        // Register the shortcode if not already registered
        if ( ! shortcode_exists( $tag ) ) {
            add_shortcode( $tag, array( __CLASS__, 'render_shortcode' ) );
        }
    }
}
