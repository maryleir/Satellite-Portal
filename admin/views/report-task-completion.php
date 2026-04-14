<?php
/**
 * Report: Task Completion Matrix
 *
 * Variables provided by WSSP_Reports::render_task_completion():
 *   $sessions     — all sessions (id, session_code, short_name, event_type, rollup_status)
 *   $phases       — phase definitions with nested tasks (only if sessions exist)
 *   $task_columns — flat list of tasks to show as columns
 *   $status_map   — session_id => task_key => status
 *
 * @package WSSP
 */

defined( 'ABSPATH' ) || exit;
?>

<div class="wrap wssp-admin wssp-report">
    <h1>Task Completion Matrix</h1>
    <p class="wssp-report__subtitle">At-a-glance view of task progress across all sessions.</p>

    <?php if ( empty( $sessions ) ) : ?>
        <div class="wssp-card">
            <p>No sessions have been created yet.</p>
        </div>
        <?php return; ?>
    <?php endif; ?>

    <?php if ( empty( $task_columns ) ) : ?>
        <div class="wssp-card">
            <p>No tasks are configured for this event type.</p>
        </div>
        <?php return; ?>
    <?php endif; ?>

    <!-- ─── Legend ─── -->
    <div class="wssp-card wssp-report__legend-card">
        <span class="wssp-report__legend-item">
            <span class="wssp-report__dot wssp-report__dot--not_started"></span> Not Started
        </span>
        <span class="wssp-report__legend-item">
            <span class="wssp-report__dot wssp-report__dot--acknowledged"></span> Acknowledged
        </span>
        <span class="wssp-report__legend-item">
            <span class="wssp-report__dot wssp-report__dot--in_progress"></span> In Progress
        </span>
        <span class="wssp-report__legend-item">
            <span class="wssp-report__dot wssp-report__dot--complete"></span> Complete
        </span>
        <span class="wssp-report__legend-item">
            <span class="wssp-report__dot wssp-report__dot--approved"></span> Approved
        </span>
        <span class="wssp-report__legend-item">
            <span class="wssp-report__dot wssp-report__dot--revision_requested"></span> Revision Requested
        </span>
    </div>

    <!-- ─── Matrix ─── -->
    <div class="wssp-card wssp-report__matrix-wrap">
        <div class="wssp-report__matrix-scroll">
            <table class="wssp-report__matrix">
                <thead>
                    <tr>
                        <th class="wssp-report__matrix-session-header" rowspan="2">Session</th>
                        <th class="wssp-report__matrix-rollup-header" rowspan="2">Rollup</th>
                        <?php
                        // Group task columns by phase for a grouped header row
                        $current_phase = '';
                        $phase_spans = array();
                        foreach ( $task_columns as $tc ) {
                            $pk = $tc['phase_key'];
                            if ( ! isset( $phase_spans[ $pk ] ) ) {
                                $phase_spans[ $pk ] = array(
                                    'label' => $tc['phase_label'],
                                    'count' => 0,
                                );
                            }
                            $phase_spans[ $pk ]['count']++;
                        }

                        foreach ( $phase_spans as $pk => $ps ) : ?>
                            <th colspan="<?php echo esc_attr( $ps['count'] ); ?>"
                                class="wssp-report__matrix-phase-header">
                                <?php echo esc_html( $ps['label'] ); ?>
                            </th>
                        <?php endforeach; ?>
                    </tr>
                    <tr>
                        <?php foreach ( $task_columns as $tc ) : ?>
                            <th class="wssp-report__matrix-task-header"
                                title="<?php echo esc_attr( $tc['label'] ); ?>">
                                <span><?php echo esc_html( $tc['label'] ); ?></span>
                            </th>
                        <?php endforeach; ?>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ( $sessions as $s ) :
                        $sid = (int) $s['id'];
                    ?>
                        <tr>
                            <td class="wssp-report__matrix-session-cell">
                                <a href="<?php echo esc_url( admin_url( 'admin.php?page=wssp-manage-session&session_id=' . $sid ) ); ?>">
                                    <strong><?php echo esc_html( $s['session_code'] ); ?></strong>
                                </a>
                                <span class="wssp-report__matrix-name"><?php echo esc_html( $s['short_name'] ); ?></span>
                            </td>
                            <td class="wssp-report__matrix-rollup-cell">
                                <?php echo WSSP_Reports::status_badge( $s['rollup_status'] ); ?>
                            </td>
                            <?php foreach ( $task_columns as $tc ) :
                                $status = $status_map[ $sid ][ $tc['key'] ] ?? 'not_started';
                            ?>
                                <td class="wssp-report__matrix-cell wssp-report__matrix-cell--<?php echo esc_attr( $status ); ?>"
                                    title="<?php echo esc_attr( $tc['label'] . ': ' . ucwords( str_replace( '_', ' ', $status ) ) ); ?>">
                                    <span class="wssp-report__dot wssp-report__dot--<?php echo esc_attr( $status ); ?>"></span>
                                </td>
                            <?php endforeach; ?>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- ─── Summary stats ─── -->
    <?php
    // Calculate completion stats per task
    $task_stats = array();
    $session_count = count( $sessions );
    foreach ( $task_columns as $tc ) {
        $completed = 0;
        $active = 0;
        foreach ( $sessions as $s ) {
            $st = $status_map[ (int) $s['id'] ][ $tc['key'] ] ?? 'not_started';
            if ( in_array( $st, array( 'approved', 'complete' ), true ) ) {
                $completed++;
            }
            if ( $st !== 'not_started' ) {
                $active++;
            }
        }
        $task_stats[] = array(
            'key'       => $tc['key'],
            'label'     => $tc['label'],
            'phase'     => $tc['phase_label'],
            'approved'  => $completed,
            'submitted' => $active,
            'total'     => $session_count,
        );
    }
    ?>

    <div class="wssp-card">
        <h2>Completion Summary</h2>
        <table class="wp-list-table widefat fixed striped">
            <thead>
                <tr>
                    <th>Task</th>
                    <th>Phase</th>
                    <th style="width: 120px;">In Progress+</th>
                    <th style="width: 120px;">Complete</th>
                    <th style="width: 200px;">Progress</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ( $task_stats as $ts ) :
                    $pct = $session_count > 0 ? round( ( $ts['submitted'] / $session_count ) * 100 ) : 0;
                ?>
                    <tr>
                        <td><?php echo esc_html( $ts['label'] ); ?></td>
                        <td><?php echo esc_html( $ts['phase'] ); ?></td>
                        <td><?php echo esc_html( $ts['submitted'] . ' / ' . $ts['total'] ); ?></td>
                        <td><?php echo esc_html( $ts['approved'] . ' / ' . $ts['total'] ); ?></td>
                        <td>
                            <div class="wssp-report__progress-bar">
                                <div class="wssp-report__progress-fill" style="width: <?php echo esc_attr( $pct ); ?>%"></div>
                            </div>
                            <span class="wssp-report__progress-label"><?php echo esc_html( $pct ); ?>%</span>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
