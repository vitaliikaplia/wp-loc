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
     * Output hreflang and canonical tags
     */
    public function output_hreflang_tags(): void {
        if ( ! is_singular() ) return;

        $post_id = get_queried_object_id();
        $db = WP_LOC::instance()->db;
        $element_type = WP_LOC_DB::post_element_type( get_post_type( $post_id ) );

        $current_lang = $db->get_element_language( $post_id, $element_type );
        $trid = $db->get_trid( $post_id, $element_type );

        $active = WP_LOC_Languages::get_active_languages();
        $default = WP_LOC_Languages::get_default_language();

        $translations = [];

        if ( $trid ) {
            $all_translations = $db->get_element_translations( $trid, $element_type );

            foreach ( $all_translations as $slug => $row ) {
                if ( ! isset( $active[ $slug ] ) ) continue;

                $translated_post = get_post( $row->element_id );
                if ( ! $translated_post || $translated_post->post_status !== 'publish' ) continue;

                $translations[ $slug ] = get_permalink( $row->element_id );
            }
        } else {
            if ( $current_lang ) {
                $translations[ $current_lang ] = get_permalink( $post_id );
            }
        }

        // Output hreflang tags
        foreach ( $translations as $slug => $url ) {
            echo '<link rel="alternate" hreflang="' . esc_attr( $slug ) . '" href="' . esc_url( $url ) . '" />' . "\n";
        }

        // x-default
        if ( isset( $translations[ $default ] ) ) {
            echo '<link rel="alternate" hreflang="x-default" href="' . esc_url( $translations[ $default ] ) . '" />' . "\n";
        }

        // Canonical
        echo '<link rel="canonical" href="' . esc_url( get_permalink( $post_id ) ) . '" />' . "\n";
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
    $home = set_url_scheme( get_option( 'home' ) );
    $build_home_url = static function ( string $code ) use ( $home, $default ): string {
        return $home . ( $code === $default ? '/' : "/{$code}/" );
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
            $append_switcher_item( $switcher, $code, $data, $build_home_url( $code ) );
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

            $append_switcher_item( $switcher, $code, $data, $url, $has_translation );
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
                    $url = $translated_link;
                }

                $append_switcher_item( $switcher, $code, $data, $url, $has_translation );
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

            $append_switcher_item( $switcher, $code, $data, $url, $has_translation );
        }

        return $switcher;
    }

    // Fallback: replace language prefix in current URL
    $uri_path = trim( parse_url( $_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH ), '/' );
    $segments = explode( '/', $uri_path );
    $uri_lang_prefix = array_key_exists( $segments[0] ?? '', $active ) ? $segments[0] : null;
    $clean_segments = $uri_lang_prefix ? array_slice( $segments, 1 ) : $segments;
    $clean_path = implode( '/', $clean_segments );

    foreach ( $active as $code => $data ) {
        $prefix = ( $code === $default ) ? '' : '/' . $code;
        $full_path = trim( $prefix . '/' . $clean_path, '/' );
        $url = $home . ( $full_path ? '/' . $full_path . '/' : '/' );

        if ( ! $fallback_to_home && ! empty( $clean_path ) ) {
            $url = $home . ( $full_path ? '/' . $full_path . '/' : '/' );
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
