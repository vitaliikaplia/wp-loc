<?php

if ( ! defined( 'ABSPATH' ) ) exit;

class WP_LOC_Translation_Service {

    public const QUEUE_OPTION_KEY = 'wp_loc_chrome_ai_translation_queue';
    public const PROVIDER_CHROME_AI = 'chrome_ai';

    /**
     * Duplicate or return an existing translation post for a target language.
     */
    public function ensure_post_translation( int $source_post_id, string $target_lang, array $args = [] ) {
        $source_post = get_post( $source_post_id );

        if ( ! $source_post instanceof \WP_Post ) {
            return new WP_Error( 'wp_loc_source_post_missing', __( 'Source post was not found.', 'wp-loc' ) );
        }

        if ( ! WP_LOC_Admin_Settings::is_translatable( (string) $source_post->post_type ) ) {
            return new WP_Error( 'wp_loc_post_type_not_translatable', __( 'This post type is not translatable.', 'wp-loc' ) );
        }

        $target_lang = sanitize_key( $target_lang );
        $active_languages = WP_LOC_Languages::get_active_languages();

        if ( ! $target_lang || ! isset( $active_languages[ $target_lang ] ) ) {
            return new WP_Error( 'wp_loc_target_language_missing', __( 'Target language is not active.', 'wp-loc' ) );
        }

        $db = WP_LOC::instance()->db;
        $element_type = WP_LOC_DB::post_element_type( $source_post->post_type );
        $source_lang = isset( $args['source_lang'] ) ? sanitize_key( (string) $args['source_lang'] ) : '';
        $source_lang = $source_lang ?: $db->get_element_language( $source_post_id, $element_type ) ?: wp_loc_get_admin_lang();

        if ( $source_lang === $target_lang ) {
            return $source_post_id;
        }

        $trid = $db->get_trid( $source_post_id, $element_type );

        if ( ! $trid ) {
            $trid = $db->set_element_language( $source_post_id, $element_type, $source_lang );
        }

        $existing_id = $db->get_element_translation( $source_post_id, $element_type, $target_lang );
        if ( $existing_id ) {
            return (int) $existing_id;
        }

        $status = isset( $args['status'] ) ? sanitize_key( (string) $args['status'] ) : 'draft';
        $status = get_post_status_object( $status ) ? $status : 'draft';

        $duplicate_id = wp_insert_post(
            [
                'post_title'    => $source_post->post_title,
                'post_content'  => $source_post->post_content,
                'post_excerpt'  => $source_post->post_excerpt,
                'post_status'   => $status,
                'post_type'     => $source_post->post_type,
                'post_parent'   => $this->get_translated_parent_id( $source_post, $target_lang, $element_type ),
                'menu_order'    => $source_post->menu_order,
                'post_password' => $source_post->post_password,
                'post_author'   => $source_post->post_author,
            ],
            true
        );

        if ( is_wp_error( $duplicate_id ) ) {
            return $duplicate_id;
        }

        $db->set_element_language( (int) $duplicate_id, $element_type, $target_lang, $trid, $source_lang );

        wp_update_post(
            [
                'ID'        => (int) $duplicate_id,
                'post_name' => $source_post->post_name,
            ]
        );

        $this->copy_post_meta( $source_post_id, (int) $duplicate_id );
        $this->copy_featured_image( $source_post_id, (int) $duplicate_id, $target_lang );
        $this->copy_taxonomies( $source_post_id, (int) $duplicate_id, $source_post->post_type, $target_lang, $source_lang );

        return (int) $duplicate_id;
    }

    /**
     * Translate selected post fields and persist them into the target translation.
     */
    public function translate_post( int $source_post_id, string $target_lang, array $args = [] ) {
        $provider = isset( $args['provider'] ) ? sanitize_key( (string) $args['provider'] ) : WP_LOC_Admin_Settings::get_ai_engine();
        $fields = $this->normalize_fields( $args['fields'] ?? [ 'title', 'content', 'excerpt' ] );

        $target_post_id = $this->ensure_post_translation( $source_post_id, $target_lang, $args );
        if ( is_wp_error( $target_post_id ) ) {
            return $target_post_id;
        }

        $source_post = get_post( $source_post_id );
        if ( ! $source_post instanceof \WP_Post ) {
            return new WP_Error( 'wp_loc_source_post_missing', __( 'Source post was not found.', 'wp-loc' ) );
        }

        if ( $provider === self::PROVIDER_CHROME_AI ) {
            $this->enqueue_chrome_ai_post( $source_post_id, (int) $target_post_id, $target_lang, $fields );

            return [
                'source_id' => $source_post_id,
                'target_id' => (int) $target_post_id,
                'target_lang' => $target_lang,
                'provider' => $provider,
                'queued' => true,
            ];
        }

        $target_language_name = WP_LOC_AI::get_target_language_name( $target_lang );
        $update = [ 'ID' => (int) $target_post_id ];

        foreach ( $fields as $field ) {
            $source_value = $this->get_post_field_value( $source_post, $field );
            if ( trim( wp_strip_all_tags( (string) $source_value ) ) === '' ) {
                continue;
            }

            $translated = WP_LOC_AI::translate_content( (string) $source_value, $target_language_name, $provider );
            if ( $translated === '' ) {
                return new WP_Error( 'wp_loc_translation_failed', sprintf( __( 'Translation failed for field: %s.', 'wp-loc' ), $field ) );
            }

            $this->set_update_field_value( $update, $field, $translated );
        }

        if ( count( $update ) > 1 ) {
            $result = wp_update_post( $update, true );
            if ( is_wp_error( $result ) ) {
                return $result;
            }
        }

        return [
            'source_id' => $source_post_id,
            'target_id' => (int) $target_post_id,
            'target_lang' => $target_lang,
            'provider' => $provider,
            'queued' => false,
        ];
    }

    public function enqueue_chrome_ai_post( int $source_post_id, int $target_post_id, string $target_lang, array $fields ): void {
        $queue = self::get_chrome_ai_queue();
        $queue[] = [
            'id' => wp_generate_uuid4(),
            'source_id' => $source_post_id,
            'target_id' => $target_post_id,
            'target_lang' => sanitize_key( $target_lang ),
            'fields' => $this->normalize_fields( $fields ),
            'status' => 'pending',
            'created_at' => time(),
        ];

        update_option( self::QUEUE_OPTION_KEY, array_values( $queue ), false );
    }

    public static function get_chrome_ai_queue(): array {
        $queue = get_option( self::QUEUE_OPTION_KEY, [] );

        return is_array( $queue ) ? array_values( $queue ) : [];
    }

    public static function get_next_chrome_ai_queue_item(): ?array {
        foreach ( self::get_chrome_ai_queue() as $item ) {
            if ( ( $item['status'] ?? 'pending' ) === 'pending' ) {
                return $item;
            }
        }

        return null;
    }

    public static function mark_chrome_ai_queue_item_complete( string $item_id ): void {
        $queue = self::get_chrome_ai_queue();

        foreach ( $queue as &$item ) {
            if ( ( $item['id'] ?? '' ) === $item_id ) {
                $item['status'] = 'completed';
                $item['completed_at'] = time();
                break;
            }
        }

        update_option( self::QUEUE_OPTION_KEY, $queue, false );
    }

    public static function get_queue_counts(): array {
        $counts = [
            'pending' => 0,
            'completed' => 0,
        ];

        foreach ( self::get_chrome_ai_queue() as $item ) {
            $status = ( $item['status'] ?? 'pending' ) === 'completed' ? 'completed' : 'pending';
            $counts[ $status ]++;
        }

        return $counts;
    }

    public function get_queue_item_payload( array $item ): array {
        $source_post = get_post( (int) ( $item['source_id'] ?? 0 ) );

        if ( ! $source_post instanceof \WP_Post ) {
            return [];
        }

        $fields = $this->normalize_fields( $item['fields'] ?? [] );
        $payload_fields = [];

        foreach ( $fields as $field ) {
            $payload_fields[ $field ] = $this->get_post_field_value( $source_post, $field );
        }

        return [
            'id' => (string) ( $item['id'] ?? '' ),
            'sourceId' => (int) $item['source_id'],
            'targetId' => (int) $item['target_id'],
            'sourceLang' => $this->get_post_language_code( (int) $item['source_id'], $source_post->post_type ),
            'targetLang' => (string) $item['target_lang'],
            'fields' => $payload_fields,
        ];
    }

    public function save_translated_fields( int $target_post_id, array $translated_fields, bool $update_slug = true ) {
        $target_post = get_post( $target_post_id );

        if ( ! $target_post instanceof \WP_Post ) {
            return new WP_Error( 'wp_loc_target_post_missing', __( 'Target post was not found.', 'wp-loc' ) );
        }

        $update = [ 'ID' => $target_post_id ];

        foreach ( $this->normalize_fields( array_keys( $translated_fields ) ) as $field ) {
            $value = isset( $translated_fields[ $field ] ) ? (string) $translated_fields[ $field ] : '';
            $this->set_update_field_value( $update, $field, $value );
        }

        if ( $update_slug && isset( $update['post_title'] ) && trim( wp_strip_all_tags( $update['post_title'] ) ) !== '' ) {
            $update['post_name'] = sanitize_title( $update['post_title'] );
        }

        return wp_update_post( $update, true );
    }

    private function normalize_fields( $fields ): array {
        $fields = is_array( $fields ) ? $fields : explode( ',', (string) $fields );
        $allowed = [ 'title', 'content', 'excerpt' ];
        $result = [];

        foreach ( $fields as $field ) {
            $field = sanitize_key( (string) $field );
            if ( in_array( $field, $allowed, true ) ) {
                $result[] = $field;
            }
        }

        return array_values( array_unique( $result ?: $allowed ) );
    }

    private function get_post_field_value( \WP_Post $post, string $field ): string {
        return match ( $field ) {
            'title' => (string) $post->post_title,
            'excerpt' => (string) $post->post_excerpt,
            default => (string) $post->post_content,
        };
    }

    private function set_update_field_value( array &$update, string $field, string $value ): void {
        if ( $field === 'title' ) {
            $update['post_title'] = trim( wp_strip_all_tags( $value ) );
        } elseif ( $field === 'excerpt' ) {
            $update['post_excerpt'] = $value;
        } else {
            $update['post_content'] = $value;
        }
    }

    private function get_translated_parent_id( \WP_Post $source_post, string $target_lang, string $element_type ): int {
        if ( ! $source_post->post_parent ) {
            return 0;
        }

        $translated_parent = WP_LOC::instance()->db->get_element_translation( (int) $source_post->post_parent, $element_type, $target_lang );

        return $translated_parent ?: (int) $source_post->post_parent;
    }

    private function copy_post_meta( int $source_post_id, int $target_post_id ): void {
        foreach ( get_post_meta( $source_post_id ) as $key => $values ) {
            if ( str_starts_with( $key, '_wp_loc_' ) || in_array( $key, [ '_edit_lock', '_edit_last' ], true ) ) {
                continue;
            }

            foreach ( $values as $value ) {
                add_post_meta( $target_post_id, $key, maybe_unserialize( $value ) );
            }
        }
    }

    private function copy_featured_image( int $source_post_id, int $target_post_id, string $target_lang ): void {
        $thumbnail_id = get_post_thumbnail_id( $source_post_id );

        if ( ! $thumbnail_id ) {
            return;
        }

        $attachment_element_type = WP_LOC_DB::post_element_type( 'attachment' );
        $translated_thumb = WP_LOC::instance()->db->get_element_translation( (int) $thumbnail_id, $attachment_element_type, $target_lang );

        set_post_thumbnail( $target_post_id, $translated_thumb ?: $thumbnail_id );
    }

    private function copy_taxonomies( int $source_post_id, int $target_post_id, string $post_type, string $target_lang, string $source_lang ): void {
        $taxonomies = get_object_taxonomies( $post_type, 'names' );
        $translatable_taxonomies = class_exists( 'WP_LOC_Terms' ) ? WP_LOC_Terms::get_translatable_taxonomies() : [];

        foreach ( $taxonomies as $taxonomy ) {
            $source_term_ids = wp_get_object_terms( $source_post_id, $taxonomy, [ 'fields' => 'ids' ] );

            if ( is_wp_error( $source_term_ids ) ) {
                continue;
            }

            $target_term_ids = [];

            foreach ( array_map( 'intval', $source_term_ids ) as $term_id ) {
                if ( in_array( $taxonomy, $translatable_taxonomies, true ) ) {
                    $translated_id = WP_LOC_Terms::get_term_translation( $term_id, $taxonomy, $target_lang );
                    $target_term_ids[] = $translated_id ?: $term_id;
                } else {
                    $target_term_ids[] = $term_id;
                }
            }

            wp_set_object_terms( $target_post_id, array_values( array_unique( $target_term_ids ) ), $taxonomy, false );
        }
    }

    private function get_post_language_code( int $post_id, string $post_type ): string {
        $lang = WP_LOC::instance()->db->get_element_language( $post_id, WP_LOC_DB::post_element_type( $post_type ) );
        $locale = $lang ? WP_LOC_Languages::get_language_locale( $lang ) : '';

        return strtolower( str_replace( '_', '-', $locale ?: $lang ) );
    }
}
