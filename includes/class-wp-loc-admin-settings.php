<?php

if ( ! defined( 'ABSPATH' ) ) exit;

class WP_LOC_Admin_Settings {

    const OPTION_KEY = 'wp_loc_translatable_post_types';

    public function __construct() {
        add_action( 'admin_menu', [ $this, 'add_menu' ], 20 );
        add_action( 'admin_init', [ $this, 'handle_save' ] );
        add_filter( 'wp_loc_translatable_post_types', [ $this, 'filter_post_types' ] );
    }

    public function add_menu(): void {
        add_submenu_page(
            'wp-loc',
            __( 'Settings', 'wp-loc' ),
            __( 'Settings', 'wp-loc' ),
            'manage_options',
            'wp-loc-settings',
            [ $this, 'render_page' ]
        );
    }

    /**
     * Filter translatable post types based on saved settings
     */
    public function filter_post_types( array $post_types ): array {
        $saved = get_option( self::OPTION_KEY );

        if ( $saved !== false && is_array( $saved ) ) {
            return $saved;
        }

        return $post_types;
    }

    /**
     * Check if a post type is translatable
     */
    public static function is_translatable( string $post_type ): bool {
        $translatable = apply_filters( 'wp_loc_translatable_post_types', [ 'post', 'page' ] );
        return in_array( $post_type, $translatable, true );
    }

    public function handle_save(): void {
        if ( ! isset( $_POST['wp_loc_settings_nonce'] ) ) return;
        if ( ! wp_verify_nonce( $_POST['wp_loc_settings_nonce'], 'wp_loc_save_settings' ) ) return;
        if ( ! current_user_can( 'manage_options' ) ) return;

        $selected = isset( $_POST['wp_loc_post_types'] ) ? array_map( 'sanitize_key', (array) $_POST['wp_loc_post_types'] ) : [];

        update_option( self::OPTION_KEY, $selected );

        wp_redirect( add_query_arg( [
            'page'    => 'wp-loc-settings',
            'updated' => 1,
        ], admin_url( 'admin.php' ) ) );
        exit;
    }

    public function render_page(): void {
        $all_post_types = get_post_types( [ 'public' => true ], 'objects' );
        unset( $all_post_types['attachment'] );

        $saved = get_option( self::OPTION_KEY );
        $selected = ( $saved !== false && is_array( $saved ) ) ? $saved : [ 'post', 'page' ];

        ?>
        <div class="wrap">
            <h1><?php esc_html_e( 'Settings', 'wp-loc' ); ?></h1>

            <?php if ( isset( $_GET['updated'] ) ) : ?>
                <div class="notice notice-success is-dismissible">
                    <p><?php esc_html_e( 'Settings saved.', 'wp-loc' ); ?></p>
                </div>
            <?php endif; ?>

            <form method="post">
                <?php wp_nonce_field( 'wp_loc_save_settings', 'wp_loc_settings_nonce' ); ?>

                <table class="form-table">
                    <tr>
                        <th scope="row"><?php esc_html_e( 'Translatable Post Types', 'wp-loc' ); ?></th>
                        <td>
                            <fieldset>
                                <?php foreach ( $all_post_types as $pt ) : ?>
                                    <label class="wp-loc-settings-label">
                                        <input type="checkbox"
                                               name="wp_loc_post_types[]"
                                               value="<?php echo esc_attr( $pt->name ); ?>"
                                               <?php checked( in_array( $pt->name, $selected, true ) ); ?>
                                        />
                                        <?php echo esc_html( $pt->labels->name ); ?>
                                        <code>(<?php echo esc_html( $pt->name ); ?>)</code>
                                    </label>
                                <?php endforeach; ?>
                            </fieldset>
                            <p class="description"><?php esc_html_e( 'Select which post types should support multilingual translations.', 'wp-loc' ); ?></p>
                        </td>
                    </tr>
                </table>

                <?php submit_button( __( 'Save', 'wp-loc' ) ); ?>
            </form>
        </div>
        <?php
    }
}
