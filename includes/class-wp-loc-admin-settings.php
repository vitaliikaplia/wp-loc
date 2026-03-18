<?php

if ( ! defined( 'ABSPATH' ) ) exit;

class WP_LOC_Admin_Settings {

    const OPTION_KEY = 'wp_loc_translatable_post_types';
    const TAXONOMIES_OPTION_KEY = 'wp_loc_translatable_taxonomies';
    const SHOW_FLAGS_OPTION_KEY = 'wp_loc_show_switcher_flags';
    const AI_ENGINE_OPTION_KEY = 'wp_loc_ai_engine';
    const OPENAI_API_KEY_OPTION_KEY = 'wp_loc_openai_api_key';
    const OPENAI_MODEL_OPTION_KEY = 'wp_loc_openai_model';
    const CLAUDE_API_KEY_OPTION_KEY = 'wp_loc_claude_api_key';
    const CLAUDE_MODEL_OPTION_KEY = 'wp_loc_claude_model';
    const GEMINI_API_KEY_OPTION_KEY = 'wp_loc_gemini_api_key';
    const GEMINI_MODEL_OPTION_KEY = 'wp_loc_gemini_model';
    const AI_TRANSLATE_CUSTOM_MENU_LINKS_OPTION_KEY = 'wp_loc_ai_translate_custom_menu_links';
    const TAB_CONTENT = 'content';
    const TAB_SWITCHER = 'switcher';
    const TAB_AI = 'ai';

    public function __construct() {
        add_action( 'admin_menu', [ $this, 'add_menu' ], 20 );
        add_action( 'admin_init', [ $this, 'handle_save' ] );
        add_action( 'wp_ajax_wp_loc_test_ai_key', [ $this, 'ajax_test_ai_key' ] );
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
        $allowed = [ self::TAB_CONTENT, self::TAB_SWITCHER, self::TAB_AI ];

        return in_array( $tab, $allowed, true ) ? $tab : self::TAB_CONTENT;
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
        $tabs = [
            self::TAB_CONTENT  => __( 'Content Translation', 'wp-loc' ),
            self::TAB_SWITCHER => __( 'Frontend Language Switcher', 'wp-loc' ),
            self::TAB_AI       => __( 'AI', 'wp-loc' ),
        ];

        echo '<nav class="nav-tab-wrapper wp-clearfix">';

        foreach ( $tabs as $tab => $label ) {
            $class = $tab === $current_tab ? 'nav-tab nav-tab-active' : 'nav-tab';
            echo '<a href="' . esc_url( $this->get_tab_url( $tab ) ) . '" class="' . esc_attr( $class ) . '">' . esc_html( $label ) . '</a>';
        }

        echo '</nav>';
    }

    /**
     * Filter translatable post types based on saved settings
     */
    public function filter_post_types( array $post_types ): array {
        $saved = get_option( self::OPTION_KEY );

        if ( $saved !== false && is_array( $saved ) ) {
            return $saved;
        }

        return $post_types;
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

        if ( $saved !== false && is_array( $saved ) ) {
            return $saved;
        }

        return $taxonomies;
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

    public static function get_ai_engine(): string {
        $engine = (string) get_option( self::AI_ENGINE_OPTION_KEY, 'openai' );
        $allowed = [ 'openai', 'claude', 'gemini' ];

        return in_array( $engine, $allowed, true ) ? $engine : 'openai';
    }

    public static function get_openai_api_key(): string {
        return (string) get_option( self::OPENAI_API_KEY_OPTION_KEY, '' );
    }

    public static function get_openai_model(): string {
        $models = self::get_openai_models();
        $model = (string) get_option( self::OPENAI_MODEL_OPTION_KEY, 'gpt-5.4-mini' );

        return array_key_exists( $model, $models ) ? $model : 'gpt-5.4-mini';
    }

    public static function get_claude_api_key(): string {
        return (string) get_option( self::CLAUDE_API_KEY_OPTION_KEY, '' );
    }

    public static function get_claude_model(): string {
        $models = self::get_claude_models();
        $model = (string) get_option( self::CLAUDE_MODEL_OPTION_KEY, 'claude-sonnet-4-6' );

        return array_key_exists( $model, $models ) ? $model : 'claude-sonnet-4-6';
    }

    public static function get_gemini_api_key(): string {
        return (string) get_option( self::GEMINI_API_KEY_OPTION_KEY, '' );
    }

    public static function get_gemini_model(): string {
        $models = self::get_gemini_models();
        $model = (string) get_option( self::GEMINI_MODEL_OPTION_KEY, 'gemini-2.5-flash' );

        return array_key_exists( $model, $models ) ? $model : 'gemini-2.5-flash';
    }

    public static function get_openai_models(): array {
        return [
            'gpt-4o-mini' => __( 'GPT-4o mini', 'wp-loc' ),
            'gpt-4o'      => __( 'GPT-4o', 'wp-loc' ),
            'gpt-5.4-nano' => __( 'GPT-5.4 Nano, fastest and cheapest', 'wp-loc' ),
            'gpt-5.4-mini' => __( 'GPT-5.4 Mini, balanced default', 'wp-loc' ),
            'gpt-5.4'      => __( 'GPT-5.4, highest quality', 'wp-loc' ),
        ];
    }

    public static function get_claude_models(): array {
        return [
            'claude-haiku-4-5'   => __( 'Claude Haiku 4.5, fastest and cheapest', 'wp-loc' ),
            'claude-sonnet-4-5'  => __( 'Claude Sonnet 4.5, balanced', 'wp-loc' ),
            'claude-sonnet-4-6'  => __( 'Claude Sonnet 4.6, stronger quality', 'wp-loc' ),
            'claude-opus-4-6'    => __( 'Claude Opus 4.6, highest quality', 'wp-loc' ),
        ];
    }

    public static function get_gemini_models(): array {
        return [
            'gemini-2.5-flash-lite' => __( 'Gemini 2.5 Flash-Lite, fastest and cheapest', 'wp-loc' ),
            'gemini-2.5-flash'      => __( 'Gemini 2.5 Flash, balanced default', 'wp-loc' ),
            'gemini-3-flash-preview'=> __( 'Gemini 3 Flash Preview, stronger frontier option', 'wp-loc' ),
            'gemini-2.5-pro'        => __( 'Gemini 2.5 Pro, highest quality', 'wp-loc' ),
        ];
    }

    public static function should_ai_translate_custom_menu_links(): bool {
        return (bool) get_option( self::AI_TRANSLATE_CUSTOM_MENU_LINKS_OPTION_KEY, false );
    }

    public function handle_save(): void {
        if ( ! isset( $_POST['wp_loc_settings_nonce'] ) ) return;
        if ( ! wp_verify_nonce( $_POST['wp_loc_settings_nonce'], 'wp_loc_save_settings' ) ) return;
        if ( ! current_user_can( 'manage_options' ) ) return;

        $current_tab = isset( $_POST['wp_loc_settings_tab'] ) ? sanitize_key( (string) $_POST['wp_loc_settings_tab'] ) : self::TAB_CONTENT;

        if ( $current_tab === self::TAB_CONTENT ) {
            $selected = isset( $_POST['wp_loc_post_types'] ) ? array_map( 'sanitize_key', (array) $_POST['wp_loc_post_types'] ) : [];
            $selected_taxonomies = isset( $_POST['wp_loc_taxonomies'] ) ? array_map( 'sanitize_key', (array) $_POST['wp_loc_taxonomies'] ) : [];
            $translate_custom_menu_links = isset( $_POST['wp_loc_ai_translate_custom_menu_links'] ) ? 1 : 0;

            update_option( self::OPTION_KEY, $selected );
            update_option( self::TAXONOMIES_OPTION_KEY, $selected_taxonomies );
            update_option( self::AI_TRANSLATE_CUSTOM_MENU_LINKS_OPTION_KEY, $translate_custom_menu_links );
        } elseif ( $current_tab === self::TAB_SWITCHER ) {
            $show_flags = isset( $_POST['wp_loc_show_switcher_flags'] ) ? 1 : 0;
            update_option( self::SHOW_FLAGS_OPTION_KEY, $show_flags );
        } elseif ( $current_tab === self::TAB_AI ) {
            $ai_engine = isset( $_POST['wp_loc_ai_engine'] ) ? sanitize_key( (string) $_POST['wp_loc_ai_engine'] ) : 'openai';
            if ( ! in_array( $ai_engine, [ 'openai', 'claude', 'gemini' ], true ) ) {
                $ai_engine = 'openai';
            }

            $openai_api_key = isset( $_POST['wp_loc_openai_api_key'] ) ? sanitize_text_field( trim( (string) $_POST['wp_loc_openai_api_key'] ) ) : '';
            $openai_model = isset( $_POST['wp_loc_openai_model'] ) ? sanitize_text_field( trim( (string) $_POST['wp_loc_openai_model'] ) ) : 'gpt-5.4-mini';
            $claude_api_key = isset( $_POST['wp_loc_claude_api_key'] ) ? sanitize_text_field( trim( (string) $_POST['wp_loc_claude_api_key'] ) ) : '';
            $claude_model = isset( $_POST['wp_loc_claude_model'] ) ? sanitize_text_field( trim( (string) $_POST['wp_loc_claude_model'] ) ) : 'claude-sonnet-4-6';
            $gemini_api_key = isset( $_POST['wp_loc_gemini_api_key'] ) ? sanitize_text_field( trim( (string) $_POST['wp_loc_gemini_api_key'] ) ) : '';
            $gemini_model = isset( $_POST['wp_loc_gemini_model'] ) ? sanitize_text_field( trim( (string) $_POST['wp_loc_gemini_model'] ) ) : 'gemini-2.5-flash';

            if ( ! array_key_exists( $openai_model, self::get_openai_models() ) ) {
                $openai_model = 'gpt-5.4-mini';
            }

            if ( ! array_key_exists( $claude_model, self::get_claude_models() ) ) {
                $claude_model = 'claude-sonnet-4-6';
            }

            if ( ! array_key_exists( $gemini_model, self::get_gemini_models() ) ) {
                $gemini_model = 'gemini-2.5-flash';
            }

            update_option( self::AI_ENGINE_OPTION_KEY, $ai_engine );
            update_option( self::OPENAI_API_KEY_OPTION_KEY, $openai_api_key );
            update_option( self::OPENAI_MODEL_OPTION_KEY, $openai_model );
            update_option( self::CLAUDE_API_KEY_OPTION_KEY, $claude_api_key );
            update_option( self::CLAUDE_MODEL_OPTION_KEY, $claude_model );
            update_option( self::GEMINI_API_KEY_OPTION_KEY, $gemini_api_key );
            update_option( self::GEMINI_MODEL_OPTION_KEY, $gemini_model );
        }

        wp_redirect( add_query_arg( [
            'page'    => 'wp-loc-settings',
            'tab'     => $current_tab,
            'updated' => 1,
        ], admin_url( 'admin.php' ) ) );
        exit;
    }

    public function render_page(): void {
        $all_post_types = get_post_types( [ 'public' => true ], 'objects' );
        unset( $all_post_types['attachment'] );
        $all_taxonomies = get_taxonomies( [ 'public' => true ], 'objects' );
        unset( $all_taxonomies['post_format'], $all_taxonomies['nav_menu'] );

        $saved = get_option( self::OPTION_KEY );
        $selected = ( $saved !== false && is_array( $saved ) ) ? $saved : [ 'post', 'page' ];
        $saved_taxonomies = get_option( self::TAXONOMIES_OPTION_KEY );
        $selected_taxonomies = ( $saved_taxonomies !== false && is_array( $saved_taxonomies ) ) ? $saved_taxonomies : [ 'category', 'post_tag' ];
        $show_flags = self::show_switcher_flags();
        $ai_engine = self::get_ai_engine();
        $openai_api_key = self::get_openai_api_key();
        $openai_model = self::get_openai_model();
        $claude_api_key = self::get_claude_api_key();
        $claude_model = self::get_claude_model();
        $gemini_api_key = self::get_gemini_api_key();
        $gemini_model = self::get_gemini_model();
        $translate_custom_menu_links = self::should_ai_translate_custom_menu_links();
        $current_tab = $this->get_current_tab();
        $ai_engines = [
            'openai' => __( 'OpenAI', 'wp-loc' ),
            'claude' => __( 'Claude', 'wp-loc' ),
            'gemini' => __( 'Gemini', 'wp-loc' ),
        ];
        $openai_models = self::get_openai_models();
        $claude_models = self::get_claude_models();
        $gemini_models = self::get_gemini_models();

        ?>
        <div class="wrap wp-loc-settings-page">
            <h1><?php esc_html_e( 'Settings', 'wp-loc' ); ?></h1>
            <p class="description"><?php esc_html_e( 'Configure which content types participate in multilingual behavior and how the frontend language switcher is displayed.', 'wp-loc' ); ?></p>
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
                                <th scope="row"><?php esc_html_e( 'Translatable Post Types', 'wp-loc' ); ?></th>
                                <td>
                                    <fieldset class="wp-loc-settings-stack">
                                        <?php foreach ( $all_post_types as $pt ) : ?>
                                            <label class="wp-loc-settings-label">
                                                <input type="checkbox"
                                                       name="wp_loc_post_types[]"
                                                       value="<?php echo esc_attr( $pt->name ); ?>"
                                                       <?php checked( in_array( $pt->name, $selected, true ) ); ?>
                                                />
                                                <span><?php echo esc_html( $pt->labels->name ); ?></span>
                                                <code>(<?php echo esc_html( $pt->name ); ?>)</code>
                                            </label>
                                        <?php endforeach; ?>
                                    </fieldset>
                                    <p class="description"><?php esc_html_e( 'Select which post types should support multilingual translations.', 'wp-loc' ); ?></p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><?php esc_html_e( 'Translatable Taxonomies', 'wp-loc' ); ?></th>
                                <td>
                                    <fieldset class="wp-loc-settings-stack">
                                        <?php foreach ( $all_taxonomies as $taxonomy ) : ?>
                                            <label class="wp-loc-settings-label">
                                                <input type="checkbox"
                                                       name="wp_loc_taxonomies[]"
                                                       value="<?php echo esc_attr( $taxonomy->name ); ?>"
                                                       <?php checked( in_array( $taxonomy->name, $selected_taxonomies, true ) ); ?>
                                                />
                                                <span><?php echo esc_html( $taxonomy->labels->name ); ?></span>
                                                <code>(<?php echo esc_html( $taxonomy->name ); ?>)</code>
                                            </label>
                                        <?php endforeach; ?>
                                    </fieldset>
                                    <p class="description"><?php esc_html_e( 'Select which taxonomies should support multilingual translations.', 'wp-loc' ); ?></p>
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
                        </table>
                    </div>
                <?php else : ?>
                    <div class="wp-loc-settings-section">
                        <table class="form-table" role="presentation">
                            <tr>
                                <th scope="row"><?php esc_html_e( 'Translation Engine', 'wp-loc' ); ?></th>
                                <td>
                                    <select name="wp_loc_ai_engine">
                                        <?php foreach ( $ai_engines as $engine_key => $engine_label ) : ?>
                                            <option value="<?php echo esc_attr( $engine_key ); ?>" <?php selected( $ai_engine, $engine_key ); ?>>
                                                <?php echo esc_html( $engine_label ); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                    <p class="description"><?php esc_html_e( 'Select which AI engine should be used for automatic translation.', 'wp-loc' ); ?></p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><?php esc_html_e( 'OpenAI API Key', 'wp-loc' ); ?></th>
                                <td>
                                    <div class="wp-loc-ai-key-row">
                                        <input type="password" name="wp_loc_openai_api_key" value="<?php echo esc_attr( $openai_api_key ); ?>" class="regular-text" autocomplete="off" spellcheck="false" />
                                        <button type="button" class="button wp-loc-ai-key-test" data-provider="openai"><?php esc_html_e( 'Test', 'wp-loc' ); ?></button>
                                        <span class="wp-loc-ai-key-status" aria-live="polite"></span>
                                    </div>
                                    <div class="wp-loc-ai-model-row">
                                        <label class="screen-reader-text" for="wp-loc-openai-model"><?php esc_html_e( 'Model', 'wp-loc' ); ?></label>
                                        <select id="wp-loc-openai-model" name="wp_loc_openai_model" class="wp-loc-ai-model-select">
                                            <?php foreach ( $openai_models as $model_key => $model_label ) : ?>
                                                <option value="<?php echo esc_attr( $model_key ); ?>" <?php selected( $openai_model, $model_key ); ?>>
                                                    <?php echo esc_html( $model_label ); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><?php esc_html_e( 'Claude API Key', 'wp-loc' ); ?></th>
                                <td>
                                    <div class="wp-loc-ai-key-row">
                                        <input type="password" name="wp_loc_claude_api_key" value="<?php echo esc_attr( $claude_api_key ); ?>" class="regular-text" autocomplete="off" spellcheck="false" />
                                        <button type="button" class="button wp-loc-ai-key-test" data-provider="claude"><?php esc_html_e( 'Test', 'wp-loc' ); ?></button>
                                        <span class="wp-loc-ai-key-status" aria-live="polite"></span>
                                    </div>
                                    <div class="wp-loc-ai-model-row">
                                        <label class="screen-reader-text" for="wp-loc-claude-model"><?php esc_html_e( 'Model', 'wp-loc' ); ?></label>
                                        <select id="wp-loc-claude-model" name="wp_loc_claude_model" class="wp-loc-ai-model-select">
                                            <?php foreach ( $claude_models as $model_key => $model_label ) : ?>
                                                <option value="<?php echo esc_attr( $model_key ); ?>" <?php selected( $claude_model, $model_key ); ?>>
                                                    <?php echo esc_html( $model_label ); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><?php esc_html_e( 'Gemini API Key', 'wp-loc' ); ?></th>
                                <td>
                                    <div class="wp-loc-ai-key-row">
                                        <input type="password" name="wp_loc_gemini_api_key" value="<?php echo esc_attr( $gemini_api_key ); ?>" class="regular-text" autocomplete="off" spellcheck="false" />
                                        <button type="button" class="button wp-loc-ai-key-test" data-provider="gemini"><?php esc_html_e( 'Test', 'wp-loc' ); ?></button>
                                        <span class="wp-loc-ai-key-status" aria-live="polite"></span>
                                    </div>
                                    <div class="wp-loc-ai-model-row">
                                        <label class="screen-reader-text" for="wp-loc-gemini-model"><?php esc_html_e( 'Model', 'wp-loc' ); ?></label>
                                        <select id="wp-loc-gemini-model" name="wp_loc_gemini_model" class="wp-loc-ai-model-select">
                                            <?php foreach ( $gemini_models as $model_key => $model_label ) : ?>
                                                <option value="<?php echo esc_attr( $model_key ); ?>" <?php selected( $gemini_model, $model_key ); ?>>
                                                    <?php echo esc_html( $model_label ); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                </td>
                            </tr>
                        </table>
                    </div>
                <?php endif; ?>

                <?php submit_button( __( 'Save', 'wp-loc' ) ); ?>
            </form>
        </div>
        <?php
    }

    public function ajax_test_ai_key(): void {
        check_ajax_referer( 'wp_loc_ajax', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => __( 'You do not have permission to do that.', 'wp-loc' ) ], 403 );
        }

        $provider = isset( $_POST['provider'] ) ? sanitize_key( (string) $_POST['provider'] ) : '';
        $api_key = isset( $_POST['api_key'] ) ? sanitize_text_field( trim( (string) $_POST['api_key'] ) ) : '';
        $model = isset( $_POST['model'] ) ? sanitize_text_field( trim( (string) $_POST['model'] ) ) : '';

        if ( ! in_array( $provider, [ 'openai', 'claude', 'gemini' ], true ) ) {
            wp_send_json_error( [ 'message' => __( 'Unknown AI provider.', 'wp-loc' ) ], 400 );
        }

        $result = WP_LOC_AI::test_provider( $provider, $api_key, $model );

        if ( is_wp_error( $result ) ) {
            wp_send_json_error( [ 'message' => $result->get_error_message() ], 400 );
        }

        wp_send_json_success( $result );
    }
}
