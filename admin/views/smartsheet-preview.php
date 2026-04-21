<?php
/**
 * Smartsheet Sync Preview — Partial template
 *
 * Renders a diff table showing what will change, with a Confirm / Cancel action.
 * Supports both PULL (Smartsheet→Portal) and PUSH (Portal→Smartsheet) previews.
 *
 * Include this partial in:
 *   1. session-manager.php  — when $_GET['ss_preview'] is set (single pull)
 *   2. admin-dashboard.php  — when $_GET['ss_preview_all'] is set (all sessions pull)
 *   3. session-manager.php  — when $_GET['ss_push_preview'] is set (single push)
 *
 * ═══════════════════════════════════════════════════════════════════
 *
 * USAGE IN session-manager.php (PULL preview):
 *
 *   <?php if ( ! empty( $_GET['ss_preview'] ) ) :
 *       $ss_preview = get_transient( 'wssp_ss_preview_' . $session_id );
 *       if ( $ss_preview && ! empty( $ss_preview['diff'] ) ) :
 *           $preview_type = 'single';
 *           include WSSP_PLUGIN_DIR . 'admin/views/smartsheet-preview.php';
 *       endif;
 *   endif; ?>
 *
 * ═══════════════════════════════════════════════════════════════════
 *
 * USAGE IN session-manager.php (PUSH preview):
 *
 *   <?php if ( ! empty( $_GET['ss_push_preview'] ) ) :
 *       $ss_preview = get_transient( 'wssp_ss_push_preview_' . $session_id );
 *       if ( $ss_preview && ! empty( $ss_preview['diff'] ) ) :
 *           $preview_type = 'push';
 *           include WSSP_PLUGIN_DIR . 'admin/views/smartsheet-preview.php';
 *       endif;
 *   endif; ?>
 *
 * ═══════════════════════════════════════════════════════════════════
 *
 * USAGE IN admin-dashboard.php:
 *
 *   <?php if ( ! empty( $_GET['ss_preview_all'] ) ) :
 *       $ss_preview = get_transient( 'wssp_ss_preview_all' );
 *       if ( $ss_preview ) :
 *           $preview_type = 'all';
 *           include WSSP_PLUGIN_DIR . 'admin/views/smartsheet-preview.php';
 *       endif;
 *   endif; ?>
 *
 * ═══════════════════════════════════════════════════════════════════
 *
 * Variables expected:
 *   $ss_preview   — result array from pull/push dry_run
 *   $preview_type — 'single', 'all', or 'push'
 *   $session_id   — (single/push only) the session being previewed
 *
 * @package WSSP
 */

defined( 'ABSPATH' ) || exit;
?>

<div class="wssp-card wssp-ss-preview" style="border-left: 4px solid #e65100; background: #fff8e1;">
    <h2 style="color: #e65100; margin-top: 0;">
        <?php if ( $preview_type === 'push' ) : ?>
            ⚠ Smartsheet Push Preview
        <?php else : ?>
            ⚠ Smartsheet Pull Preview
        <?php endif; ?>
    </h2>
    <p>The following changes will be applied when you confirm. Review carefully.</p>

    <?php if ( $preview_type === 'single' && ( ! empty( $ss_preview['diff'] ) || ! empty( $ss_preview['skipped_fields'] ) ) ) : ?>

        <!-- ─── Single session diff ─── -->
        <table class="wp-list-table widefat fixed striped" style="margin: 16px 0;">
            <thead>
                <tr>
                    <th style="width: 200px;">Smartsheet Column</th>
                    <th style="width: 180px;">Portal Field</th>
                    <th>Current Portal Value</th>
                    <th>New Value from Smartsheet</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ( $ss_preview['diff'] as $change ) : ?>
                    <tr>
                        <td><strong><?php echo esc_html( $change['ss_title'] ); ?></strong></td>
                        <td><code><?php echo esc_html( $change['field'] ); ?></code></td>
                        <td style="background: #fef2f2; color: #991b1b;">
                            <?php echo esc_html( $change['old'] ?: '(empty)' ); ?>
                        </td>
                        <td style="background: #f0fdf4; color: #166534;">
                            <?php echo esc_html( $change['new'] ?: '(empty)' ); ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <?php if ( ! empty( $ss_preview['skipped'] ) ) : ?>
            <h3 style="color: #b45309; margin-top: 20px;">
                Protected Fields (<?php echo (int) $ss_preview['skipped']; ?> skipped)
            </h3>
            <p style="color: #666; font-size: 13px;">These Smartsheet cells are blank but the portal already has data. The existing portal values will be kept.</p>
            <table class="wp-list-table widefat fixed striped" style="margin: 12px 0;">
                <thead>
                    <tr>
                        <th style="width: 200px;">Smartsheet Column</th>
                        <th style="width: 180px;">Portal Field</th>
                        <th>Protected Portal Value</th>
                        <th style="width: 140px;">Smartsheet Value</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ( $ss_preview['skipped_fields'] ?? array() as $sf ) : ?>
                        <tr>
                            <td><strong><?php echo esc_html( $sf['ss_title'] ); ?></strong></td>
                            <td><code><?php echo esc_html( $sf['field'] ); ?></code></td>
                            <td><?php echo esc_html( $sf['protected_value'] ); ?></td>
                            <td style="color: #999; font-style: italic;">(empty)</td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>

        <div style="display: flex; gap: 12px; margin-top: 16px;">
            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                <?php wp_nonce_field( 'wssp_smartsheet_pull_confirm' ); ?>
                <input type="hidden" name="action" value="wssp_smartsheet_pull_confirm">
                <input type="hidden" name="session_id" value="<?php echo esc_attr( $session_id ); ?>">
                <button type="submit" class="button button-primary">
                    ✓ Confirm — Apply <?php echo count( $ss_preview['diff'] ); ?> Change(s)
                </button>
            </form>
            <a href="<?php echo esc_url( admin_url( 'admin.php?page=wssp-manage-session&session_id=' . $session_id ) ); ?>"
               class="button">
                ✕ Cancel
            </a>
        </div>

        <?php if ( ! empty( $ss_preview['raw_values'] ) ) : ?>
            <details style="margin-top: 20px; border: 1px solid #e5e7eb; border-radius: 4px;">
                <summary style="padding: 8px 12px; cursor: pointer; font-size: 12px; color: #666; background: #f8f9fa;">
                    Debug: All pull-direction fields (<?php echo count( $ss_preview['raw_values'] ); ?> fields)
                </summary>
                <div style="overflow-x: auto;">
                    <table class="wp-list-table widefat fixed striped" style="font-size: 11px; margin: 0;">
                        <thead>
                            <tr>
                                <th>SS Column</th>
                                <th>Portal Field</th>
                                <th>Type</th>
                                <th>Raw API Value</th>
                                <th>Display Value</th>
                                <th>Mapped Value</th>
                                <th>Portal Value</th>
                                <th>Outcome</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ( $ss_preview['raw_values'] as $rv ) : ?>
                                <tr style="<?php echo $rv['outcome'] === 'changed' ? 'background: #f0fdf4;' : ( $rv['outcome'] === 'skipped (empty protection)' ? 'background: #fffbeb;' : '' ); ?>">
                                    <td><?php echo esc_html( $rv['ss_title'] ); ?></td>
                                    <td><code><?php echo esc_html( $rv['field'] ); ?></code></td>
                                    <td><?php echo esc_html( $rv['type'] ); ?></td>
                                    <td><code><?php echo esc_html( var_export( $rv['raw_value'], true ) ); ?></code></td>
                                    <td><code><?php echo esc_html( var_export( $rv['display_value'], true ) ); ?></code></td>
                                    <td><code><?php echo esc_html( var_export( $rv['mapped_value'], true ) ); ?></code></td>
                                    <td><code><?php echo esc_html( var_export( $rv['portal_value'], true ) ); ?></code></td>
                                    <td><strong><?php echo esc_html( $rv['outcome'] ); ?></strong></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </details>
        <?php endif; ?>

    <?php elseif ( $preview_type === 'all' ) : ?>
        <!-- ─── All sessions summary ─── -->

        <?php $would_create = $ss_preview['would_create'] ?? array(); ?>
        <?php if ( ! empty( $would_create ) ) : ?>
            <h3 style="color: #1565c0;">New Sessions to Create</h3>
            <p>These Smartsheet rows don't match any existing session and will be created:</p>
            <table class="wp-list-table widefat fixed striped" style="margin: 12px 0; max-width: 500px;">
                <thead>
                    <tr>
                        <th>Session Code</th>
                        <th>Sponsor</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ( $would_create as $wc ) : ?>
                        <tr>
                            <td><strong><?php echo esc_html( $wc['session_code'] ); ?></strong></td>
                            <td><?php echo esc_html( $wc['short_name'] ?: '—' ); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>

        <?php
        $sessions_with_activity = array_filter( $ss_preview['results'] ?? array(), function( $r ) {
            return ( $r['changes'] ?? 0 ) > 0 || ( $r['skipped'] ?? 0 ) > 0;
        });
        ?>

        <?php if ( ! empty( $sessions_with_activity ) ) : ?>
            <h3>Field Changes by Session</h3>
            <?php foreach ( $sessions_with_activity as $sr ) : ?>
                <details style="margin-bottom: 8px; border: 1px solid #e5e7eb; border-radius: 4px; padding: 0;">
                    <summary style="padding: 8px 12px; cursor: pointer; font-weight: 600; background: #f8f9fa;">
                        <?php echo esc_html( $sr['session_code'] ); ?>
                        — <?php echo (int) $sr['changes']; ?> change(s)
                        <?php if ( $sr['skipped'] > 0 ) : ?>
                            <span style="color: #999; font-weight: 400;">(<?php echo (int) $sr['skipped']; ?> skipped)</span>
                        <?php endif; ?>
                    </summary>
                    <table class="wp-list-table widefat fixed striped" style="margin: 0; border: none;">
                        <thead>
                            <tr>
                                <th style="width: 180px;">SS Column</th>
                                <th style="width: 160px;">Portal Field</th>
                                <th>Current</th>
                                <th>New</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ( $sr['diff'] ?? array() as $change ) : ?>
                                <tr>
                                    <td><?php echo esc_html( $change['ss_title'] ); ?></td>
                                    <td><code><?php echo esc_html( $change['field'] ); ?></code></td>
                                    <td style="background: #fef2f2; color: #991b1b; font-size: 12px;">
                                        <?php echo esc_html( $change['old'] ?: '(empty)' ); ?>
                                    </td>
                                    <td style="background: #f0fdf4; color: #166534; font-size: 12px;">
                                        <?php echo esc_html( $change['new'] ?: '(empty)' ); ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                    <?php if ( ! empty( $sr['skipped_fields'] ) ) : ?>
                        <div style="padding: 8px 12px; background: #fffbeb; border-top: 1px solid #e5e7eb;">
                            <strong style="font-size: 12px; color: #b45309;">Protected fields (kept as-is):</strong>
                            <?php foreach ( $sr['skipped_fields'] as $sf ) : ?>
                                <div style="font-size: 12px; color: #666; padding: 2px 0;">
                                    <code><?php echo esc_html( $sf['field'] ); ?></code>
                                    (<?php echo esc_html( $sf['ss_title'] ); ?>)
                                    — portal value: "<?php echo esc_html( mb_strlen( $sf['protected_value'] ) > 50 ? mb_substr( $sf['protected_value'], 0, 50 ) . '…' : $sf['protected_value'] ); ?>"
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </details>
            <?php endforeach; ?>
        <?php endif; ?>

        <?php
        $total_skipped = array_sum( array_column( $ss_preview['results'] ?? array(), 'skipped' ) );
        if ( $total_skipped > 0 ) : ?>
            <p style="color: #b45309; margin-top: 12px;">
                ⚠ <?php echo $total_skipped; ?> field(s) protected across all sessions — Smartsheet cells are blank but portal has data. See details within each session above.
            </p>
        <?php endif; ?>

        <div style="display: flex; gap: 12px; margin-top: 16px;">
            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                <?php wp_nonce_field( 'wssp_smartsheet_pull_all_confirm' ); ?>
                <input type="hidden" name="action" value="wssp_smartsheet_pull_all_confirm">
                <button type="submit" class="button button-primary">
                    ✓ Confirm — Sync All Sessions
                </button>
            </form>
            <a href="<?php echo esc_url( admin_url( 'admin.php?page=wssp-dashboard' ) ); ?>"
               class="button">
                ✕ Cancel
            </a>
        </div>

    <?php elseif ( $preview_type === 'push' && ! empty( $ss_preview['diff'] ) ) : ?>
        <!-- ─── Push preview diff ─── -->
        <?php
        // Mode determines what the preview is showing:
        //   diff_with_ss  — manual admin push, compared against live SS row. Show real ss_before.
        //   changed_keys  — auto-push scoped to sponsor-changed fields, no SS fetch. ss_before is unknown.
        $push_mode = $ss_preview['mode'] ?? 'diff_with_ss';
        ?>
        <table class="wp-list-table widefat fixed striped" style="margin: 16px 0;">
            <thead>
                <tr>
                    <th style="width: 200px;">Smartsheet Column</th>
                    <th style="width: 180px;">Portal Field</th>
                    <th>Source</th>
                    <th>Current Portal Value</th>
                    <?php if ( $push_mode === 'diff_with_ss' ) : ?>
                        <th>Current Smartsheet Value</th>
                    <?php endif; ?>
                </tr>
            </thead>
            <tbody>
                <?php foreach ( $ss_preview['diff'] as $change ) : ?>
                    <tr>
                        <td><strong><?php echo esc_html( $change['ss_title'] ); ?></strong></td>
                        <td><code><?php echo esc_html( $change['field'] ); ?></code></td>
                        <td>
                            <?php
                            $store_labels = array( 'meta' => 'Session Meta', 'session' => 'Session Table', 'formidable' => 'Formidable Form' );
                            echo esc_html( $store_labels[ $change['store'] ] ?? $change['store'] );
                            ?>
                        </td>
                        <td style="background: #f0fdf4; color: #166534;">
                            <?php
                            $portal_display = $change['value'];
                            if ( is_array( $portal_display ) ) {
                                $portal_display = wp_json_encode( $portal_display );
                            }
                            echo esc_html( $portal_display !== '' ? $portal_display : '(empty)' );
                            ?>
                        </td>
                        <?php if ( $push_mode === 'diff_with_ss' ) : ?>
                            <td style="background: #fef2f2; color: #991b1b;">
                                <?php
                                $ss_display = $change['ss_before'] ?? '';
                                if ( is_array( $ss_display ) ) {
                                    $ss_display = wp_json_encode( $ss_display );
                                }
                                echo esc_html( $ss_display !== '' ? (string) $ss_display : '(empty)' );
                                ?>
                            </td>
                        <?php endif; ?>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <?php // Show A2 skipped fields (portal empty, SS has value — protected from overwrite) ?>
        <?php if ( ! empty( $ss_preview['skipped_fields'] ) ) : ?>
            <h3 style="margin-top: 24px;">Skipped (empty-value protection)</h3>
            <p style="color: #666; font-size: 13px; margin-top: 4px;">
                These fields are empty in the portal but have values in Smartsheet.
                They will NOT be touched — this protects logistics entries from being wiped by stale portal data.
            </p>
            <table class="wp-list-table widefat fixed striped" style="margin: 8px 0;">
                <thead>
                    <tr>
                        <th style="width: 200px;">Smartsheet Column</th>
                        <th style="width: 180px;">Portal Field</th>
                        <th>Current Smartsheet Value (protected)</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ( $ss_preview['skipped_fields'] as $sf ) : ?>
                        <tr>
                            <td><strong><?php echo esc_html( $sf['ss_title'] ); ?></strong></td>
                            <td><code><?php echo esc_html( $sf['field'] ); ?></code></td>
                            <td style="background: #fef2f2; color: #991b1b;">
                                <?php
                                $v = $sf['ss_value'] ?? '';
                                if ( is_array( $v ) ) { $v = wp_json_encode( $v ); }
                                echo esc_html( (string) $v );
                                ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>

        <div style="display: flex; gap: 12px; margin-top: 16px;">
            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                <?php wp_nonce_field( 'wssp_smartsheet_push_confirm' ); ?>
                <input type="hidden" name="action" value="wssp_smartsheet_push_confirm">
                <input type="hidden" name="session_id" value="<?php echo esc_attr( $session_id ); ?>">
                <button type="submit" class="button button-primary">
                    ✓ Confirm — Push <?php echo count( $ss_preview['diff'] ); ?> Field(s) to Smartsheet
                </button>
            </form>
            <a href="<?php echo esc_url( admin_url( 'admin.php?page=wssp-manage-session&session_id=' . $session_id ) ); ?>"
               class="button">
                ✕ Cancel
            </a>
        </div>

    <?php endif; ?>
</div>
