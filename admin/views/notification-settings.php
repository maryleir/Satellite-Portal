<?php
/**
 * View: Notification Settings
 *
 * Rendered by WSSP_Notification_Settings::render().
 *
 * Expected vars:
 *   $mode            — 'immediate' | 'digest'
 *   $recipients_text — [event_type => newline-separated emails string, 'global' => ...]
 *   $error_to        — single email string
 *   $email_level     — 'off' | 'fatal' | 'error' | 'warning' | 'notice'
 *   $throttle_secs   — int
 *   $event_types     — [slug => label] map
 *   $saved           — bool, show "saved" notice
 *
 * @package WSSP
 */

defined( 'ABSPATH' ) || exit;
?>

<div class="wrap">
    <h1>WSSP Notification Settings</h1>

    <?php if ( $saved ) : ?>
        <div class="notice notice-success is-dismissible"><p>Settings saved.</p></div>
    <?php endif; ?>

    <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
        <input type="hidden" name="action" value="wssp_save_notification_settings">
        <?php wp_nonce_field( 'wssp_save_notification_settings' ); ?>

        <h2 class="title">Form Change Notifications</h2>
        <p class="description">
            Emails sent when a sponsor (or logistics user) edits a session data form.
            Recipients and delivery mode are configured here; the email content is
            driven by the audit log diff, so it always reflects exactly what changed.
        </p>

        <table class="form-table">
            <tr>
                <th scope="row">Delivery mode</th>
                <td>
                    <fieldset>
                        <label>
                            <input type="radio" name="mode"
                                   value="<?php echo esc_attr( WSSP_Notifier::MODE_IMMEDIATE ); ?>"
                                   <?php checked( $mode, WSSP_Notifier::MODE_IMMEDIATE ); ?>>
                            <strong>Immediate</strong> — send one email per save (good for testing).
                        </label><br>
                        <label>
                            <input type="radio" name="mode"
                                   value="<?php echo esc_attr( WSSP_Notifier::MODE_DIGEST ); ?>"
                                   <?php checked( $mode, WSSP_Notifier::MODE_DIGEST ); ?>>
                            <strong>Daily digest</strong> — queue changes and send one roll-up per day at 8am site time.
                        </label>
                    </fieldset>
                </td>
            </tr>

            <tr>
                <th scope="row">
                    Recipients by event type
                </th>
                <td>
                    <p class="description" style="margin-top:0;">
                        One email per line (or comma-separated). The <em>Global fallback</em>
                        list is used for any event type that has no specific list configured.
                    </p>

                    <?php foreach ( $event_types as $slug => $label ) : ?>
                        <p>
                            <label>
                                <strong><?php echo esc_html( $label ); ?></strong>
                                <span style="color:#888;font-size:12px;">(<?php echo esc_html( $slug ); ?>)</span><br>
                                <textarea name="recipients[<?php echo esc_attr( $slug ); ?>]"
                                          rows="3" cols="50" class="regular-text"><?php
                                    echo esc_textarea( $recipients_text[ $slug ] ?? '' );
                                ?></textarea>
                            </label>
                        </p>
                    <?php endforeach; ?>

                    <p>
                        <label>
                            <strong>Global fallback</strong><br>
                            <textarea name="recipients[global]" rows="3" cols="50" class="regular-text"><?php
                                echo esc_textarea( $recipients_text['global'] ?? '' );
                            ?></textarea>
                        </label>
                    </p>
                </td>
            </tr>
        </table>

        <h2 class="title">Error &amp; Warning Notifications</h2>
        <p class="description">
            Captures PHP errors site-wide (including from themes, other plugins,
            and WordPress core) and emails them to the address below. Every
            captured error is also written to the audit log regardless of
            whether it crosses the email threshold.
        </p>

        <table class="form-table">
            <tr>
                <th scope="row"><label for="wssp-error-recipient">Error recipient</label></th>
                <td>
                    <input type="email" id="wssp-error-recipient" name="error_recipient"
                           value="<?php echo esc_attr( $error_to ); ?>"
                           class="regular-text" placeholder="mary@example.com">
                    <p class="description">A single address — this is a developer-facing inbox, not a team list.</p>
                </td>
            </tr>

            <tr>
                <th scope="row"><label for="wssp-email-level">Email threshold</label></th>
                <td>
                    <select id="wssp-email-level" name="email_level">
                        <option value="off"     <?php selected( $email_level, 'off' ); ?>>Off — log to DB only, never email</option>
                        <option value="fatal"   <?php selected( $email_level, 'fatal' ); ?>>Fatal errors only</option>
                        <option value="error"   <?php selected( $email_level, 'error' ); ?>>Errors and fatals (recommended)</option>
                        <option value="warning" <?php selected( $email_level, 'warning' ); ?>>Warnings and above (noisy)</option>
                        <option value="notice"  <?php selected( $email_level, 'notice' ); ?>>Everything (very noisy — debugging only)</option>
                    </select>
                    <p class="description">
                        Third-party plugins often throw notices and deprecated warnings on every page load.
                        Keep this at <strong>Errors and fatals</strong> unless you're actively debugging.
                    </p>
                </td>
            </tr>

            <tr>
                <th scope="row"><label for="wssp-throttle-secs">Duplicate throttle</label></th>
                <td>
                    <input type="number" id="wssp-throttle-secs" name="throttle_secs"
                           value="<?php echo esc_attr( $throttle_secs ); ?>"
                           min="0" max="86400" step="1" class="small-text"> seconds
                    <p class="description">
                        If the same error fingerprint (level + file + line + message snippet) repeats
                        within this window, only the first occurrence is emailed. Default 900 (15 minutes).
                        Set to 0 to disable throttling.
                    </p>
                </td>
            </tr>
        </table>

        <?php submit_button( 'Save Settings' ); ?>
    </form>
</div>
