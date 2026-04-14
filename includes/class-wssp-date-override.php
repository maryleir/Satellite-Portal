<?php
/**
 * Date Override — Dev/testing tool for time-travel.
 *
 * Provides a central get_today() that all date-sensitive rendering code
 * should use instead of date('Y-m-d') or current_time('Y-m-d').
 *
 * In production (no override), returns the real current date.
 * When ?wssp_date=2026-11-15 is in the URL and the user is an admin,
 * returns the overridden date so the entire dashboard renders as if
 * that were "today" — overdue flags, upcoming badges, priority
 * calculations, and stats all reflect the simulated date.
 *
 * The override does NOT affect write operations (submitted_at,
 * reviewed_at, etc.) — only read/display logic.
 *
 * USAGE:
 *   $today = WSSP_Date_Override::get_today();
 *
 * @package WSSP
 */

defined( 'ABSPATH' ) || exit;

class WSSP_Date_Override {

    /** @var string|null Cached override value for the current request. */
    private static $override = null;

    /** @var bool Whether we've checked the request yet. */
    private static $resolved = false;

    /**
     * Get "today" — either the real date or the admin override.
     *
     * @return string Y-m-d date string.
     */
    public static function get_today() {
        if ( ! self::$resolved ) {
            self::resolve();
        }
        return self::$override ?: current_time( 'Y-m-d' );
    }

    /**
     * Is there an active date override?
     *
     * @return bool
     */
    public static function is_overridden() {
        if ( ! self::$resolved ) {
            self::resolve();
        }
        return self::$override !== null;
    }

    /**
     * Get test date options derived from real task deadlines.
     *
     * Returns a curated set of dates around actual deadlines so the
     * dropdown options produce meaningful test scenarios:
     *   - 3 days before each unique deadline (tasks show as upcoming)
     *   - The deadline day itself (due today)
     *   - 1 day after each unique deadline (tasks show as overdue)
     *
     * Also includes today's real date as the first option.
     *
     * @param WSSP_Config $config     Portal config instance.
     * @param string      $event_type Event type slug.
     * @return array [ ['date' => 'Y-m-d', 'label' => '...'], ... ]
     */
    public static function get_test_dates( WSSP_Config $config, $event_type ) {
        $real_today = current_time( 'Y-m-d' );

        $dates = array(
            array(
                'date'  => $real_today,
                'label' => 'Today (real) — ' . date_i18n( 'M j, Y', strtotime( $real_today ) ),
            ),
        );

        // Collect unique deadlines from task config
        $deadlines = $config->get_deadlines( $event_type );
        $seen      = array();

        foreach ( $deadlines as $dl ) {
            $d = $dl['date'];
            if ( ! $d || isset( $seen[ $d ] ) ) continue;
            $seen[ $d ] = true;

            $ts = strtotime( $d );
            if ( ! $ts ) continue;

            $formatted = date_i18n( 'M j, Y', $ts );
            $task_hint = $dl['label'];
            // Truncate long task names
            if ( strlen( $task_hint ) > 35 ) {
                $task_hint = substr( $task_hint, 0, 32 ) . '…';
            }

            // 3 days before (upcoming state)
            $before     = date( 'Y-m-d', strtotime( '-3 days', $ts ) );
            $before_fmt = date_i18n( 'M j', strtotime( $before ) );
            $dates[] = array(
                'date'  => $before,
                'label' => $before_fmt . ' — 3d before: ' . $task_hint,
            );

            // Deadline day
            $dates[] = array(
                'date'  => $d,
                'label' => $formatted . ' — Due: ' . $task_hint,
            );

            // 1 day after (overdue state)
            $after     = date( 'Y-m-d', strtotime( '+1 day', $ts ) );
            $after_fmt = date_i18n( 'M j', strtotime( $after ) );
            $dates[] = array(
                'date'  => $after,
                'label' => $after_fmt . ' — 1d overdue: ' . $task_hint,
            );
        }

        // Deduplicate by date (keep first occurrence — better label)
        $unique = array();
        $seen_dates = array();
        foreach ( $dates as $entry ) {
            if ( ! isset( $seen_dates[ $entry['date'] ] ) ) {
                $seen_dates[ $entry['date'] ] = true;
                $unique[] = $entry;
            }
        }

        // Sort by date
        usort( $unique, function ( $a, $b ) {
            return strcmp( $a['date'], $b['date'] );
        });

        return $unique;
    }

    /**
     * Resolve the override from the query string.
     */
    private static function resolve() {
        self::$resolved = true;
        self::$override = null;

        // Admin-only
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        $raw = isset( $_GET['wssp_date'] ) ? sanitize_text_field( $_GET['wssp_date'] ) : '';
        if ( ! $raw ) {
            return;
        }

        // Validate it's a real date
        $ts = strtotime( $raw );
        if ( $ts !== false ) {
            self::$override = date( 'Y-m-d', $ts );
        }
    }
}