<?php

if ( ! defined( 'ABSPATH' ) ) exit;

class WP_LOC_Routing {

    private static $current_lang = null;
    private const CURRENT_LANGUAGE_COOKIE = 'wp_loc_current_language';
    private const CURRENT_LOCALE_COOKIE = 'wp_loc_current_locale';
    private const WPML_CURRENT_LANGUAGE_COOKIES = [
        '_icl_current_language',
        'wp-wpml_current_language',
    ];

    public function __construct() {
        add_filter( 'rewrite_rules_array', [ $this, 'add_rewrite_rules' ] );
        add_filter( 'query_vars', [ $this, 'register_query_vars' ] );
        add_filter( 'request', [ $this, 'handle_request' ] );
        add_action( 'init', [ $this, 'bootstrap_ajax_language_context' ], 0 );
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
            $post_id = $this->resolve_pagename_to_post_id( (string) $query_vars['pagename'], $effective_lang, $active_languages );

            if ( $post_id ) {
                $query_vars = $this->set_resolved_singular_query_vars( $query_vars, $post_id );
                unset( $query_vars['pagename'] );
            }
        }

        if ( isset( $query_vars['name'] ) && $effective_lang ) {
            $post_id = $this->resolve_pagename_to_post_id( (string) $query_vars['name'], $effective_lang, $active_languages );

            if ( $post_id ) {
                $query_vars = $this->set_resolved_singular_query_vars( $query_vars, $post_id );
            }
        }

        $query_vars = $this->resolve_translated_term_request( $query_vars, $effective_lang );

        return $query_vars;
    }

    private function set_resolved_singular_query_vars( array $query_vars, int $post_id ): array {
        $post = get_post( $post_id );

        if ( ! $post instanceof \WP_Post ) {
            return $query_vars;
        }

        if ( $post->post_type === 'page' ) {
            $query_vars['page_id'] = $post_id;
        } else {
            $query_vars['p'] = $post_id;
            $query_vars['post_type'] = $post->post_type;
            $query_vars['name'] = $post->post_name;
        }

        return $query_vars;
    }

    /**
     * Resolve a pagename to the correct post ID in the requested language.
     */
    private function resolve_pagename_to_post_id( string $pagename, string $lang_slug, array $active_languages ): ?int {
        if ( ! isset( $active_languages[ $lang_slug ] ) ) {
            return null;
        }

        $normalized_path = trim( $pagename, '/' );

        if ( $normalized_path === '' ) {
            return null;
        }

        $hierarchical_post_types = get_post_types( [ 'hierarchical' => true ], 'names' );

        if ( ! empty( $hierarchical_post_types ) ) {
            $path_match = get_page_by_path( $normalized_path, OBJECT, array_values( $hierarchical_post_types ) );

            if ( $path_match instanceof \WP_Post ) {
                $matched_post_type = $path_match->post_type;

                if ( WP_LOC_Admin_Settings::is_translatable( $matched_post_type ) ) {
                    $matched_lang = WP_LOC::instance()->db->get_element_language( $path_match->ID, WP_LOC_DB::post_element_type( $matched_post_type ) );

                    if ( $matched_lang === $lang_slug ) {
                        return (int) $path_match->ID;
                    }
                } else {
                    return (int) $path_match->ID;
                }
            }
        }

        if ( str_contains( $normalized_path, '/' ) ) {
            return null;
        }

        global $wpdb;
        $table = WP_LOC::instance()->db->get_table();
        $db_lang_slug = WP_LOC_DB::to_db_language_code( $lang_slug ) ?: $lang_slug;

        $post_id = $wpdb->get_var( $wpdb->prepare(
            "SELECT p.ID FROM {$wpdb->posts} p
             INNER JOIN {$table} t ON t.element_id = p.ID AND t.element_type = CONCAT('post_', p.post_type)
             WHERE p.post_name = %s
               AND t.language_code = %s
               AND p.post_status NOT IN ('trash', 'auto-draft')
             LIMIT 1",
            $normalized_path,
            $db_lang_slug
        ) );

        if ( $post_id ) {
            return (int) $post_id;
        }

        $post_id = $wpdb->get_var( $wpdb->prepare(
            "SELECT p.ID FROM {$wpdb->posts} p
             LEFT JOIN {$table} t ON t.element_id = p.ID AND t.element_type = CONCAT('post_', p.post_type)
             WHERE p.post_name = %s
               AND t.element_id IS NULL
               AND p.post_status NOT IN ('trash', 'auto-draft')
             LIMIT 1",
            $normalized_path
        ) );

        return $post_id ? (int) $post_id : null;
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

        $this->persist_frontend_language_context( $slug );
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
        $db_lang = WP_LOC_DB::to_db_language_code( $lang ) ?: $lang;

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
                $db_lang
            ) );

            $suffix++;
        } while ( $exists );

        return ( $suffix === 2 ) ? $base_slug : "{$base_slug}-" . ( $suffix - 1 );
    }

    /**
     * Add current language prefix to home_url() on frontend.
     */
    public function filter_home_url( $url, $path, $scheme, $blog_id ) {
        if ( is_admin() || ( defined( 'WP_CLI' ) && WP_CLI ) ) {
            return $url;
        }

        static $filtering = false;
        if ( $filtering ) {
            return $url;
        }

        $filtering = true;
        $current = self::get_current_lang();
        $default = WP_LOC_Languages::get_default_language();

        if ( $current === $default ) {
            $filtering = false;
            return $url;
        }

        $raw_home = set_url_scheme( get_option( 'home' ), $scheme );

        // Avoid double-prefixing
        $prefixed = rtrim( $raw_home, '/' ) . '/' . $current;
        if ( str_starts_with( $url, $prefixed . '/' ) || $url === $prefixed ) {
            $filtering = false;
            return $url;
        }

        $url = str_replace( $raw_home, $prefixed, $url );
        $filtering = false;

        return $url;
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

        // 1. Explicit request language, including frontend calls to admin-ajax.php.
        $lang = self::get_request_language_context();

        // 2. From query var when the main query is available.
        global $wp_query;

        if ( ! $lang && $wp_query instanceof \WP_Query ) {
            $lang = get_query_var( 'lang' );
        }

        // 3. Fallback: parse from URI
        if ( ! $lang ) {
            $uri = trim( parse_url( $_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH ), '/' );
            $parts = explode( '/', $uri );
            $first = $parts[0] ?? '';

            if ( array_key_exists( $first, $active ) ) {
                $lang = $first;
            }
        }

        // 4. Frontend AJAX fallback: cookies first, then the referring frontend URL.
        if ( ! $lang && wp_doing_ajax() ) {
            $lang = self::get_ajax_language_context();
        }

        // 5. Fallback: default language
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

    public function bootstrap_ajax_language_context(): void {
        if ( ! wp_doing_ajax() ) {
            return;
        }

        $lang = self::get_request_language_context();
        if ( ! $lang ) {
            $lang = self::is_frontend_ajax_request() ? self::get_current_lang() : wp_loc_get_admin_lang();
        }

        $locale = WP_LOC_Languages::get_language_locale( $lang );

        if ( $locale ) {
            switch_to_locale( $locale );
        }
    }

    public static function is_frontend_ajax_request(): bool {
        if ( ! wp_doing_ajax() ) {
            return false;
        }

        $referer = wp_get_referer();

        if ( ! $referer && ! empty( $_SERVER['HTTP_REFERER'] ) ) {
            $referer = (string) wp_unslash( $_SERVER['HTTP_REFERER'] );
        }

        if ( $referer ) {
            $admin_url = admin_url();
            return ! str_starts_with( trailingslashit( $referer ), trailingslashit( $admin_url ) );
        }

        return (bool) self::get_cookie_language_context();
    }

    public static function normalize_language_context( ?string $candidate ): ?string {
        $candidate = trim( (string) $candidate );

        if ( $candidate === '' ) {
            return null;
        }

        $active = WP_LOC_Languages::get_active_languages();
        $slug = sanitize_key( strtolower( str_replace( '_', '-', $candidate ) ) );

        if ( isset( $active[ $slug ] ) ) {
            return $slug;
        }

        $from_db = WP_LOC_DB::from_db_language_code( $slug );
        if ( $from_db && isset( $active[ $from_db ] ) ) {
            return $from_db;
        }

        $locale_candidate = str_replace( '-', '_', $candidate );
        foreach ( $active as $active_slug => $data ) {
            $locale = (string) ( $data['locale'] ?? '' );
            if ( $locale && strtolower( $locale ) === strtolower( $locale_candidate ) ) {
                return (string) $active_slug;
            }

            $wpml_code = (string) ( $data['wpml_code'] ?? '' );
            if ( $wpml_code && sanitize_key( $wpml_code ) === $slug ) {
                return (string) $active_slug;
            }
        }

        return null;
    }

    private static function get_request_language_context(): ?string {
        foreach ( [ 'lang', 'wp_loc_lang', 'wpml_lang', '_wpml_lang', 'icl_language', 'ICL_LANGUAGE_CODE' ] as $key ) {
            if ( ! isset( $_REQUEST[ $key ] ) ) {
                continue;
            }

            $lang = self::normalize_language_context( sanitize_text_field( wp_unslash( $_REQUEST[ $key ] ) ) );
            if ( $lang ) {
                return $lang;
            }
        }

        return null;
    }

    private static function get_cookie_language_context(): ?string {
        $cookie_names = array_merge(
            [ self::CURRENT_LANGUAGE_COOKIE, self::CURRENT_LOCALE_COOKIE ],
            self::WPML_CURRENT_LANGUAGE_COOKIES
        );

        foreach ( $cookie_names as $cookie_name ) {
            if ( empty( $_COOKIE[ $cookie_name ] ) ) {
                continue;
            }

            $lang = self::normalize_language_context( sanitize_text_field( wp_unslash( $_COOKIE[ $cookie_name ] ) ) );
            if ( $lang ) {
                return $lang;
            }
        }

        return null;
    }

    private static function get_referer_language_context(): ?string {
        $referer = wp_get_referer();

        if ( ! $referer && ! empty( $_SERVER['HTTP_REFERER'] ) ) {
            $referer = (string) wp_unslash( $_SERVER['HTTP_REFERER'] );
        }

        if ( ! $referer ) {
            return null;
        }

        $path = trim( (string) wp_parse_url( $referer, PHP_URL_PATH ), '/' );
        $first = explode( '/', $path )[0] ?? '';

        return self::normalize_language_context( $first );
    }

    private static function get_ajax_language_context(): ?string {
        if ( ! self::is_frontend_ajax_request() ) {
            return null;
        }

        return self::get_cookie_language_context() ?: self::get_referer_language_context();
    }

    private function persist_frontend_language_context( string $slug ): void {
        if ( headers_sent() ) {
            return;
        }

        $active = WP_LOC_Languages::get_active_languages();
        if ( ! isset( $active[ $slug ] ) ) {
            return;
        }

        $locale = WP_LOC_Languages::get_language_locale( $slug );
        $compat_code = WP_LOC_DB::to_db_language_code( $slug ) ?: $slug;
        $expires = time() + MONTH_IN_SECONDS;
        $path = defined( 'COOKIEPATH' ) && COOKIEPATH ? COOKIEPATH : '/';
        $domain = defined( 'COOKIE_DOMAIN' ) && COOKIE_DOMAIN ? (string) COOKIE_DOMAIN : '';

        foreach ( [
            self::CURRENT_LANGUAGE_COOKIE => $slug,
            self::CURRENT_LOCALE_COOKIE => $locale,
            '_icl_current_language' => $compat_code,
            'wp-wpml_current_language' => $compat_code,
        ] as $cookie_name => $cookie_value ) {
            setcookie( $cookie_name, $cookie_value, $expires, $path, $domain );
            $_COOKIE[ $cookie_name ] = $cookie_value;
        }
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
