<?php

if ( ! defined( 'ABSPATH' ) ) exit;

class WP_LOC_Menus {

    private static bool $cloning_menu_items = false;
    private static bool $creating_menu_translations = false;
    private static bool $deleting_menu_translations = false;
    private static bool $filtering_nav_menu_posts = false;
    private static array $custom_link_translation_cache = [];

    public function __construct() {
        add_action( 'wp_create_nav_menu', [ $this, 'register_nav_menu_language' ], 10, 2 );
        add_action( 'wp_update_nav_menu', [ $this, 'register_nav_menu_language' ], 10, 2 );
        add_action( 'pre_delete_term', [ $this, 'cascade_delete_nav_menu_translations' ], 10, 2 );
        add_action( 'delete_term', [ $this, 'delete_nav_menu_language' ], 10, 5 );
        add_action( 'wp_update_nav_menu_item', [ $this, 'register_nav_menu_item_language' ], 10, 3 );
        add_action( 'delete_post', [ $this, 'delete_nav_menu_item_language' ] );
        add_action( 'pre_get_posts', [ $this, 'filter_nav_menu_meta_box_posts' ] );

        add_filter( 'pre_update_option_theme_mods_' . get_option( 'stylesheet' ), [ $this, 'pre_update_theme_mods' ] );
        add_filter( 'theme_mod_nav_menu_locations', [ $this, 'translate_theme_menu_locations' ] );
        add_filter( 'wp_get_nav_menus', [ $this, 'filter_nav_menus_by_language' ], 10, 2 );
        add_filter( 'nav_menu_meta_box_object', [ $this, 'enable_nav_menu_meta_box_filters' ], 20 );
        add_filter( 'wp_ajax_menu_quick_search_args', [ $this, 'filter_nav_menu_quick_search_args' ] );
        add_filter( 'get_terms_args', [ $this, 'filter_nav_menu_get_terms_args' ], 20, 2 );
        add_filter( 'option_page_on_front', [ $this, 'filter_nav_menu_page_option' ], 20 );
        add_filter( 'option_page_for_posts', [ $this, 'filter_nav_menu_page_option' ], 20 );
        add_filter( 'option_wp_page_for_privacy_policy', [ $this, 'filter_nav_menu_page_option' ], 20 );
        add_filter( 'posts_join', [ $this, 'filter_nav_menu_posts_join' ], 20, 2 );
        add_filter( 'posts_where', [ $this, 'filter_nav_menu_posts_where' ], 20, 2 );

        add_action( 'admin_footer', [ $this, 'inject_nav_menu_form_fields' ] );
        add_filter( 'get_user_option_nav_menu_recently_edited', [ $this, 'filter_recently_edited_menu' ], 10, 3 );
    }

    private function is_nav_menus_screen(): bool {
        global $pagenow;

        return is_admin() && $pagenow === 'nav-menus.php';
    }

    private function is_nav_menu_request(): bool {
        if ( $this->is_nav_menus_screen() ) {
            return true;
        }

        return wp_doing_ajax() && ( $_REQUEST['action'] ?? '' ) === 'menu-quick-search';
    }

    private function get_context_language(): string {
        if ( $this->is_nav_menu_request() ) {
            $active = WP_LOC_Languages::get_active_languages();

            foreach ( [ 'wp_loc_nav_menu_lang', 'wp_loc_menu_lang', 'lang', 'wp_loc_lang' ] as $key ) {
                $candidate = isset( $_REQUEST[ $key ] ) ? sanitize_key( (string) $_REQUEST[ $key ] ) : '';

                if ( $candidate && isset( $active[ $candidate ] ) ) {
                    return $candidate;
                }
            }

            foreach ( [ 'menu', 'wp_loc_translation_of' ] as $key ) {
                $menu_id = isset( $_REQUEST[ $key ] ) ? (int) $_REQUEST[ $key ] : 0;

                if ( $menu_id ) {
                    $existing_lang = $this->get_menu_language( $menu_id );

                    if ( $existing_lang ) {
                        return $existing_lang;
                    }
                }
            }
        }

        return is_admin() ? wp_loc_get_admin_lang() : wp_loc_get_current_lang();
    }

    private function get_menu_term_taxonomy_id( int $menu_id ): ?int {
        return WP_LOC_Terms::get_term_taxonomy_id( $menu_id, 'nav_menu' );
    }

    private function get_menu_language( int $menu_id ): ?string {
        $term_taxonomy_id = $this->get_menu_term_taxonomy_id( $menu_id );

        if ( ! $term_taxonomy_id ) {
            return null;
        }

        return WP_LOC::instance()->db->get_element_language( $term_taxonomy_id, WP_LOC_DB::tax_element_type( 'nav_menu' ) );
    }

    private function get_menu_trid( int $menu_id ): ?int {
        $term_taxonomy_id = $this->get_menu_term_taxonomy_id( $menu_id );

        if ( ! $term_taxonomy_id ) {
            return null;
        }

        return WP_LOC::instance()->db->get_trid( $term_taxonomy_id, WP_LOC_DB::tax_element_type( 'nav_menu' ) );
    }

    private function get_menu_translation( int $menu_id, string $target_lang ): ?int {
        $term_taxonomy_id = $this->get_menu_term_taxonomy_id( $menu_id );

        if ( ! $term_taxonomy_id ) {
            return null;
        }

        $translated_term_taxonomy_id = WP_LOC::instance()->db->get_element_translation(
            $term_taxonomy_id,
            WP_LOC_DB::tax_element_type( 'nav_menu' ),
            $target_lang
        );

        if ( ! $translated_term_taxonomy_id ) {
            return null;
        }

        return WP_LOC_Terms::get_term_id_from_taxonomy_id( $translated_term_taxonomy_id, 'nav_menu' );
    }

    private function get_menu_source_language( int $menu_id ): ?string {
        $term_taxonomy_id = $this->get_menu_term_taxonomy_id( $menu_id );

        if ( ! $term_taxonomy_id ) {
            return null;
        }

        $translations = $this->get_menu_translations( $menu_id );
        $lang = WP_LOC::instance()->db->get_element_language( $term_taxonomy_id, WP_LOC_DB::tax_element_type( 'nav_menu' ) );

        if ( ! $lang || empty( $translations[ $lang ] ) ) {
            return null;
        }

        return $translations[ $lang ]->source_language_code ?: null;
    }

    /**
     * @return array<string,\stdClass>
     */
    private function get_menu_translations( int $menu_id ): array {
        $term_taxonomy_id = $this->get_menu_term_taxonomy_id( $menu_id );

        if ( ! $term_taxonomy_id ) {
            return [];
        }

        $trid = WP_LOC::instance()->db->get_trid( $term_taxonomy_id, WP_LOC_DB::tax_element_type( 'nav_menu' ) );

        if ( ! $trid ) {
            return [];
        }

        return WP_LOC::instance()->db->get_element_translations( $trid, WP_LOC_DB::tax_element_type( 'nav_menu' ) );
    }

    private function get_requested_source_menu_id(): int {
        if ( ! empty( $_POST['wp_loc_translation_of'] ) ) {
            return (int) $_POST['wp_loc_translation_of'];
        }

        if ( ! empty( $_GET['wp_loc_translation_of'] ) ) {
            return (int) $_GET['wp_loc_translation_of'];
        }

        return 0;
    }

    private function is_plain_create_menu_request(): bool {
        if ( ! $this->is_nav_menus_screen() ) {
            return false;
        }

        $action = isset( $_REQUEST['action'] ) ? sanitize_key( (string) $_REQUEST['action'] ) : '';
        $menu_id = isset( $_REQUEST['menu'] ) ? (int) $_REQUEST['menu'] : -1;

        return $action === 'edit' && $menu_id === 0 && ! $this->get_requested_source_menu_id();
    }

    private function get_requested_menu_language( int $menu_id = 0 ): string {
        $active = WP_LOC_Languages::get_active_languages();

        if ( $menu_id ) {
            $existing_lang = $this->get_menu_language( $menu_id );

            if ( $existing_lang ) {
                return $existing_lang;
            }
        }

        foreach ( [ 'wp_loc_nav_menu_lang', 'wp_loc_menu_lang', 'lang', 'wp_loc_lang' ] as $key ) {
            $candidate = isset( $_POST[ $key ] ) ? sanitize_key( (string) $_POST[ $key ] ) : '';

            if ( ! $candidate ) {
                $candidate = isset( $_GET[ $key ] ) ? sanitize_key( (string) $_GET[ $key ] ) : '';
            }

            if ( $candidate && isset( $active[ $candidate ] ) ) {
                return $candidate;
            }
        }

        return $this->get_context_language();
    }

    private function get_requested_menu_trid( int $menu_id = 0 ): ?int {
        if ( isset( $_POST['wp_loc_nav_menu_trid'] ) ) {
            return (int) $_POST['wp_loc_nav_menu_trid'] ?: null;
        }

        if ( isset( $_GET['wp_loc_nav_menu_trid'] ) ) {
            return (int) $_GET['wp_loc_nav_menu_trid'] ?: null;
        }

        $source_menu_id = $this->get_requested_source_menu_id();

        if ( $source_menu_id ) {
            return $this->get_menu_trid( $source_menu_id );
        }

        if ( $this->is_plain_create_menu_request() ) {
            return null;
        }

        if ( $menu_id ) {
            return $this->get_menu_trid( $menu_id );
        }

        return null;
    }

    private function get_effective_selected_menu_id(): int {
        if ( $this->is_plain_create_menu_request() ) {
            return 0;
        }

        $selected_menu_id = isset( $_REQUEST['menu'] ) ? (int) $_REQUEST['menu'] : 0;

        if ( $selected_menu_id && is_nav_menu( $selected_menu_id ) ) {
            return $selected_menu_id;
        }

        $recently_edited = (int) get_user_option( 'nav_menu_recently_edited' );

        if ( $recently_edited && is_nav_menu( $recently_edited ) ) {
            return $recently_edited;
        }

        $menus = wp_get_nav_menus();

        foreach ( $menus as $menu ) {
            if ( $menu instanceof \WP_Term ) {
                return (int) $menu->term_id;
            }
        }

        return 0;
    }

    private function generate_translated_menu_name( string $base_name, string $lang ): string {
        $candidate = sprintf( '%s (%s)', $base_name, WP_LOC_Languages::get_display_name( $lang ) );
        $suffix = 2;

        while ( is_nav_menu( $candidate ) ) {
            $candidate = sprintf( '%s (%s) %d', $base_name, WP_LOC_Languages::get_display_name( $lang ), $suffix );
            $suffix++;
        }

        return $candidate;
    }

    private function get_menu_auto_add( int $menu_id ): bool {
        $options = get_option( 'nav_menu_options', [] );
        $auto_add = isset( $options['auto_add'] ) && is_array( $options['auto_add'] ) ? array_map( 'intval', $options['auto_add'] ) : [];

        return in_array( $menu_id, $auto_add, true );
    }

    private function set_menu_auto_add( int $menu_id, bool $enabled ): void {
        $options = get_option( 'nav_menu_options', [] );
        $auto_add = isset( $options['auto_add'] ) && is_array( $options['auto_add'] ) ? array_map( 'intval', $options['auto_add'] ) : [];

        if ( $enabled ) {
            if ( ! in_array( $menu_id, $auto_add, true ) ) {
                $auto_add[] = $menu_id;
            }
        } else {
            $auto_add = array_values( array_filter( $auto_add, static fn( int $id ): bool => $id !== $menu_id ) );
        }

        $options['auto_add'] = $auto_add;
        update_option( 'nav_menu_options', $options );
    }

    private function ensure_menu_item_translation_group( int $source_item_id, int $target_item_id, string $source_lang, string $target_lang ): void {
        $db = WP_LOC::instance()->db;
        $element_type = WP_LOC_DB::post_element_type( 'nav_menu_item' );
        $source_trid = $db->get_trid( $source_item_id, $element_type );

        if ( ! $source_trid ) {
            $source_trid = $db->set_element_language( $source_item_id, $element_type, $source_lang, null, null );
        }

        $db->set_element_language(
            $target_item_id,
            $element_type,
            $target_lang,
            $source_trid,
            $target_lang === $source_lang ? null : $source_lang
        );
    }

    private function should_ai_translate_custom_menu_links(): bool {
        return WP_LOC_Admin_Settings::should_ai_translate_custom_menu_links();
    }

    private function get_ai_target_language_name( string $target_lang ): string {
        return WP_LOC_AI::get_target_language_name( $target_lang );
    }

    private function get_custom_menu_item_source_hash( \WP_Post $menu_item ): string {
        return md5(
            wp_json_encode(
                [
                    'title'       => (string) $menu_item->title,
                    'url'         => (string) $menu_item->url,
                    'description' => (string) $menu_item->description,
                    'attr_title'  => (string) $menu_item->attr_title,
                ],
                JSON_UNESCAPED_UNICODE
            ) ?: ''
        );
    }

    private function get_custom_menu_item_target_hash_from_values( string $title, string $description, string $attr_title, string $url ): string {
        return md5(
            wp_json_encode(
                [
                    'title'       => $title,
                    'url'         => $url,
                    'description' => $description,
                    'attr_title'  => $attr_title,
                ],
                JSON_UNESCAPED_UNICODE
            ) ?: ''
        );
    }

    private function get_custom_menu_item_target_hash( \WP_Post $menu_item ): string {
        return $this->get_custom_menu_item_target_hash_from_values(
            (string) $menu_item->title,
            (string) $menu_item->description,
            (string) $menu_item->attr_title,
            (string) $menu_item->url
        );
    }

    private function translate_custom_menu_item_field( string $value, string $target_lang ): string {
        $value = trim( $value );

        if ( $value === '' ) {
            return '';
        }

        $cache_key = $target_lang . ':' . md5( $value );

        if ( isset( self::$custom_link_translation_cache[ $cache_key ] ) ) {
            return self::$custom_link_translation_cache[ $cache_key ];
        }

        $translated = WP_LOC_AI::translate_content( $value, WP_LOC_Languages::get_display_name( $target_lang ) );
        $translated = trim( $translated );

        if ( $translated === '' ) {
            $translated = $value;
        }

        self::$custom_link_translation_cache[ $cache_key ] = $translated;

        return $translated;
    }

    private function get_target_custom_item_hashes( int $target_menu_id ): array {
        $hashes = [];
        $items = wp_get_nav_menu_items( $target_menu_id, [ 'post_status' => 'any' ] );

        foreach ( $items ?: [] as $item ) {
            if ( ! $item instanceof \WP_Post || (string) $item->type !== 'custom' ) {
                continue;
            }

            $source_item_id = (int) get_post_meta( (int) $item->ID, '_wp_loc_ai_source_item_id', true );
            $source_hash = (string) get_post_meta( (int) $item->ID, '_wp_loc_ai_source_hash', true );
            $translated_hash = (string) get_post_meta( (int) $item->ID, '_wp_loc_ai_translated_hash', true );
            $current_hash = $this->get_custom_menu_item_target_hash( $item );

            if ( $source_item_id ) {
                $hashes[ $source_item_id ] = [
                    'source_hash' => $source_hash,
                    'translated_hash' => $translated_hash,
                    'current_hash' => $current_hash,
                ];
            }
        }

        return $hashes;
    }

    private function custom_menu_items_need_ai_sync( array $desired_items, int $target_menu_id ): bool {
        if ( ! $target_menu_id || ! $this->should_ai_translate_custom_menu_links() ) {
            return false;
        }

        $target_hashes = $this->get_target_custom_item_hashes( $target_menu_id );

        foreach ( $desired_items as $item ) {
            if ( ( $item['type'] ?? '' ) !== 'custom' ) {
                continue;
            }

            $source_item_id = (int) ( $item['source_item_id'] ?? 0 );
            $source_hash = (string) ( $item['source_hash'] ?? '' );

            if ( ! $source_item_id || $source_hash === '' ) {
                continue;
            }

            $target_state = $target_hashes[ $source_item_id ] ?? null;

            if ( ! is_array( $target_state ) ) {
                return true;
            }

            if ( ( $target_state['source_hash'] ?? '' ) !== $source_hash ) {
                return true;
            }

            if ( ( $target_state['translated_hash'] ?? '' ) === '' ) {
                return true;
            }

            if ( ( $target_state['translated_hash'] ?? '' ) !== ( $target_state['current_hash'] ?? '' ) ) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function build_desired_menu_items( int $source_menu_id, string $target_lang, bool $translate_custom_links = false ): array {
        $source_items = wp_get_nav_menu_items( $source_menu_id, [ 'post_status' => 'any' ] );

        if ( empty( $source_items ) ) {
            return [];
        }

        $desired_items = [];

        foreach ( $source_items as $item ) {
            if ( ! ( $item instanceof \WP_Post ) && ! isset( $item->ID ) ) {
                continue;
            }

            $menu_item = wp_setup_nav_menu_item( $item );

            if ( ! $menu_item || is_wp_error( $menu_item ) ) {
                continue;
            }

            $resolved_object_id = $this->resolve_menu_item_object_id(
                (string) $menu_item->type,
                (string) $menu_item->object,
                (int) $menu_item->object_id,
                $target_lang
            );

            if ( in_array( $menu_item->type, [ 'post_type', 'taxonomy' ], true ) && ! $resolved_object_id ) {
                continue;
            }

            $translated_title = (string) $menu_item->title;
            $custom_source_hash = '';

            if ( $menu_item->type === 'post_type' && $resolved_object_id ) {
                $translated_post = get_post( $resolved_object_id );

                if ( $translated_post instanceof \WP_Post ) {
                    $translated_title = $translated_post->post_title;
                }
            } elseif ( $menu_item->type === 'taxonomy' && $resolved_object_id ) {
                $translated_term = get_term( $resolved_object_id, (string) $menu_item->object );

                if ( $translated_term instanceof \WP_Term ) {
                    $translated_title = $translated_term->name;
                }
            } elseif ( $menu_item->type === 'custom' ) {
                $custom_source_hash = $this->get_custom_menu_item_source_hash( $item );

                if ( $translate_custom_links && $this->should_ai_translate_custom_menu_links() ) {
                    $ai_target_lang = $this->get_ai_target_language_name( $target_lang );
                    $translated_title = $this->translate_custom_menu_item_field( $translated_title, $ai_target_lang );
                    $menu_item->attr_title = $this->translate_custom_menu_item_field( (string) $menu_item->attr_title, $ai_target_lang );
                    $menu_item->description = $this->translate_custom_menu_item_field( (string) $menu_item->description, $ai_target_lang );
                }
            }

            $classes = is_array( $menu_item->classes ) ? implode( ' ', array_filter( $menu_item->classes ) ) : (string) $menu_item->classes;

            $desired_items[] = [
                'source_item_id'    => (int) $menu_item->ID,
                'source_parent_id'  => (int) $menu_item->menu_item_parent,
                'menu_order'        => (int) $menu_item->menu_order,
                'object_id'         => $resolved_object_id,
                'object'            => (string) $menu_item->object,
                'type'              => (string) $menu_item->type,
                'title'             => $translated_title,
                'url'               => (string) $menu_item->url,
                'description'       => (string) $menu_item->description,
                'attr_title'        => (string) $menu_item->attr_title,
                'target'            => (string) $menu_item->target,
                'classes'           => $classes,
                'xfn'               => (string) $menu_item->xfn,
                'post_status'       => $menu_item->post_status === 'publish' ? 'publish' : 'draft',
                'source_hash'       => $custom_source_hash,
                'translated_hash'   => $menu_item->type === 'custom'
                    ? $this->get_custom_menu_item_target_hash_from_values(
                        $translated_title,
                        (string) $menu_item->description,
                        (string) $menu_item->attr_title,
                        (string) $menu_item->url
                    )
                    : '',
            ];
        }

        return $desired_items;
    }

    private function ensure_menu_translation_exists( int $source_menu_id, string $target_lang ): ?int {
        $existing_menu_id = $this->get_menu_translation( $source_menu_id, $target_lang );

        if ( $existing_menu_id ) {
            return $existing_menu_id;
        }

        $source_menu = get_term( $source_menu_id, 'nav_menu' );

        if ( ! $source_menu instanceof \WP_Term ) {
            return null;
        }

        $source_lang = $this->get_menu_language( $source_menu_id ) ?: WP_LOC_Languages::get_default_language();
        $trid = $this->get_menu_trid( $source_menu_id );

        if ( ! $trid ) {
            return null;
        }

        $new_menu_id = wp_create_nav_menu( $this->generate_translated_menu_name( $source_menu->name, $target_lang ) );

        if ( is_wp_error( $new_menu_id ) || ! $new_menu_id ) {
            return null;
        }

        $new_term_taxonomy_id = $this->get_menu_term_taxonomy_id( (int) $new_menu_id );

        if ( ! $new_term_taxonomy_id ) {
            return null;
        }

        WP_LOC::instance()->db->set_element_language(
            $new_term_taxonomy_id,
            WP_LOC_DB::tax_element_type( 'nav_menu' ),
            $target_lang,
            $trid,
            $target_lang === $source_lang ? null : $source_lang
        );

        return (int) $new_menu_id;
    }

    private function build_target_menu_signature( int $menu_id ): array {
        $items = wp_get_nav_menu_items( $menu_id, [ 'post_status' => 'any' ] );
        $signature = [];
        $id_to_order = [];
        $use_ai_custom_links = $this->should_ai_translate_custom_menu_links();

        foreach ( $items ?: [] as $item ) {
            $id_to_order[ (int) $item->ID ] = (int) $item->menu_order;
        }

        foreach ( $items ?: [] as $item ) {
            $is_custom = (string) $item->type === 'custom' && $use_ai_custom_links;
            $is_custom_link = (string) $item->type === 'custom';
            $signature_object_id = (string) $item->type === 'custom' ? 0 : (int) $item->object_id;
            $signature[] = [
                'type' => (string) $item->type,
                'object' => (string) $item->object,
                'object_id' => $signature_object_id,
                'url' => $is_custom_link ? (string) $item->url : '',
                'title' => $is_custom ? '' : (string) $item->title,
                'menu_order' => (int) $item->menu_order,
                'parent' => ! empty( $item->menu_item_parent ) ? (int) ( $id_to_order[ (int) $item->menu_item_parent ] ?? 0 ) : 0,
                'source_item_id' => $is_custom ? (int) get_post_meta( (int) $item->ID, '_wp_loc_ai_source_item_id', true ) : 0,
                'source_hash' => $is_custom ? (string) get_post_meta( (int) $item->ID, '_wp_loc_ai_source_hash', true ) : '',
            ];
        }

        return $signature;
    }

    private function build_desired_menu_signature( array $desired_items ): array {
        $signature = [];
        $source_id_to_order = [];
        $use_ai_custom_links = $this->should_ai_translate_custom_menu_links();

        foreach ( $desired_items as $item ) {
            $source_id_to_order[ $item['source_item_id'] ] = (int) $item['menu_order'];
        }

        foreach ( $desired_items as $item ) {
            $is_custom = ( $item['type'] ?? '' ) === 'custom' && $use_ai_custom_links;
            $is_custom_link = ( $item['type'] ?? '' ) === 'custom';
            $signature_object_id = ( $item['type'] ?? '' ) === 'custom' ? 0 : (int) $item['object_id'];
            $signature[] = [
                'type' => (string) $item['type'],
                'object' => (string) $item['object'],
                'object_id' => $signature_object_id,
                'url' => $is_custom_link ? (string) ( $item['url'] ?? '' ) : '',
                'title' => $is_custom ? '' : (string) $item['title'],
                'menu_order' => (int) $item['menu_order'],
                'parent' => $item['source_parent_id'] ? (int) ( $source_id_to_order[ $item['source_parent_id'] ] ?? 0 ) : 0,
                'source_item_id' => $is_custom ? (int) $item['source_item_id'] : 0,
                'source_hash' => $is_custom ? (string) ( $item['source_hash'] ?? '' ) : '',
            ];
        }

        return $signature;
    }

    private function clear_menu_items( int $menu_id ): int {
        $items = wp_get_nav_menu_items( $menu_id, [ 'post_status' => 'any' ] );
        $deleted = 0;

        foreach ( $items ?: [] as $item ) {
            if ( isset( $item->ID ) && wp_delete_post( (int) $item->ID, true ) ) {
                $deleted++;
            }
        }

        return $deleted;
    }

    public function get_sync_source_menus(): array {
        $default_lang = WP_LOC_Languages::get_default_language();
        $menus = wp_get_nav_menus();

        return array_values( array_filter( $menus, function ( $menu ) use ( $default_lang ) {
            if ( ! $menu instanceof \WP_Term ) {
                return false;
            }

            return $this->get_menu_language( (int) $menu->term_id ) === $default_lang;
        } ) );
    }

    public function build_sync_preview(): array {
        $preview = [];
        $default_lang = WP_LOC_Languages::get_default_language();
        $languages = array_diff( array_keys( WP_LOC_Languages::get_active_languages() ), [ $default_lang ] );

        foreach ( $this->get_sync_source_menus() as $menu ) {
            $source_menu_id = (int) $menu->term_id;
            $source_items = wp_get_nav_menu_items( $source_menu_id, [ 'post_status' => 'any' ] );
            $source_auto_add = $this->get_menu_auto_add( $source_menu_id );

            $row = [
                'menu_id' => $source_menu_id,
                'name' => $menu->name,
                'languages' => [],
            ];

            foreach ( $languages as $lang ) {
                $target_menu_id = $this->get_menu_translation( $source_menu_id, $lang );
                $target_items = $target_menu_id ? wp_get_nav_menu_items( $target_menu_id, [ 'post_status' => 'any' ] ) : [];
                $desired_items = $this->build_desired_menu_items( $source_menu_id, $lang );
                $skipped_items = max( 0, count( $source_items ?: [] ) - count( $desired_items ) );
                $structure_changed = false;
                $ai_custom_links_count = count( array_filter( $desired_items, static fn( array $item ): bool => ( $item['type'] ?? '' ) === 'custom' ) );
                $custom_ai_needs_sync = $this->custom_menu_items_need_ai_sync( $desired_items, (int) $target_menu_id );
                $operations = [];

                if ( $target_menu_id ) {
                    $structure_changed = $this->build_target_menu_signature( $target_menu_id ) !== $this->build_desired_menu_signature( $desired_items );
                }

                if ( ! $target_menu_id ) {
                    $operations[] = [
                        'type' => 'menu_translation',
                        'label' => sprintf( __( 'Add menu translation: %s', 'wp-loc' ), $this->generate_translated_menu_name( $menu->name, $lang ) ),
                    ];
                }

                if ( ! $target_menu_id || count( $desired_items ) !== count( $target_items ?: [] ) || $skipped_items > 0 || $structure_changed ) {
                    $operations[] = [
                        'type' => 'sync_structure',
                        'label' => $structure_changed
                            ? sprintf( __( 'Sync menu structure and order (%d items)', 'wp-loc' ), count( $desired_items ) )
                            : sprintf( __( 'Sync menu structure (%d items)', 'wp-loc' ), count( $desired_items ) ),
                    ];
                }

                if ( $target_menu_id && count( $target_items ?: [] ) > count( $desired_items ) ) {
                    $operations[] = [
                        'type' => 'remove_extra',
                        'label' => sprintf( __( 'Remove outdated items (%d)', 'wp-loc' ), count( $target_items ) - count( $desired_items ) ),
                    ];
                }

                if ( $target_menu_id && $source_auto_add !== $this->get_menu_auto_add( $target_menu_id ) ) {
                    $operations[] = [
                        'type' => 'options_changed',
                        'label' => sprintf( __( 'Update menu option auto_add to %s', 'wp-loc' ), $source_auto_add ? '1' : '0' ),
                    ];
                }

                if ( $custom_ai_needs_sync && $ai_custom_links_count > 0 ) {
                    $operations[] = [
                        'type' => 'ai_translation',
                        'label' => sprintf( __( 'Translate custom links with AI (%d)', 'wp-loc' ), $ai_custom_links_count ),
                    ];
                }

                if ( $skipped_items > 0 ) {
                    $operations[] = [
                        'type' => 'skipped',
                        'label' => sprintf( __( 'Skip untranslated linked items (%d)', 'wp-loc' ), $skipped_items ),
                    ];
                }

                $row['languages'][ $lang ] = [
                    'menu_id' => $target_menu_id,
                    'menu_name' => $target_menu_id ? wp_get_nav_menu_object( $target_menu_id )->name : '',
                    'operations' => $operations,
                    'needs_sync' => ! empty( $operations ),
                ];
            }

            $preview[] = $row;
        }

        return $preview;
    }

    public function sync_menu_translation( int $source_menu_id, string $target_lang ): array {
        $source_lang = $this->get_menu_language( $source_menu_id ) ?: WP_LOC_Languages::get_default_language();
        $existing_target_menu_id = $this->get_menu_translation( $source_menu_id, $target_lang );
        $target_menu_id = $this->ensure_menu_translation_exists( $source_menu_id, $target_lang );

        if ( ! $target_menu_id ) {
            return [
                'success' => false,
                'target_menu_id' => 0,
                'created_menu' => false,
                'deleted_items' => 0,
                'created_items' => 0,
                'skipped_items' => 0,
            ];
        }

        $created_menu = ! $existing_target_menu_id;
        $desired_items = $this->build_desired_menu_items( $source_menu_id, $target_lang, $this->should_ai_translate_custom_menu_links() );
        $deleted_items = $this->clear_menu_items( $target_menu_id );
        $created_items = 0;
        $skipped_items = max( 0, count( wp_get_nav_menu_items( $source_menu_id, [ 'post_status' => 'any' ] ) ?: [] ) - count( $desired_items ) );
        $id_map = [];

        self::$cloning_menu_items = true;

        foreach ( $desired_items as $item ) {
            $new_item_id = wp_update_nav_menu_item( $target_menu_id, 0, [
                'menu-item-object-id'   => $item['object_id'],
                'menu-item-object'      => $item['object'],
                'menu-item-parent-id'   => 0,
                'menu-item-position'    => $item['menu_order'],
                'menu-item-type'        => $item['type'],
                'menu-item-title'       => $item['title'],
                'menu-item-url'         => $item['url'],
                'menu-item-description' => $item['description'],
                'menu-item-attr-title'  => $item['attr_title'],
                'menu-item-target'      => $item['target'],
                'menu-item-classes'     => $item['classes'],
                'menu-item-xfn'         => $item['xfn'],
                'menu-item-status'      => $item['post_status'],
            ] );

            if ( is_wp_error( $new_item_id ) || ! $new_item_id ) {
                continue;
            }

            $new_item_id = (int) $new_item_id;
            $id_map[ $item['source_item_id'] ] = $new_item_id;
            $this->ensure_menu_item_translation_group( $item['source_item_id'], $new_item_id, $source_lang, $target_lang );

            if ( $item['type'] === 'custom' && $this->should_ai_translate_custom_menu_links() ) {
                update_post_meta( $new_item_id, '_wp_loc_ai_source_item_id', (int) $item['source_item_id'] );
                update_post_meta( $new_item_id, '_wp_loc_ai_source_hash', (string) ( $item['source_hash'] ?? '' ) );
                update_post_meta( $new_item_id, '_wp_loc_ai_translated_hash', (string) ( $item['translated_hash'] ?? '' ) );
            }

            $created_items++;
        }

        foreach ( $desired_items as $item ) {
            $new_item_id = $id_map[ $item['source_item_id'] ] ?? 0;
            $new_parent_id = $id_map[ $item['source_parent_id'] ] ?? 0;

            if ( $new_item_id && $new_parent_id ) {
                update_post_meta( $new_item_id, '_menu_item_menu_item_parent', $new_parent_id );
            }
        }

        self::$cloning_menu_items = false;

        $this->set_menu_auto_add( $target_menu_id, $this->get_menu_auto_add( $source_menu_id ) );

        return [
            'success' => true,
            'target_menu_id' => $target_menu_id,
            'created_menu' => $created_menu,
            'deleted_items' => $deleted_items,
            'created_items' => $created_items,
            'skipped_items' => $skipped_items,
        ];
    }

    private function maybe_clone_into_existing_empty_translation( int $source_menu_id, string $target_lang ): void {
        $target_menu_id = $this->get_menu_translation( $source_menu_id, $target_lang );

        if ( ! $target_menu_id ) {
            return;
        }

        $target_items = wp_get_nav_menu_items( $target_menu_id, [ 'post_status' => 'any' ] );

        if ( ! empty( $target_items ) ) {
            return;
        }

        $this->clone_menu_items_from_source( $source_menu_id, $target_menu_id, $target_lang );
    }

    private function ensure_menu_translations( int $menu_id, string $source_lang ): void {
        if ( self::$creating_menu_translations ) {
            return;
        }

        $menu = get_term( $menu_id, 'nav_menu' );

        if ( ! $menu instanceof \WP_Term ) {
            return;
        }

        $db = WP_LOC::instance()->db;
        $trid = $this->get_menu_trid( $menu_id );

        if ( ! $trid ) {
            return;
        }

        $translations = $this->get_menu_translations( $menu_id );
        self::$creating_menu_translations = true;

        foreach ( array_keys( WP_LOC_Languages::get_active_languages() ) as $lang ) {
            if ( isset( $translations[ $lang ] ) ) {
                $this->maybe_clone_into_existing_empty_translation( $menu_id, $lang );
                continue;
            }

            $new_menu_id = wp_create_nav_menu( $this->generate_translated_menu_name( $menu->name, $lang ) );

            if ( is_wp_error( $new_menu_id ) || ! $new_menu_id ) {
                continue;
            }

            $new_term_taxonomy_id = $this->get_menu_term_taxonomy_id( (int) $new_menu_id );

            if ( ! $new_term_taxonomy_id ) {
                continue;
            }

            $db->set_element_language(
                $new_term_taxonomy_id,
                WP_LOC_DB::tax_element_type( 'nav_menu' ),
                $lang,
                $trid,
                $lang === $source_lang ? null : $source_lang
            );

            $this->clone_menu_items_from_source( $menu_id, (int) $new_menu_id, $lang );
        }

        self::$creating_menu_translations = false;
    }

    public function register_nav_menu_language( int $menu_id, $menu_data = null ): void {
        if ( ! $menu_data ) {
            return;
        }

        $term_taxonomy_id = $this->get_menu_term_taxonomy_id( $menu_id );

        if ( ! $term_taxonomy_id ) {
            return;
        }

        $db = WP_LOC::instance()->db;
        $element_type = WP_LOC_DB::tax_element_type( 'nav_menu' );
        $existing_lang = $db->get_element_language( $term_taxonomy_id, $element_type );
        $language_code = $this->get_requested_menu_language( $menu_id );
        $trid = $this->get_requested_menu_trid( $menu_id );
        $source_menu_id = $this->get_requested_source_menu_id();
        $source_lang = null;

        if ( $source_menu_id ) {
            $source_lang = $this->get_menu_language( $source_menu_id );
            $trid = $trid ?: $this->get_menu_trid( $source_menu_id );
        } elseif ( $existing_lang ) {
            $source_lang = $this->get_menu_source_language( $menu_id );
        }

        $db->set_element_language( $term_taxonomy_id, $element_type, $language_code, $trid, $source_lang );

        if ( $source_menu_id && ! self::$cloning_menu_items ) {
            $this->clone_menu_items_from_source( $source_menu_id, $menu_id, $language_code );
        }

        if ( ! $source_menu_id && ! $existing_lang && WP_LOC_Admin_Settings::should_auto_create_menu_translations() ) {
            $this->ensure_menu_translations( $menu_id, $language_code );
        }
    }

    public function cascade_delete_nav_menu_translations( int $term_id, string $taxonomy ): void {
        if ( $taxonomy !== 'nav_menu' || self::$deleting_menu_translations ) {
            return;
        }

        $term_taxonomy_id = $this->get_menu_term_taxonomy_id( $term_id );

        if ( ! $term_taxonomy_id ) {
            return;
        }

        $db = WP_LOC::instance()->db;
        $element_type = WP_LOC_DB::tax_element_type( 'nav_menu' );
        $trid = $db->get_trid( $term_taxonomy_id, $element_type );

        if ( ! $trid ) {
            return;
        }

        $translations = $db->get_element_translations( $trid, $element_type );

        if ( count( $translations ) < 2 ) {
            return;
        }

        self::$deleting_menu_translations = true;

        foreach ( $translations as $translation ) {
            $translated_term_taxonomy_id = (int) $translation->element_id;

            if ( $translated_term_taxonomy_id === $term_taxonomy_id ) {
                continue;
            }

            $translated_menu_id = WP_LOC_Terms::get_term_id_from_taxonomy_id( $translated_term_taxonomy_id, 'nav_menu' );

            if ( $translated_menu_id ) {
                wp_delete_nav_menu( $translated_menu_id );
            }
        }

        self::$deleting_menu_translations = false;
    }

    public function delete_nav_menu_language( int $term_id, int $term_taxonomy_id, string $taxonomy, $deleted_term, array $object_ids ): void {
        if ( $taxonomy !== 'nav_menu' || ! $term_taxonomy_id ) {
            return;
        }

        WP_LOC::instance()->db->delete_element( $term_taxonomy_id, WP_LOC_DB::tax_element_type( 'nav_menu' ) );
    }

    public function register_nav_menu_item_language( int $menu_id, int $menu_item_db_id, array $args ): void {
        if ( $menu_item_db_id <= 0 ) {
            return;
        }

        $menu_lang = $menu_id > 0 ? $this->get_menu_language( $menu_id ) : null;
        $menu_lang = $menu_lang ?: $this->get_context_language();
        $language_code_item = null;

        if ( isset( $args['menu-item-type'], $args['menu-item-object-id'] ) && $menu_id > 0 ) {
            $item_type = (string) $args['menu-item-type'];
            $object_id = (int) $args['menu-item-object-id'];

            if ( $item_type === 'post_type' || $item_type === 'taxonomy' ) {
                $translated_object_id = $this->resolve_menu_item_object_id( $item_type, (string) ( $args['menu-item-object'] ?? '' ), $object_id, $menu_lang );

                if ( ! $translated_object_id ) {
                    wp_remove_object_terms( $menu_item_db_id, $menu_id, 'nav_menu' );
                    return;
                }

                if ( $translated_object_id !== $object_id ) {
                    update_post_meta( $menu_item_db_id, '_menu_item_object_id', $translated_object_id );
                }

                $language_code_item = $item_type === 'post_type'
                    ? WP_LOC::instance()->db->get_element_language( $translated_object_id, WP_LOC_DB::post_element_type( (string) $args['menu-item-object'] ) )
                    : WP_LOC_Terms::get_term_language( $translated_object_id, (string) $args['menu-item-object'] );
            }
        }

        $db = WP_LOC::instance()->db;
        $element_type = WP_LOC_DB::post_element_type( 'nav_menu_item' );
        $trid = $db->get_trid( $menu_item_db_id, $element_type );
        $language_code = $language_code_item ?: $menu_lang;

        $db->set_element_language( $menu_item_db_id, $element_type, $language_code, $trid );
    }

    public function filter_nav_menu_meta_box_posts( \WP_Query $query ): void {
        if ( ! $this->is_nav_menu_request() ) {
            return;
        }

        $post_type = $query->get( 'post_type' );

        if ( ! $post_type || $post_type === 'nav_menu_item' ) {
            return;
        }

        if ( is_array( $post_type ) ) {
            $post_type = reset( $post_type );
        }

        if ( ! is_string( $post_type ) ) {
            return;
        }

        if ( WP_LOC_Admin_Settings::is_translatable( $post_type ) ) {
            $query->set( 'suppress_filters', false );
            $query->set( 'wp_loc_nav_menu_lang', $this->get_context_language() );
        }
    }

    public function enable_nav_menu_meta_box_filters( $object ) {
        if ( ! $this->is_nav_menu_request() || ! is_object( $object ) ) {
            return $object;
        }

        if ( isset( $object->_default_query ) && is_array( $object->_default_query ) ) {
            $object->_default_query['suppress_filters'] = false;
        } elseif ( isset( $object->name ) && post_type_exists( $object->name ) && WP_LOC_Admin_Settings::is_translatable( $object->name ) ) {
            $object->_default_query = [
                'suppress_filters' => false,
            ];
        }

        return $object;
    }

    public function filter_nav_menu_quick_search_args( array $args ): array {
        if ( ! $this->is_nav_menu_request() ) {
            return $args;
        }

        $post_type = $args['post_type'] ?? '';

        if ( $post_type && is_string( $post_type ) && WP_LOC_Admin_Settings::is_translatable( $post_type ) ) {
            $args['suppress_filters'] = false;
            $args['wp_loc_nav_menu_lang'] = $this->get_context_language();
        }

        return $args;
    }

    public function filter_nav_menu_get_terms_args( array $args, array $taxonomies ): array {
        if ( ! $this->is_nav_menu_request() || empty( $taxonomies ) ) {
            return $args;
        }

        $taxonomies = array_filter( array_map( 'strval', $taxonomies ) );

        if ( empty( $taxonomies ) || in_array( 'nav_menu', $taxonomies, true ) ) {
            return $args;
        }

        foreach ( $taxonomies as $taxonomy ) {
            if ( WP_LOC_Terms::is_translatable( $taxonomy ) ) {
                $args['lang'] = $this->get_context_language();
                break;
            }
        }

        return $args;
    }

    public function filter_nav_menu_page_option( $value ) {
        if ( ! $this->is_nav_menu_request() ) {
            return $value;
        }

        $page_id = (int) $value;

        if ( ! $page_id ) {
            return $value;
        }

        $post_type = get_post_type( $page_id );

        if ( ! $post_type || ! WP_LOC_Admin_Settings::is_translatable( $post_type ) ) {
            return $value;
        }

        $translated_id = WP_LOC::instance()->db->get_element_translation(
            $page_id,
            WP_LOC_DB::post_element_type( $post_type ),
            $this->get_context_language()
        );

        return $translated_id ?: $value;
    }

    public function filter_nav_menu_posts_join( string $join, \WP_Query $query ): string {
        $lang = $query->get( 'wp_loc_nav_menu_lang' );
        $post_type = $query->get( 'post_type' );

        if ( ! $lang || ! $post_type ) {
            return $join;
        }

        if ( is_array( $post_type ) ) {
            $post_type = reset( $post_type );
        }

        if ( ! is_string( $post_type ) || ! WP_LOC_Admin_Settings::is_translatable( $post_type ) ) {
            return $join;
        }

        if ( strpos( $join, 'wp_loc_nav_menu_posts' ) !== false ) {
            return $join;
        }

        global $wpdb;
        $table = WP_LOC::instance()->db->get_table();
        $element_type = esc_sql( WP_LOC_DB::post_element_type( $post_type ) );

        return $join . " LEFT JOIN {$table} AS wp_loc_nav_menu_posts ON {$wpdb->posts}.ID = wp_loc_nav_menu_posts.element_id AND wp_loc_nav_menu_posts.element_type = '{$element_type}'";
    }

    public function filter_nav_menu_posts_where( string $where, \WP_Query $query ): string {
        $lang = $query->get( 'wp_loc_nav_menu_lang' );
        $post_type = $query->get( 'post_type' );

        if ( ! $lang || ! $post_type ) {
            return $where;
        }

        if ( is_array( $post_type ) ) {
            $post_type = reset( $post_type );
        }

        if ( ! is_string( $post_type ) || ! WP_LOC_Admin_Settings::is_translatable( $post_type ) ) {
            return $where;
        }

        global $wpdb;
        $db_lang = WP_LOC_DB::to_db_language_code( $lang ) ?: $lang;

        return $where . $wpdb->prepare(
            " AND (wp_loc_nav_menu_posts.language_code = %s OR wp_loc_nav_menu_posts.element_id IS NULL)",
            $db_lang
        );
    }

    public function delete_nav_menu_item_language( int $menu_item_id ): void {
        $post = get_post( $menu_item_id );

        if ( ! $post || $post->post_type !== 'nav_menu_item' ) {
            return;
        }

        WP_LOC::instance()->db->delete_element( $menu_item_id, WP_LOC_DB::post_element_type( 'nav_menu_item' ) );
    }

    public function pre_update_theme_mods( array $value ): array {
        if ( ! isset( $value['nav_menu_locations'] ) || ! is_array( $value['nav_menu_locations'] ) ) {
            return $value;
        }

        $default_lang = WP_LOC_Languages::get_default_language();
        $current_lang = $this->get_context_language();
        $saved_locations = get_theme_mod( 'nav_menu_locations', [] );

        foreach ( $value['nav_menu_locations'] as $location => $menu_id ) {
            $menu_id = (int) $menu_id;

            if ( ! $menu_id && $current_lang !== $default_lang && isset( $saved_locations[ $location ] ) ) {
                $value['nav_menu_locations'][ $location ] = (int) $saved_locations[ $location ];
                continue;
            }

            if ( ! $menu_id ) {
                $value['nav_menu_locations'][ $location ] = 0;
                continue;
            }

            $default_menu_id = $this->get_menu_translation( $menu_id, $default_lang );
            $value['nav_menu_locations'][ $location ] = $default_menu_id ?: $menu_id;
        }

        return $value;
    }

    public function translate_theme_menu_locations( $theme_locations ) {
        if ( ! is_array( $theme_locations ) || empty( $theme_locations ) ) {
            return $theme_locations;
        }

        $current_lang = $this->get_context_language();

        foreach ( $theme_locations as $location => $menu_id ) {
            $menu_id = (int) $menu_id;

            if ( ! $menu_id ) {
                continue;
            }

            $translated_menu_id = $this->get_menu_translation( $menu_id, $current_lang );

            if ( $translated_menu_id ) {
                $theme_locations[ $location ] = $translated_menu_id;
            }
        }

        return $theme_locations;
    }

    public function filter_nav_menus_by_language( array $menus, array $args ): array {
        if ( ! $this->is_nav_menus_screen() ) {
            return $menus;
        }

        $lang = $this->get_context_language();
        $default_lang = WP_LOC_Languages::get_default_language();

        return array_values( array_filter( $menus, function ( $menu ) use ( $lang, $default_lang ) {
            if ( ! $menu instanceof \WP_Term ) {
                return false;
            }

            $menu_lang = $this->get_menu_language( (int) $menu->term_id );

            if ( ! $menu_lang ) {
                return $lang === $default_lang;
            }

            return $menu_lang === $lang;
        } ) );
    }

    public function filter_recently_edited_menu( $value, string $option, $user ) {
        if ( ! $this->is_nav_menus_screen() || isset( $_REQUEST['menu'] ) ) {
            return $value;
        }

        $recently_edited = (int) $value;

        if ( ! $recently_edited || ! is_nav_menu( $recently_edited ) ) {
            return $value;
        }

        $current_lang = $this->get_context_language();
        $default_lang = WP_LOC_Languages::get_default_language();
        $recent_lang = $this->get_menu_language( $recently_edited );

        if ( $recent_lang ) {
            return $recent_lang === $current_lang ? $value : 0;
        }

        return $current_lang === $default_lang ? $value : 0;
    }

    public function inject_nav_menu_form_fields(): void {
        if ( ! $this->is_nav_menus_screen() ) {
            return;
        }

        $selected_menu_id = $this->get_effective_selected_menu_id();
        $source_menu_id = $this->get_requested_source_menu_id();
        $context_menu_id = $selected_menu_id ?: $source_menu_id;
        $lang = $this->get_requested_menu_language( $context_menu_id );
        $trid = $this->get_requested_menu_trid( $context_menu_id );
        $source_name = '';
        $switch_urls = [];
        $translation_links = [];
        if ( $source_menu_id ) {
            $source_menu = get_term( $source_menu_id, 'nav_menu' );
            if ( $source_menu instanceof \WP_Term ) {
                $source_name = $source_menu->name;
            }
        }

        if ( $context_menu_id ) {
            $translations = $this->get_menu_translations( $context_menu_id );

            foreach ( array_keys( WP_LOC_Languages::get_active_languages() ) as $slug ) {
                $target_menu_id = null;
                $locale = WP_LOC_Languages::get_language_locale( $slug );
                $flag = WP_LOC_Languages::get_flag_url( $locale );
                $name = esc_attr( WP_LOC_Languages::get_display_name( $slug ) );
                $flag_img = '<img class="wp-loc-flag" src="' . esc_url( $flag ) . '" alt="' . $name . '" />';
                $pencil = '<span class="wp-loc-pencil" aria-hidden="true">✎</span>';

                if ( isset( $translations[ $slug ] ) ) {
                    $target_menu_id = WP_LOC_Terms::get_term_id_from_taxonomy_id( (int) $translations[ $slug ]->element_id, 'nav_menu' );
                }

                if ( $target_menu_id ) {
                    $switch_urls[ $slug ] = add_query_arg(
                        [
                            'menu'        => $target_menu_id,
                            'wp_loc_lang' => $slug,
                        ],
                        admin_url( 'nav-menus.php' )
                    );

                    $translation_links[] = sprintf(
                        '<a href="%1$s" class="wp-loc-t wp-loc-t-published %3$s" title="%4$s" aria-label="%4$s">%2$s%5$s</a>',
                        esc_url( $switch_urls[ $slug ] ),
                        $flag_img,
                        $slug === $lang ? 'current' : '',
                        $name,
                        $pencil
                    );
                    continue;
                }

                $create_url = add_query_arg(
                    [
                        'action'                => 'edit',
                        'menu'                  => 0,
                        'wp_loc_lang'           => $slug,
                        'wp_loc_menu_lang'      => $slug,
                        'wp_loc_nav_menu_trid'  => $trid,
                        'wp_loc_translation_of' => $context_menu_id,
                    ],
                    admin_url( 'nav-menus.php' )
                );

                $switch_urls[ $slug ] = $create_url;
                $translation_links[] = sprintf(
                    '<a href="%1$s" class="wp-loc-t wp-loc-t-missing" title="%3$s" aria-label="%3$s">%2$s</a>',
                    esc_url( $create_url ),
                    $flag_img,
                    $name
                );
            }
        }

        $translation_links_html = implode( '<span class="sep">|</span>', $translation_links );
        $translation_message = '';

        if ( $source_menu_id && ! $selected_menu_id && $source_name ) {
            $translation_message = sprintf(
                /* translators: 1: language name, 2: menu name */
                __( 'Creating %1$s translation of "%2$s".', 'wp-loc' ),
                WP_LOC_Languages::get_display_name( $lang ),
                $source_name
            );
        }

        $show_translation_links = (bool) $selected_menu_id;
        $translation_links_markup = $show_translation_links ? $translation_links_html : '';
        $translation_message_markup = $translation_message
            ? '<div class="wp-loc-nav-menu-message">' . esc_html( $translation_message ) . '</div>'
            : '';

        echo '<div id="wp-loc-nav-menu-data" hidden'
            . ' data-lang="' . esc_attr( $lang ) . '"'
            . ' data-trid="' . esc_attr( (string) ( $trid ?: '' ) ) . '"'
            . ' data-translation-of="' . esc_attr( (string) ( $source_menu_id ?: '' ) ) . '"'
            . ' data-source-name="' . esc_attr( $source_name ) . '"'
            . ' data-source-name-localized="' . esc_attr( $source_name ? $source_name . ' (' . WP_LOC_Languages::get_display_name( $lang ) . ')' : '' ) . '"'
            . ' data-show-translations="' . esc_attr( $show_translation_links ? '1' : '0' ) . '"'
            . ' data-translations-label="' . esc_attr__( 'Translations:', 'wp-loc' ) . '"'
            . ' data-translations-html="' . esc_attr( $translation_links_markup ) . '"'
            . ' data-message-html="' . esc_attr( $translation_message_markup ) . '"></div>';
    }

    private function clone_menu_items_from_source( int $source_menu_id, int $target_menu_id, string $target_lang ): void {
        $existing_items = wp_get_nav_menu_items( $target_menu_id, [ 'post_status' => 'any' ] );

        if ( ! empty( $existing_items ) ) {
            return;
        }

        $desired_items = $this->build_desired_menu_items( $source_menu_id, $target_lang );

        if ( empty( $desired_items ) ) {
            return;
        }

        $source_lang = $this->get_menu_language( $source_menu_id ) ?: WP_LOC_Languages::get_default_language();
        self::$cloning_menu_items = true;
        $id_map = [];
        foreach ( $desired_items as $item ) {
            $new_item_id = wp_update_nav_menu_item( $target_menu_id, 0, [
                'menu-item-object-id'   => $item['object_id'],
                'menu-item-object'      => $item['object'],
                'menu-item-parent-id'   => 0,
                'menu-item-position'    => $item['menu_order'],
                'menu-item-type'        => $item['type'],
                'menu-item-title'       => $item['title'],
                'menu-item-url'         => $item['url'],
                'menu-item-description' => $item['description'],
                'menu-item-attr-title'  => $item['attr_title'],
                'menu-item-target'      => $item['target'],
                'menu-item-classes'     => $item['classes'],
                'menu-item-xfn'         => $item['xfn'],
                'menu-item-status'      => $item['post_status'],
            ] );

            if ( ! is_wp_error( $new_item_id ) && $new_item_id ) {
                $id_map[ $item['source_item_id'] ] = (int) $new_item_id;
                $this->ensure_menu_item_translation_group( $item['source_item_id'], (int) $new_item_id, $source_lang, $target_lang );
            }
        }

        foreach ( $desired_items as $item ) {
            $new_item_id = $id_map[ $item['source_item_id'] ] ?? 0;
            $old_parent_id = $item['source_parent_id'];

            if ( ! $new_item_id || ! $old_parent_id || empty( $id_map[ $old_parent_id ] ) ) {
                continue;
            }

            update_post_meta( $new_item_id, '_menu_item_menu_item_parent', (int) $id_map[ $old_parent_id ] );
        }

        self::$cloning_menu_items = false;
    }

    private function resolve_menu_item_object_id( string $item_type, string $object_type, int $object_id, string $target_lang ): ?int {
        if ( $item_type === 'custom' || $item_type === 'post_type_archive' ) {
            return $object_id;
        }

        if ( ! $object_id || ! $object_type ) {
            return $object_id ?: null;
        }

        if ( $item_type === 'post_type' ) {
            if ( ! WP_LOC_Admin_Settings::is_translatable( $object_type ) ) {
                return $object_id;
            }

            $translated_id = WP_LOC::instance()->db->get_element_translation(
                $object_id,
                WP_LOC_DB::post_element_type( $object_type ),
                $target_lang
            );

            return $translated_id ?: null;
        }

        if ( $item_type === 'taxonomy' ) {
            if ( ! WP_LOC_Terms::is_translatable( $object_type ) ) {
                return $object_id;
            }

            return WP_LOC_Terms::get_term_translation( $object_id, $object_type, $target_lang );
        }

        return $object_id;
    }
}
