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
            'ajaxUrl' => admin_url( 'admin-ajax.php' ),
            'nonce'   => wp_create_nonce( 'wp_loc_db_optimization_wizard' ),
            'i18n'    => [
                'requestFailed' => __( 'Request failed. Please try again.', 'wp-loc' ),
                'scanning'      => __( 'Scanning database...', 'wp-loc' ),
                'optimizing'    => __( 'Optimizing database...', 'wp-loc' ),
                'noCleanup'     => __( 'No removable legacy data was found. Your database is already clean.', 'wp-loc' ),
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
                    <p class="wp-loc-db-wizard__eyebrow"><?php esc_html_e( 'Review', 'wp-loc' ); ?></p>
                    <h2><?php esc_html_e( 'Database scan summary', 'wp-loc' ); ?></h2>
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
        $cleanup = $this->cleanup_legacy_data( $scan );
        $languages_imported = $this->import_languages_from_scan( $scan );

        update_option( self::STATUS_OPTION, self::STATUS_COMPLETED );

        wp_send_json_success( [
            'languages_imported' => $languages_imported,
            'tables_removed'     => $cleanup['tables_removed'],
            'options_removed'    => $cleanup['options_removed'],
            'meta_removed'       => $cleanup['meta_removed'],
            'kept_rows'          => (int) ( $scan['compatible']['translation_rows'] ?? 0 ),
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

        $existing = WP_LOC_Languages::get_languages();
        $imported = 0;

        foreach ( $languages as $language ) {
            $slug = sanitize_key( (string) ( $language['code'] ?? '' ) );
            $locale = sanitize_text_field( (string) ( $language['locale'] ?? $slug ) );

            if ( ! $slug || isset( $existing[ $slug ] ) ) {
                continue;
            }

            $existing[ $slug ] = [
                'locale'       => $locale ?: $slug,
                'enabled'      => true,
                'display_name' => WP_LOC_Languages::get_language_display_name( $locale ?: $slug ),
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

    private function detect_languages(): array {
        global $wpdb;

        $items = [];
        $translations_table = $wpdb->prefix . 'icl_translations';

        if ( $this->table_exists( $translations_table ) ) {
            $codes = $wpdb->get_col( "SELECT DISTINCT language_code FROM {$translations_table} WHERE language_code != '' ORDER BY language_code ASC" );

            foreach ( $codes as $code ) {
                $items[ $code ] = [
                    'code'   => $code,
                    'locale' => WP_LOC_Languages::get_language_locale( $code ),
                ];
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

                $items[ $code ] = [
                    'code'   => $code,
                    'locale' => sanitize_text_field( (string) $row->locale ) ?: $code,
                ];
            }
        }

        return [
            'count' => count( $items ),
            'items' => array_values( $items ),
        ];
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
