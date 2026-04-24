<?php
/**
 * Shortcode Report — Admin view.
 *
 * Shows all registered shortcodes across all sources, their current
 * resolved values, source (Smartsheet dates, session data, $world_data),
 * and where each shortcode is used in task content.
 *
 * Expected variables:
 *   $dates_entries   (array)  Date/deadline entries from Smartsheet sync.
 *   $dates_sync_info (array)  Sync metadata (synced_at, count).
 *   $session_map     (array)  Session shortcode map from WSSP_Session_Shortcodes.
 *   $world_shortcodes (array) Conference identity shortcodes from $world_data.
 *   $usage_map       (array)  Shortcode → usage locations from task content scan.
 *   $portal_slug     (string) The portal slug to scan (e.g. 'satellite').
 *
 * @package WSSP
 */

defined( 'ABSPATH' ) || exit;

// Gather sync info
$synced_at    = $dates_sync_info['synced_at'] ?? null;
$synced_count = $dates_sync_info['count'] ?? 0;
$has_data     = ! empty( $dates_entries );
?>

<div class="wrap wssp-shortcode-report">
    <h1>Shortcode Report</h1>

    <p class="description">
        All shortcodes available in the portal, their sources, current values, and where they appear in task content.
    </p>

    <!-- Sync status banner -->
    <div class="wssp-report-sync-bar">
        <div class="wssp-report-sync-status">
            <?php if ( $synced_at ) : ?>
                <span class="dashicons dashicons-yes-alt" style="color:#00a32a;"></span>
                Dates synced: <strong><?php echo esc_html( $synced_count ); ?></strong> shortcodes
                — last sync: <strong><?php echo esc_html( date( 'M j, Y g:i A', strtotime( $synced_at ) ) ); ?></strong>
            <?php else : ?>
                <span class="dashicons dashicons-warning" style="color:#dba617;"></span>
                Dates &amp; Deadlines not yet synced from Smartsheet.
            <?php endif; ?>
        </div>
        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline;">
            <?php wp_nonce_field( 'wssp_sync_dates' ); ?>
            <input type="hidden" name="action" value="wssp_sync_dates" />
            <button type="submit" class="button button-primary">
                <span class="dashicons dashicons-update" style="vertical-align:middle;margin-right:4px;"></span>
                Sync Dates from Smartsheet
            </button>
        </form>
    </div>

    <!-- ═══════════════════════════════════════════
         DEADLINE HEALTH CHECK
         ═══════════════════════════════════════════ -->
    <?php
    $dh_has_failures = $deadline_health['failed'] > 0;
    $dh_all_ok       = $deadline_health['failed'] === 0 && $deadline_health['ok'] > 0;
    ?>
    <div class="wssp-deadline-health" style="margin-bottom:24px;">
        <h2>Deadline Health Check</h2>
        <p class="description">
            Verifies that every task deadline resolves to a valid date. Deadlines stored as shortcode keys
            (e.g. <code>satellite-title-deadline</code>) must resolve via the Dates &amp; Deadlines Smartsheet sync.
        </p>

        <!-- Summary bar -->
        <div class="wssp-deadline-health__summary" style="
            display:flex; align-items:center; gap:20px;
            background:<?php echo $dh_has_failures ? '#fcf0f0' : ( $dh_all_ok ? '#f0faf0' : '#f0f0f1' ); ?>;
            border:1px solid <?php echo $dh_has_failures ? '#e8b4b8' : ( $dh_all_ok ? '#b4ddb4' : '#c3c4c7' ); ?>;
            border-radius:4px; padding:12px 16px; margin:12px 0 16px;
        ">
            <?php if ( $dh_has_failures ) : ?>
                <span class="dashicons dashicons-warning" style="color:#d63638; font-size:22px;"></span>
                <span style="font-size:14px;">
                    <strong><?php echo esc_html( $deadline_health['failed'] ); ?></strong> deadline<?php echo $deadline_health['failed'] !== 1 ? 's' : ''; ?> failed to resolve
                    — these tasks will never appear as overdue or upcoming.
                </span>
            <?php elseif ( $dh_all_ok ) : ?>
                <span class="dashicons dashicons-yes-alt" style="color:#00a32a; font-size:22px;"></span>
                <span style="font-size:14px;">
                    All <strong><?php echo esc_html( $deadline_health['ok'] ); ?></strong> deadlines resolved successfully.
                    <?php if ( $deadline_health['none'] > 0 ) : ?>
                        <span style="color:#646970;">(<?php echo esc_html( $deadline_health['none'] ); ?> tasks have no deadline set — this is expected for info and always-available tasks.)</span>
                    <?php endif; ?>
                </span>
            <?php else : ?>
                <span class="dashicons dashicons-info" style="color:#646970; font-size:22px;"></span>
                <span style="font-size:14px;">No tasks with deadlines found.</span>
            <?php endif; ?>
        </div>

        <?php if ( $dh_has_failures || $deadline_health['none'] > 0 ) : ?>
        <table class="wp-list-table widefat striped" style="max-width:900px;">
            <thead>
                <tr>
                    <th style="width:50px;">Status</th>
                    <th style="width:220px;">Task</th>
                    <th style="width:160px;">Phase</th>
                    <th style="width:200px;">Raw Deadline Value</th>
                    <th>Resolved Date</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ( $deadline_health['tasks'] as $dh_task ) :
                    // Show failed tasks always; show 'none' tasks only if there are also failures (for context)
                    if ( $dh_task['status'] === 'ok' && ! $dh_has_failures ) continue;
                    if ( $dh_task['status'] === 'ok' ) continue;

                    $row_style = '';
                    $status_icon = '';
                    if ( $dh_task['status'] === 'failed' ) {
                        $row_style   = 'background:#fcf0f0;';
                        $status_icon = '<span class="dashicons dashicons-dismiss" style="color:#d63638;" title="Failed to resolve"></span>';
                    } elseif ( $dh_task['status'] === 'none' ) {
                        $row_style   = '';
                        $status_icon = '<span class="dashicons dashicons-minus" style="color:#a7aaad;" title="No deadline set"></span>';
                    }
                ?>
                    <tr style="<?php echo esc_attr( $row_style ); ?>">
                        <td style="text-align:center;"><?php echo $status_icon; ?></td>
                        <td>
                            <strong><?php echo esc_html( $dh_task['task_label'] ); ?></strong>
                            <div style="font-size:11px; color:#646970; font-family:monospace;"><?php echo esc_html( $dh_task['task_key'] ); ?></div>
                        </td>
                        <td><?php echo esc_html( $dh_task['phase_label'] ); ?></td>
                        <td>
                            <?php if ( $dh_task['deadline_raw'] ) : ?>
                                <code><?php echo esc_html( $dh_task['deadline_raw'] ); ?></code>
                            <?php else : ?>
                                <em style="color:#a7aaad;">—</em>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ( $dh_task['status'] === 'failed' ) : ?>
                                <strong style="color:#d63638;">
                                    <?php if ( $dh_task['deadline'] ) : ?>
                                        "<?php echo esc_html( $dh_task['deadline'] ); ?>" (not a valid date)
                                    <?php else : ?>
                                        Shortcode not registered — check Smartsheet sync
                                    <?php endif; ?>
                                </strong>
                            <?php elseif ( $dh_task['status'] === 'none' ) : ?>
                                <em style="color:#a7aaad;">No deadline</em>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php elseif ( $dh_all_ok ) : ?>
        <!-- Collapsed detail: all OK, show a compact summary -->
        <details style="margin-top:8px;">
            <summary style="cursor:pointer; font-size:13px; color:#646970;">Show all <?php echo esc_html( $deadline_health['ok'] ); ?> resolved deadlines</summary>
            <table class="wp-list-table widefat striped" style="max-width:900px; margin-top:8px;">
                <thead>
                    <tr>
                        <th style="width:220px;">Task</th>
                        <th style="width:160px;">Phase</th>
                        <th style="width:200px;">Shortcode / Raw Value</th>
                        <th>Resolved Date</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ( $deadline_health['tasks'] as $dh_task ) :
                        if ( $dh_task['status'] !== 'ok' ) continue;
                        $ts = strtotime( $dh_task['deadline'] );
                    ?>
                        <tr>
                            <td>
                                <strong><?php echo esc_html( $dh_task['task_label'] ); ?></strong>
                                <div style="font-size:11px; color:#646970; font-family:monospace;"><?php echo esc_html( $dh_task['task_key'] ); ?></div>
                            </td>
                            <td><?php echo esc_html( $dh_task['phase_label'] ); ?></td>
                            <td><code><?php echo esc_html( $dh_task['deadline_raw'] ); ?></code></td>
                            <td>
                                <strong><?php echo $ts ? esc_html( date( 'M j, Y', $ts ) ) : esc_html( $dh_task['deadline'] ); ?></strong>
                                <span style="font-size:11px; color:#646970; margin-left:6px;"><?php echo esc_html( $dh_task['deadline'] ); ?></span>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </details>
        <?php endif; ?>
    </div>

    <hr style="margin:24px 0;" />

    <!-- Filters -->
    <div class="wssp-report-filters" style="margin:16px 0;">
        <label>
            Source:
            <select id="wssp-report-filter-source">
                <option value="">All sources</option>
                <option value="dates">Dates Smartsheet</option>
                <option value="session">Session Data</option>
                <option value="world">Conference Identity</option>
            </select>
        </label>
        <label style="margin-left:12px;">
            Tag:
            <select id="wssp-report-filter-tag">
                <option value="">All tags</option>
                <?php
                $all_tags = array();
                foreach ( $dates_entries as $entry ) {
                    foreach ( $entry['tags'] ?? array() as $tag ) {
                        $all_tags[ $tag ] = true;
                    }
                }
                ksort( $all_tags );
                foreach ( $all_tags as $tag => $_v ) {
                    echo '<option value="' . esc_attr( $tag ) . '">' . esc_html( $tag ) . '</option>';
                }
                ?>
            </select>
        </label>
        <label style="margin-left:12px;">
            Search:
            <input type="text" id="wssp-report-filter-search" placeholder="shortcode key or label…" style="width:240px;" />
        </label>
    </div>

    <!-- Report table -->
    <table class="wp-list-table widefat striped wssp-report-table">
        <thead>
            <tr>
                <th style="width:220px;">Shortcode</th>
                <th style="width:100px;">Source</th>
                <th style="width:140px;">Current Value</th>
                <th style="width:120px;">Category / Tags</th>
                <th>Used In</th>
            </tr>
        </thead>
        <tbody>

            <?php
            /* ─── 1. DATE/DEADLINE SHORTCODES (from Smartsheet) ─── */
            foreach ( $dates_entries as $key => $entry ) :
                $date = $entry['date'] ?? '';
                $formatted = '';
                if ( $date ) {
                    $ts = strtotime( $date );
                    if ( $ts ) {
                        // day-of-week shortcodes show the day name
                        if ( preg_match( '/-(dow|day)$/', $key ) || str_starts_with( $key, 'day-' ) ) {
                            $formatted = date( 'l', $ts ) . ' (' . date( 'M j', $ts ) . ')';
                        } else {
                            $formatted = date( 'M j, Y', $ts );
                        }
                    }
                }
                $tags_html = implode( ', ', array_map( 'esc_html', $entry['tags'] ?? array() ) );
                $usages    = $usage_map[ $key ] ?? array();
            ?>
                <tr data-source="dates" data-tags="<?php echo esc_attr( implode( ',', $entry['tags'] ?? array() ) ); ?>">
                    <td>
                        <code>[<?php echo esc_html( $key ); ?>]</code>
                        <div class="wssp-report-label"><?php echo esc_html( $entry['label'] ?? '' ); ?></div>
                    </td>
                    <td><span class="wssp-report-badge wssp-report-badge--dates">Dates Sheet</span></td>
                    <td>
                        <?php if ( $formatted ) : ?>
                            <strong><?php echo esc_html( $formatted ); ?></strong>
                            <div class="wssp-report-raw"><?php echo esc_html( $date ); ?></div>
                        <?php else : ?>
                            <em>—</em>
                        <?php endif; ?>
                    </td>
                    <td>
                        <div><?php echo esc_html( $entry['category'] ?? '' ); ?></div>
                        <?php if ( $tags_html ) : ?>
                            <div class="wssp-report-tags"><?php echo $tags_html; ?></div>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if ( empty( $usages ) ) : ?>
                            <em class="wssp-report-unused">Not used in task content</em>
                        <?php else : ?>
                            <ul class="wssp-report-usage-list">
                                <?php foreach ( $usages as $u ) : ?>
                                    <li>
                                        <span class="wssp-report-usage-context"><?php echo esc_html( $u['context'] ); ?></span>
                                        <span class="wssp-report-usage-location"><?php echo esc_html( $u['location'] ); ?></span>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>

            <?php
            /* ─── 2. SESSION SHORTCODES (per-session from session data) ─── */
            foreach ( $session_map as $key => $mapping ) :
                $usages = $usage_map[ $key ] ?? array();
            ?>
                <tr data-source="session" data-tags="">
                    <td>
                        <code>[<?php echo esc_html( $key ); ?>]</code>
                        <div class="wssp-report-label">
                            <?php
                            if ( is_array( $mapping ) ) {
                                echo 'Keys: ' . esc_html( implode( ' → ', $mapping ) );
                            } else {
                                echo 'Key: ' . esc_html( $mapping );
                            }
                            ?>
                        </div>
                    </td>
                    <td><span class="wssp-report-badge wssp-report-badge--session">Session Data</span></td>
                    <td><em>(per session)</em></td>
                    <td>—</td>
                    <td>
                        <?php if ( empty( $usages ) ) : ?>
                            <em class="wssp-report-unused">Not used in task content</em>
                        <?php else : ?>
                            <ul class="wssp-report-usage-list">
                                <?php foreach ( $usages as $u ) : ?>
                                    <li>
                                        <span class="wssp-report-usage-context"><?php echo esc_html( $u['context'] ); ?></span>
                                        <span class="wssp-report-usage-location"><?php echo esc_html( $u['location'] ); ?></span>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>

            <?php
            /* ─── 3. CONFERENCE IDENTITY SHORTCODES ─── */
            foreach ( $world_shortcodes as $key => $value ) :
                $usages = $usage_map[ $key ] ?? array();
            ?>
                <tr data-source="world" data-tags="">
                    <td>
                        <code>[<?php echo esc_html( $key ); ?>]</code>
                    </td>
                    <td><span class="wssp-report-badge wssp-report-badge--world">Conference Identity</span></td>
                    <td>
                        <?php if ( $value ) : ?>
                            <strong><?php echo wp_kses_post( wp_trim_words( $value, 8, '…' ) ); ?></strong>
                        <?php else : ?>
                            <em>—</em>
                        <?php endif; ?>
                    </td>
                    <td>Conference</td>
                    <td>
                        <?php if ( empty( $usages ) ) : ?>
                            <em class="wssp-report-unused">Not used in task content</em>
                        <?php else : ?>
                            <ul class="wssp-report-usage-list">
                                <?php foreach ( $usages as $u ) : ?>
                                    <li>
                                        <span class="wssp-report-usage-context"><?php echo esc_html( $u['context'] ); ?></span>
                                        <span class="wssp-report-usage-location"><?php echo esc_html( $u['location'] ); ?></span>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>

            <?php
            /* ─── 4. ORPHANED — used in content but not registered anywhere ─── */
            $all_registered = array_merge(
                array_keys( $dates_entries ),
                array_keys( $session_map ),
                array_keys( $world_shortcodes )
            );
            $orphaned = array_diff_key( $usage_map, array_flip( $all_registered ) );
            foreach ( $orphaned as $key => $usages ) :
            ?>
                <tr data-source="orphan" data-tags="" style="background:#fff3cd;">
                    <td>
                        <code>[<?php echo esc_html( $key ); ?>]</code>
                        <div class="wssp-report-label" style="color:#856404;">⚠ Not registered</div>
                    </td>
                    <td><span class="wssp-report-badge wssp-report-badge--orphan">Unknown</span></td>
                    <td><em>Will not resolve</em></td>
                    <td>—</td>
                    <td>
                        <ul class="wssp-report-usage-list">
                            <?php foreach ( $usages as $u ) : ?>
                                <li>
                                    <span class="wssp-report-usage-context"><?php echo esc_html( $u['context'] ); ?></span>
                                    <span class="wssp-report-usage-location"><?php echo esc_html( $u['location'] ); ?></span>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    </td>
                </tr>
            <?php endforeach; ?>

        </tbody>
    </table>
</div>

<style>
    .wssp-report-sync-bar {
        display: flex; align-items: center; justify-content: space-between;
        background: #f0f0f1; border: 1px solid #c3c4c7; border-radius: 4px;
        padding: 12px 16px; margin: 16px 0;
    }
    .wssp-report-sync-status { font-size: 14px; }
    .wssp-report-sync-status .dashicons { font-size: 18px; vertical-align: middle; margin-right: 4px; }
    .wssp-report-table code { background: #f0f0f1; padding: 2px 6px; border-radius: 3px; font-size: 12px; }
    .wssp-report-label { font-size: 11px; color: #646970; margin-top: 2px; }
    .wssp-report-raw { font-size: 11px; color: #8c8f94; font-family: monospace; }
    .wssp-report-tags { font-size: 11px; color: #646970; margin-top: 2px; }
    .wssp-report-badge {
        display: inline-block; font-size: 11px; padding: 2px 8px; border-radius: 10px;
        font-weight: 500; white-space: nowrap;
    }
    .wssp-report-badge--dates { background: #e7f5ff; color: #1864ab; }
    .wssp-report-badge--session { background: #ebfbee; color: #2b8a3e; }
    .wssp-report-badge--world { background: #fff4e6; color: #e67700; }
    .wssp-report-badge--orphan { background: #fff3cd; color: #856404; }
    .wssp-report-unused { color: #8c8f94; font-size: 12px; }
    .wssp-report-usage-list { margin: 0; padding: 0; list-style: none; }
    .wssp-report-usage-list li { font-size: 12px; margin-bottom: 3px; line-height: 1.3; }
    .wssp-report-usage-context {
        display: inline-block; background: #f0f0f1; padding: 1px 5px; border-radius: 2px;
        font-size: 10px; color: #646970; margin-right: 4px; text-transform: uppercase;
    }
    .wssp-report-usage-location { color: #1d2327; }
</style>

<script>
(function() {
    var sourceFilter = document.getElementById('wssp-report-filter-source');
    var tagFilter    = document.getElementById('wssp-report-filter-tag');
    var searchFilter = document.getElementById('wssp-report-filter-search');

    function applyFilters() {
        var source = sourceFilter.value;
        var tag    = tagFilter.value;
        var search = searchFilter.value.toLowerCase();

        document.querySelectorAll('.wssp-report-table tbody tr').forEach(function(row) {
            var show = true;

            if (source && row.dataset.source !== source) show = false;
            if (tag) {
                var rowTags = (row.dataset.tags || '').split(',');
                if (rowTags.indexOf(tag) === -1) show = false;
            }
            if (search) {
                var text = row.textContent.toLowerCase();
                if (text.indexOf(search) === -1) show = false;
            }

            row.style.display = show ? '' : 'none';
        });
    }

    sourceFilter.addEventListener('change', applyFilters);
    tagFilter.addEventListener('change', applyFilters);
    searchFilter.addEventListener('input', applyFilters);
})();
</script>