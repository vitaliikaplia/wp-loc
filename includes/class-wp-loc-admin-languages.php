<?php

if ( ! defined( 'ABSPATH' ) ) exit;

if ( ! class_exists( 'WP_List_Table' ) ) {
    require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

class WP_LOC_Admin_Languages {

    public function __construct() {
        add_action( 'admin_menu', [ $this, 'add_menu' ] );
        add_action( 'admin_init', [ $this, 'handle_save' ] );
        add_action( 'admin_init', [ $this, 'handle_delete' ] );

        add_action( 'wp_ajax_wp_loc_save_order', [ $this, 'ajax_save_order' ] );
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_scripts' ] );
    }

    public function add_menu(): void {
        // Top-level menu
        $menu_title = __( 'Multilingual', 'wp-loc' );

        add_menu_page(
            $menu_title,
            $menu_title,
            'manage_options',
            'wp-loc',
            [ $this, 'render_page' ],
            'dashicons-translation',
            80
        );

        // Languages submenu (same as parent)
        add_submenu_page(
            'wp-loc',
            __( 'Languages', 'wp-loc' ),
            __( 'Languages', 'wp-loc' ),
            'manage_options',
            'wp-loc',
            [ $this, 'render_page' ]
        );
    }

    public function enqueue_scripts( string $hook ): void {
        if ( $hook === 'toplevel_page_wp-loc' ) {
            $admin_js_version = file_exists( WP_LOC_PATH . 'assets/js/admin.min.js' ) ? (string) filemtime( WP_LOC_PATH . 'assets/js/admin.min.js' ) : WP_LOC_VERSION;
            wp_enqueue_script( 'jquery-ui-sortable' );
            // Re-register wp-loc-admin with sortable dependency on this page
            wp_deregister_script( 'wp-loc-admin' );
            wp_enqueue_script( 'wp-loc-admin', WP_LOC_URL . 'assets/js/admin.min.js', [ 'jquery', 'jquery-ui-sortable' ], $admin_js_version, true );
            wp_localize_script( 'wp-loc-admin', 'wpLocAdmin', [
                'ajaxUrl' => admin_url( 'admin-ajax.php' ),
                'nonce'   => wp_create_nonce( 'wp_loc_ajax' ),
            ] );
        }
    }

    public function render_page(): void {
        $table = new WP_LOC_Languages_List_Table();
        $table->prepare_items();

        echo '<div class="wrap"><h1>' . esc_html__( 'Languages', 'wp-loc' ) . '</h1>';

        if ( ! empty( $_GET['saved'] ) ) {
            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Languages saved.', 'wp-loc' ) . '</p></div>';
        }

        if ( ! empty( $_GET['deleted'] ) ) {
            echo '<div class="notice notice-warning is-dismissible"><p>' . sprintf( esc_html__( 'Language "%s" deleted.', 'wp-loc' ), esc_html( $_GET['deleted'] ) ) . '</p></div>';
        }

        echo '<form method="post" action="' . esc_url( admin_url( 'admin.php?page=wp-loc' ) ) . '">';
        wp_nonce_field( 'wp_loc_save_languages', '_wp_loc_nonce' );

        echo '<input type="hidden" name="wp_loc_languages_order" id="wp_loc_languages_order" value="" />';

        $table->display();
        submit_button( __( 'Save', 'wp-loc' ) );
        echo '</form>';

        echo '</div>';
    }

    public function handle_save(): void {
        if ( $_SERVER['REQUEST_METHOD'] !== 'POST' || ! isset( $_POST['wp_loc_languages'] ) ) return;

        if ( ! check_admin_referer( 'wp_loc_save_languages', '_wp_loc_nonce' ) ) {
            wp_die( 'Security check failed.' );
        }

        $langs = [];
        $slugs = [];

        foreach ( $_POST['wp_loc_languages'] as $locale => $data ) {
            $slug = sanitize_title( $data['slug'] ?? '' );
            if ( ! $slug ) {
                wp_die( sprintf( __( 'Missing slug for locale %s', 'wp-loc' ), esc_html( $locale ) ) );
            }
            if ( in_array( $slug, $slugs, true ) ) {
                wp_die( sprintf( __( 'Duplicate slug "%s" detected.', 'wp-loc' ), esc_html( $slug ) ) );
            }
            $slugs[] = $slug;

            $display_name = sanitize_text_field( $data['display_name'] ?? strtoupper( $slug ) );

            $langs[ $slug ] = [
                'locale'       => sanitize_text_field( $locale ),
                'enabled'      => ! empty( $data['enabled'] ),
                'display_name' => $display_name,
            ];
        }

        // Apply sort order if provided
        $order_str = $_POST['wp_loc_languages_order'] ?? '';
        if ( $order_str ) {
            $order = array_map( 'sanitize_text_field', explode( ',', $order_str ) );
            // Rebuild $langs keyed by slug in the order of locales
            $ordered = [];
            foreach ( $order as $locale ) {
                // Find slug for this locale
                foreach ( $langs as $slug => $data ) {
                    if ( $data['locale'] === $locale ) {
                        $ordered[ $slug ] = $data;
                        break;
                    }
                }
            }
            // Append any remaining
            foreach ( $langs as $slug => $data ) {
                if ( ! isset( $ordered[ $slug ] ) ) {
                    $ordered[ $slug ] = $data;
                }
            }
            $langs = $ordered;
        }

        update_option( 'wp_loc_languages', $langs );
        WP_LOC_Languages::flush();

        update_option( 'wp_loc_flush_rewrite_rules', true );

        wp_redirect( add_query_arg( 'saved', 1 ) );
        exit;
    }

    public function ajax_save_order(): void {
        check_ajax_referer( 'wp_loc_ajax', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error();
        }

        $order = array_map( 'sanitize_text_field', $_POST['order'] ?? [] );
        if ( empty( $order ) ) {
            wp_send_json_error();
        }

        $languages = WP_LOC_Languages::get_languages();
        $ordered   = [];

        foreach ( $order as $locale ) {
            foreach ( $languages as $slug => $data ) {
                if ( ( $data['locale'] ?? '' ) === $locale ) {
                    $ordered[ $slug ] = $data;
                    break;
                }
            }
        }

        // Append any remaining
        foreach ( $languages as $slug => $data ) {
            if ( ! isset( $ordered[ $slug ] ) ) {
                $ordered[ $slug ] = $data;
            }
        }

        update_option( 'wp_loc_languages', $ordered );
        WP_LOC_Languages::flush();

        wp_send_json_success();
    }

    public function handle_delete(): void {
        if ( ! isset( $_GET['action'], $_GET['locale'] ) || $_GET['action'] !== 'wp_loc_delete_lang' ) return;

        $locale = sanitize_text_field( $_GET['locale'] );

        if ( ! wp_verify_nonce( $_GET['_wpnonce'] ?? '', 'wp_loc_delete_lang_' . $locale ) ) {
            wp_die( 'Invalid nonce.' );
        }

        $languages = WP_LOC_Languages::get_languages();

        // Find and remove
        $slug_to_remove = null;
        foreach ( $languages as $slug => $data ) {
            if ( ( $data['locale'] ?? '' ) === $locale ) {
                $slug_to_remove = $slug;
                break;
            }
        }

        if ( $slug_to_remove ) {
            unset( $languages[ $slug_to_remove ] );
            update_option( 'wp_loc_languages', $languages );
            WP_LOC_Languages::flush();

            // Remove all language files so it disappears from WP General Settings too
            if ( $locale !== 'en_US' ) {
                self::delete_language_files( $locale );
            }
        }

        update_option( 'wp_loc_flush_rewrite_rules', true );

        wp_redirect( add_query_arg( 'deleted', $locale, remove_query_arg( [ 'action', 'locale', '_wpnonce' ] ) ) );
        exit;
    }

    private static function delete_language_files( string $locale ): void {
        $base = WP_CONTENT_DIR . '/languages';
        $patterns = [
            "$base/$locale.*",
            "$base/admin-$locale.*",
            "$base/admin-network-$locale.*",
            "$base/core/$locale.*",
            "$base/plugins/*-$locale.*",
            "$base/themes/*-$locale.*",
            "$base/{$locale}-*.json",
            "$base/continents-cities-{$locale}.*",
        ];

        foreach ( $patterns as $pattern ) {
            foreach ( glob( $pattern ) as $file ) {
                if ( is_file( $file ) ) {
                    @unlink( $file );
                }
            }
        }
    }

}

class WP_LOC_Languages_List_Table extends WP_List_Table {

    public function get_columns(): array {
        return [
            'sort'    => '',
            'enabled' => __( 'Enabled', 'wp-loc' ),
            'name'    => __( 'Name', 'wp-loc' ),
            'locale'  => __( 'Locale', 'wp-loc' ),
            'slug'         => __( 'Slug', 'wp-loc' ),
            'display_name' => __( 'Display Name', 'wp-loc' ),
            'flag'         => __( 'Flag', 'wp-loc' ),
            'delete'       => __( 'Action', 'wp-loc' ),
        ];
    }

    public function prepare_items(): void {
        $languages = WP_LOC_Languages::get_languages();
        $system_locale = get_option( 'WPLANG' ) ?: 'en_US';

        $items = [];
        $seen_locales = [];

        // Only show configured languages (no auto-adding from .mo files)
        foreach ( $languages as $slug => $data ) {
            $locale = $data['locale'] ?? $slug;

            // Prevent duplicate locales
            if ( isset( $seen_locales[ $locale ] ) ) continue;
            $seen_locales[ $locale ] = true;

            $items[] = [
                'locale'       => $locale,
                'slug'         => $slug,
                'display_name' => $data['display_name'] ?? strtoupper( $slug ),
                'enabled'      => ! empty( $data['enabled'] ),
                'is_default'   => ( $locale === $system_locale ),
            ];
        }

        $this->items = $items;
        $this->_column_headers = [ $this->get_columns(), [], [] ];
    }

    protected function get_table_classes(): array {
        return [ 'widefat', 'fixed', 'striped', 'wp-loc-languages-table' ];
    }

    public function single_row( $item ): void {
        echo '<tr data-locale="' . esc_attr( $item['locale'] ) . '">';
        $this->single_row_columns( $item );
        echo '</tr>';
    }

    public function column_sort( $item ): string {
        return '<span class="lang-drag-handle">&#x2630;</span>';
    }

    public function column_enabled( $item ): string {
        $checked = $item['enabled'] ? 'checked' : '';
        $field_name = 'wp_loc_languages[' . esc_attr( $item['locale'] ) . '][enabled]';

        if ( $item['is_default'] ) {
            return '<input type="hidden" name="' . $field_name . '" value="1" />'
                . '<input type="checkbox" value="1" ' . $checked . ' disabled class="wp-loc-default-language-toggle" />';
        }

        return '<input type="checkbox" name="' . $field_name . '" value="1" ' . $checked . ' />';
    }

    public function column_name( $item ): string {
        return esc_html( WP_LOC_Languages::get_language_display_name( $item['locale'] ) );
    }

    public function column_locale( $item ): string {
        return esc_html( $item['locale'] );
    }

    public function column_slug( $item ): string {
        return '<input type="text" name="wp_loc_languages[' . esc_attr( $item['locale'] ) . '][slug]" value="' . esc_attr( $item['slug'] ) . '" class="wp-loc-slug-input" required />';
    }

    public function column_display_name( $item ): string {
        return '<input type="text" name="wp_loc_languages[' . esc_attr( $item['locale'] ) . '][display_name]" value="' . esc_attr( $item['display_name'] ) . '" class="wp-loc-slug-input" />';
    }

    public function column_flag( $item ): string {
        $url = WP_LOC_Languages::get_flag_url( $item['locale'] );
        return '<img class="wp-loc-flag-small" src="' . esc_url( $url ) . '" alt="" />';
    }

    public function column_delete( $item ): string {
        if ( $item['is_default'] || $item['locale'] === 'en_US' ) {
            return '<span class="wp-loc-default-only">&ndash;</span>';
        }

        $url = wp_nonce_url(
            add_query_arg( [
                'action' => 'wp_loc_delete_lang',
                'locale' => $item['locale'],
            ] ),
            'wp_loc_delete_lang_' . $item['locale']
        );

        return '<a href="' . esc_url( $url ) . '" class="wp-loc-delete-link">' . esc_html__( 'Delete', 'wp-loc' ) . '</a>';
    }

    public function column_default( $item, $column_name ): string {
        return '';
    }
}
