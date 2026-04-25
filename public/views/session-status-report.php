<?php
/**
 * Session Status Report — printable view.
 *
 * Self-contained page that renders one session's phase/task status as a
 * print-friendly document. Designed for the sponsor to open in a new tab
 * and use the browser's "Save as PDF" / "Print" function.
 *
 * Expects the same context variables that render_session_detail() produces:
 *   $session              — wp_wssp_sessions row (assoc)
 *   $event_label          — string
 *   $phases               — enriched phase array from WSSP_Dashboard
 *   $task_statuses        — keyed by task_key
 *   $task_content         — keyed by task_key, from WSSP_Task_Content
 *   $stats                — completed / total / due_this_week / overdue
 *   $file_summary         — keyed by file_type, from WSSP_Formidable
 *
 * @package WSSP
 */

defined( 'ABSPATH' ) || exit;

// Defensive defaults so a context variable miss doesn't fatal the report.
$session       = isset( $session ) && is_array( $session ) ? $session : array();
$event_label   = isset( $event_label ) ? (string) $event_label : 'Satellite Symposium';
$phases        = isset( $phases ) && is_array( $phases ) ? $phases : array();
$task_statuses = isset( $task_statuses ) && is_array( $task_statuses ) ? $task_statuses : array();
$task_content  = isset( $task_content ) && is_array( $task_content ) ? $task_content : array();
$stats         = isset( $stats ) && is_array( $stats ) ? $stats : array();
$file_summary  = isset( $file_summary ) && is_array( $file_summary ) ? $file_summary : array();

$site_name      = get_bloginfo( 'name' );
$session_code   = $session['session_code'] ?? '';
$session_name   = $session['short_name'] ?: $event_label;
$generated_at   = wp_date( 'F j, Y \a\t g:i a' );
$generated_by   = wp_get_current_user()->display_name ?: 'Sponsor';


// Derive a single, sponsor-readable status label per task.
//
// We deliberately FLATTEN the rich internal state (status string + is_done +
// is_submitted + is_overdue + completable + acknowledgment) into one of:
//     "Complete", "Submitted", "Overdue", "Acknowledged", "In Progress",
//     "Not Started", "Info Only"
// because sponsors care about a single, glanceable state — not forensics.
$resolve_task_status = static function ( $task, $task_statuses ) {
    if ( ( $task['type'] ?? '' ) === 'info' || empty( $task['completable'] ?? true ) ) {
        return array( 'label' => 'Info Only', 'class' => 'info' );
    }
    if ( ! empty( $task['is_done'] ) ) {
        return array( 'label' => 'Complete', 'class' => 'complete' );
    }
    if ( ! empty( $task['is_submitted'] ) ) {
        return array( 'label' => 'Submitted', 'class' => 'submitted' );
    }
    if ( ! empty( $task['is_overdue'] ) ) {
        return array( 'label' => 'Overdue', 'class' => 'overdue' );
    }
    $stored = $task_statuses[ $task['key'] ] ?? null;
    if ( $stored && ! empty( $stored['acknowledged_at'] ) ) {
        return array( 'label' => 'Acknowledged', 'class' => 'in-progress' );
    }
    if ( ( $task['status'] ?? 'not_started' ) !== 'not_started' ) {
        return array( 'label' => 'In Progress', 'class' => 'in-progress' );
    }
    return array( 'label' => 'Not Started', 'class' => 'not-started' );
};

$format_deadline = static function ( $deadline ) {
    if ( ! $deadline ) return '—';
    $ts = is_numeric( $deadline ) ? (int) $deadline : strtotime( $deadline );
    if ( ! $ts ) return esc_html( (string) $deadline );
    return wp_date( 'M j, Y', $ts );
};

?><!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
<meta charset="<?php bloginfo( 'charset' ); ?>" />
<meta name="viewport" content="width=device-width, initial-scale=1" />
<title>Session Status Report — <?php echo esc_html( $session_code ?: $session_name ); ?></title>
<?php wp_head(); ?>
</head>
<body class="wssp-report-body">

<div class="wssp-report">

    <!-- ─── Header ─────────────────────────────────────────── -->
    <header class="wssp-report__header">
        <div class="wssp-report__brand">
            <div class="wssp-report__site"><?php echo do_shortcode( '[conference-name-tm]' ); ?></div>
            <h1 class="wssp-report__title">Session Status Report</h1>
        </div>
        <div class="wssp-report__meta">
            <div><strong>Session:</strong> <?php echo esc_html( $session_name ); ?></div>
            <?php if ( $session_code ) : ?>
                <div><strong>Code:</strong> <?php echo esc_html( $session_code ); ?></div>
            <?php endif; ?>
            <div><strong>Type:</strong> <?php echo esc_html( $event_label ); ?></div>
            <div><strong>Generated:</strong> <?php echo esc_html( $generated_at ); ?></div>
            <div><strong>By:</strong> <?php echo esc_html( $generated_by ); ?></div>
        </div>
    </header>

    <!-- ─── Print button (hidden in print) ─────────────────── -->
    <div class="wssp-report__actions">
        <button type="button" class="wssp-report__print-btn" onclick="window.print()">
            Print / Save as PDF
        </button>
        <span class="wssp-report__hint">
            Use your browser's print dialog and choose "Save as PDF" as the destination.
        </span>
    </div>

    <!-- ─── Summary stats ──────────────────────────────────── -->
    <section class="wssp-report__summary">
        <h2 class="wssp-report__section-title">Progress Summary</h2>
        <div class="wssp-report__stat-grid">
            <div class="wssp-report__stat">
                <div class="wssp-report__stat-value"><?php echo (int) ( $stats['completed'] ?? 0 ); ?> <span class="wssp-report__stat-of">/ <?php echo (int) ( $stats['total'] ?? 0 ); ?></span></div>
                <div class="wssp-report__stat-label">Tasks Complete</div>
            </div>
            <div class="wssp-report__stat">
                <div class="wssp-report__stat-value"><?php echo (int) ( $stats['due_this_week'] ?? 0 ); ?></div>
                <div class="wssp-report__stat-label">Due This Week</div>
            </div>
            <div class="wssp-report__stat wssp-report__stat--warn">
                <div class="wssp-report__stat-value"><?php echo (int) ( $stats['overdue'] ?? 0 ); ?></div>
                <div class="wssp-report__stat-label">Overdue</div>
            </div>
        </div>
    </section>

    <!-- ─── Phases ─────────────────────────────────────────── -->
    <?php foreach ( $phases as $phase ) :
        $phase_label  = $phase['label'] ?? '(unnamed phase)';
        $phase_status = $phase['status'] ?? '';
        $phase_class  = $phase_status ? 'wssp-report__phase--' . $phase_status : '';

        // Skip hidden tasks (gated by add-on conditions etc.) so phases
        // only show what's actually applicable to this session.
        $visible_tasks = array();
        foreach ( $phase['tasks'] ?? array() as $task ) {
            if ( empty( $task['is_hidden'] ) ) {
                $visible_tasks[] = $task;
            }
        }

        // A phase with zero visible tasks (everything hidden) is suppressed
        // from the main report — it'd be a header with nothing under it.
        if ( empty( $visible_tasks ) ) continue;
    ?>
    <section class="wssp-report__phase <?php echo esc_attr( $phase_class ); ?>">
        <header class="wssp-report__phase-header">
            <h2 class="wssp-report__phase-title"><?php echo esc_html( $phase_label ); ?></h2>
            <?php if ( $phase_status ) : ?>
                <span class="wssp-report__phase-badge wssp-report__phase-badge--<?php echo esc_attr( $phase_status ); ?>">
                    <?php echo esc_html( ucfirst( $phase_status ) ); ?>
                </span>
            <?php endif; ?>
        </header>

        <table class="wssp-report__task-table">
            <thead>
                <tr>
                    <th class="col-task">Task</th>
                    <th class="col-deadline">Deadline</th>
                    <th class="col-status">Status</th>
                    <th class="col-extra">Notes</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ( $visible_tasks as $task ) :
                $status     = $resolve_task_status( $task, $task_statuses );
                $task_label = $task['label'] ?? $task['key'];
                $deadline   = $format_deadline( $task['deadline'] ?? null );

                // Build an "extra" cell describing per-task supplementary
                // info: file status for upload tasks, ack state for review
                // tasks. Kept short — this is a status report, not a manual.
                $extras = array();

                if ( ( $task['type'] ?? '' ) === 'upload' ) {
                    $file_type = $task['file_type'] ?? '';
                    $f         = $file_type ? ( $file_summary[ $file_type ] ?? null ) : null;
                    if ( $f ) {
                        $vlabel = isset( $f['version'] ) ? 'v' . (int) $f['version'] : '';
                        $extras[] = trim(
                            esc_html( $f['original_name'] ?? 'Uploaded file' ) .
                            ( $vlabel ? ' (' . esc_html( $vlabel ) . ')' : '' ) .
                            ( ! empty( $f['status'] ) ? ' — ' . esc_html( $f['status'] ) : '' )
                        );
                    } else {
                        $extras[] = 'No file uploaded';
                    }
                }

                $tc = $task_content[ $task['key'] ] ?? null;
                if ( $tc && ! empty( $tc->requires_acknowledgment ) ) {
                    $stored_ack = $task_statuses[ $task['key'] ]['acknowledged_at'] ?? '';
                    if ( $stored_ack ) {
                        $extras[] = 'Acknowledged ' . esc_html( wp_date( 'M j, Y', strtotime( $stored_ack ) ) );
                    } else {
                        $extras[] = 'Acknowledgment required';
                    }
                }
            ?>
                <tr class="wssp-report__task wssp-report__task--<?php echo esc_attr( $status['class'] ); ?>">
                    <td class="col-task"><?php echo esc_html( $task_label ); ?></td>
                    <td class="col-deadline"><?php echo esc_html( $deadline ); ?></td>
                    <td class="col-status">
                        <span class="wssp-report__status wssp-report__status--<?php echo esc_attr( $status['class'] ); ?>">
                            <?php echo esc_html( $status['label'] ); ?>
                        </span>
                    </td>
                    <td class="col-extra">
                        <?php echo $extras ? implode( '<br />', $extras ) : '&mdash;'; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </section>
    <?php endforeach; ?>

    <!-- ─── Footer ─────────────────────────────────────────── -->
    <footer class="wssp-report__footer">
        <div>
            <?php echo do_shortcode( '[conference-name]' ); ?> &middot; <?php echo $event_label ?> Status Report
        </div>
        <div>
            Generated <?php echo esc_html( $generated_at ); ?>
        </div>
    </footer>

</div>

<?php wp_footer(); ?>
</body>
</html>
