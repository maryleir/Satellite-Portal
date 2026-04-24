<?php
/**
 * Task Modal — Review Required and More Info modal overlays.
 *
 * This template renders a hidden modal container for each task
 * that has content in the WSSP Task Content plugin. JavaScript opens/closes them.
 *
 * Expected variables (set by class-wssp-public.php render_session_detail):
 *   $task_content  (array)  TC task objects keyed by slug (task_key).
 *                           Each entry is an object with:
 *                             ->title, ->description, ->slug, ->deadline,
 *                             ->requires_acknowledgment, ->acknowledgment_text,
 *                             ->sections => [ { ->heading, ->content, ->section_type }, ... ]
 *   $phases        (array)  Enriched phase data from WSSP_Dashboard (has priority, type, etc.)
 *   $session_id    (int)    Current session ID.
 *   $task_statuses (array)  Task statuses keyed by task_key (for acknowledged_at check).
 *
 * @package WSSP
 */

defined( 'ABSPATH' ) || exit;

if ( empty( $task_content ) ) return;

// Build a flat lookup of enriched task config from the dashboard phases
// so we can show priority badges, type labels, etc. in the modal header.
$_task_config_lookup = array();
foreach ( $phases ?? array() as $_phase ) {
    foreach ( $_phase['tasks'] ?? array() as $_t ) {
        $_task_config_lookup[ $_t['key'] ] = $_t;
    }
}
?>

<!-- Modal Backdrop -->
<div class="wssp-modal-backdrop" style="display:none;"></div>

<?php foreach ( $task_content as $task_key => $content ) :
    // $content is now a TC task object (stdClass), not a flat array.
    $is_review   = ! empty( $content->requires_acknowledgment );
    $is_acked    = ! empty( $task_statuses[ $task_key ]['acknowledged_at'] );
    $modal_class = ( $is_review && ! $is_acked ) ? 'wssp-modal--review' : 'wssp-modal--info';


    // Enriched task config from dashboard engine (priority, type, etc.)
    $task_config = $_task_config_lookup[ $task_key ] ?? array();
    $priority    = $task_config['priority'] ?? 'medium';
    $type_label  = '';
    if ( ! empty( $task_config['type'] ) ) {
        $type_labels = array( 'form' => 'Form Required', 'upload' => 'Upload Required', 'approval' => 'Approval Required' );
        $type_label  = $type_labels[ $task_config['type'] ] ?? '';
    }

    // Deadline: prefer the TC plugin's deadline (may be a shortcode key),
    // fall back to the enriched config deadline.
    $deadline_raw = $content->deadline ?: ( $task_config['deadline'] ?? $task_config['date'] ?? '' );
    $deadline_display = '';
    if ( $deadline_raw ) {
        $deadline_display = WSSP_TC_Task_Content::resolve_deadline( $deadline_raw );
    }
?>
    <div class="wssp-modal <?php echo esc_attr( $modal_class ); ?>"
         data-task-key="<?php echo esc_attr( $task_key ); ?>"
         data-modal-type="<?php echo ( $is_review && ! $is_acked ) ? 'review_required' : 'more_info'; ?>"
         style="display:none;">

        <div class="wssp-modal__dialog">
            <!-- Close button -->
            <button class="wssp-modal__close" aria-label="Close">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/>
                </svg>
            </button>

            <!-- Header -->
            <div class="wssp-modal__header">
                <?php if ( $is_review ) : ?>
                    <div class="wssp-modal__icon wssp-modal__icon--review">
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/>
                            <polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/>
                            <line x1="16" y1="17" x2="8" y2="17"/><polyline points="10 9 9 9 8 9"/>
                        </svg>
                    </div>
                <?php endif; ?>
                <div class="wssp-modal__header-text">
                    <h2 class="wssp-modal__title"><?php echo wssp_render_field( $content->title, 'plain' ); ?></h2>
                    <div class="wssp-modal__tags">
                        <?php if ( $priority === 'high' ) : ?>
                            <span class="wssp-badge wssp-badge--priority-high">high priority</span>
                        <?php endif; ?>
                        <?php if ( $deadline_display ) : ?>
                            <span class="wssp-badge wssp-badge--outline">Due: <?php echo wssp_render_field( $deadline_display, 'plain' ); ?></span>
                        <?php endif; ?>
                        <?php if ( $type_label ) : ?>
                            <span class="wssp-badge wssp-badge--outline"><?php echo wssp_render_field( $type_label, 'plain' ); ?></span>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <hr class="wssp-modal__divider">

            <!-- Content Body — dynamic sections from TC plugin -->
            <div class="wssp-modal__body">
                <?php if ( ! empty( $content->sections ) ) :
                    foreach ( $content->sections as $section ) :
                        if ( empty( $section->content ) ) continue;
                ?>
                    <div class="wssp-modal__section">
                        <?php if ( ! empty( $section->heading ) ) : ?>
                            <h3 class="wssp-modal__section-title"><?php echo wssp_render_field( $section->heading, 'plain' ); ?></h3>
                        <?php endif; ?>
                        <div class="wssp-modal__section-content wssp-modal__<?php echo esc_attr( $section->section_type ); ?>">
                            <?php echo wssp_render_field( $section->content, 'rich' ); ?>
                        </div>
                    </div>
                <?php
                    endforeach;
                endif;
                ?>
            </div>

            <?php if ( $is_review && ! $is_acked ) : ?>
                <hr class="wssp-modal__divider">

                <!-- Acknowledgment — pending -->
                <div class="wssp-modal__acknowledgment">
                    <label class="wssp-modal__ack-label">
                        <input type="checkbox" class="wssp-modal__ack-checkbox"
                               data-task-key="<?php echo esc_attr( $task_key ); ?>"
                               data-session-id="<?php echo esc_attr( $session_id ); ?>">
                        <span class="wssp-modal__ack-checkmark"></span>
                        <span class="wssp-modal__ack-text">
                            <?php echo wssp_render_field( $content->acknowledgment_text ?: 'I have reviewed the above requirements and understand the obligations', 'plain' ); ?>
                        </span>
                    </label>
                </div>
            <?php elseif ( $is_review && $is_acked ) : ?>
                <hr class="wssp-modal__divider">

                <!-- Acknowledgment — confirmed -->
                <div class="wssp-modal__acknowledgment wssp-modal__acknowledgment--confirmed">
                    <div class="wssp-modal__ack-confirmed">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
                            <path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/>
                            <polyline points="22 4 12 14.01 9 11.01"/>
                        </svg>
                        <span class="wssp-modal__ack-text">
                            <?php echo wssp_render_field( $content->acknowledgment_text ?: 'I have reviewed the above requirements and understand the obligations', 'plain' ); ?>
                        </span>
                    </div>
                </div>
            <?php endif; ?>

            <!-- Footer -->
            <div class="wssp-modal__footer">
                <button class="wssp-btn wssp-btn--outline wssp-modal__close-btn">Close</button>
            </div>
        </div>
    </div>
<?php endforeach; ?>

<?php
/* ───────────────────────────────────────────
 * PHASE-LEVEL MODALS
 * Rendered for phases that have sections in the TC plugin.
 * Uses data-task-key="phase-{slug}" to avoid collisions with task modals.
 * ─────────────────────────────────────────── */
foreach ( $phases ?? array() as $_phase ) :
    $phase_sections = $_phase['sections'] ?? array();
    if ( empty( $phase_sections ) ) continue;

    $phase_key   = $_phase['key'];
    $phase_label = $_phase['label'];
?>
    <div class="wssp-modal wssp-modal--info"
         data-task-key="phase-<?php echo esc_attr( $phase_key ); ?>"
         data-modal-type="more_info"
         style="display:none;">

        <div class="wssp-modal__dialog">
            <button class="wssp-modal__close" aria-label="Close">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/>
                </svg>
            </button>

            <div class="wssp-modal__header">
                <div class="wssp-modal__header-text">
                    <h2 class="wssp-modal__title"><?php echo esc_html( $phase_label ); ?></h2>
                </div>
            </div>

            <hr class="wssp-modal__divider">

            <div class="wssp-modal__body">
                <?php foreach ( $phase_sections as $section ) :
                    if ( empty( $section->content ) ) continue;
                ?>
                    <div class="wssp-modal__section">
                        <?php if ( ! empty( $section->heading ) ) : ?>
                            <h3 class="wssp-modal__section-title"><?php echo wssp_render_field( $section->heading, 'plain' ); ?></h3>
                        <?php endif; ?>
                        <div class="wssp-modal__section-content wssp-modal__<?php echo esc_attr( $section->section_type ); ?>">
                            <?php echo wssp_render_field( $section->content, 'rich' ); ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>

            <div class="wssp-modal__footer">
                <button class="wssp-btn wssp-btn--outline wssp-modal__close-btn">Close</button>
            </div>
        </div>
    </div>
<?php endforeach; ?>