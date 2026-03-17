<?php

if ( ! defined( 'ABSPATH' ) ) exit;

class WP_LOC_Routing {

    private static $current_lang = null;

    public function __construct() {
        add_action( 'init', [ $this, 'add_rewrite_rules' ] );
        add_filter( 'query_vars', [ $this, 'register_query_vars' ] );
        add_filter( 'request', [ $this, 'handle_request' ] );
        add_action( 'template_redirect', [ $this, 'set_locale' ], 1 );
        add_filter( 'redirect_canonical', [ $this, 'prevent_lang_front_redirect' ], 10, 2 );
        add_filter( 'wp_unique_post_slug', [ $this, 'allow_duplicate_slugs' ], 99, 6 );

        add_action( 'init', [ $this, 'maybe_flush_rewrite_rules' ] );
    }

    /**
     * Add rewrite rules for language prefixes
     */
    public function add_rewrite_rules(): void {
        foreach ( WP_LOC_Languages::get_additional_languages() as $lang ) {
            add_rewrite_rule( "^{$lang}/?$", 'index.php?lang=' . $lang . '&is_lang_front=1', 'top' );
            add_rewrite_rule( "^{$lang}/(.*)?$", 'index.php?pagename=$matches[1]&lang=' . $lang, 'top' );
        }
    }

    /**
     * Register lang query vars
     */
    public function register_query_vars( array $vars ): array {
        $vars[] = 'lang';
        $vars[] = 'is_lang_front';
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

        // Resolve pagename with language
        if ( ! isset( $query_vars['pagename'] ) || ! isset( $query_vars['lang'] ) ) {
            return $query_vars;
        }

        $slug = $query_vars['pagename'];
        $lang_slug = $query_vars['lang'];

        $active = WP_LOC_Languages::get_active_languages();
        if ( ! isset( $active[ $lang_slug ] ) ) {
            return $query_vars;
        }

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
