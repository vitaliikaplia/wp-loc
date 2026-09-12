<?php

if ( ! defined( 'ABSPATH' ) ) exit;

class WP_LOC_Admin_Settings {

    const OPTION_KEY = 'wp_loc_translatable_post_types';
    const TAXONOMIES_OPTION_KEY = 'wp_loc_translatable_taxonomies';
    const AUTO_CREATE_POST_TRANSLATIONS_OPTION_KEY = 'wp_loc_auto_create_post_translations';
    const AUTO_CREATE_TERM_TRANSLATIONS_OPTION_KEY = 'wp_loc_auto_create_term_translations';
    const AUTO_CREATE_MENU_TRANSLATIONS_OPTION_KEY = 'wp_loc_auto_create_menu_translations';
    const SYNC_POST_TAXONOMIES_OPTION_KEY = 'wp_loc_sync_post_taxonomies';
    const SYNC_FEATURED_IMAGE_OPTION_KEY = 'wp_loc_sync_featured_image';
    const SYNC_POST_ATTRIBUTES_OPTION_KEY = 'wp_loc_sync_post_attributes';
    const SHOW_FLAGS_OPTION_KEY = 'wp_loc_show_switcher_flags';
    const SHOW_NAMES_OPTION_KEY = 'wp_loc_show_switcher_names';
    const HIDE_CURRENT_LANGUAGE_OPTION_KEY = 'wp_loc_hide_current_language_switcher';
    const HIDE_UNTRANSLATED_LANGUAGES_OPTION_KEY = 'wp_loc_hide_untranslated_languages_switcher';
    const FALLBACK_UNTRANSLATED_TO_HOME_OPTION_KEY = 'wp_loc_fallback_untranslated_switcher_to_home';
    const ENABLE_ACF_COMPAT_OPTION_KEY = 'wp_loc_enable_acf_compat';
    const ENABLE_YOAST_COMPAT_OPTION_KEY = 'wp_loc_enable_yoast_compat';
    const ENABLE_YOAST_SITEMAP_ALTERNATES_OPTION_KEY = 'wp_loc_enable_yoast_sitemap_alternates';
    const AI_ENGINE_OPTION_KEY = 'wp_loc_ai_engine';
    const AI_MODELS_OPTION_KEY = 'wp_loc_ai_models';
    const AI_TRANSLATE_CUSTOM_MENU_LINKS_OPTION_KEY = 'wp_loc_ai_translate_custom_menu_links';
    const TAB_CONTENT = 'content';
    const TAB_SWITCHER = 'switcher';
    const TAB_INTEGRATIONS = 'integrations';
    const TAB_AI = 'ai';
    const EXCLUDED_SELECTABLE_POST_TYPES = [ 'attachment', 'nav_menu_item', 'revision' ];
    const EXCLUDED_SELECTABLE_TAXONOMIES = [ 'nav_menu', 'post_format' ];

    private static $detected_translatable_post_types = null;
    private static $detected_translatable_post_type_counts = null;
    private static $detected_translatable_taxonomies = null;

    public function __construct() {
        add_action( 'admin_menu', [ $this, 'add_menu' ], 20 );
        add_action( 'admin_init', [ $this, 'migrate_legacy_ai_settings' ], 5 );
        add_action( 'admin_init', [ $this, 'handle_save' ] );
        add_filter( 'wp_loc_translatable_post_types', [ $this, 'filter_post_types' ] );
        add_filter( 'wp_loc_translatable_taxonomies', [ $this, 'filter_taxonomies' ] );
    }

    public function add_menu(): void {
        add_submenu_page(
            'wp-loc',
            __( 'Settings', 'wp-loc' ),
            __( 'Settings', 'wp-loc' ),
            'manage_options',
            'wp-loc-settings',
            [ $this, 'render_page' ]
        );
    }

    private function get_current_tab(): string {
        $tab = isset( $_GET['tab'] ) ? sanitize_key( (string) $_GET['tab'] ) : self::TAB_CONTENT;

        return isset( $this->get_tabs()[ $tab ] ) ? $tab : self::TAB_CONTENT;
    }

    /**
     * Settings tabs as slug => label.
     *
     * Add-ons register their own tab through the `wp_loc_settings_tabs` filter and
     * render it on `wp_loc_settings_render_{$tab}`; extra fields for any tab go on
     * `wp_loc_settings_fields_{$tab}`, and both are saved on `wp_loc_settings_save_{$tab}`.
     */
    private function get_tabs(): array {
        $tabs = [
            self::TAB_CONTENT      => __( 'Content Translation', 'wp-loc' ),
            self::TAB_SWITCHER     => __( 'Frontend Language Switcher', 'wp-loc' ),
            self::TAB_INTEGRATIONS => __( 'Integrations', 'wp-loc' ),
            self::TAB_AI           => __( 'AI', 'wp-loc' ),
        ];

        return (array) apply_filters( 'wp_loc_settings_tabs', $tabs );
    }

    private function get_tab_url( string $tab ): string {
        return add_query_arg(
            [
                'page' => 'wp-loc-settings',
                'tab'  => $tab,
            ],
            admin_url( 'admin.php' )
        );
    }

    private function render_tabs( string $current_tab ): void {
        $tabs = $this->get_tabs();

        echo '<nav class="nav-tab-wrapper wp-clearfix">';

        foreach ( $tabs as $tab => $label ) {
            $class = $tab === $current_tab ? 'nav-tab nav-tab-active' : 'nav-tab';
            echo '<a href="' . esc_url( $this->get_tab_url( $tab ) ) . '" class="' . esc_attr( $class ) . '">' . esc_html( $label ) . '</a>';
        }

        echo '</nav>';
    }

    private function render_switcher_usage_examples(): void {
        $helper_code = <<<'PHP'
<?php
if ( function_exists( 'wp_loc_the_language_switcher' ) ) {
    wp_loc_the_language_switcher();
}
?>
PHP;

        $custom_code = <<<'PHP'
<?php
$languages = function_exists( 'icl_get_languages' )
    ? icl_get_languages( 'skip_missing=0&orderby=KEY' )
    : [];

$current_language = null;

foreach ( $languages as $language ) {
    if ( ! empty( $language['active'] ) ) {
        $current_language = $language;
        break;
    }
}
?>

<?php if ( $current_language ) : ?>
    <ul class="languageSwitcher">
        <li>
            <span><?php echo esc_html( $current_language['native_name'] ?? $current_language['language_code'] ); ?></span>

            <ul>
                <?php foreach ( $languages as $language ) : ?>
                    <?php if ( ! empty( $language['active'] ) ) continue; ?>

                    <li>
                        <a href="<?php echo esc_url( $language['url'] ); ?>" title="<?php echo esc_attr( $language['native_name'] ?? $language['language_code'] ); ?>">
                            <?php echo esc_html( $language['native_name'] ?? $language['language_code'] ); ?>
                        </a>
                    </li>
                <?php endforeach; ?>
            </ul>
        </li>
    </ul>
<?php endif; ?>
PHP;

        $twig_code = <<<'TWIG'
{% set languages = wp_loc_languages() %}

{% if current_language and languages %}
    <ul class="languageSwitcher">
        <li>
            <span>{{ current_language.name }}</span>

            <ul>
                {% for language in languages %}
                    {% if not language.active %}
                        <li>
                            <a href="{{ language.url }}" title="{{ language.name }}">
                                {{ language.name }}
                            </a>
                        </li>
                    {% endif %}
                {% endfor %}
            </ul>
        </li>
    </ul>
{% endif %}
TWIG;

        $examples = [
            [
                'title'       => __( 'Ready-to-render helper', 'wp-loc' ),
                'language'    => 'PHP',
                'code'        => $helper_code,
                'description' => __( 'Outputs the default WP-LOC switcher markup and follows the switcher settings above.', 'wp-loc' ),
            ],
            [
                'title'       => __( 'Custom dropdown pattern', 'wp-loc' ),
                'language'    => 'PHP',
                'code'        => $custom_code,
                'description' => __( 'Use this pattern when the theme renders the current language separately and prints the other languages in a nested list.', 'wp-loc' ),
            ],
            [
                'title'       => __( 'Custom dropdown pattern for Twig', 'wp-loc' ),
                'language'    => 'Twig',
                'code'        => $twig_code,
                'description' => __( 'Use this Timber/Twig version when the switcher is rendered from a Twig template.', 'wp-loc' ),
            ],
        ];

        ?>
        <div class="wp-loc-settings-section">
            <h2><?php esc_html_e( 'Switcher usage', 'wp-loc' ); ?></h2>
            <p class="description"><?php esc_html_e( 'Add one of these snippets to the theme template where the frontend language switcher should appear.', 'wp-loc' ); ?></p>

            <div class="wp-loc-switcher-examples">
                <?php foreach ( $examples as $example ) : ?>
                    <article class="wp-loc-switcher-example">
                        <div class="wp-loc-switcher-example__body">
                            <div class="wp-loc-switcher-example__content">
                                <span class="wp-loc-switcher-example__language"><?php echo esc_html( $example['language'] ); ?></span>
                                <h3><?php echo esc_html( $example['title'] ); ?></h3>
                                <p class="description"><?php echo esc_html( $example['description'] ); ?></p>
                            </div>
                            <pre class="wp-loc-switcher-example__code"><code><?php echo esc_html( $example['code'] ); ?></code></pre>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>
        </div>
        <?php
    }

    /**
     * Filter translatable post types based on saved settings
     */
    public function filter_post_types( array $post_types ): array {
        $saved = get_option( self::OPTION_KEY );
        $detected = self::get_detected_translatable_post_types();

        if ( $saved !== false && is_array( $saved ) ) {
            $saved = array_filter( array_map( 'sanitize_key', array_map( 'strval', $saved ) ) );

            return array_values( array_unique( array_merge( $saved, $detected ) ) );
        }

        return array_values( array_unique( array_merge( $post_types, $detected ) ) );
    }

    /**
     * Check if a post type is translatable
     */
    public static function is_translatable( string $post_type ): bool {
        $translatable = apply_filters( 'wp_loc_translatable_post_types', [ 'post', 'page' ] );
        return in_array( $post_type, $translatable, true );
    }

    /**
     * Filter translatable taxonomies based on saved settings
     */
    public function filter_taxonomies( array $taxonomies ): array {
        $saved = get_option( self::TAXONOMIES_OPTION_KEY );
        $detected = self::get_detected_translatable_taxonomies();

        if ( $saved !== false && is_array( $saved ) ) {
            $saved = array_filter( array_map( 'sanitize_key', array_map( 'strval', $saved ) ) );

            return array_values( array_unique( array_merge( $saved, $detected ) ) );
        }

        return array_values( array_unique( array_merge( $taxonomies, $detected ) ) );
    }

    private static function get_detected_translatable_post_types(): array {
        if ( self::$detected_translatable_post_types !== null ) {
            return self::$detected_translatable_post_types;
        }

        return self::$detected_translatable_post_types = array_keys( self::get_detected_translatable_post_type_counts() );
    }

    private static function get_detected_translatable_post_type_counts(): array {
        if ( self::$detected_translatable_post_type_counts !== null ) {
            return self::$detected_translatable_post_type_counts;
        }

        global $wpdb;

        $table = $wpdb->prefix . 'icl_translations';
        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
            return self::$detected_translatable_post_type_counts = [];
        }

        $rows = $wpdb->get_results(
            "SELECT REPLACE(element_type, 'post_', '') AS post_type, COUNT(*) AS translation_count
             FROM {$table}
             WHERE element_type LIKE 'post\_%'
             GROUP BY element_type",
            ARRAY_A
        );

        $counts = [];

        foreach ( (array) $rows as $row ) {
            $post_type = sanitize_key( (string) ( $row['post_type'] ?? '' ) );
            $count = (int) ( $row['translation_count'] ?? 0 );

            if ( $post_type === '' || $count < 1 || in_array( $post_type, self::EXCLUDED_SELECTABLE_POST_TYPES, true ) || ! post_type_exists( $post_type ) ) {
                continue;
            }

            $counts[ $post_type ] = $count;
        }

        return self::$detected_translatable_post_type_counts = $counts;
    }

    private static function get_detected_translatable_taxonomies(): array {
        if ( self::$detected_translatable_taxonomies !== null ) {
            return self::$detected_translatable_taxonomies;
        }

        global $wpdb;

        $table = $wpdb->prefix . 'icl_translations';
        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
            return self::$detected_translatable_taxonomies = [];
        }

        $taxonomies = $wpdb->get_col(
            "SELECT DISTINCT REPLACE(element_type, 'tax_', '') AS taxonomy
             FROM {$table}
             WHERE element_type LIKE 'tax\_%'"
        );

        return self::$detected_translatable_taxonomies = array_values( array_filter(
            array_map( 'sanitize_key', array_map( 'strval', (array) $taxonomies ) ),
            static fn( string $taxonomy ): bool => $taxonomy !== '' && ! in_array( $taxonomy, self::EXCLUDED_SELECTABLE_TAXONOMIES, true ) && taxonomy_exists( $taxonomy )
        ) );
    }

    private static function get_selectable_post_types(): array {
        return array_filter(
            get_post_types( [], 'objects' ),
            static function ( $post_type ): bool {
                if ( ! isset( $post_type->name ) || in_array( $post_type->name, self::EXCLUDED_SELECTABLE_POST_TYPES, true ) ) {
                    return false;
                }

                if ( ! empty( $post_type->_builtin ) && empty( $post_type->public ) ) {
                    return false;
                }

                return ! empty( $post_type->public ) || empty( $post_type->_builtin );
            }
        );
    }

    private static function get_selectable_taxonomies(): array {
        return array_filter(
            get_taxonomies( [], 'objects' ),
            static function ( $taxonomy ): bool {
                if ( ! isset( $taxonomy->name ) || in_array( $taxonomy->name, self::EXCLUDED_SELECTABLE_TAXONOMIES, true ) ) {
                    return false;
                }

                if ( ! empty( $taxonomy->_builtin ) && empty( $taxonomy->public ) ) {
                    return false;
                }

                return ! empty( $taxonomy->public ) || empty( $taxonomy->_builtin );
            }
        );
    }

    private function render_post_type_checkboxes( array $post_types, array $selected ): void {
        $translation_counts = self::get_detected_translatable_post_type_counts();

        foreach ( $post_types as $pt ) :
            $translation_count = (int) ( $translation_counts[ $pt->name ] ?? 0 );
            ?>
            <label class="wp-loc-settings-label">
                <input type="checkbox"
                       name="wp_loc_post_types[]"
                       value="<?php echo esc_attr( $pt->name ); ?>"
                       <?php checked( in_array( $pt->name, $selected, true ) ); ?>
                />
                <span><?php echo esc_html( $pt->labels->name ); ?></span>
                <code>(<?php echo esc_html( $pt->name ); ?>)</code>
                <?php if ( $translation_count > 0 ) : ?>
                    <span class="description">
                        <?php
                        echo esc_html( sprintf(
                            _n( '%s translation record found.', '%s translation records found.', $translation_count, 'wp-loc' ),
                            number_format_i18n( $translation_count )
                        ) );
                        ?>
                    </span>
                <?php endif; ?>
            </label>
            <?php
        endforeach;
    }

    private function render_taxonomy_checkboxes( array $taxonomies, array $selected ): void {
        foreach ( $taxonomies as $taxonomy ) :
            ?>
            <label class="wp-loc-settings-label">
                <input type="checkbox"
                       name="wp_loc_taxonomies[]"
                       value="<?php echo esc_attr( $taxonomy->name ); ?>"
                       <?php checked( in_array( $taxonomy->name, $selected, true ) ); ?>
                />
                <span><?php echo esc_html( $taxonomy->labels->name ); ?></span>
                <code>(<?php echo esc_html( $taxonomy->name ); ?>)</code>
            </label>
            <?php
        endforeach;
    }

    /**
     * Check if taxonomy is translatable
     */
    public static function is_translatable_taxonomy( string $taxonomy ): bool {
        $default_taxonomies = [ 'category', 'post_tag' ];
        $translatable = apply_filters( 'wp_loc_translatable_taxonomies', $default_taxonomies );

        return in_array( $taxonomy, $translatable, true );
    }

    /**
     * Check whether frontend language switcher should display flags
     */
    public static function show_switcher_flags(): bool {
        return (bool) get_option( self::SHOW_FLAGS_OPTION_KEY, true );
    }

    public static function show_switcher_names(): bool {
        return (bool) get_option( self::SHOW_NAMES_OPTION_KEY, true );
    }

    public static function hide_current_language_in_switcher(): bool {
        return (bool) get_option( self::HIDE_CURRENT_LANGUAGE_OPTION_KEY, false );
    }

    public static function hide_untranslated_languages_in_switcher(): bool {
        return (bool) get_option( self::HIDE_UNTRANSLATED_LANGUAGES_OPTION_KEY, false );
    }

    public static function fallback_untranslated_switcher_links_to_home(): bool {
        return (bool) get_option( self::FALLBACK_UNTRANSLATED_TO_HOME_OPTION_KEY, true );
    }

    public static function should_auto_create_post_translations(): bool {
        return (bool) get_option( self::AUTO_CREATE_POST_TRANSLATIONS_OPTION_KEY, true );
    }

    public static function should_auto_create_term_translations(): bool {
        return (bool) get_option( self::AUTO_CREATE_TERM_TRANSLATIONS_OPTION_KEY, true );
    }

    public static function should_auto_create_menu_translations(): bool {
        return (bool) get_option( self::AUTO_CREATE_MENU_TRANSLATIONS_OPTION_KEY, true );
    }

    public static function should_sync_post_taxonomies(): bool {
        return (bool) get_option( self::SYNC_POST_TAXONOMIES_OPTION_KEY, true );
    }

    public static function should_sync_featured_image(): bool {
        return (bool) get_option( self::SYNC_FEATURED_IMAGE_OPTION_KEY, true );
    }

    public static function should_sync_post_attributes(): bool {
        return (bool) get_option( self::SYNC_POST_ATTRIBUTES_OPTION_KEY, true );
    }

    public static function is_acf_compat_enabled(): bool {
        return (bool) get_option( self::ENABLE_ACF_COMPAT_OPTION_KEY, true );
    }

    public static function is_yoast_compat_enabled(): bool {
        return (bool) get_option( self::ENABLE_YOAST_COMPAT_OPTION_KEY, true );
    }

    public static function is_yoast_sitemap_alternates_enabled(): bool {
        return (bool) get_option( self::ENABLE_YOAST_SITEMAP_ALTERNATES_OPTION_KEY, true );
    }

    private static function normalize_ai_engine( string $engine ): string {
        return match ( sanitize_key( $engine ) ) {
            'claude' => 'anthropic',
            'gemini' => 'google',
            default  => sanitize_key( $engine ),
        };
    }

    public static function get_ai_engine(): string {
        $providers = WP_LOC_AI::get_connected_providers();

        if ( $providers === [] ) {
            return '';
        }

        $engine = self::normalize_ai_engine( (string) get_option( self::AI_ENGINE_OPTION_KEY, '' ) );

        return isset( $providers[ $engine ] ) ? $engine : (string) array_key_first( $providers );
    }

    public static function get_ai_model( string $provider_id = '' ): string {
        $provider_id = $provider_id !== '' ? sanitize_key( $provider_id ) : self::get_ai_engine();
        $models = WP_LOC_AI::get_provider_models( $provider_id );

        if ( $models === [] ) {
            return '';
        }

        $saved_models = get_option( self::AI_MODELS_OPTION_KEY, [] );
        $saved_models = is_array( $saved_models ) ? $saved_models : [];
        $model_id = isset( $saved_models[ $provider_id ] ) ? sanitize_text_field( (string) $saved_models[ $provider_id ] ) : '';

        return isset( $models[ $model_id ] ) ? $model_id : (string) array_key_first( $models );
    }

    public static function should_ai_translate_custom_menu_links(): bool {
        return (bool) get_option( self::AI_TRANSLATE_CUSTOM_MENU_LINKS_OPTION_KEY, false );
    }

    public function migrate_legacy_ai_settings(): void {
        $legacy_engine = sanitize_key( (string) get_option( self::AI_ENGINE_OPTION_KEY, '' ) );
        $engine = self::normalize_ai_engine( $legacy_engine );
        $legacy_model_options = [
            'openai'    => 'wp_loc_openai_model',
            'anthropic' => 'wp_loc_claude_model',
            'google'    => 'wp_loc_gemini_model',
        ];
        $saved_models = get_option( self::AI_MODELS_OPTION_KEY, [] );
        $saved_models = is_array( $saved_models ) ? $saved_models : [];
        $models_changed = false;

        foreach ( $legacy_model_options as $provider_id => $legacy_option ) {
            if ( ! empty( $saved_models[ $provider_id ] ) ) {
                continue;
            }

            $legacy_model = get_option( $legacy_option, '' );

            if ( is_string( $legacy_model ) && trim( $legacy_model ) !== '' ) {
                $saved_models[ $provider_id ] = sanitize_text_field( trim( $legacy_model ) );
                $models_changed = true;
            }
        }

        if ( $models_changed ) {
            update_option( self::AI_MODELS_OPTION_KEY, $saved_models );
        }

        if ( $legacy_engine !== '' && $engine !== $legacy_engine ) {
            update_option( self::AI_ENGINE_OPTION_KEY, $engine );
        }

        foreach ( [
            'wp_loc_openai_api_key',
            'wp_loc_openai_model',
            'wp_loc_claude_api_key',
            'wp_loc_claude_model',
            'wp_loc_gemini_api_key',
            'wp_loc_gemini_model',
        ] as $legacy_option ) {
            delete_option( $legacy_option );
        }
    }

    public function handle_save(): void {
        if ( ! isset( $_POST['wp_loc_settings_nonce'] ) ) return;
        if ( ! wp_verify_nonce( $_POST['wp_loc_settings_nonce'], 'wp_loc_save_settings' ) ) return;
        if ( ! current_user_can( 'manage_options' ) ) return;

        $current_tab = isset( $_POST['wp_loc_settings_tab'] ) ? sanitize_key( (string) $_POST['wp_loc_settings_tab'] ) : self::TAB_CONTENT;

        if ( $current_tab === self::TAB_CONTENT ) {
            $selected = isset( $_POST['wp_loc_post_types'] ) ? array_map( 'sanitize_key', (array) $_POST['wp_loc_post_types'] ) : [];
            $selected_taxonomies = isset( $_POST['wp_loc_taxonomies'] ) ? array_map( 'sanitize_key', (array) $_POST['wp_loc_taxonomies'] ) : [];
            $auto_create_posts = isset( $_POST['wp_loc_auto_create_post_translations'] ) ? 1 : 0;
            $auto_create_terms = isset( $_POST['wp_loc_auto_create_term_translations'] ) ? 1 : 0;
            $auto_create_menus = isset( $_POST['wp_loc_auto_create_menu_translations'] ) ? 1 : 0;
            $sync_post_taxonomies = isset( $_POST['wp_loc_sync_post_taxonomies'] ) ? 1 : 0;
            $sync_featured_image = isset( $_POST['wp_loc_sync_featured_image'] ) ? 1 : 0;
            $sync_post_attributes = isset( $_POST['wp_loc_sync_post_attributes'] ) ? 1 : 0;
            $translate_custom_menu_links = isset( $_POST['wp_loc_ai_translate_custom_menu_links'] ) ? 1 : 0;

            update_option( self::OPTION_KEY, $selected );
            update_option( self::TAXONOMIES_OPTION_KEY, $selected_taxonomies );
            update_option( self::AUTO_CREATE_POST_TRANSLATIONS_OPTION_KEY, $auto_create_posts );
            update_option( self::AUTO_CREATE_TERM_TRANSLATIONS_OPTION_KEY, $auto_create_terms );
            update_option( self::AUTO_CREATE_MENU_TRANSLATIONS_OPTION_KEY, $auto_create_menus );
            update_option( self::SYNC_POST_TAXONOMIES_OPTION_KEY, $sync_post_taxonomies );
            update_option( self::SYNC_FEATURED_IMAGE_OPTION_KEY, $sync_featured_image );
            update_option( self::SYNC_POST_ATTRIBUTES_OPTION_KEY, $sync_post_attributes );
            update_option( self::AI_TRANSLATE_CUSTOM_MENU_LINKS_OPTION_KEY, $translate_custom_menu_links );
        } elseif ( $current_tab === self::TAB_SWITCHER ) {
            $show_flags = isset( $_POST['wp_loc_show_switcher_flags'] ) ? 1 : 0;
            $show_names = isset( $_POST['wp_loc_show_switcher_names'] ) ? 1 : 0;
            $hide_current = isset( $_POST['wp_loc_hide_current_language_switcher'] ) ? 1 : 0;
            $hide_untranslated = isset( $_POST['wp_loc_hide_untranslated_languages_switcher'] ) ? 1 : 0;
            $fallback_untranslated_to_home = isset( $_POST['wp_loc_fallback_untranslated_switcher_to_home'] ) ? 1 : 0;

            update_option( self::SHOW_FLAGS_OPTION_KEY, $show_flags );
            update_option( self::SHOW_NAMES_OPTION_KEY, $show_names );
            update_option( self::HIDE_CURRENT_LANGUAGE_OPTION_KEY, $hide_current );
            update_option( self::HIDE_UNTRANSLATED_LANGUAGES_OPTION_KEY, $hide_untranslated );
            update_option( self::FALLBACK_UNTRANSLATED_TO_HOME_OPTION_KEY, $fallback_untranslated_to_home );
        } elseif ( $current_tab === self::TAB_INTEGRATIONS ) {
            $enable_acf_compat = isset( $_POST['wp_loc_enable_acf_compat'] ) ? 1 : 0;
            $enable_yoast_compat = isset( $_POST['wp_loc_enable_yoast_compat'] ) ? 1 : 0;
            $enable_yoast_sitemap_alternates = isset( $_POST['wp_loc_enable_yoast_sitemap_alternates'] ) ? 1 : 0;

            update_option( self::ENABLE_ACF_COMPAT_OPTION_KEY, $enable_acf_compat );
            update_option( self::ENABLE_YOAST_COMPAT_OPTION_KEY, $enable_yoast_compat );
            update_option( self::ENABLE_YOAST_SITEMAP_ALTERNATES_OPTION_KEY, $enable_yoast_sitemap_alternates );
        } elseif ( $current_tab === self::TAB_AI ) {
            $providers = WP_LOC_AI::get_connected_providers();
            $ai_engine = isset( $_POST['wp_loc_ai_engine'] ) ? sanitize_key( (string) $_POST['wp_loc_ai_engine'] ) : '';

            if ( isset( $providers[ $ai_engine ] ) ) {
                $models = WP_LOC_AI::get_provider_models( $ai_engine );
                $ai_model = isset( $_POST['wp_loc_ai_model'] ) ? sanitize_text_field( trim( (string) $_POST['wp_loc_ai_model'] ) ) : '';

                if ( ! isset( $models[ $ai_model ] ) ) {
                    $ai_model = $models !== [] ? (string) array_key_first( $models ) : '';
                }

                update_option( self::AI_ENGINE_OPTION_KEY, $ai_engine );

                if ( $ai_model !== '' ) {
                    $saved_models = get_option( self::AI_MODELS_OPTION_KEY, [] );
                    $saved_models = is_array( $saved_models ) ? $saved_models : [];
                    $saved_models[ $ai_engine ] = $ai_model;
                    update_option( self::AI_MODELS_OPTION_KEY, $saved_models );
                }
            }
        }

        /**
         * Fires after the built-in options of a settings tab are saved. Nonce and
         * capability are already verified; add-ons persist their own fields here.
         */
        do_action( "wp_loc_settings_save_{$current_tab}" );

        wp_redirect( add_query_arg( [
            'page'    => 'wp-loc-settings',
            'tab'     => $current_tab,
            'updated' => 1,
        ], admin_url( 'admin.php' ) ) );
        exit;
    }

    public function render_page(): void {
        $all_post_types = self::get_selectable_post_types();
        $public_post_types = array_filter(
            $all_post_types,
            static fn( $post_type ): bool => ! empty( $post_type->public )
        );
        $non_public_post_types = array_filter(
            $all_post_types,
            static fn( $post_type ): bool => empty( $post_type->public )
        );
        $all_taxonomies = self::get_selectable_taxonomies();
        $public_taxonomies = array_filter(
            $all_taxonomies,
            static fn( $taxonomy ): bool => ! empty( $taxonomy->public )
        );
        $non_public_taxonomies = array_filter(
            $all_taxonomies,
            static fn( $taxonomy ): bool => empty( $taxonomy->public )
        );

        $saved = get_option( self::OPTION_KEY );
        $detected_post_types = self::get_detected_translatable_post_types();
        $selected = ( $saved !== false && is_array( $saved ) )
            ? array_values( array_unique( array_merge( array_filter( array_map( 'sanitize_key', array_map( 'strval', $saved ) ) ), $detected_post_types ) ) )
            : array_values( array_unique( array_merge( [ 'post', 'page' ], $detected_post_types ) ) );
        $saved_taxonomies = get_option( self::TAXONOMIES_OPTION_KEY );
        $detected_taxonomies = self::get_detected_translatable_taxonomies();
        $selected_taxonomies = ( $saved_taxonomies !== false && is_array( $saved_taxonomies ) )
            ? array_values( array_unique( array_merge( array_filter( array_map( 'sanitize_key', array_map( 'strval', $saved_taxonomies ) ) ), $detected_taxonomies ) ) )
            : array_values( array_unique( array_merge( [ 'category', 'post_tag' ], $detected_taxonomies ) ) );
        $auto_create_posts = self::should_auto_create_post_translations();
        $auto_create_terms = self::should_auto_create_term_translations();
        $auto_create_menus = self::should_auto_create_menu_translations();
        $sync_post_taxonomies = self::should_sync_post_taxonomies();
        $sync_featured_image = self::should_sync_featured_image();
        $sync_post_attributes = self::should_sync_post_attributes();
        $show_flags = self::show_switcher_flags();
        $show_names = self::show_switcher_names();
        $hide_current_language = self::hide_current_language_in_switcher();
        $hide_untranslated_languages = self::hide_untranslated_languages_in_switcher();
        $fallback_untranslated_to_home = self::fallback_untranslated_switcher_links_to_home();
        $enable_acf_compat = self::is_acf_compat_enabled();
        $enable_yoast_compat = self::is_yoast_compat_enabled();
        $enable_yoast_sitemap_alternates = self::is_yoast_sitemap_alternates_enabled();
        $translate_custom_menu_links = self::should_ai_translate_custom_menu_links();
        $current_tab = $this->get_current_tab();
        $core_ai_available = WP_LOC_AI::is_core_ai_available();
        $ai_engines = [];
        $ai_models_by_provider = [];
        $ai_selected_models_by_provider = [];
        $ai_engine = '';
        $ai_model = '';
        $has_ai_models = false;

        if ( $current_tab === self::TAB_AI && $core_ai_available ) {
            $ai_engines = WP_LOC_AI::get_connected_providers();
            $saved_ai_models = get_option( self::AI_MODELS_OPTION_KEY, [] );
            $saved_ai_models = is_array( $saved_ai_models ) ? $saved_ai_models : [];

            foreach ( array_keys( $ai_engines ) as $provider_id ) {
                $ai_models_by_provider[ $provider_id ] = WP_LOC_AI::get_provider_models( $provider_id );
                $saved_model = isset( $saved_ai_models[ $provider_id ] )
                    ? sanitize_text_field( (string) $saved_ai_models[ $provider_id ] )
                    : '';
                $ai_selected_models_by_provider[ $provider_id ] = isset( $ai_models_by_provider[ $provider_id ][ $saved_model ] )
                    ? $saved_model
                    : (string) ( array_key_first( $ai_models_by_provider[ $provider_id ] ) ?? '' );

                if ( $ai_models_by_provider[ $provider_id ] !== [] ) {
                    $has_ai_models = true;
                }
            }

            $ai_engine = self::get_ai_engine();
            $ai_model = $ai_selected_models_by_provider[ $ai_engine ] ?? '';
        }

        ?>
        <div class="wrap wp-loc-settings-page">
            <h1><?php esc_html_e( 'Settings', 'wp-loc' ); ?></h1>
            <p class="description"><?php esc_html_e( 'Configure translation workflow, sync policies, frontend switcher behavior, integrations, and AI services.', 'wp-loc' ); ?></p>
            <?php $this->render_tabs( $current_tab ); ?>

            <?php if ( isset( $_GET['updated'] ) ) : ?>
                <div class="notice notice-success is-dismissible">
                    <p><?php esc_html_e( 'Settings saved.', 'wp-loc' ); ?></p>
                </div>
            <?php endif; ?>

            <form method="post">
                <?php wp_nonce_field( 'wp_loc_save_settings', 'wp_loc_settings_nonce' ); ?>
                <input type="hidden" name="wp_loc_settings_tab" value="<?php echo esc_attr( $current_tab ); ?>" />

                <?php if ( $current_tab === self::TAB_CONTENT ) : ?>
                    <div class="wp-loc-settings-section">
                        <table class="form-table" role="presentation">
                            <tr>
                                <th scope="row"><?php esc_html_e( 'Translation Workflow', 'wp-loc' ); ?></th>
                                <td>
                                    <fieldset class="wp-loc-settings-stack">
                                        <label class="wp-loc-settings-label">
                                            <input type="checkbox"
                                                   name="wp_loc_auto_create_post_translations"
                                                   value="1"
                                                   <?php checked( $auto_create_posts ); ?>
                                            />
                                            <span><?php esc_html_e( 'Automatically create sibling post and page translations when a new source entry is saved', 'wp-loc' ); ?></span>
                                        </label>
                                        <label class="wp-loc-settings-label">
                                            <input type="checkbox"
                                                   name="wp_loc_auto_create_term_translations"
                                                   value="1"
                                                   <?php checked( $auto_create_terms ); ?>
                                            />
                                            <span><?php esc_html_e( 'Automatically create sibling term translations when a new source term is created', 'wp-loc' ); ?></span>
                                        </label>
                                        <label class="wp-loc-settings-label">
                                            <input type="checkbox"
                                                   name="wp_loc_auto_create_menu_translations"
                                                   value="1"
                                                   <?php checked( $auto_create_menus ); ?>
                                            />
                                            <span><?php esc_html_e( 'Automatically create sibling nav menus for the other active languages', 'wp-loc' ); ?></span>
                                        </label>
                                    </fieldset>
                                    <p class="description"><?php esc_html_e( 'These defaults control whether new translation groups are created automatically or only when you create translations manually.', 'wp-loc' ); ?></p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><?php esc_html_e( 'Translatable Post Types', 'wp-loc' ); ?></th>
                                <td>
                                    <fieldset class="wp-loc-settings-stack">
                                        <?php if ( $public_post_types ) : ?>
                                            <strong><?php esc_html_e( 'Public post types', 'wp-loc' ); ?></strong>
                                            <?php $this->render_post_type_checkboxes( $public_post_types, $selected ); ?>
                                        <?php endif; ?>

                                        <?php if ( $non_public_post_types ) : ?>
                                            <strong><?php esc_html_e( 'Non-public post types', 'wp-loc' ); ?></strong>
                                            <?php $this->render_post_type_checkboxes( $non_public_post_types, $selected ); ?>
                                        <?php endif; ?>
                                    </fieldset>
                                    <p class="description"><?php esc_html_e( 'Select which post types should support multilingual translations.', 'wp-loc' ); ?></p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><?php esc_html_e( 'Translatable Taxonomies', 'wp-loc' ); ?></th>
                                <td>
                                    <fieldset class="wp-loc-settings-stack">
                                        <?php if ( $public_taxonomies ) : ?>
                                            <strong><?php esc_html_e( 'Public taxonomies', 'wp-loc' ); ?></strong>
                                            <?php $this->render_taxonomy_checkboxes( $public_taxonomies, $selected_taxonomies ); ?>
                                        <?php endif; ?>

                                        <?php if ( $non_public_taxonomies ) : ?>
                                            <strong><?php esc_html_e( 'Non-public taxonomies', 'wp-loc' ); ?></strong>
                                            <?php $this->render_taxonomy_checkboxes( $non_public_taxonomies, $selected_taxonomies ); ?>
                                        <?php endif; ?>
                                    </fieldset>
                                    <p class="description"><?php esc_html_e( 'Select which taxonomies should support multilingual translations.', 'wp-loc' ); ?></p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><?php esc_html_e( 'Content Sync', 'wp-loc' ); ?></th>
                                <td>
                                    <fieldset class="wp-loc-settings-stack">
                                        <label class="wp-loc-settings-label">
                                            <input type="checkbox"
                                                   name="wp_loc_sync_post_taxonomies"
                                                   value="1"
                                                   <?php checked( $sync_post_taxonomies ); ?>
                                            />
                                            <span><?php esc_html_e( 'Sync translated taxonomy assignments across post translations', 'wp-loc' ); ?></span>
                                        </label>
                                        <label class="wp-loc-settings-label">
                                            <input type="checkbox"
                                                   name="wp_loc_sync_featured_image"
                                                   value="1"
                                                   <?php checked( $sync_featured_image ); ?>
                                            />
                                            <span><?php esc_html_e( 'Sync featured images across post translations using translated attachment IDs', 'wp-loc' ); ?></span>
                                        </label>
                                        <label class="wp-loc-settings-label">
                                            <input type="checkbox"
                                                   name="wp_loc_sync_post_attributes"
                                                   value="1"
                                                   <?php checked( $sync_post_attributes ); ?>
                                            />
                                            <span><?php esc_html_e( 'Sync shared post attributes like status, author, parent, order, password, and page template', 'wp-loc' ); ?></span>
                                        </label>
                                    </fieldset>
                                    <p class="description"><?php esc_html_e( 'Use these toggles to control which shared properties stay aligned across a translation group.', 'wp-loc' ); ?></p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><?php esc_html_e( 'Menu Sync', 'wp-loc' ); ?></th>
                                <td>
                                    <fieldset class="wp-loc-settings-stack">
                                        <label class="wp-loc-settings-label">
                                            <input type="checkbox"
                                                   name="wp_loc_ai_translate_custom_menu_links"
                                                   value="1"
                                                   <?php checked( $translate_custom_menu_links ); ?>
                                            />
                                            <span><?php esc_html_e( 'Try to translate custom nav menu links with AI during menu sync', 'wp-loc' ); ?></span>
                                        </label>
                                    </fieldset>
                                    <p class="description"><?php esc_html_e( 'When enabled, WP Menus Sync will try to translate custom link titles and related text fields with the selected AI engine.', 'wp-loc' ); ?></p>
                                </td>
                            </tr>
                        </table>
                    </div>
                <?php elseif ( $current_tab === self::TAB_SWITCHER ) : ?>
                    <div class="wp-loc-settings-section">
                        <table class="form-table" role="presentation">
                            <tr>
                                <th scope="row"><?php esc_html_e( 'Flags', 'wp-loc' ); ?></th>
                                <td>
                                    <fieldset class="wp-loc-settings-stack">
                                        <label class="wp-loc-settings-label">
                                            <input type="checkbox"
                                                   name="wp_loc_show_switcher_flags"
                                                   value="1"
                                                   <?php checked( $show_flags ); ?>
                                            />
                                            <span><?php esc_html_e( 'Show flags in the frontend language switcher', 'wp-loc' ); ?></span>
                                        </label>
                                    </fieldset>
                                    <p class="description"><?php esc_html_e( 'Controls whether flags are rendered by the frontend language switcher helper.', 'wp-loc' ); ?></p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><?php esc_html_e( 'Labels', 'wp-loc' ); ?></th>
                                <td>
                                    <fieldset class="wp-loc-settings-stack">
                                        <label class="wp-loc-settings-label">
                                            <input type="checkbox"
                                                   name="wp_loc_show_switcher_names"
                                                   value="1"
                                                   <?php checked( $show_names ); ?>
                                            />
                                            <span><?php esc_html_e( 'Show language names in the frontend language switcher', 'wp-loc' ); ?></span>
                                        </label>
                                    </fieldset>
                                    <p class="description"><?php esc_html_e( 'Turn this off if you want a more compact switcher, for example flags only.', 'wp-loc' ); ?></p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><?php esc_html_e( 'Visibility Rules', 'wp-loc' ); ?></th>
                                <td>
                                    <fieldset class="wp-loc-settings-stack">
                                        <label class="wp-loc-settings-label">
                                            <input type="checkbox"
                                                   name="wp_loc_hide_current_language_switcher"
                                                   value="1"
                                                   <?php checked( $hide_current_language ); ?>
                                            />
                                            <span><?php esc_html_e( 'Hide the current language from the frontend language switcher', 'wp-loc' ); ?></span>
                                        </label>
                                        <label class="wp-loc-settings-label">
                                            <input type="checkbox"
                                                   name="wp_loc_hide_untranslated_languages_switcher"
                                                   value="1"
                                                   <?php checked( $hide_untranslated_languages ); ?>
                                            />
                                            <span><?php esc_html_e( 'Hide languages that do not have a translated target for the current singular or term archive', 'wp-loc' ); ?></span>
                                        </label>
                                        <label class="wp-loc-settings-label">
                                            <input type="checkbox"
                                                   name="wp_loc_fallback_untranslated_switcher_to_home"
                                                   value="1"
                                                   <?php checked( $fallback_untranslated_to_home ); ?>
                                            />
                                            <span><?php esc_html_e( 'When a translation is missing, fall back to the target language home URL instead of the current path', 'wp-loc' ); ?></span>
                                        </label>
                                    </fieldset>
                                    <p class="description"><?php esc_html_e( 'These options are especially helpful for projects that prefer a stricter switcher with fewer fallback links.', 'wp-loc' ); ?></p>
                                </td>
                            </tr>
                        </table>
                    </div>
                <?php elseif ( $current_tab === self::TAB_INTEGRATIONS ) : ?>
                    <div class="wp-loc-settings-section">
                        <table class="form-table" role="presentation">
                            <tr>
                                <th scope="row"><?php esc_html_e( 'Advanced Custom Fields', 'wp-loc' ); ?></th>
                                <td>
                                    <fieldset class="wp-loc-settings-stack">
                                        <label class="wp-loc-settings-label">
                                            <input type="checkbox"
                                                   name="wp_loc_enable_acf_compat"
                                                   value="1"
                                                   <?php checked( $enable_acf_compat ); ?>
                                            />
                                            <span><?php esc_html_e( 'Enable the ACF multilingual compatibility layer', 'wp-loc' ); ?></span>
                                        </label>
                                    </fieldset>
                                    <p class="description"><?php esc_html_e( 'Loads the ACF integration for multilingual field groups, options, media, relation fields, and container field behavior.', 'wp-loc' ); ?></p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><?php esc_html_e( 'Yoast SEO', 'wp-loc' ); ?></th>
                                <td>
                                    <fieldset class="wp-loc-settings-stack">
                                        <label class="wp-loc-settings-label">
                                            <input type="checkbox"
                                                   name="wp_loc_enable_yoast_compat"
                                                   value="1"
                                                   <?php checked( $enable_yoast_compat ); ?>
                                            />
                                            <span><?php esc_html_e( 'Enable Yoast SEO multilingual compatibility', 'wp-loc' ); ?></span>
                                        </label>
                                        <label class="wp-loc-settings-label">
                                            <input type="checkbox"
                                                   name="wp_loc_enable_yoast_sitemap_alternates"
                                                   value="1"
                                                   <?php checked( $enable_yoast_sitemap_alternates ); ?>
                                            />
                                            <span><?php esc_html_e( 'Add alternate-language links to Yoast XML sitemaps', 'wp-loc' ); ?></span>
                                        </label>
                                    </fieldset>
                                    <p class="description"><?php esc_html_e( 'Use the master toggle to disable the Yoast integration entirely, or keep it enabled and control sitemap alternates separately.', 'wp-loc' ); ?></p>
                                </td>
                            </tr>
                        </table>
                    </div>
                <?php elseif ( $current_tab === self::TAB_AI ) : ?>
                    <div class="wp-loc-settings-section">
                        <?php if ( ! $core_ai_available ) : ?>
                            <div class="notice notice-warning inline">
                                <p><?php esc_html_e( 'AI translation requires WordPress 7.0 or newer and the official Connectors API.', 'wp-loc' ); ?></p>
                            </div>
                        <?php elseif ( $ai_engines === [] ) : ?>
                            <div class="notice notice-info inline">
                                <p><?php esc_html_e( 'No connected AI provider was found. Connect and configure an AI provider in WordPress before enabling AI-assisted translation.', 'wp-loc' ); ?></p>
                                <p>
                                    <a class="button" href="<?php echo esc_url( admin_url( 'options-connectors.php' ) ); ?>">
                                        <?php esc_html_e( 'Manage WordPress Connectors', 'wp-loc' ); ?>
                                    </a>
                                </p>
                            </div>
                        <?php else : ?>
                            <table class="form-table" role="presentation">
                                <tr>
                                    <th scope="row"><label for="wp-loc-ai-engine"><?php esc_html_e( 'Translation Engine', 'wp-loc' ); ?></label></th>
                                    <td>
                                        <select id="wp-loc-ai-engine" name="wp_loc_ai_engine">
                                            <?php foreach ( $ai_engines as $engine_key => $engine_label ) : ?>
                                                <option value="<?php echo esc_attr( $engine_key ); ?>" <?php selected( $ai_engine, $engine_key ); ?>>
                                                    <?php echo esc_html( $engine_label ); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                        <p class="description">
                                            <?php esc_html_e( 'Only AI providers connected and configured through WordPress are available.', 'wp-loc' ); ?>
                                            <a href="<?php echo esc_url( admin_url( 'options-connectors.php' ) ); ?>"><?php esc_html_e( 'Manage connectors', 'wp-loc' ); ?></a>
                                        </p>
                                    </td>
                                </tr>
                                <tr>
                                    <th scope="row"><label for="wp-loc-ai-model"><?php esc_html_e( 'Model', 'wp-loc' ); ?></label></th>
                                    <td>
                                        <select
                                            id="wp-loc-ai-model"
                                            name="wp_loc_ai_model"
                                            class="wp-loc-ai-model-select"
                                            data-models="<?php echo esc_attr( wp_json_encode( $ai_models_by_provider ) ); ?>"
                                            data-selected-models="<?php echo esc_attr( wp_json_encode( $ai_selected_models_by_provider ) ); ?>"
                                            data-empty-label="<?php esc_attr_e( 'No text-generation models available', 'wp-loc' ); ?>"
                                            <?php disabled( empty( $ai_models_by_provider[ $ai_engine ] ) ); ?>
                                        >
                                            <?php foreach ( $ai_models_by_provider[ $ai_engine ] ?? [] as $model_key => $model_label ) : ?>
                                                <option value="<?php echo esc_attr( $model_key ); ?>" <?php selected( $ai_model, $model_key ); ?>>
                                                    <?php echo esc_html( $model_label ); ?>
                                                </option>
                                            <?php endforeach; ?>
                                            <?php if ( empty( $ai_models_by_provider[ $ai_engine ] ) ) : ?>
                                                <option value=""><?php esc_html_e( 'No text-generation models available', 'wp-loc' ); ?></option>
                                            <?php endif; ?>
                                        </select>
                                        <p class="description"><?php esc_html_e( 'Available text-generation models are loaded from the selected provider connector.', 'wp-loc' ); ?></p>
                                    </td>
                                </tr>
                            </table>
                        <?php endif; ?>
                    </div>
                <?php else : ?>
                    <?php do_action( "wp_loc_settings_render_{$current_tab}" ); ?>
                <?php endif; ?>

                <?php do_action( "wp_loc_settings_fields_{$current_tab}" ); ?>

                <?php if ( $current_tab !== self::TAB_AI || $has_ai_models || has_action( "wp_loc_settings_fields_{$current_tab}" ) ) : ?>
                    <?php submit_button( __( 'Save', 'wp-loc' ) ); ?>
                <?php endif; ?>
            </form>

            <?php if ( $current_tab === self::TAB_SWITCHER ) : ?>
                <?php $this->render_switcher_usage_examples(); ?>
            <?php endif; ?>
        </div>
        <?php
    }

}
