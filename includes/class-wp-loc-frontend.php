<?php

if ( ! defined( 'ABSPATH' ) ) exit;

class WP_LOC_Frontend {

    public function __construct() {
        add_filter( 'language_attributes', [ $this, 'html_lang_attribute' ] );
        add_action( 'wp_head', [ $this, 'output_hreflang_tags' ] );
        add_action( 'pre_get_posts', [ $this, 'filter_posts_by_language' ] );
    }

    /**
     * Set <html lang="..."> attribute
     */
    public function html_lang_attribute( string $output ): string {
        if ( is_admin() ) return $output;

        $locale = wp_loc_get_current_locale();
        $lang_attr = str_replace( '_', '-', $locale );

        $rtl_locales = [ 'ar', 'he', 'fa', 'ur' ];
        $lang_code = strtolower( substr( $locale, 0, 2 ) );
        $dir = in_array( $lang_code, $rtl_locales, true ) ? 'rtl' : 'ltr';

        return sprintf( 'lang="%s" dir="%s"', esc_attr( $lang_attr ), esc_attr( $dir ) );
    }

    /**
     * Output alternate hreflang tags and canonical tags.
     */
    public function output_hreflang_tags(): void {
        if ( is_admin() || is_404() || is_search() ) {
            return;
        }

        $alternates = $this->get_frontend_alternate_links();

        foreach ( $alternates as $hreflang => $url ) {
            echo '<link rel="alternate" hreflang="' . esc_attr( $hreflang ) . '" href="' . esc_url( $url ) . '" />' . "\n";
        }

        if ( is_singular() && ! $this->seo_plugin_outputs_canonical() ) {
            echo '<link rel="canonical" href="' . esc_url( get_permalink( get_queried_object_id() ) ) . '" />' . "\n";
        }
    }

    private function seo_plugin_outputs_canonical(): bool {
        return defined( 'WPSEO_VERSION' ) || class_exists( 'WPSEO_Frontend' );
    }

    private function get_frontend_alternate_links(): array {
        $switcher = wp_loc_get_lang_switcher();

        if ( empty( $switcher ) ) {
            return [];
        }

        $alternates = [];
        $default_lang = WP_LOC_Languages::get_default_language();
        $default_url = '';

        foreach ( $switcher as $language ) {
            $code = sanitize_key( (string) ( $language['code'] ?? '' ) );
            $url = (string) ( $language['url'] ?? '' );

            if ( ! $code || ! $url ) {
                continue;
            }

            if ( array_key_exists( 'has_translation', $language ) && ! $language['has_translation'] ) {
                continue;
            }

            if ( $code === $default_lang ) {
                $default_url = $url;
            }

            $alternates[ $this->get_hreflang_for_language( $code ) ] = $url;
        }

        if ( $default_url ) {
            $alternates['x-default'] = $default_url;
        }

        return $alternates;
    }

    private function get_hreflang_for_language( string $language ): string {
        if ( $language === 'x-default' ) {
            return 'x-default';
        }

        $locale = WP_LOC_Languages::get_language_locale( $language );

        if ( ! $locale ) {
            return $language;
        }

        return str_replace( '_', '-', $locale );
    }

    /**
     * Filter frontend posts by current language
     */
    public function filter_posts_by_language( \WP_Query $query ): void {
        if ( $query->get( 'suppress_filters' ) ) return;

        $lang_slug = $query->get( 'lang' ) ?: $this->get_query_context_language();
        if ( ! $lang_slug || $lang_slug === 'all' ) return;

        $active = WP_LOC_Languages::get_active_languages();
        if ( ! isset( $active[ $lang_slug ] ) ) return;
        $db_lang = WP_LOC_DB::to_db_language_code( $lang_slug ) ?: $lang_slug;

        $post_types = $this->get_query_post_types( $query );
        $filterable_post_types = array_values( array_filter(
            $post_types,
            static fn( string $post_type ): bool => WP_LOC_Admin_Settings::is_translatable( $post_type )
        ) );

        if ( empty( $filterable_post_types ) ) {
            return;
        }

        $table = WP_LOC::instance()->db->get_table();

        add_filter( 'posts_join', function ( $join, \WP_Query $filtered_query ) use ( $table, $query, $filterable_post_types ) {
            if ( $filtered_query !== $query ) {
                return $join;
            }

            global $wpdb;
            if ( strpos( $join, 'wp_loc_ft' ) !== false ) return $join;
            $element_types = array_map(
                static fn( string $post_type ): string => 'post_' . $post_type,
                $filterable_post_types
            );
            $quoted_element_types = "'" . implode( "','", array_map( 'esc_sql', $element_types ) ) . "'";
            $join .= " LEFT JOIN {$table} AS wp_loc_ft
                ON {$wpdb->posts}.ID = wp_loc_ft.element_id
                AND wp_loc_ft.element_type IN ({$quoted_element_types})";
            return $join;
        }, 10, 2 );

        add_filter( 'posts_where', function ( $where, \WP_Query $filtered_query ) use ( $db_lang, $query, $filterable_post_types ) {
            if ( $filtered_query !== $query ) {
                return $where;
            }

            global $wpdb;
            $quoted_post_types = "'" . implode( "','", array_map( 'esc_sql', $filterable_post_types ) ) . "'";
            $where .= $wpdb->prepare(
                " AND (
                    {$wpdb->posts}.post_type NOT IN ({$quoted_post_types})
                    OR wp_loc_ft.language_code = %s
                    OR wp_loc_ft.element_id IS NULL
                )",
                $db_lang
            );
            return $where;
        }, 10, 2 );
    }

    private function get_query_context_language(): ?string {
        if ( WP_LOC_Routing::is_frontend_ajax_request() ) {
            return wp_loc_get_current_lang();
        }

        $is_editor_context = is_admin() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) || wp_doing_ajax();

        if ( $is_editor_context ) {
            $post_id = $this->get_admin_context_post_id();
            if ( ! $post_id ) {
                return null;
            }

            $post_type = get_post_type( $post_id );
            if ( ! $post_type || ! WP_LOC_Admin_Settings::is_translatable( $post_type ) ) {
                return null;
            }

            return WP_LOC::instance()->db->get_element_language( $post_id, WP_LOC_DB::post_element_type( $post_type ) );
        }

        return wp_loc_get_current_lang();
    }

    private function get_admin_context_post_id(): int {
        foreach ( [ 'post_id', 'post', 'post_ID', 'id' ] as $key ) {
            if ( isset( $_REQUEST[ $key ] ) && is_numeric( $_REQUEST[ $key ] ) ) {
                $post_id = absint( wp_unslash( $_REQUEST[ $key ] ) );
                if ( $post_id && get_post( $post_id ) ) {
                    return $post_id;
                }
            }
        }

        if ( function_exists( 'get_current_screen' ) ) {
            $screen = get_current_screen();
            if ( $screen && ! empty( $screen->is_block_editor ) && isset( $_GET['post'] ) && is_numeric( $_GET['post'] ) ) {
                $post_id = absint( wp_unslash( $_GET['post'] ) );
                if ( $post_id && get_post( $post_id ) ) {
                    return $post_id;
                }
            }
        }

        return 0;
    }

    private function get_query_post_types( \WP_Query $query ): array {
        $post_type = $query->get( 'post_type' );

        if ( empty( $post_type ) ) {
            return apply_filters( 'wp_loc_translatable_post_types', [ 'post', 'page' ] );
        }

        if ( $post_type === 'any' ) {
            return get_post_types( [ 'public' => true ], 'names' );
        }

        if ( is_array( $post_type ) ) {
            return array_values( array_filter( array_map( 'sanitize_key', $post_type ) ) );
        }

        return [ sanitize_key( (string) $post_type ) ];
    }
}

/**
 * Get language switcher data for templates
 *
 * @return array [ ['code' => 'uk', 'locale' => 'uk', 'active' => true, 'url' => '...', 'flag' => '...', 'name' => '...'], ... ]
 */
function wp_loc_get_lang_switcher(): array {
    $active = WP_LOC_Languages::get_active_languages();
    $current = wp_loc_get_current_lang();
    $default = WP_LOC_Languages::get_default_language();
    $db = WP_LOC::instance()->db;
    $hide_current = WP_LOC_Admin_Settings::hide_current_language_in_switcher();
    $hide_untranslated = WP_LOC_Admin_Settings::hide_untranslated_languages_in_switcher();
    $fallback_to_home = WP_LOC_Admin_Settings::fallback_untranslated_switcher_links_to_home();

    // Use raw home URL to avoid the home_url language prefix filter
    $home = rtrim( set_url_scheme( get_option( 'home' ) ), '/' );
    $build_home_url = static function ( string $code ) use ( $home, $default ): string {
        return $home . ( $code === $default ? '/' : "/{$code}/" );
    };
    $get_current_query_args = static function (): array {
        $request_uri = isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( (string) $_SERVER['REQUEST_URI'] ) : '/';
        $query = parse_url( $request_uri, PHP_URL_QUERY );

        if ( ! is_string( $query ) || $query === '' ) {
            return [];
        }

        $query_args = [];
        wp_parse_str( $query, $query_args );

        foreach ( [ 'lang', 'wp_loc_lang', 'wpml_lang', '_wpml_lang', 'icl_language', 'ICL_LANGUAGE_CODE' ] as $language_arg ) {
            unset( $query_args[ $language_arg ] );
        }

        return array_filter( $query_args, static fn( $value ): bool => $value !== null && $value !== '' );
    };
    $append_current_query_args = static function ( string $url ) use ( $get_current_query_args ): string {
        $query_args = $get_current_query_args();

        return empty( $query_args ) ? $url : add_query_arg( $query_args, $url );
    };
    $get_clean_request_path = static function () use ( $active ): string {
        $request_uri = isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( (string) $_SERVER['REQUEST_URI'] ) : '/';
        $uri_path = trim( (string) parse_url( $request_uri, PHP_URL_PATH ), '/' );

        if ( $uri_path === '' ) {
            return '';
        }

        $segments = explode( '/', $uri_path );
        $uri_lang_prefix = array_key_exists( $segments[0] ?? '', $active ) ? $segments[0] : null;
        $clean_segments = $uri_lang_prefix ? array_slice( $segments, 1 ) : $segments;

        return trim( implode( '/', $clean_segments ), '/' );
    };
    $build_url_for_clean_path = static function ( string $code, string $clean_path ) use ( $home, $default, $append_current_query_args ): string {
        $prefix = ( $code === $default ) ? '' : $code;
        $full_path = trim( trim( $prefix . '/' . trim( $clean_path, '/' ), '/' ), '/' );
        $url = $home . ( $full_path ? '/' . $full_path . '/' : '/' );

        return $append_current_query_args( $url );
    };
    $add_pagination_to_url = static function ( string $url ): string {
        $paged = max( (int) get_query_var( 'paged' ), (int) get_query_var( 'page' ) );

        if ( $paged < 2 ) {
            return $url;
        }

        return trailingslashit( $url ) . user_trailingslashit( 'page/' . $paged, 'paged' );
    };
    $append_switcher_item = static function ( array &$switcher, string $code, array $data, string $url, bool $has_translation = true ) use ( $current, $hide_current, $hide_untranslated ) : void {
        if ( $hide_current && $code === $current ) {
            return;
        }

        if ( $hide_untranslated && ! $has_translation && $code !== $current ) {
            return;
        }

        $locale = $data['locale'] ?? $code;

        $switcher[] = [
            'code'            => $code,
            'locale'          => $locale,
            'active'          => $code === $current,
            'url'             => $url,
            'flag'            => WP_LOC_Languages::get_flag_url( $locale ),
            'name'            => WP_LOC_Languages::get_display_name( $code ),
            'has_translation' => $has_translation,
        ];
    };

    $switcher = [];

    // Front page
    if ( is_front_page() ) {
        foreach ( $active as $code => $data ) {
            $append_switcher_item( $switcher, $code, $data, $append_current_query_args( $build_home_url( $code ) ) );
        }
        return $switcher;
    }

    // Posts page configured via Settings > Reading
    if ( get_option( 'show_on_front' ) === 'page' && is_home() && ! is_front_page() ) {
        $posts_page_id = (int) get_option( 'page_for_posts' );

        foreach ( $active as $code => $data ) {
            $url = $build_home_url( $code );
            $translated_posts_page_id = $posts_page_id ? $db->get_element_translation( $posts_page_id, WP_LOC_DB::post_element_type( 'page' ), $code ) : 0;
            $target_page_id = $translated_posts_page_id ?: $posts_page_id;
            $has_translation = (bool) $translated_posts_page_id || $code === $current;

            if ( $target_page_id ) {
                $url = get_permalink( $target_page_id );
            }

            if ( ! $translated_posts_page_id && $fallback_to_home ) {
                $url = $build_home_url( $code );
            }

            $append_switcher_item( $switcher, $code, $data, $append_current_query_args( $add_pagination_to_url( $url ) ), $has_translation );
        }

        return $switcher;
    }

    // Term archives with translations
    if ( is_category() || is_tag() || is_tax() ) {
        $queried_term = get_queried_object();

        if ( $queried_term instanceof \WP_Term && WP_LOC_Terms::is_translatable( $queried_term->taxonomy ) ) {
            foreach ( $active as $code => $data ) {
                $url = $build_home_url( $code );
                $translated_link = WP_LOC_Terms::get_term_url_for_language( (int) $queried_term->term_id, $queried_term->taxonomy, $code );
                $has_translation = (bool) $translated_link || $code === $current;

                if ( $translated_link ) {
                    $url = $append_current_query_args( $add_pagination_to_url( $translated_link ) );
                }

                $append_switcher_item( $switcher, $code, $data, $url, $has_translation );
            }

            return $switcher;
        }
    }

    // Shared WordPress archive contexts: keep the same archive/search path under each language.
    if ( is_author() || is_search() || is_date() || is_post_type_archive() ) {
        $clean_path = $get_clean_request_path();

        if ( $clean_path !== '' || is_search() ) {
            foreach ( $active as $code => $data ) {
                $append_switcher_item( $switcher, $code, $data, $build_url_for_clean_path( $code, $clean_path ) );
            }

            return $switcher;
        }
    }

    // Singular posts with translations
    $current_post_id = get_queried_object_id();
    $post_type = get_post_type( $current_post_id );
    $element_type = $post_type ? WP_LOC_DB::post_element_type( $post_type ) : '';
    $trid = $current_post_id && $element_type ? $db->get_trid( $current_post_id, $element_type ) : null;

    if ( $trid ) {
        $translations = $db->get_element_translations( $trid, $element_type );

        $urls = [];
        foreach ( $translations as $slug => $row ) {
            if ( ! isset( $active[ $slug ] ) ) continue;

            $translated_post = get_post( $row->element_id );
            if ( ! $translated_post || $translated_post->post_status !== 'publish' ) continue;

            $urls[ $slug ] = get_permalink( $row->element_id );
        }

        foreach ( $active as $code => $data ) {
            $has_translation = isset( $urls[ $code ] ) || $code === $current;
            $url = $urls[ $code ] ?? $build_home_url( $code );

            if ( ! isset( $urls[ $code ] ) && ! $fallback_to_home ) {
                $url = $home . ( $code === $default ? '/' : "/{$code}/" );
            }

            $append_switcher_item( $switcher, $code, $data, $append_current_query_args( $url ), $has_translation );
        }

        return $switcher;
    }

    // Fallback: replace language prefix in current URL
    $clean_path = $get_clean_request_path();

    foreach ( $active as $code => $data ) {
        $url = $build_url_for_clean_path( $code, $clean_path );

        if ( ! $fallback_to_home && ! empty( $clean_path ) ) {
            $url = $build_url_for_clean_path( $code, $clean_path );
        } elseif ( $fallback_to_home && ! empty( $clean_path ) ) {
            $url = $build_home_url( $code );
        }

        $append_switcher_item( $switcher, $code, $data, $url );
    }

    return $switcher;
}

/**
 * Get frontend language switcher HTML markup.
 */
function wp_loc_get_language_switcher_html(): string {
    $languages = wp_loc_get_lang_switcher();

    if ( empty( $languages ) ) {
        return '';
    }

    $show_flags = WP_LOC_Admin_Settings::show_switcher_flags();
    $show_names = WP_LOC_Admin_Settings::show_switcher_names();
    $html = '<ul class="languageSwitcher">';

    foreach ( $languages as $lang ) {
        $item_class = $lang['active'] ? ' class="active"' : '';

        $html .= '<li' . $item_class . '>';
        $html .= '<a href="' . esc_url( $lang['url'] ) . '">';

        if ( $show_flags && ! empty( $lang['flag'] ) ) {
            $html .= '<img src="' . esc_url( $lang['flag'] ) . '" alt="' . esc_attr( $lang['name'] ) . '" />';
            if ( $show_names ) {
                $html .= ' ';
            }
        }

        if ( $show_names ) {
            $html .= esc_html( $lang['name'] );
        } else {
            $html .= '<span class="screen-reader-text">' . esc_html( $lang['name'] ) . '</span>';
        }
        $html .= '</a>';
        $html .= '</li>';
    }

    $html .= '</ul>';

    return $html;
}

/**
 * Output frontend language switcher HTML markup.
 */
function wp_loc_the_language_switcher(): void {
    echo wp_loc_get_language_switcher_html();
}
