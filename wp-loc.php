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

register_activation_hook( __FILE__, [ 'WP_LOC_DB', 'activate' ] );
register_deactivation_hook( __FILE__, 'wp_loc_deactivate' );

function wp_loc_deactivate(): void {
    flush_rewrite_rules();
    delete_option( 'wp_loc_flush_rewrite_rules' );
}

add_action( 'plugins_loaded', function () {
    load_plugin_textdomain( 'wp-loc', false, dirname( WP_LOC_BASENAME ) . '/languages' );
    WP_LOC::instance();
} );

add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), function ( array $links ): array {
    $links[] = '<a href="' . admin_url( 'admin.php?page=wp-loc' ) . '">' . __( 'Languages', 'wp-loc' ) . '</a>';
    $links[] = '<a href="' . admin_url( 'admin.php?page=wp-loc-settings' ) . '">' . __( 'Settings', 'wp-loc' ) . '</a>';
    return $links;
} );
