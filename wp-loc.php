<?php

/*
Plugin Name: WP-LOC
Description: Lightweight multilanguage plugin for WordPress
Version: 1.0.0
Author: Vitalii Kaplia
Author URI: https://vitaliikaplia.com/
License: GPLv2 or later
Text Domain: wp-loc
*/

if ( ! defined( 'ABSPATH' ) ) exit;

define( 'WP_LOC_VERSION', '1.0.0' );
define( 'WP_LOC_PATH', plugin_dir_path( __FILE__ ) );
define( 'WP_LOC_URL', plugin_dir_url( __FILE__ ) );
define( 'WP_LOC_BASENAME', plugin_basename( __FILE__ ) );

require_once WP_LOC_PATH . 'includes/class-wp-loc-db.php';
require_once WP_LOC_PATH . 'includes/class-wp-loc.php';

register_activation_hook( __FILE__, 'wp_loc_activate' );
register_deactivation_hook( __FILE__, 'wp_loc_deactivate' );

function wp_loc_activate(): void {
    wp_loc_deactivate_conflicting_plugins();
    WP_LOC_DB::activate();
}

function wp_loc_deactivate_conflicting_plugins(): void {
    if ( ! function_exists( 'deactivate_plugins' ) ) {
        require_once ABSPATH . 'wp-admin/includes/plugin.php';
    }

    $conflicting_plugins = [
        'sitepress-multilingual-cms/sitepress.php',
        'wp-seo-multilingual/plugin.php',
        'acfml/wpml-acf.php',
    ];

    $plugins_to_deactivate = [];
    $active_plugins = (array) get_option( 'active_plugins', [] );
    $network_active_plugins = is_multisite() ? array_keys( (array) get_site_option( 'active_sitewide_plugins', [] ) ) : [];
    $active_plugin_files = array_merge( $active_plugins, $network_active_plugins );

    foreach ( $conflicting_plugins as $plugin_file ) {
        if ( in_array( $plugin_file, $active_plugin_files, true ) ) {
            $plugins_to_deactivate[] = $plugin_file;
        }
    }

    if ( $plugins_to_deactivate ) {
        deactivate_plugins( array_values( array_unique( $plugins_to_deactivate ) ) );
    }
}

function wp_loc_deactivate(): void {
    flush_rewrite_rules();
    delete_option( 'wp_loc_flush_rewrite_rules' );
}

add_action( 'admin_post_wp_loc_restart_db_optimization_wizard', function (): void {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_die( esc_html__( 'Sorry, you are not allowed to do that.', 'wp-loc' ) );
    }

    check_admin_referer( 'wp_loc_restart_db_optimization_wizard' );
    update_option( 'wp_loc_db_optimization_wizard_status', 'pending' );

    wp_safe_redirect( admin_url( 'plugins.php' ) );
    exit;
} );

add_action( 'plugins_loaded', function () {
    load_plugin_textdomain( 'wp-loc', false, dirname( WP_LOC_BASENAME ) . '/languages' );
    WP_LOC::instance();
} );

add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), function ( array $links ): array {
    $links[] = '<a href="' . admin_url( 'admin.php?page=wp-loc-settings' ) . '">' . __( 'Settings', 'wp-loc' ) . '</a>';

    if ( current_user_can( 'manage_options' ) && get_option( 'wp_loc_db_optimization_wizard_status', 'pending' ) !== 'completed' ) {
        $wizard_url = wp_nonce_url(
            admin_url( 'admin-post.php?action=wp_loc_restart_db_optimization_wizard' ),
            'wp_loc_restart_db_optimization_wizard'
        );

        $links[] = '<a href="' . esc_url( $wizard_url ) . '">' . __( 'Wizard', 'wp-loc' ) . '</a>';
    }

    return $links;
} );
