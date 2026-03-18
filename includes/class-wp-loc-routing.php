<?php

if ( ! defined( 'ABSPATH' ) ) exit;

class WP_LOC_Routing {

    private static $current_lang = null;

    public function __construct() {
        add_filter( 'rewrite_rules_array', [ $this, 'add_rewrite_rules' ] );
        add_filter( 'query_vars', [ $this, 'register_query_vars' ] );
        add_filter( 'request', [ $this, 'handle_request' ] );
        add_action( 'template_redirect', [ $this, 'set_locale' ], 1 );
        add_filter( 'redirect_canonical', [ $this, 'prevent_lang_front_redirect' ], 10, 2 );
        add_filter( 'wp_unique_post_slug', [ $this, 'allow_duplicate_slugs' ], 99, 6 );

        add_filter( 'home_url', [ $this, 'filter_home_url' ], 10, 4 );
        add_filter( 'page_link', [ $this, 'add_lang_prefix_to_link' ], 10, 2 );
        add_filter( 'post_link', [ $this, 'add_lang_prefix_to_link' ], 10, 2 );
        add_filter( 'post_type_link', [ $this, 'add_lang_prefix_to_link' ], 10, 2 );
        add_action( 'init', [ $this, 'maybe_flush_rewrite_rules' ] );
    }

    /**
     * Add rewrite rules for language prefixes
     */
    public function add_rewrite_rules( array $rules ): array {
        $prefixed_rules = [];

        foreach ( WP_LOC_Languages::get_additional_languages() as $lang ) {
            $prefixed_rules[ "^{$lang}/?$" ] = 'index.php?lang=' . $lang . '&is_lang_front=1';

            foreach ( $rules as $regex => $query ) {
                if ( str_starts_with( $regex, '^(' . $lang . ')/' ) || str_starts_with( $regex, "^{$lang}/" ) ) {
                    continue;
                }

                $prefixed_regex = $regex === '.?'
                    ? "^{$lang}/?"
                    : '^' . $lang . '/' . ltrim( $regex, '^' );

                $separator = strpos( $query, '?' ) !== false ? '&' : '?';
                $prefixed_rules[ $prefixed_regex ] = $query . $separator . 'lang=' . $lang;
            }
        }

        return $prefixed_rules + $rules;
    }

    /**
     * Register lang query vars
     */
    public function register_query_vars( array $vars ): array {
        $vars[] = 'lang';
        $vars[] = 'is_lang_front';
        $vars[] = 'wp_loc_invalid_term_lang';
        return $vars;
    }

    /**
     * Handle request: resolve pagename + lang to correct post
     */
    public function handle_request( array $query_vars ): array {
        // Language front page
        if ( ! empty( $query_vars['lang'] ) && ! empty( $query_vars['is_lang_front'] ) ) {
            $query_vars['page_id'] = get_option( 'page_on_front' );
            unset( $query_vars['pagename'] );
            return $query_vars;
        }

        $active_languages = WP_LOC_Languages::get_active_languages();
        $uri_lang = null;
        $uri = trim( parse_url( $_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH ), '/' );
        $parts = explode( '/', $uri );
        $first = $parts[0] ?? '';

        if ( array_key_exists( $first, $active_languages ) ) {
            $uri_lang = $first;
        }

        if ( empty( $query_vars['lang'] ) && $uri_lang ) {
            $query_vars['lang'] = $uri_lang;
        }

        if ( $uri_lang && ( $uri === $uri_lang || $uri === $uri_lang . '/' ) ) {
            $query_vars['is_lang_front'] = 1;
            $query_vars['page_id'] = get_option( 'page_on_front' );
            unset( $query_vars['pagename'] );
            return $query_vars;
        }

        $effective_lang = isset( $query_vars['lang'] ) && $query_vars['lang']
            ? (string) $query_vars['lang']
            : WP_LOC_Languages::get_default_language();

        // Resolve pagename with language
        if ( isset( $query_vars['pagename'] ) && $effective_lang ) {
            $slug = $query_vars['pagename'];
            $lang_slug = $effective_lang;

            if ( isset( $active_languages[ $lang_slug ] ) ) {
                global $wpdb;
                $table = WP_LOC::instance()->db->get_table();

                // Try translatable posts first (match by slug + language)
                $post_id = $wpdb->get_var( $wpdb->prepare(
                    "SELECT p.ID FROM {$wpdb->posts} p
                     INNER JOIN {$table} t ON t.element_id = p.ID AND t.element_type = CONCAT('post_', p.post_type)
                     WHERE p.post_name = %s
                       AND t.language_code = %s
                       AND p.post_status NOT IN ('trash', 'auto-draft')
                     LIMIT 1",
                    $slug,
                    $lang_slug
                ) );

                // Fallback: non-translatable post types (not in icl_translations at all)
                if ( ! $post_id ) {
                    $post_id = $wpdb->get_var( $wpdb->prepare(
                        "SELECT p.ID FROM {$wpdb->posts} p
                         LEFT JOIN {$table} t ON t.element_id = p.ID AND t.element_type = CONCAT('post_', p.post_type)
                         WHERE p.post_name = %s
                           AND t.element_id IS NULL
                           AND p.post_status NOT IN ('trash', 'auto-draft')
                         LIMIT 1",
                        $slug
                    ) );
                }

                if ( $post_id ) {
                    $query_vars['page_id'] = $post_id;
                    unset( $query_vars['pagename'] );
                }
            }
        }

        $query_vars = $this->resolve_translated_term_request( $query_vars, $effective_lang );

        return $query_vars;
    }

    /**
     * Resolve taxonomy archives by language-aware term slug/path.
     */
    private function resolve_translated_term_request( array $query_vars, string $lang ): array {
        $taxonomies_to_check = [];

        if ( isset( $query_vars['category_name'] ) ) {
            $taxonomies_to_check['category'] = (string) $query_vars['category_name'];
        }

        if ( isset( $query_vars['tag'] ) ) {
            $taxonomies_to_check['post_tag'] = (string) $query_vars['tag'];
        }

        foreach ( WP_LOC_Terms::get_translatable_taxonomies() as $taxonomy ) {
            if ( isset( $query_vars[ $taxonomy ] ) ) {
                $taxonomies_to_check[ $taxonomy ] = (string) $query_vars[ $taxonomy ];
            }
        }

        if ( isset( $query_vars['taxonomy'], $query_vars['term'] ) ) {
            $taxonomies_to_check[ (string) $query_vars['taxonomy'] ] = (string) $query_vars['term'];
        }

        foreach ( $taxonomies_to_check as $taxonomy => $path ) {
            if ( ! WP_LOC_Terms::is_translatable( $taxonomy ) || $path === '' ) {
                continue;
            }

            $term = WP_LOC_Terms::find_term_by_path_and_language( $path, $taxonomy, $lang );

            if ( ! $term ) {
                $query_vars['wp_loc_invalid_term_lang'] = 1;
                continue;
            }

            if ( $taxonomy === 'category' ) {
                $query_vars['cat'] = (int) $term->term_id;
                unset( $query_vars['category_name'] );
                continue;
            }

            if ( $taxonomy === 'post_tag' ) {
                $query_vars['tag_id'] = (int) $term->term_id;
                unset( $query_vars['tag'] );
                continue;
            }

            $query_vars['tax_query'] = [
                [
                    'taxonomy' => $taxonomy,
                    'field'    => 'term_id',
                    'terms'    => [ (int) $term->term_id ],
                ],
            ];

            unset( $query_vars['taxonomy'], $query_vars['term'], $query_vars[ $taxonomy ] );
        }

        return $query_vars;
    }

    /**
     * Switch locale on frontend based on detected language
     */
    public function set_locale(): void {
        if ( is_admin() ) return;

        $slug = self::get_current_lang();
        $locale = WP_LOC_Languages::get_language_locale( $slug );

        switch_to_locale( $locale );

        if ( get_query_var( 'wp_loc_invalid_term_lang' ) ) {
            global $wp_query;
            $wp_query->set_404();
            status_header( 404 );
            nocache_headers();
            return;
        }

        // Verify post language matches URL language (only for translatable post types)
        if ( is_singular() ) {
            $post_id = get_queried_object_id();
            $post_type = get_post_type( $post_id );

            if ( ! WP_LOC_Admin_Settings::is_translatable( $post_type ) ) return;

            $element_type = WP_LOC_DB::post_element_type( $post_type );
            $post_lang = WP_LOC::instance()->db->get_element_language( $post_id, $element_type );

            if ( $post_lang && $post_lang !== $slug ) {
                global $wp_query;
                $wp_query->set_404();
                status_header( 404 );
                nocache_headers();
            }
        }
    }

    /**
     * Prevent canonical redirect on language front pages
     */
    public function prevent_lang_front_redirect( $redirect_url, $requested_url ) {
        if ( get_query_var( 'is_lang_front' ) ) {
            return false;
        }

        if ( get_option( 'show_on_front' ) === 'page' ) {
            $current_lang = self::get_current_lang();
            $default_lang = WP_LOC_Languages::get_default_language();

            if ( $current_lang !== $default_lang ) {
                $posts_page_id = (int) get_option( 'page_for_posts' );

                if ( $posts_page_id ) {
                    $posts_page_url = get_permalink( $posts_page_id );
                    $requested_path = wp_parse_url( $requested_url, PHP_URL_PATH );
                    $posts_page_path = wp_parse_url( $posts_page_url, PHP_URL_PATH );

                    if ( $requested_path && $posts_page_path && untrailingslashit( $requested_path ) === untrailingslashit( $posts_page_path ) ) {
                        return false;
                    }
                }

                if ( is_home() && ! is_front_page() ) {
                    return false;
                }
            }
        }

        return $redirect_url;
    }

    /**
     * Allow same slugs across different languages
     */
    public function allow_duplicate_slugs( $slug, $post_id, $post_status, $post_type, $post_parent, $original_slug ) {
        if ( ! WP_LOC_Admin_Settings::is_translatable( $post_type ) ) return $slug;

        $element_type = WP_LOC_DB::post_element_type( $post_type );
        $lang = WP_LOC::instance()->db->get_element_language( $post_id, $element_type );

        if ( ! $lang ) return $slug;

        return $this->generate_unique_slug_for_lang( $original_slug, $post_type, $lang, $post_id );
    }

    /**
     * Generate unique slug within a language
     */
    private function generate_unique_slug_for_lang( string $slug, string $post_type, string $lang, int $post_id = 0 ): string {
        global $wpdb;
        $table = WP_LOC::instance()->db->get_table();

        $base_slug = $slug;
        $suffix = 1;

        do {
            $test_slug = ( $suffix === 1 ) ? $base_slug : "{$base_slug}-{$suffix}";

            $exists = $wpdb->get_var( $wpdb->prepare(
                "SELECT p.ID
                 FROM {$wpdb->posts} p
                 INNER JOIN {$table} t ON t.element_id = p.ID AND t.element_type = %s
                 WHERE p.post_name = %s
                   AND p.post_type = %s
                   AND p.ID != %d
                   AND t.language_code = %s
                   AND p.post_status NOT IN ('trash', 'auto-draft')
                 LIMIT 1",
                WP_LOC_DB::post_element_type( $post_type ),
                $test_slug,
                $post_type,
                $post_id,
                $lang
            ) );

            $suffix++;
        } while ( $exists );

        return ( $suffix === 2 ) ? $base_slug : "{$base_slug}-" . ( $suffix - 1 );
    }

    /**
     * Add current language prefix to home_url() on frontend.
     */
    public function filter_home_url( $url, $path, $scheme, $blog_id ) {
        if ( is_admin() ) {
            return $url;
        }

        $current = self::get_current_lang();
        $default = WP_LOC_Languages::get_default_language();

        if ( $current === $default ) {
            return $url;
        }

        $raw_home = set_url_scheme( get_option( 'home' ), $scheme );

        // Avoid double-prefixing
        $prefixed = rtrim( $raw_home, '/' ) . '/' . $current;
        if ( str_starts_with( $url, $prefixed . '/' ) || $url === $prefixed ) {
            return $url;
        }

        return str_replace( $raw_home, $prefixed, $url );
    }

    /**
     * Add language prefix to post/page permalinks for non-default languages.
     *
     * Since home_url() already adds the current language prefix,
     * we strip it first and then add the correct one for the post's language.
     */
    public function add_lang_prefix_to_link( $url, $post_id ) {
        if ( $post_id instanceof \WP_Post ) {
            $post_id = $post_id->ID;
        }

        $post_type = get_post_type( $post_id );
        if ( ! $post_type || ! WP_LOC_Admin_Settings::is_translatable( $post_type ) ) {
            return $url;
        }

        $element_type = WP_LOC_DB::post_element_type( $post_type );
        $post_lang = WP_LOC::instance()->db->get_element_language( (int) $post_id, $element_type );

        if ( ! $post_lang ) {
            return $url;
        }

        $default = WP_LOC_Languages::get_default_language();
        $url_scheme = wp_parse_url( $url, PHP_URL_SCHEME );
        $raw_home = set_url_scheme( get_option( 'home' ), $url_scheme ?: null );

        // Strip any existing language prefix (injected by home_url filter)
        foreach ( WP_LOC_Languages::get_additional_languages() as $lang_code ) {
            $prefixed = rtrim( $raw_home, '/' ) . '/' . $lang_code;
            if ( str_starts_with( $url, $prefixed . '/' ) || $url === $prefixed ) {
                $url = str_replace( $prefixed, rtrim( $raw_home, '/' ), $url );
                break;
            }
        }

        // Default language posts get no prefix
        if ( $post_lang === $default ) {
            return $url;
        }

        // Add the correct prefix for the post's language
        return str_replace( rtrim( $raw_home, '/' ), rtrim( $raw_home, '/' ) . '/' . $post_lang, $url );
    }

    /**
     * Get current language slug (the central function)
     */
    public static function get_current_lang(): string {
        if ( self::$current_lang !== null ) {
            return self::$current_lang;
        }

        $active = WP_LOC_Languages::get_active_languages();
        if ( empty( $active ) ) {
            return self::$current_lang = 'en';
        }

        // 1. From query var
        $lang = get_query_var( 'lang' );

        // 2. Fallback: parse from URI
        if ( ! $lang ) {
            $uri = trim( parse_url( $_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH ), '/' );
            $parts = explode( '/', $uri );
            $first = $parts[0] ?? '';

            if ( array_key_exists( $first, $active ) ) {
                $lang = $first;
            }
        }

        // 3. Fallback: default language
        if ( ! $lang || ! isset( $active[ $lang ] ) ) {
            $lang = WP_LOC_Languages::get_default_language();
        }

        return self::$current_lang = $lang;
    }

    /**
     * Get current locale
     */
    public static function get_current_locale(): string {
        return WP_LOC_Languages::get_language_locale( self::get_current_lang() );
    }

    /**
     * Flush rewrite rules if flagged
     */
    public function maybe_flush_rewrite_rules(): void {
        if ( get_option( 'wp_loc_flush_rewrite_rules' ) ) {
            flush_rewrite_rules();
            delete_option( 'wp_loc_flush_rewrite_rules' );
        }
    }

    /**
     * Reset static cache (useful for testing)
     */
    public static function flush(): void {
        self::$current_lang = null;
    }
}

/**
 * Global helper: get current language slug
 */
function wp_loc_get_current_lang(): string {
    return WP_LOC_Routing::get_current_lang();
}

/**
 * Global helper: get current locale
 */
function wp_loc_get_current_locale(): string {
    return WP_LOC_Routing::get_current_locale();
}
