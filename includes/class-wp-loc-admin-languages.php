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
        add_action( 'admin_init', [ $this, 'handle_add' ] );
        add_action( 'admin_init', [ $this, 'handle_make_default' ] );

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
                    'addingLanguage' => __( 'Adding the language and downloading its WordPress translation files…', 'wp-loc' ),
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
        echo '<p class="description">' . esc_html__( 'The default language opens at the site root and is also the WordPress site language under Settings → General: changing one changes the other.', 'wp-loc' ) . '</p>';

        if ( ! empty( $_GET['saved'] ) ) {
            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Languages saved.', 'wp-loc' ) . '</p></div>';
        }

        if ( ! empty( $_GET['deleted'] ) ) {
            echo '<div class="notice notice-warning is-dismissible"><p>' . sprintf( esc_html__( 'Language "%s" deleted.', 'wp-loc' ), esc_html( $_GET['deleted'] ) ) . '</p></div>';
        }

        $this->render_action_notices( $languages );

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

        $this->render_add_form();

        echo '</div>';
    }

    private function render_add_form(): void {
        $addable = WP_LOC_Language_Setup::get_addable_languages();
        $installed = array_values( array_filter( $addable, static fn( array $language ): bool => $language['installed'] ) );
        $available = array_values( array_filter( $addable, static fn( array $language ): bool => ! $language['installed'] ) );

        echo '<div class="wp-loc-language-add">';
        echo '<h2>' . esc_html__( 'Add a language', 'wp-loc' ) . '</h2>';
        echo '<p class="description">' . esc_html__( 'WordPress downloads the translation files for the new language, as when you pick it as the Site Language under Settings → General, but the site language and the default language stay the same. The new language is enabled at once.', 'wp-loc' ) . '</p>';

        if ( ! $addable ) {
            echo '<p>' . esc_html__( 'There are no other languages to add.', 'wp-loc' ) . '</p>';
            echo '</div>';
            return;
        }

        echo '<form method="post" action="' . esc_url( admin_url( 'admin.php?page=wp-loc' ) ) . '" class="wp-loc-language-add-form">';
        wp_nonce_field( 'wp_loc_add_language', '_wp_loc_add_nonce' );
        echo '<label class="screen-reader-text" for="wp-loc-new-language">' . esc_html__( 'Language', 'wp-loc' ) . '</label>';
        echo '<select name="wp_loc_add_language" id="wp-loc-new-language" required>';
        echo '<option value="">' . esc_html__( '— Select a language —', 'wp-loc' ) . '</option>';

        foreach ( [
            __( 'Installed', 'wp-loc' ) => $installed,
            __( 'Available', 'wp-loc' ) => $available,
        ] as $label => $group ) {
            if ( ! $group ) {
                continue;
            }

            echo '<optgroup label="' . esc_attr( $label ) . '">';

            foreach ( $group as $language ) {
                echo '<option value="' . esc_attr( $language['locale'] ) . '">' . esc_html( $language['name'] . ' (' . $language['locale'] . ')' ) . '</option>';
            }

            echo '</optgroup>';
        }

        echo '</select>';
        submit_button( __( 'Add language', 'wp-loc' ), 'secondary', 'submit', false );
        echo '<span class="spinner"></span>';
        echo '</form>';

        if ( ! $available && ! WP_LOC_Language_Setup::can_install_files() ) {
            echo '<p class="description">' . esc_html__( 'This site cannot download translation files, so only languages already installed in WordPress are listed.', 'wp-loc' ) . '</p>';
        }

        echo '</div>';
    }

    private function render_action_notices( array $languages ): void {
        $files_notice = function ( string $files, string $name ): void {
            if ( $files === 'installed' ) {
                echo '<div class="notice notice-success is-dismissible"><p>' . sprintf( esc_html__( 'WordPress translation files for %s have been installed.', 'wp-loc' ), esc_html( $name ) ) . '</p></div>';
            } elseif ( $files === 'failed' ) {
                echo '<div class="notice notice-warning is-dismissible"><p>' . sprintf( esc_html__( 'The WordPress translation files for %s could not be downloaded, so WordPress shows its interface in English for it. Try again later under Dashboard → Updates or Settings → General.', 'wp-loc' ), esc_html( $name ) ) . '</p></div>';
            } elseif ( $files === 'not_allowed' ) {
                echo '<div class="notice notice-warning is-dismissible"><p>' . sprintf( esc_html__( 'The WordPress translation files for %s were not installed: this site or your account cannot install languages.', 'wp-loc' ), esc_html( $name ) ) . '</p></div>';
            }
        };

        $added = sanitize_key( (string) ( $_GET['added'] ?? '' ) );

        if ( $added && isset( $languages[ $added ] ) ) {
            $name = WP_LOC_Languages::get_language_display_name( (string) $languages[ $added ]['locale'] );
            echo '<div class="notice notice-success is-dismissible"><p>' . sprintf( esc_html__( 'Language %s added.', 'wp-loc' ), '<strong>' . esc_html( $name ) . '</strong>' ) . '</p></div>';
            $files_notice( sanitize_key( (string) ( $_GET['files'] ?? '' ) ), $name );
        }

        $default = sanitize_key( (string) ( $_GET['default'] ?? '' ) );

        if ( $default && isset( $languages[ $default ] ) ) {
            $name = WP_LOC_Languages::get_language_display_name( (string) $languages[ $default ]['locale'] );
            echo '<div class="notice notice-success is-dismissible"><p>' . sprintf( esc_html__( '%s is now the default language and the WordPress site language.', 'wp-loc' ), '<strong>' . esc_html( $name ) . '</strong>' ) . '</p></div>';
            $files_notice( sanitize_key( (string) ( $_GET['files'] ?? '' ) ), $name );
        }

        $error = sanitize_key( (string) ( $_GET['wp_loc_error'] ?? '' ) );
        $messages = [
            'wp_loc_unknown_locale'    => __( 'This language cannot be added.', 'wp-loc' ),
            'wp_loc_inactive_language' => __( 'Only an enabled language can be the default one.', 'wp-loc' ),
            'wp_loc_same_language'     => __( 'This language is already the default one.', 'wp-loc' ),
            'wp_loc_switch_failed'     => __( 'The default language could not be changed; nothing was changed.', 'wp-loc' ),
        ];

        if ( isset( $messages[ $error ] ) ) {
            echo '<div class="notice notice-error is-dismissible"><p>' . esc_html( $messages[ $error ] ) . '</p></div>';
        }
    }

    public function handle_add(): void {
        if ( ( $_SERVER['REQUEST_METHOD'] ?? '' ) !== 'POST' || ! isset( $_POST['wp_loc_add_language'] ) ) return;

        if ( ! current_user_can( 'manage_options' ) || ! check_admin_referer( 'wp_loc_add_language', '_wp_loc_add_nonce' ) ) {
            wp_die( esc_html__( 'Security check failed.', 'wp-loc' ) );
        }

        $result = WP_LOC_Language_Setup::add_language( sanitize_text_field( wp_unslash( (string) $_POST['wp_loc_add_language'] ) ) );
        $redirect = remove_query_arg( [ 'saved', 'deleted', 'added', 'files', 'default', 'wp_loc_error' ], admin_url( 'admin.php?page=wp-loc' ) );

        if ( is_wp_error( $result ) ) {
            wp_safe_redirect( add_query_arg( 'wp_loc_error', $result->get_error_code(), $redirect ) );
            exit;
        }

        wp_safe_redirect( add_query_arg( [ 'added' => $result['slug'], 'files' => $result['files'] ], $redirect ) );
        exit;
    }

    public function handle_make_default(): void {
        if ( ! isset( $_GET['action'], $_GET['locale'] ) || $_GET['action'] !== 'wp_loc_make_default' ) return;

        $locale = sanitize_text_field( wp_unslash( (string) $_GET['locale'] ) );

        if ( ! current_user_can( 'manage_options' ) || ! wp_verify_nonce( $_GET['_wpnonce'] ?? '', 'wp_loc_make_default_' . $locale ) ) {
            wp_die( esc_html__( 'Security check failed.', 'wp-loc' ) );
        }

        $redirect = admin_url( 'admin.php?page=wp-loc' );
        $slug = WP_LOC_Languages::get_language_slug( $locale );
        $result = $slug !== null
            ? WP_LOC_Language_Setup::switch_default_language( $slug )
            : new WP_Error( 'wp_loc_inactive_language' );

        if ( is_wp_error( $result ) ) {
            wp_safe_redirect( add_query_arg( 'wp_loc_error', $result->get_error_code(), $redirect ) );
            exit;
        }

        wp_safe_redirect( add_query_arg( [ 'default' => $result['to'], 'files' => $result['files'] ], $redirect ) );
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

            // Fold the Greek final sigma: mb_strtolower() only applies it since PHP 8.3,
            // so the key would otherwise differ between PHP versions.
            $display_name_key = function_exists( 'mb_strtolower' )
                ? str_replace( 'ς', 'σ', mb_strtolower( $display_name ) )
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
            "$base/plugins/*-{$locale}-*.json",
            "$base/themes/*-{$locale}-*.json",
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
        if ( $item['is_default'] ) {
            return '<span class="wp-loc-default-only">&ndash;</span>';
        }

        $links = [];

        if ( $item['enabled'] ) {
            $default_slug = WP_LOC_Languages::get_default_language();
            $url = wp_nonce_url(
                add_query_arg( [
                    'action' => 'wp_loc_make_default',
                    'locale' => $item['locale'],
                ], admin_url( 'admin.php?page=wp-loc' ) ),
                'wp_loc_make_default_' . $item['locale']
            );
            $confirm = sprintf(
                /* translators: 1: language name, 2: current default language name, 3: URL prefix the current default language moves to */
                __( 'Make %1$s the default language? It will open at the site root and %2$s will move to /%3$s/. The WordPress site language becomes %1$s too, and the site title, homepage, ACF options and Yoast settings saved for %1$s become the main ones.', 'wp-loc' ),
                WP_LOC_Languages::get_language_display_name( $item['locale'] ),
                WP_LOC_Languages::get_language_display_name( WP_LOC_Languages::get_language_locale( $default_slug ) ),
                $default_slug
            );
            $links[] = '<a href="' . esc_url( $url ) . '" class="wp-loc-make-default-link" data-confirm="' . esc_attr( $confirm ) . '">' . esc_html__( 'Make default', 'wp-loc' ) . '</a>';
        }

        if ( $item['locale'] !== 'en_US' ) {
            $url = wp_nonce_url(
                add_query_arg( [
                    'action' => 'wp_loc_delete_lang',
                    'locale' => $item['locale'],
                ], admin_url( 'admin.php?page=wp-loc' ) ),
                'wp_loc_delete_lang_' . $item['locale']
            );

            $links[] = '<a href="' . esc_url( $url ) . '" class="wp-loc-delete-link">' . esc_html__( 'Delete', 'wp-loc' ) . '</a>';
        }

        return $links ? '<span class="wp-loc-language-actions">' . implode( '', $links ) . '</span>' : '<span class="wp-loc-default-only">&ndash;</span>';
    }

    public function column_default( $item, $column_name ): string {
        return '';
    }
}
