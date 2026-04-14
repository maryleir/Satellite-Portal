<?php
/**
 * Dashboard Phases — Phase accordions with task cards.
 *
 * Renders the "Tasks & Deadlines" section of the portal dashboard.
 * Each phase is a collapsible accordion containing its task cards.
 *
 * Expected variables (set by class-wssp-public.php):
 *   $phases            (array)  Enriched phases from WSSP_Dashboard
 *   $current_phase_key (string) Key of the current/active phase
 *   $session_id        (int)    Current session ID
 *   $event_type        (string) Current event type slug
 *   $config            (array)  Full event type config
 *   $can_edit          (bool)   Whether the current user can submit
 *   $task_content      (array)  TC task objects keyed by slug (task_key)
 *   $task_statuses     (array)  Task statuses keyed by task_key
 *
 * Uses: WSSP_Config (via $this->config in the public class, passed here)
 *
 * @package WSSP
 */

defined( 'ABSPATH' ) || exit;

// $wssp_config is the WSSP_Config instance — set by render_session_detail()
// before including this template.
$wssp_config = $this->config;

if ( empty( $phases ) ) {
    echo '<div class="wssp-notice wssp-notice--info">No tasks are configured for this session type.</div>';
    return;
}

$phase_number = 0;
?>

<div class="wssp-tasks-section">
    <div class="wssp-tasks-section__header">
        <h3 class="wssp-tasks-section__title">Tasks &amp; Deadlines</h3>
        <p class="wssp-tasks-section__subtitle">Complete all required tasks before their deadlines</p>
    </div>

    <div class="wssp-phases">

    <?php foreach ( $phases as $phase ) :
        $phase_number++;
        $phase_key   = $phase['key'];
        $phase_label = $phase['label'];
        $tasks       = $phase['tasks'] ?? array();

        // Determine phase status (only 'completed', 'overdue', or '' from engine)
        $phase_status = $phase['status'] ?? '';
        $is_current   = $phase_key === $current_phase_key;
        $is_expanded  = $is_current || $phase_status === 'overdue';

        // Count completions (skip info-only and conditionally hidden tasks)
        $total_actionable = 0;
        $total_done       = 0;
        foreach ( $tasks as $t ) {
            if ( $t['type'] === 'info' ) continue;
            if ( ! empty( $t['is_hidden'] ) ) continue;
            if ( ! ( $t['completable'] ?? true ) ) continue;

            // Skip add-on gated tasks that aren't purchased
            $t_addon = $t['addon'] ?? null;
            if ( $t_addon && isset( $purchased_addons ) && ! in_array( $t_addon, $purchased_addons, true ) ) {
                continue;
            }

            $total_actionable++;

            // Check if done: dashboard engine status OR add-on responded OR submitted
            $t_done = ! empty( $t['is_done'] ) || ! empty( $t['is_submitted'] );

            // Add-on selection tasks: done when sponsor has responded
            if ( ! $t_done && preg_match( '/-addon$/', $t['key'] ) ) {
                $t_addon_slug = str_replace( '-', '_', preg_replace( '/-addon$/', '', $t['key'] ) );
                $t_addon_state = isset( $addon_states ) ? ( $addon_states[ $t_addon_slug ] ?? 'available' ) : 'available';
                if ( in_array( $t_addon_state, array( 'active', 'declined' ), true ) ) {
                    $t_done = true;
                }
            }

            if ( $t_done ) $total_done++;
        }

        // Phase status badge (only show for completed/overdue)
        $status_badges = array(
            'completed' => 'Completed',
            'overdue'   => 'Overdue',
        );
        $status_badge = $status_badges[ $phase_status ] ?? '';

        // Phase-level sections (content for the phase info modal)
        $phase_sections   = $phase['sections'] ?? array();
        $has_phase_modal  = ! empty( $phase_sections );
    ?>

        <div class="wssp-phase <?php if ( $phase_status ) echo esc_attr( 'wssp-phase--' . $phase_status ); ?>"
             data-phase-key="<?php echo esc_attr( $phase_key ); ?>">

            <!-- Phase Header (clickable accordion toggle) -->
            <div class="wssp-phase__header" role="button" tabindex="0"
                 aria-expanded="<?php echo $is_expanded ? 'true' : 'false'; ?>">

                <div class="wssp-phase__header-left">
                    <span class="wssp-phase__number"><?php echo esc_html( $phase_number ); ?></span>
                    <div class="wssp-phase__header-info">
                        <span class="wssp-phase__label"><?php echo esc_html( $phase_label ); ?></span>
                        <?php if ( $status_badge ) : ?>
                            <span class="wssp-badge wssp-badge--<?php echo esc_attr( $phase_status ); ?>">
                                <?php echo esc_html( $status_badge ); ?>
                            </span>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="wssp-phase__header-right">
                    <?php if ( $has_phase_modal ) : ?>
                        <button class="wssp-phase__info-btn wssp-open-modal"
                                data-task-key="phase-<?php echo esc_attr( $phase_key ); ?>"
                                data-modal-type="more_info"
                                title="Phase information"
                                aria-label="View phase information">
                            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round">
                                <circle cx="12" cy="12" r="10"/>
                                <line x1="12" y1="16" x2="12" y2="12"/>
                                <circle cx="12" cy="8" r="1.2" fill="currentColor" stroke="none"/>
                            </svg>
                        </button>
                    <?php endif; ?>
                    <svg class="wssp-phase__chevron" width="20" height="20" viewBox="0 0 24 24"
                         fill="none" stroke="currentColor" stroke-width="2">
                        <polyline points="6 9 12 15 18 9"/>
                    </svg>
                </div>
            </div>

            <!-- Phase Meta (progress count) -->
            <div class="wssp-phase__meta">
                <?php if ( $total_actionable > 0 ) : ?>
                    <span class="wssp-phase__progress <?php echo ( $total_done === $total_actionable ) ? 'wssp-phase__progress--done' : ''; ?>">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
                            <path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/>
                            <polyline points="22 4 12 14.01 9 11.01"/>
                        </svg>
                        <?php echo esc_html( $total_done . '/' . $total_actionable ); ?> completed
                    </span>
                <?php endif; ?>
            </div>

            <!-- Phase Body (task cards) -->
            <div class="wssp-phase__body" style="<?php echo $is_expanded ? '' : 'display:none;'; ?>">
                <?php foreach ( $tasks as $task ) :
                    // Display text previously came from config/task-display.php.
                    // Now sourced from TC plugin in task-card.php; this stays as
                    // an empty default so the template's fallback references don't error.
                    $task_display = array();

                    // Include the task card template
                    include WSSP_PLUGIN_DIR . 'public/views/task-card.php';
                endforeach; ?>

                <?php
                // Phase-level submit button (if submit_scope is 'phase')
                $submit_scope = $phase['submit_scope'] ?? 'task';
                if ( $submit_scope === 'phase' && $can_edit && $total_done < $total_actionable ) :
                ?>
                    <div class="wssp-phase__submit">
                        <button class="wssp-btn wssp-btn--primary wssp-submit-phase"
                                data-phase-key="<?php echo esc_attr( $phase_key ); ?>"
                                data-session-id="<?php echo esc_attr( $session_id ); ?>">
                            Submit All
                        </button>
                    </div>
                <?php endif; ?>
            </div>
        </div>

    <?php endforeach; ?>
    </div><!-- .wssp-phases -->
</div><!-- .wssp-tasks-section -->