<?php
/**
 * Report: Session Audit Log
 *
 * Variables provided by WSSP_Reports::render_audit_log():
 *   $sessions       — all sessions (for dropdown)
 *   $session_id     — currently selected session ID (0 = none)
 *   $action_filter  — current action filter value
 *   $date_from      — date range start
 *   $date_to        — date range end
 *   $entries        — audit log rows for the selected session
 *   $action_slugs   — distinct action values for filter dropdown
 *   $action_labels  — slug => human label map
 *   $paged          — current page
 *   $total          — total matching entries
 *   $total_pages    — total pages
 *   $per_page       — entries per page
 *
 * @package WSSP
 */

defined( 'ABSPATH' ) || exit;

$base_url = admin_url( 'admin.php?page=wssp-report-audit' );
?>

<div class="wrap wssp-admin wssp-report">
    <h1>Session Audit Log</h1>
    <p class="wssp-report__subtitle">Review who changed what, and when, for each session.</p>

    <!-- ─── Filters ─── -->
    <div class="wssp-card">
        <form method="get" action="<?php echo esc_url( $base_url ); ?>" class="wssp-report__filters">
            <input type="hidden" name="page" value="wssp-report-audit">

            <div class="wssp-report__filter-row">
                <label for="wssp-session-select">Session</label>
                <select name="session_id" id="wssp-session-select">
                    <option value="">— Select a session —</option>
                    <?php foreach ( $sessions as $s ) : ?>
                        <option value="<?php echo esc_attr( $s['id'] ); ?>"
                                <?php selected( $session_id, $s['id'] ); ?>>
                            <?php echo esc_html( $s['session_code'] . ' — ' . $s['short_name'] ); ?>
                        </option>
                    <?php endforeach; ?>
                </select>

                <?php if ( $session_id && ! empty( $action_slugs ) ) : ?>
                    <label for="wssp-action-filter">Action</label>
                    <select name="action_filter" id="wssp-action-filter">
                        <option value="">All actions</option>
                        <?php foreach ( $action_slugs as $slug ) : ?>
                            <option value="<?php echo esc_attr( $slug ); ?>"
                                    <?php selected( $action_filter, $slug ); ?>>
                                <?php echo esc_html( $action_labels[ $slug ] ?? ucwords( str_replace( '_', ' ', $slug ) ) ); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                <?php endif; ?>

                <label for="wssp-date-from">From</label>
                <input type="date" name="date_from" id="wssp-date-from"
                       value="<?php echo esc_attr( $date_from ); ?>">

                <label for="wssp-date-to">To</label>
                <input type="date" name="date_to" id="wssp-date-to"
                       value="<?php echo esc_attr( $date_to ); ?>">

                <button type="submit" class="button button-primary">Filter</button>

                <?php if ( $session_id ) : ?>
                    <a href="<?php echo esc_url( $base_url ); ?>" class="button">Reset</a>
                <?php endif; ?>
            </div>
        </form>
    </div>

    <!-- ─── Results ─── -->
    <?php if ( ! $session_id ) : ?>
        <div class="wssp-card">
            <p>Select a session above to view its audit log.</p>
        </div>

    <?php elseif ( empty( $entries ) ) : ?>
        <div class="wssp-card">
            <p>No audit entries found for the selected filters.</p>
        </div>

    <?php else : ?>
        <div class="wssp-card">
            <p class="wssp-report__count">
                Showing <?php echo count( $entries ); ?> of <?php echo esc_html( $total ); ?> entries
                <?php if ( $total_pages > 1 ) : ?>
                    (page <?php echo esc_html( $paged ); ?> of <?php echo esc_html( $total_pages ); ?>)
                <?php endif; ?>
            </p>

            <table class="wp-list-table widefat fixed striped wssp-report__table">
                <thead>
                    <tr>
                        <th class="wssp-col--timestamp">Timestamp</th>
                        <th class="wssp-col--user">User</th>
                        <th class="wssp-col--action">Action</th>
                        <th class="wssp-col--entity">Entity</th>
                        <th class="wssp-col--field">Field</th>
                        <th class="wssp-col--old">Old Value</th>
                        <th class="wssp-col--new">New Value</th>
                        <th class="wssp-col--source">Source</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ( $entries as $entry ) : ?>
                        <?php
                        $action_label = $action_labels[ $entry['action'] ] ?? ucwords( str_replace( '_', ' ', $entry['action'] ) );
                        $meta = $entry['meta'] ? json_decode( $entry['meta'], true ) : null;
                        ?>
                        <tr>
                            <td class="wssp-col--timestamp">
                                <?php
                                $ts = strtotime( $entry['created_at'] );
                                echo esc_html( date( 'M j, Y', $ts ) );
                                echo '<br><span class="wssp-report__time">' . esc_html( date( 'g:i:s a', $ts ) ) . '</span>';
                                ?>
                            </td>
                            <td>
                                <?php
                                if ( ! empty( $entry['display_name'] ) ) {
                                    echo esc_html( $entry['display_name'] );
                                } elseif ( (int) $entry['user_id'] === 0 ) {
                                    echo '<em>System</em>';
                                } else {
                                    echo '<em>User #' . esc_html( $entry['user_id'] ) . '</em>';
                                }
                                ?>
                            </td>
                            <td>
                                <span class="wssp-report__action-badge wssp-report__action-badge--<?php echo esc_attr( $entry['action'] ); ?>">
                                    <?php echo esc_html( $action_label ); ?>
                                </span>
                            </td>
                            <td>
                                <?php
                                echo esc_html( $entry['entity_type'] );
                                if ( $entry['entity_id'] ) {
                                    echo ' <code>' . esc_html( $entry['entity_id'] ) . '</code>';
                                }
                                ?>
                            </td>
                            <td>
                                <?php
                                if ( $entry['field_name'] ) {
                                    echo '<code>' . esc_html( $entry['field_name'] ) . '</code>';
                                } else {
                                    echo '—';
                                }
                                ?>
                            </td>
                            <td class="wssp-col--value">
                                <?php echo esc_html( WSSP_Reports::format_value( $entry['old_value'] ) ); ?>
                            </td>
                            <td class="wssp-col--value">
                                <?php echo esc_html( WSSP_Reports::format_value( $entry['new_value'] ) ); ?>
                            </td>
                            <td>
                                <?php echo esc_html( $entry['source'] ?? 'portal' ); ?>
                                <?php if ( $meta && ! empty( $meta['trigger'] ) ) : ?>
                                    <br><span class="wssp-report__trigger"><?php echo esc_html( $meta['trigger'] ); ?></span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>

            <!-- ─── Pagination ─── -->
            <?php if ( $total_pages > 1 ) : ?>
                <div class="wssp-report__pagination">
                    <?php
                    $pagination_args = array(
                        'session_id'    => $session_id,
                        'action_filter' => $action_filter,
                        'date_from'     => $date_from,
                        'date_to'       => $date_to,
                    );
                    ?>
                    <?php if ( $paged > 1 ) : ?>
                        <a href="<?php echo esc_url( add_query_arg( array_merge( $pagination_args, array( 'paged' => $paged - 1 ) ), $base_url ) ); ?>"
                           class="button">← Previous</a>
                    <?php endif; ?>

                    <span class="wssp-report__page-info">
                        Page <?php echo esc_html( $paged ); ?> of <?php echo esc_html( $total_pages ); ?>
                    </span>

                    <?php if ( $paged < $total_pages ) : ?>
                        <a href="<?php echo esc_url( add_query_arg( array_merge( $pagination_args, array( 'paged' => $paged + 1 ) ), $base_url ) ); ?>"
                           class="button">Next →</a>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>
    <?php endif; ?>
</div>
