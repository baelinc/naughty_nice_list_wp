<?php
defined( 'ABSPATH' ) || exit;

class Santa_List_Admin {

    public static function init() {
        add_action( 'admin_menu',            array( __CLASS__, 'register_menus' ) );
        add_action( 'admin_init',            array( __CLASS__, 'register_settings' ) );
        add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
        add_action( 'wp_ajax_santa_list_admin_action', array( __CLASS__, 'handle_admin_action' ) );
    }

    public static function register_menus() {
        add_menu_page(
            __( "Santa's List", 'santa-list' ),
            __( "Santa's List", 'santa-list' ),
            'manage_options',
            'santa-list',
            array( __CLASS__, 'page_entries' ),
            'dashicons-list-view',
            30
        );
        add_submenu_page(
            'santa-list',
            __( 'Entries', 'santa-list' ),
            __( 'Entries', 'santa-list' ),
            'manage_options',
            'santa-list',
            array( __CLASS__, 'page_entries' )
        );
        add_submenu_page(
            'santa-list',
            __( 'Settings', 'santa-list' ),
            __( 'Settings', 'santa-list' ),
            'manage_options',
            'santa-list-settings',
            array( __CLASS__, 'page_settings' )
        );
        add_submenu_page(
            'santa-list',
            __( 'Shortcodes', 'santa-list' ),
            __( 'Shortcodes', 'santa-list' ),
            'manage_options',
            'santa-list-shortcodes',
            array( __CLASS__, 'page_shortcodes' )
        );
    }

    public static function enqueue_assets( $hook ) {
        if ( strpos( $hook, 'santa-list' ) === false ) return;
        wp_enqueue_style( 'santa-list-admin', SANTA_LIST_URL . 'admin/admin.css', array(), SANTA_LIST_VERSION );

        // Leaflet (CDN, only on settings page)
        if ( strpos( $hook, 'santa-list-settings' ) !== false ) {
            wp_enqueue_style(  'leaflet',        'https://unpkg.com/leaflet@1.9.4/dist/leaflet.css',  array(), '1.9.4' );
            wp_enqueue_script( 'leaflet',        'https://unpkg.com/leaflet@1.9.4/dist/leaflet.js',   array(), '1.9.4', true );
        }

        wp_enqueue_script( 'santa-list-admin', SANTA_LIST_URL . 'admin/admin.js', array( 'jquery' ), SANTA_LIST_VERSION, true );
        $settings = get_option( 'santa_list_settings', array() );
        wp_localize_script( 'santa-list-admin', 'santaListAdmin', array(
            'nonce'      => wp_create_nonce( 'santa_list_admin' ),
            'ajaxUrl'    => admin_url( 'admin-ajax.php' ),
            'savedLat'   => isset( $settings['geo_lat'] )    ? floatval( $settings['geo_lat'] )  : '',
            'savedLng'   => isset( $settings['geo_lng'] )    ? floatval( $settings['geo_lng'] )  : '',
            'savedRadius'=> isset( $settings['geo_radius'] ) ? intval( $settings['geo_radius'] ) : 500,
        ) );
    }

    public static function register_settings() {
        register_setting( 'santa_list_settings_group', 'santa_list_settings', array(
            'sanitize_callback' => array( __CLASS__, 'sanitize_settings' ),
        ) );
    }

    public static function sanitize_settings( $input ) {
        $output = array();

        // Gate mode
        $output['gate_mode'] = in_array( $input['gate_mode'] ?? '', array( 'geo', 'code', 'both' ), true )
            ? $input['gate_mode'] : 'code';

        // Entry code
        $output['entry_code'] = strtoupper( sanitize_text_field( $input['entry_code'] ?? '' ) );

        // Geofence — sanitize_text_field first so commas/spaces are stripped safely
        $raw_lat = sanitize_text_field( $input['geo_lat'] ?? '' );
        $raw_lng = sanitize_text_field( $input['geo_lng'] ?? '' );
        // Accept "30.27, -93.37" pasted into the lat field
        if ( strpos( $raw_lat, ',' ) !== false ) {
            $parts   = array_map( 'trim', explode( ',', $raw_lat, 2 ) );
            $raw_lat = $parts[0];
            $raw_lng = $parts[1];
        }
        $output['geo_lat']    = $raw_lat !== '' ? floatval( $raw_lat ) : 0;
        $output['geo_lng']    = $raw_lng !== '' ? floatval( $raw_lng ) : 0;
        $output['geo_radius'] = isset( $input['geo_radius'] ) ? absint( $input['geo_radius'] )   : 500;

        // Display settings
        $output['title_text']       = sanitize_text_field( $input['title_text']       ?? "Santa's Naughty & Nice List" );
        $output['scroll_direction'] = in_array( $input['scroll_direction'] ?? '', array( 'up', 'down', 'static' ), true )
            ? $input['scroll_direction'] : 'up';
        $output['names_per_screen'] = absint( $input['names_per_screen'] ?? 5 );
        $output['scroll_speed']     = absint( $input['scroll_speed']     ?? 50 );
        $output['show_naughty']     = ! empty( $input['show_naughty'] );
        $output['show_nice']        = ! empty( $input['show_nice'] );
        $output['max_names']        = absint( $input['max_names']        ?? 500 );

        // API key — only regenerate if requested
        $existing = get_option( 'santa_list_settings', array() );
        if ( ! empty( $input['regenerate_api_key'] ) ) {
            $output['api_key'] = wp_generate_password( 32, false );
        } else {
            $output['api_key'] = isset( $existing['api_key'] ) ? $existing['api_key'] : wp_generate_password( 32, false );
        }

        return $output;
    }

    // -------------------------------------------------------------------------
    // Pages
    // -------------------------------------------------------------------------

    public static function page_entries() {
        // Handle bulk actions
        if ( isset( $_POST['bulk_action'] ) && check_admin_referer( 'santa_list_bulk', 'santa_list_nonce' ) ) {
            self::process_bulk_action();
        }

        $status   = sanitize_text_field( $_GET['status'] ?? '' );
        $per_page = 50;
        $paged    = max( 1, absint( $_GET['paged'] ?? 1 ) );
        $offset   = ( $paged - 1 ) * $per_page;

        $entries = Santa_List_DB::get_all( array( 'limit' => $per_page, 'offset' => $offset, 'status' => $status ) );
        $total   = Santa_List_DB::get_count( $status );
        $pages   = ceil( $total / $per_page );
        ?>
        <div class="wrap santa-list-admin">
            <h1 class="wp-heading-inline"><?php esc_html_e( "Santa's List — Entries", 'santa-list' ); ?></h1>
            <hr class="wp-header-end" />

            <ul class="subsubsub">
                <li><a href="<?php echo esc_url( admin_url( 'admin.php?page=santa-list' ) ); ?>" <?php if ( ! $status ) echo 'class="current"'; ?>><?php esc_html_e( 'All', 'santa-list' ); ?> <span class="count">(<?php echo Santa_List_DB::get_count(); ?>)</span></a> |</li>
                <li><a href="<?php echo esc_url( admin_url( 'admin.php?page=santa-list&status=approved' ) ); ?>" <?php if ( $status === 'approved' ) echo 'class="current"'; ?>><?php esc_html_e( 'Approved', 'santa-list' ); ?> <span class="count">(<?php echo Santa_List_DB::get_count( 'approved' ); ?>)</span></a> |</li>
                <li><a href="<?php echo esc_url( admin_url( 'admin.php?page=santa-list&status=rejected' ) ); ?>" <?php if ( $status === 'rejected' ) echo 'class="current"'; ?>><?php esc_html_e( 'Rejected', 'santa-list' ); ?> <span class="count">(<?php echo Santa_List_DB::get_count( 'rejected' ); ?>)</span></a></li>
            </ul>

            <form method="post">
                <?php wp_nonce_field( 'santa_list_bulk', 'santa_list_nonce' ); ?>
                <div class="tablenav top">
                    <div class="alignleft actions bulkactions">
                        <select name="bulk_action">
                            <option value=""><?php esc_html_e( 'Bulk Actions', 'santa-list' ); ?></option>
                            <option value="approve"><?php esc_html_e( 'Approve', 'santa-list' ); ?></option>
                            <option value="reject"><?php esc_html_e( 'Reject', 'santa-list' ); ?></option>
                            <option value="delete"><?php esc_html_e( 'Delete', 'santa-list' ); ?></option>
                        </select>
                        <input type="submit" class="button action" value="<?php esc_attr_e( 'Apply', 'santa-list' ); ?>" />
                        <a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin.php?page=santa-list&bulk_action=clear_all' ), 'santa_list_clear' ) ); ?>"
                           class="button button-secondary"
                           onclick="return confirm('<?php esc_attr_e( 'Clear ALL entries? This cannot be undone.', 'santa-list' ); ?>')">
                            <?php esc_html_e( 'Clear All Entries', 'santa-list' ); ?>
                        </a>
                    </div>
                </div>

                <table class="wp-list-table widefat fixed striped">
                    <thead>
                        <tr>
                            <td class="manage-column column-cb check-column"><input type="checkbox" /></td>
                            <th><?php esc_html_e( 'Name', 'santa-list' ); ?></th>
                            <th><?php esc_html_e( 'List', 'santa-list' ); ?></th>
                            <th><?php esc_html_e( 'Status', 'santa-list' ); ?></th>
                            <th><?php esc_html_e( 'Submitted', 'santa-list' ); ?></th>
                            <th><?php esc_html_e( 'Actions', 'santa-list' ); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ( $entries as $entry ) : ?>
                        <tr>
                            <th class="check-column"><input type="checkbox" name="entry_ids[]" value="<?php echo esc_attr( $entry->id ); ?>" /></th>
                            <td><strong><?php echo esc_html( $entry->first_name . ' ' . $entry->last_initial . '.' ); ?></strong></td>
                            <td><?php echo $entry->list_type === 'nice' ? '🎄 Nice' : '🎅 Naughty'; ?></td>
                            <td><span class="santa-status santa-status--<?php echo esc_attr( $entry->status ); ?>"><?php echo esc_html( ucfirst( $entry->status ) ); ?></span></td>
                            <td><?php echo esc_html( get_date_from_gmt( $entry->submitted_at, get_option( 'date_format' ) . ' ' . get_option( 'time_format' ) ) ); ?></td>
                            <td class="santa-actions">
                                <?php if ( $entry->status !== 'approved' ) : ?>
                                <a href="<?php echo esc_url( wp_nonce_url( admin_url( "admin.php?page=santa-list&action=approve&id={$entry->id}" ), 'santa_list_action' ) ); ?>" class="button button-small"><?php esc_html_e( 'Approve', 'santa-list' ); ?></a>
                                <?php endif; ?>
                                <?php if ( $entry->status !== 'rejected' ) : ?>
                                <a href="<?php echo esc_url( wp_nonce_url( admin_url( "admin.php?page=santa-list&action=reject&id={$entry->id}" ), 'santa_list_action' ) ); ?>" class="button button-small"><?php esc_html_e( 'Reject', 'santa-list' ); ?></a>
                                <?php endif; ?>
                                <a href="<?php echo esc_url( wp_nonce_url( admin_url( "admin.php?page=santa-list&action=delete&id={$entry->id}" ), 'santa_list_action' ) ); ?>" class="button button-small button-link-delete" onclick="return confirm('Delete this entry?')"><?php esc_html_e( 'Delete', 'santa-list' ); ?></a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if ( empty( $entries ) ) : ?>
                        <tr><td colspan="6"><?php esc_html_e( 'No entries yet.', 'santa-list' ); ?></td></tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </form>
        </div>
        <?php
    }

    private static function process_bulk_action() {
        $action = sanitize_text_field( $_POST['bulk_action'] );
        $ids    = array_map( 'absint', (array) ( $_POST['entry_ids'] ?? array() ) );

        if ( empty( $action ) ) return;

        if ( $action === 'clear_all' ) {
            Santa_List_DB::clear_all();
            return;
        }

        foreach ( $ids as $id ) {
            switch ( $action ) {
                case 'approve': Santa_List_DB::update_status( $id, 'approved' ); break;
                case 'reject':  Santa_List_DB::update_status( $id, 'rejected' ); break;
                case 'delete':  Santa_List_DB::delete_entry( $id ); break;
            }
        }
    }

    public static function page_settings() {
        $settings = get_option( 'santa_list_settings', array() );
        ?>
        <div class="wrap santa-list-admin">
            <h1><?php esc_html_e( "Santa's List — Settings", 'santa-list' ); ?></h1>

            <form method="post" action="options.php">
                <?php settings_fields( 'santa_list_settings_group' ); ?>

                <h2><?php esc_html_e( 'Entry Gating', 'santa-list' ); ?></h2>
                <table class="form-table">
                    <tr>
                        <th><?php esc_html_e( 'Gate Mode', 'santa-list' ); ?></th>
                        <td>
                            <?php $gate = $settings['gate_mode'] ?? 'code'; ?>
                            <label><input type="radio" name="santa_list_settings[gate_mode]" value="code" <?php checked( $gate, 'code' ); ?> /> <?php esc_html_e( 'Entry Code only', 'santa-list' ); ?></label><br />
                            <label><input type="radio" name="santa_list_settings[gate_mode]" value="geo" <?php checked( $gate, 'geo' ); ?> /> <?php esc_html_e( 'Geofence only', 'santa-list' ); ?></label><br />
                            <label><input type="radio" name="santa_list_settings[gate_mode]" value="both" <?php checked( $gate, 'both' ); ?> /> <?php esc_html_e( 'Both (require code AND location)', 'santa-list' ); ?></label>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="sl_entry_code"><?php esc_html_e( 'Entry Code', 'santa-list' ); ?></label></th>
                        <td>
                            <input type="text" id="sl_entry_code" name="santa_list_settings[entry_code]"
                                   value="<?php echo esc_attr( $settings['entry_code'] ?? '' ); ?>"
                                   class="regular-text" style="text-transform:uppercase" />
                            <p class="description"><?php esc_html_e( 'Give this code to event attendees. Case-insensitive.', 'santa-list' ); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th><?php esc_html_e( 'Geofence Center', 'santa-list' ); ?></th>
                        <td>
                            <?php
                            $geo_lat = $settings['geo_lat'] ?? '';
                            $geo_lng = $settings['geo_lng'] ?? '';
                            ?>
                            <div class="santa-geo-paste-row">
                                <input type="text" id="sl_geo_paste"
                                       placeholder="Paste Google Maps coordinates, e.g. 30.271570, -93.375691"
                                       class="large-text" autocomplete="off" />
                                <button type="button" id="sl_geo_my_location" class="button">
                                    📍 <?php esc_html_e( 'Use My Location', 'santa-list' ); ?>
                                </button>
                            </div>
                            <p class="description" style="margin-bottom:10px;">
                                <?php esc_html_e( 'Paste the coordinates Google Maps gives you (right-click any spot → copy the numbers shown), or click "Use My Location".', 'santa-list' ); ?>
                            </p>
                            <div class="santa-geo-fields">
                                <label><?php esc_html_e( 'Latitude', 'santa-list' ); ?>&nbsp;
                                    <input type="text" id="sl_geo_lat" name="santa_list_settings[geo_lat]"
                                           value="<?php echo esc_attr( $geo_lat ); ?>"
                                           placeholder="e.g. 30.271570" size="18" />
                                </label>
                                &nbsp;&nbsp;
                                <label><?php esc_html_e( 'Longitude', 'santa-list' ); ?>&nbsp;
                                    <input type="text" id="sl_geo_lng" name="santa_list_settings[geo_lng]"
                                           value="<?php echo esc_attr( $geo_lng ); ?>"
                                           placeholder="e.g. -93.375691" size="18" />
                                </label>
                            </div>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="sl_geo_radius"><?php esc_html_e( 'Geofence Radius (feet)', 'santa-list' ); ?></label></th>
                        <td>
                            <input type="number" id="sl_geo_radius" name="santa_list_settings[geo_radius]"
                                   value="<?php echo esc_attr( $settings['geo_radius'] ?? 500 ); ?>" min="50" max="52800" step="50" />
                            <span class="description">&nbsp;feet &nbsp;(<span id="sl_radius_meters">—</span> meters)</span>
                        </td>
                    </tr>
                    <tr id="sl_map_row">
                        <th><?php esc_html_e( 'Map Preview', 'santa-list' ); ?></th>
                        <td>
                            <div id="sl_geofence_map" style="width:100%;max-width:600px;height:340px;border-radius:10px;border:1px solid #ddd;"></div>
                            <p class="description"><?php esc_html_e( 'The circle shows the geofence. Drag the marker to adjust.', 'santa-list' ); ?></p>
                        </td>
                    </tr>
                </table>

                <h2><?php esc_html_e( 'Display Settings (for LED panels)', 'santa-list' ); ?></h2>
                <table class="form-table">
                    <tr>
                        <th><label for="sl_title_text"><?php esc_html_e( 'Title Panel Text', 'santa-list' ); ?></label></th>
                        <td>
                            <input type="text" id="sl_title_text" name="santa_list_settings[title_text]"
                                   value="<?php echo esc_attr( $settings['title_text'] ?? "Santa's Naughty & Nice List" ); ?>"
                                   class="regular-text" />
                            <p class="description"><?php esc_html_e( 'Text shown on the title panel.', 'santa-list' ); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th><?php esc_html_e( 'Scroll Direction', 'santa-list' ); ?></th>
                        <td>
                            <?php $dir = $settings['scroll_direction'] ?? 'up'; ?>
                            <label><input type="radio" name="santa_list_settings[scroll_direction]" value="up" <?php checked( $dir, 'up' ); ?> /> <?php esc_html_e( 'Scroll Up', 'santa-list' ); ?></label>&nbsp;&nbsp;
                            <label><input type="radio" name="santa_list_settings[scroll_direction]" value="down" <?php checked( $dir, 'down' ); ?> /> <?php esc_html_e( 'Scroll Down', 'santa-list' ); ?></label>&nbsp;&nbsp;
                            <label><input type="radio" name="santa_list_settings[scroll_direction]" value="static" <?php checked( $dir, 'static' ); ?> /> <?php esc_html_e( 'Static (no scroll)', 'santa-list' ); ?></label>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="sl_names_per_screen"><?php esc_html_e( 'Names Per Screen', 'santa-list' ); ?></label></th>
                        <td>
                            <input type="number" id="sl_names_per_screen" name="santa_list_settings[names_per_screen]"
                                   value="<?php echo esc_attr( $settings['names_per_screen'] ?? 5 ); ?>" min="1" max="20" />
                        </td>
                    </tr>
                    <tr>
                        <th><label for="sl_scroll_speed"><?php esc_html_e( 'Scroll Speed (ms per step)', 'santa-list' ); ?></label></th>
                        <td>
                            <input type="number" id="sl_scroll_speed" name="santa_list_settings[scroll_speed]"
                                   value="<?php echo esc_attr( $settings['scroll_speed'] ?? 50 ); ?>" min="10" max="500" />
                            <p class="description"><?php esc_html_e( 'Lower = faster. 50ms is a good default.', 'santa-list' ); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th><?php esc_html_e( 'Show Lists', 'santa-list' ); ?></th>
                        <td>
                            <label><input type="checkbox" name="santa_list_settings[show_nice]" value="1" <?php checked( ! isset( $settings['show_nice'] ) || $settings['show_nice'] ); ?> /> <?php esc_html_e( 'Show Nice List', 'santa-list' ); ?></label><br />
                            <label><input type="checkbox" name="santa_list_settings[show_naughty]" value="1" <?php checked( ! isset( $settings['show_naughty'] ) || $settings['show_naughty'] ); ?> /> <?php esc_html_e( 'Show Naughty List', 'santa-list' ); ?></label>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="sl_max_names"><?php esc_html_e( 'Max Names to Display', 'santa-list' ); ?></label></th>
                        <td>
                            <input type="number" id="sl_max_names" name="santa_list_settings[max_names]"
                                   value="<?php echo esc_attr( $settings['max_names'] ?? 500 ); ?>" min="1" max="9999" />
                        </td>
                    </tr>
                </table>

                <h2><?php esc_html_e( 'FPP API Key', 'santa-list' ); ?></h2>
                <table class="form-table">
                    <tr>
                        <th><?php esc_html_e( 'Current API Key', 'santa-list' ); ?></th>
                        <td>
                            <?php $key = $settings['api_key'] ?? ''; ?>
                            <?php if ( $key ) : ?>
                                <code><?php echo esc_html( $key ); ?></code>
                                <p class="description"><?php esc_html_e( 'Enter this in your FPP plugin settings.', 'santa-list' ); ?></p>
                            <?php else : ?>
                                <em><?php esc_html_e( 'No key yet — save settings to generate one.', 'santa-list' ); ?></em>
                            <?php endif; ?>
                            <br />
                            <label><input type="checkbox" name="santa_list_settings[regenerate_api_key]" value="1" />
                            <?php esc_html_e( 'Regenerate API key on save (invalidates current key)', 'santa-list' ); ?></label>
                        </td>
                    </tr>
                </table>

                <?php submit_button(); ?>
            </form>
        </div>
        <?php
    }

    public static function page_shortcodes() {
        ?>
        <div class="wrap santa-list-admin">
            <h1><?php esc_html_e( "Santa's List — Shortcodes", 'santa-list' ); ?></h1>
            <table class="widefat">
                <thead><tr><th><?php esc_html_e( 'Shortcode', 'santa-list' ); ?></th><th><?php esc_html_e( 'Description', 'santa-list' ); ?></th></tr></thead>
                <tbody>
                    <tr><td><code>[santa_list_form]</code></td><td><?php esc_html_e( 'The name submission form for visitors.', 'santa-list' ); ?></td></tr>
                    <tr><td><code>[santa_list_form title="Add your child!"]</code></td><td><?php esc_html_e( 'Form with custom heading.', 'santa-list' ); ?></td></tr>
                    <tr><td><code>[santa_list_display]</code></td><td><?php esc_html_e( 'Shows both Nice and Naughty lists on the page.', 'santa-list' ); ?></td></tr>
                    <tr><td><code>[santa_list_display show="nice"]</code></td><td><?php esc_html_e( 'Shows Nice list only.', 'santa-list' ); ?></td></tr>
                    <tr><td><code>[santa_list_display show="naughty" limit="20"]</code></td><td><?php esc_html_e( 'Shows Naughty list, max 20 names.', 'santa-list' ); ?></td></tr>
                    <tr><td><code>[santa_list_count]</code></td><td><?php esc_html_e( 'Total names on the list (e.g. "42 names on Santa\'s List!").', 'santa-list' ); ?></td></tr>
                    <tr><td><code>[santa_list_count show="nice"]</code></td><td><?php esc_html_e( 'Count of nice list only.', 'santa-list' ); ?></td></tr>
                    <tr><td><code>[santa_list_count show="naughty"]</code></td><td><?php esc_html_e( 'Count of naughty list only.', 'santa-list' ); ?></td></tr>
                </tbody>
            </table>
            <h2><?php esc_html_e( 'FPP API Endpoints', 'santa-list' ); ?></h2>
            <table class="widefat">
                <thead><tr><th><?php esc_html_e( 'Endpoint', 'santa-list' ); ?></th><th><?php esc_html_e( 'Description', 'santa-list' ); ?></th></tr></thead>
                <tbody>
                    <tr><td><code>GET <?php echo esc_html( rest_url( 'santa-list/v1/list' ) ); ?></code></td><td><?php esc_html_e( 'Returns approved names (requires X-Santa-API-Key header).', 'santa-list' ); ?></td></tr>
                    <tr><td><code>GET <?php echo esc_html( rest_url( 'santa-list/v1/config' ) ); ?></code></td><td><?php esc_html_e( 'Returns display config for FPP (requires X-Santa-API-Key header).', 'santa-list' ); ?></td></tr>
                </tbody>
            </table>
        </div>
        <?php
    }
}
