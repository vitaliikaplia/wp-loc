<?php

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Third-party compatibility layer
 *
 * Provides common multilingual functions, filters, constants, and $sitepress global
 * so that existing themes and plugins keep working.
 */
class WP_LOC_Compat {

    public function __construct() {
        $this->register_filters();
        $this->register_constant();
        $this->register_sitepress_global();
    }

    /**
     * Register compatibility filters
     */
    private function register_filters(): void {
        // wpml_object_id — get translated element ID
        add_filter( 'wpml_object_id', function ( $element_id, $element_type = 'post', $return_original = false, $language_code = null ) {
            return $this->object_id( $element_id, $element_type, $return_original, $language_code );
        }, 10, 4 );

        // wpml_current_language
        add_filter( 'wpml_current_language', function () {
            return wp_loc_get_current_lang();
        } );

        // wpml_default_language
        add_filter( 'wpml_default_language', function () {
            return WP_LOC_Languages::get_default_language();
        } );

        // wpml_active_languages
        add_filter( 'wpml_active_languages', function ( $value = null ) {
            $active = WP_LOC_Languages::get_active_languages();
            $current = wp_loc_get_current_lang();
            $default = WP_LOC_Languages::get_default_language();

            $result = [];
            foreach ( $active as $slug => $data ) {
                $locale = $data['locale'] ?? $slug;
                $result[ $slug ] = [
                    'code'            => $slug,
                    'id'              => $slug,
                    'native_name'     => WP_LOC_Languages::get_language_display_name( $locale ),
                    'translated_name' => WP_LOC_Languages::get_language_display_name( $locale ),
                    'language_code'   => $slug,
                    'default_locale'  => $locale,
                    'active'          => $slug === $current ? 1 : 0,
                    'tag'             => str_replace( '_', '-', $locale ),
                    'url'             => $slug === $default ? home_url( '/' ) : home_url( "/{$slug}/" ),
                    'country_flag_url' => WP_LOC_Languages::get_flag_url( $locale ),
                ];
            }

            return $result;
        } );

        add_filter( 'wpml_is_translated_taxonomy', function ( $is_translated, $taxonomy ) {
            return WP_LOC_Terms::is_translatable( (string) $taxonomy );
        }, 10, 2 );

        // wpml_element_trid
        add_filter( 'wpml_element_trid', function ( $value, $element_id, $element_type = 'post_post' ) {
            return WP_LOC::instance()->db->get_trid( (int) $element_id, $element_type );
        }, 10, 3 );

        // wpml_get_element_translations
        add_filter( 'wpml_get_element_translations', function ( $value, $trid, $element_type = '' ) {
            return WP_LOC::instance()->db->get_element_translations( (int) $trid, $element_type );
        }, 10, 3 );

        // wpml_element_language_code
        add_filter( 'wpml_element_language_code', function ( $value, $args ) {
            $element_id = $args['element_id'] ?? 0;
            $element_type = $args['element_type'] ?? 'post_post';
            return WP_LOC::instance()->db->get_element_language( (int) $element_id, $element_type ) ?: $value;
        }, 10, 2 );

        // wpml_switch_language action
        add_action( 'wpml_switch_language', function ( $language_code ) {
            // This is a simplified version — stores switched language
            WP_LOC_Routing::flush();
        } );
    }

    /**
     * Define ICL_LANGUAGE_CODE constant
     */
    private function register_constant(): void {
        add_action( 'after_setup_theme', function () {
            if ( ! defined( 'ICL_LANGUAGE_CODE' ) ) {
                define( 'ICL_LANGUAGE_CODE', wp_loc_get_current_lang() );
            }
            if ( ! defined( 'ICL_LANGUAGE_NAME' ) ) {
                $locale = wp_loc_get_current_locale();
                define( 'ICL_LANGUAGE_NAME', WP_LOC_Languages::get_language_display_name( $locale ) );
            }
        }, 999 );

        // Prevent loading of external multilingual CSS/JS
        if ( ! defined( 'ICL_DONT_LOAD_NAVIGATION_CSS' ) ) {
            define( 'ICL_DONT_LOAD_NAVIGATION_CSS', true );
        }
        if ( ! defined( 'ICL_DONT_LOAD_LANGUAGE_SELECTOR_CSS' ) ) {
            define( 'ICL_DONT_LOAD_LANGUAGE_SELECTOR_CSS', true );
        }
        if ( ! defined( 'ICL_DONT_LOAD_LANGUAGES_JS' ) ) {
            define( 'ICL_DONT_LOAD_LANGUAGES_JS', true );
        }
    }

    /**
     * Create mock $sitepress global
     */
    private function register_sitepress_global(): void {
        add_action( 'after_setup_theme', function () {
            if ( isset( $GLOBALS['sitepress'] ) ) return;

            $GLOBALS['sitepress'] = new WP_LOC_Sitepress_Mock();
        }, 998 );
    }

    /**
     * Get translated object ID (implements icl_object_id logic)
     */
    public function object_id( $element_id, $element_type = 'post', $return_original = false, $language_code = null ) {
        if ( ! $element_id ) return $return_original ? $element_id : null;

        $db = WP_LOC::instance()->db;
        $target_lang = $language_code ?: wp_loc_get_current_lang();

        // Determine element_type prefix
        $type_prefix = $element_type;
        if ( in_array( $element_type, [ 'post', 'page', 'attachment' ], true ) || post_type_exists( $element_type ) ) {
            $type_prefix = 'post_' . $element_type;
        } elseif ( taxonomy_exists( $element_type ) ) {
            $translated_id = WP_LOC_Terms::get_term_translation( (int) $element_id, $element_type, $target_lang );

            if ( $translated_id ) return $translated_id;

            return $return_original ? $element_id : null;
        }

        $translated_id = $db->get_element_translation( (int) $element_id, $type_prefix, $target_lang );

        if ( $translated_id ) return $translated_id;

        return $return_original ? $element_id : null;
    }
}

/**
 * Mock SitePress class for $sitepress global
 */
class WP_LOC_Sitepress_Mock {

    public function get_current_language(): string {
        return wp_loc_get_current_lang();
    }

    public function get_default_language(): string {
        return WP_LOC_Languages::get_default_language();
    }

    public function switch_lang( string $language_code ): void {
        WP_LOC_Routing::flush();
    }

    public function get_active_languages( bool $refresh = false ): array {
        return apply_filters( 'wpml_active_languages', null );
    }

    public function get_setting( string $key, $default = false ) {
        return get_option( 'wp_loc_' . $key, $default );
    }

    public function set_setting( string $key, $value ): void {
        update_option( 'wp_loc_' . $key, $value );
    }

    public function get_language_for_element( int $element_id, string $element_type = 'post_post' ): ?string {
        return WP_LOC::instance()->db->get_element_language( $element_id, $element_type );
    }

    public function get_element_trid( int $element_id, string $element_type = 'post_post' ): ?int {
        return WP_LOC::instance()->db->get_trid( $element_id, $element_type );
    }

    public function is_translated_taxonomy( string $taxonomy ): bool {
        return WP_LOC_Terms::is_translatable( $taxonomy );
    }
}

/**
 * Global function: icl_object_id
 */
if ( ! function_exists( 'icl_object_id' ) ) {
    function icl_object_id( $element_id, $element_type = 'post', $return_original_if_missing = false, $ulanguage_code = null ) {
        if ( ! WP_LOC::instance()->compat ) return $element_id;
        return WP_LOC::instance()->compat->object_id( $element_id, $element_type, $return_original_if_missing, $ulanguage_code );
    }
}

/**
 * Global function: icl_get_languages
 */
if ( ! function_exists( 'icl_get_languages' ) ) {
    function icl_get_languages( $args = '' ) {
        return apply_filters( 'wpml_active_languages', null );
    }
}

/**
 * Global function: wpml_object_id_filter
 */
if ( ! function_exists( 'wpml_object_id_filter' ) ) {
    function wpml_object_id_filter( $element_id, $element_type = 'post', $return_original = false, $language_code = null ) {
        return icl_object_id( $element_id, $element_type, $return_original, $language_code );
    }
}
