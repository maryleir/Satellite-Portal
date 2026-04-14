<?php
/**
 * Configuration reader — merges TC plugin structure with portal behavior.
 *
 * Phase and task structure (titles, descriptions, deadlines, form mappings,
 * sections) comes from the WSSP Task Content plugin via wssp_tc().
 *
 * Behavioral overrides (task type, file_type, addon gating, conditional
 * visibility) and system config (file_types, addons, vendor_views) come
 * from config/portal-config.php.
 *
 * This class merges the two sources and presents a unified interface to
 * the dashboard engine, REST endpoints, and templates. The public API is
 * preserved so downstream code doesn't need to know the data came from
 * two places.
 *
 * TC task objects use:  slug, title, description, deadline, form_key, field_keys
 * The dashboard expects: key,  label, description, deadline, form_key, field_keys, type, priority, owner
 *
 * This class normalizes TC fields to the dashboard's expected keys and
 * overlays behavioral fields from portal-config.php.
 *
 * @package WSSP
 */

defined( 'ABSPATH' ) || exit;

class WSSP_Config {

    /** @var array Portal config from portal-config.php, keyed by event type slug. */
    private $portal_config = array();

    /** @var array Smartsheet field mapping. */
    private $smartsheet_map = array();

    /** @var array Cached merged phases, keyed by event type slug. */
    private $phases_cache = array();

    /**
     * Constructor — load portal config files.
     */
    public function __construct() {
        $config_dir = WSSP_PLUGIN_DIR . 'config/';

        $portal_file = $config_dir . 'portal-config.php';
        if ( file_exists( $portal_file ) ) {
            $this->portal_config = require $portal_file;
        }

        $ss_file = $config_dir . 'smartsheet-field-map.php';
        if ( file_exists( $ss_file ) ) {
            $this->smartsheet_map = require $ss_file;
        }
    }

    /**
     * Get conference dates from the conference shortcodes plugin.
     */
    public function get_conference_dates() {
        return array(
            'start'           => ws_cs_get_date( 'day-one-dow' )          ?? '2027-01-31',
            'end'             => ws_cs_get_date( 'day-five-dow' )         ?? '2027-02-04',
            'on_demand_open'  => ws_cs_get_date( 'on-demand-open-date' )  ?? '2027-02-01',
            'on_demand_close' => ws_cs_get_date( 'on-demand-close-date' ) ?? '2027-03-12',
        );
    }

    /* ───────────────────────────────────────────
     * EVENT TYPE ACCESSORS
     * ─────────────────────────────────────────── */

    /**
     * Get all event type slugs.
     *
     * @return array e.g. ['satellite']
     */
    public function get_event_types() {
        return array_keys( $this->portal_config );
    }

    /**
     * Get a single event type's portal config (behavioral layer only).
     *
     * Returns the raw portal-config.php entry for this event type,
     * including addons, file_types, vendor_views, and label.
     *
     * @param string $type Event type slug.
     * @return array|null
     */
    public function get_event_type( $type ) {
        return $this->portal_config[ $type ] ?? null;
    }

    /**
     * Get all event type configurations.
     *
     * @return array slug => config array
     */
    public function get_event_type_configs() {
        return $this->portal_config;
    }

    /**
     * Get the display label for an event type.
     *
     * Checks the TC plugin portal label first, falls back to portal config.
     *
     * @param string $type Event type slug.
     * @return string
     */
    public function get_event_label( $type ) {
        $tc = wssp_tc();
        if ( $tc ) {
            $portal = $tc->get_portal( $type );
            if ( $portal && ! empty( $portal->label ) ) {
                return $portal->label;
            }
        }
        return $this->portal_config[ $type ]['label'] ?? $type;
    }

    /* ───────────────────────────────────────────
     * PHASE & TASK ACCESSORS
     * Reads from TC plugin, overlays behavioral config.
     * ─────────────────────────────────────────── */

    /**
     * Get all phases for an event type, with tasks merged from TC + behavior.
     *
     * Each phase is an array with:
     *   key, label, sort_order, date_range_content, start_date, end_date, tasks
     *
     * Each task within a phase is an array with:
     *   key, label, description, type, priority, owner, deadline, form_key,
     *   field_keys, form_instructions, requires_acknowledgment, acknowledgment_text,
     *   optional, addon, file_type, provided_by, deadline_display, submit_scope
     *
     * @param string $type Event type slug.
     * @return array
     */
    public function get_phases( $type ) {
        if ( isset( $this->phases_cache[ $type ] ) ) {
            return $this->phases_cache[ $type ];
        }

        $tc = wssp_tc();
        if ( ! $tc ) {
            $this->phases_cache[ $type ] = array();
            return array();
        }

        $portal = $tc->get_portal( $type );
        if ( ! $portal ) {
            $this->phases_cache[ $type ] = array();
            return array();
        }

        $tc_phases = $tc->get_phases( $portal->id );
        $behavior  = $this->portal_config[ $type ]['task_behavior'] ?? array();

        $merged_phases = array();
        foreach ( $tc_phases as $tc_phase ) {
            $tc_tasks    = $tc->get_phase_tasks( $tc_phase->id );
            $phase_tasks = array();

            foreach ( $tc_tasks as $tc_task ) {
                $slug     = $tc_task->slug;
                $override = $behavior[ $slug ] ?? array();

                // Resolve deadline: shortcode key or date string → Y-m-d
                $deadline_raw = $tc_task->deadline ?? null;
                $deadline     = self::resolve_date( $deadline_raw );

                $phase_tasks[] = array(
                    // Identity
                    'key'                     => $slug,
                    'label'                   => $tc_task->title,
                    'description'             => $tc_task->description ?? '',

                    // Behavioral — from portal config overrides, with defaults
                    'type'                    => $override['type']             ?? 'form',
                    'priority'                => 'medium', // Placeholder — will be computed dynamically from deadline proximity
                    'owner'                   => $override['owner']            ?? 'sponsor',
                    'optional'                => $override['optional']         ?? false,
                    'addon'                   => $override['addon']            ?? null,
                    'file_type'               => $override['file_type']        ?? null,
                    'provided_by'             => $override['provided_by']      ?? null,
                    'condition'               => $override['condition']        ?? null,
                    'completable'             => $override['completable']      ?? true,
                    'deadline_display'        => $override['deadline_display'] ?? '',

                    // Schedule
                    'deadline'                => $deadline,
                    'deadline_raw'            => $deadline_raw,

                    // Form / content
                    'form_key'                => $tc_task->form_key ?? null,
                    'field_keys'              => $tc_task->field_keys ?? array(),
                    'form_instructions'       => $tc_task->form_instructions ?? null,
                                        
                    'subtitle_html'           => $tc_task->form_instructions
                        ? wpautop( wp_kses_post( $tc_task->form_instructions ) )
                        : null,
                    
                    'requires_acknowledgment' => ! empty( $tc_task->requires_acknowledgment ),
                    'acknowledgment_text'     => $tc_task->acknowledgment_text ?? '',

                    // Sort
                    'sort_order'              => (int) ( $tc_task->sort_order ?? 0 ),
                );
            }

            // Resolve phase dates: shortcode key or date string → Y-m-d
            $start_date = self::resolve_date( $tc_phase->start_date ?? null );
            $end_date   = self::resolve_date( $tc_phase->end_date ?? null );

            // Date range display: use override if set, otherwise auto-format from resolved dates
            $date_range_content = $tc_phase->date_range_content ?? '';
            if ( ! empty( $date_range_content ) ) {
                // Resolve any shortcodes in the display override
                $date_range_content = do_shortcode( $date_range_content );
            } elseif ( $start_date && $end_date ) {
                // Auto-format from resolved dates
                $start_ts = strtotime( $start_date );
                $end_ts   = strtotime( $end_date );
                if ( $start_ts && $end_ts ) {
                    $date_range_content = date( 'M j', $start_ts )
                        . ' – '
                        . date( 'M j, Y', $end_ts );
                } else {
                    // Dates resolved but not parseable — show as-is
                    $date_range_content = $start_date . ' – ' . $end_date;
                }
            }

            $merged_phases[] = array(
                'key'                => $tc_phase->slug,
                'label'              => $tc_phase->label,
                'sort_order'         => (int) ( $tc_phase->sort_order ?? 0 ),
                'date_range_content' => $date_range_content,
                'start_date'         => $start_date ?: '',
                'end_date'           => $end_date ?: '',
                'submit_scope'       => 'task', // Default; override per-task via behavior
                'tasks'              => $phase_tasks,
                'sections'           => $tc->get_phase_sections( $tc_phase->id ),
            );
        }

        $this->phases_cache[ $type ] = $merged_phases;
        return $merged_phases;
    }

    /**
     * Get all tasks across all phases for an event type, flattened.
     *
     * @param string $type Event type slug.
     * @return array Array of task arrays, each with 'phase_key' injected.
     */
    public function get_all_tasks( $type ) {
        $tasks = array();
        foreach ( $this->get_phases( $type ) as $phase ) {
            foreach ( $phase['tasks'] ?? array() as $task ) {
                $task['phase_key']   = $phase['key'];
                $task['phase_label'] = $phase['label'];
                if ( ! isset( $task['submit_scope'] ) ) {
                    $task['submit_scope'] = $phase['submit_scope'] ?? 'task';
                }
                $tasks[] = $task;
            }
        }
        return $tasks;
    }

    /**
     * Get a specific task by key (slug).
     *
     * @param string $type     Event type slug.
     * @param string $task_key Task key (slug).
     * @return array|null
     */
    public function get_task( $type, $task_key ) {
        foreach ( $this->get_all_tasks( $type ) as $task ) {
            if ( $task['key'] === $task_key ) {
                return $task;
            }
        }
        return null;
    }

    /* ───────────────────────────────────────────
     * DEADLINES
     * ─────────────────────────────────────────── */

    /**
     * Get all deadlines for an event type, sorted by date.
     *
     * @param string $type Event type slug.
     * @return array [ ['date' => '2026-11-04', 'task_key' => '...', 'label' => '...'], ... ]
     */
    public function get_deadlines( $type ) {
        $deadlines = array();
        foreach ( $this->get_all_tasks( $type ) as $task ) {
            $date = $task['deadline'] ?? null;
            if ( $date ) {
                $deadlines[] = array(
                    'date'      => $date,
                    'task_key'  => $task['key'],
                    'label'     => $task['label'],
                    'owner'     => $task['owner'] ?? 'sponsor',
                    'phase_key' => $task['phase_key'],
                );
            }
        }
        usort( $deadlines, function ( $a, $b ) {
            return strcmp( $a['date'], $b['date'] );
        });
        return $deadlines;
    }

    /**
     * Run a deadline health check for all tasks in an event type.
     *
     * Returns a diagnostic array for every task, showing:
     *   - The raw deadline value from the TC plugin
     *   - Whether it resolved to a valid Y-m-d date
     *   - The resolved date (or null)
     *   - The phase it belongs to
     *
     * Tasks with no deadline are included with status 'none'.
     * Tasks whose deadline_raw is a shortcode key that failed to
     * resolve are flagged as 'failed'.
     *
     * @param string $type Event type slug.
     * @return array {
     *     @type array[] $tasks   Per-task results.
     *     @type int     $total   Total tasks with deadlines.
     *     @type int     $ok      Successfully resolved.
     *     @type int     $failed  Failed to resolve.
     *     @type int     $none    Tasks with no deadline set.
     * }
     */
    public function get_deadline_health( $type ) {
        $tasks  = $this->get_all_tasks( $type );
        $results = array();
        $ok      = 0;
        $failed  = 0;
        $none    = 0;

        foreach ( $tasks as $task ) {
            $raw      = $task['deadline_raw'] ?? null;
            $resolved = $task['deadline'] ?? null;

            if ( empty( $raw ) ) {
                $status = 'none';
                $none++;
            } elseif ( $resolved && preg_match( '/^\d{4}-\d{2}-\d{2}$/', $resolved ) ) {
                $status = 'ok';
                $ok++;
            } else {
                $status = 'failed';
                $failed++;
            }

            $results[] = array(
                'task_key'    => $task['key'],
                'task_label'  => $task['label'],
                'phase_key'   => $task['phase_key'] ?? '',
                'phase_label' => $task['phase_label'] ?? '',
                'deadline_raw' => $raw,
                'deadline'     => $resolved,
                'status'       => $status,
            );
        }

        return array(
            'tasks'  => $results,
            'total'  => $ok + $failed,
            'ok'     => $ok,
            'failed' => $failed,
            'none'   => $none,
        );
    }

    /* ───────────────────────────────────────────
     * FILE TYPES
     * ─────────────────────────────────────────── */

    /**
     * Get file types for an event type.
     *
     * @param string $type Event type slug.
     * @return array Keyed by file type slug.
     */
    public function get_file_types( $type ) {
        return $this->portal_config[ $type ]['file_types'] ?? array();
    }

    /**
     * Get a specific file type config.
     *
     * @param string $type      Event type slug.
     * @param string $file_type File type slug.
     * @return array|null
     */
    public function get_file_type( $type, $file_type ) {
        return $this->portal_config[ $type ]['file_types'][ $file_type ] ?? null;
    }

    /* ───────────────────────────────────────────
     * ADD-ONS
     * ─────────────────────────────────────────── */

    /**
     * Get add-ons for an event type.
     *
     * Reads tasks from the 'manage-add-ons' phase in the TC plugin.
     * Each task in that phase is an add-on. The addon slug (used for
     * session_meta keys like 'addon_push_notification') is derived from
     * the task slug by stripping the '-addon' suffix and converting
     * hyphens to underscores.
     *
     * Returns:
     *   addon_slug => [
     *       'label'      => task title,
     *       'cutoff'     => resolved deadline (Y-m-d),
     *       'task_key'   => TC task slug,
     *       'form_key'   => Formidable form key,
     *       'field_keys' => Formidable field keys,
     *   ]
     *
     * @param string $type Event type slug.
     * @return array Keyed by addon slug.
     */
    public function get_addons( $type ) {
        $phases = $this->get_phases( $type );
        $addons = array();

        foreach ( $phases as $phase ) {
            if ( $phase['key'] !== 'manage-add-ons' ) {
                continue;
            }

            foreach ( $phase['tasks'] as $task ) {
                // Derive addon slug: 'push-notification-addon' → 'push_notification'
                $addon_slug = $task['key'];
                $addon_slug = preg_replace( '/-addon$/', '', $addon_slug );
                $addon_slug = str_replace( '-', '_', $addon_slug );

                $addons[ $addon_slug ] = array(
                    'label'      => $task['label'],
                    'cutoff'     => $task['deadline'] ?? '',
                    'task_key'   => $task['key'],
                    'form_key'   => $task['form_key'] ?? '',
                    'field_keys' => $task['field_keys'] ?? array(),
                );
            }
            break; // Only one manage-add-ons phase
        }

        return $addons;
    }

    /**
     * Compute add-on states for a session.
     *
     * Returns a map of addon_slug => state, where state is one of:
     *   'active'    — Confirmed via Smartsheet meta or requested via form
     *   'declined'  — Sponsor explicitly selected "No" in the form
     *   'available' — No response yet (form field empty or not submitted)
     *
     * Two-tier detection:
     *   1. Session meta 'addon_{slug}' = 'yes'/'1'/'true'/'hold' (Smartsheet-confirmed — authoritative)
     *   2. Formidable field value from TC plugin field_keys (sponsor self-service request)
     *
     * Smartsheet meta always wins — if logistics confirms the add-on,
     * the form "No" is overridden.
     *
     * @param array  $session_meta All session meta for this session.
     * @param array  $session_data Full merged session + Formidable data.
     * @param string $event_type   Event type slug.
     * @return array addon_slug => 'active'|'declined'|'available'
     */
    public function compute_addon_states( $session_meta, $session_data, $event_type = 'satellite' ) {
        $states     = array();
        $addon_defs = $this->get_addons( $event_type );

        foreach ( $addon_defs as $addon_slug => $addon ) {
            $meta_key = 'addon_' . $addon_slug;

            // Tier 1: Session meta (Smartsheet-confirmed — authoritative)
            $meta_val = strtolower( trim( (string) ( $session_meta[ $meta_key ] ?? '' ) ) );
            if ( in_array( $meta_val, array( 'yes', '1', 'true', 'hold' ), true ) ) {
                $states[ $addon_slug ] = 'active';
                continue;
            }

            // Tier 2: Formidable request field from TC plugin field_keys
            $field_key = ! empty( $addon['field_keys'] ) ? $addon['field_keys'][0] : '';
            if ( ! $field_key ) {
                $states[ $addon_slug ] = 'available';
                continue;
            }

            $raw_value = $session_data[ $field_key ] ?? '';

            // Normalize: checkbox arrays come as ['Request...'] or ['No']
            if ( is_array( $raw_value ) ) {
                $raw_value = implode( ' ', $raw_value );
            }
            $raw_value = strtolower( trim( (string) $raw_value ) );

            if ( $raw_value === '' ) {
                $states[ $addon_slug ] = 'available';
            } elseif ( in_array( $raw_value, array( 'no', 'decline', 'declined', 'not interested' ), true ) ) {
                $states[ $addon_slug ] = 'declined';
            } else {
                $states[ $addon_slug ] = 'active';
            }
        }

        return $states;
    }

    /**
     * Get active/purchased add-on slugs (convenience wrapper).
     *
     * @param array  $session_meta All session meta for this session.
     * @param array  $session_data Full merged session + Formidable data.
     * @param string $event_type   Event type slug.
     * @return array List of active add-on slugs.
     */
    public function get_purchased_addons( $session_meta, $session_data, $event_type = 'satellite' ) {
        $states = $this->compute_addon_states( $session_meta, $session_data, $event_type );
        return array_keys( array_filter( $states, function ( $s ) { return $s === 'active'; } ) );
    }

    /* ───────────────────────────────────────────
     * VENDOR VIEWS
     * ─────────────────────────────────────────── */

    /**
     * Get vendor view configs for an event type.
     *
     * @param string $type Event type slug.
     * @return array Keyed by vendor type slug.
     */
    public function get_vendor_views( $type ) {
        return $this->portal_config[ $type ]['vendor_views'] ?? array();
    }

    /* ───────────────────────────────────────────
     * SMARTSHEET MAPPING
     * ─────────────────────────────────────────── */

    /**
     * Get the full Smartsheet field map.
     *
     * @return array
     */
    public function get_smartsheet_map() {
        return $this->smartsheet_map;
    }

    /**
     * Get the Smartsheet column config for a portal field.
     *
     * @param string $field_key Portal field key.
     * @return array|null ['column_id' => '...', 'type' => '...']
     */
    public function get_smartsheet_column( $field_key ) {
        return $this->smartsheet_map[ $field_key ] ?? null;
    }

    /* ───────────────────────────────────────────
     * HELPERS
     * ─────────────────────────────────────────── */

    /**
     * Resolve a date value that may be a Y-m-d string or a shortcode key.
     *
     * Accepts either:
     *   - A parseable date string (e.g. '2026-11-04', 'November 4, 2026')
     *   - A shortcode key (e.g. 'satellite-title-deadline') which resolves
     *     via do_shortcode('[satellite-title-deadline]')
     *
     * @param string|null $raw Raw date value from TC plugin.
     * @return string|null Y-m-d date string, or null if unresolvable.
     */
    private static function resolve_date( $raw ) {
        if ( empty( $raw ) ) {
            return null;
        }

        // Try parsing as a date directly
        $ts = strtotime( $raw );
        if ( $ts !== false ) {
            return date( 'Y-m-d', $ts );
        }

        // It's a shortcode key — resolve via do_shortcode
        $resolved = do_shortcode( '[' . $raw . ']' );
        if ( $resolved !== '[' . $raw . ']' ) {
            // Strip HTML tags and clean whitespace (shortcodes may return wrapped content)
            $clean = trim( wp_strip_all_tags( $resolved ) );
            $ts = strtotime( $clean );
            if ( $ts !== false ) {
                return date( 'Y-m-d', $ts );
            }
            // Shortcode resolved but not parseable as a date — return the clean value
            return $clean;
        }

        return null;
    }
}