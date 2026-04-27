<?php

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Third-party compatibility layer
 *
 * Provides common multilingual functions, filters, constants, and $sitepress global
 * so that existing themes and plugins keep working.
 */
class WP_LOC_Compat {

    private function get_internal_context_language(): string {
        return WP_LOC_Routing::is_frontend_ajax_request() || ! is_admin()
            ? wp_loc_get_current_lang()
            : wp_loc_get_admin_lang();
    }

    private function get_context_language(): string {
        $language = $this->get_internal_context_language();

        return WP_LOC_DB::to_db_language_code( $language ) ?: $language;
    }

    private function get_default_context_language(): string {
        $language = WP_LOC_Languages::get_default_language();

        return WP_LOC_DB::to_db_language_code( $language ) ?: $language;
    }

    public function __construct() {
        $this->sync_legacy_settings();
        $this->register_filters();
        $this->register_constant();
        $this->register_sitepress_global();
    }

    private function sync_legacy_settings(): void {
        $active = WP_LOC_Languages::get_active_languages();
        $active_codes = [];

        foreach ( $active as $slug => $data ) {
            $active_codes[] = WP_LOC_DB::to_db_language_code( (string) $slug ) ?: (string) $slug;
        }

        $settings = get_option( 'icl_sitepress_settings', [] );
        $settings = is_array( $settings ) ? $settings : [];
        $original_settings = $settings;
        $settings['default_language'] = $this->get_default_context_language();
        $settings['active_languages'] = array_values( array_unique( array_filter( $active_codes ) ) );

        if ( $settings !== $original_settings ) {
            update_option( 'icl_sitepress_settings', $settings, false );
        }
    }

    private function get_switcher_urls_by_language(): array {
        if ( ! function_exists( 'wp_loc_get_lang_switcher' ) ) {
            return [];
        }

        $urls = [];
        foreach ( wp_loc_get_lang_switcher() as $language ) {
            $code = sanitize_key( (string) ( $language['code'] ?? '' ) );
            $url = (string) ( $language['url'] ?? '' );

            if ( $code && $url ) {
                $urls[ $code ] = $url;
            }
        }

        return $urls;
    }

    private function build_home_url_for_language( string $slug ): string {
        $default = WP_LOC_Languages::get_default_language();
        $home = rtrim( set_url_scheme( get_option( 'home' ) ), '/' );

        return $home . ( $slug === $default ? '/' : "/{$slug}/" );
    }

    /**
     * Register compatibility filters
     */
    private function register_filters(): void {
        // wpml_post_language_details
        add_filter( 'wpml_post_language_details', function ( $value, $post_id ) {
            return $this->post_language_details( (int) $post_id ) ?: $value;
        }, 10, 2 );

        // wpml_object_id — get translated element ID
        add_filter( 'wpml_object_id', function ( $element_id, $element_type = 'post', $return_original = false, $language_code = null ) {
            return $this->object_id( $element_id, $element_type, $return_original, $language_code );
        }, 10, 4 );

        // wpml_current_language
        add_filter( 'wpml_current_language', function () {
            return $this->get_context_language();
        } );

        // wpml_default_language
        add_filter( 'wpml_default_language', function () {
            return $this->get_default_context_language();
        } );

        // wpml_active_languages
        add_filter( 'wpml_active_languages', function ( $value = null ) {
            $active = WP_LOC_Languages::get_active_languages();
            $current = $this->get_internal_context_language();
            $switcher_urls = $this->get_switcher_urls_by_language();

            $result = [];
            foreach ( $active as $slug => $data ) {
                $locale = $data['locale'] ?? $slug;
                $display_name = WP_LOC_Languages::get_display_name( $slug );
                $compat_code = WP_LOC_DB::to_db_language_code( $slug ) ?: $slug;
                $result[ $compat_code ] = [
                    'code'            => $compat_code,
                    'id'              => $compat_code,
                    'native_name'     => $display_name,
                    'translated_name' => $display_name,
                    'language_code'   => $compat_code,
                    'default_locale'  => $locale,
                    'active'          => $slug === $current ? 1 : 0,
                    'tag'             => str_replace( '_', '-', $locale ),
                    'url'             => $switcher_urls[ $slug ] ?? $this->build_home_url_for_language( $slug ),
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
            $element = $this->normalize_element( (int) $element_id, (string) $element_type );

            return $element ? WP_LOC::instance()->db->get_trid( $element['id'], $element['type'] ) : $value;
        }, 10, 3 );

        // wpml_get_element_translations
        add_filter( 'wpml_get_element_translations', function ( $value, $trid, $element_type = '' ) {
            $normalized_type = $element_type ? $this->normalize_element_type( (string) $element_type ) : '';

            $translations = WP_LOC::instance()->db->get_element_translations( (int) $trid, $normalized_type ?: (string) $element_type );
            $result = [];

            foreach ( $translations as $language => $row ) {
                $compat_code = WP_LOC_DB::to_db_language_code( $language ) ?: $language;
                $compat_row = clone $row;
                $compat_row->language_code = $compat_code;
                $compat_row->source_language_code = $row->source_language_code
                    ? ( WP_LOC_DB::to_db_language_code( $row->source_language_code ) ?: $row->source_language_code )
                    : null;
                $result[ $compat_code ] = $compat_row;
            }

            return $result;
        }, 10, 3 );

        // wpml_element_language_code
        add_filter( 'wpml_element_language_code', function ( $value, $args ) {
            $element_id = (int) ( $args['element_id'] ?? 0 );
            $element_type = (string) ( $args['element_type'] ?? 'post_post' );
            $element = $this->normalize_element( $element_id, $element_type );

            if ( ! $element ) {
                return $value;
            }

            $language = WP_LOC::instance()->db->get_element_language( $element['id'], $element['type'] );

            return $language ? ( WP_LOC_DB::to_db_language_code( $language ) ?: $language ) : $value;
        }, 10, 2 );

        // wpml_element_language_details
        add_filter( 'wpml_element_language_details', function ( $value, $args ) {
            $element_id = (int) ( $args['element_id'] ?? 0 );
            $element_type = (string) ( $args['element_type'] ?? 'post_post' );

            return $this->element_language_details( $element_id, $element_type ) ?: $value;
        }, 10, 2 );

        // wpml_set_element_language_details
        add_action( 'wpml_set_element_language_details', function ( $args ) {
            if ( is_array( $args ) ) {
                $this->set_element_language_details_from_array( $args );
            }
        } );

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
        if ( ! defined( 'ICL_LANGUAGE_CODE' ) ) {
            define( 'ICL_LANGUAGE_CODE', $this->get_context_language() );
        }

        if ( ! defined( 'ICL_LANGUAGE_NAME' ) ) {
            define( 'ICL_LANGUAGE_NAME', WP_LOC_Languages::get_display_name( $this->get_internal_context_language() ) );
        }

        add_action( 'after_setup_theme', function (): void {
            if ( ! defined( 'ICL_DONT_LOAD_NAVIGATION_CSS' ) ) {
                define( 'ICL_DONT_LOAD_NAVIGATION_CSS', true );
            }
            if ( ! defined( 'ICL_DONT_LOAD_LANGUAGE_SELECTOR_CSS' ) ) {
                define( 'ICL_DONT_LOAD_LANGUAGE_SELECTOR_CSS', true );
            }
            if ( ! defined( 'ICL_DONT_LOAD_LANGUAGES_JS' ) ) {
                define( 'ICL_DONT_LOAD_LANGUAGES_JS', true );
            }
        }, 99 );
    }

    /**
     * Create mock $sitepress global
     */
    private function register_sitepress_global(): void {
        if ( isset( $GLOBALS['sitepress'] ) ) return;

        $GLOBALS['sitepress'] = new WP_LOC_Sitepress_Mock();
    }

    /**
     * Get translated object ID (implements icl_object_id logic)
     */
    public function object_id( $element_id, $element_type = 'post', $return_original = false, $language_code = null ) {
        if ( ! $element_id ) return $return_original ? $element_id : null;

        $db = WP_LOC::instance()->db;
        $target_lang = $language_code ?: $this->get_context_language();
        $element = $this->normalize_element( (int) $element_id, (string) $element_type );

        if ( $element && str_starts_with( $element['type'], 'tax_' ) ) {
            $translated_term_taxonomy_id = $db->get_element_translation( $element['id'], $element['type'], $target_lang );

            if ( $translated_term_taxonomy_id ) {
                $taxonomy = substr( $element['type'], 4 );
                $translated_term_id = WP_LOC_Terms::get_term_id_from_taxonomy_id( $translated_term_taxonomy_id, $taxonomy );

                if ( $translated_term_id ) {
                    return $translated_term_id;
                }
            }

            return $return_original ? $element_id : null;
        }

        if ( $element_type === 'nav_menu' ) {
            $translated_id = WP_LOC_Terms::get_term_translation( (int) $element_id, 'nav_menu', $target_lang );

            if ( $translated_id ) {
                return $translated_id;
            }

            return $return_original ? $element_id : null;
        }

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

    private function normalize_element_type( string $element_type ): string {
        if ( $element_type === 'nav_menu' ) {
            return WP_LOC_DB::tax_element_type( 'nav_menu' );
        }

        if ( str_starts_with( $element_type, 'tax_' ) ) {
            return $element_type;
        }

        if ( taxonomy_exists( $element_type ) ) {
            return WP_LOC_DB::tax_element_type( $element_type );
        }

        if ( str_starts_with( $element_type, 'post_' ) ) {
            return $element_type;
        }

        if ( in_array( $element_type, [ 'post', 'page', 'attachment' ], true ) || post_type_exists( $element_type ) ) {
            return WP_LOC_DB::post_element_type( $element_type );
        }

        return $element_type;
    }

    private function normalize_element( int $element_id, string $element_type ): ?array {
        if ( ! $element_id ) {
            return null;
        }

        $normalized_type = $this->normalize_element_type( $element_type );

        if ( str_starts_with( $normalized_type, 'tax_' ) ) {
            $taxonomy = substr( $normalized_type, 4 );
            $term_taxonomy_id = WP_LOC_Terms::get_term_taxonomy_id( $element_id, $taxonomy );

            if ( ! $term_taxonomy_id ) {
                $term_id = WP_LOC_Terms::get_term_id_from_taxonomy_id( $element_id, $taxonomy );
                $term_taxonomy_id = $term_id ? $element_id : null;
            }

            if ( ! $term_taxonomy_id ) {
                return null;
            }

            return [
                'id'   => (int) $term_taxonomy_id,
                'type' => $normalized_type,
            ];
        }

        return [
            'id'   => $element_id,
            'type' => $normalized_type,
        ];
    }

    public function element_language_details( int $element_id, string $element_type ): ?array {
        $element = $this->normalize_element( $element_id, $element_type );

        if ( ! $element ) {
            return null;
        }

        $db = WP_LOC::instance()->db;
        $language_code = $db->get_element_language( $element['id'], $element['type'] );
        $trid = $db->get_trid( $element['id'], $element['type'] );

        if ( ! $language_code && ! $trid ) {
            return null;
        }

        $source_language_code = null;

        if ( $trid && $language_code ) {
            $translations = $db->get_element_translations( $trid, $element['type'] );
            $source_language_code = isset( $translations[ $language_code ] )
                ? ( $translations[ $language_code ]->source_language_code ?: null )
                : null;
        }

        return [
            'trid'                 => $trid,
            'language_code'        => $language_code ? ( WP_LOC_DB::to_db_language_code( $language_code ) ?: $language_code ) : null,
            'source_language_code' => $source_language_code ? ( WP_LOC_DB::to_db_language_code( $source_language_code ) ?: $source_language_code ) : null,
        ];
    }

    public function post_language_details( int $post_id ): ?array {
        $post_type = get_post_type( $post_id );

        if ( ! $post_type ) {
            return null;
        }

        return $this->element_language_details( $post_id, WP_LOC_DB::post_element_type( $post_type ) );
    }

    public function set_element_language_details_from_array( array $args ): void {
        $element_id = (int) ( $args['element_id'] ?? 0 );
        $element_type = (string) ( $args['element_type'] ?? 'post_post' );
        $language_code = (string) ( $args['language_code'] ?? '' );

        if ( ! $element_id || ! $language_code ) {
            return;
        }

        $element = $this->normalize_element( $element_id, $element_type );

        if ( ! $element ) {
            return;
        }

        $trid = isset( $args['trid'] ) && $args['trid'] ? (int) $args['trid'] : null;
        $source_language_code = isset( $args['source_language_code'] ) && $args['source_language_code'] !== ''
            ? (string) $args['source_language_code']
            : null;

        WP_LOC::instance()->db->set_element_language( $element['id'], $element['type'], $language_code, $trid, $source_language_code );
    }
}

/**
 * Mock SitePress class for $sitepress global
 */
class WP_LOC_Sitepress_Mock {

    public function get_current_language(): string {
        $language = WP_LOC_Routing::is_frontend_ajax_request() || ! is_admin()
            ? wp_loc_get_current_lang()
            : wp_loc_get_admin_lang();

        return WP_LOC_DB::to_db_language_code( $language ) ?: $language;
    }

    public function get_default_language(): string {
        $language = WP_LOC_Languages::get_default_language();

        return WP_LOC_DB::to_db_language_code( $language ) ?: $language;
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
        return apply_filters( 'wpml_element_language_code', null, [
            'element_id'   => $element_id,
            'element_type' => $element_type,
        ] );
    }

    public function get_element_trid( int $element_id, string $element_type = 'post_post' ): ?int {
        return apply_filters( 'wpml_element_trid', null, $element_id, $element_type );
    }

    public function is_translated_taxonomy( string $taxonomy ): bool {
        return WP_LOC_Terms::is_translatable( $taxonomy );
    }

    public function set_element_language_details( int $element_id, string $element_type = 'post_post', $trid = false, string $language_code = '', ?string $source_language_code = null ): void {
        if ( ! $language_code ) {
            return;
        }

        do_action( 'wpml_set_element_language_details', [
            'element_id'            => $element_id,
            'element_type'          => $element_type,
            'trid'                  => $trid ?: null,
            'language_code'         => $language_code,
            'source_language_code'  => $source_language_code,
        ] );
    }

    public function get_element_language_details( int $element_id, string $element_type = 'post_post' ): ?array {
        return apply_filters( 'wpml_element_language_details', null, [
            'element_id'   => $element_id,
            'element_type' => $element_type,
        ] );
    }

    public function get_terms_args_filter( array $args, array $taxonomies = [] ): array {
        return $args;
    }

    public function get_term_adjust_id( $term, $taxonomy = null ) {
        return $term;
    }

    public function terms_clauses( array $clauses, array $taxonomies, array $args ): array {
        return $clauses;
    }

    public function meta_generator_tag(): void {}
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
 * Global function: icl_get_default_language
 */
if ( ! function_exists( 'icl_get_default_language' ) ) {
    function icl_get_default_language() {
        return apply_filters( 'wpml_default_language', null );
    }
}

/**
 * Global function: wpml_get_default_language
 */
if ( ! function_exists( 'wpml_get_default_language' ) ) {
    function wpml_get_default_language() {
        return apply_filters( 'wpml_default_language', null );
    }
}

/**
 * Global function: wpml_get_current_language
 */
if ( ! function_exists( 'wpml_get_current_language' ) ) {
    function wpml_get_current_language() {
        return apply_filters( 'wpml_current_language', null );
    }
}

/**
 * Global function: wpml_add_translatable_content
 */
if ( ! function_exists( 'wpml_add_translatable_content' ) ) {
    function wpml_add_translatable_content( ...$args ) {
        return true;
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
