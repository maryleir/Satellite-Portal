<?php
/**
 * Dashboard Header — Portal header bar.
 *
 * Displays the portal title, session selector dropdown (for multi-session
 * users and admins), and today's date (with admin date-override dropdown
 * for testing date-dependent behavior).
 *
 * Expected variables:
 *   $session        (array)  Current session record.
 *   $all_sessions   (array)  All sessions this user can access [ ['session_id'=>int, 'role'=>string], ... ].
 *   $session_lookup (array)  Full session records keyed by ID.
 *   $is_admin       (bool)   Whether user is admin/editor.
 *   $permalink      (string) Current page permalink (for session switching URLs).
 *   $event_type     (string) Current event type slug.
 *
 * @package WSSP
 */

defined( 'ABSPATH' ) || exit;

$show_selector  = count( $all_sessions ) > 1 || $is_admin;
$effective_date = WSSP_Date_Override::get_today();
$today_display  = date_i18n( 'D, M j, Y', strtotime( $effective_date ) );
$can_override   = current_user_can( 'manage_options' );
?>

<div class="wssp-portal-header">
    <div class="wssp-portal-header__left">
        <div class="wssp-portal-header__icon">
          <svg width="20" height="20" viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg">
          <path d="M7.5 2.5H3.33333C2.8731 2.5 2.5 2.8731 2.5 3.33333V9.16667C2.5 9.6269 2.8731 10 3.33333 10H7.5C7.96024 10 8.33333 9.6269 8.33333 9.16667V3.33333C8.33333 2.8731 7.96024 2.5 7.5 2.5Z" stroke="white" stroke-width="1.66667" stroke-linecap="round" stroke-linejoin="round"/>
          <path d="M16.6667 2.5H12.5C12.0398 2.5 11.6667 2.8731 11.6667 3.33333V5.83333C11.6667 6.29357 12.0398 6.66667 12.5 6.66667H16.6667C17.1269 6.66667 17.5 6.29357 17.5 5.83333V3.33333C17.5 2.8731 17.1269 2.5 16.6667 2.5Z" stroke="white" stroke-width="1.66667" stroke-linecap="round" stroke-linejoin="round"/>
          <path d="M16.6667 10H12.5C12.0398 10 11.6667 10.3731 11.6667 10.8333V16.6667C11.6667 17.1269 12.0398 17.5 12.5 17.5H16.6667C17.1269 17.5 17.5 17.1269 17.5 16.6667V10.8333C17.5 10.3731 17.1269 10 16.6667 10Z" stroke="white" stroke-width="1.66667" stroke-linecap="round" stroke-linejoin="round"/>
          <path d="M7.5 13.3333H3.33333C2.8731 13.3333 2.5 13.7064 2.5 14.1667V16.6667C2.5 17.1269 2.8731 17.5 3.33333 17.5H7.5C7.96024 17.5 8.33333 17.1269 8.33333 16.6667V14.1667C8.33333 13.7064 7.96024 13.3333 7.5 13.3333Z" stroke="white" stroke-width="1.66667" stroke-linecap="round" stroke-linejoin="round"/>
          </svg>
        </div>
        <div class="wssp-portal-header__title">
            <span class="wssp-portal-header__name">Satellite Symposium Portal</span>
            <span class="wssp-portal-header__subtitle">WORLD<i>Symposium</i> 2027   |  <a href="https://worldsponor.local/satellite-symposia/assigned-satellite-symposium/">Back to Assigned Satellite List</a></span>
        </div>
    </div>

    <div class="wssp-portal-header__right">
        <?php if ( $show_selector ) : ?>
            <label class="wssp-portal-header__session-label" for="wssp-session-select">Session:</label>
            <select id="wssp-session-select" class="wssp-portal-header__session-select"
                    data-permalink="<?php echo esc_attr( $permalink ); ?>">
                <?php foreach ( $all_sessions as $link ) :
                    $s = $session_lookup[ $link['session_id'] ] ?? null;
                    if ( ! $s ) continue;
                    $label = $s['session_code'];
                    if ( $s['short_name'] ) {
                        $label .= ' – ' . $s['short_name'];
                    }
                    // Truncate long labels
                    if ( strlen( $label ) > 40 ) {
                        $label = substr( $label, 0, 37 ) . '…';
                    }
                    $selected = ( (int) $s['id'] === (int) $session['id'] ) ? 'selected' : '';
                ?>
                    <option value="<?php echo esc_attr( $s['session_key'] ); ?>" <?php echo $selected; ?>>
                        <?php echo esc_html( $label ); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        <?php endif; ?>

        <?php if ( $can_override ) :
            $test_dates = WSSP_Date_Override::get_test_dates( $this->config, $event_type );
        ?>
            <span class="wssp-portal-header__date wssp-portal-header__date--override">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <rect x="3" y="4" width="18" height="18" rx="2"/>
                    <line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/>
                    <line x1="3" y1="10" x2="21" y2="10"/>
                </svg>
                <select id="wssp-date-override" class="wssp-portal-header__date-select">
                    <?php foreach ( $test_dates as $opt ) :
                        $selected = ( $opt['date'] === $effective_date ) ? 'selected' : '';
                    ?>
                        <option value="<?php echo esc_attr( $opt['date'] ); ?>" <?php echo $selected; ?>>
                            <?php echo esc_html( $opt['label'] ); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <?php if ( WSSP_Date_Override::is_overridden() ) : ?>
                    <span class="wssp-portal-header__date-badge">SIMULATED</span>
                <?php endif; ?>
            </span>
        <?php else : ?>
            <span class="wssp-portal-header__date">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <rect x="3" y="4" width="18" height="18" rx="2"/>
                    <line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/>
                    <line x1="3" y1="10" x2="21" y2="10"/>
                </svg>
                <?php echo esc_html( $today_display ); ?>
            </span>
        <?php endif; ?>
    </div>
</div>