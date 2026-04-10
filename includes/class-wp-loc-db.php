<?php

if ( ! defined( 'ABSPATH' ) ) exit;

class WP_LOC_DB {

    private $table;
    private const TRID_LOCK_NAME = 'wp_loc_trid_generation';

    public function __construct() {
        global $wpdb;
        $this->table = $wpdb->prefix . 'icl_translations';
    }

    /**
     * Create icl_translations table if not exists
     */
    public static function activate() {
        global $wpdb;

        $table = $wpdb->prefix . 'icl_translations';
        $charset = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE {$table} (
            translation_id bigint(20) NOT NULL AUTO_INCREMENT,
            element_type varchar(60) NOT NULL DEFAULT '',
            element_id bigint(20) DEFAULT NULL,
            trid bigint(20) NOT NULL,
            language_code varchar(7) NOT NULL,
            source_language_code varchar(7) DEFAULT NULL,
            PRIMARY KEY  (translation_id),
            UNIQUE KEY element_id (element_type, element_id),
            KEY trid (trid, language_code)
        ) {$charset};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta( $sql );

        update_option( 'wp_loc_db_version', WP_LOC_VERSION );
        flush_rewrite_rules();
    }

    /**
     * Get trid for an element
     */
    public function get_trid( int $element_id, string $element_type ): ?int {
        $cache_key = "trid_{$element_type}_{$element_id}";
        $cached = wp_cache_get( $cache_key, 'wp_loc' );

        if ( $cached !== false ) {
            return $cached ?: null;
        }

        global $wpdb;

        $trid = $wpdb->get_var( $wpdb->prepare(
            "SELECT trid FROM {$this->table} WHERE element_id = %d AND element_type = %s LIMIT 1",
            $element_id,
            $element_type
        ) );

        $result = $trid ? (int) $trid : null;
        wp_cache_set( $cache_key, $result ?: 0, 'wp_loc' );

        return $result;
    }

    /**
     * Get all translations for a trid
     *
     * @return array [ 'uk' => object{element_id, language_code, source_language_code}, ... ]
     */
    public function get_element_translations( int $trid, string $element_type = '' ): array {
        $cache_key = "translations_{$trid}";
        $cached = wp_cache_get( $cache_key, 'wp_loc' );

        if ( $cached !== false ) {
            return $cached;
        }

        global $wpdb;

        $where = $wpdb->prepare( "WHERE trid = %d", $trid );
        if ( $element_type ) {
            $where .= $wpdb->prepare( " AND element_type = %s", $element_type );
        }

        $rows = $wpdb->get_results( "SELECT element_id, language_code, source_language_code FROM {$this->table} {$where}" );

        $result = [];
        foreach ( $rows as $row ) {
            $row->element_id = (int) $row->element_id;
            $result[ $row->language_code ] = $row;
        }

        wp_cache_set( $cache_key, $result, 'wp_loc' );

        return $result;
    }

    /**
     * Get translated element ID for a target language
     */
    public function get_element_translation( int $element_id, string $element_type, string $target_lang ): ?int {
        $trid = $this->get_trid( $element_id, $element_type );
        if ( ! $trid ) return null;

        $translations = $this->get_element_translations( $trid, $element_type );

        if ( isset( $translations[ $target_lang ] ) ) {
            return (int) $translations[ $target_lang ]->element_id;
        }

        return null;
    }

    /**
     * Get language code for an element
     */
    public function get_element_language( int $element_id, string $element_type ): ?string {
        $cache_key = "lang_{$element_type}_{$element_id}";
        $cached = wp_cache_get( $cache_key, 'wp_loc' );

        if ( $cached !== false ) {
            return $cached ?: null;
        }

        global $wpdb;

        $lang = $wpdb->get_var( $wpdb->prepare(
            "SELECT language_code FROM {$this->table} WHERE element_id = %d AND element_type = %s LIMIT 1",
            $element_id,
            $element_type
        ) );

        wp_cache_set( $cache_key, $lang ?: '', 'wp_loc' );

        return $lang ?: null;
    }

    /**
     * Register or update element in translation table
     *
     * @return int trid
     */
    public function set_element_language( int $element_id, string $element_type, string $language_code, ?int $trid = null, ?string $source_language_code = null ): int {
        global $wpdb;

        $existing = $wpdb->get_row( $wpdb->prepare(
            "SELECT translation_id, trid FROM {$this->table} WHERE element_id = %d AND element_type = %s LIMIT 1",
            $element_id,
            $element_type
        ) );

        $existing_trid = $existing ? (int) $existing->trid : null;
        $lock_acquired = false;

        // Reuse an existing trid for updates; only allocate a new one for brand-new elements.
        if ( $trid === null ) {
            if ( $existing_trid ) {
                $trid = $existing_trid;
            } else {
                $lock_acquired = $this->acquire_trid_lock();
                $max_trid = (int) $wpdb->get_var( "SELECT MAX(trid) FROM {$this->table}" );
                $trid = $max_trid + 1;
            }
        }

        if ( $existing ) {
            $wpdb->update(
                $this->table,
                [
                    'trid'                 => $trid,
                    'language_code'        => $language_code,
                    'source_language_code' => $source_language_code,
                ],
                [
                    'element_id'   => $element_id,
                    'element_type' => $element_type,
                ],
                [ '%d', '%s', '%s' ],
                [ '%d', '%s' ]
            );
        } else {
            $wpdb->insert(
                $this->table,
                [
                    'element_type'         => $element_type,
                    'element_id'           => $element_id,
                    'trid'                 => $trid,
                    'language_code'        => $language_code,
                    'source_language_code' => $source_language_code,
                ],
                [ '%s', '%d', '%d', '%s', '%s' ]
            );
        }

        if ( $lock_acquired ) {
            $this->release_trid_lock();
        }

        $this->bust_cache( $element_id, $element_type, $existing_trid );
        if ( $existing_trid !== $trid ) {
            $this->bust_cache( $element_id, $element_type, $trid );
        }

        return $trid;
    }

    /**
     * Remove element from translation table
     */
    public function delete_element( int $element_id, string $element_type ): void {
        $trid = $this->get_trid( $element_id, $element_type );

        global $wpdb;

        $wpdb->delete(
            $this->table,
            [
                'element_id'   => $element_id,
                'element_type' => $element_type,
            ],
            [ '%d', '%s' ]
        );

        $this->bust_cache( $element_id, $element_type, $trid );
    }

    /**
     * Get element_type string for a post type
     */
    public static function post_element_type( string $post_type ): string {
        return 'post_' . $post_type;
    }

    /**
     * Get element_type string for a taxonomy
     */
    public static function tax_element_type( string $taxonomy ): string {
        return 'tax_' . $taxonomy;
    }

    /**
     * Get the table name
     */
    public function get_table(): string {
        return $this->table;
    }

    /**
     * Clear caches for an element
     */
    private function bust_cache( int $element_id, string $element_type, ?int $trid = null ): void {
        wp_cache_delete( "trid_{$element_type}_{$element_id}", 'wp_loc' );
        wp_cache_delete( "lang_{$element_type}_{$element_id}", 'wp_loc' );

        if ( $trid ) {
            wp_cache_delete( "translations_{$trid}", 'wp_loc' );
        }
    }

    private function acquire_trid_lock(): bool {
        global $wpdb;

        return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 5)', self::TRID_LOCK_NAME ) ) === 1;
    }

    private function release_trid_lock(): void {
        global $wpdb;

        $wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', self::TRID_LOCK_NAME ) );
    }
}
