<?php
/**
 * Email notifications for portal events.
 *
 * Owns all outbound admin email. Other classes call public notify_*
 * methods with structured data; this class handles templating,
 * recipient routing, batching (digest mode), and delivery.
 *
 * Two delivery modes:
 *   - immediate  → one email per triggering event (good for testing)
 *   - digest     → events queued in a transient, sent once per day
 *                  via the wssp_send_digest cron hook
 *
 * Mode and recipient lists live in WP options:
 *   - wssp_notification_mode             ('immediate' | 'digest')
 *   - wssp_notification_recipients       array keyed by event_type
 *                                         plus a 'global' fallback key
 *   - wssp_error_notification_recipient  single email for PHP errors
 *                                         (see WSSP_Logger)
 *
 * @package WSSP
 */

defined( 'ABSPATH' ) || exit;

class WSSP_Notifier {

    const OPT_MODE           = 'wssp_notification_mode';
    const OPT_RECIPIENTS     = 'wssp_notification_recipients';
    const OPT_ERROR_RECIPIENT = 'wssp_error_notification_recipient';

    const MODE_IMMEDIATE = 'immediate';
    const MODE_DIGEST    = 'digest';

    const DIGEST_TRANSIENT = 'wssp_pending_digest';
    const DIGEST_CRON_HOOK = 'wssp_send_digest';

    /**
     * How long queued digest items persist in the transient.
     * Set well above the send interval so a missed cron run doesn't
     * drop pending notifications on the floor.
     */
    const DIGEST_TTL = 2 * DAY_IN_SECONDS;
    
    public function __construct() {
        add_action( 'init',                    array( $this, 'maybe_schedule_digest_cron' ) );
        add_action( self::DIGEST_CRON_HOOK,    array( $this, 'send_digest' ) );
        add_action( 'update_option_' . self::OPT_MODE, array( $this, 'on_mode_change' ), 10, 2 );
    }

    /* ───────────────────────────────────────────
     * SCHEDULING
     * ─────────────────────────────────────────── */

    public function maybe_schedule_digest_cron() {
        if ( self::MODE_DIGEST !== $this->get_mode() ) {
            return;
        }
        if ( ! wp_next_scheduled( self::DIGEST_CRON_HOOK ) ) {
            // First run: tomorrow at 8am site time. wp_schedule_event
            // handles the daily recurrence from there.
            $first = strtotime( 'tomorrow 8:00am' );
            wp_schedule_event( $first, 'daily', self::DIGEST_CRON_HOOK );
        }
    }

    public function on_mode_change( $old_value, $new_value ) {
        if ( self::MODE_DIGEST !== $new_value ) {
            // Switched away from digest — clear any scheduled run.
            $ts = wp_next_scheduled( self::DIGEST_CRON_HOOK );
            if ( $ts ) {
                wp_unschedule_event( $ts, self::DIGEST_CRON_HOOK );
            }
        } else {
            // Switched to digest — schedule immediately.
            $this->maybe_schedule_digest_cron();
        }
    }

    /* ───────────────────────────────────────────
     * PUBLIC API — form change notification
     * ─────────────────────────────────────────── */

    /**
     * Notify admins that a form entry was changed.
     *
     * Called from WSSP_Formidable::audit_log_form_update() after the
     * diff loop has built its $changes list.
     *
     * @param array  $session     Session row: id, session_key, event_type,
     *                            session_code, short_name.
     * @param array  $changes     List of ['field_key' => ..., 'field_name' => ...,
     *                            'old' => ..., 'new' => ...].
     * @param int    $user_id     User who made the edit.
     * @param int    $entry_id    Formidable entry ID (for deep link).
     */
public function notify_form_changes( $session, $changes, $user_id, $entry_id ) {
    $event_type = $session['event_type'] ?? 'satellite';
    $recipients = $this->get_recipients_for_event_type( $event_type );

    $payload = array(
        'kind'        => 'form_changes',
        'session'     => $session,
        'event_type'  => $event_type,
        'changes'     => $changes,
        'user_id'     => $user_id,
        'entry_id'    => $entry_id,
        'occurred_at' => current_time( 'mysql' ),
    );

    if ( self::MODE_IMMEDIATE === $this->get_mode() ) {
        $this->send_form_changes_email( $recipients, array( $payload ) );
    } else {
        $this->queue_for_digest( $event_type, $payload );
        $after = get_transient( self::DIGEST_TRANSIENT );
    }
}

    /* ───────────────────────────────────────────
     * PUBLIC API — error/warning notification
     * ─────────────────────────────────────────── */

    /**
     * Notify the error recipient about a PHP error/warning.
     *
     * Called from WSSP_Logger. Always immediate (errors shouldn't
     * wait a day), but deduped/throttled by the logger itself.
     *
     * @param string $level    'error' | 'warning' | 'notice' | 'fatal'.
     * @param string $message  The error message.
     * @param array  $context  ['file' => ..., 'line' => ..., 'url' => ..., 'user_id' => ...].
     */
    public function notify_error( $level, $message, $context = array() ) {
        $to = get_option( self::OPT_ERROR_RECIPIENT, '' );
        if ( empty( $to ) || ! is_email( $to ) ) {
            return;
        }

        $subject = sprintf(
            '[WSSP %s] %s',
            strtoupper( $level ),
            $this->truncate_for_subject( $message )
        );

        $body = $this->render_error_email( $level, $message, $context );

        $this->send( array( $to ), $subject, $body );
    }

    /* ───────────────────────────────────────────
     * DIGEST QUEUEING + SEND
     * ─────────────────────────────────────────── */

    /**
     * Append a payload to the digest queue. Keyed by event_type
     * so the daily email can group by event.
     */
    private function queue_for_digest( $event_type, $payload ) {
        $queue = get_transient( self::DIGEST_TRANSIENT );
        if ( ! is_array( $queue ) ) {
            $queue = array();
        }
        if ( ! isset( $queue[ $event_type ] ) ) {
            $queue[ $event_type ] = array();
        }
        $queue[ $event_type ][] = $payload;
        set_transient( self::DIGEST_TRANSIENT, $queue, self::DIGEST_TTL );
    }

    /**
     * Cron handler: assemble and send the daily digest, then clear the queue.
     *
     * Groups queued items by event_type and sends each group to its
     * configured recipient list. A single send failure for one event_type
     * doesn't drop the queue for the others.
     */
    public function send_digest() {
        $queue = get_transient( self::DIGEST_TRANSIENT );
        if ( empty( $queue ) || ! is_array( $queue ) ) {
            return;
        }

        foreach ( $queue as $event_type => $payloads ) {
            $recipients = $this->get_recipients_for_event_type( $event_type );
            if ( empty( $recipients ) || empty( $payloads ) ) {
                continue;
            }
            $this->send_form_changes_email( $recipients, $payloads, true );
        }

        delete_transient( self::DIGEST_TRANSIENT );
    }

    /* ───────────────────────────────────────────
     * EMAIL RENDERING
     * ─────────────────────────────────────────── */

    /**
     * Build and send the form-changes email.
     *
     * @param array $recipients  Email addresses.
     * @param array $payloads    One or more form_changes payloads.
     * @param bool  $is_digest   True if sending a daily roll-up.
     */
    private function send_form_changes_email( $recipients, $payloads, $is_digest = false ) {
        $count   = count( $payloads );
        $subject = $is_digest
            ? sprintf( '[WSSP] Daily change digest — %d session update%s', $count, $count === 1 ? '' : 's' )
            : $this->build_single_change_subject( $payloads[0] );

        $body = $this->render_form_changes_email( $payloads, $is_digest );

        $this->send( $recipients, $subject, $body );
    }

    private function build_single_change_subject( $payload ) {
        $session    = $payload['session'] ?? array();
        $short_name = $session['short_name'] ?? '';
        $code       = $session['session_code'] ?? '';
        $n_changes  = count( $payload['changes'] ?? array() );

        $label = trim( $code . ( $short_name ? ' — ' . $short_name : '' ) );
        if ( '' === $label ) {
            $label = 'Session ' . ( $session['id'] ?? '?' );
        }

        return sprintf(
            '[WSSP] %s: %d field%s updated',
            $label,
            $n_changes,
            $n_changes === 1 ? '' : 's'
        );
    }

    /**
     * Render the form-changes HTML email body.
     *
     * Inline styles only — no stylesheet support in most email clients.
     * Table per session with Field / Previous / New columns.
     */
    private function render_form_changes_email( $payloads, $is_digest ) {
        ob_start();
        ?>
        <div style="font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',sans-serif;color:#222;max-width:760px;">
            <h2 style="margin:0 0 16px;font-size:18px;color:#111;">
                <?php echo $is_digest ? 'Daily Change Digest' : 'Session Update'; ?>
            </h2>
            <?php if ( $is_digest ) : ?>
                <p style="margin:0 0 16px;color:#555;">
                    The following sessions were edited in the last 24 hours.
                </p>
            <?php endif; ?>

            <?php foreach ( $payloads as $payload ) : ?>
                <?php echo $this->render_form_changes_block( $payload ); ?>
            <?php endforeach; ?>

            <p style="margin-top:24px;padding-top:16px;border-top:1px solid #e5e5e5;color:#888;font-size:12px;">
                You're receiving this because your address is listed in the
                WSSP notification recipients for one or more event types.
                To change recipients, visit
                <a href="<?php echo esc_url( admin_url( 'admin.php?page=wssp-notification-settings' ) ); ?>">
                    Satellite Portal → Notification Settings
                </a>.
            </p>
        </div>
        <?php
        return ob_get_clean();
    }

    /**
     * Render one session's change block inside the email.
     */
    private function render_form_changes_block( $payload ) {
        $session    = $payload['session'] ?? array();
        $changes    = $payload['changes'] ?? array();
        $user_id    = $payload['user_id'] ?? 0;
        $entry_id   = $payload['entry_id'] ?? 0;
        $when       = $payload['occurred_at'] ?? current_time( 'mysql' );
        $event_type = $payload['event_type'] ?? 'satellite';

        $user    = $user_id ? get_userdata( $user_id ) : null;
        $user_label = $user ? ( $user->display_name . ' (' . $user->user_email . ')' ) : 'Unknown user';

        $audit_url = admin_url( 'admin.php?page=wssp-report-audit&session_id=' . intval( $session['id'] ?? 0 ) );
        $entry_url = $entry_id
            ? admin_url( 'admin.php?page=formidable-entries&frm_action=edit&id=' . intval( $entry_id ) )
            : '';

        $short_name   = $session['short_name'] ?? '';
        $session_code = $session['session_code'] ?? '';
        $title = trim( $session_code . ( $short_name ? ' — ' . $short_name : '' ) );
        if ( '' === $title ) {
            $title = 'Session #' . ( $session['id'] ?? '?' );
        }

        ob_start();
        ?>
        <div style="margin-bottom:28px;padding:16px;border:1px solid #e5e5e5;border-radius:4px;background:#fafafa;">
            <h3 style="margin:0 0 6px;font-size:15px;color:#111;">
                <?php echo esc_html( $title ); ?>
                <span style="font-weight:normal;color:#888;font-size:13px;">
                    (<?php echo esc_html( $event_type ); ?>)
                </span>
            </h3>
            <p style="margin:0 0 12px;color:#555;font-size:13px;">
                Edited by <strong><?php echo esc_html( $user_label ); ?></strong>
                on <?php echo esc_html( mysql2date( 'M j, Y \a\t g:i a', $when ) ); ?>
            </p>

            <table cellpadding="8" cellspacing="0" style="width:100%;border-collapse:collapse;font-size:13px;">
                <thead>
                    <tr style="background:#eee;">
                        <th style="text-align:left;border-bottom:1px solid #ccc;">Field</th>
                        <th style="text-align:left;border-bottom:1px solid #ccc;">Previous</th>
                        <th style="text-align:left;border-bottom:1px solid #ccc;">New</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ( $changes as $c ) : ?>
                        <tr>
                            <td style="vertical-align:top;border-bottom:1px solid #eee;">
                                <strong><?php echo esc_html( $c['field_name'] ?? $c['field_key'] ?? '?' ); ?></strong>
                                <?php if ( ! empty( $c['field_key'] ) && ! empty( $c['field_name'] ) && $c['field_key'] !== $c['field_name'] ) : ?>
                                    <br><span style="color:#888;font-size:11px;">
                                        <?php echo esc_html( $c['field_key'] ); ?>
                                    </span>
                                <?php endif; ?>
                            </td>
                            <td style="vertical-align:top;border-bottom:1px solid #eee;color:#a33;">
                                <?php echo $this->format_value_for_display( $c['old'] ?? '' ); ?>
                            </td>
                            <td style="vertical-align:top;border-bottom:1px solid #eee;color:#183;">
                                <?php echo $this->format_value_for_display( $c['new'] ?? '' ); ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>

            <p style="margin:12px 0 0;font-size:13px;">
                <a href="<?php echo esc_url( $audit_url ); ?>" style="color:#2271b1;">View full audit log</a>
                <?php if ( $entry_url ) : ?>
                    &nbsp;·&nbsp;
                    <a href="<?php echo esc_url( $entry_url ); ?>" style="color:#2271b1;">Open entry</a>
                <?php endif; ?>
            </p>
        </div>
        <?php
        return ob_get_clean();
    }

    /**
     * Render the error-notification email body.
     */
    private function render_error_email( $level, $message, $context ) {
        $file  = $context['file']    ?? '';
        $line  = $context['line']    ?? '';
        $url   = $context['url']     ?? '';
        $user  = $context['user_id'] ?? 0;
        $trace = $context['trace']   ?? '';

        ob_start();
        ?>
        <div style="font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',monospace;color:#222;max-width:760px;">
            <h2 style="margin:0 0 16px;font-size:16px;color:#a33;">
                PHP <?php echo esc_html( strtoupper( $level ) ); ?>
            </h2>
            <pre style="background:#fff5f5;border:1px solid #f5c5c5;padding:12px;
                        white-space:pre-wrap;word-break:break-word;font-size:13px;margin:0 0 16px;">
<?php echo esc_html( $message ); ?></pre>

            <table cellpadding="6" cellspacing="0" style="font-size:13px;border-collapse:collapse;">
                <?php if ( $file ) : ?>
                    <tr><td style="color:#888;">File</td><td><code><?php echo esc_html( $file ); ?><?php echo $line ? ':' . intval( $line ) : ''; ?></code></td></tr>
                <?php endif; ?>
                <?php if ( $url ) : ?>
                    <tr><td style="color:#888;">URL</td><td><?php echo esc_html( $url ); ?></td></tr>
                <?php endif; ?>
                <?php if ( $user ) :
                    $u = get_userdata( $user ); ?>
                    <tr><td style="color:#888;">User</td><td><?php echo esc_html( $u ? $u->display_name . ' (#' . $user . ')' : '#' . $user ); ?></td></tr>
                <?php endif; ?>
                <tr><td style="color:#888;">Time</td><td><?php echo esc_html( current_time( 'M j, Y g:i a' ) ); ?></td></tr>
            </table>

            <?php if ( $trace ) : ?>
                <details style="margin-top:16px;">
                    <summary style="cursor:pointer;color:#555;">Stack trace</summary>
                    <pre style="background:#f5f5f5;padding:12px;overflow:auto;font-size:12px;
                                white-space:pre-wrap;word-break:break-word;">
<?php echo esc_html( $trace ); ?></pre>
                </details>
            <?php endif; ?>
        </div>
        <?php
        return ob_get_clean();
    }

    /* ───────────────────────────────────────────
     * HELPERS
     * ─────────────────────────────────────────── */

    /**
     * Render a field value for the email table cell.
     *
     * Handles empty values, JSON-encoded arrays (the audit log serializes
     * them that way), and long strings.
     */
    private function format_value_for_display( $value ) {
        if ( $value === '' || $value === null ) {
            return '<em style="color:#aaa;">(empty)</em>';
        }

        // Audit log stores arrays as JSON — try to pretty-print.
        if ( is_string( $value ) && ( $value[0] === '[' || $value[0] === '{' ) ) {
            $decoded = json_decode( $value, true );
            if ( json_last_error() === JSON_ERROR_NONE && is_array( $decoded ) ) {
                return '<code style="font-size:12px;">' .
                       esc_html( wp_json_encode( $decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) ) .
                       '</code>';
            }
        }

        $str = (string) $value;
        if ( strlen( $str ) > 500 ) {
            $str = substr( $str, 0, 500 ) . '…';
        }
        return nl2br( esc_html( $str ) );
    }

    private function truncate_for_subject( $text ) {
        $text = preg_replace( '/\s+/', ' ', (string) $text );
        if ( mb_strlen( $text ) > 100 ) {
            $text = mb_substr( $text, 0, 100 ) . '…';
        }
        return $text;
    }

    /**
     * Actually send an email. Single wp_mail with all recipients in To.
     */
    private function send( $recipients, $subject, $body ) {
        if ( empty( $recipients ) ) {
            return;
        }
        $recipients = array_filter( array_map( 'trim', (array) $recipients ), 'is_email' );
        if ( empty( $recipients ) ) {
            return;
        }

        $headers = array( 'Content-Type: text/html; charset=UTF-8' );
        wp_mail( $recipients, $subject, $body, $headers );
    }

    /* ───────────────────────────────────────────
     * OPTION ACCESSORS
     * ─────────────────────────────────────────── */

    public function get_mode() {
        $mode = get_option( self::OPT_MODE, self::MODE_IMMEDIATE );
        return in_array( $mode, array( self::MODE_IMMEDIATE, self::MODE_DIGEST ), true )
            ? $mode
            : self::MODE_IMMEDIATE;
    }

    /**
     * Resolve the recipient list for a given event type.
     *
     * Looks up the per-event_type list first; falls back to the 'global'
     * key if no event-specific list is configured.
     *
     * @return array Email addresses.
     */
    public function get_recipients_for_event_type( $event_type ) {
        $all = get_option( self::OPT_RECIPIENTS, array() );
        if ( ! is_array( $all ) ) {
            $all = array();
        }

        $list = $all[ $event_type ] ?? array();
        if ( empty( $list ) && ! empty( $all['global'] ) ) {
            $list = $all['global'];
        }

        return is_array( $list )
            ? array_values( array_filter( array_map( 'trim', $list ), 'is_email' ) )
            : array();
    }
}
