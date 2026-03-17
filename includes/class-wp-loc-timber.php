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

        return $twig;
    }
}
