<?php

if ( ! defined( 'ABSPATH' ) ) exit;

class WP_LOC_Menu_Sync {

    private const PAGE_SLUG = 'wp-loc-tools';
    private const TAB_MENU_SYNC = 'menu-sync';
    private const TAB_AI_TRANSLATE = 'ai-translate';

    public function __construct() {
        add_action( 'admin_menu', [ $this, 'add_menu' ], 15 );
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

        return in_array( $tab, [ self::TAB_MENU_SYNC, self::TAB_AI_TRANSLATE ], true ) ? $tab : self::TAB_MENU_SYNC;
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
        ];

        echo '<nav class="nav-tab-wrapper wp-clearfix">';

        foreach ( $tabs as $tab => $label ) {
            $class = $tab === $current_tab ? 'nav-tab nav-tab-active' : 'nav-tab';
            echo '<a href="' . esc_url( $this->get_tab_url( $tab ) ) . '" class="' . esc_attr( $class ) . '">' . esc_html( $label ) . '</a>';
        }

        echo '</nav>';
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
            <?php endif; ?>
        </div>
        <?php
    }
}
