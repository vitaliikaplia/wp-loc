<?php

if ( ! defined( 'ABSPATH' ) ) exit;

class WP_LOC_ACF {

    public function __construct() {
        add_action( 'acf/render_field_settings', [ $this, 'add_translation_mode_setting' ] );
        add_filter( 'acf/update_value', [ $this, 'handle_update_value' ], 10, 3 );
        add_filter( 'acf/load_field', [ $this, 'handle_load_field' ] );
        add_filter( 'acf/pre_load_value', [ $this, 'handle_pre_load_value' ], 10, 3 );
        add_action( 'acf/update_field_group', [ $this, 'save_translatable_fields_config' ] );
    }

    /**
     * Add "Translation Mode" setting to ACF field settings
     */
    public function add_translation_mode_setting( array $field ): void {
        if ( empty( $field['name'] ) ) return;

        acf_render_field_setting( $field, [
            'label'         => __( 'Translation Mode', 'wp-loc' ),
            'instructions'  => __( 'Is this field shared or translatable between languages?', 'wp-loc' ),
            'name'          => 'translation_mode',
            'type'          => 'radio',
            'choices'       => [
                'shared'       => __( 'Shared across all languages', 'wp-loc' ),
                'translatable' => __( 'Translatable', 'wp-loc' ),
            ],
            'layout'        => 'vertical',
            'default_value' => 'shared',
            'ui'            => 0,
        ], true );
    }

    /**
     * Handle saving ACF field values — route to language-specific option for translatable fields
     */
    public function handle_update_value( $value, $post_id, array $field ) {
        if ( ! is_array( $field ) || ! isset( $field['name'] ) ) return $value;
        if ( strpos( (string) $post_id, 'options' ) !== 0 ) return $value;

        $current_locale = WP_LOC_Admin::get_admin_locale();
        if ( ! $current_locale ) {
            $langs = WP_LOC_Languages::get_active_languages();
            $default = WP_LOC_Languages::get_default_language();
            $current_locale = $langs[ $default ]['locale'] ?? 'en_US';
        }

        $translatable_fields = get_option( 'wp_loc_acf_translatable_fields', [] );
        $is_translatable = isset( $translatable_fields[ $field['name'] ] );

        // Translatable field: save to language-specific option
        if ( $is_translatable ) {
            $option_name = "_options_{$current_locale}_{$field['name']}";
            update_option( $option_name, $value );
            return null; // Prevent default ACF save
        }

        // Shared field: only save for default language
        $admin_lang = wp_loc_get_admin_lang();
        $default_lang = WP_LOC_Languages::get_default_language();

        if ( $admin_lang !== $default_lang ) {
            return null; // Don't save shared fields for non-default language
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

        $translatable_fields = get_option( 'wp_loc_acf_translatable_fields', [] );
        $is_translatable = isset( $translatable_fields[ $field['name'] ] );

        // Shared field + non-default language → readonly
        if ( ! $is_translatable ) {
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
        if ( strpos( (string) $post_id, 'options' ) !== 0 || ! isset( $field['name'] ) ) {
            return $null;
        }

        $translatable_fields = get_option( 'wp_loc_acf_translatable_fields', [] );

        if ( ! isset( $translatable_fields[ $field['name'] ] ) ) {
            return $null;
        }

        // Get current locale based on context
        $current_locale = is_admin()
            ? WP_LOC_Admin::get_admin_locale()
            : wp_loc_get_current_locale();

        $option_name = "_options_{$current_locale}_{$field['name']}";
        $value = get_option( $option_name, false );

        if ( $value !== false ) {
            return $value;
        }

        return $null;
    }

    /**
     * Save translatable fields configuration when ACF field group is updated
     */
    public function save_translatable_fields_config( array $field_group ): void {
        $translatable_fields = get_option( 'wp_loc_acf_translatable_fields', [] );

        $fields = acf_get_fields( $field_group['key'] );
        if ( ! is_array( $fields ) ) return;

        foreach ( $fields as $field ) {
            if ( isset( $field['translation_mode'] ) && $field['translation_mode'] === 'translatable' ) {
                $translatable_fields[ $field['name'] ] = $field['key'];
            } else {
                unset( $translatable_fields[ $field['name'] ] );
            }
        }

        update_option( 'wp_loc_acf_translatable_fields', $translatable_fields );
    }
}
