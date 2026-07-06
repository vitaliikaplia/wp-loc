<?php

if ( ! defined( 'ABSPATH' ) ) exit;

if ( ! class_exists( 'WP_List_Table' ) ) {
    require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

class WP_LOC_Admin_Languages {

    public function __construct() {
        add_action( 'admin_menu', [ $this, 'add_menu' ] );
        add_action( 'admin_init', [ $this, 'handle_add_language' ] );
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
                'i18n'    => [
                    'confirmDeleteLanguage' => __( 'Delete this language? Its language files will also be removed from WordPress.', 'wp-loc' ),
                    'missingSlug' => __( 'Slug is required.', 'wp-loc' ),
                    'duplicateSlug' => __( 'Each language slug must be unique.', 'wp-loc' ),
                    'missingDisplayName' => __( 'Display name is required.', 'wp-loc' ),
                    'duplicateDisplayName' => __( 'Each display name must be unique.', 'wp-loc' ),
                ],
            ] );
        }
    }

    public function render_page(): void {
        $table = new WP_LOC_Languages_List_Table();
        $table->prepare_items();
        $languages = WP_LOC_Languages::get_languages();
        $enabled_count = count( array_filter( $languages, static fn( $language ) => ! empty( $language['enabled'] ) ) );
        $default_slug = WP_LOC_Languages::get_default_language();
        $default_name = WP_LOC_Languages::get_display_name( $default_slug );

        echo '<div class="wrap wp-loc-languages-page">';
        echo '<h1>' . esc_html__( 'Languages', 'wp-loc' ) . '</h1>';
        echo '<p class="description">' . esc_html__( 'Manage site languages, URL slugs, display labels, and ordering for the multilingual interface.', 'wp-loc' ) . '</p>';

        if ( ! empty( $_GET['saved'] ) ) {
            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Languages saved.', 'wp-loc' ) . '</p></div>';
        }

        if ( ! empty( $_GET['deleted'] ) ) {
            echo '<div class="notice notice-warning is-dismissible"><p>' . sprintf( esc_html__( 'Language "%s" deleted.', 'wp-loc' ), esc_html( $_GET['deleted'] ) ) . '</p></div>';
        }

        if ( ! empty( $_GET['added'] ) ) {
            echo '<div class="notice notice-success is-dismissible"><p>' . sprintf( esc_html__( 'Language "%s" added.', 'wp-loc' ), esc_html( $_GET['added'] ) ) . '</p></div>';
        }

        if ( ! empty( $_GET['wp_loc_language_error'] ) ) {
            echo '<div class="notice notice-error is-dismissible"><p>' . esc_html( sanitize_text_field( wp_unslash( (string) $_GET['wp_loc_language_error'] ) ) ) . '</p></div>';
        }

        if ( self::is_language_installer_enabled() ) {
            $this->render_add_language_form();
        }

        echo '<div class="wp-loc-menu-sync-summary wp-loc-languages-summary">';
        echo '<div class="wp-loc-menu-sync-summary-card">';
        echo '<span class="wp-loc-menu-sync-summary-value">' . esc_html( (string) count( $languages ) ) . '</span>';
        echo '<span class="wp-loc-menu-sync-summary-label">' . esc_html__( 'Configured languages', 'wp-loc' ) . '</span>';
        echo '</div>';
        echo '<div class="wp-loc-menu-sync-summary-card">';
        echo '<span class="wp-loc-menu-sync-summary-value">' . esc_html( (string) $enabled_count ) . '</span>';
        echo '<span class="wp-loc-menu-sync-summary-label">' . esc_html__( 'Enabled languages', 'wp-loc' ) . '</span>';
        echo '</div>';
        echo '<div class="wp-loc-menu-sync-summary-card">';
        echo '<span class="wp-loc-menu-sync-summary-value">' . esc_html( $default_name ) . '</span>';
        echo '<span class="wp-loc-menu-sync-summary-label">' . esc_html__( 'Default language', 'wp-loc' ) . '</span>';
        echo '</div>';
        echo '</div>';

        echo '<form method="post" action="' . esc_url( admin_url( 'admin.php?page=wp-loc' ) ) . '" class="wp-loc-languages-form">';
        wp_nonce_field( 'wp_loc_save_languages', '_wp_loc_nonce' );

        echo '<input type="hidden" name="wp_loc_languages_order" id="wp_loc_languages_order" value="" />';

        echo '<div class="wp-loc-languages-card">';
        $table->display();
        echo '</div>';
        echo '<div class="wp-loc-languages-actions">';
        submit_button( __( 'Save', 'wp-loc' ), 'primary', 'submit', false );
        echo '</div>';
        echo '</form>';

        echo '</div>';
    }

    public function handle_add_language(): void {
        if ( $_SERVER['REQUEST_METHOD'] !== 'POST' || ( $_POST['wp_loc_language_action'] ?? '' ) !== 'add_language' ) {
            return;
        }

        if ( ! self::is_language_installer_enabled() ) {
            wp_die( esc_html__( 'Language installer is disabled for this site.', 'wp-loc' ) );
        }

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'You do not have permission to add languages.', 'wp-loc' ) );
        }

        if ( ! check_admin_referer( 'wp_loc_add_language', '_wp_loc_add_language_nonce' ) ) {
            wp_die( 'Security check failed.' );
        }

        $locale = isset( $_POST['wp_loc_new_language'] ) ? sanitize_text_field( (string) $_POST['wp_loc_new_language'] ) : '';

        if ( $locale === '' ) {
            $this->redirect_language_error( __( 'Select a language to add.', 'wp-loc' ) );
        }

        $languages = WP_LOC_Languages::get_languages();

        foreach ( $languages as $language ) {
            if ( ( $language['locale'] ?? '' ) === $locale ) {
                $this->redirect_language_error( __( 'This language is already configured.', 'wp-loc' ) );
            }
        }

        if ( ! in_array( $locale, WP_LOC_Languages::get_installed_locales(), true ) ) {
            $installed = $this->install_language_pack( $locale );

            if ( is_wp_error( $installed ) ) {
                $this->redirect_language_error( $installed->get_error_message() );
            }
        }

        $slug = $this->get_unique_language_slug( WP_LOC_Languages::locale_to_slug( $locale ), $languages );

        $languages[ $slug ] = [
            'locale'       => $locale,
            'enabled'      => true,
            'display_name' => WP_LOC_Languages::get_language_display_name( $locale ),
            'wpml_code'    => WP_LOC_Language_Registry::wpml_code_from_locale( $locale ),
        ];

        update_option( 'wp_loc_languages', $languages );
        update_option( 'wp_loc_flush_rewrite_rules', true );
        WP_LOC_Languages::flush();

        wp_safe_redirect( add_query_arg( 'added', rawurlencode( $locale ), admin_url( 'admin.php?page=wp-loc' ) ) );
        exit;
    }

    private static function is_language_installer_enabled(): bool {
        $enabled = defined( 'WP_LOC_ENABLE_LANGUAGE_INSTALLER' )
            ? (bool) WP_LOC_ENABLE_LANGUAGE_INSTALLER
            : true;

        return (bool) apply_filters( 'wp_loc_enable_language_installer', $enabled );
    }

    private function render_add_language_form(): void {
        $languages = $this->get_addable_languages();

        echo '<div class="wp-loc-languages-card wp-loc-add-language-card">';
        echo '<h2>' . esc_html__( 'Add language', 'wp-loc' ) . '</h2>';

        if ( empty( $languages ) ) {
            echo '<p class="description">' . esc_html__( 'All installed and available WordPress languages are already configured.', 'wp-loc' ) . '</p>';
            echo '</div>';
            return;
        }

        echo '<form method="post" action="' . esc_url( admin_url( 'admin.php?page=wp-loc' ) ) . '" class="wp-loc-add-language-form">';
        wp_nonce_field( 'wp_loc_add_language', '_wp_loc_add_language_nonce' );
        echo '<input type="hidden" name="wp_loc_language_action" value="add_language" />';
        echo '<label for="wp-loc-new-language" class="screen-reader-text">' . esc_html__( 'Language', 'wp-loc' ) . '</label>';
        echo '<select id="wp-loc-new-language" name="wp_loc_new_language">';

        foreach ( $languages as $locale => $label ) {
            echo '<option value="' . esc_attr( $locale ) . '">' . esc_html( $label ) . '</option>';
        }

        echo '</select> ';
        submit_button( __( 'Add language', 'wp-loc' ), 'secondary', 'submit', false );
        echo '<p class="description">' . esc_html__( 'This installs the WordPress language pack if needed and adds the language to WP-LOC without changing the site default language.', 'wp-loc' ) . '</p>';
        echo '</form>';
        echo '</div>';
    }

    private function get_addable_languages(): array {
        $configured_locales = array_map(
            static fn( $language ) => (string) ( $language['locale'] ?? '' ),
            WP_LOC_Languages::get_languages()
        );

        $languages = [];

        foreach ( WP_LOC_Languages::get_installed_locales() as $locale ) {
            if ( in_array( $locale, $configured_locales, true ) ) {
                continue;
            }

            $languages[ $locale ] = sprintf(
                '%s (%s)',
                WP_LOC_Languages::get_language_display_name( $locale ),
                $locale
            );
        }

        if ( ! function_exists( 'wp_get_available_translations' ) ) {
            require_once ABSPATH . 'wp-admin/includes/translation-install.php';
        }

        foreach ( wp_get_available_translations() as $locale => $translation ) {
            if ( in_array( $locale, $configured_locales, true ) || isset( $languages[ $locale ] ) ) {
                continue;
            }

            $native = isset( $translation['native_name'] ) ? (string) $translation['native_name'] : WP_LOC_Languages::get_language_display_name( $locale );
            $english = isset( $translation['english_name'] ) ? (string) $translation['english_name'] : '';
            $languages[ $locale ] = $english && $english !== $native
                ? sprintf( '%1$s / %2$s (%3$s)', $native, $english, $locale )
                : sprintf( '%1$s (%2$s)', $native, $locale );
        }

        natcasesort( $languages );

        return $languages;
    }

    private function install_language_pack( string $locale ) {
        if ( $locale === 'en_US' ) {
            return true;
        }

        if ( ! function_exists( 'wp_download_language_pack' ) ) {
            require_once ABSPATH . 'wp-admin/includes/translation-install.php';
        }

        $result = wp_download_language_pack( $locale );

        if ( is_wp_error( $result ) ) {
            return $result;
        }

        if ( ! $result ) {
            return new WP_Error( 'wp_loc_language_install_failed', __( 'The language pack could not be installed.', 'wp-loc' ) );
        }

        return true;
    }

    private function get_unique_language_slug( string $slug, array $languages ): string {
        $base = sanitize_title( $slug ) ?: 'lang';
        $slug = $base;
        $index = 2;

        while ( isset( $languages[ $slug ] ) ) {
            $slug = $base . '-' . $index;
            $index++;
        }

        return $slug;
    }

    private function redirect_language_error( string $message ): void {
        wp_safe_redirect( add_query_arg( 'wp_loc_language_error', rawurlencode( $message ), admin_url( 'admin.php?page=wp-loc' ) ) );
        exit;
    }

    public function handle_save(): void {
        if ( $_SERVER['REQUEST_METHOD'] !== 'POST' || ! isset( $_POST['wp_loc_languages'] ) ) return;

        if ( ! check_admin_referer( 'wp_loc_save_languages', '_wp_loc_nonce' ) ) {
            wp_die( 'Security check failed.' );
        }

        $langs = [];
        $slugs = [];
        $display_names = [];
        $current_languages = WP_LOC_Languages::get_languages();
        $current_default_slug = WP_LOC_Languages::get_default_language();
        $current_default_locale = (string) ( $current_languages[ $current_default_slug ]['locale'] ?? '' );
        $has_explicit_default = get_option( WP_LOC_Languages::DEFAULT_LANGUAGE_OPTION_KEY, '' ) !== '';

        foreach ( $_POST['wp_loc_languages'] as $locale => $data ) {
            $slug = sanitize_title( $data['slug'] ?? '' );
            if ( ! $slug ) {
                wp_die( sprintf( __( 'Missing slug for locale %s', 'wp-loc' ), esc_html( $locale ) ) );
            }
            if ( in_array( $slug, $slugs, true ) ) {
                wp_die( sprintf( __( 'Duplicate slug "%s" detected.', 'wp-loc' ), esc_html( $slug ) ) );
            }
            $slugs[] = $slug;

            $display_name = trim( sanitize_text_field( $data['display_name'] ?? '' ) );
            if ( $display_name === '' ) {
                wp_die( sprintf( __( 'Missing display name for locale %s', 'wp-loc' ), esc_html( $locale ) ) );
            }

            $display_name_key = function_exists( 'mb_strtolower' )
                ? mb_strtolower( $display_name )
                : strtolower( $display_name );

            if ( in_array( $display_name_key, $display_names, true ) ) {
                wp_die( sprintf( __( 'Duplicate display name "%s" detected.', 'wp-loc' ), esc_html( $display_name ) ) );
            }
            $display_names[] = $display_name_key;

            $langs[ $slug ] = [
                'locale'       => sanitize_text_field( $locale ),
                'enabled'      => ! empty( $data['enabled'] ),
                'display_name' => $display_name,
                'wpml_code'    => WP_LOC_Language_Registry::wpml_code_from_locale( (string) $locale ),
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

        if ( $has_explicit_default && $current_default_locale ) {
            foreach ( $langs as $slug => $data ) {
                if ( ( $data['locale'] ?? '' ) === $current_default_locale ) {
                    WP_LOC_Languages::set_default_language( $slug );
                    break;
                }
            }
        }

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
            if ( $slug_to_remove === WP_LOC_Languages::get_default_language() ) {
                wp_die( esc_html__( 'The default language cannot be deleted.', 'wp-loc' ) );
            }

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
        $default_slug = WP_LOC_Languages::get_default_language();

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
                'is_default'   => ( $slug === $default_slug ),
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
        return '<span class="lang-drag-handle" aria-hidden="true">&#x2630;</span>';
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
        $name = WP_LOC_Languages::get_language_display_name( $item['locale'] );
        $default_badge = $item['is_default']
            ? '<span class="wp-loc-language-badge">' . esc_html__( 'Default', 'wp-loc' ) . '</span>'
            : '';

        return '<div class="wp-loc-language-name-cell"><strong>' . esc_html( $name ) . '</strong>' . $default_badge . '</div>';
    }

    public function column_locale( $item ): string {
        return esc_html( $item['locale'] );
    }

    public function column_slug( $item ): string {
        return '<input type="text" name="wp_loc_languages[' . esc_attr( $item['locale'] ) . '][slug]" value="' . esc_attr( $item['slug'] ) . '" class="wp-loc-slug-input" required maxlength="24" autocapitalize="off" autocomplete="off" spellcheck="false" />';
    }

    public function column_display_name( $item ): string {
        return '<input type="text" name="wp_loc_languages[' . esc_attr( $item['locale'] ) . '][display_name]" value="' . esc_attr( $item['display_name'] ) . '" class="wp-loc-display-name-input" required maxlength="60" autocomplete="off" />';
    }

    public function column_flag( $item ): string {
        $url = WP_LOC_Languages::get_flag_url( $item['locale'] );
        return '<span class="wp-loc-flag-chip"><img class="wp-loc-flag-small" src="' . esc_url( $url ) . '" alt="" /></span>';
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
