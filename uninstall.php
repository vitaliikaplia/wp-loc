<?php

/**
 * WP-LOC Uninstall
 *
 * Removes all plugin data when deleted via WordPress admin.
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) exit;

global $wpdb;

$languages = get_option( 'wp_loc_languages', [] );
$language_slugs = [];
$language_locales = [];

if ( is_array( $languages ) ) {
    foreach ( $languages as $slug => $language ) {
        if ( is_string( $slug ) && $slug !== '' ) {
            $language_slugs[] = $slug;
        }

        if ( ! empty( $language['locale'] ) && is_string( $language['locale'] ) ) {
            $language_locales[] = $language['locale'];
        }
    }
}

$language_slugs = array_values( array_unique( $language_slugs ) );
$language_locales = array_values( array_unique( $language_locales ) );

// Remove plugin options
$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE 'wp\_loc\_%'" );

// Remove localized options (e.g. blogname_ua, page_on_front_ru)
$multilingual_options = [ 'blogname', 'blogdescription', 'page_on_front', 'page_for_posts' ];

foreach ( $multilingual_options as $option ) {
    $wpdb->query( $wpdb->prepare(
        "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
        $wpdb->esc_like( $option . '_' ) . '%'
    ) );
}

// Remove only WP-LOC-managed ACF translated options/reference rows.
$acf_options_post_ids = [ 'options' ];
if ( function_exists( 'acf_get_options_pages' ) ) {
    $options_pages = acf_get_options_pages();

    if ( is_array( $options_pages ) ) {
        foreach ( $options_pages as $options_page ) {
            if ( ! empty( $options_page['post_id'] ) && is_string( $options_page['post_id'] ) ) {
                $acf_options_post_ids[] = $options_page['post_id'];
            }
        }
    }
}

$acf_options_post_ids = array_values( array_unique( $acf_options_post_ids ) );
$acf_language_tokens = array_values( array_unique( array_merge( $language_slugs, $language_locales ) ) );

foreach ( $acf_options_post_ids as $base_post_id ) {
    foreach ( $acf_language_tokens as $token ) {
        $wpdb->query( $wpdb->prepare(
            "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
            $wpdb->esc_like( "{$base_post_id}_{$token}_" ) . '%'
        ) );

        $wpdb->query( $wpdb->prepare(
            "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
            $wpdb->esc_like( "_{$base_post_id}_{$token}_" ) . '%'
        ) );
    }
}

// Remove post meta
$wpdb->query( "DELETE FROM {$wpdb->postmeta} WHERE meta_key = '_wp_loc_is_new'" );

// Drop icl_translations table
$table = $wpdb->prefix . 'icl_translations';
$wpdb->query( "DROP TABLE IF EXISTS {$table}" );

// Clean rewrite rules
flush_rewrite_rules();
