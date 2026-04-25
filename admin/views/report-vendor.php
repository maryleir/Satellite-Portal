<?php
/**
 * Report: Vendor Report (AV / Print / Hotel)
 *
 * Per-vendor view of session data, ordered by session number, scoped
 * to the fields each vendor team needs (per portal-config vendor_views).
 *
 * Variables provided by WSSP_Reports::render_vendor_report():
 *   $type           — 'av' | 'print' | 'hotel'
 *   $vendor_locked  — true when current user is a vendor (no type-switch UI)
 *   $vendor_config  — the vendor_views[type] config sub-array
 *   $rows           — ordered session data rows
 *   $filters        — current ?filter state
 *   $base_url       — /admin.php?page=wssp-report-vendors
 *   $export_url     — same + current filters + export=csv
 *
 * @package WSSP
 */

defined( 'ABSPATH' ) || exit;

$reports = $this; // For calling vendor_field_label() / vendor_field_display() in this scope.

$field_keys = (array) ( $vendor_config['fields']     ?? array() );
$file_types = (array) ( $vendor_config['file_types'] ?? array() );

$type_label = WSSP_Vendor_Access::vendor_label( $type );

?>

<div class="wrap wssp-admin wssp-report wssp-vrp">

    <h1>
        Vendor Report
        <span class="wssp-vrp__type-tag wssp-vrp__type-tag--<?php echo esc_attr( $type ); ?>">
            <?php echo esc_html( $type_label ); ?>
        </span>
    </h1>

    <?php if ( $vendor_locked ) : ?>
        <p class="wssp-report__subtitle">
            Sessions and details for the <strong><?php echo esc_html( $type_label ); ?></strong> team.
            All sessions are listed in session-number order.
        </p>
    <?php else : ?>
        <p class="wssp-report__subtitle">
            Logistics view — switch between vendor types using the selector below.
            Vendor users with their own login see only their assigned type.
        </p>
    <?php endif; ?>

    <!-- ═══════════════════════════════════════════
         Type selector + filters
         ═══════════════════════════════════════════ -->
    <form method="get" action="<?php echo esc_url( $base_url ); ?>" class="wssp-vrp__filters">
        <input type="hidden" name="page" value="wssp-report-vendors" />

        <?php if ( ! $vendor_locked ) : ?>
            <label class="wssp-vrp__filter">
                <span>Vendor type</span>
                <select name="type" onchange="this.form.submit()">
                    <?php foreach ( WSSP_Vendor_Access::VENDOR_TYPES as $slug ) : ?>
                        <option value="<?php echo esc_attr( $slug ); ?>" <?php selected( $type, $slug ); ?>>
                            <?php echo esc_html( WSSP_Vendor_Access::vendor_label( $slug ) ); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>
        <?php else : ?>
            <input type="hidden" name="type" value="<?php echo esc_attr( $type ); ?>" />
        <?php endif; ?>

        <label class="wssp-vrp__filter">
            <span>Session status</span>
            <select name="session_status">
                <option value="">All</option>
                <option value="on_track"             <?php selected( $filters['session_status'], 'on_track' ); ?>>On Track</option>
                <option value="attention_needed"     <?php selected( $filters['session_status'], 'attention_needed' ); ?>>Attention Needed</option>
                <option value="submitted_for_review" <?php selected( $filters['session_status'], 'submitted_for_review' ); ?>>Submitted for Review</option>
                <option value="completed"            <?php selected( $filters['session_status'], 'completed' ); ?>>Completed</option>
            </select>
        </label>

        <label class="wssp-vrp__filter wssp-vrp__filter--search">
            <span>Search</span>
            <input type="search" name="search"
                   value="<?php echo esc_attr( $filters['search'] ); ?>"
                   placeholder="Code, sponsor, or session name…" />
        </label>

        <div class="wssp-vrp__filter-actions">
            <button type="submit" class="button button-primary">Apply</button>
            <a href="<?php echo esc_url( add_query_arg( 'type', $type, $base_url ) ); ?>" class="button">Reset</a>
            <a href="<?php echo esc_url( $export_url ); ?>" class="button">Export CSV</a>
        </div>
    </form>

    <!-- ═══════════════════════════════════════════
         The session list
         ═══════════════════════════════════════════ -->
    <?php if ( empty( $rows ) ) : ?>
        <div class="wssp-card">
            <p class="wssp-vrp__empty">No sessions match these filters.</p>
        </div>
    <?php else : ?>

        <p class="wssp-vrp__count">
            <?php echo (int) count( $rows ); ?> session<?php echo count( $rows ) === 1 ? '' : 's'; ?>
            shown
            <?php if ( array_filter( array( $filters['session_status'], $filters['search'] ) ) ) : ?>
                (filtered)
            <?php endif; ?>
        </p>

        <?php foreach ( $rows as $r ) :
            $b = $r['baseline'];
            $date_display = $b['session_date']
                ? wp_date( 'D, M j', strtotime( $b['session_date'] . ' UTC' ) )
                : '';
            $when = trim( $date_display . ( $b['session_time'] ? ' · ' . $b['session_time'] : '' ) );
        ?>
            <div class="wssp-card wssp-vrp__session">

                <!-- ─── Session header ─── -->
                <header class="wssp-vrp__session-header">
                    <div class="wssp-vrp__session-id">
                        <span class="wssp-vrp__session-code"><?php echo esc_html( $r['session_code'] ); ?></span>
                        <span class="wssp-vrp__session-status wssp-vrp__session-status--<?php echo esc_attr( $r['rollup_status'] ); ?>">
                            <?php echo esc_html( str_replace( '_', ' ', $r['rollup_status'] ) ); ?>
                        </span>
                    </div>
                    <div class="wssp-vrp__session-info">
                        <h2 class="wssp-vrp__session-title">
                            <?php echo esc_html( $b['session_title'] ?: $r['short_name'] ?: 'Untitled session' ); ?>
                        </h2>
                        <div class="wssp-vrp__session-sponsor">
                            <?php if ( $b['company_name'] ) : ?>
                                <?php echo esc_html( $b['company_name'] ); ?>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="wssp-vrp__session-when">
                        <?php if ( $when ) : ?>
                            <div class="wssp-vrp__session-when-date"><?php echo esc_html( $when ); ?></div>
                        <?php endif; ?>
                        <?php if ( $b['session_location'] ) : ?>
                            <div class="wssp-vrp__session-when-room"><?php echo esc_html( $b['session_location'] ); ?></div>
                        <?php endif; ?>
                    </div>
                </header>

                <!-- ─── Vendor-specific fields ─── -->
                <?php if ( ! empty( $field_keys ) ) : ?>
                    <div class="wssp-vrp__fields">
                        <?php foreach ( $field_keys as $fk ) :
                            $val = $r['fields'][ $fk ] ?? '';
                        ?>
                            <div class="wssp-vrp__field">
                                <div class="wssp-vrp__field-label">
                                    <?php echo esc_html( $reports->vendor_field_label( $fk ) ); ?>
                                </div>
                                <div class="wssp-vrp__field-value <?php echo $val === '' ? 'is-empty' : ''; ?>">
                                    <?php echo esc_html( $reports->vendor_field_display( $val ) ); ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>

                <!-- ─── Vendor-specific files ─── -->
                <?php if ( ! empty( $file_types ) ) : ?>
                    <div class="wssp-vrp__files">
                        <h3 class="wssp-vrp__files-heading">Materials</h3>
                        <ul class="wssp-vrp__files-list">
                            <?php foreach ( $file_types as $ft ) :
                                $f = $r['files'][ $ft ] ?? null;
                            ?>
                                <li class="wssp-vrp__file <?php echo $f ? '' : 'is-missing'; ?>">
                                    <span class="wssp-vrp__file-type"><?php echo esc_html( ucwords( str_replace( '_', ' ', $ft ) ) ); ?></span>
                                    <?php if ( $f ) : ?>
                                        <span class="wssp-vrp__file-status wssp-vrp__file-status--<?php echo esc_attr( sanitize_html_class( strtolower( str_replace( ' ', '-', $f['status'] ?? '' ) ) ) ); ?>">
                                            <?php echo esc_html( $f['status'] ?? '' ); ?>
                                            <?php if ( ! empty( $f['version'] ) ) : ?>
                                                <span class="wssp-vrp__file-version">v<?php echo (int) $f['version']; ?></span>
                                            <?php endif; ?>
                                        </span>
                                        <?php if ( ! empty( $f['file_url'] ) ) : ?>
                                            <a class="wssp-vrp__file-link"
                                               href="<?php echo esc_url( $f['file_url'] ); ?>"
                                               target="_blank" rel="noopener">View</a>
                                        <?php endif; ?>
                                    <?php else : ?>
                                        <span class="wssp-vrp__file-missing">Not yet uploaded</span>
                                    <?php endif; ?>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                <?php endif; ?>

            </div>
        <?php endforeach; ?>

    <?php endif; ?>

</div>
