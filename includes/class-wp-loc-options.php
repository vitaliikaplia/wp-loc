<?php

if ( ! defined( 'ABSPATH' ) ) exit;

class WP_LOC_Options {

    /**
     * Registered multilingual option names
     */
    private static $multilingual_options = [];

    public function __construct() {
        // Register built-in multilingual options
        add_action( 'init', [ $this, 'register_defaults' ], 5 );

        // Handle multilingual options actions
        add_action( 'wpml_multilingual_options', [ $this, 'register_option' ] );
        add_action( 'wp_loc_multilingual_options', [ $this, 'register_option' ] );

        // Frontend: load localized option values
        add_filter( 'pre_option', [ $this, 'filter_pre_option' ], 10, 3 );

        // Admin: save localized option values
        add_action( 'updated_option', [ $this, 'save_localized_option' ], 10, 3 );

        // Admin: load localized values on settings pages
        add_action( 'current_screen', [ $this, 'filter_admin_options' ] );
    }

    /**
     * Register default multilingual options
     */
    public function register_defaults(): void {
        $defaults = [ 'blogname', 'blogdescription', 'page_on_front', 'page_for_posts' ];

        $defaults = apply_filters( 'wp_loc_default_multilingual_options', $defaults );

        foreach ( $defaults as $option ) {
            self::$multilingual_options[ $option ] = true;
        }
    }

    /**
     * Register an option as multilingual
     */
    public function register_option( string $option_name ): void {
        self::$multilingual_options[ $option_name ] = true;
    }

    /**
     * Check if an option is registered as multilingual
     */
    public static function is_multilingual( string $option_name ): bool {
        return isset( self::$multilingual_options[ $option_name ] );
    }

    /**
     * Filter pre_option to return localized value on frontend
     */
    public function filter_pre_option( $pre_option, string $option, $default ) {
        // Only on frontend, skip REST and admin
        if ( is_admin() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
            return $pre_option;
        }

        // Already filtered
        if ( $pre_option !== false ) {
            return $pre_option;
        }

        if ( ! self::is_multilingual( $option ) ) {
            return $pre_option;
        }

        $current_lang = wp_loc_get_current_lang();
        $default_lang = WP_LOC_Languages::get_default_language();

        if ( $current_lang === $default_lang ) {
            return $pre_option;
        }

        $localized_key = $option . '_' . $current_lang;

        global $wpdb;
        $value = $wpdb->get_var( $wpdb->prepare(
            "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s LIMIT 1",
            $localized_key
        ) );

        if ( $value !== null ) {
            return maybe_unserialize( $value );
        }

        // Auto-resolve page IDs to their translations
        if ( in_array( $option, [ 'page_on_front', 'page_for_posts' ], true ) ) {
            // Get the default language value
            $default_value = $wpdb->get_var( $wpdb->prepare(
                "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s LIMIT 1",
                $option
            ) );
            if ( $default_value ) {
                $post_type = get_post_type( (int) $default_value );
                if ( $post_type ) {
                    $element_type = WP_LOC_DB::post_element_type( $post_type );
                    $translated_id = WP_LOC::instance()->db->get_element_translation( (int) $default_value, $element_type, $current_lang );
                    if ( $translated_id ) {
                        return $translated_id;
                    }
                }
            }
        }

        return $pre_option;
    }

    /**
     * Save localized option when admin language differs from default
     */
    public function save_localized_option( string $option, $old_value, $value ): void {
        if ( ! is_admin() ) return;
        if ( ! self::is_multilingual( $option ) ) return;

        // Prevent recursion
        static $saving = [];
        if ( isset( $saving[ $option ] ) ) return;

        $admin_lang = wp_loc_get_admin_lang();
        $default_lang = WP_LOC_Languages::get_default_language();

        if ( $admin_lang === $default_lang ) return;

        $localized_key = $option . '_' . $admin_lang;

        $saving[ $option ] = true;
        update_option( $localized_key, $value );

        // Restore original value for default language
        update_option( $option, $old_value );
        unset( $saving[ $option ] );
    }

    /**
     * On settings pages, show localized values for non-default admin language
     */
    public function filter_admin_options( $screen ): void {
        if ( ! $screen ) return;

        $admin_lang = wp_loc_get_admin_lang();
        $default_lang = WP_LOC_Languages::get_default_language();

        if ( $admin_lang === $default_lang ) return;

        // On settings pages — filter all multilingual options
        // On edit screens — filter only page_on_front/page_for_posts (for post status labels)
        $is_settings = in_array( $screen->id, [ 'options-general', 'options-reading' ], true );
        $is_edit = ( $screen->base === 'edit' );

        if ( ! $is_settings && ! $is_edit ) return;

        $options_to_filter = $is_settings
            ? array_keys( self::$multilingual_options )
            : array_intersect( [ 'page_on_front', 'page_for_posts' ], array_keys( self::$multilingual_options ) );

        foreach ( $options_to_filter as $option ) {
            add_filter( "option_{$option}", function ( $value ) use ( $option, $admin_lang ) {
                static $filtering = [];
                if ( isset( $filtering[ $option ] ) ) return $value;
                $filtering[ $option ] = true;

                $localized = get_option( $option . '_' . $admin_lang );

                unset( $filtering[ $option ] );

                if ( $localized !== false && $localized !== '' ) {
                    return $localized;
                }

                // Auto-resolve page IDs to their translations
                if ( in_array( $option, [ 'page_on_front', 'page_for_posts' ], true ) && $value ) {
                    $post_type = get_post_type( $value );
                    if ( $post_type ) {
                        $element_type = WP_LOC_DB::post_element_type( $post_type );
                        $translated_id = WP_LOC::instance()->db->get_element_translation( (int) $value, $element_type, $admin_lang );
                        if ( $translated_id ) {
                            return $translated_id;
                        }
                    }
                }

                return $value;
            } );
        }

        // Disable page selects for non-default languages (auto-resolved via translations)
        if ( $screen->id === 'options-reading' ) {
            add_action( 'admin_enqueue_scripts', function () {
                wp_add_inline_script( 'wp-loc-admin', 'window.wpLocDisablePageSelects = ' . wp_json_encode( __( 'Auto-resolved from default language translation', 'wp-loc' ) ) . ';', 'before' );
            } );
        }
    }
}
