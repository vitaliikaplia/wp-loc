<?php

if ( ! defined( 'ABSPATH' ) ) exit;

class WP_LOC {

    private static $instance = null;

    public $db;
    public $languages;
    public $routing;
    public $admin;
    public $admin_languages;
    public $content;
    public $frontend;
    public $options;
    public $compat;
    public $acf;
    public $admin_settings;
    public $media;
    public $timber;

    public static function instance() {
        if ( self::$instance === null ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        $this->load_includes();
        $this->init_modules();
    }

    private function load_includes() {
        $includes = [
            'class-wp-loc-db',
            'class-wp-loc-languages',
            'class-wp-loc-routing',
            'class-wp-loc-admin',
            'class-wp-loc-admin-languages',
            'class-wp-loc-admin-settings',
            'class-wp-loc-content',
            'class-wp-loc-frontend',
            'class-wp-loc-options',
            'class-wp-loc-compat',
            'class-wp-loc-media',
            'class-wp-loc-timber',
        ];

        foreach ( $includes as $file ) {
            require_once WP_LOC_PATH . "includes/{$file}.php";
        }

        if ( class_exists( 'ACF' ) ) {
            require_once WP_LOC_PATH . 'includes/class-wp-loc-acf.php';
        }
    }

    private function init_modules() {
        $this->db             = new WP_LOC_DB();
        $this->languages      = new WP_LOC_Languages();
        $this->routing        = new WP_LOC_Routing();
        $this->content        = new WP_LOC_Content();
        $this->frontend       = new WP_LOC_Frontend();
        $this->options        = new WP_LOC_Options();

        // Settings loads on both admin and frontend (filter + is_translatable helper)
        $this->admin_settings  = new WP_LOC_Admin_Settings();
        $this->media           = new WP_LOC_Media();
        $this->timber          = new WP_LOC_Timber();

        if ( is_admin() ) {
            $this->admin           = new WP_LOC_Admin();
            $this->admin_languages = new WP_LOC_Admin_Languages();
        }

        // Compatibility layer (only when no other multilingual plugin is active)
        if ( ! defined( 'ICL_SITEPRESS_VERSION' ) ) {
            $this->compat = new WP_LOC_Compat();
        }

        if ( class_exists( 'ACF' ) ) {
            $this->acf = new WP_LOC_ACF();
        }
    }
}
