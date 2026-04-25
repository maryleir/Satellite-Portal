<?php
/**
 * Task Card — Individual task card inside a phase accordion.
 *
 * Layout (three rows matching portal.css):
 *   Row 1 (top):    checkbox + title + review badge  |  priority badge
 *   Row 2 (desc):   description text (indented past checkbox)
 *   Row 3 (bottom): status/deadline tag  |  action buttons
 *
 * Expected variables (set by dashboard-phases.php before include):
 *   $task          (array)   Enriched task from dashboard engine (has key, label, priority, type, etc.)
 *   $task_content  (array)   TC task objects keyed by slug. Each is an object with:
 *                              ->title, ->description, ->requires_acknowledgment,
 *                              ->form_key, ->field_keys, ->sections, etc.
 *   $session_id    (int)     Current session ID
 *   $event_type    (string)  Current event type slug
 *   $can_edit      (bool)    Whether the current user can edit/submit
 *   $task_statuses (array)   Task statuses keyed by task_key
 *
 * @package WSSP
 */

defined( 'ABSPATH' ) || exit;

// ─── Derived state ───
$task_key      = $task['key'];
$is_done       = ! empty( $task['is_done'] ) || ! empty( $task['is_submitted'] );
$is_info       = $task['type'] === 'info';
$completable   = $task['completable'] ?? true;
$priority      = $task['priority'] ?? 'medium';
$deadline      = $task['deadline'] ?? '';
$deadline_display = $task['deadline_display'] ?? '';
$owner       = $task['owner'] ?? 'sponsor';
$form_key    = $task['form_key'] ?? '';
$field_keys  = $task['field_keys'] ?? '';
$subtitle_html  = $task['subtitle_html'] ?? '';
if ( $subtitle_html ) {
    $subtitle_html = do_shortcode( $subtitle_html );
}

// Addon identity (still used by the form-completion gate and admin
// reactivate affordance below). Done-state for addons now follows the
// standard rule: status row only, no derived override. See
// sync_addon_task_statuses (SS import path) and
// apply_addon_request_triggers (form latch path) for how status is written.
$is_addon   = (bool) preg_match( '/-addon$/', $task_key );
$addon_slug = $is_addon ? str_replace( '-', '_', preg_replace( '/-addon$/', '', $task_key ) ) : '';

// SS-imported addon: task was auto-completed by the Smartsheet pull
// (submitted_by = 0 marker) with no sponsor form interaction. The
// Formidable entry is empty in this case, so opening "View Response"
// would just show blank checkboxes and confuse the sponsor. Detect this
// here so the render block below can show a contextual label instead.
$is_ss_imported_addon = false;
if ( $is_addon && $is_done && isset( $task_statuses[ $task_key ] ) ) {
    $status_row = $task_statuses[ $task_key ];
    $submitted_by = isset( $status_row['submitted_by'] ) ? (int) $status_row['submitted_by'] : -1;
    $is_ss_imported_addon = ( $submitted_by === 0 );
}

// Upload task state: detect file type and current file status
$is_upload   = $task['type'] === 'upload';
$file_type   = $task['file_type'] ?? '';
$active_file = null;
if ( $is_upload && $file_type && isset( $file_summary ) && is_array( $file_summary ) ) {
    $active_file = $file_summary[ $file_type ] ?? null;
}
$has_file        = (bool) $active_file;
$file_status     = $has_file ? ( $active_file['status'] ?? '' ) : '';
$file_version    = $has_file ? ( $active_file['version'] ?? 0 ) : 0;
$file_url        = $has_file ? ( $active_file['file_url'] ?? '' ) : '';
$file_orig_name  = $has_file ? ( $active_file['original_name'] ?? '' ) : '';

// TC task object (if content exists for this task)
$tc_task = $task_content[ $task_key ] ?? null;

// Only show "More Info" button if the task has sections with content
$has_modal = $tc_task && ! empty( $tc_task->sections );

// Description: prefer TC plugin description, fall back to task-display.php (transitional)
$description = '';
if ( $tc_task && ! empty( $tc_task->description ) ) {
    $description = $tc_task->description;
} elseif ( ! empty( $task_display['description'] ) ) {
    $description = $task_display['description'];
}

// Badge text: task-display.php override (e.g. "Optional"), otherwise use priority
$badge_text = $task_display['badge_text'] ?? '';

$field_keys_str = '';
if ( ! empty( $task['field_keys'] ) && is_array( $task['field_keys'] ) ) {
    $field_keys_str = implode( ',', $task['field_keys'] );
    $field_keys = $field_keys_str;
}

// Review Required state
$has_review      = $has_modal && ! empty( $tc_task->requires_acknowledgment );
$is_acknowledged = ! empty( $task_statuses[ $task_key ]['acknowledged_at'] );
$needs_ack       = $has_review && ! $is_acknowledged && ! $is_done;

// Form completion check: if this task has a form with fields, the checkbox
// should be disabled until the sponsor has filled in at least one field.
// Upload tasks are excluded — they use the file upload system, not Formidable forms.
$form_incomplete = false;
if ( $form_key && ! $is_upload && ! empty( $task['field_keys'] ) && is_array( $task['field_keys'] ) && ! $is_done ) {
    $form_incomplete = true;
    if ( isset( $session_data ) && is_array( $session_data ) ) {
        foreach ( $task['field_keys'] as $fk ) {
            $val = $session_data[ $fk ] ?? '';
            if ( is_array( $val ) ) {
                $val = implode( '', $val );
            }
            if ( $val !== '' ) {
                $form_incomplete = false;
                break;
            }
        }
    }
}

// Checkbox state — upload tasks are never completable via checkbox
// (they're completed by logistics file approval)
$checkbox_disabled = $is_info || $is_upload || ! $can_edit || $needs_ack || $form_incomplete;

// Admin reactivation: admins can uncheck completed tasks.
// Non-admins cannot uncheck — the checkbox stays disabled once done.
$admin_can_reactivate = $is_done && $is_admin && ! $is_info && ! $is_upload;
if ( $is_done && ! $admin_can_reactivate ) {
    $checkbox_disabled = true;
}

// Conditional visibility — task is in the DOM but hidden by condition evaluator
$is_hidden = ! empty( $task['is_hidden'] );

// Effective "today" (supports admin date override for testing)
$today_ts = strtotime( WSSP_Date_Override::get_today() );

// ─── Dynamic priority from deadline proximity ───
// Overrides the static 'medium' placeholder from config.
// Only applies to non-info, non-done tasks with deadlines.
// Tasks without a deadline get no priority badge.
if ( $is_info || $is_done ) {
    $priority = '';
} elseif ( $deadline ) {
    $deadline_ts = strtotime( $deadline );
    if ( $deadline_ts ) {
        $days_until = (int) floor( ( $deadline_ts - $today_ts ) / 86400 );
        if ( $days_until < 0 ) {
            $priority = 'overdue';
        } elseif ( $days_until <= 3 ) {
            $priority = 'high';
        } elseif ( $days_until <= 14 ) {
            $priority = 'medium';
        } else {
            $priority = 'low';
        }
    } else {
        $priority = '';
    }
} else {
    $priority = '';
}

// Card CSS classes
$card_classes = array( 'wssp-task-card' );
if ( $is_done )    $card_classes[] = 'wssp-task-card--done';
if ( $is_info )    $card_classes[] = 'wssp-task-card--info';
if ( $needs_ack )  $card_classes[] = 'wssp-task-card--needs-review';
if ( $priority === 'overdue' ) {
    $card_classes[] = 'wssp-task-card--overdue';
}

// Status tag — always show the deadline date, add context for overdue
$status_class = '';
$status_label = '';
if ( $is_done ) {
    $status_label = 'Completed';
    $status_class = 'wssp-task-card__status-tag--completed';
} elseif ( $deadline_display ) {
    $status_label = $deadline_display;
    $status_class = 'wssp-task-card__status-tag--upcoming';
} elseif ( $deadline && ! $is_info ) {
    $deadline_ts = strtotime( $deadline );
    if ( $deadline_ts ) {
        $days_until = (int) floor( ( $deadline_ts - $today_ts ) / 86400 );
        if ( $days_until < 0 ) {
            $days_overdue = abs( $days_until );
            $status_label = 'Due ' . date( 'M j', $deadline_ts ) . ' (' . $days_overdue . 'd overdue)';
            $status_class = 'wssp-task-card__status-tag--overdue';
        } else {
            $status_label = 'Due ' . date( 'M j', $deadline_ts );
            $status_class = 'wssp-task-card__status-tag--upcoming';
        }
    }
}
?>

<div class="<?php echo esc_attr( implode( ' ', $card_classes ) ); ?>"
     data-task-key="<?php echo esc_attr( $task_key ); ?>"
     data-status="<?php echo esc_attr( $task['status'] ?? '' ); ?>"
     <?php if ( $is_hidden ) echo 'style="display:none;"'; ?>>

    <!-- ═══ Row 1: Checkbox + Title + Review Badge  |  Priority ═══ -->
    <div class="wssp-task-card__row wssp-task-card__row--top">
        <div class="wssp-task-card__left">
            <?php if ( ! $is_info && $completable ) : ?>
                <label class="wssp-task-card__checkbox <?php echo $is_done ? 'wssp-task-card__checkbox--checked' : ''; ?>">
                    <input type="checkbox" class="wssp-task-checkbox"
                           data-task-key="<?php echo esc_attr( $task_key ); ?>"
                           data-session-id="<?php echo esc_attr( $session_id ); ?>"
                           <?php if ( $admin_can_reactivate ) echo 'data-admin-reactivate="1"'; ?>
                           <?php checked( $is_done ); ?>
                           <?php disabled( $checkbox_disabled ); ?>>
                    <span class="wssp-task-card__checkmark">
                        <?php if ( $is_done ) : ?>
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="white" stroke-width="3">
                                <polyline points="20 6 9 17 4 12"/>
                            </svg>
                        <?php endif; ?>
                    </span>
                </label>
            <?php else : ?>
                <div class="wssp-task-card__checkbox-spacer"></div>
            <?php endif; ?>

            <div class="wssp-task-card__title-area">
                <h4 class="wssp-task-card__title"><?php echo wssp_render_field( $task['label'], 'plain' ); ?></h4>

                <?php if ( $needs_ack ) : ?>
                    <span class="wssp-badge wssp-badge--review">
                        <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
                            <path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/>
                            <line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/>
                        </svg>
                        Review Required
                    </span>
                <?php elseif ( $has_review && $is_acknowledged && ! $is_done ) : ?>
                    <span class="wssp-badge wssp-badge--acknowledged">Acknowledged</span>
                <?php endif; ?>
            </div>
        </div>
        <div class="wssp-task-card__right">
            <?php if ( $priority ) : ?>
                <span class="wssp-badge wssp-badge--priority-<?php echo esc_attr( $priority ); ?>">
                    <?php echo esc_html( $badge_text ?: $priority ); ?>
                </span>
            <?php endif; ?>
        </div>
    </div>

    <!-- ═══ Row 2: Description ═══ -->
    <?php if ( $description ) : ?>
        <div class="wssp-task-card__row wssp-task-card__row--desc">
            <div class="wssp-task-card__description"><?php echo wssp_render_field( $description, 'rich' ); ?></div>
        </div>
    <?php endif; ?>

    <?php // ─── Upload file indicator (shows current file with view link) ─── ?>
    <?php if ( $is_upload && $has_file && $file_url ) : ?>
        <div class="wssp-task-card__row wssp-task-card__row--file">
            <div class="wssp-task-card__file-info">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/>
                    <polyline points="14 2 14 8 20 8"/>
                </svg>
                <a href="<?php echo esc_url( $file_url ); ?>" class="wssp-task-card__file-link" target="_blank">
                    <?php echo esc_html( $file_orig_name ?: 'View file' ); ?>
                </a>
                <span class="wssp-task-card__file-version">v<?php echo esc_html( $file_version ); ?></span>
                <?php
                    $file_status_class = 'pending';
                    if ( strpos( $file_status, 'Approved' ) !== false ) $file_status_class = 'approved';
                    elseif ( strpos( $file_status, 'Changes Required' ) !== false ) $file_status_class = 'changes-required';
                ?>
                <span class="wssp-task-card__file-status wssp-task-card__file-status--<?php echo esc_attr( $file_status_class ); ?>">
                    <?php echo esc_html( $file_status ); ?>
                </span>
            </div>
        </div>
    <?php endif; ?>

    <!-- ═══ Row 3: Status Tag  |  Action Buttons ═══ -->
    <div class="wssp-task-card__row wssp-task-card__row--bottom">
        <div class="wssp-task-card__bottom-left">
            <?php if ( $status_label ) : ?>
                <span class="wssp-task-card__status-tag <?php echo esc_attr( $status_class ); ?>">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/>
                    </svg>
                    <?php echo esc_html( $status_label ); ?>
                </span>
            <?php endif; ?>
        </div>

        <div class="wssp-task-card__bottom-right">
            <?php if ( $needs_ack ) : ?>
                <button class="wssp-btn wssp-btn--review wssp-open-modal"
                        data-task-key="<?php echo esc_attr( $task_key ); ?>"
                        data-modal-type="review_required">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
                        <path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/>
                        <line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/>
                    </svg>
                    Review Required
                </button>
            <?php elseif ( $has_modal ) : ?>
                <button class="wssp-btn wssp-btn--info wssp-open-modal"
                        data-task-key="<?php echo esc_attr( $task_key ); ?>"
                        data-modal-type="more_info">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path d="M2 3h6a4 4 0 0 1 4 4v14a3 3 0 0 0-3-3H2z"/>
                        <path d="M22 3h-6a4 4 0 0 0-4 4v14a3 3 0 0 1 3-3h7z"/>
                    </svg>
                    More Info
                </button>
            <?php endif; ?>
            <?php if ( $is_upload && ! $is_info ) : ?>
                <?php if ( $is_done && $has_file ) : ?>
                    <!-- Completed upload: view files -->
                    <button class="wssp-btn wssp-btn--info wssp-open-upload-drawer"
                            data-task-key="<?php echo esc_attr( $task_key ); ?>"
                            data-task-label="<?php echo esc_attr( $task['label'] ); ?>"
                            data-session-id="<?php echo esc_attr( $session_id ); ?>"
                            data-file-type="<?php echo esc_attr( $file_type ); ?>"
                            data-readonly="true"
                            <?php if ( $subtitle_html ) : ?>
                                data-subtitle-html="<?php echo esc_attr( $subtitle_html ); ?>"
                            <?php endif; ?>>
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/>
                            <polyline points="14 2 14 8 20 8"/>
                        </svg>
                        View Files
                    </button>
                <?php elseif ( ! $is_done && $can_edit ) : ?>
                    <!-- Active upload: upload or manage files -->
                    <button class="wssp-btn wssp-btn--upload wssp-open-upload-drawer"
                            data-task-key="<?php echo esc_attr( $task_key ); ?>"
                            data-task-label="<?php echo esc_attr( $task['label'] ); ?>"
                            data-session-id="<?php echo esc_attr( $session_id ); ?>"
                            data-file-type="<?php echo esc_attr( $file_type ); ?>"
                            <?php if ( $subtitle_html ) : ?>
                                data-subtitle-html="<?php echo esc_attr( $subtitle_html ); ?>"
                            <?php endif; ?>>
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/>
                            <polyline points="17 8 12 3 7 8"/>
                            <line x1="12" y1="3" x2="12" y2="15"/>
                        </svg>
                        <?php echo $has_file ? 'Manage Files' : 'Upload File'; ?>
                    </button>
                <?php endif; ?>
            <?php elseif ( $form_key && ! $is_info && $is_done ) : ?>
                <?php if ( $is_ss_imported_addon ) : ?>
                    <!-- SS-imported addon: no form response to show.
                         Display a static status label instead of opening the
                         empty Formidable entry. -->
                    <span class="wssp-ss-imported-note">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                            <path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/>
                            <polyline points="22 4 12 14.01 9 11.01"/>
                        </svg>
                        Purchased on application
                    </span>
                <?php else : ?>
                    <!-- Completed task: view-only access to submitted responses -->
                    <button class="wssp-btn wssp-btn--info wssp-open-form-drawer"
                            data-form-key="<?php echo esc_attr( $form_key ); ?>"
                            data-task-key="<?php echo esc_attr( $task_key ); ?>"
                            data-task-label="<?php echo esc_attr( $task['label'] ); ?>"
                            data-field-keys="<?php echo esc_attr( $field_keys ); ?>"  
                            data-session-id="<?php echo esc_attr( $session_id ); ?>"
                            data-readonly="true"
                            <?php if ( $subtitle_html ) : ?>
                                data-subtitle-html="<?php echo esc_attr( $subtitle_html ); ?>"
                            <?php endif; ?>>
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/>
                            <circle cx="12" cy="12" r="3"/>
                        </svg>
                        View Response
                    </button>
                <?php endif; ?>
            <?php elseif ( $form_key && ! $is_info && ! $is_done && ! $needs_ack ) : ?>
                <!-- Active task: editable form -->
                <button class="wssp-btn wssp-btn--form wssp-open-form-drawer"
                        data-form-key="<?php echo esc_attr( $form_key ); ?>"
                        data-task-key="<?php echo esc_attr( $task_key ); ?>"
                        data-task-label="<?php echo esc_attr( $task['label'] ); ?>"
                        data-field-keys="<?php echo esc_attr( $field_keys ); ?>"  
                        data-session-id="<?php echo esc_attr( $session_id ); ?>"
                        <?php if ( $subtitle_html ) : ?>
                            data-subtitle-html="<?php echo esc_attr( $subtitle_html ); ?>"
                        <?php endif; ?>>
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/>
                        <polyline points="14 2 14 8 20 8"/>
                    </svg>
                    Open Form
                </button>
            <?php endif; ?>
        </div>
    </div>
</div>