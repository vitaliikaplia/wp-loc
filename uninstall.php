<?php

/**
 * WP-LOC Uninstall
 *
 * Removes all plugin data when deleted via WordPress admin.
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) exit;

global $wpdb;

// Remove plugin options
$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE 'wp\_loc\_%'" );

// Remove localized options (e.g. blogname_ua, page_on_front_ru)
$languages = [ 'en', 'ua', 'ru' ]; // Fallback — options already deleted above, so hardcode is fine
$multilingual_options = [ 'blogname', 'blogdescription', 'page_on_front', 'page_for_posts' ];

foreach ( $multilingual_options as $option ) {
    $wpdb->query( $wpdb->prepare(
        "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
        $wpdb->esc_like( $option . '_' ) . '%'
    ) );
}

// Remove ACF localized options
$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '\_options\_%'" );

// Remove post meta
$wpdb->query( "DELETE FROM {$wpdb->postmeta} WHERE meta_key = '_wp_loc_is_new'" );

// Drop icl_translations table
$table = $wpdb->prefix . 'icl_translations';
$wpdb->query( "DROP TABLE IF EXISTS {$table}" );

// Clean rewrite rules
flush_rewrite_rules();
