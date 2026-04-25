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
            
            <tr>
                <th scope="row">Room Floor Plan (url)</th>
                <td>
                
                  <?php
                    $room_floor_plan_url = $m( 'room_floor_plan_url' );
                    $room_floor_plan_url = str_replace('[siteurl]', '', $room_floor_plan_url);
                    $room_floor_plan_url = site_url($room_floor_plan_url);

                    if ( $room_floor_plan_url ) :
                    ?>
                        <p>
                            <a href="<?php echo esc_url( $room_floor_plan_url ); ?>" target="_blank" class="button button-small">
                                View Current Floor Plan
                            </a>
                        </p>
                    <?php endif; ?>


                    <input type="text" name="meta[room_floor_plan_url]" class="regular-text"
                           value="<?php echo esc_attr( $m( 'room_floor_plan_url' ) ); ?>">
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

        </table>

        <!-- ═══ Add-On Status ═══ -->
        <h3>Add-On Status</h3>
        <p class="description">
            Logistics-entered state for each add-on. Values sync to Smartsheet.
            Sponsors can also request add-ons through the portal — those
            requests flip these states automatically. Audit log preserves
            the history of each change.
        </p>

        <table class="form-table">
            <?php foreach ( $addons_config as $addon_slug => $addon ) :
                $meta_key = 'addon_' . $addon_slug;
                $current  = (string) $m( $meta_key );
                // Normalize any legacy/raw values for the UI. This is
                // cosmetic — the DB is the source of truth. Everything
                // that isn't exactly 'yes' or 'declined' renders as Not set.
                $current_state = ( $current === 'yes' || $current === 'declined' ) ? $current : '';
            ?>
                <tr>
                    <th scope="row"><?php echo esc_html( $addon['label'] ); ?></th>
                    <td>
                        <fieldset class="wssp-addon-radio-group" style="display: flex; gap: 16px; align-items: center;">
                            <label>
                                <input type="radio"
                                       name="meta[<?php echo esc_attr( $meta_key ); ?>]"
                                       value="yes"
                                       <?php checked( $current_state, 'yes' ); ?>>
                                Purchased
                            </label>
                            <label>
                                <input type="radio"
                                       name="meta[<?php echo esc_attr( $meta_key ); ?>]"
                                       value="declined"
                                       <?php checked( $current_state, 'declined' ); ?>>
                                Declined
                            </label>
                            <label>
                                <input type="radio"
                                       name="meta[<?php echo esc_attr( $meta_key ); ?>]"
                                       value=""
                                       <?php checked( $current_state, '' ); ?>>
                                Not set
                            </label>
                        </fieldset>
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
                    $backplate_url = str_replace('[siteurl]', '', $backplate_url);
                    $backplate_url = site_url($backplate_url);

                    if ( $backplate_url ) :
                    ?>
                        <p>
                            <a href="<?php echo esc_url( $backplate_url ); ?>" target="_blank" class="button button-small">
                                View Current Backplate
                            </a>
                        </p>
                    <?php endif; ?>
                    <input type="url" name="meta[backplate_template_url]" class="large-text"
                           value="<?php echo esc_attr( $m( 'backplate_template_url' ) ); ?>"
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