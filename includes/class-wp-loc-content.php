<?php

if ( ! defined( 'ABSPATH' ) ) exit;

class WP_LOC_Content {

    private static $creating_translations = false;
    private static $syncing = false;

    public function __construct() {
        add_action( 'wp_insert_post', [ $this, 'mark_new_post' ], 10, 3 );
        add_action( 'save_post', [ $this, 'handle_save_post' ], 20, 3 );
        add_action( 'save_post', [ $this, 'sync_translations' ], 30, 2 );
        add_action( 'before_delete_post', [ $this, 'handle_delete_post' ] );
    }

    /**
     * Mark newly created posts
     */
    public function mark_new_post( int $post_id, \WP_Post $post, bool $update ): void {
        if ( $update || self::$creating_translations ) return;

        $translatable = apply_filters( 'wp_loc_translatable_post_types', [ 'post', 'page' ] );
        if ( ! in_array( $post->post_type, $translatable, true ) ) return;

        add_post_meta( $post_id, '_wp_loc_is_new', 1, true );
    }

    /**
     * Handle post save — register in icl_translations and optionally create duplicates
     */
    public function handle_save_post( int $post_id, \WP_Post $post, bool $update ): void {
        if ( self::$creating_translations ) return;
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

        delete_post_meta( $post_id, '_wp_loc_is_new' );

        $current_lang = wp_loc_get_admin_lang();
        $db->set_element_language( $post_id, $element_type, $current_lang );

        // Auto-create translation drafts
        $this->create_translations( $post_id );
    }

    /**
     * Create translation drafts for a post
     */
    public function create_translations( int $post_id ): void {
        if ( self::$creating_translations ) return;
        self::$creating_translations = true;

        $post = get_post( $post_id );
        if ( ! $post ) {
            self::$creating_translations = false;
            return;
        }

        $db = WP_LOC::instance()->db;
        $element_type = WP_LOC_DB::post_element_type( $post->post_type );

        // Get or create trid for this post
        $current_lang = wp_loc_get_admin_lang();
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
            $langs_to_create[] = $slug;
        }

        foreach ( $langs_to_create as $lang_slug ) {
            $duplicate_id = wp_insert_post( [
                'post_title'    => $post->post_title,
                'post_content'  => $post->post_content,
                'post_excerpt'  => $post->post_excerpt,
                'post_status'   => 'draft',
                'post_type'     => $post->post_type,
                'post_parent'   => $post->post_parent,
                'menu_order'    => $post->menu_order,
                'post_password' => $post->post_password,
                'post_author'   => $post->post_author,
            ] );

            if ( ! $duplicate_id || is_wp_error( $duplicate_id ) ) continue;

            // Register in icl_translations
            $db->set_element_language( $duplicate_id, $element_type, $lang_slug, $trid, $current_lang );

            // Fix slug — wp_insert_post may have added "-2" because icl_translations
            // registration happens after insert; now that language is set, re-apply original slug
            wp_update_post( [
                'ID'        => $duplicate_id,
                'post_name' => $post->post_name,
            ] );

            // Copy meta
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
        }

        self::$creating_translations = false;
    }

    /**
     * Sync post properties to all translations (both directions)
     */
    public function sync_translations( int $post_id, \WP_Post $post ): void {
        if ( self::$syncing || self::$creating_translations ) return;
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

        self::$syncing = true;

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

            // Resolve translated parent
            if ( $post->post_parent ) {
                $translated_parent = $db->get_element_translation( $post->post_parent, $element_type, $slug );
                $update_data['post_parent'] = $translated_parent ?: $post->post_parent;
            } else {
                $update_data['post_parent'] = 0;
            }

            wp_update_post( $update_data );

            // Sync page template
            if ( $page_template !== false ) {
                update_post_meta( $sibling_id, '_wp_page_template', $page_template ?: 'default' );
            }
        }

        self::$syncing = false;
    }

    /**
     * Clean up icl_translations when a post is deleted
     */
    public function handle_delete_post( int $post_id ): void {
        $post = get_post( $post_id );
        if ( ! $post ) return;

        $translatable = apply_filters( 'wp_loc_translatable_post_types', [ 'post', 'page' ] );
        if ( ! in_array( $post->post_type, $translatable, true ) ) return;

        $element_type = WP_LOC_DB::post_element_type( $post->post_type );
        WP_LOC::instance()->db->delete_element( $post_id, $element_type );
    }
}
