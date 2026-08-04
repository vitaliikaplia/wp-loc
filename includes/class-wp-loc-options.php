<?php

if ( ! defined( 'ABSPATH' ) ) exit;

class WP_LOC_Options {

    /**
     * Registered multilingual option names
     */
    private static $multilingual_options = [];

    private static function get_language_option_suffixes( string $language ): array {
        $compat_code = WP_LOC_DB::to_db_language_code( $language ) ?: $language;

        return array_values( array_unique( array_filter( [ $compat_code, $language ] ) ) );
    }

    private static function get_primary_language_option_suffix( string $language ): string {
        $suffixes = self::get_language_option_suffixes( $language );

        return $suffixes[0] ?? $language;
    }

    private static function get_localized_option_value( string $option, string $language ): array {
        global $wpdb;

        foreach ( self::get_language_option_suffixes( $language ) as $suffix ) {
            $value = $wpdb->get_var( $wpdb->prepare(
                "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s LIMIT 1",
                $option . '_' . $suffix
            ) );

            if ( $value !== null ) {
                return [ true, maybe_unserialize( $value ) ];
            }
        }

        return [ false, null ];
    }

    private static function is_valid_localized_page_option_value( string $option, $value ): bool {
        if ( ! in_array( $option, [ 'page_on_front', 'page_for_posts' ], true ) ) {
            return true;
        }

        return self::is_page_id_option_value( $value );
    }

    private static function is_page_id_option_value( $value ): bool {
        if ( is_array( $value ) || is_object( $value ) || $value === null ) {
            return false;
        }

        $value = trim( (string) $value );

        if ( $value === '' || ! ctype_digit( $value ) ) {
            return false;
        }

        $page_id = (int) $value;

        return $page_id > 0 && get_post_type( $page_id ) === 'page';
    }

    public function __construct() {
        // Register built-in multilingual options
        add_action( 'init', [ $this, 'register_defaults' ], 5 );

        // Handle multilingual options actions
        add_action( 'wpml_multilingual_options', [ $this, 'register_option' ] );
        add_action( 'wp_loc_multilingual_options', [ $this, 'register_option' ] );

        // Admin: route non-default-language option updates to localized rows.
        // pre_update_option runs before update_option compares values, so a
        // translation equal to the default-language value still saves.
        add_filter( 'pre_update_option', [ $this, 'route_localized_option_update' ], 10, 3 );

        // Admin: sync page option translations on default-language saves
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
            $this->register_option( $option );
        }
    }

    /**
     * Register an option as multilingual and hook its pre_option filter
     */
    public function register_option( string $option_name ): void {
        if ( isset( self::$multilingual_options[ $option_name ] ) ) {
            return;
        }

        self::$multilingual_options[ $option_name ] = true;

        add_filter( "pre_option_{$option_name}", function ( $pre_option, $option, $default ) {
            return $this->filter_pre_option( $pre_option, $option, $default );
        }, 10, 3 );
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
        // Only on frontend, skip REST and real admin screens. Frontend AJAX runs through admin-ajax.php.
        if (
            ( is_admin() && ! WP_LOC_Routing::is_frontend_ajax_request() && ! WP_LOC_Routing::has_switched_language() )
            || ( defined( 'REST_REQUEST' ) && REST_REQUEST )
        ) {
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

        if ( in_array( $option, [ 'page_on_front', 'page_for_posts' ], true ) ) {
            $translated_page_option = $this->resolve_page_option_for_language( $option, $current_lang );

            if ( $translated_page_option !== null ) {
                return $translated_page_option;
            }
        }

        if ( $current_lang === $default_lang ) {
            return $pre_option;
        }

        [ $has_localized_value, $localized_value ] = self::get_localized_option_value( $option, $current_lang );

        // An empty translation falls back to the default-language value.
        if ( $has_localized_value && $localized_value !== '' && self::is_valid_localized_page_option_value( $option, $localized_value ) ) {
            return $localized_value;
        }

        return $pre_option;
    }

    private function resolve_page_option_for_language( string $option, string $language ): ?int {
        global $wpdb;

        [ $has_localized_value, $localized_value ] = self::get_localized_option_value( $option, $language );

        if ( $has_localized_value && self::is_valid_localized_page_option_value( $option, $localized_value ) ) {
            return (int) $localized_value;
        }

        $raw_value = $wpdb->get_var( $wpdb->prepare(
            "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s LIMIT 1",
            $option
        ) );
        $raw_page_id = (int) $raw_value;

        if ( $raw_page_id <= 0 || get_post_type( $raw_page_id ) !== 'page' ) {
            return null;
        }

        $element_type = WP_LOC_DB::post_element_type( 'page' );
        $raw_language = WP_LOC::instance()->db->get_element_language( $raw_page_id, $element_type );

        if ( ! $raw_language || $raw_language === $language ) {
            return null;
        }

        $translated_id = WP_LOC::instance()->db->get_element_translation( $raw_page_id, $element_type, $language );

        return $translated_id && get_post_type( $translated_id ) === 'page' ? (int) $translated_id : null;
    }

    /**
     * Route option updates made in a non-default admin language to the
     * localized option row instead of the default-language row.
     *
     * Hooked to pre_update_option because updated_option never fires when the
     * submitted translation equals the stored default-language value, so the
     * save has to happen before update_option's value comparison. Returning
     * $old_value short-circuits the update and leaves the default row untouched.
     */
    public function route_localized_option_update( $value, string $option, $old_value ) {
        $is_page_option = in_array( $option, [ 'page_on_front', 'page_for_posts' ], true );
        if ( ! is_admin() && ! $is_page_option ) return $value;
        if ( ! self::is_multilingual( $option ) ) return $value;

        // Prevent recursion
        static $routing = [];
        if ( isset( $routing[ $option ] ) ) return $value;

        $admin_lang = wp_loc_get_admin_lang();

        if ( $admin_lang === WP_LOC_Languages::get_default_language() ) {
            return $value;
        }

        $routing[ $option ] = true;
        update_option( $option . '_' . self::get_primary_language_option_suffix( $admin_lang ), $value );
        unset( $routing[ $option ] );

        return $old_value;
    }

    /**
     * Sync page option translations when saving in the default admin language.
     * Non-default-language saves are routed by route_localized_option_update().
     */
    public function save_localized_option( string $option, $old_value, $value ): void {
        $is_page_option = in_array( $option, [ 'page_on_front', 'page_for_posts' ], true );
        if ( ! is_admin() && ! $is_page_option ) return;
        if ( ! self::is_multilingual( $option ) ) return;

        if ( wp_loc_get_admin_lang() === WP_LOC_Languages::get_default_language() ) {
            $this->sync_localized_page_option_translations( $option, $value );
        }
    }

    private function sync_localized_page_option_translations( string $option, $value ): void {
        if ( ! in_array( $option, [ 'page_on_front', 'page_for_posts' ], true ) ) {
            return;
        }

        $page_id = (int) $value;
        $default_lang = WP_LOC_Languages::get_default_language();

        foreach ( array_keys( WP_LOC_Languages::get_active_languages() ) as $lang ) {
            $localized_key = $option . '_' . self::get_primary_language_option_suffix( $lang );

            if ( $lang === $default_lang ) {
                foreach ( self::get_language_option_suffixes( $lang ) as $suffix ) {
                    delete_option( $option . '_' . $suffix );
                }
                continue;
            }

            if ( $page_id <= 0 ) {
                foreach ( self::get_language_option_suffixes( $lang ) as $suffix ) {
                    delete_option( $option . '_' . $suffix );
                }
                continue;
            }

            $translated_id = WP_LOC::instance()->db->get_element_translation( $page_id, WP_LOC_DB::post_element_type( 'page' ), $lang );

            if ( $translated_id && get_post_type( $translated_id ) === 'page' ) {
                update_option( $localized_key, $translated_id );
            } else {
                foreach ( self::get_language_option_suffixes( $lang ) as $suffix ) {
                    delete_option( $option . '_' . $suffix );
                }
            }
        }
    }

    /**
     * On settings pages, show localized values for non-default admin language
     */
    public function filter_admin_options( $screen ): void {
        if ( ! $screen ) return;

        $admin_lang = wp_loc_get_admin_lang();
        $default_lang = WP_LOC_Languages::get_default_language();

        if ( $admin_lang === $default_lang ) return;

        // On settings pages — filter all multilingual options.
        // On edit/list screens — filter page ID options for post status labels and core page notices.
        $is_settings = in_array( $screen->id, [ 'options-general', 'options-reading' ], true )
            || $screen->parent_base === 'options-general'
            || str_starts_with( (string) $screen->id, 'settings_page_' );
        $is_edit = ( $screen->base === 'edit' );
        $is_post_editor = ( $screen->base === 'post' );

        if ( ! $is_settings && ! $is_edit && ! $is_post_editor ) return;

        $options_to_filter = $is_post_editor
            ? array_intersect( [ 'page_on_front', 'page_for_posts' ], array_keys( self::$multilingual_options ) )
            : array_keys( self::$multilingual_options );
        $page_id_only_context = $is_edit || $is_post_editor;

        foreach ( $options_to_filter as $option ) {
            add_filter( "option_{$option}", function ( $value ) use ( $option, $admin_lang, $page_id_only_context ) {
                static $filtering = [];
                if ( isset( $filtering[ $option ] ) ) return $value;
                $filtering[ $option ] = true;

                [ $has_localized_value, $localized ] = self::get_localized_option_value( $option, $admin_lang );

                unset( $filtering[ $option ] );

                if ( $has_localized_value && $localized !== '' && self::is_valid_localized_page_option_value( $option, $localized ) ) {
                    if ( ! $page_id_only_context || self::is_page_id_option_value( $localized ) ) {
                        return $localized;
                    }
                }

                // Auto-resolve page IDs to their translations
                if ( self::is_page_id_option_value( $value ) ) {
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
    }
}
