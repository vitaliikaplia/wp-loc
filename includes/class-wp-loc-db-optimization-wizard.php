<?php

if ( ! defined( 'ABSPATH' ) ) exit;

class WP_LOC_DB_Optimization_Wizard {

    private const STATUS_OPTION = 'wp_loc_db_optimization_wizard_status';
    private const STATUS_PENDING = 'pending';
    private const STATUS_DISMISSED = 'dismissed';
    private const STATUS_COMPLETED = 'completed';

    public function __construct() {
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );
        add_action( 'admin_footer', [ $this, 'render_modal' ] );
        add_action( 'wp_ajax_wp_loc_db_optimization_dismiss', [ $this, 'ajax_dismiss' ] );
        add_action( 'wp_ajax_wp_loc_db_optimization_scan', [ $this, 'ajax_scan' ] );
        add_action( 'wp_ajax_wp_loc_db_optimization_apply', [ $this, 'ajax_apply' ] );
    }

    private function should_show(): bool {
        if ( ! is_admin() || wp_doing_ajax() || ! current_user_can( 'manage_options' ) ) {
            return false;
        }

        if ( defined( 'ICL_SITEPRESS_VERSION' ) ) {
            return false;
        }

        $status = get_option( self::STATUS_OPTION, self::STATUS_PENDING );

        return ! in_array( $status, [ self::STATUS_DISMISSED, self::STATUS_COMPLETED ], true );
    }

    public function enqueue_assets(): void {
        if ( ! $this->should_show() ) {
            return;
        }

        wp_localize_script( 'wp-loc-admin', 'wpLocDbOptimizationWizard', [
            'ajaxUrl'     => admin_url( 'admin-ajax.php' ),
            'redirectUrl' => admin_url( 'admin.php?page=wp-loc' ),
            'nonce'       => wp_create_nonce( 'wp_loc_db_optimization_wizard' ),
            'i18n'        => [
                'requestFailed' => __( 'Request failed. Please try again.', 'wp-loc' ),
                'scanning'      => __( 'Scanning database...', 'wp-loc' ),
                'optimizing'    => __( 'Optimizing database...', 'wp-loc' ),
                'noCleanup'     => __( 'No removable legacy data was found. Your database is already clean.', 'wp-loc' ),
                'confirmDismiss' => __( 'Are you sure you do not want to optimize the database? This wizard will be dismissed and WP-LOC will continue without cleaning legacy multilingual data.', 'wp-loc' ),
                'confirmApply'   => __( 'Are you sure you want to continue? Legacy multilingual service data will be permanently removed and this action cannot be undone.', 'wp-loc' ),
                'noLanguages'    => __( 'No language records were found for import.', 'wp-loc' ),
                'translationLinksKept' => __( 'translation links kept', 'wp-loc' ),
                'postRecordsRecognized' => __( 'post records recognized', 'wp-loc' ),
                'termRecordsRecognized' => __( 'term records recognized', 'wp-loc' ),
                'menuRecordsRecognized' => __( 'menu records recognized', 'wp-loc' ),
                'mediaRecordsRecognized' => __( 'media records recognized', 'wp-loc' ),
                'languagesDetected' => __( 'languages detected', 'wp-loc' ),
                'localizedOptionsDetected' => __( 'localized options detected', 'wp-loc' ),
                'fieldPreferencesDetected' => __( 'field preferences detected', 'wp-loc' ),
                'keptByWpLoc' => __( 'Kept by WP-LOC', 'wp-loc' ),
                'keptByWpLocDescription' => __( 'Compatible translation links remain in place and continue powering posts, pages, media, terms, and menus.', 'wp-loc' ),
                'contentTypesFound' => __( 'Content types found', 'wp-loc' ),
                'noPostTypes' => __( 'No translated post types were found.', 'wp-loc' ),
                'taxonomiesFound' => __( 'Taxonomies found', 'wp-loc' ),
                'noTaxonomies' => __( 'No translated taxonomies were found.', 'wp-loc' ),
                'languagesToAdopt' => __( 'Languages to adopt', 'wp-loc' ),
                'adoptAs' => __( 'Adopt as', 'wp-loc' ),
                'matchExact' => __( 'Exact match', 'wp-loc' ),
                'matchNormalized' => __( 'Slug normalized', 'wp-loc' ),
                'matchLocale' => __( 'Locale match', 'wp-loc' ),
                'matchCode' => __( 'Code match', 'wp-loc' ),
                'matchWordPress' => __( 'WordPress match', 'wp-loc' ),
                'matchFallback' => __( 'Needs review', 'wp-loc' ),
                'matchManual' => __( 'Manual match', 'wp-loc' ),
                'legacyDataToRemove' => __( 'Legacy data to remove', 'wp-loc' ),
                'cleanupRowsSummary' => __( '%1$s option rows and %2$s meta rows are marked for cleanup.', 'wp-loc' ),
                'languagesImported' => __( 'languages imported', 'wp-loc' ),
                'legacyTablesRemoved' => __( 'legacy tables removed', 'wp-loc' ),
                'optionRowsRemoved' => __( 'option rows removed', 'wp-loc' ),
                'metaRowsRemoved' => __( 'meta rows removed', 'wp-loc' ),
                'optimizationReady' => __( 'WP-LOC is ready to continue with the optimized multilingual database.', 'wp-loc' ),
            ],
        ] );
    }

    public function render_modal(): void {
        if ( ! $this->should_show() ) {
            return;
        }
        ?>
        <div class="wp-loc-db-wizard" data-wp-loc-db-wizard hidden>
            <div class="wp-loc-db-wizard__backdrop" data-wp-loc-db-wizard-close></div>
            <div class="wp-loc-db-wizard__dialog" role="dialog" aria-modal="true" aria-labelledby="wp-loc-db-wizard-title">
                <button type="button" class="wp-loc-db-wizard__close" data-wp-loc-db-wizard-close aria-label="<?php echo esc_attr__( 'Close', 'wp-loc' ); ?>">×</button>

                <div class="wp-loc-db-wizard__step is-active" data-step="intro">
                    <p class="wp-loc-db-wizard__eyebrow"><?php esc_html_e( 'Database optimization', 'wp-loc' ); ?></p>
                    <h2 id="wp-loc-db-wizard-title"><?php esc_html_e( 'Prepare multilingual data for WP-LOC', 'wp-loc' ); ?></h2>
                    <p><?php esc_html_e( 'WP-LOC can scan multilingual data left by another plugin, adopt compatible translation links, and remove obsolete service data after your confirmation.', 'wp-loc' ); ?></p>
                    <div class="wp-loc-db-wizard__actions">
                        <button type="button" class="button button-primary" data-action="start"><?php esc_html_e( 'Continue', 'wp-loc' ); ?></button>
                        <button type="button" class="button" data-action="dismiss"><?php esc_html_e( 'Do not optimize database', 'wp-loc' ); ?></button>
                    </div>
                </div>

                <div class="wp-loc-db-wizard__step" data-step="scan">
                    <div class="wp-loc-db-wizard__header">
                        <div>
                            <p class="wp-loc-db-wizard__eyebrow"><?php esc_html_e( 'Review', 'wp-loc' ); ?></p>
                            <h2><?php esc_html_e( 'Database scan summary', 'wp-loc' ); ?></h2>
                        </div>
                        <aside class="wp-loc-db-wizard__note">
                            <h3><?php esc_html_e( 'Kept by WP-LOC', 'wp-loc' ); ?></h3>
                            <p><?php esc_html_e( 'Compatible translation links remain in place and continue powering posts, pages, media, terms, and menus.', 'wp-loc' ); ?></p>
                        </aside>
                    </div>
                    <div class="wp-loc-db-wizard__loading" data-loading-scan><?php esc_html_e( 'Scanning database...', 'wp-loc' ); ?></div>
                    <div class="wp-loc-db-wizard__summary" data-summary hidden></div>
                    <div class="wp-loc-db-wizard__actions">
                        <button type="button" class="button button-primary" data-action="apply" disabled><?php esc_html_e( 'Agree and optimize', 'wp-loc' ); ?></button>
                        <button type="button" class="button" data-action="dismiss"><?php esc_html_e( 'Do not optimize database', 'wp-loc' ); ?></button>
                    </div>
                </div>

                <div class="wp-loc-db-wizard__step" data-step="progress">
                    <p class="wp-loc-db-wizard__eyebrow"><?php esc_html_e( 'Optimization', 'wp-loc' ); ?></p>
                    <h2><?php esc_html_e( 'Optimizing database', 'wp-loc' ); ?></h2>
                    <div class="wp-loc-db-wizard__spinner"></div>
                    <p data-progress-text><?php esc_html_e( 'Please wait while WP-LOC applies the selected cleanup.', 'wp-loc' ); ?></p>
                </div>

                <div class="wp-loc-db-wizard__step" data-step="done">
                    <p class="wp-loc-db-wizard__eyebrow"><?php esc_html_e( 'Complete', 'wp-loc' ); ?></p>
                    <h2><?php esc_html_e( 'Database optimization completed', 'wp-loc' ); ?></h2>
                    <div class="wp-loc-db-wizard__summary" data-result></div>
                    <div class="wp-loc-db-wizard__actions">
                        <button type="button" class="button button-primary" data-action="finish"><?php esc_html_e( 'Continue using WP-LOC', 'wp-loc' ); ?></button>
                    </div>
                </div>
            </div>
        </div>
        <?php
    }

    public function ajax_dismiss(): void {
        $this->assert_ajax_permissions();
        update_option( self::STATUS_OPTION, self::STATUS_DISMISSED );
        wp_send_json_success();
    }

    public function ajax_scan(): void {
        $this->assert_ajax_permissions();
        wp_send_json_success( $this->scan_database() );
    }

    public function ajax_apply(): void {
        $this->assert_ajax_permissions();
        $scan = $this->scan_database();
        $scan = $this->apply_requested_language_mapping( $scan );
        $this->normalize_translation_language_codes( $scan );
        $cleanup = $this->cleanup_legacy_data( $scan );
        $languages_imported = $this->import_languages_from_scan( $scan );
        $content_settings = $this->adopt_content_settings_from_scan( $scan );

        update_option( self::STATUS_OPTION, self::STATUS_COMPLETED );

        wp_send_json_success( [
            'languages_imported' => $languages_imported,
            'tables_removed'     => $cleanup['tables_removed'],
            'options_removed'    => $cleanup['options_removed'],
            'meta_removed'       => $cleanup['meta_removed'],
            'kept_rows'          => (int) ( $scan['compatible']['translation_rows'] ?? 0 ),
            'post_types_adopted' => $content_settings['post_types'],
            'taxonomies_adopted' => $content_settings['taxonomies'],
        ] );
    }

    private function assert_ajax_permissions(): void {
        check_ajax_referer( 'wp_loc_db_optimization_wizard', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => __( 'You are not allowed to do this.', 'wp-loc' ) ], 403 );
        }
    }

    private function scan_database(): array {
        global $wpdb;

        $translations_table = $wpdb->prefix . 'icl_translations';
        $translation_rows = $this->table_exists( $translations_table ) ? (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$translations_table}" ) : 0;
        $element_counts = [];

        if ( $translation_rows ) {
            $rows = $wpdb->get_results( "SELECT element_type, COUNT(*) AS total FROM {$translations_table} GROUP BY element_type" );

            foreach ( $rows as $row ) {
                $element_counts[ (string) $row->element_type ] = (int) $row->total;
            }
        }

        $legacy_tables = $this->get_legacy_tables();
        $removable_tables = array_values( array_filter(
            $legacy_tables,
            fn( string $table ): bool => $table !== $translations_table
        ) );

        $languages = $this->detect_languages();

        return [
            'compatible' => [
                'translation_rows' => $translation_rows,
                'posts'            => $this->sum_element_counts_by_prefix( $element_counts, 'post_' ),
                'terms'            => $this->sum_element_counts_by_prefix( $element_counts, 'tax_' ),
                'menus'            => ( $element_counts['tax_nav_menu'] ?? 0 ) + ( $element_counts['post_nav_menu_item'] ?? 0 ),
                'attachments'      => $element_counts['post_attachment'] ?? 0,
            ],
            'details' => [
                'post_types'        => $this->summarize_element_counts( $element_counts, 'post_', [ 'attachment', 'nav_menu_item' ] ),
                'taxonomies'        => $this->summarize_element_counts( $element_counts, 'tax_', [ 'nav_menu' ] ),
                'localized_options' => $this->count_localized_options(),
                'field_preferences' => $this->count_field_preferences(),
            ],
            'languages' => $languages,
            'language_targets' => WP_LOC_Language_Registry::get_language_options(),
            'legacy'    => [
                'tables'        => array_map( [ $this, 'table_summary' ], $removable_tables ),
                'options'       => $this->count_legacy_options(),
                'postmeta'      => $this->count_legacy_postmeta(),
                'usermeta'      => $this->count_legacy_usermeta(),
                'commentmeta'   => $this->count_legacy_commentmeta(),
            ],
        ];
    }

    private function cleanup_legacy_data( array $scan ): array {
        global $wpdb;

        $translations_table = $wpdb->prefix . 'icl_translations';
        $tables_removed = 0;

        foreach ( $this->get_legacy_tables() as $table ) {
            if ( $table === $translations_table ) {
                continue;
            }

            $wpdb->query( "DROP TABLE IF EXISTS `{$table}`" );
            $tables_removed++;
        }

        $options_removed = (int) $wpdb->query(
            "DELETE FROM {$wpdb->options}
             WHERE option_name LIKE 'icl\_%'
                OR option_name LIKE '\_icl\_%'
                OR option_name LIKE '\_transient\_icl\_%'
                OR option_name LIKE '\_transient\_timeout\_icl\_%'"
        );

        $meta_removed = 0;
        $meta_removed += (int) $wpdb->query( "DELETE FROM {$wpdb->postmeta} WHERE meta_key LIKE '\_icl\_%'" );
        $meta_removed += (int) $wpdb->query( "DELETE FROM {$wpdb->usermeta} WHERE meta_key LIKE '\_icl\_%'" );
        $meta_removed += (int) $wpdb->query( "DELETE FROM {$wpdb->commentmeta} WHERE meta_key LIKE '\_icl\_%'" );

        return [
            'tables_removed'  => $tables_removed,
            'options_removed' => max( 0, $options_removed ),
            'meta_removed'    => max( 0, $meta_removed ),
        ];
    }

    private function import_languages_from_scan( array $scan ): int {
        $languages = $scan['languages']['items'] ?? [];

        if ( empty( $languages ) ) {
            return 0;
        }

        $existing = $this->normalize_configured_languages( WP_LOC_Languages::get_languages() );
        $imported = 0;

        foreach ( $languages as $language ) {
            $slug = sanitize_key( (string) ( $language['code'] ?? '' ) );
            $locale = sanitize_text_field( (string) ( $language['locale'] ?? $slug ) );
            $display_name = trim( sanitize_text_field( (string) ( $language['display_name'] ?? '' ) ) );

            if ( ! $slug ) {
                continue;
            }

            if ( isset( $existing[ $slug ] ) ) {
                if ( empty( $existing[ $slug ]['wpml_code'] ) ) {
                    $existing[ $slug ]['wpml_code'] = sanitize_key( (string) ( $language['wpml_code'] ?? WP_LOC_Language_Registry::wpml_code_from_locale( $locale ?: $slug ) ) );
                    $imported++;
                }
                if ( $display_name && $this->is_generated_display_name( (string) ( $existing[ $slug ]['display_name'] ?? '' ), $slug, $locale ) ) {
                    $existing[ $slug ]['display_name'] = $display_name;
                    $imported++;
                }
                continue;
            }

            $existing[ $slug ] = [
                'locale'       => $locale ?: $slug,
                'enabled'      => true,
                'display_name' => $display_name ?: WP_LOC_Languages::get_language_display_name( $locale ?: $slug ),
                'wpml_code'    => sanitize_key( (string) ( $language['wpml_code'] ?? WP_LOC_Language_Registry::wpml_code_from_locale( $locale ?: $slug ) ) ),
            ];
            $imported++;
        }

        if ( $imported ) {
            update_option( 'wp_loc_languages', $existing );
            update_option( 'wp_loc_flush_rewrite_rules', true );
            WP_LOC_Languages::flush();
        }

        return $imported;
    }

    private function apply_requested_language_mapping( array $scan ): array {
        $mapping = $this->get_requested_language_mapping();

        if ( empty( $mapping ) ) {
            return $scan;
        }

        $target_codes = [];

        foreach ( $scan['languages']['items'] ?? [] as $index => $language ) {
            $source_code = sanitize_key( (string) ( $language['source_code'] ?? $language['code'] ?? '' ) );

            if ( ! $source_code || empty( $mapping[ $source_code ] ) ) {
                continue;
            }

            $target = WP_LOC_Language_Registry::normalize_external_language(
                (string) ( $mapping[ $source_code ]['code'] ?? '' ),
                (string) ( $mapping[ $source_code ]['locale'] ?? '' ),
                (string) ( $mapping[ $source_code ]['display_name'] ?? '' )
            );

            $target_code = sanitize_key( (string) ( $target['code'] ?? '' ) );
            if ( ! $target_code ) {
                wp_send_json_error( [ 'message' => __( 'Invalid language mapping selected.', 'wp-loc' ) ], 400 );
            }

            if ( isset( $target_codes[ $target_code ] ) ) {
                wp_send_json_error( [ 'message' => __( 'Each detected language must be mapped to a unique WP-LOC language.', 'wp-loc' ) ], 400 );
            }

            $target_codes[ $target_code ] = true;

            $scan['languages']['items'][ $index ] = array_merge( $language, [
                'code'         => $target_code,
                'wpml_code'    => sanitize_key( (string) ( $target['wpml_code'] ?? WP_LOC_Language_Registry::wpml_code_from_locale( (string) ( $target['locale'] ?? $target_code ) ) ) ),
                'locale'       => sanitize_text_field( (string) ( $target['locale'] ?? $target_code ) ),
                'display_name' => sanitize_text_field( (string) ( $language['display_name'] ?? $target['display_name'] ?? '' ) ),
                'flag'         => sanitize_key( (string) ( $target['flag'] ?? '' ) ),
                'confidence'   => 'manual',
            ] );
        }

        return $scan;
    }

    private function get_requested_language_mapping(): array {
        $raw = isset( $_POST['language_mapping'] ) ? wp_unslash( $_POST['language_mapping'] ) : '';
        if ( ! is_string( $raw ) || trim( $raw ) === '' ) {
            return [];
        }

        $decoded = json_decode( $raw, true );
        if ( ! is_array( $decoded ) ) {
            wp_send_json_error( [ 'message' => __( 'Invalid language mapping payload.', 'wp-loc' ) ], 400 );
        }

        $mapping = [];
        foreach ( $decoded as $source_code => $target ) {
            if ( ! is_array( $target ) ) {
                continue;
            }

            $source_code = sanitize_key( (string) $source_code );
            if ( ! $source_code ) {
                continue;
            }

            $mapping[ $source_code ] = [
                'code'         => sanitize_key( (string) ( $target['code'] ?? '' ) ),
                'locale'       => sanitize_text_field( (string) ( $target['locale'] ?? '' ) ),
                'display_name' => sanitize_text_field( (string) ( $target['display_name'] ?? '' ) ),
            ];
        }

        return $mapping;
    }

    private function is_generated_display_name( string $display_name, string $slug, string $locale ): bool {
        $display_name = trim( $display_name );

        if ( $display_name === '' || $display_name === strtoupper( $slug ) ) {
            return true;
        }

        return $display_name === WP_LOC_Languages::get_language_display_name( $locale ?: $slug );
    }

    private function adopt_content_settings_from_scan( array $scan ): array {
        $post_types = $this->extract_summary_labels( $scan['details']['post_types'] ?? [] );
        $taxonomies = $this->extract_summary_labels( $scan['details']['taxonomies'] ?? [] );

        if ( $post_types ) {
            $existing = get_option( WP_LOC_Admin_Settings::OPTION_KEY, [] );
            $existing = is_array( $existing ) ? array_map( 'sanitize_key', $existing ) : [];
            update_option( WP_LOC_Admin_Settings::OPTION_KEY, array_values( array_unique( array_merge( $existing, $post_types ) ) ) );
        }

        if ( $taxonomies ) {
            $existing = get_option( WP_LOC_Admin_Settings::TAXONOMIES_OPTION_KEY, [] );
            $existing = is_array( $existing ) ? array_map( 'sanitize_key', $existing ) : [];
            update_option( WP_LOC_Admin_Settings::TAXONOMIES_OPTION_KEY, array_values( array_unique( array_merge( $existing, $taxonomies ) ) ) );
        }

        return [
            'post_types' => count( $post_types ),
            'taxonomies' => count( $taxonomies ),
        ];
    }

    private function extract_summary_labels( array $items ): array {
        $labels = [];

        foreach ( $items as $item ) {
            if ( ! is_array( $item ) ) {
                continue;
            }

            $label = sanitize_key( (string) ( $item['label'] ?? '' ) );
            if ( $label ) {
                $labels[] = $label;
            }
        }

        return array_values( array_unique( $labels ) );
    }

    private function detect_languages(): array {
        global $wpdb;

        $items = [];
        $translations_table = $wpdb->prefix . 'icl_translations';
        $display_names = $this->detect_language_display_names();

        if ( $this->table_exists( $translations_table ) ) {
            $codes = $wpdb->get_col( "SELECT DISTINCT language_code FROM {$translations_table} WHERE language_code != '' ORDER BY language_code ASC" );

            foreach ( $codes as $code ) {
                $this->add_detected_language( $items, (string) $code, WP_LOC_Languages::get_language_locale( (string) $code ), $display_names[ (string) $code ] ?? '' );
            }
        }

        $locale_table = $wpdb->prefix . 'icl_locale_map';
        if ( $this->table_exists( $locale_table ) ) {
            $rows = $wpdb->get_results( "SELECT code, locale FROM {$locale_table}" );

            foreach ( $rows as $row ) {
                $code = sanitize_key( (string) $row->code );
                if ( ! $code ) {
                    continue;
                }

                $this->add_detected_language( $items, $code, sanitize_text_field( (string) $row->locale ) ?: $code, $display_names[ $code ] ?? '' );
            }
        }

        return [
            'count' => count( $items ),
            'items' => array_values( $items ),
        ];
    }

    private function add_detected_language( array &$items, string $source_code, string $locale, string $display_name = '' ): void {
        $language = WP_LOC_Language_Registry::normalize_external_language( $source_code, $locale, $display_name );
        $source_code = sanitize_key( (string) ( $language['source_code'] ?? $source_code ) );
        $slug = sanitize_key( (string) ( $language['code'] ?? '' ) );

        if ( ! $slug ) {
            return;
        }

        $items[ $slug ] = [
            'code'        => $slug,
            'wpml_code'   => sanitize_key( (string) ( $language['wpml_code'] ?? WP_LOC_Language_Registry::wpml_code_from_locale( (string) ( $language['locale'] ?? $slug ) ) ) ),
            'locale'      => sanitize_text_field( (string) ( $language['locale'] ?? $slug ) ),
            'source_code' => $source_code,
            'display_name' => sanitize_text_field( (string) ( $language['display_name'] ?? '' ) ),
            'flag'        => sanitize_key( (string) ( $language['flag'] ?? '' ) ),
            'confidence'  => sanitize_key( (string) ( $language['confidence'] ?? 'fallback' ) ),
        ];
    }

    private function detect_language_display_names(): array {
        global $wpdb;

        $names = [];
        $names = $this->detect_language_names_from_language_table( $names );
        $translations_table = $wpdb->prefix . 'icl_languages_translations';

        if ( ! $this->table_exists( $translations_table ) ) {
            return $names;
        }

        $switcher_names = [];
        $display_codes = $this->detect_switcher_display_language_codes();
        $rows = $wpdb->get_results(
            "SELECT language_code, display_language_code, name
             FROM {$translations_table}
             WHERE language_code != ''
               AND display_language_code != ''
               AND name != ''"
        );

        $fallback_names = [];

        foreach ( $rows as $row ) {
            $language_code = sanitize_key( (string) $row->language_code );
            $display_language_code = sanitize_key( (string) $row->display_language_code );
            $name = trim( sanitize_text_field( (string) $row->name ) );

            if ( ! $language_code || ! $display_language_code || $name === '' ) {
                continue;
            }

            if ( $display_codes && in_array( $display_language_code, $display_codes, true ) ) {
                $switcher_names[ $language_code ][ $display_language_code ] = $name;
            }

            if ( $language_code === $display_language_code ) {
                $this->add_detected_display_name( $names, $language_code, $name );
                continue;
            }

            if ( ! isset( $fallback_names[ $language_code ] ) ) {
                $this->add_detected_display_name( $fallback_names, $language_code, $name );
            }
        }

        foreach ( $switcher_names as $language_code => $localized_names ) {
            $unique_names = array_values( array_unique( array_filter( array_map( 'trim', $localized_names ) ) ) );

            if ( count( $unique_names ) === 1 ) {
                $this->add_detected_display_name( $names, (string) $language_code, (string) $unique_names[0] );
            }
        }

        return $names + $fallback_names;
    }

    private function detect_switcher_display_language_codes(): array {
        global $wpdb;

        $codes = [];
        $settings = get_option( 'wpml_language_switcher', [] );

        if ( is_array( $settings ) && ! empty( $settings['languages_order'] ) && is_array( $settings['languages_order'] ) ) {
            $codes = array_merge( $codes, array_map( 'sanitize_key', $settings['languages_order'] ) );
        }

        $sitepress_settings = get_option( 'icl_sitepress_settings', [] );
        if ( is_array( $sitepress_settings ) && ! empty( $sitepress_settings['languages_order'] ) && is_array( $sitepress_settings['languages_order'] ) ) {
            $codes = array_merge( $codes, array_map( 'sanitize_key', $sitepress_settings['languages_order'] ) );
        }

        $translations_table = $wpdb->prefix . 'icl_translations';
        if ( $this->table_exists( $translations_table ) ) {
            $db_codes = $wpdb->get_col( "SELECT DISTINCT language_code FROM {$translations_table} WHERE language_code != ''" );
            $codes = array_merge( $codes, array_map( 'sanitize_key', (array) $db_codes ) );
        }

        return array_values( array_unique( array_filter( $codes ) ) );
    }

    private function detect_language_names_from_language_table( array $names ): array {
        global $wpdb;

        $table = $wpdb->prefix . 'icl_languages';
        if ( ! $this->table_exists( $table ) ) {
            return $names;
        }

        $columns = $wpdb->get_col( "SHOW COLUMNS FROM {$table}", 0 );
        $columns = array_map( 'strval', (array) $columns );

        if ( ! in_array( 'code', $columns, true ) ) {
            return $names;
        }

        $name_column = '';
        foreach ( [ 'native_name', 'display_name', 'translated_name', 'english_name' ] as $candidate ) {
            if ( in_array( $candidate, $columns, true ) ) {
                $name_column = $candidate;
                break;
            }
        }

        if ( ! $name_column ) {
            return $names;
        }

        $rows = $wpdb->get_results( "SELECT code, {$name_column} AS name FROM {$table} WHERE code != '' AND {$name_column} != ''" );

        foreach ( $rows as $row ) {
            $this->add_detected_display_name( $names, (string) $row->code, (string) $row->name );
        }

        return $names;
    }

    private function add_detected_display_name( array &$names, string $code, string $name ): void {
        $code = sanitize_key( $code );
        $name = trim( sanitize_text_field( $name ) );

        if ( ! $code || $name === '' ) {
            return;
        }

        $names[ $code ] = $name;

        $normalized = $this->normalize_language_slug( $code );
        if ( $normalized && ! isset( $names[ $normalized ] ) ) {
            $names[ $normalized ] = $name;
        }
    }

    private function normalize_language_slug( string $code, string $locale = '' ): string {
        $language = WP_LOC_Language_Registry::normalize_external_language( $code, $locale ?: WP_LOC_Languages::get_language_locale( $code ) );
        $slug = (string) ( $language['code'] ?? '' );

        return sanitize_key( $slug ?: $code );
    }

    private function normalize_translation_language_codes( array $scan ): void {
        global $wpdb;

        $translations_table = $wpdb->prefix . 'icl_translations';
        if ( ! $this->table_exists( $translations_table ) ) {
            return;
        }

        foreach ( $scan['languages']['items'] ?? [] as $language ) {
            $source_code = sanitize_key( (string) ( $language['source_code'] ?? '' ) );
            $target_code = sanitize_key( (string) ( $language['wpml_code'] ?? '' ) );

            if ( ! $target_code ) {
                $target_code = WP_LOC_DB::to_db_language_code( sanitize_key( (string) ( $language['code'] ?? '' ) ) ) ?: '';
            }

            if ( ! $source_code || ! $target_code || $source_code === $target_code ) {
                continue;
            }

            $wpdb->query( $wpdb->prepare(
                "UPDATE {$translations_table} SET language_code = %s WHERE language_code = %s",
                $target_code,
                $source_code
            ) );

            $wpdb->query( $wpdb->prepare(
                "UPDATE {$translations_table} SET source_language_code = %s WHERE source_language_code = %s",
                $target_code,
                $source_code
            ) );
        }
    }

    private function normalize_configured_languages( array $languages ): array {
        $normalized = [];

        foreach ( $languages as $slug => $data ) {
            if ( ! is_array( $data ) ) {
                continue;
            }

            $locale = sanitize_text_field( (string) ( $data['locale'] ?? $slug ) );
            $normalized_slug = $this->normalize_language_slug( (string) $slug, $locale );

            if ( ! $normalized_slug ) {
                continue;
            }

            if ( isset( $normalized[ $normalized_slug ] ) ) {
                $normalized[ $normalized_slug ] = array_merge( $data, $normalized[ $normalized_slug ] );
            } else {
                $normalized[ $normalized_slug ] = $data;
            }

            $normalized[ $normalized_slug ]['locale'] = $locale ?: $normalized_slug;
            $normalized[ $normalized_slug ]['wpml_code'] = WP_LOC_Languages::get_wpml_code( $normalized_slug );
            if ( empty( $normalized[ $normalized_slug ]['display_name'] ) ) {
                $normalized[ $normalized_slug ]['display_name'] = WP_LOC_Languages::get_language_display_name( $locale ?: $normalized_slug );
            }
        }

        if ( $normalized !== $languages ) {
            update_option( 'wp_loc_languages', $normalized );
            update_option( 'wp_loc_flush_rewrite_rules', true );
            WP_LOC_Languages::flush();
        }

        return $normalized;
    }

    private function get_legacy_tables(): array {
        global $wpdb;

        $like = $wpdb->esc_like( $wpdb->prefix . 'icl_' ) . '%';
        $tables = $wpdb->get_col( $wpdb->prepare( 'SHOW TABLES LIKE %s', $like ) );

        return array_values( array_filter( array_map( 'strval', $tables ) ) );
    }

    private function table_exists( string $table ): bool {
        global $wpdb;

        return $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) === $table;
    }

    private function table_summary( string $table ): array {
        global $wpdb;

        return [
            'label' => $this->humanize_table_name( $table ),
            'rows'  => (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$table}`" ),
        ];
    }

    private function humanize_table_name( string $table ): string {
        global $wpdb;

        $name = preg_replace( '/^' . preg_quote( $wpdb->prefix, '/' ) . '/', '', $table );
        $name = preg_replace( '/^icl_/', '', (string) $name );

        return ucwords( str_replace( '_', ' ', $name ) );
    }

    private function sum_element_counts_by_prefix( array $counts, string $prefix ): int {
        $total = 0;

        foreach ( $counts as $type => $count ) {
            if ( str_starts_with( (string) $type, $prefix ) ) {
                $total += (int) $count;
            }
        }

        return $total;
    }

    private function summarize_element_counts( array $counts, string $prefix, array $exclude_names = [] ): array {
        $summary = [];

        foreach ( $counts as $type => $count ) {
            if ( ! str_starts_with( (string) $type, $prefix ) ) {
                continue;
            }

            $name = substr( (string) $type, strlen( $prefix ) );

            if ( in_array( $name, $exclude_names, true ) ) {
                continue;
            }

            $summary[] = [
                'label' => $name,
                'count' => (int) $count,
            ];
        }

        usort( $summary, static fn( array $a, array $b ): int => strcmp( $a['label'], $b['label'] ) );

        return $summary;
    }

    private function count_localized_options(): int {
        global $wpdb;

        $languages = $this->detect_languages();
        $codes = array_filter( array_map(
            static fn( array $language ): string => sanitize_key( (string) ( $language['code'] ?? '' ) ),
            $languages['items'] ?? []
        ) );

        if ( empty( $codes ) ) {
            return 0;
        }

        $conditions = [];

        foreach ( $codes as $code ) {
            $conditions[] = $wpdb->prepare( 'option_name LIKE %s', '%' . $wpdb->esc_like( '_' . $code ) );
        }

        return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->options} WHERE " . implode( ' OR ', $conditions ) );
    }

    private function count_field_preferences(): int {
        global $wpdb;

        $postmeta = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->postmeta}
             WHERE meta_key IN ('" . 'wp' . "ml_cf_preferences', 'acfml_field_group_mode')"
        );

        $options = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->options}
             WHERE option_name IN ('wp_loc_acf_field_group_modes', 'wp_loc_acf_field_translation_modes_by_key')"
        );

        return $postmeta + $options;
    }

    private function count_legacy_options(): int {
        global $wpdb;

        return (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->options}
             WHERE option_name LIKE 'icl\_%'
                OR option_name LIKE '\_icl\_%'
                OR option_name LIKE '\_transient\_icl\_%'
                OR option_name LIKE '\_transient\_timeout\_icl\_%'"
        );
    }

    private function count_legacy_postmeta(): int {
        global $wpdb;

        return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key LIKE '\_icl\_%'" );
    }

    private function count_legacy_usermeta(): int {
        global $wpdb;

        return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->usermeta} WHERE meta_key LIKE '\_icl\_%'" );
    }

    private function count_legacy_commentmeta(): int {
        global $wpdb;

        return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->commentmeta} WHERE meta_key LIKE '\_icl\_%'" );
    }
}
