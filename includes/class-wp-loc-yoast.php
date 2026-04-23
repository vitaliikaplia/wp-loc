<?php

if ( ! defined( 'ABSPATH' ) ) exit;

class WP_LOC_Yoast {

    private const OPTIONS = [
        'wpseo_titles',
        'wpseo_social',
        'wpseo_rss',
    ];

    private const PRIMARY_TERM_META = [
        '_yoast_wpseo_primary_category'    => 'category',
        '_yoast_wpseo_primary_product_cat' => 'product_cat',
    ];

    private const TERM_IMAGE_ID_KEYS = [
        'wpseo_opengraph-image-id' => 'wpseo_opengraph-image',
        'wpseo_twitter-image-id'   => 'wpseo_twitter-image',
    ];

    private const PRESENTATION_OPTION_MAP = [
        'title' => [
            'home-page'         => 'title-home-wpseo',
            'post-type-archive' => 'title-ptarchive-%s',
            'system-page'       => 'title-%s-wpseo',
        ],
        'metadesc' => [
            'home-page'         => 'metadesc-home-wpseo',
            'post-type-archive' => 'metadesc-ptarchive-%s',
            'system-page'       => 'metadesc-%s-wpseo',
        ],
        'bctitle' => [
            'post-type-archive' => 'bctitle-ptarchive-%s',
        ],
    ];

    public function __construct() {
        add_action( 'init', [ $this, 'register_multilingual_options' ], 20 );
        add_action( 'init', [ $this, 'register_category_base_hooks' ], 20 );
        add_filter( 'get_sample_permalink', [ $this, 'filter_sample_permalink_for_metabox' ], 20, 1 );
        add_filter( 'home_url', [ $this, 'filter_home_url_for_metabox' ], 20, 4 );

        foreach ( self::OPTIONS as $option_name ) {
            add_filter( "option_{$option_name}", [ $this, 'filter_admin_option' ], 10, 1 );
        }

        add_filter( 'option_wpseo_taxonomy_meta', [ $this, 'filter_wpseo_taxonomy_meta' ], 10, 1 );

        add_filter( 'get_post_metadata', [ $this, 'translate_primary_term_meta' ], 20, 4 );

        add_action( 'save_post', [ $this, 'handle_save_post' ], 40, 3 );
        add_action( 'created_term', [ $this, 'handle_save_term' ], 30, 3 );
        add_action( 'edited_term', [ $this, 'handle_save_term' ], 30, 3 );
        add_action( 'update_option', [ $this, 'handle_update_option' ], 20, 3 );
        add_action( 'update_option_wpseo_titles', [ $this, 'handle_update_wpseo_titles' ], 20, 2 );

        add_filter( 'wpseo_title', [ $this, 'filter_wpseo_title' ], 20, 2 );
        add_filter( 'wpseo_metadesc', [ $this, 'filter_wpseo_metadesc' ], 20, 2 );
        add_filter( 'wpseo_opengraph_title', [ $this, 'filter_wpseo_opengraph_title' ], 20, 1 );
        add_filter( 'wpseo_opengraph_desc', [ $this, 'filter_wpseo_opengraph_desc' ], 20, 1 );
        add_filter( 'wpseo_frontend_presentation', [ $this, 'filter_frontend_presentation' ], 20, 1 );
        add_filter( 'wpseo_breadcrumb_indexables', [ $this, 'filter_breadcrumb_indexables' ], 20, 1 );
        add_filter( 'wpseo_sitemap_urlset', [ $this, 'filter_sitemap_urlset' ], 20, 1 );
        add_filter( 'wpseo_sitemap_entry', [ $this, 'filter_sitemap_entry' ], 20, 3 );
        add_filter( 'wpseo_sitemap_url', [ $this, 'filter_sitemap_url' ], 20, 2 );
        add_filter( 'wpseo_sitemap_post_type_first_links', [ $this, 'filter_sitemap_post_type_first_links' ], 20, 2 );
        add_filter( 'Yoast\\WP\\News\\publication_language', [ $this, 'filter_news_publication_language' ], 20, 2 );
    }

    public function register_multilingual_options(): void {
        foreach ( self::OPTIONS as $option_name ) {
            do_action( 'wp_loc_multilingual_options', $option_name );
        }
    }

    public function filter_admin_option( $value ) {
        if ( ! is_admin() ) {
            return $value;
        }

        $admin_lang = wp_loc_get_admin_lang();
        $default_lang = WP_LOC_Languages::get_default_language();

        if ( $admin_lang === $default_lang ) {
            return $value;
        }

        $option_name = current_filter();
        $option_name = is_string( $option_name ) ? str_replace( 'option_', '', $option_name ) : '';

        if ( ! $option_name ) {
            return $value;
        }

        static $filtering = [];
        if ( isset( $filtering[ $option_name ] ) ) {
            return $value;
        }

        $filtering[ $option_name ] = true;
        $localized = get_option( "{$option_name}_{$admin_lang}" );
        unset( $filtering[ $option_name ] );

        if ( $localized !== false && $localized !== '' ) {
            return $localized;
        }

        return $value;
    }

    public function filter_wpseo_taxonomy_meta( $value ) {
        if ( ! is_array( $value ) ) {
            return $value;
        }

        $current_lang = WP_LOC_Terms::get_context_language();
        if ( ! $current_lang ) {
            return $value;
        }

        static $filtering = false;
        if ( $filtering ) {
            return $value;
        }

        $filtering = true;

        foreach ( $value as $taxonomy => $term_meta_map ) {
            if ( ! is_string( $taxonomy ) || ! WP_LOC_Terms::is_translatable( $taxonomy ) || ! is_array( $term_meta_map ) ) {
                continue;
            }

            $group_cache = [];

            foreach ( array_keys( $term_meta_map ) as $term_id ) {
                $term_id = (int) $term_id;
                if ( $term_id <= 0 ) {
                    continue;
                }

                $term_taxonomy_id = WP_LOC_Terms::get_term_taxonomy_id( $term_id, $taxonomy );
                if ( ! $term_taxonomy_id ) {
                    continue;
                }

                $trid = WP_LOC::instance()->db->get_trid( $term_taxonomy_id, WP_LOC_DB::tax_element_type( $taxonomy ) );
                if ( ! $trid ) {
                    continue;
                }

                if ( ! isset( $group_cache[ $trid ] ) ) {
                    $translations = WP_LOC::instance()->db->get_element_translations( $trid, WP_LOC_DB::tax_element_type( $taxonomy ) );
                    $term_ids = [];

                    foreach ( $translations as $lang_code => $translation ) {
                        $translated_term_id = WP_LOC_Terms::get_term_id_from_taxonomy_id( (int) $translation->element_id, $taxonomy );
                        if ( $translated_term_id ) {
                            $term_ids[ $lang_code ] = $translated_term_id;
                        }
                    }

                    $preferred_term_id = $term_ids[ $current_lang ] ?? 0;
                    $preferred_meta = $preferred_term_id && ! empty( $term_meta_map[ $preferred_term_id ] ) && is_array( $term_meta_map[ $preferred_term_id ] )
                        ? $term_meta_map[ $preferred_term_id ]
                        : null;

                    if ( ! $preferred_meta ) {
                        $source_lang = null;
                        foreach ( $translations as $lang_code => $translation ) {
                            if ( empty( $translation->source_language_code ) ) {
                                $source_lang = $lang_code;
                                break;
                            }
                        }

                        $source_term_id = $source_lang && ! empty( $term_ids[ $source_lang ] ) ? $term_ids[ $source_lang ] : 0;
                        $preferred_meta = $source_term_id && ! empty( $term_meta_map[ $source_term_id ] ) && is_array( $term_meta_map[ $source_term_id ] )
                            ? $term_meta_map[ $source_term_id ]
                            : null;
                    }

                    $group_cache[ $trid ] = [
                        'term_ids'       => array_values( array_unique( array_map( 'intval', $term_ids ) ) ),
                        'preferred_meta' => $preferred_meta,
                    ];
                }

                if ( empty( $group_cache[ $trid ]['preferred_meta'] ) || ! is_array( $group_cache[ $trid ]['preferred_meta'] ) ) {
                    continue;
                }

                foreach ( $group_cache[ $trid ]['term_ids'] as $group_term_id ) {
                    $value[ $taxonomy ][ $group_term_id ] = $group_cache[ $trid ]['preferred_meta'];
                }
            }
        }

        $filtering = false;

        return $value;
    }

    public function translate_primary_term_meta( $value, int $post_id, string $meta_key, bool $single ) {
        if ( ! isset( self::PRIMARY_TERM_META[ $meta_key ] ) ) {
            return $value;
        }

        remove_filter( 'get_post_metadata', [ $this, 'translate_primary_term_meta' ], 20 );
        $raw_value = get_post_meta( $post_id, $meta_key, true );
        add_filter( 'get_post_metadata', [ $this, 'translate_primary_term_meta' ], 20, 4 );

        if ( ! $raw_value ) {
            return $value;
        }

        $mapped_value = $this->map_term_id_to_post_language( (int) $raw_value, $post_id, self::PRIMARY_TERM_META[ $meta_key ] );

        if ( ! $mapped_value ) {
            $mapped_value = (int) $raw_value;
        }

        return $single ? $mapped_value : [ $mapped_value ];
    }

    public function handle_save_post( int $post_id, \WP_Post $post, bool $update ): void {
        if ( wp_is_post_autosave( $post_id ) || wp_is_post_revision( $post_id ) ) {
            return;
        }

        $this->normalize_primary_term_meta_for_post( $post_id );
        $this->invalidate_post_indexables( $post_id, $post->post_type );
    }

    public function handle_save_term( int $term_id, int $term_taxonomy_id, string $taxonomy ): void {
        if ( ! class_exists( 'WPSEO_Taxonomy_Meta' ) || ! WP_LOC_Terms::is_translatable( $taxonomy ) ) {
            return;
        }

        $this->copy_term_meta_from_source_translation( $term_id, $taxonomy );
        $this->invalidate_term_indexables( $term_id, $taxonomy );
    }

    public function handle_update_option( string $option, $old_value, $value ): void {
        if ( ! in_array( $option, self::OPTIONS, true ) ) {
            return;
        }

        $this->invalidate_general_indexables();
    }

    public function handle_update_wpseo_titles( $old_value, $new_value ): void {
        $old_enabled = ! empty( $old_value['stripcategorybase'] );
        $new_enabled = ! empty( $new_value['stripcategorybase'] );

        if ( $old_enabled !== $new_enabled ) {
            add_action( 'shutdown', 'flush_rewrite_rules' );
        }
    }

    public function filter_sample_permalink_for_metabox( $permalink ) {
        if ( ! is_admin() || ! is_array( $permalink ) || empty( $permalink[0] ) || ! is_string( $permalink[0] ) ) {
            return $permalink;
        }

        if ( ! $this->backtrace_has_frame( 'WPSEO_Metabox', 'localize_post_scraper_script' ) ) {
            return $permalink;
        }

        $permalink[0] = preg_replace( '#%pagename%#', '%postname%', $permalink[0] );

        return $permalink;
    }

    public function filter_home_url_for_metabox( $url, $path, $scheme, $blog_id ) {
        if ( ! is_admin() || ! is_string( $url ) || $url === '' ) {
            return $url;
        }

        if ( ! $this->backtrace_has_frame( 'WPSEO_Post_Metabox_Formatter', 'base_url_for_js' ) ) {
            return $url;
        }

        $admin_lang = wp_loc_get_admin_lang();

        return $this->prefix_url_for_language( $url, $admin_lang );
    }

    public function filter_wpseo_title( string $title, $presentation = null ): string {
        return $this->translate_presentation_value( 'title', $title, $presentation );
    }

    public function filter_wpseo_metadesc( string $description, $presentation = null ): string {
        return $this->translate_presentation_value( 'metadesc', $description, $presentation );
    }

    public function filter_wpseo_opengraph_title( string $title ): string {
        if ( ! $this->is_front_page_with_posts() ) {
            return $title;
        }

        return $this->get_social_option_value( 'open_graph_frontpage_title', $title );
    }

    public function filter_wpseo_opengraph_desc( string $description ): string {
        if ( ! $this->is_front_page_with_posts() ) {
            return $description;
        }

        return $this->get_social_option_value( 'open_graph_frontpage_desc', $description );
    }

    public function filter_frontend_presentation( $presentation ) {
        if ( ! is_object( $presentation ) || empty( $presentation->model ) || ! is_object( $presentation->model ) ) {
            return $presentation;
        }

        $object_type = $presentation->model->object_type ?? '';
        $object_id = isset( $presentation->model->object_id ) ? (int) $presentation->model->object_id : 0;
        $object_sub_type = $presentation->model->object_sub_type ?? '';

        if ( $object_type === 'post' && $object_id ) {
            $presentation->model->permalink = get_permalink( $object_id ) ?: ( $presentation->model->permalink ?? '' );
        } elseif ( $object_type === 'term' && $object_id ) {
            $term_link = get_term_link( $object_id );
            if ( ! is_wp_error( $term_link ) ) {
                $presentation->model->permalink = $term_link;
            }
        } elseif ( $object_type === 'post-type-archive' && is_string( $object_sub_type ) && $object_sub_type !== '' ) {
            $archive_link = get_post_type_archive_link( $object_sub_type );
            if ( is_string( $archive_link ) && $archive_link !== '' ) {
                $presentation->model->permalink = $archive_link;
            }
        }

        if ( isset( $presentation->canonical ) && isset( $presentation->model->permalink ) ) {
            $presentation->canonical = $presentation->model->permalink;
        }

        if ( isset( $presentation->open_graph_url ) && isset( $presentation->model->permalink ) ) {
            $presentation->open_graph_url = $presentation->model->permalink;
        }

        return $presentation;
    }

    public function filter_breadcrumb_indexables( array $indexables ): array {
        foreach ( $indexables as $indexable ) {
            if ( ! is_object( $indexable ) ) {
                continue;
            }

            $object_type = $indexable->object_type ?? '';

            if ( $object_type === 'post-type-archive' ) {
                $sub_type = $indexable->object_sub_type ?? '';
                $translated = $this->get_presentation_option_value( 'bctitle', $object_type, $sub_type );
                if ( $translated ) {
                    $indexable->breadcrumb_title = $translated;
                }

                $archive_link = is_string( $sub_type ) ? get_post_type_archive_link( $sub_type ) : '';
                if ( is_string( $archive_link ) && $archive_link !== '' ) {
                    $indexable->permalink = $archive_link;
                }
            } elseif ( $object_type === 'term' && ! empty( $indexable->object_id ) && ! empty( $indexable->object_sub_type ) ) {
                $term = get_term( (int) $indexable->object_id, (string) $indexable->object_sub_type );
                if ( $term instanceof \WP_Term ) {
                    $indexable->breadcrumb_title = $term->name;
                    $term_link = get_term_link( $term );
                    if ( ! is_wp_error( $term_link ) ) {
                        $indexable->permalink = $term_link;
                    }
                }
            } elseif ( ! empty( $indexable->permalink ) ) {
                $indexable->permalink = apply_filters( 'wpml_permalink', $indexable->permalink );
            }
        }

        return $indexables;
    }

    public function filter_sitemap_urlset( string $urlset ): string {
        if ( ! WP_LOC_Admin_Settings::is_yoast_sitemap_alternates_enabled() ) {
            return $urlset;
        }

        if ( strpos( $urlset, 'xmlns:xhtml=' ) !== false ) {
            return $urlset;
        }

        return str_replace( '>', ' xmlns:xhtml="http://www.w3.org/1999/xhtml">', $urlset );
    }

    public function filter_sitemap_entry( $entry, string $type, $object ) {
        if ( ! WP_LOC_Admin_Settings::is_yoast_sitemap_alternates_enabled() ) {
            return $entry;
        }

        if ( empty( $entry ) || ! is_array( $entry ) ) {
            return $entry;
        }

        $alternate_langs = [];

        if ( $type === 'post' && $object instanceof \WP_Post ) {
            $alternate_langs = $this->get_post_sitemap_alternate_langs( (int) $object->ID );
        } elseif ( $type === 'term' && $object instanceof \WP_Term ) {
            $alternate_langs = $this->get_term_sitemap_alternate_langs( (int) $object->term_id, (string) $object->taxonomy );
        }

        if ( ! empty( $alternate_langs ) ) {
            $entry['wp_loc_alternate_langs'] = $alternate_langs;
        }

        return $entry;
    }

    public function filter_sitemap_url( string $output, array $url ): string {
        if ( ! WP_LOC_Admin_Settings::is_yoast_sitemap_alternates_enabled() ) {
            return $output;
        }

        if ( empty( $url['wp_loc_alternate_langs'] ) || ! is_array( $url['wp_loc_alternate_langs'] ) ) {
            return $output;
        }

        $alternate_links = [];
        foreach ( $url['wp_loc_alternate_langs'] as $lang_code => $href ) {
            $alternate_links[] = sprintf(
                '<xhtml:link rel="alternate" hreflang="%s" href="%s" />',
                esc_attr( $this->get_hreflang_for_language( (string) $lang_code ) ),
                esc_url( (string) $href )
            );
        }

        if ( ! empty( $alternate_links ) ) {
            $output = str_replace( '</loc>', '</loc>' . "\n\t\t" . implode( "\n\t\t", $alternate_links ), $output );
        }

        return $output;
    }

    public function filter_sitemap_post_type_first_links( array $links, string $post_type ): array {
        if ( ! WP_LOC_Admin_Settings::is_yoast_sitemap_alternates_enabled() ) {
            return $links;
        }

        foreach ( $links as &$link ) {
            if ( empty( $link['loc'] ) || ! is_string( $link['loc'] ) ) {
                continue;
            }

            $alternate_langs = $this->get_archive_sitemap_alternate_langs( $post_type, $link['loc'] );

            if ( ! empty( $alternate_langs ) ) {
                $link['wp_loc_alternate_langs'] = $alternate_langs;
            }
        }

        return $links;
    }

    public function filter_news_publication_language( $language, $indexable ) {
        if ( ! is_object( $indexable ) || empty( $indexable->object_id ) || empty( $indexable->object_sub_type ) ) {
            return $language;
        }

        $post_lang = WP_LOC::instance()->db->get_element_language(
            (int) $indexable->object_id,
            WP_LOC_DB::post_element_type( (string) $indexable->object_sub_type )
        );

        return $post_lang ?: $language;
    }

    private function translate_presentation_value( string $kind, string $default_value, $presentation = null ): string {
        if ( ! is_object( $presentation ) || empty( $presentation->model ) || ! is_object( $presentation->model ) ) {
            return $default_value;
        }

        $object_type = $presentation->model->object_type ?? '';
        $object_sub_type = $presentation->model->object_sub_type ?? '';

        if ( $object_type === 'term' ) {
            $term_meta_value = $this->get_term_archive_meta_value(
                $kind,
                isset( $presentation->model->object_id ) ? (int) $presentation->model->object_id : 0,
                is_string( $object_sub_type ) ? $object_sub_type : ''
            );

            if ( $term_meta_value !== '' ) {
                return $term_meta_value;
            }
        }

        $translated = $this->get_presentation_option_value( $kind, $object_type, is_string( $object_sub_type ) ? $object_sub_type : '' );

        if ( ! $translated ) {
            return $default_value;
        }

        if ( function_exists( 'wpseo_replace_vars' ) ) {
            return wpseo_replace_vars( $translated, $presentation );
        }

        return $translated;
    }

    private function get_presentation_option_value( string $kind, string $object_type, string $object_sub_type = '' ): string {
        $options = get_option( 'wpseo_titles', [] );
        if ( ! is_array( $options ) || empty( self::PRESENTATION_OPTION_MAP[ $kind ][ $object_type ] ) ) {
            return '';
        }

        $key_pattern = self::PRESENTATION_OPTION_MAP[ $kind ][ $object_type ];
        $lookup_key = $key_pattern;

        if ( strpos( $key_pattern, '%s' ) !== false ) {
            $system_page_map = [
                'search-result' => 'search',
            ];
            $lookup_key = sprintf( $key_pattern, $system_page_map[ $object_sub_type ] ?? $object_sub_type );
        }

        return isset( $options[ $lookup_key ] ) && is_string( $options[ $lookup_key ] ) ? $options[ $lookup_key ] : '';
    }

    private function get_social_option_value( string $key, string $default_value ): string {
        $social = get_option( 'wpseo_social', [] );
        $titles = get_option( 'wpseo_titles', [] );

        if ( is_array( $social ) && ! empty( $social[ $key ] ) && is_string( $social[ $key ] ) ) {
            return $social[ $key ];
        }

        if ( is_array( $titles ) && ! empty( $titles[ $key ] ) && is_string( $titles[ $key ] ) ) {
            return $titles[ $key ];
        }

        return $default_value;
    }

    private function get_term_archive_meta_value( string $kind, int $term_id, string $taxonomy ): string {
        $meta_key = [
            'title'    => 'wpseo_title',
            'metadesc' => 'wpseo_desc',
        ][ $kind ] ?? '';

        if ( ! $meta_key ) {
            return '';
        }

        $term = get_queried_object();
        if ( ! $term instanceof \WP_Term ) {
            $term = $term_id && $taxonomy ? get_term( $term_id, $taxonomy ) : null;
        }

        if ( ! $term instanceof \WP_Term ) {
            return '';
        }

        $current_lang = WP_LOC_Terms::get_context_language();
        $term_lang = WP_LOC_Terms::get_term_language( (int) $term->term_id, $term->taxonomy );

        if ( $current_lang && $term_lang && $term_lang !== $current_lang ) {
            $translated_term = WP_LOC_Terms::get_translated_term( (int) $term->term_id, $term->taxonomy, $current_lang );
            if ( $translated_term instanceof \WP_Term ) {
                $term = $translated_term;
            }
        }

        $all_meta = get_option( $this->get_wpseo_term_option_name(), [] );
        if ( ! is_array( $all_meta ) || empty( $all_meta[ $term->taxonomy ][ $term->term_id ][ $meta_key ] ) || ! is_string( $all_meta[ $term->taxonomy ][ $term->term_id ][ $meta_key ] ) ) {
            return '';
        }

        return $all_meta[ $term->taxonomy ][ $term->term_id ][ $meta_key ];
    }

    public function register_category_base_hooks(): void {
        if ( ! $this->is_category_base_stripped() ) {
            return;
        }

        add_filter( 'category_rewrite_rules', [ $this, 'enable_category_rewrite_term_expansion' ], -PHP_INT_MAX );
        add_filter( 'category_rewrite_rules', [ $this, 'disable_category_rewrite_term_expansion' ], PHP_INT_MAX );
    }

    public function enable_category_rewrite_term_expansion( array $rules ): array {
        add_filter( 'get_terms', [ $this, 'append_all_category_terms_for_rewrite_rules' ], 10, 2 );
        return $rules;
    }

    public function disable_category_rewrite_term_expansion( array $rules ): array {
        remove_filter( 'get_terms', [ $this, 'append_all_category_terms_for_rewrite_rules' ], 10 );
        return $rules;
    }

    public function append_all_category_terms_for_rewrite_rules( $terms, $taxonomies ) {
        if ( ! is_array( $terms ) || empty( $terms ) || ! is_array( $taxonomies ) || ! in_array( 'category', $taxonomies, true ) ) {
            return $terms;
        }

        if ( ! ( current( $terms ) instanceof \WP_Term ) ) {
            return $terms;
        }

        global $wpdb;

        $term_ids = $wpdb->get_col(
            "SELECT t.term_id
             FROM {$wpdb->terms} t
             INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_id = t.term_id
             WHERE tt.taxonomy = 'category'"
        );

        if ( empty( $term_ids ) ) {
            return $terms;
        }

        $expanded_terms = [];
        $terms_instance = WP_LOC::instance()->terms ?? null;

        if ( $terms_instance && method_exists( $terms_instance, 'adjust_term_to_current_language' ) ) {
            remove_filter( 'get_term', [ $terms_instance, 'adjust_term_to_current_language' ], 1 );
        }

        foreach ( array_unique( array_map( 'intval', $term_ids ) ) as $term_id ) {
            $term = get_term( $term_id, 'category' );
            if ( $term instanceof \WP_Term ) {
                $expanded_terms[] = $term;
            }
        }

        if ( $terms_instance && method_exists( $terms_instance, 'adjust_term_to_current_language' ) ) {
            add_filter( 'get_term', [ $terms_instance, 'adjust_term_to_current_language' ], 1, 2 );
        }

        return ! empty( $expanded_terms ) ? $expanded_terms : $terms;
    }

    private function is_front_page_with_posts(): bool {
        return is_front_page() && get_option( 'show_on_front' ) === 'posts';
    }

    private function normalize_primary_term_meta_for_post( int $post_id ): void {
        foreach ( self::PRIMARY_TERM_META as $meta_key => $taxonomy ) {
            remove_filter( 'get_post_metadata', [ $this, 'translate_primary_term_meta' ], 20 );
            $raw_value = get_post_meta( $post_id, $meta_key, true );
            add_filter( 'get_post_metadata', [ $this, 'translate_primary_term_meta' ], 20, 4 );

            if ( ! $raw_value ) {
                continue;
            }

            $mapped_value = $this->map_term_id_to_post_language( (int) $raw_value, $post_id, $taxonomy );

            if ( $mapped_value && (int) $mapped_value !== (int) $raw_value ) {
                $this->write_post_meta_raw( $post_id, $meta_key, (string) $mapped_value );
            }
        }
    }

    private function map_term_id_to_post_language( int $term_id, int $post_id, string $taxonomy ): ?int {
        $post_type = get_post_type( $post_id );
        if ( ! $post_type ) {
            return null;
        }

        $post_lang = WP_LOC::instance()->db->get_element_language( $post_id, WP_LOC_DB::post_element_type( $post_type ) );
        if ( ! $post_lang ) {
            return null;
        }

        $term_lang = WP_LOC_Terms::get_term_language( $term_id, $taxonomy );
        if ( $term_lang === $post_lang ) {
            return $term_id;
        }

        $translated_term_id = WP_LOC_Terms::get_term_translation( $term_id, $taxonomy, $post_lang );

        return $translated_term_id ?: $term_id;
    }

    private function copy_term_meta_from_source_translation( int $term_id, string $taxonomy ): void {
        $source_term_id = $this->get_source_term_id( $term_id, $taxonomy );

        if ( ! $source_term_id || $source_term_id === $term_id ) {
            return;
        }

        $option_name = $this->get_wpseo_term_option_name();
        $all_meta = get_option( $option_name, [] );

        if ( ! is_array( $all_meta ) || empty( $all_meta[ $taxonomy ][ $source_term_id ] ) || ! is_array( $all_meta[ $taxonomy ][ $source_term_id ] ) ) {
            return;
        }

        $target_lang = WP_LOC_Terms::get_term_language( $term_id, $taxonomy );
        if ( ! $target_lang ) {
            return;
        }

        $existing_meta = $all_meta[ $taxonomy ][ $term_id ] ?? [];
        if ( ! empty( $existing_meta ) && is_array( $existing_meta ) ) {
            return;
        }

        $term_meta = $all_meta[ $taxonomy ][ $source_term_id ];

        foreach ( self::TERM_IMAGE_ID_KEYS as $id_key => $url_key ) {
            if ( empty( $term_meta[ $id_key ] ) ) {
                continue;
            }

            $translated_attachment_id = WP_LOC::instance()->db->get_element_translation(
                (int) $term_meta[ $id_key ],
                WP_LOC_DB::post_element_type( 'attachment' ),
                $target_lang
            );

            if ( $translated_attachment_id ) {
                $term_meta[ $id_key ] = $translated_attachment_id;

                $translated_url = wp_get_attachment_url( $translated_attachment_id );
                if ( $translated_url ) {
                    $term_meta[ $url_key ] = $translated_url;
                }
            }
        }

        $all_meta[ $taxonomy ][ $term_id ] = $term_meta;
        update_option( $option_name, $all_meta );
    }

    private function get_source_term_id( int $term_id, string $taxonomy ): ?int {
        $term_taxonomy_id = WP_LOC_Terms::get_term_taxonomy_id( $term_id, $taxonomy );
        if ( ! $term_taxonomy_id ) {
            return null;
        }

        $db = WP_LOC::instance()->db;
        $element_type = WP_LOC_DB::tax_element_type( $taxonomy );
        $trid = $db->get_trid( $term_taxonomy_id, $element_type );

        if ( ! $trid ) {
            return null;
        }

        $translations = $db->get_element_translations( $trid, $element_type );
        $current_lang = $db->get_element_language( $term_taxonomy_id, $element_type );

        if ( ! $current_lang || empty( $translations[ $current_lang ]->source_language_code ) ) {
            return null;
        }

        $source_lang = (string) $translations[ $current_lang ]->source_language_code;

        if ( empty( $translations[ $source_lang ]->element_id ) ) {
            return null;
        }

        return WP_LOC_Terms::get_term_id_from_taxonomy_id( (int) $translations[ $source_lang ]->element_id, $taxonomy );
    }

    private function get_wpseo_term_option_name(): string {
        if ( class_exists( 'WPSEO_Taxonomy_Meta' ) ) {
            return WPSEO_Taxonomy_Meta::get_instance()->option_name;
        }

        return 'wpseo_taxonomy_meta';
    }

    private function is_category_base_stripped(): bool {
        $options = get_option( 'wpseo_titles', [] );
        return is_array( $options ) && ! empty( $options['stripcategorybase'] );
    }

    private function get_post_sitemap_alternate_langs( int $post_id ): array {
        $post_type = get_post_type( $post_id );
        if ( ! $post_type ) {
            return [];
        }

        $element_type = WP_LOC_DB::post_element_type( $post_type );
        $trid = WP_LOC::instance()->db->get_trid( $post_id, $element_type );
        if ( ! $trid ) {
            return [];
        }

        $translations = WP_LOC::instance()->db->get_element_translations( $trid, $element_type );
        $alternate_langs = [];
        $default_lang = WP_LOC_Languages::get_default_language();

        foreach ( $translations as $lang_code => $translation ) {
            $translated_post = get_post( (int) $translation->element_id );
            if ( ! $translated_post || $translated_post->post_status !== 'publish' ) {
                continue;
            }

            $alternate_langs[ $lang_code ] = get_permalink( (int) $translation->element_id );
        }

        if ( ! empty( $alternate_langs[ $default_lang ] ) ) {
            $alternate_langs['x-default'] = $alternate_langs[ $default_lang ];
        }

        return $alternate_langs;
    }

    private function get_term_sitemap_alternate_langs( int $term_id, string $taxonomy ): array {
        $translations = WP_LOC_Terms::get_term_translations( $term_id, $taxonomy );
        if ( empty( $translations ) ) {
            return [];
        }

        $alternate_langs = [];
        $default_lang = WP_LOC_Languages::get_default_language();

        foreach ( $translations as $lang_code => $translation ) {
            $translated_term_id = WP_LOC_Terms::get_term_id_from_taxonomy_id( (int) $translation->element_id, $taxonomy );
            if ( ! $translated_term_id ) {
                continue;
            }

            $link = WP_LOC_Terms::get_term_url_for_language( $translated_term_id, $taxonomy, $lang_code );
            if ( $link ) {
                $alternate_langs[ $lang_code ] = $link;
            }
        }

        if ( ! empty( $alternate_langs[ $default_lang ] ) ) {
            $alternate_langs['x-default'] = $alternate_langs[ $default_lang ];
        }

        return $alternate_langs;
    }

    private function get_archive_sitemap_alternate_langs( string $post_type, string $source_url ): array {
        $alternate_langs = [];
        $default_lang = WP_LOC_Languages::get_default_language();
        $raw_home = rtrim( set_url_scheme( get_option( 'home' ) ), '/' );

        foreach ( array_keys( WP_LOC_Languages::get_active_languages() ) as $lang_code ) {
            $url = '';

            if ( $post_type === 'page' ) {
                $front_page_id = (int) get_option( 'page_on_front' );
                $target_page_id = $front_page_id
                    ? WP_LOC::instance()->db->get_element_translation( $front_page_id, WP_LOC_DB::post_element_type( 'page' ), $lang_code )
                    : 0;

                if ( $target_page_id ) {
                    $url = get_permalink( $target_page_id );
                } else {
                    $url = $lang_code === $default_lang ? "{$raw_home}/" : "{$raw_home}/{$lang_code}/";
                }
            } elseif ( $post_type === 'post' ) {
                $posts_page_id = (int) get_option( 'page_for_posts' );
                $target_page_id = $posts_page_id
                    ? WP_LOC::instance()->db->get_element_translation( $posts_page_id, WP_LOC_DB::post_element_type( 'page' ), $lang_code )
                    : 0;

                if ( $target_page_id ) {
                    $url = get_permalink( $target_page_id );
                }
            }

            if ( ! $url ) {
                $url = $this->prefix_url_for_language( $source_url, $lang_code );
            }

            $alternate_langs[ $lang_code ] = $url;
        }

        if ( ! empty( $alternate_langs[ $default_lang ] ) ) {
            $alternate_langs['x-default'] = $alternate_langs[ $default_lang ];
        }

        return $alternate_langs;
    }

    private function prefix_url_for_language( string $url, string $lang_code ): string {
        $default_lang = WP_LOC_Languages::get_default_language();
        $raw_home = rtrim( set_url_scheme( get_option( 'home' ) ), '/' );
        $parsed_path = (string) wp_parse_url( $url, PHP_URL_PATH );
        $path = trim( $parsed_path, '/' );
        $segments = $path === '' ? [] : explode( '/', $path );
        $active_langs = array_keys( WP_LOC_Languages::get_active_languages() );

        if ( ! empty( $segments ) && in_array( $segments[0], $active_langs, true ) ) {
            array_shift( $segments );
        }

        $path = implode( '/', $segments );

        if ( $lang_code !== $default_lang ) {
            $path = trim( "{$lang_code}/{$path}", '/' );
        }

        return $raw_home . ( $path !== '' ? '/' . $path . '/' : '/' );
    }

    private function get_hreflang_for_language( string $lang_code ): string {
        if ( $lang_code === 'x-default' ) {
            return $lang_code;
        }

        $locale = WP_LOC_Languages::get_language_locale( $lang_code );
        if ( $locale ) {
            return str_replace( '_', '-', $locale );
        }

        return $lang_code;
    }

    private function backtrace_has_frame( string $class_name, string $method_name ): bool {
        foreach ( debug_backtrace( DEBUG_BACKTRACE_IGNORE_ARGS, 20 ) as $frame ) {
            if ( ( $frame['class'] ?? '' ) === $class_name && ( $frame['function'] ?? '' ) === $method_name ) {
                return true;
            }
        }

        return false;
    }

    private function invalidate_post_indexables( int $post_id, string $post_type ): void {
        global $wpdb;

        $indexable_table = $wpdb->prefix . 'yoast_indexable';
        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $indexable_table ) ) !== $indexable_table ) {
            return;
        }

        $post_ids = [ $post_id ];
        $trid = WP_LOC::instance()->db->get_trid( $post_id, WP_LOC_DB::post_element_type( $post_type ) );

        if ( $trid ) {
            foreach ( WP_LOC::instance()->db->get_element_translations( $trid, WP_LOC_DB::post_element_type( $post_type ) ) as $translation ) {
                $post_ids[] = (int) $translation->element_id;
            }
        }

        $post_ids = array_values( array_unique( array_filter( array_map( 'intval', $post_ids ) ) ) );
        if ( empty( $post_ids ) ) {
            return;
        }

        $placeholders = implode( ',', array_fill( 0, count( $post_ids ), '%d' ) );
        $sql = "UPDATE {$indexable_table} SET version = 0 WHERE object_type = 'post' AND object_id IN ({$placeholders})";
        $wpdb->query( $wpdb->prepare( $sql, ...$post_ids ) );
    }

    private function invalidate_term_indexables( int $term_id, string $taxonomy ): void {
        global $wpdb;

        $indexable_table = $wpdb->prefix . 'yoast_indexable';
        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $indexable_table ) ) !== $indexable_table ) {
            return;
        }

        $term_ids = [ $term_id ];
        foreach ( WP_LOC_Terms::get_term_translations( $term_id, $taxonomy ) as $translation ) {
            $translated_term_id = WP_LOC_Terms::get_term_id_from_taxonomy_id( (int) $translation->element_id, $taxonomy );
            if ( $translated_term_id ) {
                $term_ids[] = $translated_term_id;
            }
        }

        $term_ids = array_values( array_unique( array_filter( array_map( 'intval', $term_ids ) ) ) );
        if ( empty( $term_ids ) ) {
            return;
        }

        $placeholders = implode( ',', array_fill( 0, count( $term_ids ), '%d' ) );
        $sql = "UPDATE {$indexable_table} SET version = 0 WHERE object_type = 'term' AND object_id IN ({$placeholders})";
        $wpdb->query( $wpdb->prepare( $sql, ...$term_ids ) );
    }

    private function invalidate_general_indexables(): void {
        global $wpdb;

        $indexable_table = $wpdb->prefix . 'yoast_indexable';
        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $indexable_table ) ) !== $indexable_table ) {
            return;
        }

        $wpdb->query(
            "UPDATE {$indexable_table} SET version = 0 WHERE object_type IN ('home-page', 'post-type-archive', 'system-page')"
        );
    }

    private function write_post_meta_raw( int $post_id, string $meta_key, string $meta_value ): void {
        global $wpdb;

        $meta_id = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT meta_id FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = %s ORDER BY meta_id ASC LIMIT 1",
                $post_id,
                $meta_key
            )
        );

        if ( $meta_id ) {
            $wpdb->update(
                $wpdb->postmeta,
                [ 'meta_value' => $meta_value ],
                [ 'meta_id' => (int) $meta_id ],
                [ '%s' ],
                [ '%d' ]
            );
            wp_cache_delete( $post_id, 'post_meta' );
            return;
        }

        add_post_meta( $post_id, $meta_key, $meta_value, true );
    }
}
