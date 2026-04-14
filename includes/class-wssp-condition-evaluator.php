<?php
/**
 * Task Condition Evaluator — centralized visibility rules for conditional tasks.
 *
 * Each condition slug (defined in portal-config.php's task_behavior) maps to
 * a callable that receives the session's merged data and returns true/false.
 *
 * USAGE:
 *   // In dashboard engine / templates:
 *   if ( ! WSSP_Condition_Evaluator::is_visible( $task['condition'], $session_data ) ) {
 *       continue; // skip this task
 *   }
 *
 * ADDING A NEW CONDITION:
 *   1. In config/portal-config.php → task_behavior, add:
 *        'my-task-slug' => array( 'condition' => 'my_condition_slug' ),
 *   2. In get_rules() below, add:
 *        'my_condition_slug' => function ( $data ) { return (bool) ...; },
 *
 * The evaluator also exposes evaluate_all() for the REST endpoint so the
 * JS layer can request the current visibility state of every conditional
 * task after a form submission — enabling smooth DOM updates without a
 * full page reload.
 *
 * @package WSSP
 */

defined( 'ABSPATH' ) || exit;

class WSSP_Condition_Evaluator {

    /**
     * Should a task with the given condition be visible?
     *
     * @param string|null $condition    Condition slug from portal-config.
     * @param array       $session_data Merged session data (session + meta + Formidable).
     * @return bool True = show the task, false = hide it.
     */
    public static function is_visible( $condition, $session_data ) {
        if ( empty( $condition ) ) {
            return true;
        }

        $rules = self::get_rules();

        if ( isset( $rules[ $condition ] ) ) {
            return call_user_func( $rules[ $condition ], $session_data );
        }

        // Unknown condition — fail-open (show the task).
        return true;
    }

    /**
     * Evaluate ALL condition rules against session data.
     *
     * Returns a map of condition_slug => bool for every registered rule.
     * Used by the REST endpoint to send the full visibility state to JS
     * so it can show/hide all conditional tasks in one pass.
     *
     * @param array $session_data Merged session data.
     * @return array  condition_slug => bool (true = visible).
     */
    public static function evaluate_all( $session_data ) {
        $results = array();
        foreach ( self::get_rules() as $slug => $fn ) {
            $results[ $slug ] = (bool) call_user_func( $fn, $session_data );
        }
        return $results;
    }

    /**
     * Get all task keys and their condition slugs from portal config.
     *
     * Scans the task_behavior block for entries with a 'condition' key.
     * Returns task_key => condition_slug.
     *
     * @param string     $event_type Event type slug.
     * @param WSSP_Config $config     Portal config instance.
     * @return array  task_key => condition_slug
     */
    public static function get_conditional_tasks( $event_type, WSSP_Config $config ) {
        $event_config = $config->get_event_type( $event_type );
        $behavior     = $event_config['task_behavior'] ?? array();
        $map          = array();

        foreach ( $behavior as $task_key => $overrides ) {
            if ( ! empty( $overrides['condition'] ) ) {
                $map[ $task_key ] = $overrides['condition'];
            }
        }

        return $map;
    }

    /* ───────────────────────────────────────────
     * CONDITION RULES REGISTRY
     * ─────────────────────────────────────────── */

    /**
     * Registry of condition rules.
     *
     * Each key is a condition slug (matching portal-config.php).
     * Each value is a callable: fn( array $session_data ) => bool.
     *
     * @return array
     */
    private static function get_rules() {
        return array(

            /**
             * CE Accreditation path.
             * Visible only when the sponsor has explicitly selected CE.
             */
            'ce_path' => function ( $session_data ) {
                $value = $session_data['wssp_program_ce_status'] ?? '';
                return self::is_ce_selected( $value );
            },

            /**
             * Non-CE Accreditation path (the default).
             * Visible when there is NO form entry yet, OR when
             * the sponsor has NOT selected CE accreditation.
             */
            'non_ce_path' => function ( $session_data ) {
                $value = $session_data['wssp_program_ce_status'] ?? '';
                return ! self::is_ce_selected( $value );
            },

            /**
             * Push Notification add-on active.
             * Visible when EITHER:
             *   1. The Smartsheet-confirmed meta key 'addon_push_notification'
             *      is affirmative (yes/1/true/hold), OR
             *   2. The sponsor has requested push notifications via the form
             *      field 'wssp_request_push' (any value that isn't empty,
             *      'no', 'decline', 'declined', or 'not interested').
             *
             * This mirrors the two-tier logic in compute_addon_states().
             */
            'push_notification_addon' => function ( $session_data ) {
                // Tier 1: Smartsheet-confirmed purchase
                if ( self::is_addon_active( $session_data, 'push_notification' ) ) {
                    return true;
                }
                // Tier 2: Sponsor requested via form
                return self::is_addon_requested( $session_data, 'wssp_request_push' );
            },

            /**
             * Recording add-on active.
             * Visible when the recording add-on is confirmed via Smartsheet
             * OR requested via the form field.
             */
            'recording_addon' => function ( $session_data ) {
                if ( self::is_addon_active( $session_data, 'recording' ) ) {
                    return true;
                }
                return self::is_addon_requested( $session_data, 'wssp_request_video_recording' );
            },

            /**
             * Hotel Door Drop add-on active.
             * Visible when the door drop add-on is confirmed via Smartsheet
             * OR requested via the form field.
             */
            'door_drop_addon' => function ( $session_data ) {
                if ( self::is_addon_active( $session_data, 'door_drop' ) ) {
                    return true;
                }
                return self::is_addon_requested( $session_data, 'wssp_request_door_drop' );
            },

            /**
             * Program Advertisement add-on active.
             * Visible when the program ad add-on is confirmed via Smartsheet
             * OR requested via the form field.
             */
            'program_ad_addon' => function ( $session_data ) {
                if ( self::is_addon_active( $session_data, 'program_ad' ) ) {
                    return true;
                }
                return self::is_addon_requested( $session_data, 'wssp_request_program_ad' );
            },

            /**
             * Recording approval required AND add-on active.
             * Visible only when the recording add-on is active (confirmed
             * or requested) and logistics has flagged the session as
             * requiring approval (wssp_od_recording_approval_required = 'yes').
             */
            'recording_approval_required' => function ( $session_data ) {
                // Must have recording add-on active (either tier)
                $recording_active = self::is_addon_active( $session_data, 'recording' )
                    || self::is_addon_requested( $session_data, 'wssp_request_video_recording' );
                if ( ! $recording_active ) {
                    return false;
                }
                $value = strtolower( trim( (string) ( $session_data['wssp_od_recording_approval_required'] ?? '' ) ) );
                return in_array( $value, array( 'yes', '1', 'true' ), true );
            },

            /**
             * Separate Virtual Bag Insert file.
             * Default: VISIBLE. Hidden only when the sponsor has explicitly
             * selected "Yes - existing" on the VBI field in the session-data
             * form, meaning they'll reuse the invitation PDF.
             */
            'separate_vbi_upload' => function ( $session_data ) {
                $value = $session_data['wssp_virtual_bag_insert_file'] ?? '';
                if ( is_array( $value ) ) {
                    $value = reset( $value );
                }
                $value = strtolower( trim( (string) $value ) );
                if ( $value === '' ) {
                    return true; // No selection yet — show VBI upload task
                }
                // "Yes - existing" = reuse invitation = hide VBI upload
                // Anything else (including "No - separate") = show VBI upload
                return strpos( $value, 'yes' ) === false;
            },

            // ─── Future condition examples ──────────────────
            //
            // 'has_speakers' => function ( $data ) {
            //     return ! empty( $data['wssp_speaker_count'] );
            // },
            //
            // 'virtual_session' => function ( $data ) {
            //     return ( $data['wssp_session_format'] ?? '' ) === 'virtual';
            // },
            //
            // 'recording_selected' => function ( $data ) {
            //     return strtolower( trim( $data['wssp_recording_option'] ?? '' ) ) === 'yes';
            // },
        );
    }

    /* ───────────────────────────────────────────
     * HELPERS
     * ─────────────────────────────────────────── */

    /**
     * Determine if a CE status value indicates CE accreditation.
     *
     * Handles Formidable field values like:
     *   'CE', 'CE Accredited', 'Yes', 'ce-accreditation'
     * Excludes:
     *   '', 'Non-CE', 'No', 'non-ce-accreditation'
     *
     * @param string $value Raw form value.
     * @return bool
     */
    private static function is_ce_selected( $value ) {
        $value = strtolower( trim( (string) $value ) );

        // Empty = no selection yet = Non-CE default
        if ( $value === '' ) {
            return false;
        }

        // Explicit non-CE indicators
        if ( in_array( $value, array( 'no', 'non-ce', 'none' ), true ) ) {
            return false;
        }
        if ( strpos( $value, 'non' ) !== false ) {
            return false;
        }

        // Anything else that contains 'ce' or affirmative = CE selected
        if ( in_array( $value, array( 'yes', '1', 'true' ), true ) ) {
            return true;
        }
        if ( strpos( $value, 'ce' ) !== false ) {
            return true;
        }

        return false;
    }

    /**
     * Determine if a given add-on is active (purchased/confirmed).
     *
     * Checks for session_meta key 'addon_{slug}' in the merged session data.
     * Values like 'yes', '1', 'true', 'hold' indicate an active add-on.
     *
     * @param array  $session_data Merged session data (must include session_meta).
     * @param string $addon_slug   Add-on slug, e.g. 'push_notification'.
     * @return bool
     */
    private static function is_addon_active( $session_data, $addon_slug ) {
        $meta_key = 'addon_' . $addon_slug;
        $value    = strtolower( trim( (string) ( $session_data[ $meta_key ] ?? '' ) ) );

        return in_array( $value, array( 'yes', '1', 'true', 'hold' ), true );
    }

    /**
     * Determine if an add-on has been requested via its Formidable form field.
     *
     * Returns true when the field has a non-empty value that is not an
     * explicit decline. Mirrors the fallback tier in compute_addon_states().
     *
     * @param array  $session_data Merged session data.
     * @param string $field_key    Formidable field key, e.g. 'wssp_request_push'.
     * @return bool
     */
    private static function is_addon_requested( $session_data, $field_key ) {
        $value = $session_data[ $field_key ] ?? '';
        if ( is_array( $value ) ) {
            $value = implode( ' ', $value );
        }
        $value = strtolower( trim( (string) $value ) );

        if ( $value === '' ) {
            return false;
        }

        // Explicit decline values
        if ( in_array( $value, array( 'no', 'decline', 'declined', 'not interested' ), true ) ) {
            return false;
        }

        return true;
    }
}