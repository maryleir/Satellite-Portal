<?php
/**
 * Contacts-for-Logistics → Session Access Sync.
 *
 * Listens for saves of the core Satellite Session Data Formidable form
 * and reconciles the "Contacts for Logistics" repeater field against
 * the wp_wssp_session_users table.
 *
 * Each repeater row carries a name and an email. When the email matches
 * a registered WordPress user, that user is granted portal access to
 * the session. When a row is removed from the repeater on a later save,
 * the sync removes the corresponding link.
 *
 * Scope:
 *   - Only fires for the form whose key matches the `logistics-contacts`
 *     task in the Task Content CMS (currently 'wssp-sat-session-data').
 *   - Only touches rows it created (source = 'contacts_repeater').
 *     Users linked via the admin "Manage Session" screen are never
 *     affected by this sync.
 *   - Unknown emails (no matching WP user) are silently skipped — the
 *     sponsor is responsible for getting their team registered.
 *
 * @package WSSP
 */

defined( 'ABSPATH' ) || exit;

class WSSP_Contacts_Sync {

    /** Source marker written into wp_wssp_session_users.source. */
    const SOURCE = 'contacts_repeater';

    /** Slug of the CMS task that owns this repeater. */
    const TASK_SLUG = 'logistics-contacts';

    /** Portal slug in the Task Content CMS. */
    const PORTAL_SLUG = 'satellite';

    /**
     * Role assigned to auto-linked contacts.
     *
     * Per spec: contacts have the same access as admin-linked users.
     * We use sponsor_collaborator (broad edit access, no team/add-on
     * management — those remain with sponsor_primary set by admin).
     */
    const ROLE = 'sponsor_collaborator';

    /** @var WSSP_Session_Access */
    private $access;

    /** @var WSSP_Formidable */
    private $formidable;

    /** @var WSSP_Audit_Log */
    private $audit;

    /**
     * Cached task definition from the CMS (form_key + field_keys).
     * Lazily loaded on the first save event so we don't hit the DB
     * during plugin bootstrap for every page load.
     *
     * @var array{form_key:string, repeater_key:string, email_key:string}|null
     */
    private $task_config = null;

    /**
     * @param WSSP_Session_Access $access
     * @param WSSP_Formidable     $formidable
     * @param WSSP_Audit_Log      $audit
     */
    public function __construct( WSSP_Session_Access $access, WSSP_Formidable $formidable, WSSP_Audit_Log $audit ) {
        $this->access     = $access;
        $this->formidable = $formidable;
        $this->audit      = $audit;

        $this->init_hooks();
    }

    private function init_hooks() {
        // Priority 37: after link_session_data_entry (30) so the session
        // is linked to the entry, and after audit_log_form_update (35)
        // so our own access-changes are the last audit rows for the save.
        add_action( 'frm_after_create_entry', array( $this, 'sync' ), 37, 2 );
        add_action( 'frm_after_update_entry', array( $this, 'sync' ), 37, 2 );
    }

    /**
     * Reconcile the session's contact-sourced access links against
     * the current state of the repeater on this entry.
     *
     * @param int $entry_id
     * @param int $form_id
     */
    public function sync( $entry_id, $form_id ) {
        $config = $this->get_task_config();
        if ( ! $config ) {
            return; // Task/form/fields not configured or CMS not available.
        }

        // Scope: only run for the form that owns the repeater.
        if ( ! $this->entry_is_target_form( $entry_id, $config['form_key'] ) ) {
            return;
        }

        // Resolve which session this entry belongs to.
        $session = $this->resolve_session_for_entry( $entry_id );
        if ( ! $session ) {
            return;
        }
        $session_id = (int) $session['id'];

        // Desired state: user IDs implied by the current repeater rows.
        $desired_user_ids = $this->collect_user_ids_from_repeater(
            $entry_id,
            $config['repeater_key'],
            $config['email_key']
        );

        // Current state: user IDs we previously linked via this source.
        $current_user_ids = $this->access->get_session_user_ids_by_source(
            $session_id,
            self::SOURCE
        );

        $to_add    = array_diff( $desired_user_ids, $current_user_ids );
        $to_remove = array_diff( $current_user_ids, $desired_user_ids );

        if ( empty( $to_add ) && empty( $to_remove ) ) {
            return;
        }

        $event_type = $session['event_type'] ?? 'satellite';

        foreach ( $to_add as $user_id ) {
            $inserted = $this->access->link_user(
                $session_id,
                $user_id,
                self::ROLE,
                get_current_user_id(),
                self::SOURCE
            );

            if ( $inserted ) {
                $this->audit->log( array(
                    'session_id'  => $session_id,
                    'event_type'  => $event_type,
                    'action'      => 'team_change',
                    'entity_type' => 'team',
                    'entity_id'   => (string) $user_id,
                    'new_value'   => 'Auto-linked via Contacts for Logistics (role: ' . self::ROLE . ')',
                    'meta'        => array(
                        'source'   => self::SOURCE,
                        'entry_id' => $entry_id,
                    ),
                ) );
            }
        }

        foreach ( $to_remove as $user_id ) {
            $deleted = $this->access->unlink_user_by_source(
                $session_id,
                $user_id,
                self::SOURCE
            );

            if ( $deleted ) {
                $this->audit->log( array(
                    'session_id'  => $session_id,
                    'event_type'  => $event_type,
                    'action'      => 'team_change',
                    'entity_type' => 'team',
                    'entity_id'   => (string) $user_id,
                    'old_value'   => 'Auto-unlinked: no longer in Contacts for Logistics',
                    'meta'        => array(
                        'source'   => self::SOURCE,
                        'entry_id' => $entry_id,
                    ),
                ) );
            }
        }
    }

    /* ───────────────────────────────────────────
     * INTERNAL HELPERS
     * ─────────────────────────────────────────── */

    /**
     * Pull the form_key and repeater/email field keys from the
     * `logistics-contacts` task in the Task Content CMS.
     *
     * Cached for the life of the request.
     *
     * @return array{form_key:string, repeater_key:string, email_key:string}|null
     */
    private function get_task_config() {
        if ( null !== $this->task_config ) {
            return $this->task_config ?: null;
        }

        // Sentinel: false means "already tried and failed" — don't retry.
        $this->task_config = false;

        if ( ! function_exists( 'wssp_tc' ) ) {
            return null;
        }

        $task = wssp_tc()->get_task_by_slug( self::PORTAL_SLUG, self::TASK_SLUG );
        if ( ! $task || empty( $task->form_key ) || empty( $task->field_keys ) ) {
            return null;
        }

        $field_keys = (array) $task->field_keys;

        // Convention used in the CMS bundle:
        //   field_keys[0] = the repeater field itself
        //   field_keys[N] = child fields inside the repeater
        // We look for the email child by substring match so this doesn't
        // break if additional child fields (phone, role, etc.) are added.
        $repeater_key = $field_keys[0] ?? '';
        $email_key    = '';
        foreach ( $field_keys as $key ) {
            if ( false !== stripos( $key, 'email' ) ) {
                $email_key = $key;
                break;
            }
        }

        if ( empty( $repeater_key ) || empty( $email_key ) ) {
            return null;
        }

        $this->task_config = array(
            'form_key'     => $task->form_key,
            'repeater_key' => $repeater_key,
            'email_key'    => $email_key,
        );

        return $this->task_config;
    }

    /**
     * Is the given entry part of the target form?
     *
     * @param int    $entry_id
     * @param string $target_form_key
     * @return bool
     */
    private function entry_is_target_form( $entry_id, $target_form_key ) {
        if ( ! class_exists( 'FrmEntry' ) || ! class_exists( 'FrmForm' ) ) {
            return false;
        }

        $entry = FrmEntry::getOne( $entry_id );
        if ( ! $entry || empty( $entry->form_id ) ) {
            return false;
        }

        $form = FrmForm::getOne( $entry->form_id );
        if ( ! $form || empty( $form->form_key ) ) {
            return false;
        }

        return $form->form_key === $target_form_key;
    }

    /**
     * Map the entry's session_key meta to a row in wp_wssp_sessions.
     *
     * @param int $entry_id
     * @return array|null
     */
    private function resolve_session_for_entry( $entry_id ) {
        if ( ! class_exists( 'FrmEntryMeta' ) ) {
            return null;
        }

        $session_key = FrmEntryMeta::get_entry_meta_by_field(
            $entry_id,
            WSSP_Formidable::FIELD_SESSION_KEY,
            true
        );
        if ( empty( $session_key ) ) {
            return null;
        }

        global $wpdb;
        return $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}wssp_sessions WHERE session_key = %s",
            $session_key
        ), ARRAY_A );
    }

    /**
     * Walk the repeater's child entries, pull emails, resolve each to
     * a registered WordPress user, and return the unique user IDs.
     *
     * Unknown emails are silently skipped.
     *
     * @param int    $parent_entry_id
     * @param string $repeater_key   Field key of the repeater section field.
     * @param string $email_key      Field key of the email field inside each row.
     * @return int[] Unique WP user IDs.
     */
    private function collect_user_ids_from_repeater( $parent_entry_id, $repeater_key, $email_key ) {
        if ( ! class_exists( 'FrmField' ) || ! class_exists( 'FrmEntry' ) || ! class_exists( 'FrmEntryMeta' ) ) {
            return array();
        }

        // The repeater field's value on the parent entry is a
        // comma-separated list of child entry IDs. If Formidable ever
        // changes how repeaters store that, the child-items query
        // below (by parent_item_id) is a safer fallback.
        $child_entries = $this->get_repeater_child_entries( $parent_entry_id, $repeater_key );
        if ( empty( $child_entries ) ) {
            return array();
        }

        $email_field_id = FrmField::get_id_by_key( $email_key );
        if ( ! $email_field_id ) {
            return array();
        }

        $user_ids = array();
        foreach ( $child_entries as $child_id ) {
            $email = FrmEntryMeta::get_entry_meta_by_field( $child_id, $email_field_id, true );
            if ( empty( $email ) || ! is_email( $email ) ) {
                continue;
            }

            $user = get_user_by( 'email', $email );
            if ( ! $user ) {
                continue; // Silent skip — sponsor's job to register their team.
            }

            $user_ids[ (int) $user->ID ] = (int) $user->ID;
        }

        return array_values( $user_ids );
    }

    /**
     * Get child entry IDs for a repeater on a given parent entry.
     *
     * Primary strategy: read the repeater field's own meta value
     * (a comma-separated list of child entry IDs, which is how
     * Formidable records the row order). Fallback: query frm_items
     * directly by parent_item_id + form_id.
     *
     * @param int    $parent_entry_id
     * @param string $repeater_key
     * @return int[]
     */
    private function get_repeater_child_entries( $parent_entry_id, $repeater_key ) {
        $repeater_field_id = FrmField::get_id_by_key( $repeater_key );
        if ( ! $repeater_field_id ) {
            return array();
        }

        // Strategy 1: the repeater's own stored value.
        $raw = FrmEntryMeta::get_entry_meta_by_field( $parent_entry_id, $repeater_field_id, true );
        if ( ! empty( $raw ) ) {
            $ids = is_array( $raw ) ? $raw : array_map( 'trim', explode( ',', (string) $raw ) );
            $ids = array_values( array_filter( array_map( 'intval', $ids ) ) );
            if ( ! empty( $ids ) ) {
                return $ids;
            }
        }

        // Strategy 2: child items by parent_item_id.
        // The repeater field's `form_id` points to the form holding
        // the parent entry; child entries live on a separate child
        // form whose ID is stored on the repeater field config. We
        // query broadly by parent_item_id which is sufficient.
        global $wpdb;
        $rows = $wpdb->get_col( $wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}frm_items WHERE parent_item_id = %d ORDER BY id ASC",
            $parent_entry_id
        ) );

        return array_map( 'intval', $rows ?: array() );
    }
}
