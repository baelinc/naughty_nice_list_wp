<?php
defined( 'ABSPATH' ) || exit;

class Santa_List_Geofence {

    /**
     * Check if coordinates are within the configured geofence.
     *
     * @param float $lat  Submitted latitude
     * @param float $lng  Submitted longitude
     * @return bool|WP_Error  true if inside fence, WP_Error if outside or not configured
     */
    public static function check( $lat, $lng ) {
        $settings = get_option( 'santa_list_settings', array() );

        $center_lat = isset( $settings['geo_lat'] )    ? floatval( $settings['geo_lat'] )    : 0;
        $center_lng = isset( $settings['geo_lng'] )    ? floatval( $settings['geo_lng'] )    : 0;
        $radius_ft  = isset( $settings['geo_radius'] ) ? floatval( $settings['geo_radius'] ) : 500;

        if ( ! $center_lat && ! $center_lng ) {
            return new WP_Error( 'geo_not_configured', __( 'Geofence is not configured.', 'santa-list' ) );
        }

        $distance_ft = self::haversine_feet( $center_lat, $center_lng, $lat, $lng );

        if ( $distance_ft > $radius_ft ) {
            return new WP_Error(
                'outside_geofence',
                sprintf(
                    __( 'You must be at the event to submit a name. You appear to be %.0f feet away.', 'santa-list' ),
                    $distance_ft
                )
            );
        }

        return true;
    }

    /**
     * Calculate distance in feet between two lat/lng points using the Haversine formula.
     */
    public static function haversine_feet( $lat1, $lng1, $lat2, $lng2 ) {
        $earth_radius_ft = 20902231; // Earth radius in feet

        $d_lat = deg2rad( $lat2 - $lat1 );
        $d_lng = deg2rad( $lng2 - $lng1 );

        $a = sin( $d_lat / 2 ) * sin( $d_lat / 2 )
           + cos( deg2rad( $lat1 ) ) * cos( deg2rad( $lat2 ) )
           * sin( $d_lng / 2 ) * sin( $d_lng / 2 );

        $c = 2 * atan2( sqrt( $a ), sqrt( 1 - $a ) );

        return $earth_radius_ft * $c;
    }
}
