<?php

if ( ! defined( 'ABSPATH' ) ) exit;

class WP_LOC_ACF {

    private function get_options_base_post_ids(): array {
        if ( ! function_exists( 'acf_get_options_pages' ) ) {
            return [ 'options' ];
        }

        $post_ids = [];
        $options_pages = acf_get_options_pages();

        if ( is_array( $options_pages ) ) {
            foreach ( $options_pages as $options_page ) {
                if ( ! empty( $options_page['post_id'] ) && is_string( $options_page['post_id'] ) ) {
                    $post_ids[] = $options_page['post_id'];
                }
            }
        }

        $post_ids[] = 'options';

        return array_values( array_unique( $post_ids ) );
    }

    private function get_translated_options_post_id( string $language, string $base_post_id = 'options' ): string {
        return "{$base_post_id}_{$language}";
    }

    private function get_context_locale(): string {
        if ( is_admin() ) {
            $locale = WP_LOC_Admin::get_admin_locale();
            if ( $locale ) {
                return $locale;
            }
        } else {
            $locale = wp_loc_get_current_locale();
            if ( $locale ) {
                return $locale;
            }
        }

        $default_lang = WP_LOC_Languages::get_default_language();
        $active = WP_LOC_Languages::get_active_languages();

        return $active[ $default_lang ]['locale'] ?? 'en_US';
    }

    private function is_options_post_id( $post_id ): bool {
        if ( ! is_string( $post_id ) ) {
            return false;
        }

        if ( $this->is_valid_acf_options_post_id( $post_id ) ) {
            return true;
        }

        return $this->get_base_options_post_id( $post_id ) !== null;
    }

    private function is_translated_options_post_id( $post_id ): bool {
        if ( ! is_string( $post_id ) ) {
            return false;
        }

        $base_post_id = $this->get_base_options_post_id( $post_id );

        return $base_post_id !== null && $post_id !== $base_post_id;
    }

    private function get_base_options_post_id( $post_id ): ?string {
        if ( ! is_string( $post_id ) ) {
            return null;
        }

        foreach ( $this->get_options_base_post_ids() as $base_post_id ) {
            if ( $post_id === $base_post_id ) {
                return $base_post_id;
            }

            if ( str_starts_with( $post_id, $base_post_id . '_' ) ) {
                return $base_post_id;
            }
        }

        return null;
    }

    private function get_options_post_id_language( $post_id ): ?string {
        if ( ! $this->is_translated_options_post_id( $post_id ) ) {
            return null;
        }

        $base_post_id = $this->get_base_options_post_id( $post_id );

        if ( ! $base_post_id ) {
            return null;
        }

        return substr( (string) $post_id, strlen( $base_post_id ) + 1 );
    }

    private function is_valid_acf_options_post_id( $post_id ): bool {
        if ( ! is_string( $post_id ) || ! function_exists( 'acf_get_options_pages' ) ) {
            return false;
        }

        return in_array( $post_id, $this->get_options_base_post_ids(), true );
    }

    private function get_language_locale( string $language ): ?string {
        $active = WP_LOC_Languages::get_active_languages();

        return $active[ $language ]['locale'] ?? null;
    }

    private function get_field_reference_key( string $field_name, string $base_post_id = 'options' ): ?string {
        $translatable_fields = get_option( 'wp_loc_acf_translatable_fields', [] );

        if ( ! empty( $translatable_fields[ $field_name ] ) ) {
            return (string) $translatable_fields[ $field_name ];
        }

        return get_option( "_{$base_post_id}_{$field_name}", null );
    }

    private function get_translatable_option_value( string $language, string $field_name, string $base_post_id = 'options' ) {
        $translated_post_id = $this->get_translated_options_post_id( $language, $base_post_id );
        $value = get_option( "{$translated_post_id}_{$field_name}", false );

        if ( $value !== false ) {
            return $value;
        }

        $locale = $this->get_language_locale( $language ) ?: $this->get_context_locale();

        if ( $locale ) {
            $legacy_value = get_option( "{$base_post_id}_{$locale}_{$field_name}", false );

            if ( $legacy_value !== false ) {
                update_option( "{$translated_post_id}_{$field_name}", $legacy_value );
                return $legacy_value;
            }

            $legacy_value = get_option( "_{$base_post_id}_{$locale}_{$field_name}", false );
            if ( $legacy_value !== false ) {
                return $legacy_value;
            }
        }

        return false;
    }

    private function build_options_meta( string $post_id ): array {
        if ( ! function_exists( 'acf_get_option_meta' ) ) {
            return [];
        }

        $all_meta = acf_get_option_meta( $post_id );
        $meta = [];

        foreach ( $all_meta as $key => $value ) {
            if ( isset( $all_meta[ "_{$key}" ] ) ) {
                $meta[ $key ] = $value[0];
                $meta[ "_{$key}" ] = $all_meta[ "_{$key}" ][0];
            }
        }

        return $meta;
    }

    public function __construct() {
        add_action( 'acf/render_field_settings', [ $this, 'add_translation_mode_setting' ] );
        add_filter( 'acf/validate_post_id', [ $this, 'handle_validate_post_id' ], 10, 2 );
        add_filter( 'acf/update_value', [ $this, 'handle_update_value' ], 10, 3 );
        add_filter( 'acf/update_value/type=nav_menu', [ $this, 'normalize_nav_menu_field_value_on_save' ], 20, 3 );
        add_filter( 'acf/load_field', [ $this, 'handle_load_field' ] );
        add_filter( 'acf/pre_load_meta', [ $this, 'handle_pre_load_meta' ], 10, 2 );
        add_filter( 'acf/pre_load_value', [ $this, 'handle_pre_load_value' ], 10, 3 );
        add_filter( 'acf/load_value/type=nav_menu', [ $this, 'translate_nav_menu_field_value' ], 20, 3 );
        add_action( 'acf/update_field_group', [ $this, 'save_translatable_fields_config' ] );
    }

    private function get_context_language(): string {
        return is_admin() ? wp_loc_get_admin_lang() : wp_loc_get_current_lang();
    }

    private function translate_nav_menu_id_for_context( int $menu_id, ?string $target_lang = null ): int {
        if ( ! $menu_id || ! is_nav_menu( $menu_id ) ) {
            return $menu_id;
        }

        $target_lang = $target_lang ?: $this->get_context_language();
        $translated_menu_id = WP_LOC_Terms::get_term_translation( $menu_id, 'nav_menu', $target_lang );

        return $translated_menu_id ?: $menu_id;
    }

    private function normalize_nav_menu_id_to_default( int $menu_id ): int {
        if ( ! $menu_id || ! is_nav_menu( $menu_id ) ) {
            return $menu_id;
        }

        $default_lang = WP_LOC_Languages::get_default_language();
        $default_menu_id = WP_LOC_Terms::get_term_translation( $menu_id, 'nav_menu', $default_lang );

        return $default_menu_id ?: $menu_id;
    }

    /**
     * Add "Translation Mode" setting to ACF field settings
     */
    public function add_translation_mode_setting( array $field ): void {
        if ( empty( $field['name'] ) ) return;

        acf_render_field_setting( $field, [
            'label'         => __( 'Translation Mode', 'wp-loc' ),
            'instructions'  => __( 'Should multilingual logic be disabled, shared, or translated for this field?', 'wp-loc' ),
            'name'          => 'translation_mode',
            'type'          => 'radio',
            'choices'       => [
                'none'         => __( 'Do not apply multilingual logic', 'wp-loc' ),
                'shared'       => __( 'Shared across all languages', 'wp-loc' ),
                'translatable' => __( 'Translatable', 'wp-loc' ),
            ],
            'layout'        => 'vertical',
            'default_value' => 'none',
            'ui'            => 0,
        ], true );
    }

    /**
     * Handle saving ACF field values — route to language-specific option for translatable fields
     */
    public function handle_update_value( $value, $post_id, array $field ) {
        if ( ! is_array( $field ) || ! isset( $field['name'] ) ) return $value;
        if ( ! $this->is_options_post_id( $post_id ) ) return $value;

        $translation_mode = $this->get_translation_mode( $field );

        if ( $translation_mode === 'none' ) {
            return $value;
        }

        if ( $translation_mode === 'translatable' ) {
            return $value;
        }

        if ( $translation_mode === 'shared' && $this->is_translated_options_post_id( $post_id ) ) {
            $base_post_id = $this->get_base_options_post_id( $post_id );

            if ( $base_post_id && ! empty( $field['name'] ) ) {
                update_option( "{$base_post_id}_{$field['name']}", $value );
            }

            if ( $base_post_id && ! empty( $field['key'] ) ) {
                update_option( "_{$base_post_id}_{$field['name']}", $field['key'] );
            }

            return null;
        }

        return $value;
    }

    /**
     * Make shared fields readonly for non-default languages
     */
    public function handle_load_field( array $field ): array {
        if ( ! is_admin() || ! isset( $field['name'] ) ) return $field;

        $admin_lang = wp_loc_get_admin_lang();
        $default_lang = WP_LOC_Languages::get_default_language();

        if ( $admin_lang === $default_lang ) return $field;
        $translation_mode = $this->get_translation_mode( $field );

        // Shared field + non-default language → readonly
        if ( $translation_mode === 'shared' ) {
            $field['readonly'] = 1;
            $field['disabled'] = 1;
            $field['wrapper']['class'] = ( $field['wrapper']['class'] ?? '' ) . ' acf-disabled';
        }

        return $field;
    }

    /**
     * Load language-specific value for translatable ACF options fields
     */
    public function handle_pre_load_value( $null, $post_id, array $field ) {
        if ( ! $this->is_options_post_id( $post_id ) || ! isset( $field['name'] ) ) {
            return $null;
        }

        $translation_mode = $this->get_translation_mode( $field );

        if ( $translation_mode === 'shared' && $this->is_translated_options_post_id( $post_id ) ) {
            $base_post_id = $this->get_base_options_post_id( $post_id );

            if ( $base_post_id ) {
                return get_option( "{$base_post_id}_{$field['name']}", null );
            }
        }

        if ( $translation_mode !== 'translatable' ) {
            return $null;
        }

        $language = $this->get_options_post_id_language( $post_id );
        $base_post_id = $this->get_base_options_post_id( $post_id );

        if ( ! $language || ! $base_post_id ) {
            return $null;
        }

        $value = $this->get_translatable_option_value( $language, $field['name'], $base_post_id );

        if ( $value !== false ) {
            return $value;
        }

        return $null;
    }

    /**
     * Inject localized ACF options meta so get_fields('options') sees translatable fields.
     */
    public function handle_pre_load_meta( $null, $post_id ) {
        if ( ! $this->is_options_post_id( $post_id ) || ! function_exists( 'acf_decode_post_id' ) || ! function_exists( 'acf_get_option_meta' ) ) {
            return $null;
        }

        $decoded = acf_decode_post_id( $post_id );
        if ( empty( $decoded['type'] ) || $decoded['type'] !== 'option' || empty( $decoded['id'] ) ) {
            return $null;
        }

        if ( ! $this->is_translated_options_post_id( $decoded['id'] ) ) {
            return $null;
        }

        $language = $this->get_options_post_id_language( $decoded['id'] );
        $base_post_id = $this->get_base_options_post_id( $decoded['id'] );

        if ( ! $language || ! $base_post_id ) {
            return $null;
        }

        $base_meta = $this->build_options_meta( $base_post_id );
        $translated_meta = $this->build_options_meta( $decoded['id'] );
        $meta = $base_meta;
        $field_modes = get_option( 'wp_loc_acf_field_translation_modes', [] );

        foreach ( $field_modes as $field_name => $mode ) {
            if ( $mode !== 'translatable' ) {
                continue;
            }

            if ( array_key_exists( $field_name, $translated_meta ) ) {
                $meta[ $field_name ] = $translated_meta[ $field_name ];
            } else {
                $localized_value = $this->get_translatable_option_value( $language, $field_name, $base_post_id );

                if ( $localized_value !== false ) {
                    $meta[ $field_name ] = $localized_value;
                } else {
                    unset( $meta[ $field_name ] );
                }
            }

            if ( ! empty( $translated_meta[ "_{$field_name}" ] ) ) {
                $meta[ "_{$field_name}" ] = $translated_meta[ "_{$field_name}" ];
            } else {
                $reference = $this->get_field_reference_key( $field_name, $base_post_id );
                if ( $reference ) {
                    $meta[ "_{$field_name}" ] = $reference;
                }
            }
        }

        return $meta;
    }

    /**
     * Route ACF options pages through a language-aware post_id like options_en/options_ru.
     */
    public function handle_validate_post_id( $post_id, $_post_id ) {
        if ( ! is_string( $post_id ) || ! $this->is_valid_acf_options_post_id( $post_id ) ) {
            return $post_id;
        }

        $current_language = $this->get_context_language();
        $default_language = WP_LOC_Languages::get_default_language();

        if ( ! $current_language || $current_language === $default_language ) {
            return $post_id;
        }

        if ( $this->is_translated_options_post_id( $post_id ) ) {
            return $post_id;
        }

        return $this->get_translated_options_post_id( $current_language, $post_id );
    }

    /**
     * Map ACF nav_menu field values to the menu translation for the current language context.
     */
    public function translate_nav_menu_field_value( $value, $post_id, array $field ) {
        if ( empty( $value ) ) {
            return $value;
        }

        if ( is_numeric( $value ) ) {
            return $this->translate_nav_menu_id_for_context( (int) $value );
        }

        if ( is_string( $value ) && ctype_digit( $value ) ) {
            return (string) $this->translate_nav_menu_id_for_context( (int) $value );
        }

        return $value;
    }

    /**
     * Persist canonical default-language menu IDs for nav_menu ACF fields unless the field is explicitly translatable.
     */
    public function normalize_nav_menu_field_value_on_save( $value, $post_id, array $field ) {
        if ( empty( $value ) ) {
            return $value;
        }

        if ( $this->get_translation_mode( $field ) === 'translatable' ) {
            return $value;
        }

        if ( is_numeric( $value ) ) {
            return $this->normalize_nav_menu_id_to_default( (int) $value );
        }

        if ( is_string( $value ) && ctype_digit( $value ) ) {
            return (string) $this->normalize_nav_menu_id_to_default( (int) $value );
        }

        return $value;
    }

    /**
     * Save translatable fields configuration when ACF field group is updated
     */
    public function save_translatable_fields_config( array $field_group ): void {
        $translatable_fields = get_option( 'wp_loc_acf_translatable_fields', [] );
        $field_modes = get_option( 'wp_loc_acf_field_translation_modes', [] );

        $fields = acf_get_fields( $field_group['key'] );
        if ( ! is_array( $fields ) ) return;

        foreach ( $fields as $field ) {
            if ( empty( $field['name'] ) ) {
                continue;
            }

            $mode = $field['translation_mode'] ?? 'none';
            $field_modes[ $field['name'] ] = $mode;

            if ( $mode === 'translatable' ) {
                $translatable_fields[ $field['name'] ] = $field['key'];
            } else {
                unset( $translatable_fields[ $field['name'] ] );
            }
        }

        update_option( 'wp_loc_acf_translatable_fields', $translatable_fields );
        update_option( 'wp_loc_acf_field_translation_modes', $field_modes );
    }

    /**
     * Resolve the configured translation mode for a field.
     */
    private function get_translation_mode( array $field ): string {
        if ( isset( $field['translation_mode'] ) && in_array( $field['translation_mode'], [ 'none', 'shared', 'translatable' ], true ) ) {
            return $field['translation_mode'];
        }

        if ( empty( $field['name'] ) ) {
            return 'none';
        }

        $field_modes = get_option( 'wp_loc_acf_field_translation_modes', [] );

        if ( isset( $field_modes[ $field['name'] ] ) && in_array( $field_modes[ $field['name'] ], [ 'none', 'shared', 'translatable' ], true ) ) {
            return $field_modes[ $field['name'] ];
        }

        $translatable_fields = get_option( 'wp_loc_acf_translatable_fields', [] );

        if ( isset( $translatable_fields[ $field['name'] ] ) ) {
            return 'translatable';
        }

        return 'none';
    }
}
