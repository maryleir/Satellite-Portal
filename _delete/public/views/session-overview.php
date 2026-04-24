<?php
/**
 * Session Overview  Session info card (2/3) + Task progress sidebar (1/3).
 *
 * Expected variables:
 *   $session          (array)  Base session record
 *   $session_data     (array)  Full merged data: sessions table + session_meta + Formidable
 *   $phases           (array)  Enriched phase data (for deriving add-on pills)
 *   $event_type, $event_label, $purchased_addons, $stats, $session_id
 */

defined( 'ABSPATH' ) || exit;

// --- Extract values with correct priority (Formidable Entry > Meta Data Table wssp_session_meta > Session Data Table wssp_sessions) ---
$title     = $session_data['wssp_program_title'] 
          ?? $session_data['topic'] 
          ?? '';

$company_name = $session_data['wssp_data_company_name'] 
             ?? $session_data['sponsor_name'] 
             ?? $session_data['short_name'] 
             ?? '';
$company   = $company_name ? 'Sponsored by ' . $company_name : '';

$day = $session_data['session_day'] ?? '';           // Only from Smartsheet meta
$date = $session_data['session_date'] ?? '';         // Only from Smartsheet meta
$time = $session_data['session_time'] ?? '';         // Only from Smartsheet meta
$date_formatted = $date ? (DateTime::createFromFormat('Y-m-d', $date)->format('F j, Y') ?? $date) : '';

$date_time_display = trim( $day . ($day && $date_formatted ? ', ' : '') . $date_formatted );
if ( $time ) {
    $date_time_display .= '<br>' . $time;
}

$location  = $session_data['session_location'] ?? '';       // Only from Smartsheet meta

$av_contact    = $session_data['av_contact_name'] ?? '';    // Only from Smartsheet meta
$av_email      = $session_data['av_contact_email'] ?? '';   // Only from Smartsheet meta

$freeman       = $session_data['freeman_contact_name'] ?? '';     // Only from Smartsheet meta
$freeman_email = $session_data['freeman_contact_email'] ?? '';    // Only from Smartsheet meta

$ce_status     = $session_data['wssp_program_ce_status'] ?? '';
$audience      = $session_data['wssp_program_audience_type'] ?? '';
$restricted    = $session_data['wssp_program_intl_audience_desc'] ?? '';

// CE accredited sessions show the "Supported by" entity instead of company name
if ( $ce_status && strtolower( $ce_status ) !== 'non-ce' && strtolower( $ce_status ) !== 'no' ) {
    $company = $session_data['wssp_data_supported_by'] ?? $company;
}

?>

<div class="wssp-overview-row">

    <!-- --- Session Info (2/3) --- -->
    <div class="wssp-overview-card">
        <div class="wssp-overview-card__badges">
            <span class="wssp-badge wssp-badge--session"><?php echo esc_html( $session['session_code'] ); ?></span>
            <?php $show_ce = $ce_status && strtolower( $ce_status ) !== 'non-ce' && strtolower( $ce_status ) !== 'no'; ?>
            <span class="wssp-badge wssp-badge--outline" id="wssp-badge-ce" <?php if ( ! $show_ce ) echo 'style="display:none;"'; ?>>CE Accredited</span>
            <?php $show_audience = $audience && ! str_contains( $audience, 'All' ); ?>
            <span class="wssp-badge wssp-badge--outline" id="wssp-badge-audience" <?php if ( ! $show_audience ) echo 'style="display:none;"'; ?>>Restricted Audience</span>
        </div>

        <h2 class="wssp-overview-card__title">
            <?php echo $title ? esc_html( $title ) : '<span class="wssp-missing">Session title not yet provided</span>'; ?>
        </h2>
        <p class="wssp-overview-card__company">
            <?php echo $company ? wp_kses_post( nl2br( esc_html( $company ) ) ) : '<span class="wssp-missing">Company name not yet provided</span>'; ?>
           
            <span class="last-updated" id="wssp-audience-detail" <?php if ( ! $show_audience ) echo 'style="display:none;"'; ?>><br /><?php echo $restricted ? esc_html( $restricted ) : '<span class="wssp-missing">Audience Restriction Not defined.</span>'; ?></span>
        </p>


        <div class="wssp-overview-card__details">
            <div class="wssp-overview-card__detail">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/>
                    <line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/>
                </svg>
                <div>
                    <span class="wssp-overview-card__detail-label">Date &amp; Time</span>
                    <span class="wssp-overview-card__detail-value">
                        <?php echo $date_time_display ?: '<span class="wssp-missing">Not assigned</span>'; ?>
                    </span>
                </div>
            </div>
            <div class="wssp-overview-card__detail">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"/>
                    <circle cx="12" cy="10" r="3"/>
                </svg>
                <div>
                    <span class="wssp-overview-card__detail-label">Location</span>
                    <span class="wssp-overview-card__detail-value">
                        <?php echo $location ? esc_html( $location ) : '<span class="wssp-missing">Not assigned</span>'; ?>
                    </span>
                </div>
            </div>
        </div>

        <?php if ( $av_contact || $freeman ) : ?>
            <div class="wssp-overview-card__section">
                <span class="wssp-overview-card__section-label">CONTACTS</span>
                <div class="wssp-overview-card__contacts">
                    <?php if ( $av_contact ) : ?>
                        <div class="wssp-overview-card__contact">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <path d="M12 1a3 3 0 0 0-3 3v8a3 3 0 0 0 6 0V4a3 3 0 0 0-3-3z"/>
                                <path d="M19 10v2a7 7 0 0 1-14 0v-2"/><line x1="12" y1="19" x2="12" y2="23"/>
                            </svg>
                            <div>
                                <span class="wssp-overview-card__contact-label">A/V Contact</span>
                                <span class="wssp-overview-card__contact-value"><?php echo esc_html( $av_contact ); ?><?php echo $av_email ? '  ' . esc_html( $av_email ) : ''; ?></span>
                            </div>
                        </div>
                    <?php endif; ?>
                    <?php if ( $freeman ) : ?>
                        <div class="wssp-overview-card__contact">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/>
                                <circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/>
                                <path d="M16 3.13a4 4 0 0 1 0 7.75"/>
                            </svg>
                            <div>
                                <span class="wssp-overview-card__contact-label">Freeman Contact</span>
                                <span class="wssp-overview-card__contact-value"><?php echo esc_html( $freeman ); ?><?php echo $freeman_email ? '  ' . esc_html( $freeman_email ) : ''; ?></span>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        <?php endif; ?>
        
        <?php
        // Get add-on definitions from the TC plugin's manage-add-ons phase.
        $addon_defs = $this->config->get_addons( $event_type );
        ?>

        <?php if ( ! empty( $addon_defs ) ) : ?>
        <div class="wssp-overview-card__section">
            <span class="wssp-overview-card__section-label">ADDITIONAL (Add On) ITEMS</span>
            <div class="wssp-overview-card__addons">
                <?php foreach ( $addon_defs as $addon_slug => $addon ) :
                    $addon_state    = $addon_states[ $addon_slug ] ?? 'available';
                    $field_keys_str = implode( ',', $addon['field_keys'] );
                ?>
                    <?php if ( $addon_state === 'active' ) : ?>
                        <!-- Requested/confirmed add-on: static pill with checkmark -->
                        <span class="wssp-addon-pill wssp-addon-pill--confirmed">
                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
                                <polyline points="20 6 9 17 4 12"/>
                            </svg>
                            <?php echo esc_html( $addon['label'] ); ?>
                        </span>
                    <?php elseif ( $addon_state === 'declined' ) : ?>
                        <!-- Declined add-on: static gray pill with X -->
                        <span class="wssp-addon-pill wssp-addon-pill--declined">
                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
                                <line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/>
                            </svg>
                            <?php echo esc_html( $addon['label'] ); ?>
                        </span>
                    <?php else : ?>
                        <!-- Available add-on: clickable, opens form drawer to request -->
                        <button
                            class="wssp-addon-pill wssp-addon-pill--available wssp-open-form-drawer"
                            data-form-key="<?php echo esc_attr( $addon['form_key'] ); ?>"
                            data-session-id="<?php echo esc_attr( $session_id ); ?>"
                            data-field-keys="<?php echo esc_attr( $field_keys_str ); ?>"
                            data-task-key="<?php echo esc_attr( $addon['task_key'] ); ?>"
                            data-task-label="<?php echo esc_attr( $addon['label'] ); ?>">
                            + <?php echo esc_html( $addon['label'] ); ?>
                        </button>
                    <?php endif; ?>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <!-- --- Task Progress (1/3) --- -->
    <div class="wssp-progress-card">
        <h3 class="wssp-progress-card__title">Task Progress</h3>

        <div class="wssp-progress-card__stat">
            <div class="wssp-progress-card__icon wssp-progress-card__icon--complete">
                <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
                    <path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/>
                    <polyline points="22 4 12 14.01 9 11.01"/>
                </svg>
            </div>
            <div>
                <span class="wssp-progress-card__stat-label">Completed</span>
                <span class="wssp-progress-card__stat-value">
                    <strong><?php echo (int) $stats['completed']; ?></strong>
                    <span class="wssp-progress-card__stat-of">of <?php echo (int) $stats['total']; ?></span>
                </span>
            </div>
        </div>

        <div class="wssp-progress-card__stat">
            <div class="wssp-progress-card__icon wssp-progress-card__icon--due">
                <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
                    <circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/>
                </svg>
            </div>
            <div>
                <span class="wssp-progress-card__stat-label">Due This Week</span>
                <span class="wssp-progress-card__stat-value">
                    <strong><?php echo (int) $stats['due_this_week']; ?></strong>
                    <span class="wssp-progress-card__stat-of">active</span>
                </span>
            </div>
        </div>

        <div class="wssp-progress-card__stat">
            <div class="wssp-progress-card__icon wssp-progress-card__icon--overdue">
                <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
                    <circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/>
                    <line x1="12" y1="16" x2="12.01" y2="16"/>
                </svg>
            </div>
            <div>
                <span class="wssp-progress-card__stat-label">Overdue</span>
                <span class="wssp-progress-card__stat-value">
                    <strong><?php echo (int) $stats['overdue']; ?></strong>
                    <span class="wssp-progress-card__stat-of">urgent</span>
                </span>
            </div>
        </div>
    </div>

</div>