<?php
/**
 * Plugin Name: Santa's Naughty & Nice List
 * Plugin URI:  https://github.com/baelinc/naughty_nice_list
 * Description: Lets families submit their child's name to Santa's Naughty or Nice List, displayed on LED panels via FPP.
 * Version:     1.0.0
 * Author:      baelinc
 * License:     GPL-2.0+
 * Text Domain: santa-list
 */

defined( 'ABSPATH' ) || exit;

define( 'SANTA_LIST_VERSION', '1.0.0' );
define( 'SANTA_LIST_DIR',     plugin_dir_path( __FILE__ ) );
define( 'SANTA_LIST_URL',     plugin_dir_url( __FILE__ ) );

require_once SANTA_LIST_DIR . 'includes/class-santa-list-db.php';
require_once SANTA_LIST_DIR . 'includes/class-santa-list-filter.php';
require_once SANTA_LIST_DIR . 'includes/class-santa-list-geofence.php';
require_once SANTA_LIST_DIR . 'includes/class-santa-list-api.php';
require_once SANTA_LIST_DIR . 'includes/class-santa-list-shortcodes.php';
require_once SANTA_LIST_DIR . 'includes/class-santa-list-updater.php';
require_once SANTA_LIST_DIR . 'admin/class-santa-list-admin.php';

register_activation_hook( __FILE__, array( 'Santa_List_DB', 'install' ) );
register_deactivation_hook( __FILE__, array( 'Santa_List_DB', 'deactivate' ) );

add_action( 'plugins_loaded', 'santa_list_init' );
if ( ! function_exists( 'santa_list_init' ) ) {
    function santa_list_init() {
        Santa_List_API::init();
        Santa_List_Shortcodes::init();
        Santa_List_Updater::init();   // GitHub update checker
        if ( is_admin() ) {
            Santa_List_Admin::init();
        }
    }
}

/**
 * Register the standalone QR page template so WordPress finds it
 * even though it lives inside the plugin folder rather than the theme.
 */
add_filter( 'theme_page_templates', function( $templates ) {
    $templates['santa-list/public/page-santa-list.php'] = __( "Santa's List — Standalone", 'santa-list' );
    return $templates;
} );

add_filter( 'template_include', function( $template ) {
    if ( is_page() ) {
        $meta = get_post_meta( get_the_ID(), '_wp_page_template', true );
        if ( $meta === 'santa-list/public/page-santa-list.php' ) {
            $candidate = SANTA_LIST_DIR . 'public/page-santa-list.php';
            if ( file_exists( $candidate ) ) {
                return $candidate;
            }
        }
    }
    return $template;
} );
