<?php

if ( ! defined( 'ABSPATH' ) ) exit;

class WP_LOC_Options {

    /**
     * Registered multilingual option names
     */
    private static $multilingual_options = [];

    /** While true, option writes go to the rows they name (set while the default language changes). */
    private static $routing_suspended = false;

    private static function get_language_option_suffixes( string $language ): array {
        $compat_code = WP_LOC_DB::to_db_language_code( $language ) ?: $language;

        return array_values( array_unique( array_filter( [ $compat_code, $language ] ) ) );
    }

    private static function get_primary_language_option_suffix( string $language ): string {
        $suffixes = self::get_language_option_suffixes( $language );

        return $suffixes[0] ?? $language;
    }

    private static function get_localized_option_value( string $option, string $language ): array {
        foreach ( self::get_language_option_suffixes( $language ) as $suffix ) {
            [ $exists, $value ] = self::get_unfiltered_option( $option . '_' . $suffix );

            if ( $exists ) {
                return [ true, $value ];
            }
        }

        return [ false, null ];
    }

    /**
     * Read an option the way get_option() does — through the alloptions, options and notoptions
     * caches, which update_option(), add_option() and delete_option() keep current — but without
     * its filters. Localized rows and the raw base value are read from inside the base option's
     * pre_option filter, and the language configuration before the language is known, where a
     * filtered read could loop back into WP-LOC.
     *
     * @return array{0: bool, 1: mixed} Whether the option exists, and its unserialized value.
     */
    public static function get_unfiltered_option( string $option ): array {
        global $wpdb;

        if ( wp_installing() ) {
            $row = $wpdb->get_row( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s LIMIT 1", $option ) );

            return is_object( $row ) ? [ true, maybe_unserialize( $row->option_value ) ] : [ false, null ];
        }

        $alloptions = wp_load_alloptions();
        if ( isset( $alloptions[ $option ] ) ) {
            return [ true, maybe_unserialize( $alloptions[ $option ] ) ];
        }

        $notoptions = wp_cache_get( 'notoptions', 'options' );
        if ( is_array( $notoptions ) && isset( $notoptions[ $option ] ) ) {
            return [ false, null ];
        }

        $value = wp_cache_get( $option, 'options' );
        if ( $value === false ) {
            $row = $wpdb->get_row( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s LIMIT 1", $option ) );

            if ( ! is_object( $row ) ) {
                $notoptions = is_array( $notoptions ) ? $notoptions : [];
                $notoptions[ $option ] = true;
                wp_cache_set( 'notoptions', $notoptions, 'options' );

                return [ false, null ];
            }

            $value = $row->option_value;
            wp_cache_add( $option, $value, 'options' );
        }

        return [ true, maybe_unserialize( $value ) ];
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
     * Called when the default language changes from $from to $to (slugs). A multilingual option keeps
     * the default language's value in its base row and the others' in {option}_{code} rows. Where $to
     * has a value of its own, it becomes the base value and the old base value becomes $from's row;
     * where it has none, $to showed the base value and keeps showing it, so nothing moves.
     * page_on_front / page_for_posts take $to's page the way it was shown (its row, else the
     * translation of the base page).
     *
     * @return string[] Options whose values moved.
     */
    public function move_default_language_values( string $from, string $to ): array {
        $moved = [];
        self::$routing_suspended = true;

        try {
            foreach ( array_keys( self::$multilingual_options ) as $option ) {
                $option = (string) $option;
                [ $has_base, $base_value ] = self::get_unfiltered_option( $option );

                if ( in_array( $option, [ 'page_on_front', 'page_for_posts' ], true ) ) {
                    $resolved = $this->resolve_page_option_for_language( $option, $to );
                    $to_value = $resolved !== null && $resolved !== (int) $base_value ? $resolved : null;
                } else {
                    [ $has_to, $to_value ] = self::get_localized_option_value( $option, $to );
                    // An empty translation falls back to the base value, as filter_pre_option() does.
                    $to_value = $has_to && $to_value !== '' ? $to_value : null;
                }

                if ( $to_value !== null ) {
                    foreach ( self::get_language_option_suffixes( $from ) as $suffix ) {
                        delete_option( $option . '_' . $suffix );
                    }

                    if ( $has_base ) {
                        self::write_option_row( $option . '_' . self::get_primary_language_option_suffix( $from ), $base_value );
                    }

                    self::write_option_row( $option, $to_value );
                    $moved[] = $option;
                }

                foreach ( self::get_language_option_suffixes( $to ) as $suffix ) {
                    delete_option( $option . '_' . $suffix );
                }
            }
        } finally {
            self::$routing_suspended = false;
        }

        return $moved;
    }

    /**
     * Write an option row as given. update_option() would compare the value with get_option(),
     * which language filters (WP-LOC's own, Yoast's in the admin, a theme's) may answer with
     * another language's value, and then skip the write.
     */
    private static function write_option_row( string $option, $value ): void {
        global $wpdb;

        $serialized = maybe_serialize( $value );
        $exists = $wpdb->get_var( $wpdb->prepare( "SELECT option_id FROM {$wpdb->options} WHERE option_name = %s LIMIT 1", $option ) );

        if ( $exists ) {
            $wpdb->update( $wpdb->options, [ 'option_value' => $serialized ], [ 'option_name' => $option ] );
        } else {
            $wpdb->insert( $wpdb->options, [ 'option_name' => $option, 'option_value' => $serialized, 'autoload' => 'yes' ] );
        }

        wp_cache_delete( $option, 'options' );
        wp_cache_delete( 'alloptions', 'options' );
        wp_cache_delete( 'notoptions', 'options' );
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
        [ $has_localized_value, $localized_value ] = self::get_localized_option_value( $option, $language );

        if ( $has_localized_value && self::is_valid_localized_page_option_value( $option, $localized_value ) ) {
            return (int) $localized_value;
        }

        $raw_page_id = (int) self::get_unfiltered_option( $option )[1];

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
        if ( self::$routing_suspended ) return $value;

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
        if ( self::$routing_suspended ) return;

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

        /**
         * Whether the screen edits option values for the current admin language.
         *
         * Plugins registering multilingual options on their own settings screens
         * must opt those screens in, otherwise the fields render the default
         * language value while saving routes it to the current language.
         *
         * @param bool      $is_settings Whether the screen is a settings screen.
         * @param WP_Screen $screen      Current screen.
         */
        $is_settings = (bool) apply_filters( 'wp_loc_is_localized_options_screen', $is_settings, $screen );
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
