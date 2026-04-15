<?php
/**
 * Session Meta Fields — Admin partial for session-manager.php
 *
 * Renders the session details and add-on purchase fields.
 * Include this inside your session-manager.php view.
 *
 * Expected variables:
 *   $session      (array)           Session record from wssp_sessions
 *   $session_meta (WSSP_Session_Meta) Session meta instance
 *   $config       (WSSP_Config)     Config instance
 *
 * @package WSSP
 */

defined( 'ABSPATH' ) || exit;

$session_id = $session['id'];
$event_type = $session['event_type'] ?? 'satellite';
$meta       = $session_meta->get_all( $session_id );
$addons_config = $config->get_addons( $event_type );

echo '<!-- DEBUG addons: ' . esc_html( wp_json_encode( array_keys( $addons_config ) ) ) . ' -->';
echo '<!-- DEBUG meta keys: ' . esc_html( wp_json_encode( array_keys( $meta ) ) ) . ' -->';


// Helper to get meta with fallback
$m = function( $key, $default = '' ) use ( $meta ) {
    return $meta[ $key ] ?? $default;
};
?>

<!-- ═══ Session Details ═══ -->
<div class="wssp-admin-section">
    <h3>Session Details</h3>
    <p class="description">Conference logistics and scheduling information.</p>

    <?php
    // Conference dates — single source for all date dropdowns
    $conference_dates = array( '2027-01-31', '2027-02-01', '2027-02-02', '2027-02-03', '2027-02-04' );
    $times = array( '06:45 - 07:45 AM', '12:15 - 13:15 PM', '17:45 - 18:45 PM' );
    $rooms = array( 'Grand Hall AB', 'Grand Hall CD' );

    ?>

    <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
        <?php wp_nonce_field( 'wssp_save_session_meta' ); ?>
        <input type="hidden" name="action" value="wssp_save_session_meta">
        <input type="hidden" name="session_id" value="<?php echo esc_attr( $session_id ); ?>">

        <table class="form-table">
        
            <tr>
                <th scope="row">Sponsor Name</th>
                <td>
                    <input type="text" name="meta[sponsor_name]" class="regular-text"
                           value="<?php echo esc_attr( $m( 'sponsor_name' ) ); ?>"
                           placeholder="Sponsor / Company Name">
                </td>
            </tr>
            
            <tr>
                <th scope="row">Session Topic / Title</th>
                <td>
                    <input type="text" name="meta[topic]" class="regular-text"
                           value="<?php echo esc_attr( $m( 'topic' ) ); ?>"
                           placeholder="Topic (from Smartsheet)">
                </td>
            </tr>


            <!-- ─── Schedule ─── -->
            <tr>
                <th scope="row">Session Date</th>
                <td>
                    <select name="meta[session_date]" class="regular-text">
                        <option value="">— Select —</option>
                        <?php foreach ( $conference_dates as $date ) :
                            $ts    = strtotime( $date );
                            $label = date( 'l, F j, Y', $ts );
                        ?>
                            <option value="<?php echo esc_attr( $date ); ?>" <?php selected( $m( 'session_date' ), $date ); ?>>
                                <?php echo esc_html( $label ); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </td>
            </tr>
            <tr>
                <th scope="row">Session Time</th>
                <td>
                    <select name="meta[session_time]" class="regular-text">
                        <option value="">— Select —</option>
                        <?php
                        foreach ( $times as $time ) :
                        ?>
                            <option value="<?php echo esc_attr( $time ); ?>" <?php selected( $m( 'session_time' ), $time ); ?>>
                                <?php echo esc_html( $time ); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </td>
            </tr>
            <tr>
                <th scope="row">Location</th>
                <td>
                    <select name="meta[session_location]" class="regular-text">
                        <option value="">— Select —</option>
                        <?php
                        foreach ( $rooms as $room ) :
                        ?>
                            <option value="<?php echo esc_attr( $room ); ?>" <?php selected( $m( 'session_location' ), $room ); ?>>
                                <?php echo esc_html( $room ); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </td>
            </tr>

            <!-- ─── Contacts ─── -->
            <tr>
                <th scope="row">Assigned AV Contact</th>
                <td>
                    <input type="text" name="meta[av_contact_name]" class="regular-text"
                           value="<?php echo esc_attr( $m( 'av_contact_name' ) ); ?>"
                           placeholder="Name">
                    <input type="email" name="meta[av_contact_email]" class="regular-text"
                           value="<?php echo esc_attr( $m( 'av_contact_email' ) ); ?>"
                           placeholder="Email" style="margin-top: 4px;">
                </td>
            </tr>
            <tr>
                <th scope="row">Freeman Contact</th>
                <td>
                    <input type="text" name="meta[freeman_contact_name]" class="regular-text"
                           value="<?php echo esc_attr( $m( 'freeman_contact_name' ) ); ?>"
                           placeholder="Name">
                    <input type="email" name="meta[freeman_contact_email]" class="regular-text"
                           value="<?php echo esc_attr( $m( 'freeman_contact_email' ) ); ?>"
                           placeholder="Email" style="margin-top: 4px;">
                </td>
            </tr>

            <!-- ─── Rehearsal ─── -->
            <tr>
                <th scope="row">Rehearsal Date</th>
                <td>
                    <select name="meta[rehearsal_date]" class="regular-text">
                        <option value="">— Select —</option>
                        <?php foreach ( $conference_dates as $date ) :
                            $ts    = strtotime( $date );
                            $label = date( 'l, F j, Y', $ts );
                        ?>
                            <option value="<?php echo esc_attr( $date ); ?>" <?php selected( $m( 'rehearsal_date' ), $date ); ?>>
                                <?php echo esc_html( $label ); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </td>
            </tr>
            <tr>
                <th scope="row">Rehearsal Time Slot</th>
                <td>
                    <input type="text" name="meta[rehearsal_time]" class="regular-text"
                           value="<?php echo esc_attr( $m( 'rehearsal_time' ) ); ?>"
                           placeholder="e.g. 2:00 - 2:30 PM">
                </td>
            </tr>

            <!-- ─── Tracking ─── -->
            <tr>
                <th scope="row">Lead Retrieval #</th>
                <td>
                    <input type="text" name="meta[lead_retrieval_number]" class="regular-text"
                           value="<?php echo esc_attr( $m( 'lead_retrieval_number' ) ); ?>">
                </td>
            </tr>
            <tr>
                <th scope="row">Lead Retrieval Report Sent</th>
                <td>
                    <label>
                        <input type="checkbox" name="meta[lead_report_sent]" value="yes"
                            <?php checked( $m( 'lead_report_sent' ), 'yes' ); ?>>
                        Report has been sent to sponsor
                    </label>
                </td>
            </tr>
            <tr>
                <th scope="row">On Demand Count</th>
                <td>
                    <input type="number" name="meta[on_demand_count]" class="small-text"
                           value="<?php echo esc_attr( $m( 'on_demand_count' ) ); ?>"
                           min="0">
                </td>
            </tr>
        </table>

        <!-- ═══ Purchased Add-Ons ═══ -->
        <h3>Purchased Add-Ons</h3>
        <p class="description">Mark which add-ons were purchased with the application. Sponsors can also request add-ons through the portal.</p>

        <table class="form-table">
            <?php foreach ( $addons_config as $addon_slug => $addon ) :
                $meta_key  = 'addon_' . $addon_slug;
                $is_purchased = $m( $meta_key ) === 'yes';
                $requested_key = 'addon_requested_' . $addon_slug;
                $is_requested = $m( $requested_key ) === 'yes';
            ?>
                <tr>
                    <th scope="row"><?php echo esc_html( $addon['label'] ); ?></th>
                    <td>
                        <label style="margin-right: 20px;">
                            <input type="checkbox" name="meta[<?php echo esc_attr( $meta_key ); ?>]" value="yes"
                                <?php checked( $is_purchased ); ?>>
                            Purchased
                        </label>
                        <?php if ( $is_requested && ! $is_purchased ) : ?>
                            <span class="wssp-admin-badge wssp-admin-badge--requested">
                                Sponsor requested this add-on
                            </span>
                        <?php endif; ?>
                        <?php if ( ! empty( $addon['cutoff'] ) ) : ?>
                            <p class="description">Cutoff: <?php echo esc_html( date( 'M j, Y', strtotime( $addon['cutoff'] ) ) ); ?></p>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
        </table>

        <!-- ═══ Backplate ═══ -->
        <h3>Backplate Template</h3>
        <p class="description">Upload the backplate template for the sponsor to review and approve.</p>

        <table class="form-table">
            <tr>
                <th scope="row">Backplate File</th>
                <td>
                    <?php
                    $backplate_url = $m( 'backplate_template_url' );
                    if ( $backplate_url ) :
                    ?>
                        <p>
                            <a href="<?php echo esc_url( $backplate_url ); ?>" target="_blank" class="button button-small">
                                View Current Backplate
                            </a>
                        </p>
                    <?php endif; ?>
                    <input type="url" name="meta[backplate_template_url]" class="large-text"
                           value="<?php echo esc_attr( $backplate_url ); ?>"
                           placeholder="URL to backplate template file">
                    <p class="description">Enter the URL of the backplate template. The sponsor will review and approve this in the portal.</p>
                </td>
            </tr>
        </table>

        <p class="submit">
            <button type="submit" class="button button-primary">Save Session Details</button>
        </p>
    </form>
</div>