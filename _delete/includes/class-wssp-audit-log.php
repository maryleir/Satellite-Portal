<?php
/**
 * Audit log — append-only change tracking.
 *
 * This class only ever INSERTs into the wssp_audit_log table.
 * No UPDATE or DELETE methods exist by design.
 *
 * @package WSSP
 */

defined( 'ABSPATH' ) || exit;

class WSSP_Audit_Log {

    /** @var string Table name. */
    private $table;

    public function __construct() {
        global $wpdb;
        $this->table = $wpdb->prefix . 'wssp_audit_log';
    }

    /**
     * Log an action.
     *
     * @param array $args {
     *     @type int    $session_id  WSSP session ID.
     *     @type string $event_type  Event type slug (satellite, iet, etc.).
     *     @type int    $user_id     Who performed the action (defaults to current user).
     *     @type string $action      Action slug: field_edit, file_upload, status_change, etc.
     *     @type string $entity_type What was changed: session, file, addon, field, team.
     *     @type string $entity_id   ID of the thing changed.
     *     @type string $field_name  Which field changed (optional).
     *     @type string $old_value   Previous value (optional).
     *     @type string $new_value   New value (optional).
     *     @type array  $meta        Additional context (optional, stored as JSON).
     * }
     * @return int|false Insert ID or false.
     */
    public function log( $args ) {
        global $wpdb;

        $data = array(
            'session_id'  => absint( $args['session_id'] ?? 0 ),
            'event_type'  => sanitize_text_field( $args['event_type'] ?? 'satellite' ),
            'user_id'     => absint( $args['user_id'] ?? get_current_user_id() ),
            'action'      => sanitize_text_field( $args['action'] ?? '' ),
            'source'      => sanitize_text_field( $args['source'] ?? 'portal' ),
            'entity_type' => sanitize_text_field( $args['entity_type'] ?? '' ),
            'entity_id'   => sanitize_text_field( $args['entity_id'] ?? '' ),
            'field_name'  => isset( $args['field_name'] ) ? sanitize_text_field( $args['field_name'] ) : null,
            'old_value'   => $args['old_value'] ?? null,
            'new_value'   => $args['new_value'] ?? null,
            'meta'        => isset( $args['meta'] ) ? wp_json_encode( $args['meta'] ) : null,
        );

        $formats = array( '%d', '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' );

        $result = $wpdb->insert( $this->table, $data, $formats );
        return $result ? $wpdb->insert_id : false;
    }

    /**
     * Get log entries for a session.
     *
     * @param int   $session_id WSSP session ID.
     * @param array $args       Optional filters.
     * @return array
     */
    public function get_entries( $session_id, $args = array() ) {
        global $wpdb;

        $where  = array( 'session_id = %d' );
        $values = array( $session_id );

        if ( ! empty( $args['since'] ) ) {
            $where[]  = 'created_at > %s';
            $values[] = $args['since'];
        }

        if ( ! empty( $args['action'] ) ) {
            $where[]  = 'action = %s';
            $values[] = $args['action'];
        }

        if ( ! empty( $args['entity_type'] ) ) {
            $where[]  = 'entity_type = %s';
            $values[] = $args['entity_type'];
        }

        $limit  = absint( $args['limit'] ?? 100 );
        $offset = absint( $args['offset'] ?? 0 );

        $sql = sprintf(
            "SELECT al.*, u.display_name
             FROM {$this->table} al
             LEFT JOIN {$wpdb->users} u ON u.ID = al.user_id
             WHERE %s
             ORDER BY al.created_at DESC
             LIMIT %d OFFSET %d",
            implode( ' AND ', $where ),
            $limit,
            $offset
        );

        return $wpdb->get_results( $wpdb->prepare( $sql, ...$values ), ARRAY_A );
    }

    /**
     * Get entries changed since a given timestamp (for "changes since last review").
     *
     * @param int    $session_id WSSP session ID.
     * @param string $since      Datetime string (UTC).
     * @return array
     */
    public function get_changes_since( $session_id, $since ) {
        return $this->get_entries( $session_id, array( 'since' => $since ) );
    }

    /**
     * Get entries visible to sponsors (filtered: no internal admin notes).
     *
     * @param int $session_id WSSP session ID.
     * @param int $limit      Max entries.
     * @return array
     */
    public function get_sponsor_changelog( $session_id, $limit = 50 ) {
        global $wpdb;

        return $wpdb->get_results( $wpdb->prepare(
            "SELECT action, entity_type, field_name, new_value, created_at
             FROM {$this->table}
             WHERE session_id = %d
               AND action NOT IN ('login', 'admin_note')
             ORDER BY created_at DESC
             LIMIT %d",
            $session_id,
            $limit
        ), ARRAY_A );
    }
}