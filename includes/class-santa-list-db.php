<?php
defined( 'ABSPATH' ) || exit;

class Santa_List_DB {

    const TABLE = 'santa_list_entries';

    public static function install() {
        global $wpdb;
        $table      = $wpdb->prefix . self::TABLE;
        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE IF NOT EXISTS $table (
            id           BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            first_name   VARCHAR(50)         NOT NULL,
            last_initial CHAR(1)             NOT NULL,
            list_type    ENUM('nice','naughty') NOT NULL DEFAULT 'nice',
            status       ENUM('approved','rejected','pending') NOT NULL DEFAULT 'approved',
            ip_address   VARCHAR(45)         NOT NULL DEFAULT '',
            latitude     DECIMAL(10,7)       DEFAULT NULL,
            longitude    DECIMAL(10,7)       DEFAULT NULL,
            entry_code   VARCHAR(50)         DEFAULT NULL,
            submitted_at DATETIME            NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY list_type (list_type),
            KEY status (status),
            KEY submitted_at (submitted_at)
        ) $charset_collate;";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta( $sql );

        add_option( 'santa_list_db_version', SANTA_LIST_VERSION );
    }

    public static function deactivate() {
        // Keep data on deactivation; only remove on uninstall
    }

    public static function uninstall() {
        global $wpdb;
        $wpdb->query( "DROP TABLE IF EXISTS " . $wpdb->prefix . self::TABLE );
        delete_option( 'santa_list_db_version' );
        delete_option( 'santa_list_settings' );
    }

    /**
     * Insert a new entry. Returns entry ID or WP_Error.
     */
    public static function insert( $data ) {
        global $wpdb;
        $table = $wpdb->prefix . self::TABLE;

        $inserted = $wpdb->insert( $table, array(
            'first_name'   => sanitize_text_field( $data['first_name'] ),
            'last_initial' => strtoupper( substr( sanitize_text_field( $data['last_initial'] ), 0, 1 ) ),
            'list_type'    => in_array( $data['list_type'], array( 'nice', 'naughty' ), true ) ? $data['list_type'] : 'nice',
            'status'       => 'approved',
            'ip_address'   => sanitize_text_field( $data['ip_address'] ?? '' ),
            'latitude'     => isset( $data['latitude'] )  ? floatval( $data['latitude'] )  : null,
            'longitude'    => isset( $data['longitude'] ) ? floatval( $data['longitude'] ) : null,
            'entry_code'   => isset( $data['entry_code'] ) ? sanitize_text_field( $data['entry_code'] ) : null,
        ), array( '%s', '%s', '%s', '%s', '%s', '%f', '%f', '%s' ) );

        if ( false === $inserted ) {
            return new WP_Error( 'db_error', $wpdb->last_error );
        }
        return $wpdb->insert_id;
    }

    /**
     * Check for duplicate: same first_name + last_initial already approved.
     */
    public static function is_duplicate( $first_name, $last_initial ) {
        global $wpdb;
        $table = $wpdb->prefix . self::TABLE;
        $count = $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM $table WHERE first_name = %s AND last_initial = %s AND status = 'approved'",
            sanitize_text_field( $first_name ),
            strtoupper( substr( $last_initial, 0, 1 ) )
        ) );
        return ( (int) $count ) > 0;
    }

    /**
     * Get approved entries for the API, ordered by submitted_at DESC.
     * Returns array of objects.
     */
    public static function get_approved( $limit = 500 ) {
        global $wpdb;
        $table = $wpdb->prefix . self::TABLE;
        return $wpdb->get_results( $wpdb->prepare(
            "SELECT id, first_name, last_initial, list_type, submitted_at
               FROM $table
              WHERE status = 'approved'
              ORDER BY submitted_at DESC
              LIMIT %d",
            absint( $limit )
        ) );
    }

    /**
     * Get all entries for the admin list table.
     */
    public static function get_all( $args = array() ) {
        global $wpdb;
        $table  = $wpdb->prefix . self::TABLE;
        $limit  = isset( $args['limit'] )  ? absint( $args['limit'] )  : 50;
        $offset = isset( $args['offset'] ) ? absint( $args['offset'] ) : 0;
        $status = isset( $args['status'] ) ? sanitize_text_field( $args['status'] ) : '';

        $where = $status ? $wpdb->prepare( "WHERE status = %s", $status ) : '';

        return $wpdb->get_results(
            "SELECT * FROM $table $where ORDER BY submitted_at DESC LIMIT $limit OFFSET $offset"
        );
    }

    public static function get_count( $status = '' ) {
        global $wpdb;
        $table = $wpdb->prefix . self::TABLE;
        $where = $status ? $wpdb->prepare( "WHERE status = %s", $status ) : '';
        return (int) $wpdb->get_var( "SELECT COUNT(*) FROM $table $where" );
    }

    public static function update_status( $id, $status ) {
        global $wpdb;
        return $wpdb->update(
            $wpdb->prefix . self::TABLE,
            array( 'status' => $status ),
            array( 'id' => absint( $id ) ),
            array( '%s' ),
            array( '%d' )
        );
    }

    public static function delete_entry( $id ) {
        global $wpdb;
        return $wpdb->delete(
            $wpdb->prefix . self::TABLE,
            array( 'id' => absint( $id ) ),
            array( '%d' )
        );
    }

    public static function clear_all() {
        global $wpdb;
        return $wpdb->query( "TRUNCATE TABLE " . $wpdb->prefix . self::TABLE );
    }
}
