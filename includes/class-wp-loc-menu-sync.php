<?php

if ( ! defined( 'ABSPATH' ) ) exit;

class WP_LOC_Menu_Sync {

    private const PAGE_SLUG = 'wp-loc-tools';
    private const TAB_MENU_SYNC = 'menu-sync';
    private const TAB_AI_TRANSLATE = 'ai-translate';
    private const TAB_CONFIG_MIGRATION = 'config-migration';
    private const CONFIG_ACTION_WRITE_CURRENT = 'write-current-config';
    private const CONFIG_ACTION_WRITE_FROM_WPML = 'write-from-wpml-config';
    private const CONFIG_ACTION_DELETE_WPML = 'delete-wpml-config';

    public function __construct() {
        add_action( 'admin_menu', [ $this, 'add_menu' ], 15 );
        add_action( 'admin_init', [ $this, 'handle_config_actions' ] );
        add_action( 'wp_ajax_wp_loc_menu_sync_preview', [ $this, 'ajax_preview' ] );
        add_action( 'wp_ajax_wp_loc_menu_sync_apply', [ $this, 'ajax_apply' ] );
        add_action( 'wp_ajax_wp_loc_ai_translate', [ $this, 'ajax_ai_translate' ] );
    }

    public function add_menu(): void {
        add_submenu_page(
            'wp-loc',
            __( 'Tools', 'wp-loc' ),
            __( 'Tools', 'wp-loc' ),
            'manage_options',
            self::PAGE_SLUG,
            [ $this, 'render_page' ]
        );
    }

    private function get_current_tab(): string {
        $tab = isset( $_GET['tab'] ) ? sanitize_key( (string) $_GET['tab'] ) : self::TAB_MENU_SYNC;

        return in_array( $tab, [ self::TAB_MENU_SYNC, self::TAB_AI_TRANSLATE, self::TAB_CONFIG_MIGRATION ], true ) ? $tab : self::TAB_MENU_SYNC;
    }

    private function get_tab_url( string $tab ): string {
        return add_query_arg(
            [
                'page' => self::PAGE_SLUG,
                'tab' => $tab,
            ],
            admin_url( 'admin.php' )
        );
    }

    private function render_tabs( string $current_tab ): void {
        $tabs = [
            self::TAB_MENU_SYNC => __( 'WP Menus Sync', 'wp-loc' ),
            self::TAB_AI_TRANSLATE => __( 'AI Translation', 'wp-loc' ),
            self::TAB_CONFIG_MIGRATION => __( 'Config Migration', 'wp-loc' ),
        ];

        echo '<nav class="nav-tab-wrapper wp-clearfix">';

        foreach ( $tabs as $tab => $label ) {
            $class = $tab === $current_tab ? 'nav-tab nav-tab-active' : 'nav-tab';
            echo '<a href="' . esc_url( $this->get_tab_url( $tab ) ) . '" class="' . esc_attr( $class ) . '">' . esc_html( $label ) . '</a>';
        }

        echo '</nav>';
    }

    private function is_tools_page_request(): bool {
        return is_admin()
            && isset( $_GET['page'] )
            && sanitize_key( (string) $_GET['page'] ) === self::PAGE_SLUG;
    }

    public function handle_config_actions(): void {
        if ( ! $this->is_tools_page_request() || strtoupper( $_SERVER['REQUEST_METHOD'] ?? '' ) !== 'POST' ) {
            return;
        }

        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        $action = isset( $_POST['wp_loc_config_action'] ) ? sanitize_key( (string) $_POST['wp_loc_config_action'] ) : '';

        if ( ! in_array( $action, [ self::CONFIG_ACTION_WRITE_CURRENT, self::CONFIG_ACTION_WRITE_FROM_WPML, self::CONFIG_ACTION_DELETE_WPML ], true ) ) {
            return;
        }

        check_admin_referer( 'wp_loc_tools_config_action', 'wp_loc_tools_config_nonce' );

        $redirect = add_query_arg(
            [
                'page' => self::PAGE_SLUG,
                'tab'  => self::TAB_CONFIG_MIGRATION,
            ],
            admin_url( 'admin.php' )
        );

        if ( $action === self::CONFIG_ACTION_WRITE_CURRENT ) {
            $result = $this->write_current_wp_loc_config();
            wp_safe_redirect( add_query_arg( $result, $redirect ) );
            exit;
        }

        $source_path = isset( $_POST['wp_loc_config_source'] ) ? wp_unslash( (string) $_POST['wp_loc_config_source'] ) : '';
        $sources = $this->get_config_sources();
        $source = $this->find_config_source( $source_path, $sources );

        if ( ! $source ) {
            wp_safe_redirect( add_query_arg( [ 'wp_loc_config_notice' => 'source-missing', 'wp_loc_config_status' => 'error' ], $redirect ) );
            exit;
        }

        if ( $action === self::CONFIG_ACTION_WRITE_FROM_WPML ) {
            $result = $this->write_wp_loc_config_from_source( $source );
            wp_safe_redirect( add_query_arg( $result, $redirect ) );
            exit;
        }

        $result = $this->delete_wpml_config_from_source( $source );
        wp_safe_redirect( add_query_arg( $result, $redirect ) );
        exit;
    }

    public function ajax_preview(): void {
        $this->assert_ajax_permissions();

        wp_send_json_success( [
            'html' => $this->get_sync_content_html(),
        ] );
    }

    public function ajax_apply(): void {
        $this->assert_ajax_permissions();

        $selected = isset( $_POST['sync'] ) && is_array( $_POST['sync'] ) ? $_POST['sync'] : [];
        $summary = $this->apply_sync_selection( $selected );

        wp_send_json_success( [
            'message' => sprintf(
                __( 'Menu sync complete. Synced %1$d targets, created %2$d menus, created %3$d items, skipped %4$d untranslated linked items.', 'wp-loc' ),
                $summary['applied'],
                $summary['created'],
                $summary['items'],
                $summary['skipped']
            ),
            'html' => $this->get_sync_content_html(),
        ] );
    }

    public function ajax_ai_translate(): void {
        $this->assert_ajax_permissions();

        $content = isset( $_POST['content'] ) ? wp_unslash( (string) $_POST['content'] ) : '';
        $target_lang = isset( $_POST['target_lang'] ) ? sanitize_key( (string) $_POST['target_lang'] ) : '';
        $active_languages = WP_LOC_Languages::get_active_languages();

        if ( $content === '' ) {
            wp_send_json_error( [ 'message' => __( 'Enter text to translate first.', 'wp-loc' ) ], 400 );
        }

        if ( ! $target_lang || ! isset( $active_languages[ $target_lang ] ) ) {
            wp_send_json_error( [ 'message' => __( 'Select a target language.', 'wp-loc' ) ], 400 );
        }

        $translated = WP_LOC_AI::translate_content( $content, WP_LOC_AI::get_target_language_name( $target_lang ) );

        if ( $translated === '' ) {
            wp_send_json_error( [ 'message' => __( 'Translation failed.', 'wp-loc' ) ], 500 );
        }

        wp_send_json_success( [
            'content' => $translated,
            'message' => __( 'Translation inserted into the editor.', 'wp-loc' ),
        ] );
    }

    private function assert_ajax_permissions(): void {
        check_ajax_referer( 'wp_loc_ajax', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => __( 'You are not allowed to do this.', 'wp-loc' ) ], 403 );
        }
    }

    private function apply_sync_selection( array $selected ): array {
        $summary = [
            'applied' => 0,
            'created' => 0,
            'items' => 0,
            'skipped' => 0,
        ];

        foreach ( $selected as $source_menu_id => $languages ) {
            $source_menu_id = (int) $source_menu_id;

            if ( ! $source_menu_id || ! is_array( $languages ) ) {
                continue;
            }

            foreach ( array_keys( $languages ) as $lang ) {
                $result = WP_LOC::instance()->menus->sync_menu_translation( $source_menu_id, sanitize_key( (string) $lang ) );

                if ( empty( $result['success'] ) ) {
                    continue;
                }

                $summary['applied']++;
                $summary['created'] += ! empty( $result['created_menu'] ) ? 1 : 0;
                $summary['items'] += (int) ( $result['created_items'] ?? 0 );
                $summary['skipped'] += (int) ( $result['skipped_items'] ?? 0 );
            }
        }

        $this->clear_general_fields_cache();

        return $summary;
    }

    private function clear_general_fields_cache(): void {
        foreach ( array_keys( WP_LOC_Languages::get_active_languages() ) as $lang ) {
            delete_transient( 'general_fields_' . $lang );
        }
    }

    private function get_preview_data(): array {
        return WP_LOC::instance()->menus->build_sync_preview();
    }

    private function get_preview_stats( array $preview ): array {
        $menus_total = count( $preview );
        $targets_total = 0;
        $targets_needing_sync = 0;
        $warnings_total = 0;

        foreach ( $preview as $menu ) {
            foreach ( $menu['languages'] as $cell ) {
                $targets_total++;

                if ( ! empty( $cell['needs_sync'] ) ) {
                    $targets_needing_sync++;
                }

                foreach ( $cell['operations'] as $operation ) {
                    if ( in_array( $operation['type'], [ 'skipped', 'warning' ], true ) ) {
                        $warnings_total++;
                    }
                }
            }
        }

        return [
            'menus_total' => $menus_total,
            'targets_total' => $targets_total,
            'targets_needing_sync' => $targets_needing_sync,
            'warnings_total' => $warnings_total,
        ];
    }

    private function get_status_label( array $cell ): string {
        foreach ( $cell['operations'] as $operation ) {
            if ( $operation['type'] === 'menu_translation' ) {
                return __( 'Missing translation', 'wp-loc' );
            }
        }

        foreach ( $cell['operations'] as $operation ) {
            if ( $operation['type'] === 'skipped' ) {
                return __( 'Needs review', 'wp-loc' );
            }
        }

        if ( ! empty( $cell['needs_sync'] ) ) {
            return __( 'Needs sync', 'wp-loc' );
        }

        return __( 'Up to date', 'wp-loc' );
    }

    private function get_status_class( array $cell ): string {
        foreach ( $cell['operations'] as $operation ) {
            if ( $operation['type'] === 'menu_translation' ) {
                return 'is-missing';
            }
        }

        foreach ( $cell['operations'] as $operation ) {
            if ( $operation['type'] === 'skipped' ) {
                return 'is-warning';
            }
        }

        if ( ! empty( $cell['needs_sync'] ) ) {
            return 'is-pending';
        }

        return 'is-ok';
    }

    private function get_operation_badge_class( string $type ): string {
        return match ( $type ) {
            'menu_translation' => 'is-missing',
            'options_changed' => 'is-option',
            'ai_translation' => 'is-option',
            'skipped', 'warning' => 'is-warning',
            default => 'is-structure',
        };
    }

    private function get_operation_badge_label( string $type ): string {
        return match ( $type ) {
            'menu_translation' => __( 'Translation', 'wp-loc' ),
            'options_changed' => __( 'Option', 'wp-loc' ),
            'ai_translation' => __( 'AI', 'wp-loc' ),
            'skipped' => __( 'Warning', 'wp-loc' ),
            default => __( 'Structure', 'wp-loc' ),
        };
    }

    private function get_toolbar_html( array $stats, string $extra_class = '' ): string {
        ob_start();
        ?>
        <div class="wp-loc-menu-sync-toolbar <?php echo esc_attr( trim( $extra_class ) ); ?>">
            <div class="wp-loc-menu-sync-toolbar-actions">
                <button type="button" class="button button-primary wp-loc-menu-sync-apply" <?php disabled( $stats['targets_needing_sync'] === 0 ); ?>>
                    <?php esc_html_e( 'Apply changes', 'wp-loc' ); ?>
                </button>
                <button type="button" class="button wp-loc-menu-sync-refresh">
                    <?php esc_html_e( 'Refresh', 'wp-loc' ); ?>
                </button>
            </div>
            <div class="wp-loc-menu-sync-toolbar-actions">
                <button type="button" class="button-link wp-loc-menu-sync-select-all"><?php esc_html_e( 'Select all', 'wp-loc' ); ?></button>
                <button type="button" class="button-link wp-loc-menu-sync-deselect-all"><?php esc_html_e( 'Deselect all', 'wp-loc' ); ?></button>
            </div>
        </div>
        <?php

        return (string) ob_get_clean();
    }

    private function get_ai_translate_html(): string {
        $active_languages = WP_LOC_Languages::get_active_languages();

        ob_start();
        ?>
        <div class="wp-loc-ai-translate-tool">
            <div class="wp-loc-ai-translate-editor">
                <?php
                wp_editor(
                    '',
                    'wp_loc_ai_translate_editor',
                    [
                        'textarea_name' => 'wp_loc_ai_translate_editor',
                        'textarea_rows' => 16,
                        'media_buttons' => false,
                        'teeny' => false,
                    ]
                );
                ?>
            </div>

            <div class="wp-loc-ai-translate-actions">
                <label for="wp-loc-ai-target-lang" class="screen-reader-text"><?php esc_html_e( 'Translate to', 'wp-loc' ); ?></label>
                <select id="wp-loc-ai-target-lang" class="wp-loc-ai-target-lang">
                    <option value=""><?php esc_html_e( 'Select language', 'wp-loc' ); ?></option>
                    <?php foreach ( $active_languages as $lang => $data ) : ?>
                        <option value="<?php echo esc_attr( $lang ); ?>"><?php echo esc_html( WP_LOC_Languages::get_display_name( $lang ) ); ?></option>
                    <?php endforeach; ?>
                </select>
                <button type="button" class="button button-primary wp-loc-ai-translate-submit" disabled="disabled"><?php esc_html_e( 'Translate', 'wp-loc' ); ?></button>
            </div>

            <div class="wp-loc-menu-sync-feedback wp-loc-ai-translate-feedback" aria-live="polite"></div>
        </div>
        <?php

        return (string) ob_get_clean();
    }

    private function get_current_settings_payload(): array {
        $post_types = array_values( array_filter( array_map( 'sanitize_key', (array) get_option( WP_LOC_Admin_Settings::OPTION_KEY, [ 'post', 'page' ] ) ) ) );
        $taxonomies = array_values( array_filter( array_map( 'sanitize_key', (array) get_option( WP_LOC_Admin_Settings::TAXONOMIES_OPTION_KEY, [ 'category', 'post_tag' ] ) ) ) );

        sort( $post_types );
        sort( $taxonomies );

        return [
            'post_types' => array_values( array_unique( $post_types ) ),
            'taxonomies' => array_values( array_unique( $taxonomies ) ),
        ];
    }

    private function get_config_roots(): array {
        $roots = [];
        $stylesheet_dir = get_stylesheet_directory();
        $template_dir = get_template_directory();

        if ( $stylesheet_dir ) {
            $roots[] = [
                'path' => $stylesheet_dir,
                'type' => 'theme',
                'label' => __( 'Active theme', 'wp-loc' ),
            ];
        }

        if ( $template_dir && $template_dir !== $stylesheet_dir ) {
            $roots[] = [
                'path' => $template_dir,
                'type' => 'theme',
                'label' => __( 'Parent theme', 'wp-loc' ),
            ];
        }

        foreach ( (array) get_option( 'active_plugins', [] ) as $plugin_basename ) {
            $plugin_dirname = dirname( $plugin_basename );
            $plugin_dir = $plugin_dirname === '.' ? WP_PLUGIN_DIR : WP_PLUGIN_DIR . '/' . $plugin_dirname;

            if ( ! is_dir( $plugin_dir ) ) {
                continue;
            }

            $roots[] = [
                'path' => $plugin_dir,
                'type' => 'plugin',
                'label' => sprintf(
                    /* translators: %s: plugin directory */
                    __( 'Plugin: %s', 'wp-loc' ),
                    basename( $plugin_dir )
                ),
            ];
        }

        $unique = [];

        foreach ( $roots as $root ) {
            $real = realpath( $root['path'] );

            if ( ! $real || isset( $unique[ $real ] ) ) {
                continue;
            }

            $root['path'] = $real;
            $unique[ $real ] = $root;
        }

        return array_values( $unique );
    }

    private function parse_wpml_config_file( string $path ): array {
        $result = [
            'post_types' => [],
            'taxonomies' => [],
            'valid' => false,
        ];

        if ( ! is_readable( $path ) ) {
            return $result;
        }

        $previous = libxml_use_internal_errors( true );
        $xml = simplexml_load_file( $path, 'SimpleXMLElement', LIBXML_NONET | LIBXML_NOCDATA );
        libxml_clear_errors();
        libxml_use_internal_errors( $previous );

        if ( ! $xml instanceof \SimpleXMLElement ) {
            return $result;
        }

        if ( isset( $xml->{'custom-types'}->{'custom-type'} ) ) {
            foreach ( $xml->{'custom-types'}->{'custom-type'} as $node ) {
                $translate = (string) ( $node['translate'] ?? $node['action'] ?? '' );
                $slug = sanitize_key( trim( (string) $node ) );

                if ( $slug !== '' && in_array( $translate, [ '1', 'translate', 'true', 'yes' ], true ) ) {
                    $result['post_types'][] = $slug;
                }
            }
        }

        if ( isset( $xml->taxonomies->taxonomy ) ) {
            foreach ( $xml->taxonomies->taxonomy as $node ) {
                $translate = (string) ( $node['translate'] ?? $node['action'] ?? '' );
                $slug = sanitize_key( trim( (string) $node ) );

                if ( $slug !== '' && in_array( $translate, [ '1', 'translate', 'true', 'yes' ], true ) ) {
                    $result['taxonomies'][] = $slug;
                }
            }
        }

        $result['post_types'] = array_values( array_unique( $result['post_types'] ) );
        $result['taxonomies'] = array_values( array_unique( $result['taxonomies'] ) );
        $result['valid'] = true;

        return $result;
    }

    private function parse_wp_loc_config_file( string $path ): array {
        $result = [
            'post_types' => [],
            'taxonomies' => [],
            'valid' => false,
        ];

        if ( ! is_readable( $path ) ) {
            return $result;
        }

        $previous = libxml_use_internal_errors( true );
        $xml = simplexml_load_file( $path, 'SimpleXMLElement', LIBXML_NONET | LIBXML_NOCDATA );
        libxml_clear_errors();
        libxml_use_internal_errors( $previous );

        if ( ! $xml instanceof \SimpleXMLElement ) {
            return $result;
        }

        if ( isset( $xml->{'translatable-post-types'}->{'post-type'} ) ) {
            foreach ( $xml->{'translatable-post-types'}->{'post-type'} as $node ) {
                $slug = sanitize_key( trim( (string) $node ) );

                if ( $slug !== '' ) {
                    $result['post_types'][] = $slug;
                }
            }
        }

        if ( isset( $xml->{'translatable-taxonomies'}->taxonomy ) ) {
            foreach ( $xml->{'translatable-taxonomies'}->taxonomy as $node ) {
                $slug = sanitize_key( trim( (string) $node ) );

                if ( $slug !== '' ) {
                    $result['taxonomies'][] = $slug;
                }
            }
        }

        $result['post_types'] = array_values( array_unique( $result['post_types'] ) );
        $result['taxonomies'] = array_values( array_unique( $result['taxonomies'] ) );
        $result['valid'] = true;

        return $result;
    }

    private function get_config_sources(): array {
        $sources = [];

        foreach ( $this->get_config_roots() as $root ) {
            $wpml_path = trailingslashit( $root['path'] ) . 'wpml-config.xml';
            $wp_loc_path = trailingslashit( $root['path'] ) . 'wp-loc-config.xml';
            $wpml_data = file_exists( $wpml_path ) ? $this->parse_wpml_config_file( $wpml_path ) : [ 'post_types' => [], 'taxonomies' => [], 'valid' => false ];
            $wp_loc_data = file_exists( $wp_loc_path ) ? $this->parse_wp_loc_config_file( $wp_loc_path ) : [ 'post_types' => [], 'taxonomies' => [], 'valid' => false ];

            if ( ! file_exists( $wpml_path ) && ! file_exists( $wp_loc_path ) ) {
                continue;
            }

            if ( empty( $wpml_data['post_types'] ) && empty( $wpml_data['taxonomies'] ) && ! file_exists( $wp_loc_path ) ) {
                continue;
            }

            $sources[] = [
                'root' => $root['path'],
                'type' => $root['type'],
                'label' => $root['label'],
                'wpml_path' => $wpml_path,
                'wpml_exists' => file_exists( $wpml_path ),
                'wpml_data' => $wpml_data,
                'wp_loc_path' => $wp_loc_path,
                'wp_loc_exists' => file_exists( $wp_loc_path ),
                'wp_loc_data' => $wp_loc_data,
                'can_delete_wpml' => $root['type'] === 'theme',
            ];
        }

        return $sources;
    }

    private function find_config_source( string $source_path, array $sources ): ?array {
        $real_source = realpath( $source_path );

        if ( ! $real_source ) {
            return null;
        }

        foreach ( $sources as $source ) {
            if ( realpath( $source['wpml_path'] ) === $real_source ) {
                return $source;
            }
        }

        return null;
    }

    private function build_wp_loc_config_xml( array $post_types, array $taxonomies ): string {
        $post_types = array_values( array_unique( array_filter( array_map( 'sanitize_key', $post_types ) ) ) );
        $taxonomies = array_values( array_unique( array_filter( array_map( 'sanitize_key', $taxonomies ) ) ) );

        sort( $post_types );
        sort( $taxonomies );

        $lines = [
            '<?xml version="1.0" encoding="UTF-8"?>',
            '<wp-loc-config>',
        ];

        $lines[] = '    <translatable-post-types>';
        foreach ( $post_types as $post_type ) {
            $lines[] = '        <post-type>' . esc_xml( $post_type ) . '</post-type>';
        }
        $lines[] = '    </translatable-post-types>';

        $lines[] = '    <translatable-taxonomies>';
        foreach ( $taxonomies as $taxonomy ) {
            $lines[] = '        <taxonomy>' . esc_xml( $taxonomy ) . '</taxonomy>';
        }
        $lines[] = '    </translatable-taxonomies>';

        $lines[] = '</wp-loc-config>';

        return implode( "\n", $lines ) . "\n";
    }

    private function write_current_wp_loc_config(): array {
        $target_path = trailingslashit( get_stylesheet_directory() ) . 'wp-loc-config.xml';
        $payload = $this->get_current_settings_payload();

        if ( empty( $payload['post_types'] ) && empty( $payload['taxonomies'] ) ) {
            return [
                'wp_loc_config_notice' => 'empty-current',
                'wp_loc_config_status' => 'error',
            ];
        }

        $written = file_put_contents( $target_path, $this->build_wp_loc_config_xml( $payload['post_types'], $payload['taxonomies'] ) );

        return [
            'wp_loc_config_notice' => $written !== false ? 'current-written' : 'write-failed',
            'wp_loc_config_status' => $written !== false ? 'success' : 'error',
        ];
    }

    private function write_wp_loc_config_from_source( array $source ): array {
        $payload = [
            'post_types' => $source['wpml_data']['post_types'] ?? [],
            'taxonomies' => $source['wpml_data']['taxonomies'] ?? [],
        ];

        if ( empty( $payload['post_types'] ) && empty( $payload['taxonomies'] ) ) {
            return [
                'wp_loc_config_notice' => 'nothing-supported',
                'wp_loc_config_status' => 'error',
            ];
        }

        $written = file_put_contents( $source['wp_loc_path'], $this->build_wp_loc_config_xml( $payload['post_types'], $payload['taxonomies'] ) );

        return [
            'wp_loc_config_notice' => $written !== false ? 'generated' : 'write-failed',
            'wp_loc_config_status' => $written !== false ? 'success' : 'error',
        ];
    }

    private function delete_wpml_config_from_source( array $source ): array {
        if ( empty( $source['can_delete_wpml'] ) ) {
            return [
                'wp_loc_config_notice' => 'delete-not-allowed',
                'wp_loc_config_status' => 'error',
            ];
        }

        if ( empty( $source['wpml_exists'] ) || ! file_exists( $source['wpml_path'] ) ) {
            return [
                'wp_loc_config_notice' => 'source-missing',
                'wp_loc_config_status' => 'error',
            ];
        }

        $deleted = @unlink( $source['wpml_path'] );

        return [
            'wp_loc_config_notice' => $deleted ? 'deleted' : 'delete-failed',
            'wp_loc_config_status' => $deleted ? 'success' : 'error',
        ];
    }

    private function render_config_notice(): void {
        $notice = isset( $_GET['wp_loc_config_notice'] ) ? sanitize_key( (string) $_GET['wp_loc_config_notice'] ) : '';
        $status = isset( $_GET['wp_loc_config_status'] ) ? sanitize_key( (string) $_GET['wp_loc_config_status'] ) : 'success';

        if ( ! $notice ) {
            return;
        }

        $messages = [
            'current-written' => __( 'wp-loc-config.xml was written from the current wp-loc settings.', 'wp-loc' ),
            'generated' => __( 'wp-loc-config.xml was generated from the detected wpml-config.xml file.', 'wp-loc' ),
            'deleted' => __( 'wpml-config.xml was deleted.', 'wp-loc' ),
            'source-missing' => __( 'The selected wpml-config.xml file was not found anymore.', 'wp-loc' ),
            'nothing-supported' => __( 'The detected wpml-config.xml file does not contain translatable post types or taxonomies that wp-loc uses.', 'wp-loc' ),
            'empty-current' => __( 'Current wp-loc settings do not contain translatable post types or taxonomies to write.', 'wp-loc' ),
            'write-failed' => __( 'Could not write wp-loc-config.xml.', 'wp-loc' ),
            'delete-failed' => __( 'Could not delete wpml-config.xml.', 'wp-loc' ),
            'delete-not-allowed' => __( 'This wpml-config.xml belongs to a plugin source and is shown as read-only here.', 'wp-loc' ),
        ];

        if ( empty( $messages[ $notice ] ) ) {
            return;
        }

        printf(
            '<div class="notice notice-%1$s is-dismissible"><p>%2$s</p></div>',
            esc_attr( $status === 'error' ? 'error' : 'success' ),
            esc_html( $messages[ $notice ] )
        );
    }

    private function render_config_list( array $items ): string {
        if ( empty( $items ) ) {
            return '<p class="wp-loc-config-empty">' . esc_html__( 'None found.', 'wp-loc' ) . '</p>';
        }

        $html = '<ul class="wp-loc-config-list">';

        foreach ( $items as $item ) {
            $html .= '<li><code>' . esc_html( $item ) . '</code></li>';
        }

        $html .= '</ul>';

        return $html;
    }

    private function render_config_tokens( array $items ): string {
        if ( empty( $items ) ) {
            return '<span class="wp-loc-config-empty">' . esc_html__( 'None found.', 'wp-loc' ) . '</span>';
        }

        $html = '<div class="wp-loc-config-tokens">';

        foreach ( $items as $item ) {
            $html .= '<code class="wp-loc-config-token">' . esc_html( $item ) . '</code>';
        }

        $html .= '</div>';

        return $html;
    }

    private function get_config_migration_html(): string {
        $sources = $this->get_config_sources();
        $current = $this->get_current_settings_payload();
        $default_target = trailingslashit( get_stylesheet_directory() ) . 'wp-loc-config.xml';

        ob_start();
        ?>
        <div class="wp-loc-config-detected-state">
            <strong><?php esc_html_e( 'Status:', 'wp-loc' ); ?></strong>
            <?php echo esc_html( ! empty( $sources ) ? __( 'wpml-config.xml found', 'wp-loc' ) : __( 'wpml-config.xml not found', 'wp-loc' ) ); ?>
        </div>

        <div class="wp-loc-config-panel">
            <div class="wp-loc-config-panel-head">
                <div>
                    <h2><?php esc_html_e( 'Current wp-loc config', 'wp-loc' ); ?></h2>
                    <p class="description"><?php esc_html_e( 'This writes a lightweight wp-loc-config.xml file for the active theme based on the translatable post types and taxonomies currently enabled in wp-loc.', 'wp-loc' ); ?></p>
                </div>
            </div>

            <table class="form-table" role="presentation">
                <tbody>
                    <tr>
                        <th scope="row"><?php esc_html_e( 'Target file', 'wp-loc' ); ?></th>
                        <td><code><?php echo esc_html( $default_target ); ?></code></td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e( 'Post types', 'wp-loc' ); ?></th>
                        <td><?php echo $this->render_config_tokens( $current['post_types'] ); ?></td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e( 'Taxonomies', 'wp-loc' ); ?></th>
                        <td><?php echo $this->render_config_tokens( $current['taxonomies'] ); ?></td>
                    </tr>
                </tbody>
            </table>

            <form method="post" class="wp-loc-config-actions">
                <?php wp_nonce_field( 'wp_loc_tools_config_action', 'wp_loc_tools_config_nonce' ); ?>
                <input type="hidden" name="wp_loc_config_action" value="<?php echo esc_attr( self::CONFIG_ACTION_WRITE_CURRENT ); ?>" />
                <button type="submit" class="button button-primary"><?php esc_html_e( 'Write wp-loc-config.xml', 'wp-loc' ); ?></button>
            </form>
        </div>

        <div class="wp-loc-config-panel">
            <div class="wp-loc-config-panel-head">
                <div>
                    <h2><?php esc_html_e( 'Found wpml-config.xml files', 'wp-loc' ); ?></h2>
                    <p class="description"><?php esc_html_e( 'wp-loc reads only the post type and taxonomy information from WPML config files. Other WPML options are intentionally ignored here.', 'wp-loc' ); ?></p>
                </div>
            </div>

            <?php if ( empty( $sources ) ) : ?>
                <p class="description"><?php esc_html_e( 'No relevant wpml-config.xml files were found in the active theme or active plugins.', 'wp-loc' ); ?></p>
            <?php else : ?>
                <div class="wp-loc-config-source-list">
                    <?php foreach ( $sources as $source ) : ?>
                        <div class="wp-loc-config-source">
                            <div class="wp-loc-config-source-head">
                                <div>
                                    <h3><?php echo esc_html( $source['label'] ); ?></h3>
                                </div>
                                <span class="wp-loc-config-source-badge"><?php echo esc_html( $source['type'] === 'theme' ? __( 'Theme', 'wp-loc' ) : __( 'Plugin', 'wp-loc' ) ); ?></span>
                            </div>

                            <table class="form-table" role="presentation">
                                <tbody>
                                    <tr>
                                        <th scope="row"><?php esc_html_e( 'WPML file', 'wp-loc' ); ?></th>
                                        <td><code><?php echo esc_html( $source['wpml_path'] ); ?></code></td>
                                    </tr>
                                    <?php if ( ! empty( $source['wp_loc_exists'] ) ) : ?>
                                        <tr>
                                            <th scope="row"><?php esc_html_e( 'Existing wp-loc-config.xml', 'wp-loc' ); ?></th>
                                            <td><code><?php echo esc_html( $source['wp_loc_path'] ); ?></code></td>
                                        </tr>
                                    <?php endif; ?>
                                    <tr>
                                        <th scope="row"><?php esc_html_e( 'Post types', 'wp-loc' ); ?></th>
                                        <td><?php echo $this->render_config_tokens( $source['wpml_data']['post_types'] ?? [] ); ?></td>
                                    </tr>
                                    <tr>
                                        <th scope="row"><?php esc_html_e( 'Taxonomies', 'wp-loc' ); ?></th>
                                        <td><?php echo $this->render_config_tokens( $source['wpml_data']['taxonomies'] ?? [] ); ?></td>
                                    </tr>
                                </tbody>
                            </table>

                            <div class="wp-loc-config-actions">
                                <?php if ( ! empty( $source['wpml_exists'] ) ) : ?>
                                    <form method="post">
                                        <?php wp_nonce_field( 'wp_loc_tools_config_action', 'wp_loc_tools_config_nonce' ); ?>
                                        <input type="hidden" name="wp_loc_config_source" value="<?php echo esc_attr( $source['wpml_path'] ); ?>" />
                                        <button type="submit" name="wp_loc_config_action" value="<?php echo esc_attr( self::CONFIG_ACTION_WRITE_FROM_WPML ); ?>" class="button button-primary"><?php esc_html_e( 'Generate wp-loc-config.xml', 'wp-loc' ); ?></button>
                                    </form>
                                <?php endif; ?>

                                <?php if ( ! empty( $source['wpml_exists'] ) && ! empty( $source['can_delete_wpml'] ) ) : ?>
                                    <form method="post">
                                        <?php wp_nonce_field( 'wp_loc_tools_config_action', 'wp_loc_tools_config_nonce' ); ?>
                                        <input type="hidden" name="wp_loc_config_source" value="<?php echo esc_attr( $source['wpml_path'] ); ?>" />
                                        <button type="submit" name="wp_loc_config_action" value="<?php echo esc_attr( self::CONFIG_ACTION_DELETE_WPML ); ?>" class="wp-loc-config-delete"><?php esc_html_e( 'Delete wpml-config.xml', 'wp-loc' ); ?></button>
                                    </form>
                                <?php elseif ( ! empty( $source['wpml_exists'] ) ) : ?>
                                    <span class="wp-loc-config-note"><?php esc_html_e( 'Plugin config is shown as read-only here.', 'wp-loc' ); ?></span>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
        <?php

        return (string) ob_get_clean();
    }

    private function get_sync_content_html(): string {
        $preview = $this->get_preview_data();
        $active_languages = WP_LOC_Languages::get_active_languages();
        $default_lang = WP_LOC_Languages::get_default_language();
        $stats = $this->get_preview_stats( $preview );

        ob_start();
        ?>
        <div class="wp-loc-menu-sync-summary">
            <div class="wp-loc-menu-sync-summary-card">
                <span class="wp-loc-menu-sync-summary-value"><?php echo esc_html( (string) $stats['menus_total'] ); ?></span>
                <span class="wp-loc-menu-sync-summary-label"><?php esc_html_e( 'Default menus', 'wp-loc' ); ?></span>
            </div>
            <div class="wp-loc-menu-sync-summary-card">
                <span class="wp-loc-menu-sync-summary-value"><?php echo esc_html( (string) $stats['targets_needing_sync'] ); ?></span>
                <span class="wp-loc-menu-sync-summary-label"><?php esc_html_e( 'Targets needing sync', 'wp-loc' ); ?></span>
            </div>
            <div class="wp-loc-menu-sync-summary-card">
                <span class="wp-loc-menu-sync-summary-value"><?php echo esc_html( (string) $stats['warnings_total'] ); ?></span>
                <span class="wp-loc-menu-sync-summary-label"><?php esc_html_e( 'Warnings', 'wp-loc' ); ?></span>
            </div>
        </div>

        <div class="wp-loc-menu-sync-grid">
            <table class="widefat striped">
                <thead>
                    <tr>
                        <th><?php echo esc_html( WP_LOC_Languages::get_display_name( $default_lang ) ); ?></th>
                        <?php foreach ( $active_languages as $lang => $data ) : ?>
                            <?php if ( $lang === $default_lang ) continue; ?>
                            <th><?php echo esc_html( WP_LOC_Languages::get_display_name( $lang ) ); ?></th>
                        <?php endforeach; ?>
                    </tr>
                </thead>
                <tbody>
                    <?php if ( empty( $preview ) ) : ?>
                        <tr>
                            <td colspan="<?php echo esc_attr( (string) count( $active_languages ) ); ?>">
                                <?php esc_html_e( 'No menus found.', 'wp-loc' ); ?>
                            </td>
                        </tr>
                    <?php else : ?>
                        <?php foreach ( $preview as $menu ) : ?>
                            <tr>
                                <td class="wp-loc-menu-sync-source">
                                    <strong><?php echo esc_html( $menu['name'] ); ?></strong>
                                </td>
                                <?php foreach ( $active_languages as $lang => $data ) : ?>
                                    <?php if ( $lang === $default_lang ) continue; ?>
                                    <?php $cell = $menu['languages'][ $lang ] ?? [ 'operations' => [], 'needs_sync' => false, 'menu_name' => '' ]; ?>
                                    <td>
                                        <div class="wp-loc-menu-sync-cell <?php echo esc_attr( $this->get_status_class( $cell ) ); ?>">
                                            <div class="wp-loc-menu-sync-cell-head">
                                                <div>
                                                    <div class="wp-loc-menu-sync-cell-title">
                                                        <?php echo esc_html( $cell['menu_name'] ?: sprintf( __( '%s menu', 'wp-loc' ), WP_LOC_Languages::get_display_name( $lang ) ) ); ?>
                                                    </div>
                                                    <span class="wp-loc-menu-sync-status <?php echo esc_attr( $this->get_status_class( $cell ) ); ?>">
                                                        <?php echo esc_html( $this->get_status_label( $cell ) ); ?>
                                                    </span>
                                                </div>

                                                <?php if ( ! empty( $cell['needs_sync'] ) ) : ?>
                                                    <label class="wp-loc-menu-sync-checkbox">
                                                        <input type="checkbox" name="sync[<?php echo esc_attr( (string) $menu['menu_id'] ); ?>][<?php echo esc_attr( $lang ); ?>]" value="1" checked="checked" />
                                                        <span><?php esc_html_e( 'Apply', 'wp-loc' ); ?></span>
                                                    </label>
                                                <?php endif; ?>
                                            </div>

                                            <?php if ( empty( $cell['needs_sync'] ) ) : ?>
                                                <p class="wp-loc-menu-sync-empty"><?php esc_html_e( 'Nothing to sync for this language.', 'wp-loc' ); ?></p>
                                            <?php else : ?>
                                                <div class="wp-loc-menu-sync-badges">
                                                    <?php foreach ( $cell['operations'] as $operation ) : ?>
                                                        <span class="wp-loc-menu-sync-badge <?php echo esc_attr( $this->get_operation_badge_class( $operation['type'] ) ); ?>">
                                                            <?php echo esc_html( $this->get_operation_badge_label( $operation['type'] ) ); ?>
                                                        </span>
                                                    <?php endforeach; ?>
                                                </div>

                                                <button type="button" class="button-link wp-loc-menu-sync-toggle-details" aria-expanded="false">
                                                    <?php esc_html_e( 'Details', 'wp-loc' ); ?>
                                                </button>

                                                <div class="wp-loc-menu-sync-details" hidden>
                                                    <ul class="wp-loc-menu-sync-operations">
                                                        <?php foreach ( $cell['operations'] as $operation ) : ?>
                                                            <li class="<?php echo esc_attr( $operation['type'] === 'skipped' ? 'is-warning' : '' ); ?>">
                                                                <?php echo esc_html( $operation['label'] ); ?>
                                                            </li>
                                                        <?php endforeach; ?>
                                                    </ul>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                <?php endforeach; ?>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <?php echo $this->get_toolbar_html( $stats, 'is-bottom' ); ?>
        <?php

        return (string) ob_get_clean();
    }

    public function render_page(): void {
        $default_lang = WP_LOC_Languages::get_default_language();
        $current_tab = $this->get_current_tab();
        ?>
        <div class="wrap wp-loc-menu-sync-page wp-loc-tools-page">
            <h1><?php esc_html_e( 'Tools', 'wp-loc' ); ?></h1>
            <p class="description">
                <?php esc_html_e( 'Utilities for maintaining multilingual data and synchronizing translated content.', 'wp-loc' ); ?>
            </p>
            <?php $this->render_tabs( $current_tab ); ?>

            <?php if ( $current_tab === self::TAB_MENU_SYNC ) : ?>
            <p class="description">
                <?php
                printf(
                    /* translators: %s: default language name */
                    esc_html__( 'Menu synchronization will sync the menu structure from the default language of %s to the secondary languages.', 'wp-loc' ),
                    esc_html( WP_LOC_Languages::get_display_name( $default_lang ) )
                );
                ?>
            </p>
            <div class="wp-loc-menu-sync-feedback" aria-live="polite"></div>
            <div class="wp-loc-menu-sync-content"><?php echo $this->get_sync_content_html(); ?></div>
            <?php elseif ( $current_tab === self::TAB_AI_TRANSLATE ) : ?>
            <p class="description">
                <?php esc_html_e( 'Paste or write formatted content in the editor, then translate it into the selected language and insert the translated version back into the editor.', 'wp-loc' ); ?>
            </p>
            <div class="wp-loc-menu-sync-content"><?php echo $this->get_ai_translate_html(); ?></div>
            <?php elseif ( $current_tab === self::TAB_CONFIG_MIGRATION ) : ?>
            <p class="description">
                <?php esc_html_e( 'Detect WPML config files, extract only the post types and taxonomies relevant for wp-loc, and generate a lightweight wp-loc-config.xml file.', 'wp-loc' ); ?>
            </p>
            <?php $this->render_config_notice(); ?>
            <div class="wp-loc-menu-sync-content"><?php echo $this->get_config_migration_html(); ?></div>
            <?php endif; ?>
        </div>
        <?php
    }
}
