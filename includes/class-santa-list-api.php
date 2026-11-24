<?php
defined( 'ABSPATH' ) || exit;

class Santa_List_API {

    const NAMESPACE = 'santa-list/v1';

    public static function init() {
        add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
        add_action( 'wp_ajax_nopriv_santa_list_submit', array( __CLASS__, 'handle_submission' ) );
        add_action( 'wp_ajax_santa_list_submit',        array( __CLASS__, 'handle_submission' ) );
    }

    public static function register_routes() {
        // Public: submit a name (uses nonce, not API key)
        register_rest_route( self::NAMESPACE, '/submit', array(
            'methods'             => 'POST',
            'callback'            => array( __CLASS__, 'rest_submit' ),
            'permission_callback' => '__return_true',
            'args'                => array(
                'first_name'   => array( 'required' => true,  'type' => 'string' ),
                'last_initial' => array( 'required' => true,  'type' => 'string' ),
                'list_type'    => array( 'required' => true,  'type' => 'string', 'enum' => array( 'nice', 'naughty' ) ),
                'entry_code'   => array( 'required' => false, 'type' => 'string' ),
                'latitude'     => array( 'required' => false, 'type' => 'number' ),
                'longitude'    => array( 'required' => false, 'type' => 'number' ),
                'nonce'        => array( 'required' => true,  'type' => 'string' ),
            ),
        ) );

        // FPP: get approved list (requires API key header)
        register_rest_route( self::NAMESPACE, '/list', array(
            'methods'             => 'GET',
            'callback'            => array( __CLASS__, 'rest_get_list' ),
            'permission_callback' => array( __CLASS__, 'verify_api_key' ),
        ) );

        // FPP: get plugin settings for FPP (poll interval, display config, etc.)
        register_rest_route( self::NAMESPACE, '/config', array(
            'methods'             => 'GET',
            'callback'            => array( __CLASS__, 'rest_get_config' ),
            'permission_callback' => array( __CLASS__, 'verify_api_key' ),
        ) );
    }

    /**
     * Verify API key from X-Santa-API-Key header or ?api_key= param.
     */
    public static function verify_api_key( WP_REST_Request $request ) {
        $settings = get_option( 'santa_list_settings', array() );
        $stored   = isset( $settings['api_key'] ) ? $settings['api_key'] : '';

        if ( empty( $stored ) ) {
            return new WP_Error( 'no_api_key', 'API key not configured.', array( 'status' => 500 ) );
        }

        $submitted = $request->get_header( 'X-Santa-API-Key' );
        if ( ! $submitted ) {
            $submitted = $request->get_param( 'api_key' );
        }

        if ( ! hash_equals( $stored, (string) $submitted ) ) {
            return new WP_Error( 'invalid_api_key', 'Invalid API key.', array( 'status' => 401 ) );
        }

        return true;
    }

    /**
     * REST: submit a name.
     */
    public static function rest_submit( WP_REST_Request $request ) {
        // Verify nonce — accept both REST and AJAX nonce actions in case of caching
        $nonce = $request->get_param( 'nonce' );
        $valid = wp_verify_nonce( $nonce, 'santa_list_submit' )
              || wp_verify_nonce( $nonce, 'wp_rest' );
        if ( ! $valid ) {
            return new WP_REST_Response( array(
                'success' => false,
                'message' => __( 'Your session has expired. Please refresh the page and try again.', 'santa-list' ),
            ), 403 );
        }

        return self::process_submission( array(
            'first_name'   => $request->get_param( 'first_name' ),
            'last_initial' => $request->get_param( 'last_initial' ),
            'list_type'    => $request->get_param( 'list_type' ),
            'entry_code'   => $request->get_param( 'entry_code' ),
            'latitude'     => $request->get_param( 'latitude' ),
            'longitude'    => $request->get_param( 'longitude' ),
        ) );
    }

    /**
     * AJAX: submit a name (fallback for non-REST).
     */
    public static function handle_submission() {
        check_ajax_referer( 'santa_list_submit', 'nonce' );

        $result = self::process_submission( array(
            'first_name'   => sanitize_text_field( $_POST['first_name'] ?? '' ),
            'last_initial' => sanitize_text_field( $_POST['last_initial'] ?? '' ),
            'list_type'    => sanitize_text_field( $_POST['list_type'] ?? 'nice' ),
            'entry_code'   => sanitize_text_field( $_POST['entry_code'] ?? '' ),
            'latitude'     => isset( $_POST['latitude'] )  ? floatval( $_POST['latitude'] )  : null,
            'longitude'    => isset( $_POST['longitude'] ) ? floatval( $_POST['longitude'] ) : null,
        ) );

        if ( is_wp_error( $result ) ) {
            wp_send_json_error( array( 'message' => $result->get_error_message() ) );
        }
        wp_send_json_success( $result->data );
    }

    /**
     * Core submission logic used by both REST and AJAX.
     */
    private static function process_submission( $data ) {
        $settings = get_option( 'santa_list_settings', array() );
        $gate_mode = isset( $settings['gate_mode'] ) ? $settings['gate_mode'] : 'code'; // 'geo', 'code', 'both'

        $first_name   = sanitize_text_field( $data['first_name'] );
        $last_initial = strtoupper( substr( sanitize_text_field( $data['last_initial'] ), 0, 1 ) );
        $list_type    = in_array( $data['list_type'], array( 'nice', 'naughty' ), true ) ? $data['list_type'] : 'nice';

        // --- Validate name fields ---
        if ( empty( $first_name ) || empty( $last_initial ) ) {
            return new WP_REST_Response( array( 'success' => false, 'message' => __( 'First name and last initial are required.', 'santa-list' ) ), 400 );
        }

        if ( ! preg_match( '/^[A-Za-z]{1,50}$/', $first_name ) ) {
            return new WP_REST_Response( array( 'success' => false, 'message' => __( 'First name may only contain letters.', 'santa-list' ) ), 400 );
        }

        if ( ! preg_match( '/^[A-Za-z]$/', $last_initial ) ) {
            return new WP_REST_Response( array( 'success' => false, 'message' => __( 'Last initial must be a single letter.', 'santa-list' ) ), 400 );
        }

        // --- Profanity filter ---
        $filter_result = Santa_List_Filter::filter_name( $first_name );
        if ( is_wp_error( $filter_result ) ) {
            return new WP_REST_Response( array( 'success' => false, 'message' => $filter_result->get_error_message() ), 400 );
        }

        // --- Duplicate check ---
        if ( Santa_List_DB::is_duplicate( $first_name, $last_initial ) ) {
            return new WP_REST_Response( array( 'success' => false, 'message' => __( 'This name has already been added to the list.', 'santa-list' ) ), 409 );
        }

        // --- Gate: Entry Code ---
        if ( in_array( $gate_mode, array( 'code', 'both' ), true ) ) {
            $stored_code = isset( $settings['entry_code'] ) ? $settings['entry_code'] : '';
            $submitted_code = sanitize_text_field( $data['entry_code'] ?? '' );
            if ( empty( $stored_code ) || ! hash_equals( strtoupper( $stored_code ), strtoupper( $submitted_code ) ) ) {
                return new WP_REST_Response( array( 'success' => false, 'message' => __( 'Invalid entry code. Please check with the event staff.', 'santa-list' ) ), 403 );
            }
        }

        // --- Gate: Geofence ---
        if ( in_array( $gate_mode, array( 'geo', 'both' ), true ) ) {
            $lat = isset( $data['latitude'] )  ? floatval( $data['latitude'] )  : null;
            $lng = isset( $data['longitude'] ) ? floatval( $data['longitude'] ) : null;

            if ( is_null( $lat ) || is_null( $lng ) ) {
                return new WP_REST_Response( array( 'success' => false, 'message' => __( 'Location access is required to submit a name. Please allow location access and try again.', 'santa-list' ) ), 403 );
            }

            $geo_result = Santa_List_Geofence::check( $lat, $lng );
            if ( is_wp_error( $geo_result ) ) {
                return new WP_REST_Response( array( 'success' => false, 'message' => $geo_result->get_error_message() ), 403 );
            }
        }

        // --- Insert ---
        $ip = $_SERVER['HTTP_CF_CONNECTING_IP'] ?? $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? '';
        $id = Santa_List_DB::insert( array(
            'first_name'   => $first_name,
            'last_initial' => $last_initial,
            'list_type'    => $list_type,
            'ip_address'   => sanitize_text_field( $ip ),
            'latitude'     => $data['latitude'] ?? null,
            'longitude'    => $data['longitude'] ?? null,
            'entry_code'   => $data['entry_code'] ?? null,
        ) );

        if ( is_wp_error( $id ) ) {
            return new WP_REST_Response( array( 'success' => false, 'message' => __( 'Could not save your submission. Please try again.', 'santa-list' ) ), 500 );
        }

        $list_label = $list_type === 'nice' ? __( 'Nice', 'santa-list' ) : __( 'Naughty', 'santa-list' );
        return new WP_REST_Response( array(
            'success' => true,
            'message' => sprintf( __( "%s %s. has been added to Santa's %s List! 🎅", 'santa-list' ), $first_name, $last_initial, $list_label ),
        ), 201 );
    }

    /**
     * REST: return approved list for FPP.
     */
    public static function rest_get_list( WP_REST_Request $request ) {
        $settings = get_option( 'santa_list_settings', array() );
        $limit    = isset( $settings['max_names'] ) ? absint( $settings['max_names'] ) : 500;
        $entries  = Santa_List_DB::get_approved( $limit );

        $list = array(
            'nice'    => array(),
            'naughty' => array(),
            'all'     => array(),
        );

        foreach ( $entries as $entry ) {
            $display = $entry->first_name . ' ' . $entry->last_initial . '.';
            $item    = array(
                'id'           => (int) $entry->id,
                'name'         => $display,
                'list_type'    => $entry->list_type,
                'submitted_at' => $entry->submitted_at,
            );
            $list[ $entry->list_type ][] = $item;
            $list['all'][]               = $item;
        }

        return new WP_REST_Response( $list, 200 );
    }

    /**
     * REST: return display config for FPP.
     */
    public static function rest_get_config( WP_REST_Request $request ) {
        $settings = get_option( 'santa_list_settings', array() );
        return new WP_REST_Response( array(
            'title_text'      => isset( $settings['title_text'] )      ? $settings['title_text']      : "Santa's Naughty & Nice List",
            'scroll_direction'=> isset( $settings['scroll_direction'] ) ? $settings['scroll_direction'] : 'up',
            'names_per_screen'=> isset( $settings['names_per_screen'] ) ? (int) $settings['names_per_screen'] : 5,
            'scroll_speed'    => isset( $settings['scroll_speed'] )    ? (int) $settings['scroll_speed']    : 50,
            'show_naughty'    => isset( $settings['show_naughty'] )    ? (bool) $settings['show_naughty']    : true,
            'show_nice'       => isset( $settings['show_nice'] )       ? (bool) $settings['show_nice']       : true,
            'max_names'       => isset( $settings['max_names'] )       ? (int) $settings['max_names']        : 500,
        ), 200 );
    }
}
