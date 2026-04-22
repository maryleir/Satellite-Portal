<?php
/**
 * Site-wide PHP error / warning capture with email alerts.
 *
 * Registers a PHP error handler and shutdown handler that:
 *   1. Write every captured error to the wssp_audit_log table
 *      (action = 'php_' . level, source = 'system').
 *   2. Email the configured recipient for errors at or above the
 *      configured threshold, throttled by message fingerprint.
 *
 * Scope is site-wide — this catches errors from themes, plugins,
 * core, anywhere — because the original request was exactly that:
 * surface problems Mary needs to address, wherever they originate.
 *
 * Why not hook into WordPress's own error handler? WP doesn't have
 * one for PHP-level notices/warnings; it only surfaces display errors
 * via WP_DEBUG_DISPLAY. The native set_error_handler is the right
 * tool here.
 *
 * The logger also exposes public methods so plugin code can emit
 * structured log entries directly:
 *     $logger->error( 'Smartsheet push failed', array( 'session_id' => 42 ) );
 *
 * OPTIONS:
 *   - wssp_logger_email_level   'error' | 'warning' | 'notice' | 'off'
 *                                Defaults to 'error' — notices/warnings are
 *                                logged to DB but not emailed, to avoid spam
 *                                from third-party plugin noise.
 *   - wssp_logger_throttle_secs How long to suppress duplicate emails for
 *                                the same fingerprint. Default 900 (15 min).
 *
 * @package WSSP
 */

defined( 'ABSPATH' ) || exit;

class WSSP_Logger {

    const OPT_EMAIL_LEVEL    = 'wssp_logger_email_level';
    const OPT_THROTTLE_SECS  = 'wssp_logger_throttle_secs';

    const DEFAULT_EMAIL_LEVEL   = 'error';
    const DEFAULT_THROTTLE_SECS = 900; // 15 minutes

    /**
     * Numeric priority for each level. Higher = more severe.
     * 'off' is a sentinel used only for the email-threshold option.
     */
    private static $level_priority = array(
        'off'     => 100,
        'fatal'   => 50,
        'error'   => 40,
        'warning' => 30,
        'notice'  => 20,
        'debug'   => 10,
    );

    /** @var WSSP_Audit_Log|null */
    private $audit;

    /** @var WSSP_Notifier|null */
    private $notifier;

    /** @var bool Whether handlers have been registered (once-per-request guard). */
    private $registered = false;

    public function __construct( ?WSSP_Audit_Log $audit = null, ?WSSP_Notifier $notifier = null ) {
        $this->audit    = $audit;
        $this->notifier = $notifier;
    }

    /**
     * Register the PHP error + shutdown handlers.
     *
     * Call this once from the plugin bootstrap, as early as practical
     * (but after plugins_loaded so wp_* functions work in handlers).
     */
    public function register() {
        if ( $this->registered ) {
            return;
        }
        $this->registered = true;

        // Don't replace the existing handler — chain to it so we play
        // nicely with other plugins (e.g. Query Monitor) that install
        // their own error handlers.
        $previous = set_error_handler( array( $this, 'handle_php_error' ) );
        if ( $previous && is_callable( $previous ) ) {
            // Stash the previous handler so we can forward to it.
            $this->previous_handler = $previous;
        }

        register_shutdown_function( array( $this, 'handle_shutdown' ) );
    }

    /** @var callable|null */
    private $previous_handler = null;

    /* ───────────────────────────────────────────
     * PHP ERROR HANDLER
     * ─────────────────────────────────────────── */

    /**
     * PHP error handler.
     *
     * Must return false to let PHP's normal handling continue (so
     * errors still go to the PHP error log as well). Returning true
     * would suppress them entirely, which we don't want.
     */
    public function handle_php_error( $errno, $errstr, $errfile = '', $errline = 0 ) {
        // Respect the @-suppression operator and current error_reporting level.
        if ( ! ( error_reporting() & $errno ) ) {
            return false;
        }

        $level = $this->php_errno_to_level( $errno );

        $this->log( $level, $errstr, array(
            'file'   => $errfile,
            'line'   => $errline,
            'errno'  => $errno,
            'source' => 'php_handler',
        ) );

        // Chain to the previous handler if there was one.
        if ( $this->previous_handler ) {
            return call_user_func( $this->previous_handler, $errno, $errstr, $errfile, $errline );
        }

        return false; // Let PHP's default handling run too.
    }

    /**
     * Shutdown handler — catches fatal errors that set_error_handler can't.
     */
    public function handle_shutdown() {
        $err = error_get_last();
        if ( ! $err ) {
            return;
        }

        // Only act on fatal-class errors here; non-fatals already went
        // through handle_php_error during the request.
        $fatal_types = E_ERROR | E_PARSE | E_CORE_ERROR | E_CORE_WARNING
                     | E_COMPILE_ERROR | E_COMPILE_WARNING | E_USER_ERROR;

        if ( ! ( $err['type'] & $fatal_types ) ) {
            return;
        }

        $this->log( 'fatal', $err['message'], array(
            'file'   => $err['file']  ?? '',
            'line'   => $err['line']  ?? 0,
            'errno'  => $err['type'],
            'source' => 'shutdown',
        ) );
    }

    /* ───────────────────────────────────────────
     * PUBLIC API — structured logging from plugin code
     * ─────────────────────────────────────────── */

    public function error(   $message, $context = array() ) { $this->log( 'error',   $message, $context ); }
    public function warning( $message, $context = array() ) { $this->log( 'warning', $message, $context ); }
    public function notice(  $message, $context = array() ) { $this->log( 'notice',  $message, $context ); }
    public function debug(   $message, $context = array() ) { $this->log( 'debug',   $message, $context ); }

    /**
     * Core log method — writes to audit log and (if above threshold) emails.
     *
     * @param string $level    One of: debug, notice, warning, error, fatal.
     * @param string $message  Human-readable message.
     * @param array  $context  Arbitrary context; 'file' and 'line' are surfaced
     *                         in the email, everything else goes into meta.
     */
    public function log( $level, $message, $context = array() ) {
        $level = strtolower( $level );
        if ( ! isset( self::$level_priority[ $level ] ) ) {
            $level = 'error';
        }

        // 1. Always write to PHP error log as a reliable fallback.
        //    (If the DB is what's broken, we still want a trail.)
        @error_log( sprintf( 'WSSP[%s] %s', strtoupper( $level ), $message ) );

        // 2. Write to our own audit log table (best-effort — swallow
        //    errors so a logger problem can't take the site down).
        if ( $this->audit ) {
            try {
                $this->audit->log( array(
                    'session_id'  => 0,
                    'event_type'  => 'system',
                    'user_id'     => get_current_user_id(),
                    'action'      => 'php_' . $level,
                    'source'      => 'system',
                    'entity_type' => 'error',
                    'entity_id'   => '',
                    'field_name'  => null,
                    'old_value'   => null,
                    'new_value'   => $message,
                    'meta'        => array_merge( $context, array(
                        'url' => $this->current_url(),
                    ) ),
                ) );
            } catch ( \Throwable $e ) {
                // Don't let logging failures cascade.
            }
        }

        // 3. Email, if this level meets the threshold and we're not throttled.
        if ( ! $this->should_email( $level ) ) {
            return;
        }
        if ( $this->is_throttled( $level, $message, $context ) ) {
            return;
        }

        if ( $this->notifier ) {
            $email_context = array(
                'file'    => $context['file']  ?? '',
                'line'    => $context['line']  ?? 0,
                'url'     => $this->current_url(),
                'user_id' => get_current_user_id(),
            );
            $this->notifier->notify_error( $level, $message, $email_context );
        }
    }

    /* ───────────────────────────────────────────
     * THROTTLING + FILTERING
     * ─────────────────────────────────────────── */

    private function should_email( $level ) {
        $threshold = get_option( self::OPT_EMAIL_LEVEL, self::DEFAULT_EMAIL_LEVEL );
        if ( $threshold === 'off' ) {
            return false;
        }
        $level_p     = self::$level_priority[ $level ]     ?? 0;
        $threshold_p = self::$level_priority[ $threshold ] ?? self::$level_priority[ self::DEFAULT_EMAIL_LEVEL ];
        return $level_p >= $threshold_p;
    }

    /**
     * Throttle duplicate emails. Fingerprint = level + file + line + first
     * 80 chars of message so that minor variations in a repeated error
     * (e.g. different numeric IDs) still deduplicate.
     *
     * Uses a transient as the lock. If a matching transient already
     * exists, we skip the email. Otherwise set the transient and proceed.
     */
    private function is_throttled( $level, $message, $context ) {
        $ttl = absint( get_option( self::OPT_THROTTLE_SECS, self::DEFAULT_THROTTLE_SECS ) );
        if ( $ttl <= 0 ) {
            return false;
        }

        $fingerprint = md5( $level . '|' .
                            ( $context['file'] ?? '' ) . '|' .
                            ( $context['line'] ?? 0 ) . '|' .
                            substr( (string) $message, 0, 80 ) );
        $key = 'wssp_err_throttle_' . $fingerprint;

        if ( get_transient( $key ) ) {
            return true;
        }
        set_transient( $key, 1, $ttl );
        return false;
    }

    /* ───────────────────────────────────────────
     * HELPERS
     * ─────────────────────────────────────────── */

    private function php_errno_to_level( $errno ) {
        switch ( $errno ) {
            case E_ERROR:
            case E_CORE_ERROR:
            case E_COMPILE_ERROR:
            case E_USER_ERROR:
            case E_RECOVERABLE_ERROR:
                return 'fatal';

            case E_WARNING:
            case E_CORE_WARNING:
            case E_COMPILE_WARNING:
            case E_USER_WARNING:
                return 'warning';

            case E_PARSE:
                return 'fatal';

            case E_NOTICE:
            case E_USER_NOTICE:
            case E_DEPRECATED:
            case E_USER_DEPRECATED:
                return 'notice';

            default:
                return 'warning';
        }
    }

    private function current_url() {
        if ( defined( 'WP_CLI' ) && WP_CLI ) {
            return '(wp-cli)';
        }
        if ( wp_doing_cron() ) {
            return '(cron)';
        }
        $host = $_SERVER['HTTP_HOST']   ?? '';
        $uri  = $_SERVER['REQUEST_URI'] ?? '';
        if ( ! $host || ! $uri ) {
            return '';
        }
        $scheme = ( ! empty( $_SERVER['HTTPS'] ) && $_SERVER['HTTPS'] !== 'off' ) ? 'https' : 'http';
        return $scheme . '://' . $host . $uri;
    }
}
