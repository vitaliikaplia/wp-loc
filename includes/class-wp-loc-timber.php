<?php

if ( ! defined( 'ABSPATH' ) ) exit;

class WP_LOC_Timber {

    private bool $bootstrapped = false;

    public function __construct() {
        add_action( 'after_setup_theme', [ $this, 'maybe_bootstrap' ], 20 );
        add_action( 'init', [ $this, 'maybe_bootstrap' ], 1 );
    }

    /**
     * Register Timber integration when Timber is available.
     * This supports both the Timber plugin and theme-level Timber bootstrap.
     */
    public function maybe_bootstrap(): void {
        if ( $this->bootstrapped ) {
            return;
        }

        if ( ! class_exists( '\Timber\Timber' ) ) {
            return;
        }

        add_filter( 'timber/twig', [ $this, 'add_to_twig' ] );
        add_filter( 'timber/context', [ $this, 'add_to_context' ] );

        $this->bootstrapped = true;
    }

    /**
     * Register WP-LOC Twig helpers.
     */
    public function add_to_twig( $twig ) {
        if ( ! class_exists( '\Twig\TwigFunction' ) ) {
            return $twig;
        }

        $twig->addFunction( new \Twig\TwigFunction(
            'wp_loc_language_switcher',
            'wp_loc_get_language_switcher_html',
            [ 'is_safe' => [ 'html' ] ]
        ) );

        $twig->addFunction( new \Twig\TwigFunction(
            'wp_loc_languages',
            'wp_loc_get_lang_switcher'
        ) );

        $twig->addFunction( new \Twig\TwigFunction(
            'wp_loc_translate',
            [ $this, 'get_translated_post' ]
        ) );

        $twig->addFunction( new \Twig\TwigFunction(
            'wp_loc_translations',
            [ $this, 'get_post_translations' ]
        ) );

        return $twig;
    }

    /**
     * Add language data to Timber context.
     */
    public function add_to_context( array $context ): array {
        $current = wp_loc_get_current_lang();
        $locale  = wp_loc_get_current_locale();

        $context['current_language'] = [
            'code'   => $current,
            'locale' => $locale,
            'name'   => WP_LOC_Languages::get_display_name( $current ),
            'flag'   => WP_LOC_Languages::get_flag_url( $locale ),
        ];

        // Override cached Site properties with localized values
        if ( isset( $context['site'] ) ) {
            $context['site']->name        = get_bloginfo( 'name' );
            $context['site']->description = get_bloginfo( 'description' );
            $context['site']->url         = home_url();
        }

        return $context;
    }

    /**
     * Get a translated Timber\Post for a given post and language.
     *
     * Usage in Twig: wp_loc_translate(post, 'en')
     * Returns null if no translation exists.
     */
    public function get_translated_post( $post, string $lang ) {
        $post_id = $post instanceof \WP_Post ? $post->ID : (int) $post;
        if ( is_object( $post ) && property_exists( $post, 'ID' ) ) {
            $post_id = (int) $post->ID;
        }

        $post_type = get_post_type( $post_id );
        if ( ! $post_type ) {
            return null;
        }

        $element_type  = WP_LOC_DB::post_element_type( $post_type );
        $translated_id = WP_LOC::instance()->db->get_element_translation( $post_id, $element_type, $lang );

        if ( ! $translated_id ) {
            return null;
        }

        if ( class_exists( '\Timber\Post' ) ) {
            return \Timber\Timber::get_post( $translated_id );
        }

        return get_post( $translated_id );
    }

    /**
     * Get all translations as Timber\Post objects keyed by language code.
     *
     * Usage in Twig: wp_loc_translations(post)
     * Returns: { 'uk': TimberPost, 'en': TimberPost, ... }
     */
    public function get_post_translations( $post ): array {
        $post_id = $post instanceof \WP_Post ? $post->ID : (int) $post;
        if ( is_object( $post ) && property_exists( $post, 'ID' ) ) {
            $post_id = (int) $post->ID;
        }

        $post_type = get_post_type( $post_id );
        if ( ! $post_type ) {
            return [];
        }

        $db           = WP_LOC::instance()->db;
        $element_type = WP_LOC_DB::post_element_type( $post_type );
        $trid         = $db->get_trid( $post_id, $element_type );

        if ( ! $trid ) {
            return [];
        }

        $rows   = $db->get_element_translations( $trid, $element_type );
        $active = WP_LOC_Languages::get_active_languages();
        $result = [];

        foreach ( $rows as $slug => $row ) {
            if ( ! isset( $active[ $slug ] ) ) {
                continue;
            }

            $translated_post = get_post( $row->element_id );
            if ( ! $translated_post || $translated_post->post_status !== 'publish' ) {
                continue;
            }

            if ( class_exists( '\Timber\Post' ) ) {
                $result[ $slug ] = \Timber\Timber::get_post( $row->element_id );
            } else {
                $result[ $slug ] = $translated_post;
            }
        }

        return $result;
    }
}
