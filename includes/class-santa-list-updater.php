<?php
/**
 * Santa's List — GitHub Update Checker
 *
 * Hooks into WordPress's native plugin-update pipeline and checks
 * the GitHub Releases API for a newer version.  No extra plugins needed.
 *
 * How it works:
 *  1. On the standard WP "check for plugin updates" event we query
 *     the GitHub Releases API for the latest release tag.
 *  2. If the tag version is higher than SANTA_LIST_VERSION we inject
 *     an update record into WP's transient so the Plugins page shows
 *     the "Update Available" notice just like a .org plugin.
 *  3. We also filter the "Plugin Information" popup so the changelog
 *     from the GitHub release description is shown.
 *  4. The download URL points to the release ZIP on GitHub so WP can
 *     install it automatically with one click.
 *
 * To publish a new version:
 *  - Bump SANTA_LIST_VERSION in santa-list.php (and the plugin header).
 *  - Create a GitHub Release tagged  v{version}  (e.g. v1.1.0).
 *  - Attach a ZIP of the wordpress/santa-list/ folder as a release asset
 *    named  santa-list.zip  — OR just let WordPress use the auto-generated
 *    source ZIP that GitHub creates for every release.
 */

defined( 'ABSPATH' ) || exit;

class Santa_List_Updater {

    const GITHUB_USER = 'baelinc';
    const GITHUB_REPO = 'naughty_nice_list_wp';

    /** How long to cache the API response (12 hours). */
    const CACHE_TTL = 43200;

    /**
     * Returns the plugin's slug as WordPress sees it, e.g.
     * "santa-list/santa-list.php" or "admin-XYZ/santa-list.php"
     * depending on what folder the host installed it into.
     */
    public static function plugin_basename() {
        return plugin_basename( SANTA_LIST_DIR . 'santa-list.php' );
    }

    public static function init() {
        add_filter( 'pre_set_site_transient_update_plugins', array( __CLASS__, 'check_for_update' ) );
        add_filter( 'plugins_api',                           array( __CLASS__, 'plugin_info' ), 20, 3 );
        add_filter( 'upgrader_package_options',              array( __CLASS__, 'fix_zip_folder' ) );
    }

    // -------------------------------------------------------------------------
    // 1. Inject update record into WP's update transient
    // -------------------------------------------------------------------------

    public static function check_for_update( $transient ) {
        if ( empty( $transient->checked ) ) {
            return $transient;
        }

        $release = self::get_latest_release();
        if ( ! $release ) {
            return $transient;
        }

        $latest     = ltrim( $release['tag_name'], 'v' );
        $plugin_key = self::plugin_basename(); // actual installed path, whatever folder name

        if ( version_compare( $latest, SANTA_LIST_VERSION, '>' ) ) {
            $transient->response[ $plugin_key ] = (object) array(
                'slug'         => 'santa-list',
                'plugin'       => $plugin_key,
                'new_version'  => $latest,
                'url'          => 'https://github.com/' . self::GITHUB_USER . '/' . self::GITHUB_REPO,
                'package'      => self::get_download_url( $release ),
                'icons'        => array(),
                'banners'      => array(),
                'tested'       => '',
                'requires_php' => '',
                'compatibility'=> new stdClass(),
            );
        } else {
            $transient->no_update[ $plugin_key ] = (object) array(
                'slug'        => 'santa-list',
                'plugin'      => $plugin_key,
                'new_version' => SANTA_LIST_VERSION,
                'url'         => 'https://github.com/' . self::GITHUB_USER . '/' . self::GITHUB_REPO,
                'package'     => '',
            );
        }

        return $transient;
    }

    // -------------------------------------------------------------------------
    // 2. Populate the "View version x.x details" info popup
    // -------------------------------------------------------------------------

    public static function plugin_info( $result, $action, $args ) {
        if ( $action !== 'plugin_information' ) {
            return $result;
        }
        // Match on the short slug regardless of the installed folder name
        if ( ! isset( $args->slug ) || $args->slug !== 'santa-list' ) {
            return $result;
        }

        $release = self::get_latest_release();
        if ( ! $release ) {
            return $result;
        }

        $latest = ltrim( $release['tag_name'], 'v' );

        $info                = new stdClass();
        $info->name          = "Santa's Naughty & Nice List";
        $info->slug          = 'santa-list';
        $info->version       = $latest;
        $info->author        = '<a href="https://github.com/' . self::GITHUB_USER . '">' . self::GITHUB_USER . '</a>';
        $info->homepage      = 'https://github.com/' . self::GITHUB_USER . '/' . self::GITHUB_REPO;
        $info->requires      = '5.8';
        $info->tested        = '6.7';
        $info->last_updated  = $release['published_at'] ?? '';
        $info->download_link = self::get_download_url( $release );
        $info->sections      = array(
            'description' => "Santa's Naughty &amp; Nice List — WordPress submission form + FPP LED panel integration.",
            'changelog'   => ! empty( $release['body'] )
                ? '<pre>' . esc_html( $release['body'] ) . '</pre>'
                : '<p>See <a href="' . esc_url( $release['html_url'] ?? '' ) . '">GitHub Release</a>.</p>',
        );

        return $info;
    }

    // -------------------------------------------------------------------------
    // 3. Fix the folder name inside GitHub's auto-generated ZIP
    //    GitHub names the folder  naughty_nice_list-main/wordpress/santa-list/
    //    WP expects  santa-list/   at the root of the ZIP.
    //    We flag it here so the upgrader knows to rename it.
    // -------------------------------------------------------------------------

    public static function fix_zip_folder( $options ) {
        $source = $options['package'] ?? '';
        if ( strpos( $source, self::GITHUB_REPO ) !== false ) {
            $options['clear_destination'] = true;
        }
        return $options;
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Fetch the latest GitHub release (cached for CACHE_TTL seconds).
     *
     * @return array|false  Decoded release object or false on failure.
     */
    private static function get_latest_release() {
        $cache_key = 'santa_list_gh_release';
        $cached    = get_transient( $cache_key );
        if ( false !== $cached ) {
            return $cached;
        }

        $url      = 'https://api.github.com/repos/' . self::GITHUB_USER . '/' . self::GITHUB_REPO . '/releases/latest';
        $response = wp_remote_get( $url, array(
            'timeout'    => 10,
            'user-agent' => 'WordPress/' . get_bloginfo( 'version' ) . '; ' . get_bloginfo( 'url' ),
            'headers'    => array( 'Accept' => 'application/vnd.github+json' ),
        ) );

        if ( is_wp_error( $response ) || wp_remote_retrieve_response_code( $response ) !== 200 ) {
            // Cache a short failure so we don't hammer the API on every page load
            set_transient( $cache_key, false, 300 );
            return false;
        }

        $body    = json_decode( wp_remote_retrieve_body( $response ), true );
        $release = is_array( $body ) && ! empty( $body['tag_name'] ) ? $body : false;

        set_transient( $cache_key, $release, self::CACHE_TTL );
        return $release;
    }

    /**
     * Return the best download URL for a release.
     * Prefers an asset named santa-list.zip; falls back to the zipball.
     *
     * @param  array  $release  GitHub release array.
     * @return string
     */
    private static function get_download_url( $release ) {
        if ( ! empty( $release['assets'] ) ) {
            foreach ( $release['assets'] as $asset ) {
                if ( $asset['name'] === 'santa-list.zip' && ! empty( $asset['browser_download_url'] ) ) {
                    return $asset['browser_download_url'];
                }
            }
        }
        // Fall back to GitHub's auto-generated source ZIP
        return $release['zipball_url'] ?? '';
    }
}
