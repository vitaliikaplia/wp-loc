<?php

if ( ! defined( 'ABSPATH' ) ) exit;

class WP_LOC_Media {

    private static $duplicating = false;
    private static $deleting = false;

    /** @var array Duplicate IDs created during current upload, keyed by attachment_id */
    private static $pending_duplicates = [];

    public function __construct() {
        // Create translated attachment duplicates on upload
        add_action( 'add_attachment', [ $this, 'handle_new_attachment' ] );

        // Copy attachment metadata to duplicates AFTER WordPress generates it
        add_filter( 'wp_generate_attachment_metadata', [ $this, 'copy_metadata_to_duplicates' ], 10, 2 );

        // Filter media library by language in admin
        add_action( 'pre_get_posts', [ $this, 'filter_media_by_language' ] );

        // Filter AJAX media queries (grid mode)
        add_filter( 'ajax_query_attachments_args', [ $this, 'filter_ajax_media_by_language' ] );

        // Cascade delete all translations when any attachment is deleted
        add_action( 'delete_attachment', [ $this, 'handle_delete_attachment' ] );

        // Prevent duplicate physical file deletion
        add_filter( 'wp_delete_file', [ $this, 'prevent_duplicate_file_deletion' ] );

        // Sync featured image across translations
        add_action( 'updated_post_meta', [ $this, 'sync_thumbnail' ], 10, 4 );
        add_action( 'added_post_meta', [ $this, 'sync_thumbnail' ], 10, 4 );
        add_action( 'deleted_post_meta', [ $this, 'sync_thumbnail_delete' ], 10, 4 );

        // Resolve attachment ID to current language (frontend)
        add_filter( 'wp_get_attachment_image_src', [ $this, 'maybe_resolve_attachment' ], 10, 4 );
    }

    /**
     * When a new attachment is uploaded, register it and create duplicates.
     * NOTE: _wp_attachment_metadata is NOT available yet at this point —
     * it gets generated AFTER add_attachment fires. We copy it later
     * via wp_generate_attachment_metadata filter.
     */
    public function handle_new_attachment( int $attachment_id ): void {
        if ( self::$duplicating ) return;

        $db = WP_LOC::instance()->db;
        $element_type = WP_LOC_DB::post_element_type( 'attachment' );

        // Already registered
        if ( $db->get_trid( $attachment_id, $element_type ) ) return;

        // Register original in current admin language
        $current_lang = is_admin() ? wp_loc_get_admin_lang() : ( function_exists( 'wp_loc_get_current_lang' ) ? wp_loc_get_current_lang() : null );
        if ( ! $current_lang ) {
            $current_lang = WP_LOC_Languages::get_default_language();
        }

        $trid = $db->set_element_language( $attachment_id, $element_type, $current_lang );

        // Create duplicates for other active languages
        $active = WP_LOC_Languages::get_active_languages();
        $original = get_post( $attachment_id );
        if ( ! $original ) return;

        self::$duplicating = true;

        // Temporarily unhook to prevent recursion
        remove_action( 'add_attachment', [ $this, 'handle_new_attachment' ] );

        $dup_ids = [];

        foreach ( array_keys( $active ) as $slug ) {
            if ( $slug === $current_lang ) continue;

            $dup_id = wp_insert_post( [
                'post_title'     => $original->post_title,
                'post_content'   => $original->post_content,
                'post_excerpt'   => $original->post_excerpt,
                'post_status'    => 'inherit',
                'post_type'      => 'attachment',
                'post_mime_type' => $original->post_mime_type,
                'post_author'    => $original->post_author,
                'guid'           => $original->guid,
            ] );

            if ( ! $dup_id || is_wp_error( $dup_id ) ) continue;

            // Copy file reference — this IS available at add_attachment time
            $file = get_post_meta( $attachment_id, '_wp_attached_file', true );
            if ( $file ) {
                update_post_meta( $dup_id, '_wp_attached_file', $file );
            }

            // Copy alt text
            $alt = get_post_meta( $attachment_id, '_wp_attachment_image_alt', true );
            if ( $alt ) {
                update_post_meta( $dup_id, '_wp_attachment_image_alt', $alt );
            }

            // Register in icl_translations
            $db->set_element_language( $dup_id, $element_type, $slug, $trid, $current_lang );

            $dup_ids[] = $dup_id;
        }

        // Store duplicate IDs so we can copy metadata later
        if ( ! empty( $dup_ids ) ) {
            self::$pending_duplicates[ $attachment_id ] = $dup_ids;
        }

        // Re-hook
        add_action( 'add_attachment', [ $this, 'handle_new_attachment' ] );

        self::$duplicating = false;
    }

    /**
     * Copy attachment metadata to duplicates AFTER WordPress generates it.
     * This runs after wp_generate_attachment_metadata() — sizes, dimensions, etc. are now available.
     */
    public function copy_metadata_to_duplicates( array $metadata, int $attachment_id ): array {
        if ( empty( self::$pending_duplicates[ $attachment_id ] ) ) {
            return $metadata;
        }

        $dup_ids = self::$pending_duplicates[ $attachment_id ];
        unset( self::$pending_duplicates[ $attachment_id ] );

        foreach ( $dup_ids as $dup_id ) {
            update_post_meta( $dup_id, '_wp_attachment_metadata', $metadata );
        }

        return $metadata;
    }

    /**
     * Filter media library list mode by admin language
     */
    public function filter_media_by_language( \WP_Query $query ): void {
        if ( ! is_admin() || ! $query->is_main_query() ) return;

        $screen = get_current_screen();
        if ( ! $screen || $screen->id !== 'upload' ) return;

        $lang = WP_LOC_DB::to_db_language_code( wp_loc_get_admin_lang() ) ?: wp_loc_get_admin_lang();
        $element_type = WP_LOC_DB::post_element_type( 'attachment' );
        $table = WP_LOC::instance()->db->get_table();

        add_filter( 'posts_join', function ( $join ) use ( $table, $element_type ) {
            global $wpdb;
            $join .= " LEFT JOIN {$table} AS wp_loc_media ON {$wpdb->posts}.ID = wp_loc_media.element_id AND wp_loc_media.element_type = '" . esc_sql( $element_type ) . "'";
            return $join;
        } );

        add_filter( 'posts_where', function ( $where ) use ( $lang ) {
            global $wpdb;
            $where .= $wpdb->prepare(
                " AND (wp_loc_media.language_code = %s OR wp_loc_media.language_code IS NULL)",
                $lang
            );
            return $where;
        } );
    }

    /**
     * Filter AJAX media queries (grid view / media modal) by admin language
     */
    public function filter_ajax_media_by_language( array $args ): array {
        if ( ! is_admin() ) return $args;

        $lang = WP_LOC_DB::to_db_language_code( wp_loc_get_admin_lang() ) ?: wp_loc_get_admin_lang();
        $element_type = WP_LOC_DB::post_element_type( 'attachment' );
        $table = WP_LOC::instance()->db->get_table();

        global $wpdb;

        // Single query: get IDs that match current language OR are unregistered
        $allowed = $wpdb->get_col( $wpdb->prepare(
            "SELECT p.ID FROM {$wpdb->posts} p
             LEFT JOIN {$table} t ON p.ID = t.element_id AND t.element_type = %s
             WHERE p.post_type = 'attachment'
             AND (t.language_code = %s OR t.element_id IS NULL)",
            $element_type,
            $lang
        ) );

        if ( ! empty( $allowed ) ) {
            // Merge with existing post__in if any
            if ( ! empty( $args['post__in'] ) ) {
                $args['post__in'] = array_intersect( $args['post__in'], $allowed );
                if ( empty( $args['post__in'] ) ) {
                    $args['post__in'] = [ 0 ];
                }
            } else {
                $args['post__in'] = $allowed;
            }
        } else {
            $args['post__in'] = [ 0 ];
        }

        return $args;
    }

    /**
     * Cascade delete all translation duplicates when any attachment is deleted.
     * Hooked on delete_attachment — fires specifically for attachments.
     */
    public function handle_delete_attachment( int $post_id ): void {
        if ( self::$deleting ) return;

        $db = WP_LOC::instance()->db;
        $element_type = WP_LOC_DB::post_element_type( 'attachment' );
        $trid = $db->get_trid( $post_id, $element_type );

        if ( ! $trid ) return;

        $translations = $db->get_element_translations( $trid, $element_type );

        self::$deleting = true;

        foreach ( $translations as $slug => $row ) {
            $sibling_id = (int) $row->element_id;
            if ( $sibling_id === $post_id ) continue;

            // Remove from icl_translations first
            $db->delete_element( $sibling_id, $element_type );

            // Delete the duplicate post (file won't be deleted — see prevent_duplicate_file_deletion)
            wp_delete_attachment( $sibling_id, true );
        }

        // Remove the original from icl_translations
        $db->delete_element( $post_id, $element_type );

        self::$deleting = false;
    }

    /**
     * Prevent physical file deletion when deleting duplicate attachments
     */
    public function prevent_duplicate_file_deletion( string $file ): string {
        if ( ! self::$deleting ) return $file;
        return '';
    }

    /**
     * Sync featured image (_thumbnail_id) across all translations of a post.
     * When setting featured image on UA post, resolve to the translated attachment for EN/RU.
     */
    public function sync_thumbnail( int $meta_id, int $post_id, string $meta_key, $meta_value ): void {
        if ( $meta_key !== '_thumbnail_id' ) return;
        if ( self::$duplicating ) return;
        if ( ! WP_LOC_Admin_Settings::should_sync_featured_image() ) return;

        $post = get_post( $post_id );
        if ( ! $post ) return;

        if ( ! WP_LOC_Admin_Settings::is_translatable( $post->post_type ) ) return;

        $db = WP_LOC::instance()->db;
        $post_element_type = WP_LOC_DB::post_element_type( $post->post_type );
        $trid = $db->get_trid( $post_id, $post_element_type );
        if ( ! $trid ) return;

        $translations = $db->get_element_translations( $trid, $post_element_type );
        if ( count( $translations ) < 2 ) return;

        $attachment_element_type = WP_LOC_DB::post_element_type( 'attachment' );

        static $syncing_thumb = false;
        if ( $syncing_thumb ) return;
        $syncing_thumb = true;

        foreach ( $translations as $slug => $row ) {
            $sibling_id = (int) $row->element_id;
            if ( $sibling_id === $post_id ) continue;

            if ( $meta_value ) {
                // Resolve thumbnail to the translation for this language
                $translated_thumb = $db->get_element_translation( (int) $meta_value, $attachment_element_type, $slug );
                update_post_meta( $sibling_id, '_thumbnail_id', $translated_thumb ?: $meta_value );
            } else {
                delete_post_meta( $sibling_id, '_thumbnail_id' );
            }
        }

        $syncing_thumb = false;
    }

    /**
     * Sync featured image removal across all translations of a post.
     */
    public function sync_thumbnail_delete( array $meta_ids, int $post_id, string $meta_key, $meta_value ): void {
        if ( $meta_key !== '_thumbnail_id' ) return;
        if ( self::$duplicating ) return;
        if ( ! WP_LOC_Admin_Settings::should_sync_featured_image() ) return;

        $post = get_post( $post_id );
        if ( ! $post || ! WP_LOC_Admin_Settings::is_translatable( $post->post_type ) ) return;

        $db = WP_LOC::instance()->db;
        $post_element_type = WP_LOC_DB::post_element_type( $post->post_type );
        $trid = $db->get_trid( $post_id, $post_element_type );
        if ( ! $trid ) return;

        $translations = $db->get_element_translations( $trid, $post_element_type );

        static $syncing_delete = false;
        if ( $syncing_delete ) return;
        $syncing_delete = true;

        foreach ( $translations as $row ) {
            $sibling_id = (int) $row->element_id;
            if ( $sibling_id === $post_id ) continue;
            delete_post_meta( $sibling_id, '_thumbnail_id' );
        }

        $syncing_delete = false;
    }

    /**
     * Resolve attachment to the current language version (frontend)
     */
    public function maybe_resolve_attachment( $image, int $attachment_id, $size, bool $icon ) {
        if ( is_admin() || ! $image ) return $image;

        $current_lang = wp_loc_get_current_lang();
        $default_lang = WP_LOC_Languages::get_default_language();

        if ( $current_lang === $default_lang ) return $image;

        $db = WP_LOC::instance()->db;
        $element_type = WP_LOC_DB::post_element_type( 'attachment' );

        $translated_id = $db->get_element_translation( $attachment_id, $element_type, $current_lang );

        if ( $translated_id && $translated_id !== $attachment_id ) {
            remove_filter( 'wp_get_attachment_image_src', [ $this, 'maybe_resolve_attachment' ], 10 );
            $translated_image = wp_get_attachment_image_src( $translated_id, $size, $icon );
            add_filter( 'wp_get_attachment_image_src', [ $this, 'maybe_resolve_attachment' ], 10, 4 );

            return $translated_image ?: $image;
        }

        return $image;
    }
}
