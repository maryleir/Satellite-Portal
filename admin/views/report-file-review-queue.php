<?php
/**
 * Report: File Review Queue
 *
 * One-stop view for logistics to see every file upload's current review
 * state, across all sessions, without clicking into each session one by
 * one. Oldest-pending-first default sort, with filters to slice by
 * status / phase / file type / session text / aging.
 *
 * Variables provided by WSSP_Reports::render_file_review_queue():
 *   $rows            — filtered+sorted queue rows, one per (session × file_type) latest version
 *   $counts          — array(total, pending, changes, approved) BEFORE status filter
 *   $filters         — current ?filter state for form fields + links
 *   $phases          — phase config (for phase dropdown)
 *   $file_types      — file_type config (for file_type dropdown)
 *   $recent_activity — last-14-days file audit entries
 *   $base_url        — /admin.php?page=wssp-report-files
 *   $export_url      — same + current filters + export=csv
 *
 * @package WSSP
 */

defined( 'ABSPATH' ) || exit;

/**
 * Tiny helper: build a filter URL that toggles a single key while
 * preserving other filters. Used by the status-pill clicks.
 */
if ( ! function_exists( 'wssp_fr_queue_url' ) ) {
    function wssp_fr_queue_url( $base_url, $filters, $key, $value ) {
        $next          = $filters;
        $next[ $key ]  = $value;
        // Collapse empties so the URL stays clean.
        $next = array_filter( $next, function ( $v ) { return $v !== '' && $v !== null; } );
        return add_query_arg( $next, $base_url );
    }
}
?>

<div class="wrap wssp-admin wssp-report wssp-frq">
    <h1>File Review Queue</h1>
    <p class="wssp-report__subtitle">
        Every uploaded file's latest version across all sessions, ordered
        with the oldest pending review first. Use this to find what
        needs your attention without clicking into each session.
    </p>

    <!-- ═══════════════════════════════════════════
         Status counter pills (double as filters)
         ═══════════════════════════════════════════ -->
    <div class="wssp-frq__pills">
        <a class="wssp-frq__pill <?php echo $filters['status'] === '' ? 'is-active' : ''; ?>"
           href="<?php echo esc_url( wssp_fr_queue_url( $base_url, $filters, 'status', '' ) ); ?>">
            <span class="wssp-frq__pill-number"><?php echo (int) $counts['total']; ?></span>
            <span class="wssp-frq__pill-label">Total</span>
        </a>
        <a class="wssp-frq__pill wssp-frq__pill--pending <?php echo $filters['status'] === 'pending' ? 'is-active' : ''; ?>"
           href="<?php echo esc_url( wssp_fr_queue_url( $base_url, $filters, 'status', 'pending' ) ); ?>">
            <span class="wssp-frq__pill-number"><?php echo (int) $counts['pending']; ?></span>
            <span class="wssp-frq__pill-label">Pending Review</span>
        </a>
        <a class="wssp-frq__pill wssp-frq__pill--changes <?php echo $filters['status'] === 'changes' ? 'is-active' : ''; ?>"
           href="<?php echo esc_url( wssp_fr_queue_url( $base_url, $filters, 'status', 'changes' ) ); ?>">
            <span class="wssp-frq__pill-number"><?php echo (int) $counts['changes']; ?></span>
            <span class="wssp-frq__pill-label">Changes Requested</span>
        </a>
        <a class="wssp-frq__pill wssp-frq__pill--approved <?php echo $filters['status'] === 'approved' ? 'is-active' : ''; ?>"
           href="<?php echo esc_url( wssp_fr_queue_url( $base_url, $filters, 'status', 'approved' ) ); ?>">
            <span class="wssp-frq__pill-number"><?php echo (int) $counts['approved']; ?></span>
            <span class="wssp-frq__pill-label">Approved</span>
        </a>
    </div>

    <!-- ═══════════════════════════════════════════
         Secondary filters (phase / file_type / search / aging)
         ═══════════════════════════════════════════ -->
    <form method="get" action="<?php echo esc_url( $base_url ); ?>" class="wssp-frq__filters">
        <input type="hidden" name="page" value="wssp-report-files" />
        <?php if ( $filters['status'] ) : ?>
            <input type="hidden" name="status" value="<?php echo esc_attr( $filters['status'] ); ?>" />
        <?php endif; ?>

        <label class="wssp-frq__filter">
            <span>Phase</span>
            <select name="phase">
                <option value="">All phases</option>
                <?php foreach ( $phases as $p ) : ?>
                    <option value="<?php echo esc_attr( $p['key'] ); ?>"
                            <?php selected( $filters['phase'], $p['key'] ); ?>>
                        <?php echo esc_html( $p['label'] ); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </label>

        <label class="wssp-frq__filter">
            <span>File type</span>
            <select name="file_type">
                <option value="">All types</option>
                <?php foreach ( $file_types as $key => $ft ) : ?>
                    <option value="<?php echo esc_attr( $key ); ?>"
                            <?php selected( $filters['file_type'], $key ); ?>>
                        <?php echo esc_html( $ft['label'] ?? $key ); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </label>

        <label class="wssp-frq__filter wssp-frq__filter--search">
            <span>Search</span>
            <input type="search" name="search"
                   value="<?php echo esc_attr( $filters['search'] ); ?>"
                   placeholder="Session code, name, or file type…" />
        </label>

        <label class="wssp-frq__filter wssp-frq__filter--checkbox">
            <input type="checkbox" name="aging" value="1" <?php checked( $filters['aging'], '1' ); ?> />
            <span>Only show aging (pending &gt; 3 days)</span>
        </label>

        <div class="wssp-frq__filter-actions">
            <button type="submit" class="button button-primary">Apply</button>
            <a href="<?php echo esc_url( $base_url ); ?>" class="button">Reset</a>
            <a href="<?php echo esc_url( $export_url ); ?>" class="button">Export CSV</a>
        </div>
    </form>

    <!-- ═══════════════════════════════════════════
         The queue itself
         ═══════════════════════════════════════════ -->
    <div class="wssp-card">
        <?php if ( empty( $rows ) ) : ?>
            <p class="wssp-frq__empty">
                No files match these filters.
                <?php if ( array_filter( $filters ) ) : ?>
                    <a href="<?php echo esc_url( $base_url ); ?>">Clear filters</a>
                <?php endif; ?>
            </p>
        <?php else : ?>
            <table class="wp-list-table widefat fixed striped wssp-frq__table">
                <thead>
                    <tr>
                        <th>Session</th>
                        <th>File Type</th>
                        <th>Phase</th>
                        <th class="wssp-frq__col-version">Ver.</th>
                        <th>Status</th>
                        <th>Uploaded</th>
                        <th class="wssp-frq__col-days">In Status</th>
                        <th>Uploader</th>
                        <th class="wssp-frq__col-actions">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ( $rows as $r ) : ?>
                        <?php
                        $session_url = WSSP_Helpers::session_portal_url( $r );
                        $row_class   = 'wssp-frq__row wssp-frq__row--' . esc_attr( $r['status_class'] );
                        if ( $r['is_aging'] ) {
                            $row_class .= ' wssp-frq__row--aging';
                        }
                        ?>
                        <tr class="<?php echo $row_class; ?>">
                            <td>
                                <a href="<?php echo esc_url( $session_url ); ?>" class="wssp-frq__session-link">
                                    <strong><?php echo esc_html( $r['session_code'] ); ?></strong>
                                </a>
                                <?php if ( ! empty( $r['short_name'] ) ) : ?>
                                    <div class="wssp-frq__session-name"><?php echo esc_html( $r['short_name'] ); ?></div>
                                <?php endif; ?>
                            </td>
                            <td><?php echo esc_html( $r['file_type_label'] ); ?></td>
                            <td><?php echo esc_html( $r['phase_label'] ); ?></td>
                            <td class="wssp-frq__col-version">v<?php echo (int) $r['version']; ?></td>
                            <td>
                                <span class="wssp-frq__status wssp-frq__status--<?php echo esc_attr( $r['status_class'] ); ?>">
                                    <?php echo esc_html( $r['status_short'] ); ?>
                                </span>
                                <?php if ( $r['is_aging'] ) : ?>
                                    <span class="wssp-frq__aging-flag" title="Pending review for more than 3 days">●</span>
                                <?php endif; ?>
                            </td>
                            <td><?php echo esc_html( mysql2date( 'M j, Y', $r['uploaded_at'] ) ); ?></td>
                            <td class="wssp-frq__col-days">
                                <?php echo (int) $r['days_in_status']; ?>d
                            </td>
                            <td><?php echo esc_html( $r['uploader_name'] ); ?></td>
                            <td class="wssp-frq__col-actions">
                                <a href="<?php echo esc_url( $session_url ); ?>"
                                   class="button button-small button-primary">Review</a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>

    <!-- ═══════════════════════════════════════════
         Recent file activity (last 14 days)
         ═══════════════════════════════════════════ -->
    <div class="wssp-card">
        <h2>Recent File Activity</h2>
        <p class="wssp-frq__feed-subtitle">
            Uploads and reviews across all sessions, last 14 days.
        </p>

        <?php if ( empty( $recent_activity ) ) : ?>
            <p class="wssp-frq__empty">No file activity in the last 14 days.</p>
        <?php else : ?>
            <?php
            // Group by YYYY-MM-DD so the reader sees day-by-day blocks.
            $by_day = array();
            foreach ( $recent_activity as $a ) {
                $d = mysql2date( 'Y-m-d', $a['created_at'] );
                if ( ! isset( $by_day[ $d ] ) ) $by_day[ $d ] = array();
                $by_day[ $d ][] = $a;
            }
            ?>
            <?php foreach ( $by_day as $day => $entries ) : ?>
                <h3 class="wssp-frq__feed-day"><?php echo esc_html( mysql2date( 'l, F j, Y', $day ) ); ?></h3>
                <ul class="wssp-frq__feed">
                    <?php foreach ( $entries as $a ) :
                        $meta  = json_decode( $a['meta'] ?? '{}', true );
                        if ( ! is_array( $meta ) ) $meta = array();
                        $ft    = $meta['file_type'] ?? $a['field_name'] ?? '';
                        $ver   = $meta['version']   ?? '';
                        $sess  = trim( ( $a['session_code'] ?? '' ) . ( ! empty( $a['short_name'] ) ? ' — ' . $a['short_name'] : '' ) );
                        if ( '' === $sess ) $sess = 'Session #' . (int) $a['session_id'];
                        $sess_url = WSSP_Helpers::session_portal_url( $a );

                        if ( $a['action'] === 'file_upload' ) {
                            $verb    = 'uploaded';
                            $pill_cl = 'upload';
                        } else {
                            $new = $a['new_value'] ?? '';
                            if ( $new === 'approved' ) {
                                $verb    = 'approved';
                                $pill_cl = 'approved';
                            } elseif ( $new === 'revision_requested' ) {
                                $verb    = 'requested changes on';
                                $pill_cl = 'changes';
                            } else {
                                $verb    = 'updated status on';
                                $pill_cl = 'pending';
                            }
                        }
                    ?>
                        <li class="wssp-frq__feed-item">
                            <span class="wssp-frq__feed-time"><?php echo esc_html( mysql2date( 'g:i a', $a['created_at'] ) ); ?></span>
                            <span class="wssp-frq__feed-pill wssp-frq__feed-pill--<?php echo esc_attr( $pill_cl ); ?>">
                                <?php echo esc_html( $verb ); ?>
                            </span>
                            <strong><?php echo esc_html( $ft ); ?></strong>
                            <?php if ( $ver !== '' ) : ?> (v<?php echo esc_html( $ver ); ?>)<?php endif; ?>
                            on
                            <a href="<?php echo esc_url( $sess_url ); ?>"><?php echo esc_html( $sess ); ?></a>
                            by <?php echo esc_html( $a['display_name'] ?: 'Unknown' ); ?>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</div>
