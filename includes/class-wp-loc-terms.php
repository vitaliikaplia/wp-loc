<?php

if ( ! defined( 'ABSPATH' ) ) exit;

class WP_LOC_Terms {

    private static bool $adjusting_term = false;
    private static bool $translating_term_link = false;
    private static bool $cascading_delete = false;
    private static bool $creating_translations = false;
    private static bool $syncing_parent = false;
    private static ?string $term_link_lang_override = null;

    public function __construct() {
        add_action( 'created_term', [ $this, 'register_term_language' ], 10, 3 );
        add_action( 'edited_term', [ $this, 'register_term_language' ], 10, 3 );
        add_action( 'created_term', [ $this, 'auto_create_term_translations' ], 20, 3 );
        add_action( 'edited_term', [ $this, 'sync_term_parent_translations' ], 20, 3 );
        add_action( 'admin_init', [ $this, 'guard_protected_term_deletion' ] );
        add_action( 'admin_init', [ $this, 'normalize_bulk_delete_request' ], 1 );
        add_action( 'delete_term', [ $this, 'cascade_delete_term_translations' ], 5, 5 );
        add_action( 'delete_term', [ $this, 'delete_term_language' ], 10, 4 );

        add_action( 'wp_ajax_wp_loc_create_term_translation', [ $this, 'ajax_create_term_translation' ] );
        add_action( 'wp_ajax_wp_loc_refresh_term_translations', [ $this, 'ajax_refresh_term_translations' ] );
        add_action( 'wp_ajax_wp_loc_translate_term_name', [ $this, 'ajax_translate_term_name' ] );
        add_action( 'admin_notices', [ $this, 'render_protected_term_delete_notice' ] );
        add_action( 'current_screen', [ $this, 'register_admin_ui' ] );
        add_action( 'pre_get_posts', [ $this, 'translate_term_queries' ] );
        add_filter( 'get_terms', [ $this, 'sort_admin_terms_by_default_language_name' ], 10, 4 );
        add_filter( 'terms_clauses', [ $this, 'filter_terms_clauses' ], 10, 3 );
        add_filter( 'wp_unique_term_slug', [ $this, 'allow_duplicate_term_slugs' ], 99, 3 );
        add_filter( 'wp_insert_term_duplicate_term_check', [ $this, 'filter_duplicate_term_check' ], 10, 5 );
        add_filter( 'get_term', [ $this, 'adjust_term_to_current_language' ], 1, 2 );
        add_filter( 'get_edit_term_link', [ $this, 'add_lang_to_edit_term_link' ], 10, 4 );
        add_filter( 'term_link', [ $this, 'translate_term_link' ], 10, 3 );
    }

    /**
     * Get translatable taxonomies.
     */
    public static function get_translatable_taxonomies(): array {
        $default_taxonomies = [ 'category', 'post_tag' ];

        return apply_filters( 'wp_loc_translatable_taxonomies', $default_taxonomies );
    }

    /**
     * Build a raw admin edit URL for a term without relying on get_edit_term_link(),
     * which internally calls get_term() and can be affected by language filters.
     */
    public static function get_admin_edit_term_url( int $term_id, string $taxonomy, ?string $lang = null ): string {
        $args = [
            'taxonomy' => $taxonomy,
            'tag_ID'   => $term_id,
        ];

        if ( isset( $_GET['post_type'] ) ) {
            $args['post_type'] = sanitize_key( $_GET['post_type'] );
        }

        if ( $lang ) {
            $args['wp_loc_lang'] = $lang;
        }

        return add_query_arg( $args, admin_url( 'term.php' ) );
    }

    /**
     * Check if taxonomy is translatable.
     */
    public static function is_translatable( string $taxonomy ): bool {
        return in_array( $taxonomy, self::get_translatable_taxonomies(), true );
    }

    /**
     * Check whether a term belongs to the protected default-term translation group.
     */
    public static function is_protected_term( int $term_id, string $taxonomy ): bool {
        $default_term_id = 0;

        if ( $taxonomy === 'category' ) {
            $default_term_id = (int) get_option( 'default_category' );
        } else {
            $taxonomy_object = get_taxonomy( $taxonomy );

            if ( ! empty( $taxonomy_object->default_term ) ) {
                $default_term_id = (int) get_option( 'default_term_' . $taxonomy );
            }
        }

        if ( ! $default_term_id ) {
            return false;
        }

        if ( $term_id === $default_term_id ) {
            return true;
        }

        if ( ! self::is_translatable( $taxonomy ) ) {
            return false;
        }

        $default_term_taxonomy_id = self::get_term_taxonomy_id( $default_term_id, $taxonomy );
        $term_taxonomy_id = self::get_term_taxonomy_id( $term_id, $taxonomy );

        if ( ! $default_term_taxonomy_id || ! $term_taxonomy_id ) {
            return false;
        }

        $db = WP_LOC::instance()->db;
        $element_type = WP_LOC_DB::tax_element_type( $taxonomy );

        return $db->get_trid( $default_term_taxonomy_id, $element_type ) === $db->get_trid( $term_taxonomy_id, $element_type );
    }

    /**
     * Get term_taxonomy_id by term_id.
     */
    public static function get_term_taxonomy_id( int $term_id, string $taxonomy ): ?int {
        global $wpdb;

        $term_taxonomy_id = $wpdb->get_var( $wpdb->prepare(
            "SELECT term_taxonomy_id FROM {$wpdb->term_taxonomy} WHERE term_id = %d AND taxonomy = %s LIMIT 1",
            $term_id,
            $taxonomy
        ) );

        return $term_taxonomy_id ? (int) $term_taxonomy_id : null;
    }

    /**
     * Get term_id by term_taxonomy_id.
     */
    public static function get_term_id_from_taxonomy_id( int $term_taxonomy_id, string $taxonomy ): ?int {
        global $wpdb;

        $term_id = $wpdb->get_var( $wpdb->prepare(
            "SELECT term_id FROM {$wpdb->term_taxonomy} WHERE term_taxonomy_id = %d AND taxonomy = %s LIMIT 1",
            $term_taxonomy_id,
            $taxonomy
        ) );

        return $term_id ? (int) $term_id : null;
    }

    /**
     * Get current language for term context.
     */
    public static function get_context_language(): string {
        return is_admin() ? wp_loc_get_admin_lang() : wp_loc_get_current_lang();
    }

    /**
     * Get term translations keyed by language for a term_id.
     *
     * @return array<string,\stdClass>
     */
    public static function get_term_translations( int $term_id, string $taxonomy ): array {
        $term_taxonomy_id = self::get_term_taxonomy_id( $term_id, $taxonomy );
        if ( ! $term_taxonomy_id ) {
            return [];
        }

        $db = WP_LOC::instance()->db;
        $element_type = WP_LOC_DB::tax_element_type( $taxonomy );
        $trid = $db->get_trid( $term_taxonomy_id, $element_type );

        if ( ! $trid ) {
            return [];
        }

        return $db->get_element_translations( $trid, $element_type );
    }

    /**
     * Translate a term_id to another language.
     */
    public static function get_term_translation( int $term_id, string $taxonomy, string $target_lang ): ?int {
        $term_taxonomy_id = self::get_term_taxonomy_id( $term_id, $taxonomy );
        if ( ! $term_taxonomy_id ) return null;

        $translated_term_taxonomy_id = WP_LOC::instance()->db->get_element_translation(
            $term_taxonomy_id,
            WP_LOC_DB::tax_element_type( $taxonomy ),
            $target_lang
        );

        if ( ! $translated_term_taxonomy_id ) {
            return null;
        }

        return self::get_term_id_from_taxonomy_id( $translated_term_taxonomy_id, $taxonomy );
    }

    /**
     * Find a term by slug, taxonomy, parent and language.
     */
    public static function find_term_by_slug_and_language( string $slug, string $taxonomy, string $lang, int $parent = 0 ): ?\WP_Term {
        global $wpdb;

        if ( ! self::is_translatable( $taxonomy ) ) {
            $term = get_term_by( 'slug', $slug, $taxonomy );
            return $term instanceof \WP_Term ? $term : null;
        }

        $table = WP_LOC::instance()->db->get_table();
        $term_id = $wpdb->get_var( $wpdb->prepare(
            "SELECT t.term_id
             FROM {$wpdb->terms} t
             INNER JOIN {$wpdb->term_taxonomy} tt
                ON tt.term_id = t.term_id
               AND tt.taxonomy = %s
               AND tt.parent = %d
             INNER JOIN {$table} tr
                ON tr.element_id = tt.term_taxonomy_id
               AND tr.element_type = %s
               AND tr.language_code = %s
             WHERE t.slug = %s
             LIMIT 1",
            $taxonomy,
            $parent,
            WP_LOC_DB::tax_element_type( $taxonomy ),
            $lang,
            $slug
        ) );

        if ( ! $term_id ) {
            return null;
        }

        self::$adjusting_term = true;
        $term = get_term( (int) $term_id, $taxonomy );
        self::$adjusting_term = false;

        return $term instanceof \WP_Term ? $term : null;
    }

    /**
     * Resolve a hierarchical term path to a term in a specific language.
     */
    public static function find_term_by_path_and_language( string $path, string $taxonomy, string $lang ): ?\WP_Term {
        $segments = array_values( array_filter( array_map( 'sanitize_title', explode( '/', trim( $path, '/' ) ) ) ) );

        if ( empty( $segments ) ) {
            return null;
        }

        $parent = 0;
        $term = null;

        foreach ( $segments as $segment ) {
            $term = self::find_term_by_slug_and_language( $segment, $taxonomy, $lang, $parent );

            if ( ! $term ) {
                return null;
            }

            $parent = (int) $term->term_id;
        }

        return $term;
    }

    /**
     * Generate a unique placeholder term name for an auto-created translation.
     */
    private static function generate_unique_term_name( \WP_Term $term, string $taxonomy, string $lang_slug, int $parent = 0 ): string {
        $base_name = sprintf(
            '%s (%s)',
            $term->name,
            WP_LOC_Languages::get_display_name( $lang_slug )
        );

        $candidate = $base_name;
        $suffix = 2;

        while ( term_exists( $candidate, $taxonomy, $parent ) ) {
            $candidate = "{$base_name} {$suffix}";
            $suffix++;
        }

        return $candidate;
    }

    /**
     * Generate a unique slug for an auto-created term translation.
     */
    private static function generate_unique_term_slug( \WP_Term $term, string $taxonomy, string $lang_slug ): string {
        $base_slug = sanitize_title( $term->slug ?: $term->name );
        $base_slug = $base_slug ? "{$base_slug}-{$lang_slug}" : $lang_slug;
        $candidate = $base_slug;
        $suffix = 2;

        while ( get_term_by( 'slug', $candidate, $taxonomy ) ) {
            $candidate = "{$base_slug}-{$suffix}";
            $suffix++;
        }

        return $candidate;
    }

    /**
     * Get the intended language while saving or creating a term.
     */
    private static function get_term_save_language( string $taxonomy, int $term_id = 0 ): string {
        $active = WP_LOC_Languages::get_active_languages();

        if ( ! $term_id && ! empty( $_REQUEST['tax_ID'] ) ) {
            $term_id = (int) $_REQUEST['tax_ID'];
        }

        if ( ! $term_id && ! empty( $_REQUEST['tag_ID'] ) ) {
            $term_id = (int) $_REQUEST['tag_ID'];
        }

        foreach ( [ 'wp_loc_lang', 'lang' ] as $key ) {
            $candidate = isset( $_REQUEST[ $key ] ) ? sanitize_key( (string) $_REQUEST[ $key ] ) : '';

            if ( $candidate && isset( $active[ $candidate ] ) ) {
                return $candidate;
            }
        }

        if ( $term_id ) {
            $existing_lang = self::get_term_language( $term_id, $taxonomy );

            if ( $existing_lang ) {
                return $existing_lang;
            }
        }

        return self::get_context_language();
    }

    /**
     * Check whether a term slug already exists within a specific language context.
     */
    private static function term_slug_exists_in_language( string $slug, string $taxonomy, string $lang, int $parent = 0, int $exclude_term_id = 0 ): bool {
        global $wpdb;

        $table = WP_LOC::instance()->db->get_table();
        $sql = "SELECT t.term_id
                FROM {$wpdb->terms} t
                INNER JOIN {$wpdb->term_taxonomy} tt
                    ON tt.term_id = t.term_id
                   AND tt.taxonomy = %s
                   AND tt.parent = %d
                INNER JOIN {$table} tr
                    ON tr.element_id = tt.term_taxonomy_id
                   AND tr.element_type = %s
                   AND tr.language_code = %s
                WHERE t.slug = %s";

        $params = [
            $taxonomy,
            $parent,
            WP_LOC_DB::tax_element_type( $taxonomy ),
            $lang,
            $slug,
        ];

        if ( $exclude_term_id > 0 ) {
            $sql .= ' AND t.term_id != %d';
            $params[] = $exclude_term_id;
        }

        $sql .= ' LIMIT 1';

        return (bool) $wpdb->get_var( $wpdb->prepare( $sql, ...$params ) );
    }

    /**
     * Generate a unique term slug within a language.
     */
    private static function generate_unique_slug_for_language( string $slug, string $taxonomy, string $lang, int $parent = 0, int $exclude_term_id = 0 ): string {
        $base_slug = sanitize_title( $slug );
        $candidate = $base_slug;
        $suffix = 2;

        while ( self::term_slug_exists_in_language( $candidate, $taxonomy, $lang, $parent, $exclude_term_id ) ) {
            $candidate = _truncate_post_slug( $base_slug, 200 - ( strlen( (string) $suffix ) + 1 ) ) . "-{$suffix}";
            $suffix++;
        }

        return $candidate;
    }

    /**
     * Get language code for a term.
     */
    public static function get_term_language( int $term_id, string $taxonomy ): ?string {
        $term_taxonomy_id = self::get_term_taxonomy_id( $term_id, $taxonomy );
        if ( ! $term_taxonomy_id ) return null;

        return WP_LOC::instance()->db->get_element_language(
            $term_taxonomy_id,
            WP_LOC_DB::tax_element_type( $taxonomy )
        );
    }

    /**
     * Get a translated term object.
     */
    public static function get_translated_term( int $term_id, string $taxonomy, ?string $target_lang = null ): ?\WP_Term {
        $target_lang = $target_lang ?: self::get_context_language();
        $translated_term_id = self::get_term_translation( $term_id, $taxonomy, $target_lang );

        if ( ! $translated_term_id ) {
            return null;
        }

        $term = get_term( $translated_term_id, $taxonomy );

        return $term instanceof \WP_Term ? $term : null;
    }

    /**
     * Build a frontend URL for a term in a specific language.
     */
    public static function get_term_url_for_language( int $term_id, string $taxonomy, string $target_lang ): string {
        if ( ! self::is_translatable( $taxonomy ) ) {
            $term_link = get_term_link( $term_id, $taxonomy );
            return is_wp_error( $term_link ) ? '' : $term_link;
        }

        $translated_term_id = self::get_term_translation( $term_id, $taxonomy, $target_lang );

        if ( ! $translated_term_id ) {
            $default = WP_LOC_Languages::get_default_language();
            return home_url( $target_lang === $default ? '/' : "/{$target_lang}/" );
        }

        self::$adjusting_term = true;
        $translated_term = get_term( $translated_term_id, $taxonomy );
        self::$adjusting_term = false;

        if ( ! $translated_term instanceof \WP_Term ) {
            return '';
        }

        $previous_override = self::$term_link_lang_override;
        self::$term_link_lang_override = $target_lang;
        self::$adjusting_term = true;
        $term_link = get_term_link( $translated_term, $taxonomy );
        self::$adjusting_term = false;
        self::$term_link_lang_override = $previous_override;

        return is_wp_error( $term_link ) ? '' : $term_link;
    }

    /**
     * Register term language in icl_translations.
     */
    public function register_term_language( int $term_id, int $term_taxonomy_id, string $taxonomy ): void {
        if ( ! self::is_translatable( $taxonomy ) ) return;
        if ( self::$creating_translations ) return;

        $db = WP_LOC::instance()->db;
        $element_type = WP_LOC_DB::tax_element_type( $taxonomy );

        if ( $db->get_element_language( $term_taxonomy_id, $element_type ) ) {
            return;
        }

        $trid = isset( $_REQUEST['trid'] ) ? (int) $_REQUEST['trid'] : null;
        $source_lang = null;

        if ( ! empty( $_REQUEST['wp_loc_source_term'] ) ) {
            $source_term_id = (int) $_REQUEST['wp_loc_source_term'];
            $source_term_taxonomy_id = self::get_term_taxonomy_id( $source_term_id, $taxonomy );

            if ( $source_term_taxonomy_id ) {
                $source_lang = $db->get_element_language( $source_term_taxonomy_id, $element_type );
                $trid = $trid ?: $db->get_trid( $source_term_taxonomy_id, $element_type );
            }
        }

        $language_code = sanitize_text_field( $_REQUEST['lang'] ?? '' );
        if ( ! $language_code ) {
            $language_code = self::get_context_language();
        }

        $db->set_element_language( $term_taxonomy_id, $element_type, $language_code, $trid, $source_lang );
    }

    /**
     * Auto-create missing translations for a newly created term.
     */
    public function auto_create_term_translations( int $term_id, int $term_taxonomy_id, string $taxonomy ): void {
        if ( ! self::is_translatable( $taxonomy ) || self::$creating_translations ) {
            return;
        }

        if ( ! $this->should_auto_create_term_translations() ) {
            return;
        }

        $term = get_term( $term_id, $taxonomy );
        if ( ! $term instanceof \WP_Term ) {
            return;
        }

        $db = WP_LOC::instance()->db;
        $element_type = WP_LOC_DB::tax_element_type( $taxonomy );
        $current_lang = $db->get_element_language( $term_taxonomy_id, $element_type ) ?: self::get_term_save_language( $taxonomy, $term_id );
        $trid = $db->get_trid( $term_taxonomy_id, $element_type );

        if ( ! $trid ) {
            $trid = $db->set_element_language( $term_taxonomy_id, $element_type, $current_lang );
        }

        $existing = $db->get_element_translations( $trid, $element_type );
        $langs_to_create = array_diff(
            array_keys( WP_LOC_Languages::get_active_languages() ),
            array_keys( $existing )
        );

        if ( empty( $langs_to_create ) ) {
            return;
        }

        self::$creating_translations = true;

        foreach ( $langs_to_create as $lang_slug ) {
            if ( $lang_slug === $current_lang ) {
                continue;
            }

            $parent = 0;
            if ( is_taxonomy_hierarchical( $taxonomy ) && $term->parent ) {
                $translated_parent = self::get_term_translation( (int) $term->parent, $taxonomy, $lang_slug );
                $parent = $translated_parent ?: 0;
            }

            $previous_lang = $_REQUEST['lang'] ?? null;
            $previous_wp_loc_lang = $_REQUEST['wp_loc_lang'] ?? null;
            $_REQUEST['lang'] = $lang_slug;
            $_REQUEST['wp_loc_lang'] = $lang_slug;

            $inserted = wp_insert_term( $term->name, $taxonomy, [
                'description' => $term->description,
                'parent'      => $parent,
                'slug'        => $term->slug,
            ] );

            if ( $previous_lang !== null ) {
                $_REQUEST['lang'] = $previous_lang;
            } else {
                unset( $_REQUEST['lang'] );
            }

            if ( $previous_wp_loc_lang !== null ) {
                $_REQUEST['wp_loc_lang'] = $previous_wp_loc_lang;
            } else {
                unset( $_REQUEST['wp_loc_lang'] );
            }

            if ( is_wp_error( $inserted ) || empty( $inserted['term_taxonomy_id'] ) ) {
                continue;
            }

            $db->set_element_language(
                (int) $inserted['term_taxonomy_id'],
                $element_type,
                $lang_slug,
                $trid,
                $current_lang
            );
        }

        self::$creating_translations = false;
    }

    /**
     * Remove term language data when term is deleted.
     */
    public function delete_term_language( int $term_id, int $term_taxonomy_id, string $taxonomy, $deleted_term ): void {
        if ( ! self::is_translatable( $taxonomy ) ) return;

        WP_LOC::instance()->db->delete_element( $term_taxonomy_id, WP_LOC_DB::tax_element_type( $taxonomy ) );
    }

    /**
     * Keep translated parent terms in sync across all term translations.
     */
    public function sync_term_parent_translations( int $term_id, int $term_taxonomy_id, string $taxonomy ): void {
        if ( self::$syncing_parent || ! self::is_translatable( $taxonomy ) || ! is_taxonomy_hierarchical( $taxonomy ) ) {
            return;
        }

        $term = get_term( $term_id, $taxonomy );
        if ( ! $term instanceof \WP_Term ) {
            return;
        }

        $db = WP_LOC::instance()->db;
        $element_type = WP_LOC_DB::tax_element_type( $taxonomy );
        $trid = $db->get_trid( $term_taxonomy_id, $element_type );

        if ( ! $trid ) {
            return;
        }

        $translations = $db->get_element_translations( $trid, $element_type );
        if ( count( $translations ) < 2 ) {
            return;
        }

        $source_lang = $db->get_element_language( $term_taxonomy_id, $element_type ) ?: self::get_term_save_language( $taxonomy, $term_id );
        $source_parent = (int) $term->parent;

        self::$syncing_parent = true;

        foreach ( $translations as $lang_slug => $translation ) {
            $translated_term_taxonomy_id = (int) $translation->element_id;
            $translated_term_id = self::get_term_id_from_taxonomy_id( $translated_term_taxonomy_id, $taxonomy );

            if ( ! $translated_term_id || $translated_term_id === $term_id ) {
                continue;
            }

            $target_parent = 0;
            if ( $source_parent ) {
                $target_parent = $lang_slug === $source_lang
                    ? $source_parent
                    : ( self::get_term_translation( $source_parent, $taxonomy, $lang_slug ) ?: 0 );
            }

            $previous_lang = $_REQUEST['lang'] ?? null;
            $previous_wp_loc_lang = $_REQUEST['wp_loc_lang'] ?? null;
            $_REQUEST['lang'] = $lang_slug;
            $_REQUEST['wp_loc_lang'] = $lang_slug;

            self::$adjusting_term = true;
            wp_update_term( $translated_term_id, $taxonomy, [ 'parent' => $target_parent ] );
            self::$adjusting_term = false;

            if ( $previous_lang !== null ) {
                $_REQUEST['lang'] = $previous_lang;
            } else {
                unset( $_REQUEST['lang'] );
            }

            if ( $previous_wp_loc_lang !== null ) {
                $_REQUEST['wp_loc_lang'] = $previous_wp_loc_lang;
            } else {
                unset( $_REQUEST['wp_loc_lang'] );
            }
        }

        self::$syncing_parent = false;
    }

    /**
     * Cascade term deletion to all translations in the same trid.
     */
    public function cascade_delete_term_translations( int $term_id, int $term_taxonomy_id, string $taxonomy, $deleted_term, array $object_ids ): void {
        if ( self::$cascading_delete || ! self::is_translatable( $taxonomy ) ) {
            return;
        }

        $db = WP_LOC::instance()->db;
        $element_type = WP_LOC_DB::tax_element_type( $taxonomy );
        $trid = $db->get_trid( $term_taxonomy_id, $element_type );

        if ( ! $trid ) {
            return;
        }

        $translations = $db->get_element_translations( $trid, $element_type );

        if ( count( $translations ) < 2 ) {
            return;
        }

        self::$cascading_delete = true;
        $original_admin_lang_cookie = $_COOKIE['admin_lang'] ?? null;

        foreach ( $translations as $translation ) {
            $translated_term_taxonomy_id = (int) $translation->element_id;

            if ( $translated_term_taxonomy_id === $term_taxonomy_id ) {
                continue;
            }

            $translated_term_id = self::get_term_id_from_taxonomy_id( $translated_term_taxonomy_id, $taxonomy );

            if ( ! $translated_term_id ) {
                continue;
            }

            $translation_lang = (string) ( $translation->language_code ?? '' );
            if ( $translation_lang ) {
                $translation_locale = WP_LOC_Languages::get_language_locale( $translation_lang );
                $_COOKIE['admin_lang'] = $translation_locale;
            }

            wp_delete_term( $translated_term_id, $taxonomy );
        }

        if ( $original_admin_lang_cookie !== null ) {
            $_COOKIE['admin_lang'] = $original_admin_lang_cookie;
        } else {
            unset( $_COOKIE['admin_lang'] );
        }

        self::$cascading_delete = false;
    }

    /**
     * Filter term queries by current language.
     */
    public function filter_terms_clauses( array $clauses, array $taxonomies, array $args ): array {
        if ( empty( $taxonomies ) || $this->should_skip_terms_filter() ) {
            return $clauses;
        }

        $translatable_taxonomies = array_values( array_filter(
            $taxonomies,
            fn( $taxonomy ) => self::is_translatable( $taxonomy )
        ) );

        if ( empty( $translatable_taxonomies ) ) {
            return $clauses;
        }

        $lang = $args['lang'] ?? null;

        if ( ! $lang && $this->is_term_save_request() ) {
            $primary_taxonomy = is_array( $translatable_taxonomies ) ? (string) reset( $translatable_taxonomies ) : '';

            if ( $primary_taxonomy ) {
                $lang = self::get_term_save_language( $primary_taxonomy );
            }
        }

        $lang = $lang ?: self::get_context_language();

        if ( ! $lang || $lang === 'all' ) {
            return $clauses;
        }

        global $wpdb;

        $element_types = array_map(
            fn( $taxonomy ) => 'tax_' . $taxonomy,
            $translatable_taxonomies
        );

        $quoted_element_types = "'" . implode( "','", array_map( 'esc_sql', $element_types ) ) . "'";
        $translations_table = WP_LOC::instance()->db->get_table();

        $clauses['join'] .= " LEFT JOIN {$translations_table} wp_loc_terms_tr
            ON wp_loc_terms_tr.element_id = tt.term_taxonomy_id
            AND wp_loc_terms_tr.element_type IN ({$quoted_element_types})";

        $clauses['where'] .= $wpdb->prepare(
            " AND (
                ( wp_loc_terms_tr.element_type IN ({$quoted_element_types}) AND wp_loc_terms_tr.language_code = %s )
                OR wp_loc_terms_tr.element_type IS NULL
            )",
            $lang
        );

        return $clauses;
    }

    /**
     * Use default-language term names as the stable sort key in the admin term list.
     */
    private function should_sort_admin_terms_by_default_language_name( array $taxonomies, array $args ): bool {
        if ( ! is_admin() ) {
            return false;
        }

        global $pagenow;

        if ( $pagenow !== 'edit-tags.php' ) {
            return false;
        }

        $requested_taxonomy = sanitize_key( $_REQUEST['taxonomy'] ?? '' );

        if ( ! $requested_taxonomy || ! in_array( $requested_taxonomy, $taxonomies, true ) ) {
            return false;
        }

        $orderby = (string) ( $args['orderby'] ?? '' );

        return $orderby === '' || $orderby === 'name';
    }

    /**
     * Stabilize admin term list ordering across languages using the default-language term name.
     */
    public function sort_admin_terms_by_default_language_name( $terms, $taxonomies, $args, $term_query ) {
        if ( ! is_array( $terms ) || empty( $terms ) ) {
            return $terms;
        }

        $taxonomies = (array) $taxonomies;
        $translatable_taxonomies = array_values( array_filter(
            $taxonomies,
            fn( $taxonomy ) => self::is_translatable( (string) $taxonomy )
        ) );

        if ( empty( $translatable_taxonomies ) || ! $this->should_sort_admin_terms_by_default_language_name( $translatable_taxonomies, (array) $args ) ) {
            return $terms;
        }

        if ( ( $args['fields'] ?? 'all' ) !== 'all' ) {
            return $terms;
        }

        $order = strtoupper( (string) ( $args['order'] ?? 'ASC' ) ) === 'DESC' ? 'DESC' : 'ASC';
        $default_lang = WP_LOC_Languages::get_default_language();
        $name_cache = [];

        usort( $terms, function ( $a, $b ) use ( $default_lang, $order, &$name_cache ) {
            if ( ! $a instanceof \WP_Term || ! $b instanceof \WP_Term ) {
                return 0;
            }

            $a_key = $this->get_default_language_sort_name( $a, $default_lang, $name_cache );
            $b_key = $this->get_default_language_sort_name( $b, $default_lang, $name_cache );
            $comparison = strcasecmp( $a_key, $b_key );

            if ( $comparison === 0 ) {
                $comparison = strcasecmp( $a->name, $b->name );
            }

            if ( $comparison === 0 ) {
                $comparison = $a->term_id <=> $b->term_id;
            }

            return $order === 'DESC' ? -$comparison : $comparison;
        } );

        return $terms;
    }

    /**
     * Resolve the default-language term name used as the stable admin sort key.
     */
    private function get_default_language_sort_name( \WP_Term $term, string $default_lang, array &$cache ): string {
        $cache_key = $term->taxonomy . ':' . $term->term_id;

        if ( isset( $cache[ $cache_key ] ) ) {
            return $cache[ $cache_key ];
        }

        $sort_name = $term->name;

        if ( self::is_translatable( $term->taxonomy ) ) {
            $default_term = self::get_translated_term( (int) $term->term_id, $term->taxonomy, $default_lang );

            if ( $default_term instanceof \WP_Term ) {
                $sort_name = $default_term->name;
            }
        }

        $cache[ $cache_key ] = (string) $sort_name;

        return $cache[ $cache_key ];
    }

    /**
     * Allow the same term slug in different languages.
     */
    public function allow_duplicate_term_slugs( string $slug, $term, string $original_slug ): string {
        if ( ! is_object( $term ) || empty( $term->taxonomy ) || ! self::is_translatable( $term->taxonomy ) ) {
            return $slug;
        }

        if ( $slug === $original_slug ) {
            return $slug;
        }

        $term_id = ! empty( $term->term_id ) ? (int) $term->term_id : 0;
        $parent = ! empty( $term->parent ) ? (int) $term->parent : 0;
        $lang = self::get_term_save_language( $term->taxonomy, $term_id );

        return self::generate_unique_slug_for_language( $original_slug, $term->taxonomy, $lang, $parent, $term_id );
    }

    /**
     * Skip the final duplicate-term check when the colliding slug belongs to another language.
     */
    public function filter_duplicate_term_check( $duplicate_term, string $term, string $taxonomy, array $args, int $tt_id ) {
        if ( ! $duplicate_term || ! self::is_translatable( $taxonomy ) ) {
            return $duplicate_term;
        }

        $term_id = $tt_id ? ( self::get_term_id_from_taxonomy_id( $tt_id, $taxonomy ) ?: 0 ) : 0;
        $lang = self::get_term_save_language( $taxonomy, $term_id );
        $parent = isset( $args['parent'] ) ? (int) $args['parent'] : 0;

        if ( ! self::term_slug_exists_in_language( $duplicate_term->slug, $taxonomy, $lang, $parent, $term_id ) ) {
            return null;
        }

        return $duplicate_term;
    }

    /**
     * Skip term SQL filtering for internal/core lookups that should remain raw.
     */
    private function should_skip_terms_filter(): bool {
        $trace = debug_backtrace( DEBUG_BACKTRACE_IGNORE_ARGS, 20 );
        $functions = array_map(
            static function ( array $frame ): string {
                return $frame['function'] ?? '';
            },
            $trace
        );

        foreach ( [ '_get_term_hierarchy', 'wp_get_object_terms' ] as $function ) {
            if ( in_array( $function, $functions, true ) ) {
                return true;
            }
        }

        if ( in_array( 'get_term_by', $functions, true ) && ! $this->is_term_save_request() ) {
            return true;
        }

        return false;
    }

    /**
     * Whether the current admin request is creating or updating a term.
     */
    private function is_term_save_request(): bool {
        if ( strtoupper( $_SERVER['REQUEST_METHOD'] ?? '' ) !== 'POST' ) {
            return false;
        }

        $taxonomy = sanitize_key( $_REQUEST['taxonomy'] ?? '' );

        if ( ! $taxonomy || ! self::is_translatable( $taxonomy ) ) {
            return false;
        }

        $action = sanitize_key( $_REQUEST['action'] ?? '' );

        return in_array( $action, [ 'editedtag', 'add-tag', 'inline-save-tax' ], true );
    }

    /**
     * Whether the current term creation request should auto-create sibling translations.
     */
    private function should_auto_create_term_translations(): bool {
        if ( strtoupper( $_SERVER['REQUEST_METHOD'] ?? '' ) !== 'POST' ) {
            return false;
        }

        if ( ! empty( $_REQUEST['wp_loc_source_term'] ) || ! empty( $_REQUEST['trid'] ) ) {
            return false;
        }

        $action = sanitize_key( $_REQUEST['action'] ?? '' );

        return $action === 'add-tag';
    }

    /**
     * Register admin UI hooks for translatable taxonomy screens.
     */
    public function register_admin_ui( $screen ): void {
        if ( ! $screen || empty( $screen->taxonomy ) || ! self::is_translatable( $screen->taxonomy ) ) {
            return;
        }

        $taxonomy = $screen->taxonomy;

        if ( $screen->base === 'edit-tags' ) {
            add_filter( "manage_edit-{$taxonomy}_columns", [ $this, 'add_terms_translation_column' ] );
            add_filter( "manage_{$taxonomy}_custom_column", [ $this, 'render_terms_translation_column' ], 10, 3 );
            add_filter( 'manage_categories_columns', [ $this, 'filter_category_checkbox_column' ] );
            add_filter( "{$taxonomy}_row_actions", [ $this, 'filter_term_row_actions' ], 10, 2 );
            add_action( "{$taxonomy}_add_form_fields", [ $this, 'render_term_translation_add_form' ] );
            add_action( 'quick_edit_custom_box', [ $this, 'render_term_quick_edit_hidden_fields' ], 10, 3 );
        }

        if ( $screen->base === 'term' ) {
            add_action( "{$taxonomy}_term_edit_form_top", [ $this, 'render_term_edit_hidden_fields' ], 10, 2 );
            add_action( "{$taxonomy}_edit_form_fields", [ $this, 'render_term_translation_edit_form' ], 20, 2 );
        }
    }

    /**
     * Remove the Delete row action for protected default-term translations.
     */
    public function filter_term_row_actions( array $actions, \WP_Term $term ): array {
        if ( self::is_protected_term( (int) $term->term_id, $term->taxonomy ) ) {
            unset( $actions['delete'] );
        }

        $targets = $this->get_term_translate_targets( $term );

        if ( ! empty( $targets ) ) {
            $actions['wp_loc_translate_term_name'] = sprintf(
                '<a href="#" class="wp-loc-translate-term-name" data-term-id="%1$d" data-taxonomy="%2$s" data-current-title="%3$s" data-targets="%4$s">%5$s</a>',
                (int) $term->term_id,
                esc_attr( $term->taxonomy ),
                esc_attr( $term->name ),
                esc_attr( wp_json_encode( $targets, JSON_UNESCAPED_UNICODE ) ),
                esc_html__( 'Translate term name', 'wp-loc' )
            );
        }

        return $actions;
    }

    private function get_term_translate_targets( \WP_Term $term ): array {
        if ( ! self::is_translatable( $term->taxonomy ) ) {
            return [];
        }

        $current_lang = self::get_term_language( (int) $term->term_id, $term->taxonomy ) ?: self::get_context_language();
        $translations = self::get_term_translations( (int) $term->term_id, $term->taxonomy );
        $targets = [];

        foreach ( WP_LOC_Languages::get_active_languages() as $slug => $data ) {
            $target_term_id = $slug === $current_lang
                ? (int) $term->term_id
                : self::get_term_id_from_taxonomy_id( (int) ( $translations[ $slug ]->element_id ?? 0 ), $term->taxonomy );

            if ( ! $target_term_id ) {
                continue;
            }

            $targets[] = [
                'lang'       => $slug,
                'name'       => WP_LOC_Languages::get_display_name( $slug ),
                'flag'       => WP_LOC_Languages::get_flag_url( $data['locale'] ?? $slug ),
                'is_current' => $slug === $current_lang,
            ];
        }

        return $targets;
    }

    /**
     * Replace the bulk checkbox column header for categories so protected terms are not bulk-selectable.
     */
    public function filter_category_checkbox_column( array $columns ): array {
        if ( empty( $_REQUEST['taxonomy'] ) || sanitize_key( $_REQUEST['taxonomy'] ) !== 'category' ) {
            return $columns;
        }

        if ( isset( $columns['cb'] ) ) {
            $columns['cb'] = '&nbsp;';
        }

        return $columns;
    }

    /**
     * Block direct deletion requests for protected default-term translations.
     */
    public function guard_protected_term_deletion(): void {
        if ( ! is_admin() || empty( $_REQUEST['taxonomy'] ) ) {
            return;
        }

        $taxonomy = sanitize_key( $_REQUEST['taxonomy'] );

        if ( ! taxonomy_exists( $taxonomy ) ) {
            return;
        }

        $action = sanitize_key( $_REQUEST['action'] ?? '' );

        if ( ! in_array( $action, [ 'delete', 'bulk-delete' ], true ) ) {
            return;
        }

        $term_ids = [];

        if ( $action === 'delete' && isset( $_REQUEST['tag_ID'] ) ) {
            $term_ids[] = (int) $_REQUEST['tag_ID'];
        }

        if ( $action === 'bulk-delete' && ! empty( $_REQUEST['delete_tags'] ) ) {
            $term_ids = array_map( 'intval', (array) $_REQUEST['delete_tags'] );
        }

        $protected_ids = array_values( array_filter(
            $term_ids,
            fn( int $term_id ): bool => self::is_protected_term( $term_id, $taxonomy )
        ) );

        if ( empty( $protected_ids ) ) {
            return;
        }

        $redirect = admin_url( 'edit-tags.php?taxonomy=' . $taxonomy );

        if ( isset( $_REQUEST['post_type'] ) ) {
            $redirect = add_query_arg( 'post_type', sanitize_key( $_REQUEST['post_type'] ), $redirect );
        }

        wp_safe_redirect( add_query_arg( 'wp_loc_term_delete_blocked', 1, $redirect ) );
        exit;
    }

    /**
     * Reduce bulk delete requests to one representative term per translation group.
     */
    public function normalize_bulk_delete_request(): void {
        if ( ! is_admin() || empty( $_REQUEST['taxonomy'] ) ) {
            return;
        }

        $taxonomy = sanitize_key( $_REQUEST['taxonomy'] );

        if ( ! self::is_translatable( $taxonomy ) ) {
            return;
        }

        $action = sanitize_key( $_REQUEST['action'] ?? '' );

        if ( $action !== 'delete' || empty( $_REQUEST['delete_tags'] ) || ! is_array( $_REQUEST['delete_tags'] ) ) {
            return;
        }

        $element_type = WP_LOC_DB::tax_element_type( $taxonomy );
        $db = WP_LOC::instance()->db;
        $seen_trids = [];
        $normalized_ids = [];

        foreach ( array_map( 'intval', (array) $_REQUEST['delete_tags'] ) as $term_id ) {
            if ( ! $term_id ) {
                continue;
            }

            $term_taxonomy_id = self::get_term_taxonomy_id( $term_id, $taxonomy );

            if ( ! $term_taxonomy_id ) {
                continue;
            }

            $trid = $db->get_trid( $term_taxonomy_id, $element_type );

            if ( $trid ) {
                if ( isset( $seen_trids[ $trid ] ) ) {
                    continue;
                }

                $seen_trids[ $trid ] = true;
            }

            $normalized_ids[] = $term_id;
        }

        $_REQUEST['delete_tags'] = $normalized_ids;
        $_POST['delete_tags'] = $normalized_ids;
    }

    /**
     * Show a notice when deletion of a protected default-term translation is blocked.
     */
    public function render_protected_term_delete_notice(): void {
        if ( empty( $_GET['wp_loc_term_delete_blocked'] ) ) {
            return;
        }

        echo '<div class="notice notice-warning is-dismissible"><p>' .
            esc_html__( 'This term cannot be deleted because it belongs to the default category translation group.', 'wp-loc' ) .
        '</p></div>';
    }

    /**
     * Add translations column to term list.
     */
    public function add_terms_translation_column( array $columns ): array {
        $new_columns = [];

        foreach ( $columns as $key => $label ) {
            $new_columns[ $key ] = $label;

            if ( $key === 'name' ) {
                $new_columns['wp_loc_translations'] = __( 'Translations', 'wp-loc' );
            }
        }

        return $new_columns;
    }

    /**
     * Render translations column in term list.
     */
    public function render_terms_translation_column( string $content, string $column_name, int $term_id ): string {
        if ( $column_name !== 'wp_loc_translations' ) {
            return $content;
        }

        $taxonomy = $_REQUEST['taxonomy'] ?? '';
        if ( ! $taxonomy || ! self::is_translatable( $taxonomy ) ) {
            return $content;
        }

        $translations = self::get_term_translations( $term_id, $taxonomy );
        $current_lang = self::get_context_language();
        $active = WP_LOC_Languages::get_active_languages();
        $other_langs = array_diff_key( $active, [ $current_lang => true ] );
        $items = [];

        foreach ( $other_langs as $slug => $data ) {
            $locale = $data['locale'] ?? $slug;
            $flag = WP_LOC_Languages::get_flag_url( $locale );
            $name = esc_attr( WP_LOC_Languages::get_display_name( $slug ) );
            $flag_img = '<img class="wp-loc-flag" src="' . esc_url( $flag ) . '" title="' . $name . '" />';
            $pencil = '<span class="wp-loc-pencil">✎</span>';

            if ( isset( $translations[ $slug ] ) ) {
                $translated_term_id = self::get_term_id_from_taxonomy_id( (int) $translations[ $slug ]->element_id, $taxonomy );
                $edit_link = $translated_term_id ? self::get_admin_edit_term_url( $translated_term_id, $taxonomy, $slug ) : '';

                if ( $edit_link ) {
                    $items[] = '<a href="' . esc_url( $edit_link ) . '" class="wp-loc-t wp-loc-t-published" title="' . esc_attr__( 'Edit translation', 'wp-loc' ) . '">' . $flag_img . $pencil . '</a>';
                    continue;
                }
            }

            $items[] = '<span class="wp-loc-t wp-loc-t-missing">' . $flag_img . '</span>';
        }

        return implode( '', $items );
    }

    /**
     * Render translation controls on term edit screen.
     */
    public function render_term_translation_edit_form( \WP_Term $term, string $taxonomy ): void {
        $active = WP_LOC_Languages::get_active_languages();
        if ( count( $active ) < 2 ) {
            return;
        }

        ?>
        <tr class="form-field">
            <th scope="row"><?php esc_html_e( 'Translations', 'wp-loc' ); ?></th>
            <td>
                <div id="wp-loc-term-translations" class="postbox">
                    <div class="inside">
                        <?php echo $this->get_term_translation_controls_html( $term, $taxonomy ); ?>
                    </div>
                </div>
            </td>
        </tr>
        <?php
    }

    /**
     * Build translation controls HTML for a term.
     */
    private function get_term_translation_controls_html( \WP_Term $term, string $taxonomy ): string {
        $active = WP_LOC_Languages::get_active_languages();
        $translations = self::get_term_translations( $term->term_id, $taxonomy );
        $current_lang = self::get_term_language( $term->term_id, $taxonomy ) ?: self::get_context_language();
        $db = WP_LOC::instance()->db;
        $term_taxonomy_id = self::get_term_taxonomy_id( $term->term_id, $taxonomy );
        $trid = $term_taxonomy_id ? $db->get_trid( $term_taxonomy_id, WP_LOC_DB::tax_element_type( $taxonomy ) ) : null;

        ob_start();
        ?>
        <ul class="wp-loc-translations-list">
            <?php foreach ( $active as $slug => $data ) : ?>
                <?php
                $locale = $data['locale'] ?? $slug;
                $flag = WP_LOC_Languages::get_flag_url( $locale );
                $is_active = $slug === $current_lang;
                $translated_term_taxonomy_id = isset( $translations[ $slug ] ) ? (int) $translations[ $slug ]->element_id : 0;
                $translated_term_id = $translated_term_taxonomy_id ? self::get_term_id_from_taxonomy_id( $translated_term_taxonomy_id, $taxonomy ) : 0;
                $edit_link = $translated_term_id ? self::get_admin_edit_term_url( $translated_term_id, $taxonomy, $slug ) : '';
                ?>
                <li<?php echo $is_active ? ' class="wp-loc-lang-active"' : ''; ?>>
                    <?php if ( $translated_term_id && $edit_link ) : ?>
                        <a href="<?php echo esc_url( $edit_link ); ?>" class="wp-loc-metabox-link" title="<?php echo esc_attr__( 'Edit translation', 'wp-loc' ); ?>">
                            <span class="wp-loc-t wp-loc-t-published" aria-hidden="true">
                                <img class="wp-loc-flag" src="<?php echo esc_url( $flag ); ?>" alt="" />
                                <span class="wp-loc-pencil">✎</span>
                            </span>
                            <span class="wp-loc-metabox-link-text"><?php echo esc_html( WP_LOC_Languages::get_display_name( $slug ) ); ?></span>
                        </a>
                    <?php else : ?>
                        <img class="wp-loc-flag-small" src="<?php echo esc_url( $flag ); ?>" alt="" />
                        <span class="wp-loc-lang-name-missing"><?php echo esc_html( WP_LOC_Languages::get_display_name( $slug ) ); ?></span>
                        <?php if ( $trid && ! $is_active ) : ?>
                            <button type="button"
                                    class="button button-small wp-loc-create-single-term-translation"
                                    data-term-id="<?php echo esc_attr( $term->term_id ); ?>"
                                    data-taxonomy="<?php echo esc_attr( $taxonomy ); ?>"
                                    data-lang="<?php echo esc_attr( $slug ); ?>">
                                +
                            </button>
                        <?php endif; ?>
                    <?php endif; ?>

                    <?php if ( $is_active ) : ?>
                        <span class="wp-loc-status-active">&#9679;</span>
                    <?php endif; ?>
                </li>
            <?php endforeach; ?>
        </ul>
        <?php

        return (string) ob_get_clean();
    }

    /**
     * AJAX: create a translation for a term in a target language.
     */
    public function ajax_create_term_translation(): void {
        check_ajax_referer( 'wp_loc_ajax', 'nonce' );

        $term_id = (int) ( $_POST['term_id'] ?? 0 );
        $taxonomy = sanitize_key( $_POST['taxonomy'] ?? '' );
        $lang_slug = sanitize_key( $_POST['lang'] ?? '' );

        if ( ! $term_id || ! $taxonomy || ! $lang_slug || ! taxonomy_exists( $taxonomy ) || ! self::is_translatable( $taxonomy ) ) {
            wp_send_json_error( [ 'message' => 'Missing parameters' ] );
        }

        $taxonomy_object = get_taxonomy( $taxonomy );
        $manage_cap = $taxonomy_object->cap->manage_terms ?? 'manage_categories';

        if ( ! current_user_can( $manage_cap ) ) {
            wp_send_json_error( [ 'message' => 'Permission denied' ] );
        }

        $term = get_term( $term_id, $taxonomy );
        if ( ! $term instanceof \WP_Term ) {
            wp_send_json_error( [ 'message' => 'Term not found' ] );
        }

        $db = WP_LOC::instance()->db;
        $element_type = WP_LOC_DB::tax_element_type( $taxonomy );
        $term_taxonomy_id = self::get_term_taxonomy_id( $term_id, $taxonomy );

        if ( ! $term_taxonomy_id ) {
            wp_send_json_error( [ 'message' => 'Missing term taxonomy ID' ] );
        }

        $source_lang = $db->get_element_language( $term_taxonomy_id, $element_type ) ?: self::get_context_language();
        $trid = $db->get_trid( $term_taxonomy_id, $element_type );

        if ( ! $trid ) {
            $trid = $db->set_element_language( $term_taxonomy_id, $element_type, $source_lang );
        }

        $existing = $db->get_element_translations( $trid, $element_type );
        if ( isset( $existing[ $lang_slug ] ) ) {
            $existing_term_id = self::get_term_id_from_taxonomy_id( (int) $existing[ $lang_slug ]->element_id, $taxonomy );

            wp_send_json_success( [
                'edit_url' => $existing_term_id ? self::get_admin_edit_term_url( $existing_term_id, $taxonomy, $lang_slug ) : '',
            ] );
        }

        $parent = 0;
        if ( is_taxonomy_hierarchical( $taxonomy ) && $term->parent ) {
            $translated_parent = self::get_term_translation( (int) $term->parent, $taxonomy, $lang_slug );
            $parent = $translated_parent ?: 0;
        }

        self::$creating_translations = true;
        $previous_lang = $_REQUEST['lang'] ?? null;
        $previous_wp_loc_lang = $_REQUEST['wp_loc_lang'] ?? null;
        $_REQUEST['lang'] = $lang_slug;
        $_REQUEST['wp_loc_lang'] = $lang_slug;

        $inserted = wp_insert_term( self::generate_unique_term_name( $term, $taxonomy, $lang_slug, $parent ), $taxonomy, [
            'description' => $term->description,
            'parent'      => $parent,
            'slug'        => $term->slug,
        ] );

        if ( $previous_lang !== null ) {
            $_REQUEST['lang'] = $previous_lang;
        } else {
            unset( $_REQUEST['lang'] );
        }

        if ( $previous_wp_loc_lang !== null ) {
            $_REQUEST['wp_loc_lang'] = $previous_wp_loc_lang;
        } else {
            unset( $_REQUEST['wp_loc_lang'] );
        }

        self::$creating_translations = false;

        if ( is_wp_error( $inserted ) || empty( $inserted['term_id'] ) || empty( $inserted['term_taxonomy_id'] ) ) {
            $message = is_wp_error( $inserted ) ? $inserted->get_error_message() : 'Failed to create term';
            wp_send_json_error( [ 'message' => $message ] );
        }

        $new_term_id = (int) $inserted['term_id'];
        $new_term_taxonomy_id = (int) $inserted['term_taxonomy_id'];

        $db->set_element_language( $new_term_taxonomy_id, $element_type, $lang_slug, $trid, $source_lang );

        wp_send_json_success( [
            'edit_url' => self::get_admin_edit_term_url( $new_term_id, $taxonomy, $lang_slug ),
        ] );
    }

    /**
     * AJAX: refresh the term translation controls block.
     */
    public function ajax_refresh_term_translations(): void {
        check_ajax_referer( 'wp_loc_ajax', 'nonce' );

        $term_id = (int) ( $_POST['term_id'] ?? 0 );
        $taxonomy = sanitize_key( $_POST['taxonomy'] ?? '' );

        if ( ! $term_id || ! $taxonomy || ! taxonomy_exists( $taxonomy ) ) {
            wp_send_json_error();
        }

        $taxonomy_object = get_taxonomy( $taxonomy );
        $manage_cap = $taxonomy_object->cap->manage_terms ?? 'manage_categories';

        if ( ! current_user_can( $manage_cap ) ) {
            wp_send_json_error();
        }

        $term = get_term( $term_id, $taxonomy );
        if ( ! $term instanceof \WP_Term ) {
            wp_send_json_error();
        }

        wp_send_json_success( [
            'html' => $this->get_term_translation_controls_html( $term, $taxonomy ),
        ] );
    }

    public function ajax_translate_term_name(): void {
        check_ajax_referer( 'wp_loc_ajax', 'nonce' );

        $term_id = isset( $_POST['term_id'] ) ? (int) $_POST['term_id'] : 0;
        $taxonomy = isset( $_POST['taxonomy'] ) ? sanitize_key( (string) $_POST['taxonomy'] ) : '';
        $target_lang = isset( $_POST['target_lang'] ) ? sanitize_key( (string) $_POST['target_lang'] ) : '';

        if ( ! $term_id || ! $taxonomy || ! $target_lang || ! taxonomy_exists( $taxonomy ) ) {
            wp_send_json_error( [ 'message' => __( 'Missing required parameters.', 'wp-loc' ) ], 400 );
        }

        $taxonomy_object = get_taxonomy( $taxonomy );
        $manage_cap = $taxonomy_object->cap->manage_terms ?? 'manage_categories';

        if ( ! current_user_can( $manage_cap ) ) {
            wp_send_json_error( [ 'message' => __( 'You do not have permission to do that.', 'wp-loc' ) ], 403 );
        }

        $term = get_term( $term_id, $taxonomy );
        if ( ! $term instanceof \WP_Term || ! self::is_translatable( $taxonomy ) ) {
            wp_send_json_error( [ 'message' => __( 'Term not found.', 'wp-loc' ) ], 404 );
        }

        $source_name = trim( (string) $term->name );
        if ( $source_name === '' ) {
            wp_send_json_error( [ 'message' => __( 'There is no term name to translate.', 'wp-loc' ) ], 400 );
        }

        $source_lang = self::get_term_language( (int) $term->term_id, $taxonomy ) ?: self::get_context_language();

        $target_term_id = $source_lang === $target_lang
            ? (int) $term->term_id
            : (int) self::get_term_translation( (int) $term->term_id, $taxonomy, $target_lang );

        if ( ! $target_term_id ) {
            wp_send_json_error( [ 'message' => __( 'Translation term was not found for the selected language.', 'wp-loc' ) ], 404 );
        }

        global $wpdb;

        $target_term_row = $wpdb->get_row( $wpdb->prepare(
            "SELECT t.term_id, t.slug, tt.parent
             FROM {$wpdb->terms} t
             INNER JOIN {$wpdb->term_taxonomy} tt
                ON tt.term_id = t.term_id
               AND tt.taxonomy = %s
             WHERE t.term_id = %d
             LIMIT 1",
            $taxonomy,
            $target_term_id
        ) );

        if ( ! $target_term_row ) {
            wp_send_json_error( [ 'message' => __( 'Translation term was not found for the selected language.', 'wp-loc' ) ], 404 );
        }

        $translated_name = trim( wp_strip_all_tags( WP_LOC_AI::translate_content( $source_name, WP_LOC_AI::get_target_language_name( $target_lang ) ) ) );

        if ( $translated_name === '' ) {
            wp_send_json_error( [ 'message' => __( 'Term name translation failed.', 'wp-loc' ) ], 500 );
        }

        $translated_slug = self::generate_unique_slug_for_language(
            $translated_name,
            $taxonomy,
            $target_lang,
            isset( $target_term_row->parent ) ? (int) $target_term_row->parent : 0,
            $target_term_id
        );

        $result = $wpdb->update(
            $wpdb->terms,
            [
                'name' => $translated_name,
                'slug' => $translated_slug,
            ],
            [ 'term_id' => $target_term_id ],
            [ '%s', '%s' ],
            [ '%d' ]
        );

        if ( $result === false ) {
            wp_send_json_error( [
                'message' => __( 'Term name translation failed.', 'wp-loc' ),
            ], 500 );
        }

        clean_term_cache( $target_term_id, $taxonomy );

        wp_send_json_success( [
            'message'     => __( 'Term name translated successfully.', 'wp-loc' ),
            'new_title'   => $translated_name,
            'new_slug'    => $translated_slug,
            'target_lang' => $target_lang,
        ] );
    }

    /**
     * Preserve translation context on the add-term form.
     */
    public function render_term_translation_add_form( string $taxonomy ): void {
        $trid = isset( $_GET['trid'] ) ? (int) $_GET['trid'] : 0;
        $lang = isset( $_GET['lang'] ) ? sanitize_key( $_GET['lang'] ) : self::get_context_language();
        $source_term = isset( $_GET['wp_loc_source_term'] ) ? (int) $_GET['wp_loc_source_term'] : 0;

        ?>
        <div class="form-field term-wp-loc-translation-context">
            <input type="hidden" name="lang" value="<?php echo esc_attr( $lang ); ?>" />
            <input type="hidden" name="wp_loc_lang" value="<?php echo esc_attr( $lang ); ?>" />
            <?php if ( $trid && $source_term ) : ?>
                <p>
                    <?php
                    printf(
                        esc_html__( 'Creating a translation in %s.', 'wp-loc' ),
                        esc_html( WP_LOC_Languages::get_display_name( $lang ) )
                    );
                    ?>
                </p>
                <input type="hidden" name="trid" value="<?php echo esc_attr( $trid ); ?>" />
                <input type="hidden" name="wp_loc_source_term" value="<?php echo esc_attr( $source_term ); ?>" />
            <?php endif; ?>
        </div>
        <?php
    }

    /**
     * Render hidden language fields on the term edit screen.
     */
    public function render_term_edit_hidden_fields( \WP_Term $term, string $taxonomy ): void {
        $lang = self::get_term_language( (int) $term->term_id, $taxonomy ) ?: self::get_context_language();

        echo '<input type="hidden" name="wp_loc_lang" value="' . esc_attr( $lang ) . '" />';
    }

    /**
     * Render hidden language fields for term quick edit.
     */
    public function render_term_quick_edit_hidden_fields( string $column_name, string $screen, string $taxonomy ): void {
        static $rendered = false;

        if ( $rendered || $screen !== 'edit-tags' || ! self::is_translatable( $taxonomy ) || $column_name !== 'wp_loc_translations' ) {
            return;
        }

        $rendered = true;

        echo '<input type="hidden" name="wp_loc_lang" value="' . esc_attr( self::get_context_language() ) . '" />';
    }

    /**
     * Translate taxonomy-related query vars to the current language.
     */
    public function translate_term_queries( \WP_Query $query ): void {
        if ( is_admin() || ! $query->is_main_query() ) {
            return;
        }

        $lang = $query->get( 'lang' ) ?: wp_loc_get_current_lang();
        if ( ! $lang ) {
            return;
        }

        $this->translate_tax_query_terms( $query, $lang );
        $this->translate_core_tax_query_vars( $query, $lang );
    }

    /**
     * Translate term IDs inside tax_query.
     */
    private function translate_tax_query_terms( \WP_Query $query, string $lang ): void {
        $tax_query = $query->get( 'tax_query' );

        if ( ! is_array( $tax_query ) ) {
            return;
        }

        $translate_clause = function ( array $clause ) use ( $lang, &$translate_clause ) {
            if ( isset( $clause['relation'] ) ) {
                foreach ( $clause as $key => $value ) {
                    if ( is_array( $value ) ) {
                        $clause[ $key ] = $translate_clause( $value );
                    }
                }

                return $clause;
            }

            $taxonomy = $clause['taxonomy'] ?? '';
            $field = $clause['field'] ?? 'term_id';

            if ( ! $taxonomy || ! self::is_translatable( $taxonomy ) ) {
                return $clause;
            }

            if ( ! in_array( $field, [ 'term_id', 'id' ], true ) ) {
                return $clause;
            }

            $terms = array_map( 'intval', (array) ( $clause['terms'] ?? [] ) );
            $translated_terms = [];

            foreach ( $terms as $term_id ) {
                $translated_terms[] = self::get_term_translation( $term_id, $taxonomy, $lang ) ?: $term_id;
            }

            $clause['terms'] = $translated_terms;

            return $clause;
        };

        foreach ( $tax_query as $key => $clause ) {
            if ( is_array( $clause ) ) {
                $tax_query[ $key ] = $translate_clause( $clause );
            }
        }

        $query->set( 'tax_query', $tax_query );
    }

    /**
     * Translate common core category/tag query vars.
     */
    private function translate_core_tax_query_vars( \WP_Query $query, string $lang ): void {
        $map = [
            'cat'               => 'category',
            'category__in'      => 'category',
            'category__and'     => 'category',
            'category__not_in'  => 'category',
            'tag_id'            => 'post_tag',
            'tag__in'           => 'post_tag',
            'tag__and'          => 'post_tag',
            'tag__not_in'       => 'post_tag',
        ];

        foreach ( $map as $query_var => $taxonomy ) {
            $value = $query->get( $query_var );

            if ( empty( $value ) || ! self::is_translatable( $taxonomy ) ) {
                continue;
            }

            $translated_value = $this->translate_query_var_term_ids( $value, $taxonomy, $lang );
            $query->set( $query_var, $translated_value );
        }
    }

    /**
     * Translate term IDs in a WP_Query var while preserving scalar/csv formats.
     */
    private function translate_query_var_term_ids( $value, string $taxonomy, string $lang ) {
        $is_csv = is_string( $value );
        $terms = $is_csv ? array_filter( array_map( 'trim', explode( ',', $value ) ) ) : (array) $value;

        $translated_terms = [];
        foreach ( $terms as $term_id ) {
            $sign = (int) $term_id < 0 ? -1 : 1;
            $absolute_term_id = abs( (int) $term_id );
            $translated_term_id = self::get_term_translation( $absolute_term_id, $taxonomy, $lang ) ?: $absolute_term_id;
            $translated_terms[] = $sign * $translated_term_id;
        }

        if ( $is_csv ) {
            return implode( ',', $translated_terms );
        }

        return is_scalar( $value ) ? ( $translated_terms[0] ?? 0 ) : $translated_terms;
    }

    /**
     * Adjust a term object to the current language.
     */
    public function adjust_term_to_current_language( $term, $taxonomy ) {
        if ( self::$adjusting_term || ! $term instanceof \WP_Term ) return $term;
        if ( is_admin() ) return $term;
        if ( ! $taxonomy || ! self::is_translatable( $taxonomy ) ) return $term;

        $disable_adjust = apply_filters( 'wpml_disable_term_adjust_id', false, $term );
        if ( $disable_adjust ) return $term;

        $target_lang = self::get_context_language();
        $translated_term_id = self::get_term_translation( (int) $term->term_id, $taxonomy, $target_lang );

        if ( ! $translated_term_id || $translated_term_id === (int) $term->term_id ) {
            return $term;
        }

        self::$adjusting_term = true;
        $translated_term = get_term( $translated_term_id, $taxonomy );
        self::$adjusting_term = false;

        return $translated_term instanceof \WP_Term ? $translated_term : $term;
    }

    /**
     * Add language context to edit-term links.
     */
    public function add_lang_to_edit_term_link( string $link, int $term_id, string $taxonomy, string $object_type ): string {
        if ( ! self::is_translatable( $taxonomy ) ) {
            return $link;
        }

        $lang = self::get_term_language( $term_id, $taxonomy );
        $default_lang = WP_LOC_Languages::get_default_language();
        $current_lang = self::get_context_language();
        $lang = $lang ?: $default_lang;

        if ( $lang !== $default_lang || $current_lang !== $default_lang ) {
            $link = add_query_arg( 'wp_loc_lang', $lang, $link );
        }

        return $link;
    }

    /**
     * Translate term links to the current language and add the URL prefix.
     */
    public function translate_term_link( string $termlink, $term, string $taxonomy ): string {
        if ( self::$translating_term_link || ! self::is_translatable( $taxonomy ) ) {
            return $termlink;
        }

        $term = is_object( $term ) ? $term : get_term( (int) $term, $taxonomy );
        if ( ! $term instanceof \WP_Term ) {
            return $termlink;
        }

        $term_lang = self::get_term_language( (int) $term->term_id, $taxonomy );
        $lang = self::$term_link_lang_override ?: ( is_admin() ? ( $term_lang ?: self::get_context_language() ) : wp_loc_get_current_lang() );
        $default = WP_LOC_Languages::get_default_language();

        if ( ! $lang ) {
            return $termlink;
        }

        $translated_term = null;

        if ( ! is_admin() ) {
            $translated_term = self::get_translated_term( (int) $term->term_id, $taxonomy, $lang );
        }

        if ( $translated_term && (int) $translated_term->term_id !== (int) $term->term_id ) {
            self::$adjusting_term = true;
            self::$translating_term_link = true;
            $termlink = get_term_link( $translated_term, $taxonomy );
            self::$translating_term_link = false;
            self::$adjusting_term = false;
        }

        if ( is_wp_error( $termlink ) ) {
            return '';
        }

        if ( $lang === $default ) {
            return $termlink;
        }

        $path = parse_url( $termlink, PHP_URL_PATH );
        if ( ! $path ) {
            return $termlink;
        }

        $normalized_path = '/' . ltrim( $path, '/' );

        if ( ! str_starts_with( $normalized_path, '/' . $lang . '/' ) ) {
            $normalized_path = '/' . $lang . $normalized_path;
        }

        return home_url( trailingslashit( ltrim( $normalized_path, '/' ) ) );
    }
}
