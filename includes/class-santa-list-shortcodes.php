<?php
defined( 'ABSPATH' ) || exit;

class Santa_List_Shortcodes {

    public static function init() {
        add_shortcode( 'santa_list_form',    array( __CLASS__, 'render_form' ) );
        add_shortcode( 'santa_list_display', array( __CLASS__, 'render_display' ) );
        add_shortcode( 'santa_list_count',   array( __CLASS__, 'render_count' ) );
        add_action( 'wp_enqueue_scripts',    array( __CLASS__, 'enqueue_assets' ) );
    }

    public static function enqueue_assets() {
        global $post;
        if ( ! is_a( $post, 'WP_Post' ) ) return;

        $has_shortcode = has_shortcode( $post->post_content, 'santa_list_form' )
                      || has_shortcode( $post->post_content, 'santa_list_display' );
        if ( ! $has_shortcode ) return;

        wp_enqueue_style(
            'santa-list',
            SANTA_LIST_URL . 'public/css/santa-list.css',
            array(),
            SANTA_LIST_VERSION
        );
        wp_enqueue_script(
            'santa-list',
            SANTA_LIST_URL . 'public/js/santa-list.js',
            array( 'jquery' ),
            SANTA_LIST_VERSION,
            true
        );
        wp_localize_script( 'santa-list', 'santaList', array(
            'ajaxUrl'  => admin_url( 'admin-ajax.php' ),
            'restUrl'  => rest_url( 'santa-list/v1' ),
            'nonce'    => wp_create_nonce( 'santa_list_submit' ),
            'settings' => self::get_public_settings(),
            'i18n'     => array(
                'submitting'      => __( 'Adding to the list...', 'santa-list' ),
                'location_prompt' => __( 'Getting your location...', 'santa-list' ),
                'location_denied' => __( 'Location access was denied. Location is required to submit a name.', 'santa-list' ),
            ),
        ) );
    }

    private static function get_public_settings() {
        $settings  = get_option( 'santa_list_settings', array() );
        $gate_mode = isset( $settings['gate_mode'] ) ? $settings['gate_mode'] : 'code';
        return array(
            'gate_mode'    => $gate_mode,
            'requires_geo' => in_array( $gate_mode, array( 'geo', 'both' ), true ),
            'requires_code'=> in_array( $gate_mode, array( 'code', 'both' ), true ),
        );
    }

    /**
     * [santa_list_form]
     * Attributes:
     *   title="Add Your Child to Santa's List"
     */
    public static function render_form( $atts ) {
        $atts = shortcode_atts( array(
            'title' => __( "Add Your Child to Santa's List", 'santa-list' ),
        ), $atts, 'santa_list_form' );

        $settings  = get_option( 'santa_list_settings', array() );
        $gate_mode = isset( $settings['gate_mode'] ) ? $settings['gate_mode'] : 'code';
        $requires_code = in_array( $gate_mode, array( 'code', 'both' ), true );

        ob_start();
        ?>
        <div class="santa-list-form-wrap" id="santa-list-form-wrap">
            <h2 class="santa-list-form-title"><?php echo esc_html( $atts['title'] ); ?></h2>

            <div class="santa-list-messages" id="santa-list-messages" aria-live="polite"></div>

            <form class="santa-list-form" id="santa-list-form" novalidate>

                <div class="santa-list-field-row">
                    <div class="santa-list-field">
                        <label for="sl_first_name"><?php esc_html_e( "Child's First Name", 'santa-list' ); ?> <span aria-hidden="true">*</span></label>
                        <input type="text" id="sl_first_name" name="first_name"
                               placeholder="<?php esc_attr_e( 'First name', 'santa-list' ); ?>"
                               maxlength="50" autocomplete="off" required />
                    </div>
                    <div class="santa-list-field santa-list-field--narrow">
                        <label for="sl_last_initial"><?php esc_html_e( 'Last Initial', 'santa-list' ); ?> <span aria-hidden="true">*</span></label>
                        <input type="text" id="sl_last_initial" name="last_initial"
                               placeholder="<?php esc_attr_e( 'B', 'santa-list' ); ?>"
                               maxlength="1" autocomplete="off" required />
                    </div>
                </div>

                <div class="santa-list-field">
                    <label><?php esc_html_e( 'List', 'santa-list' ); ?> <span aria-hidden="true">*</span></label>
                    <div class="santa-list-radio-group">
                        <label class="santa-list-radio santa-list-radio--nice">
                            <input type="radio" name="list_type" value="nice" checked />
                            <span>🎄 <?php esc_html_e( 'Nice List', 'santa-list' ); ?></span>
                        </label>
                        <label class="santa-list-radio santa-list-radio--naughty">
                            <input type="radio" name="list_type" value="naughty" />
                            <span>🎅 <?php esc_html_e( 'Naughty List', 'santa-list' ); ?></span>
                        </label>
                    </div>
                </div>

                <?php if ( $requires_code ) : ?>
                <div class="santa-list-field">
                    <label for="sl_entry_code"><?php esc_html_e( 'Event Code', 'santa-list' ); ?> <span aria-hidden="true">*</span></label>
                    <input type="text" id="sl_entry_code" name="entry_code"
                           placeholder="<?php esc_attr_e( 'Enter code from event staff', 'santa-list' ); ?>"
                           maxlength="20" autocomplete="off" />
                </div>
                <?php endif; ?>

                <input type="hidden" id="sl_latitude"  name="latitude"  value="" />
                <input type="hidden" id="sl_longitude" name="longitude" value="" />

                <button type="submit" class="santa-list-submit" id="santa-list-submit">
                    🎅 <?php esc_html_e( "Add to Santa's List", 'santa-list' ); ?>
                </button>

            </form>
        </div>
        <?php
        return ob_get_clean();
    }

    /**
     * [santa_list_display]
     * Shows the current list on the website.
     * Attributes:
     *   show="both|nice|naughty"
     *   limit="50"
     */
    public static function render_display( $atts ) {
        $atts = shortcode_atts( array(
            'show'  => 'both',
            'limit' => 50,
        ), $atts, 'santa_list_display' );

        $limit   = absint( $atts['limit'] );
        $entries = Santa_List_DB::get_approved( $limit );

        $nice    = array_filter( $entries, fn( $e ) => $e->list_type === 'nice' );
        $naughty = array_filter( $entries, fn( $e ) => $e->list_type === 'naughty' );

        ob_start();
        ?>
        <div class="santa-list-display">
            <?php if ( in_array( $atts['show'], array( 'both', 'nice' ), true ) ) : ?>
            <div class="santa-list-display__column santa-list-display__column--nice">
                <h3 class="santa-list-display__heading">🎄 <?php esc_html_e( 'Nice List', 'santa-list' ); ?></h3>
                <ul class="santa-list-display__list">
                    <?php foreach ( $nice as $e ) : ?>
                    <li><?php echo esc_html( $e->first_name . ' ' . $e->last_initial . '.' ); ?></li>
                    <?php endforeach; ?>
                    <?php if ( empty( $nice ) ) : ?>
                    <li class="santa-list-display__empty"><?php esc_html_e( 'No names yet!', 'santa-list' ); ?></li>
                    <?php endif; ?>
                </ul>
            </div>
            <?php endif; ?>

            <?php if ( in_array( $atts['show'], array( 'both', 'naughty' ), true ) ) : ?>
            <div class="santa-list-display__column santa-list-display__column--naughty">
                <h3 class="santa-list-display__heading">🎅 <?php esc_html_e( 'Naughty List', 'santa-list' ); ?></h3>
                <ul class="santa-list-display__list">
                    <?php foreach ( $naughty as $e ) : ?>
                    <li><?php echo esc_html( $e->first_name . ' ' . $e->last_initial . '.' ); ?></li>
                    <?php endforeach; ?>
                    <?php if ( empty( $naughty ) ) : ?>
                    <li class="santa-list-display__empty"><?php esc_html_e( 'No names yet!', 'santa-list' ); ?></li>
                    <?php endif; ?>
                </ul>
            </div>
            <?php endif; ?>
        </div>
        <?php
        return ob_get_clean();
    }

    /**
     * [santa_list_count]
     * Shows a count badge: "42 names on Santa's List!"
     * Attributes: show="both|nice|naughty"
     */
    public static function render_count( $atts ) {
        $atts = shortcode_atts( array( 'show' => 'both' ), $atts, 'santa_list_count' );

        $entries = Santa_List_DB::get_approved( 9999 );
        $nice    = count( array_filter( $entries, fn( $e ) => $e->list_type === 'nice' ) );
        $naughty = count( array_filter( $entries, fn( $e ) => $e->list_type === 'naughty' ) );
        $total   = $nice + $naughty;

        ob_start();
        echo '<span class="santa-list-count">';
        switch ( $atts['show'] ) {
            case 'nice':
                printf( esc_html__( '%d on the Nice List 🎄', 'santa-list' ), $nice );
                break;
            case 'naughty':
                printf( esc_html__( '%d on the Naughty List 🎅', 'santa-list' ), $naughty );
                break;
            default:
                printf( esc_html__( '%d names on Santa\'s List!', 'santa-list' ), $total );
        }
        echo '</span>';
        return ob_get_clean();
    }
}
