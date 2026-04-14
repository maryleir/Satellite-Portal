<?php
/**
 * Task Content — Adapter bridging the satellite portal to the WSSP Task Content plugin.
 *
 * Delegates all reads to wssp_tc(), the global accessor exposed by the
 * wssp-task-content plugin. Returns TC task objects keyed by slug, each
 * with ->title, ->description, ->sections, ->requires_acknowledgment, etc.
 *
 * Content editing happens in the WSSP Task Content plugin admin UI —
 * this class is read-only.
 *
 * @package WSSP
 */

defined( 'ABSPATH' ) || exit;

class WSSP_Task_Content {

    /** @var WSSP_Config Portal config (retained for any future use). */
    private $config;

    /** @var string Portal slug used for TC plugin lookups. */
    private $portal_slug = 'satellite';

    /** @var array|null In-memory cache: slug => TC task object. */
    private $task_cache = null;

    /**
     * Constructor.
     *
     * @param WSSP_Config $config Portal configuration instance.
     */
    public function __construct( WSSP_Config $config ) {
        $this->config = $config;
    }

    /**
     * Get all task content for a session, keyed by task slug.
     *
     * This is the main method consumed by render_session_detail() and
     * passed to the dashboard templates as the $task_content variable.
     *
     * Returns full TC task objects (with ->sections) keyed by slug.
     * Content definitions are the same for all sessions — the $session_id
     * parameter exists for signature compatibility only.
     *
     * @param int    $session_id  Session ID (unused — kept for compatibility).
     * @param string $event_type  Event type slug (unused — portal slug is fixed).
     * @return array  slug => TC task object (with ->sections).
     */
    public function get_for_session( $session_id, $event_type ) {
        return $this->load_task_lookup();
    }

    /* ───────────────────────────────────────────
     * INTERNAL — load and cache TC plugin data
     * ─────────────────────────────────────────── */

    /**
     * Build (or return cached) lookup of TC task objects keyed by slug.
     *
     * Calls wssp_tc()->get_phases() and wssp_tc()->get_phase_tasks() to
     * batch-load every task with its sections in minimal DB queries.
     *
     * @return array  slug => TC task object
     */
    private function load_task_lookup() {
        if ( $this->task_cache !== null ) {
            return $this->task_cache;
        }

        $this->task_cache = array();

        $tc = wssp_tc();
        if ( ! $tc ) {
            return $this->task_cache;
        }

        $portal = $tc->get_portal( $this->portal_slug );
        if ( ! $portal ) {
            return $this->task_cache;
        }

        $phases = $tc->get_phases( $portal->id );
        foreach ( $phases as $phase ) {
            $tasks = $tc->get_phase_tasks( $phase->id );
            foreach ( $tasks as $task ) {
                $this->task_cache[ $task->slug ] = $task;
            }
        }

        return $this->task_cache;
    }
}