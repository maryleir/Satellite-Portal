<?php
/**
 * Report: Logistics Dashboard
 *
 * Cross-session overview for the logistics planner.
 *
 * Variables provided by WSSP_Reports::render_logistics_dashboard():
 *   $sessions            — all sessions
 *   $phases              — phase definitions
 *   $attention_sessions  — sessions with action_needed rollup + issue details
 *   $activity_by_date    — date => audit entries (last N days)
 *   $phase_progress      — per-phase completion stats with task breakdown
 *   $days_back           — how many days of activity to show
 *   $action_labels       — action slug → human label map
 *
 * @package WSSP
 */

defined( 'ABSPATH' ) || exit;

$base_url = admin_url( 'admin.php?page=wssp-report-logistics' );
$matrix_url = admin_url( 'admin.php?page=wssp-report-tasks' );
$session_count = count( $sessions ?? array() );
$total_complete = 0;
foreach ( $sessions ?? array() as $s ) {
    if ( $s['rollup_status'] === 'complete' ) $total_complete++;
}
?>

<div class="wrap wssp-admin wssp-report">
    <h1>Logistics Dashboard</h1>
    <p class="wssp-report__subtitle">Cross-session overview — who needs attention, what changed recently, and where each phase stands.</p>

    <?php if ( empty( $sessions ) ) : ?>
        <div class="wssp-card">
            <p>No sessions have been created yet.</p>
        </div>
        <?php return; ?>
    <?php endif; ?>

    <!-- ═══════════════════════════════════════════
         Quick Stats
         ═══════════════════════════════════════════ -->
    <div class="wssp-ld__stats-row">
        <div class="wssp-ld__stat-card">
            <div class="wssp-ld__stat-number"><?php echo $session_count; ?></div>
            <div class="wssp-ld__stat-label">Total Sessions</div>
        </div>
        <div class="wssp-ld__stat-card wssp-ld__stat-card--attention">
            <div class="wssp-ld__stat-number"><?php echo count( $attention_sessions ); ?></div>
            <div class="wssp-ld__stat-label">Need Attention</div>
        </div>
        <div class="wssp-ld__stat-card wssp-ld__stat-card--complete">
            <div class="wssp-ld__stat-number"><?php echo $total_complete; ?></div>
            <div class="wssp-ld__stat-label">Fully Complete</div>
        </div>
        <div class="wssp-ld__stat-card">
            <div class="wssp-ld__stat-number"><?php echo count( $recent_activity ?? array() ); ?></div>
            <div class="wssp-ld__stat-label">Actions (<?php echo $days_back; ?>d)</div>
        </div>
    </div>

    <!-- ═══════════════════════════════════════════
         Section A: Attention Required
         ═══════════════════════════════════════════ -->
    <div class="wssp-card">
        <h2>
            <?php if ( ! empty( $attention_sessions ) ) : ?>
                <span class="wssp-ld__attention-icon">⚠</span>
            <?php endif; ?>
            Attention Required
            <?php if ( ! empty( $attention_sessions ) ) : ?>
                <span class="wssp-ld__count-badge"><?php echo count( $attention_sessions ); ?></span>
            <?php endif; ?>
        </h2>

        <?php if ( empty( $attention_sessions ) ) : ?>
            <p class="wssp-ld__all-clear">All sessions are on track — no overdue tasks or pending revisions.</p>
        <?php else : ?>
            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th style="width: 160px;">Session</th>
                        <th>Issues</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ( $attention_sessions as $as ) :
                        $s = $as['session'];
                    ?>
                        <tr>
                            <td>
                                <a href="<?php echo esc_url( admin_url( 'admin.php?page=wssp-manage-session&session_id=' . $s['id'] ) ); ?>">
                                    <strong><?php echo esc_html( $s['session_code'] ); ?></strong>
                                </a>
                                <span class="wssp-report__matrix-name"><?php echo esc_html( $s['short_name'] ); ?></span>
                            </td>
                            <td>
                                <?php foreach ( $as['issues'] as $issue ) : ?>
                                    <span class="wssp-ld__issue wssp-ld__issue--<?php echo esc_attr( $issue['type'] ); ?>">
                                        <?php echo esc_html( $issue['task'] ); ?>
                                        <span class="wssp-ld__issue-badge"><?php echo esc_html( $issue['label'] ); ?></span>
                                    </span>
                                <?php endforeach; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>

    <!-- ═══════════════════════════════════════════
         Section B: Phase Progress
         ═══════════════════════════════════════════ -->
    <div class="wssp-card">
        <h2>Phase Progress</h2>
        <p class="wssp-report__subtitle" style="margin-bottom: 16px;">Click a phase to see task-by-task detail in the
            <a href="<?php echo esc_url( $matrix_url ); ?>">Task Completion Matrix</a>.</p>

        <div class="wssp-ld__phases-grid">
            <?php foreach ( $phase_progress as $pp ) : ?>
                <a href="<?php echo esc_url( add_query_arg( 'phase', $pp['key'], $matrix_url ) ); ?>"
                   class="wssp-ld__phase-card">
                    <div class="wssp-ld__phase-header">
                        <span class="wssp-ld__phase-name"><?php echo esc_html( $pp['label'] ); ?></span>
                        <span class="wssp-ld__phase-pct"><?php echo $pp['pct']; ?>%</span>
                    </div>
                    <div class="wssp-report__progress-bar wssp-ld__phase-bar">
                        <div class="wssp-report__progress-fill" style="width: <?php echo esc_attr( $pp['pct'] ); ?>%"></div>
                    </div>
                    <div class="wssp-ld__phase-meta">
                        <?php
                        $revision_total = array_sum( array_column( $pp['tasks'], 'revision' ) );
                        ?>
                        <span><?php echo $pp['done']; ?>/<?php echo $pp['total']; ?> complete</span>
                        <?php if ( $revision_total > 0 ) : ?>
                            <span class="wssp-ld__phase-revision"><?php echo $revision_total; ?> revision<?php echo $revision_total > 1 ? 's' : ''; ?></span>
                        <?php endif; ?>
                    </div>
                    <div class="wssp-ld__phase-tasks">
                        <?php foreach ( $pp['tasks'] as $pt ) :
                            $task_pct = $pt['total'] > 0 ? round( ( $pt['completed'] / $pt['total'] ) * 100 ) : 0;
                        ?>
                            <div class="wssp-ld__phase-task-row">
                                <span class="wssp-ld__phase-task-name"><?php echo esc_html( $pt['label'] ); ?></span>
                                <span class="wssp-ld__phase-task-count">
                                    <?php echo $pt['completed']; ?>/<?php echo $pt['total']; ?>
                                    <?php if ( $pt['revision'] > 0 ) : ?>
                                        <span class="wssp-ld__revision-dot" title="<?php echo $pt['revision']; ?> revision requested">●</span>
                                    <?php endif; ?>
                                </span>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </a>
            <?php endforeach; ?>
        </div>
    </div>

    <!-- ═══════════════════════════════════════════
         Section C: Recent Activity
         ═══════════════════════════════════════════ -->
    <div class="wssp-card">
        <h2>
            Recent Activity
            <span class="wssp-ld__activity-controls">
                <?php foreach ( array( 3, 7, 14, 30 ) as $d ) : ?>
                    <a href="<?php echo esc_url( add_query_arg( 'days', $d, $base_url ) ); ?>"
                       class="button button-small <?php echo $days_back === $d ? 'button-primary' : ''; ?>">
                        <?php echo $d; ?>d
                    </a>
                <?php endforeach; ?>
            </span>
        </h2>

        <?php if ( empty( $activity_by_date ) ) : ?>
            <p>No activity in the last <?php echo $days_back; ?> days.</p>
        <?php else : ?>
            <div class="wssp-ld__timeline">
                <?php foreach ( $activity_by_date as $date_key => $entries ) :
                    $day_ts   = strtotime( $date_key );
                    $is_today = ( $date_key === current_time( 'Y-m-d' ) );
                ?>
                    <div class="wssp-ld__timeline-day">
                        <div class="wssp-ld__timeline-date">
                            <?php echo $is_today ? 'Today' : date( 'l, M j', $day_ts ); ?>
                            <span class="wssp-ld__timeline-count"><?php echo count( $entries ); ?> action<?php echo count( $entries ) > 1 ? 's' : ''; ?></span>
                        </div>
                        <div class="wssp-ld__timeline-entries">
                            <?php foreach ( $entries as $entry ) :
                                $time = date( 'g:ia', strtotime( $entry['created_at'] ) );
                            ?>
                                <div class="wssp-ld__timeline-entry">
                                    <span class="wssp-ld__timeline-time"><?php echo esc_html( $time ); ?></span>
                                    <span class="wssp-ld__timeline-session">
                                        <a href="<?php echo esc_url( admin_url( 'admin.php?page=wssp-manage-session&session_id=' . $entry['session_id'] ) ); ?>">
                                            <?php echo esc_html( $entry['session_code'] ?? '' ); ?>
                                        </a>
                                    </span>
                                    <span class="wssp-ld__timeline-user"><?php echo esc_html( $entry['display_name'] ?? 'System' ); ?></span>
                                    <span class="wssp-ld__timeline-action">
                                        <?php echo WSSP_Reports::describe_action( $entry, $action_labels ); ?>
                                    </span>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</div>
