<?php

if ( ! defined( 'ABSPATH' ) ) exit;

class WP_LOC_Content {

    private static $creating_translations = false;
    private static $syncing = false;
    private static $deleting = false;

    /**
     * When true, the default new-post registration/auto-translation is suspended.
     * Used by the Duplicate Post integration, which registers copies explicitly.
     */
    public static $suspend_new_post_registration = false;

    /**
     * Get multilingual taxonomies that should be synced for a post type.
     *
     * @return string[]
     */
    private function get_syncable_taxonomies( string $post_type ): array {
        $post_type_taxonomies = get_object_taxonomies( $post_type, 'names' );
        $translatable_taxonomies = class_exists( 'WP_LOC_Terms' )
            ? WP_LOC_Terms::get_translatable_taxonomies()
            : [];

        return array_values( array_intersect( $post_type_taxonomies, $translatable_taxonomies ) );
    }

    /**
     * Build target-language term IDs from relationships that belong to the source language.
     *
     * Returns NULL when the source post has no terms in the taxonomy at all (or the
     * lookup failed): with nothing to map or strip, the caller must leave the target
     * untouched instead of replacing its terms with an empty set. Returns an array
     * (possibly empty) when the source HAS terms — an empty array then means "no
     * mappable translations", which legitimately clears the target because a
     * translated post must never fall back to source-language terms.
     *
     * @return int[]|null
     */
    private function get_translated_post_term_ids( int $post_id, string $taxonomy, string $target_lang, ?string $source_lang = null ): ?array {
        $source_term_ids = WP_LOC_Terms::without_language_filter(
            static fn() => wp_get_object_terms( $post_id, $taxonomy, [
                'fields' => 'ids',
            ] )
        );

        if ( is_wp_error( $source_term_ids ) || empty( $source_term_ids ) ) {
            return null;
        }

        $source_term_ids = array_values( array_unique( array_map( 'intval', $source_term_ids ) ) );

        if ( empty( $source_term_ids ) ) {
            return null;
        }

        $target_term_ids = [];

        foreach ( $source_term_ids as $source_term_id ) {
            $term_lang = WP_LOC_Terms::get_term_language( $source_term_id, $taxonomy );

            // A translated post must never retain a registered term from another language.
            if ( $source_lang && $term_lang && $term_lang !== $source_lang ) {
                continue;
            }

            // Legacy/unregistered terms are language-neutral until they are adopted.
            if ( ! $term_lang ) {
                $target_term_ids[] = $source_term_id;
                continue;
            }

            if ( $term_lang === $target_lang ) {
                $target_term_ids[] = $source_term_id;
                continue;
            }

            $translated_term_id = WP_LOC_Terms::get_term_translation( $source_term_id, $taxonomy, $target_lang );

            if ( $translated_term_id ) {
                $target_term_ids[] = $translated_term_id;
            }
        }

        return array_values( array_unique( array_map( 'intval', $target_term_ids ) ) );
    }

    /**
     * Sync multilingual taxonomy relationships from one post to all its translations.
     */
    private function sync_post_terms( int $post_id, \WP_Post $post, array $translations, ?string $source_lang = null, ?array $taxonomies = null ): void {
        if ( empty( $translations ) || ! class_exists( 'WP_LOC_Terms' ) ) {
            return;
        }

        $taxonomies = $taxonomies ?: $this->get_syncable_taxonomies( $post->post_type );

        if ( empty( $taxonomies ) ) {
            return;
        }

        foreach ( $translations as $lang_slug => $row ) {
            $target_post_id = (int) $row->element_id;

            foreach ( $taxonomies as $taxonomy ) {
                $target_term_ids = $this->get_translated_post_term_ids( $post_id, $taxonomy, $lang_slug, $source_lang );

                // NULL = the source has no terms in this taxonomy at all. An empty
                // source set must never be mirrored onto siblings: a save whose
                // language-scoped editor panel failed to see the assigned terms
                // submits an empty set, and replicating it silently wiped the
                // category relationships of every translation in the group
                // (measured in production). Clearing a sibling on purpose is done
                // by editing that sibling directly.
                if ( null === $target_term_ids ) {
                    continue;
                }

                $this->assign_post_terms_in_language( $target_post_id, $taxonomy, $target_term_ids, $lang_slug );
            }
        }
    }

    /**
     * Assign taxonomy terms in the target context while exposing all raw relationships
     * so WordPress can replace stale terms and resolve the exact translated IDs.
     *
     * @param int[] $term_ids
     */
    private function assign_post_terms_in_language( int $post_id, string $taxonomy, array $term_ids, string $lang_slug ): void {
        $previous_lang = $_REQUEST['lang'] ?? null;
        $previous_wp_loc_lang = $_REQUEST['wp_loc_lang'] ?? null;
        $previous_admin_lang_cookie = $_COOKIE['admin_lang'] ?? null;

        $_REQUEST['lang'] = $lang_slug;
        $_REQUEST['wp_loc_lang'] = $lang_slug;

        $target_locale = WP_LOC_Languages::get_language_locale( $lang_slug );
        if ( $target_locale ) {
            $_COOKIE['admin_lang'] = $target_locale;
        }

        try {
            WP_LOC_Terms::without_language_filter(
                static fn() => wp_set_object_terms( $post_id, $term_ids, $taxonomy, false )
            );
        } finally {
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

            if ( $previous_admin_lang_cookie !== null ) {
                $_COOKIE['admin_lang'] = $previous_admin_lang_cookie;
            } else {
                unset( $_COOKIE['admin_lang'] );
            }
        }
    }

    /**
     * Give a freshly created translation the source's terms, mapped to its language.
     *
     * Translation copies are inserted with the source's other data, but core assigns
     * them only the default category of the request's language. Syncing from the
     * source here keeps that stray term from being mirrored back onto the group, and
     * whatever the source did not replace is then dropped if it is in another language.
     */
    public function sync_terms_to_translation( int $source_id, int $translation_id, string $lang_slug ): void {
        $source = get_post( $source_id );
        $translation = get_post( $translation_id );

        if ( ! $source instanceof \WP_Post || ! $translation instanceof \WP_Post ) {
            return;
        }

        $translation_only = [ $lang_slug => (object) [ 'element_id' => $translation_id ] ];
        $was_syncing = self::$syncing;
        self::$syncing = true;

        try {
            if ( WP_LOC_Admin_Settings::should_sync_post_taxonomies() ) {
                $source_lang = WP_LOC::instance()->db->get_element_language( $source_id, WP_LOC_DB::post_element_type( $source->post_type ) );
                $this->sync_post_terms( $source_id, $source, $translation_only, $source_lang );
            }

            $this->sync_post_terms( $translation_id, $translation, $translation_only, $lang_slug );
            $this->ensure_default_category( $translation, $lang_slug );
        } finally {
            self::$syncing = $was_syncing;
        }
    }

    /**
     * Give a post whose own language just changed (Quick Edit or Bulk Edit) the terms of that
     * language: a term already in $lang_slug or without a language stays, a term of another
     * language becomes its $lang_slug translation or is dropped, and a 'post' left without a
     * category gets the default category's translation. Runs whatever the taxonomy sync setting,
     * since a post must never keep terms of another language.
     */
    public function move_post_terms_to_language( int $post_id, string $lang_slug ): void {
        $post = get_post( $post_id );

        if ( ! $post instanceof \WP_Post ) {
            return;
        }

        $was_syncing = self::$syncing;
        self::$syncing = true;

        try {
            $this->sync_post_terms( $post_id, $post, [ $lang_slug => (object) [ 'element_id' => $post_id ] ] );
            $this->ensure_default_category( $post, $lang_slug );
        } finally {
            self::$syncing = $was_syncing;
        }
    }

    /**
     * Parent for a translation of $post in $lang_slug: the parent's translation when it has one.
     *
     * The parent is looked up as its own post type, which need not be $post's: a WooCommerce
     * variation belongs to a product. When a parent of another type has no translation, the
     * translation keeps $current_parent, its own parent, instead of moving under the source's
     * parent in another language. A parent of the same type keeps the earlier fallback.
     */
    public function get_translated_parent_id( \WP_Post $post, string $lang_slug, ?int $current_parent = null ): int {
        if ( ! $post->post_parent ) {
            return 0;
        }

        $parent_type = get_post_type( $post->post_parent ) ?: $post->post_type;
        $translated_parent = WP_LOC::instance()->db->get_element_translation( $post->post_parent, WP_LOC_DB::post_element_type( $parent_type ), $lang_slug );

        if ( $translated_parent ) {
            return (int) $translated_parent;
        }

        if ( $parent_type !== $post->post_type && $current_parent !== null ) {
            return $current_parent;
        }

        return (int) $post->post_parent;
    }

    /**
     * A 'post' always has a category: core falls back to get_option( 'default_category' ),
     * the default-language term. While the request works in another language, core's
     * language-filtered term_exists() drops that term, so a new original in that language
     * ended up with no category. Assign the default category's translation instead.
     */
    private function ensure_default_category( \WP_Post $post, string $lang_slug ): void {
        if ( $post->post_type !== 'post' || ! class_exists( 'WP_LOC_Terms' ) || ! WP_LOC_Terms::is_translatable( 'category' ) ) {
            return;
        }

        $assigned = WP_LOC_Terms::without_language_filter(
            static fn() => wp_get_object_terms( $post->ID, 'category', [ 'fields' => 'ids' ] )
        );

        if ( is_wp_error( $assigned ) || ! empty( $assigned ) ) {
            return;
        }

        $default_category = (int) get_option( 'default_category' );
        $translated_default = $default_category ? WP_LOC_Terms::get_term_translation( $default_category, 'category', $lang_slug ) : null;

        if ( $translated_default ) {
            $this->assign_post_terms_in_language( $post->ID, 'category', [ (int) $translated_default ], $lang_slug );
        }
    }

    /**
     * Write a sibling post with the submitted editor form hidden.
     *
     * A sibling write inside the source post's save request fires a nested save_post
     * for the sibling while $_POST still holds the SOURCE post's form. Handlers that
     * do not compare $_POST['post_ID'] with the saved ID (All in One SEO's metabox
     * save) would store the source form onto the sibling. Synced values come from the
     * saved source post, never from $_POST.
     */
    private static function without_submitted_form( callable $write ) {
        $posted = $_POST;
        $_POST = [];

        try {
            return $write();
        } finally {
            $_POST = $posted;
        }
    }

    public function __construct() {
        add_action( 'wp_insert_post', [ $this, 'mark_new_post' ], 10, 3 );
        add_action( 'save_post', [ $this, 'handle_save_post' ], 20, 3 );
        add_action( 'save_post', [ $this, 'sync_translations' ], 30, 2 );
        add_action( 'set_object_terms', [ $this, 'handle_set_object_terms' ], 20, 4 );
        add_action( 'before_delete_post', [ $this, 'handle_delete_post' ] );
    }

    /**
     * Normalize and sync a multilingual taxonomy immediately after its relationships change.
     *
     * Gutenberg and REST assign terms after save_post, so save_post alone cannot reliably
     * keep translated posts synchronized or remove stale foreign-language relationships.
     */
    public function handle_set_object_terms( int $object_id, $terms, array $term_taxonomy_ids, string $taxonomy ): void {
        if ( self::$syncing || self::$creating_translations || self::$suspend_new_post_registration ) {
            return;
        }

        $post = get_post( $object_id );
        if ( ! $post instanceof \WP_Post ) {
            return;
        }

        $translatable = apply_filters( 'wp_loc_translatable_post_types', [ 'post', 'page' ] );
        if ( ! in_array( $post->post_type, $translatable, true ) || ! in_array( $taxonomy, $this->get_syncable_taxonomies( $post->post_type ), true ) ) {
            return;
        }

        $db = WP_LOC::instance()->db;
        $element_type = WP_LOC_DB::post_element_type( $post->post_type );
        $source_lang = $db->get_element_language( $object_id, $element_type );

        // New posts may receive terms before WP-LOC registers their language. save_post
        // performs the same normalization after registration in that request flow.
        if ( ! $source_lang ) {
            return;
        }

        $trid = $db->get_trid( $object_id, $element_type );
        $translations = $trid ? $db->get_element_translations( $trid, $element_type ) : [];

        if ( ! WP_LOC_Admin_Settings::should_sync_post_taxonomies() || empty( $translations ) ) {
            $translations = [
                $source_lang => (object) [
                    'element_id' => $object_id,
                ],
            ];
        }

        self::$syncing = true;

        try {
            $this->sync_post_terms( $object_id, $post, $translations, $source_lang, [ $taxonomy ] );
        } finally {
            self::$syncing = false;
        }
    }

    /**
     * Mark newly created posts
     */
    public function mark_new_post( int $post_id, \WP_Post $post, bool $update ): void {
        if ( $update || self::$creating_translations || self::$suspend_new_post_registration ) return;

        $translatable = apply_filters( 'wp_loc_translatable_post_types', [ 'post', 'page' ] );
        if ( ! in_array( $post->post_type, $translatable, true ) ) return;

        // The marker carries the language the editor was opened in: the first save of a
        // block-editor post is a REST request, and REST never gets the admin_lang cookie
        // (its path is /wp-admin), so the language must be fixed while post-new.php has it.
        global $pagenow;
        $marker = is_admin() && $pagenow === 'post-new.php' ? wp_loc_get_admin_lang() : 1;

        add_post_meta( $post_id, '_wp_loc_is_new', $marker, true );
    }

    /**
     * Language a new, not yet registered post was opened in on post-new.php.
     */
    public static function get_new_post_language( int $post_id ): ?string {
        $marker = get_post_meta( $post_id, '_wp_loc_is_new', true );

        if ( ! is_string( $marker ) || ! isset( WP_LOC_Languages::get_active_languages()[ $marker ] ) ) {
            return null;
        }

        return $marker;
    }

    /**
     * Handle post save — register in icl_translations and optionally create duplicates
     */
    public function handle_save_post( int $post_id, \WP_Post $post, bool $update ): void {
        if ( self::$creating_translations || self::$suspend_new_post_registration ) return;
        if ( wp_is_post_autosave( $post_id ) || wp_is_post_revision( $post_id ) ) return;
        if ( $post->post_status === 'auto-draft' ) return;

        $translatable = apply_filters( 'wp_loc_translatable_post_types', [ 'post', 'page' ] );
        if ( ! in_array( $post->post_type, $translatable, true ) ) return;

        $db = WP_LOC::instance()->db;
        $element_type = WP_LOC_DB::post_element_type( $post->post_type );

        // Already registered in icl_translations
        $existing_lang = $db->get_element_language( $post_id, $element_type );
        if ( $existing_lang ) return;

        // New post — register it
        $is_new = get_post_meta( $post_id, '_wp_loc_is_new', true );
        if ( ! $is_new ) return;

        $current_lang = self::get_new_post_language( $post_id ) ?? wp_loc_get_admin_lang();

        delete_post_meta( $post_id, '_wp_loc_is_new' );

        $db->set_element_language( $post_id, $element_type, $current_lang );
        $this->ensure_default_category( $post, $current_lang );

        if ( WP_LOC_Admin_Settings::should_auto_create_post_translations() ) {
            $this->create_translations( $post_id );
        }
    }

    /**
     * Create translation drafts for a post
     */
    /**
     * Create the missing translation copies of a post: all active languages, or only those in
     * $languages when given (the CLI backfill passes its --lang).
     *
     * @param string[] $languages
     */
    public function create_translations( int $post_id, array $languages = [] ): void {
        if ( self::$creating_translations ) return;
        self::$creating_translations = true;

        $post = get_post( $post_id );
        if ( ! $post ) {
            self::$creating_translations = false;
            return;
        }

        $db = WP_LOC::instance()->db;
        $element_type = WP_LOC_DB::post_element_type( $post->post_type );

        // Get or create trid for this post. The source is the post's own language: the admin
        // language differs from it in REST saves (no admin_lang cookie there).
        $current_lang = $db->get_element_language( $post_id, $element_type ) ?: wp_loc_get_admin_lang();
        $trid = $db->get_trid( $post_id, $element_type );

        if ( ! $trid ) {
            $trid = $db->set_element_language( $post_id, $element_type, $current_lang );
        }

        $meta = get_post_meta( $post_id );
        $thumbnail_id = get_post_thumbnail_id( $post_id );
        $additional = WP_LOC_Languages::get_additional_languages();

        // Also include default if current is not default
        $default = WP_LOC_Languages::get_default_language();
        $existing = $db->get_element_translations( $trid, $element_type );
        $langs_to_create = [];

        $active = WP_LOC_Languages::get_active_languages();
        foreach ( array_keys( $active ) as $slug ) {
            if ( $slug === $current_lang ) continue;
            if ( isset( $existing[ $slug ] ) ) continue;
            if ( $languages && ! in_array( $slug, $languages, true ) ) continue;
            $langs_to_create[] = $slug;
        }

        foreach ( $langs_to_create as $lang_slug ) {
            $duplicate_id = self::without_submitted_form( fn() => wp_insert_post( [
                'post_title'    => $post->post_title,
                'post_content'  => $post->post_content,
                'post_excerpt'  => $post->post_excerpt,
                'post_status'   => 'draft',
                'post_type'     => $post->post_type,
                'post_parent'   => $this->get_translated_parent_id( $post, $lang_slug ),
                'menu_order'    => $post->menu_order,
                'post_password' => $post->post_password,
                'post_author'   => $post->post_author,
            ] ) );

            if ( ! $duplicate_id || is_wp_error( $duplicate_id ) ) continue;

            // Register in icl_translations
            $db->set_element_language( $duplicate_id, $element_type, $lang_slug, $trid, $current_lang );

            // Copy meta — BEFORE the slug-fix save below, so that save_post hooks
            // which materialize per-post meta on a registered translation (e.g. a
            // theme's editor-mode flag) already see the source values and do not
            // write a row of their own that this copy would then duplicate.
            foreach ( $meta as $key => $values ) {
                if ( str_starts_with( $key, '_wp_loc_' ) ) continue;
                if ( $key === '_edit_lock' || $key === '_edit_last' ) continue;

                foreach ( $values as $value ) {
                    add_post_meta( $duplicate_id, $key, maybe_unserialize( $value ) );
                }
            }

            // Copy thumbnail — resolve to the translated attachment for this language
            if ( $thumbnail_id ) {
                $attachment_element_type = WP_LOC_DB::post_element_type( 'attachment' );
                $translated_thumb = $db->get_element_translation( (int) $thumbnail_id, $attachment_element_type, $lang_slug );
                set_post_thumbnail( $duplicate_id, $translated_thumb ?: $thumbnail_id );
            }

            // Fix slug — wp_insert_post may have added "-2" because icl_translations
            // registration happens after insert; now that language is set, re-apply original slug
            self::without_submitted_form( fn() => wp_update_post( [
                'ID'        => $duplicate_id,
                'post_name' => $post->post_name,
            ] ) );

            $this->sync_terms_to_translation( $post_id, $duplicate_id, $lang_slug );

            /**
             * Fires once a translation draft is fully created: registered in
             * icl_translations, with the source post's meta and thumbnail copied.
             *
             * Fires for the auto-create workflow and for the per-language "+" button alike.
             * Terms have the matching wp_loc_term_translation_created action.
             *
             * @param int    $duplicate_id The new translation post ID.
             * @param int    $post_id      The source post it was copied from.
             * @param string $lang_slug    Language slug of the new translation.
             * @param string $source_lang  Language slug of the source post.
             */
            do_action( 'wp_loc_translation_created', $duplicate_id, $post_id, $lang_slug, $current_lang );
        }

        self::$creating_translations = false;
    }

    /**
     * Sync post properties to all translations (both directions)
     */
    public function sync_translations( int $post_id, \WP_Post $post ): void {
        if ( self::$syncing || self::$creating_translations || self::$suspend_new_post_registration ) return;
        if ( wp_is_post_autosave( $post_id ) || wp_is_post_revision( $post_id ) ) return;
        if ( $post->post_status === 'auto-draft' ) return;

        $translatable = apply_filters( 'wp_loc_translatable_post_types', [ 'post', 'page' ] );
        if ( ! in_array( $post->post_type, $translatable, true ) ) return;

        $db = WP_LOC::instance()->db;
        $element_type = WP_LOC_DB::post_element_type( $post->post_type );
        $trid = $db->get_trid( $post_id, $element_type );
        if ( ! $trid ) return;

        $translations = $db->get_element_translations( $trid, $element_type );
        if ( count( $translations ) < 2 ) return;

        $source_lang = $db->get_element_language( $post_id, $element_type ) ?: wp_loc_get_admin_lang();

        self::$syncing = true;

        if ( WP_LOC_Admin_Settings::should_sync_post_attributes() ) {
            $page_template = get_page_template_slug( $post_id );

            foreach ( $translations as $slug => $row ) {
                $sibling_id = (int) $row->element_id;
                if ( $sibling_id === $post_id ) continue;

                $sibling = get_post( $sibling_id );
                if ( ! $sibling ) continue;

                $update_data = [
                    'ID'            => $sibling_id,
                    'menu_order'    => $post->menu_order,
                    'post_status'   => $post->post_status,
                    'post_author'   => $post->post_author,
                    'post_password' => $post->post_password,
                ];

                $update_data['post_parent'] = $this->get_translated_parent_id( $post, $slug, (int) $sibling->post_parent );

                self::without_submitted_form( fn() => wp_update_post( $update_data ) );

                // Sync page template
                if ( $page_template !== false ) {
                    update_post_meta( $sibling_id, '_wp_page_template', $page_template ?: 'default' );
                }
            }
        }

        if ( WP_LOC_Admin_Settings::should_sync_post_taxonomies() ) {
            $this->sync_post_terms( $post_id, $post, $translations, $source_lang );
        }

        self::$syncing = false;
    }

    /**
     * Permanently delete post translations and clean up icl_translations rows.
     */
    public function handle_delete_post( int $post_id ): void {
        if ( self::$deleting ) return;

        $post = get_post( $post_id );
        if ( ! $post ) return;

        $db = WP_LOC::instance()->db;
        $element_type = WP_LOC_DB::post_element_type( $post->post_type );
        $trid = $db->get_trid( $post_id, $element_type );

        if ( ! $trid ) return;

        $translatable = apply_filters( 'wp_loc_translatable_post_types', [ 'post', 'page' ] );
        if ( ! in_array( $post->post_type, $translatable, true ) ) {
            $db->delete_element( $post_id, $element_type );
            return;
        }

        $translations = $db->get_element_translations( $trid, $element_type );

        self::$deleting = true;

        try {
            foreach ( $translations as $translation ) {
                $sibling_id = (int) $translation->element_id;

                if ( $sibling_id === $post_id ) {
                    continue;
                }

                $sibling = get_post( $sibling_id );
                if ( $sibling instanceof \WP_Post && ! wp_delete_post( $sibling_id, true ) ) {
                    continue;
                }

                $db->delete_element( $sibling_id, $element_type );
            }
        } finally {
            self::$deleting = false;
        }

        $db->delete_element( $post_id, $element_type );
    }
}
