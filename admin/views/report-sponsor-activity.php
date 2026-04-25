<?php
/**
 * Report: Sponsor Activity
 *
 * Cross-session roster: every user assigned to one or more sessions, with
 * their most recent login, distinct login-day count, total login events,
 * and the sessions they're associated with.
 *
 * Also surfaces contacts who are listed in a session's logistics-contacts
 * repeater but DO NOT have a WP user account ("unregistered" rows). They
 * appear visually distinct, with no login data and no audit-log link, so
 * logistics can see at a glance who has been added as a contact but
 * cannot actually log in.
 *
 * Variables provided by WSSP_Reports::render_sponsor_activity():
 *   $rows           — filtered+sorted rows (registered + unregistered)
 *   $counts         — pill counts BEFORE state filter
 *   $filters        — current ?filter state
 *   $sessions       — all sessions (for the session dropdown)
 *   $roles          — distinct roles from wssp_session_users
 *   $base_url       — /admin.php?page=wssp-report-activity
 *   $export_url     — same + current filters + export=csv
 *
 * @package WSSP
 */

defined( 'ABSPATH' ) || exit;

if ( ! function_exists( 'wssp_act_url' ) ) {
    function wssp_act_url( $base_url, $filters, $key, $value ) {
        $next         = $filters;
        $next[ $key ] = $value;
        $next         = array_filter( $next, function ( $v ) { return $v !== '' && $v !== null; } );
        return add_query_arg( $next, $base_url );
    }
}

$format_login_ts = static function ( $ts ) {
    if ( ! $ts ) return '—';
    $unix = strtotime( $ts . ' UTC' );
    if ( ! $unix ) return esc_html( $ts );

    $days_ago = (int) floor( ( time() - $unix ) / DAY_IN_SECONDS );
    $abs      = wp_date( 'M j, Y g:i a', $unix );

    if ( $days_ago === 0 ) {
        $rel = 'today';
    } elseif ( $days_ago === 1 ) {
        $rel = 'yesterday';
    } elseif ( $days_ago < 7 ) {
        $rel = $days_ago . ' days ago';
    } else {
        $rel = '';
    }

    return $rel
        ? '<span title="' . esc_attr( $abs ) . '">' . esc_html( $abs ) . '<br><small>' . esc_html( $rel ) . '</small></span>'
        : '<span>' . esc_html( $abs ) . '</span>';
};

?>

<div class="wrap wssp-admin wssp-report wssp-act">
    <h1>Sponsor Activity</h1>
    <p class="wssp-report__subtitle">
        Every user and contact associated with a session, with login status.
        Use this to spot sponsors who haven't activated their accounts and
        to find contacts who were added to a session but cannot log in
        because they don't have a WP account.
    </p>

    <!-- ═══════════════════════════════════════════
         Counter pills (also serve as state filters)
         ═══════════════════════════════════════════ -->
    <div class="wssp-act__pills">
        <a class="wssp-act__pill <?php echo $filters['state'] === '' ? 'is-active' : ''; ?>"
           href="<?php echo esc_url( wssp_act_url( $base_url, $filters, 'state', '' ) ); ?>">
            <span class="wssp-act__pill-number"><?php echo (int) $counts['total']; ?></span>
            <span class="wssp-act__pill-label">All</span>
        </a>
        <a class="wssp-act__pill wssp-act__pill--never <?php echo $filters['state'] === 'never' ? 'is-active' : ''; ?>"
           href="<?php echo esc_url( wssp_act_url( $base_url, $filters, 'state', 'never' ) ); ?>">
            <span class="wssp-act__pill-number"><?php echo (int) $counts['never_logged_in']; ?></span>
            <span class="wssp-act__pill-label">Never Logged In</span>
        </a>
        <a class="wssp-act__pill wssp-act__pill--recent <?php echo $filters['state'] === 'recent' ? 'is-active' : ''; ?>"
           href="<?php echo esc_url( wssp_act_url( $base_url, $filters, 'state', 'recent' ) ); ?>">
            <span class="wssp-act__pill-number"><?php echo (int) $counts['recent_7d']; ?></span>
            <span class="wssp-act__pill-label">Active Last 7 Days</span>
        </a>
        <a class="wssp-act__pill wssp-act__pill--stale <?php echo $filters['state'] === 'stale' ? 'is-active' : ''; ?>"
           href="<?php echo esc_url( wssp_act_url( $base_url, $filters, 'state', 'stale' ) ); ?>">
            <span class="wssp-act__pill-number"><?php echo (int) $counts['stale_30d']; ?></span>
            <span class="wssp-act__pill-label">Stale (No Login &gt; 30 Days)</span>
        </a>
        <a class="wssp-act__pill wssp-act__pill--no-account <?php echo $filters['state'] === 'no_account' ? 'is-active' : ''; ?>"
           href="<?php echo esc_url( wssp_act_url( $base_url, $filters, 'state', 'no_account' ) ); ?>">
            <span class="wssp-act__pill-number"><?php echo (int) ( $counts['no_account'] ?? 0 ); ?></span>
            <span class="wssp-act__pill-label">No Portal Account</span>
        </a>
    </div>

    <!-- ═══════════════════════════════════════════
         Filter form
         ═══════════════════════════════════════════ -->
    <form method="get" action="<?php echo esc_url( $base_url ); ?>" class="wssp-act__filters">
        <input type="hidden" name="page" value="wssp-report-activity" />
        <?php if ( $filters['state'] ) : ?>
            <input type="hidden" name="state" value="<?php echo esc_attr( $filters['state'] ); ?>" />
        <?php endif; ?>

        <label class="wssp-act__filter">
            <span>Session</span>
            <select name="session_id">
                <option value="">All sessions</option>
                <?php foreach ( $sessions as $s ) : ?>
                    <option value="<?php echo esc_attr( $s['id'] ); ?>"
                            <?php selected( (int) $filters['session_id'], (int) $s['id'] ); ?>>
                        <?php echo esc_html( trim( $s['session_code'] . ' — ' . ( $s['short_name'] ?? '' ) ) ); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </label>

        <label class="wssp-act__filter">
            <span>Role</span>
            <select name="role">
                <option value="">All roles</option>
                <?php foreach ( $roles as $role_slug ) : ?>
                    <option value="<?php echo esc_attr( $role_slug ); ?>"
                            <?php selected( $filters['role'], $role_slug ); ?>>
                        <?php echo esc_html( ucwords( str_replace( '_', ' ', $role_slug ) ) ); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </label>

        <label class="wssp-act__filter wssp-act__filter--search">
            <span>Search</span>
            <input type="search" name="search"
                   value="<?php echo esc_attr( $filters['search'] ); ?>"
                   placeholder="Name or email…" />
        </label>

        <div class="wssp-act__filter-actions">
            <button type="submit" class="button button-primary">Apply</button>
            <a href="<?php echo esc_url( $base_url ); ?>" class="button">Reset</a>
            <a href="<?php echo esc_url( $export_url ); ?>" class="button">Export CSV</a>
        </div>
    </form>

    <!-- ═══════════════════════════════════════════
         The table
         ═══════════════════════════════════════════ -->
    <div class="wssp-card">
        <?php if ( empty( $rows ) ) : ?>
            <p class="wssp-act__empty">
                No users match these filters.
                <?php if ( array_filter( $filters ) ) : ?>
                    <a href="<?php echo esc_url( $base_url ); ?>">Clear filters</a>
                <?php endif; ?>
            </p>
        <?php else : ?>
            <table class="wp-list-table widefat fixed striped wssp-act__table">
                <thead>
                    <tr>
                        <th class="wssp-act__col-user">User / Contact</th>
                        <th class="wssp-act__col-sessions">Sessions</th>
                        <th class="wssp-act__col-role">Role</th>
                        <th class="wssp-act__col-last">Last Login</th>
                        <th class="wssp-act__col-days">Login Days</th>
                        <th class="wssp-act__col-total">Total Logins</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ( $rows as $r ) :
                        $is_unregistered = ! empty( $r['is_unregistered'] );

                        $row_class = 'wssp-act__row';
                        if ( $is_unregistered ) {
                            $row_class .= ' wssp-act__row--no-account';
                        } elseif ( ! $r['last_login'] ) {
                            $row_class .= ' wssp-act__row--never';
                        } elseif ( ! empty( $r['is_stale'] ) ) {
                            $row_class .= ' wssp-act__row--stale';
                        } elseif ( ! empty( $r['is_recent'] ) ) {
                            $row_class .= ' wssp-act__row--recent';
                        }
                    ?>
                        <tr class="<?php echo esc_attr( $row_class ); ?>"
                            <?php if ( ! $is_unregistered ) : ?>
                                data-user-id="<?php echo (int) $r['user_id']; ?>"
                            <?php endif; ?>>
                            <td>
                                <strong><?php echo esc_html( $r['display_name'] ?: '(no name)' ); ?></strong>
                                <?php if ( $is_unregistered ) : ?>
                                    <span class="wssp-act__no-account-badge" title="This contact does not have a WP user account and cannot log in to the portal.">No Portal Account</span>
                                <?php endif; ?>
                                <?php if ( ! empty( $r['user_email'] ) ) : ?>
                                    <div class="wssp-act__user-email">
                                        <a href="mailto:<?php echo esc_attr( $r['user_email'] ); ?>">
                                            <?php echo esc_html( $r['user_email'] ); ?>
                                        </a>
                                    </div>
                                <?php endif; ?>
                            </td>
                            <td class="wssp-act__col-sessions">
                                <?php if ( ! empty( $r['session_links'] ) ) : ?>
                                    <?php foreach ( $r['session_links'] as $sl ) : ?>
                                        <?php if ( ! empty( $sl['audit_url'] ) ) : ?>
                                            <a class="wssp-act__session-tag"
                                               href="<?php echo esc_url( $sl['audit_url'] ); ?>"
                                               title="View this user's logins for this session in the audit log">
                                                <?php echo esc_html( $sl['code'] ); ?>
                                            </a>
                                        <?php else : ?>
                                            <span class="wssp-act__session-tag wssp-act__session-tag--plain"
                                                  title="No audit history available for unregistered contacts">
                                                <?php echo esc_html( $sl['code'] ); ?>
                                            </span>
                                        <?php endif; ?>
                                    <?php endforeach; ?>
                                <?php else : ?>
                                    <span class="wssp-act__no-sessions">—</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ( $is_unregistered ) : ?>
                                    <span class="wssp-act__muted">—</span>
                                <?php else : ?>
                                    <?php echo esc_html( ucwords( str_replace( '_', ' ', $r['role'] ?? '' ) ) ); ?>
                                <?php endif; ?>
                            </td>
                            <td class="wssp-act__col-last">
                                <?php
                                if ( $is_unregistered ) {
                                    echo '<span class="wssp-act__muted">—</span>';
                                } else {
                                    echo $format_login_ts( $r['last_login'] );
                                }
                                ?>
                            </td>
                            <td class="wssp-act__col-days">
                                <?php
                                if ( $is_unregistered ) {
                                    echo '<span class="wssp-act__muted">—</span>';
                                } else {
                                    echo $r['login_days'] ? (int) $r['login_days'] : '—';
                                }
                                ?>
                            </td>
                            <td class="wssp-act__col-total">
                                <?php
                                if ( $is_unregistered ) {
                                    echo '<span class="wssp-act__muted">—</span>';
                                } else {
                                    echo $r['total_logins'] ? (int) $r['total_logins'] : '—';
                                }
                                ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>

            <p class="wssp-act__count">
                <?php echo (int) count( $rows ); ?> row<?php echo count( $rows ) === 1 ? '' : 's'; ?>
                shown
                <?php if ( array_filter( array( $filters['session_id'], $filters['role'], $filters['search'], $filters['state'] ) ) ) : ?>
                    (filtered)
                <?php endif; ?>
            </p>
        <?php endif; ?>
    </div>
</div>
