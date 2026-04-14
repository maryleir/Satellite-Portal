<?php
/**
 * Session Meta — key-value storage for session data.
 *
 * Stores admin-entered session data (location, time, rehearsal,
 * purchased add-ons, assigned contacts, etc.) in a flexible
 * key-value table. Follows the WordPress meta pattern.
 *
 * Usage:
 *   $meta = new WSSP_Session_Meta();
 *   $meta->update( $session_id, 'location', 'Grand Hall CD' );
 *   $meta->update( $session_id, 'addon_push_notification', 'yes' );
 *   $location = $meta->get( $session_id, 'location' );
 *   $all = $meta->get_all( $session_id );
 *
 * @package WSSP
 */

defined( 'ABSPATH' ) || exit;

class WSSP_Session_Meta {

    /** @var string Table name. */
    private $table;

    public function __construct() {
        global $wpdb;
        $this->table = $wpdb->prefix . 'wssp_session_meta';
    }

    /**
     * Get a single meta value.
     *
     * @param int    $session_id
     * @param string $key
     * @param mixed  $default    Value to return if key doesn't exist.
     * @return mixed  The meta value, or $default if not found.
     */
    public function get( $session_id, $key, $default = null ) {
        global $wpdb;

        $value = $wpdb->get_var( $wpdb->prepare(
            "SELECT meta_value FROM {$this->table} WHERE session_id = %d AND meta_key = %s",
            $session_id, $key
        ) );

        if ( $value === null ) {
            return $default;
        }

        return maybe_unserialize( $value );
    }

    /**
     * Get all meta for a session.
     *
     * @param int $session_id
     * @return array  Keyed by meta_key => meta_value (unserialized).
     */
    public function get_all( $session_id ) {
        global $wpdb;

        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT meta_key, meta_value FROM {$this->table} WHERE session_id = %d",
            $session_id
        ), ARRAY_A );

        $meta = array();
        foreach ( $rows as $row ) {
            $meta[ $row['meta_key'] ] = maybe_unserialize( $row['meta_value'] );
        }

        return $meta;
    }

    /**
     * Get multiple specific keys for a session.
     *
     * @param int   $session_id
     * @param array $keys        Meta keys to retrieve.
     * @return array  Keyed by meta_key => meta_value. Missing keys are omitted.
     */
    public function get_many( $session_id, array $keys ) {
        if ( empty( $keys ) ) {
            return array();
        }

        global $wpdb;

        $placeholders = implode( ',', array_fill( 0, count( $keys ), '%s' ) );
        $args = array_merge( array( $session_id ), $keys );

        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT meta_key, meta_value FROM {$this->table}
             WHERE session_id = %d AND meta_key IN ({$placeholders})",
            ...$args
        ), ARRAY_A );

        $meta = array();
        foreach ( $rows as $row ) {
            $meta[ $row['meta_key'] ] = maybe_unserialize( $row['meta_value'] );
        }

        return $meta;
    }

    /**
     * Set a meta value. Inserts or updates (upsert).
     *
     * @param int    $session_id
     * @param string $key
     * @param mixed  $value       Will be serialized if non-scalar.
     * @return bool
     */
    public function update( $session_id, $key, $value ) {
        global $wpdb;

        $serialized = maybe_serialize( $value );

        $existing = $wpdb->get_var( $wpdb->prepare(
            "SELECT meta_id FROM {$this->table} WHERE session_id = %d AND meta_key = %s",
            $session_id, $key
        ) );

        if ( $existing ) {
            return $wpdb->update(
                $this->table,
                array( 'meta_value' => $serialized ),
                array( 'meta_id' => $existing ),
                array( '%s' ),
                array( '%d' )
            ) !== false;
        }

        return $wpdb->insert(
            $this->table,
            array(
                'session_id' => $session_id,
                'meta_key'   => $key,
                'meta_value' => $serialized,
            ),
            array( '%d', '%s', '%s' )
        ) !== false;
    }

    /**
     * Set multiple meta values at once.
     *
     * @param int   $session_id
     * @param array $data        Keyed by meta_key => value.
     * @return int  Number of keys updated.
     */
    public function update_many( $session_id, array $data ) {
        $count = 0;
        foreach ( $data as $key => $value ) {
            if ( $this->update( $session_id, $key, $value ) ) {
                $count++;
            }
        }
        return $count;
    }

    /**
     * Delete a single meta key.
     *
     * @param int    $session_id
     * @param string $key
     * @return bool
     */
    public function delete( $session_id, $key ) {
        global $wpdb;

        return $wpdb->delete(
            $this->table,
            array(
                'session_id' => $session_id,
                'meta_key'   => $key,
            ),
            array( '%d', '%s' )
        ) !== false;
    }

    /**
     * Delete all meta for a session.
     *
     * @param int $session_id
     * @return bool
     */
    public function delete_all( $session_id ) {
        global $wpdb;

        return $wpdb->delete(
            $this->table,
            array( 'session_id' => $session_id ),
            array( '%d' )
        ) !== false;
    }

    /**
     * Check if a meta key exists (even if value is empty).
     *
     * @param int    $session_id
     * @param string $key
     * @return bool
     */
    public function exists( $session_id, $key ) {
        global $wpdb;

        return (bool) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$this->table} WHERE session_id = %d AND meta_key = %s",
            $session_id, $key
        ) );
    }

    /**
     * Get all purchased add-ons for a session.
     *
     * Convenience method — looks for meta keys starting with 'addon_'
     * where the value is 'yes' or truthy.
     *
     * @param int $session_id
     * @return array  List of add-on slugs (e.g. ['push_notification', 'recording']).
     */
    public function get_purchased_addons( $session_id ) {
        global $wpdb;

        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT meta_key, meta_value FROM {$this->table}
             WHERE session_id = %d AND meta_key LIKE 'addon_%%'",
            $session_id
        ), ARRAY_A );

        $addons = array();
        foreach ( $rows as $row ) {
            if ( in_array( $row['meta_value'], array( 'yes', '1', 'true' ), true ) ) {
                // Strip 'addon_' prefix to get the slug
                $addons[] = substr( $row['meta_key'], 6 );
            }
        }

        return $addons;
    }
}