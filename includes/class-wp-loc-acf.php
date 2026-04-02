<?php

if ( ! defined( 'ABSPATH' ) ) exit;

class WP_LOC_ACF {

    private const WPML_IGNORE_CUSTOM_FIELD = 0;
    private const WPML_COPY_CUSTOM_FIELD = 1;
    private const WPML_TRANSLATE_CUSTOM_FIELD = 2;
    private const WPML_COPY_ONCE_CUSTOM_FIELD = 3;

    private const ACFML_FIELD_GROUP_MODE_KEY = 'acfml_field_group_mode';

    private const ACFML_GROUP_MODE_TRANSLATION = 'translation';
    private const ACFML_GROUP_MODE_LOCALIZATION = 'localization';
    private const ACFML_GROUP_MODE_ADVANCED = 'advanced';

    private const FIELD_GROUP_MODE_COLUMN_KEY = 'wp_loc_acf_translation_option';
    private const FIELD_GROUP_MODES_OPTION = 'wp_loc_acf_field_group_modes';
    private const FIELD_TRANSLATION_MODES_BY_KEY_OPTION = 'wp_loc_acf_field_translation_modes_by_key';

    private const ACFML_MODE_DEFAULTS = [
        'text' => [
            self::ACFML_GROUP_MODE_TRANSLATION => self::WPML_TRANSLATE_CUSTOM_FIELD,
            self::ACFML_GROUP_MODE_LOCALIZATION => self::WPML_TRANSLATE_CUSTOM_FIELD,
        ],
        'textarea' => [
            self::ACFML_GROUP_MODE_TRANSLATION => self::WPML_TRANSLATE_CUSTOM_FIELD,
            self::ACFML_GROUP_MODE_LOCALIZATION => self::WPML_TRANSLATE_CUSTOM_FIELD,
        ],
        'number' => [
            self::ACFML_GROUP_MODE_TRANSLATION => self::WPML_COPY_CUSTOM_FIELD,
            self::ACFML_GROUP_MODE_LOCALIZATION => self::WPML_COPY_ONCE_CUSTOM_FIELD,
        ],
        'range' => [
            self::ACFML_GROUP_MODE_TRANSLATION => self::WPML_COPY_CUSTOM_FIELD,
            self::ACFML_GROUP_MODE_LOCALIZATION => self::WPML_COPY_ONCE_CUSTOM_FIELD,
        ],
        'email' => [
            self::ACFML_GROUP_MODE_TRANSLATION => self::WPML_COPY_CUSTOM_FIELD,
            self::ACFML_GROUP_MODE_LOCALIZATION => self::WPML_COPY_ONCE_CUSTOM_FIELD,
        ],
        'url' => [
            self::ACFML_GROUP_MODE_TRANSLATION => self::WPML_COPY_CUSTOM_FIELD,
            self::ACFML_GROUP_MODE_LOCALIZATION => self::WPML_COPY_ONCE_CUSTOM_FIELD,
        ],
        'password' => [
            self::ACFML_GROUP_MODE_TRANSLATION => self::WPML_COPY_CUSTOM_FIELD,
            self::ACFML_GROUP_MODE_LOCALIZATION => self::WPML_COPY_ONCE_CUSTOM_FIELD,
        ],
        'image' => [
            self::ACFML_GROUP_MODE_TRANSLATION => self::WPML_COPY_CUSTOM_FIELD,
            self::ACFML_GROUP_MODE_LOCALIZATION => self::WPML_COPY_ONCE_CUSTOM_FIELD,
        ],
        'file' => [
            self::ACFML_GROUP_MODE_TRANSLATION => self::WPML_COPY_CUSTOM_FIELD,
            self::ACFML_GROUP_MODE_LOCALIZATION => self::WPML_COPY_ONCE_CUSTOM_FIELD,
        ],
        'wysiwyg' => [
            self::ACFML_GROUP_MODE_TRANSLATION => self::WPML_TRANSLATE_CUSTOM_FIELD,
            self::ACFML_GROUP_MODE_LOCALIZATION => self::WPML_TRANSLATE_CUSTOM_FIELD,
        ],
        'oembed' => [
            self::ACFML_GROUP_MODE_TRANSLATION => self::WPML_COPY_CUSTOM_FIELD,
            self::ACFML_GROUP_MODE_LOCALIZATION => self::WPML_COPY_ONCE_CUSTOM_FIELD,
        ],
        'gallery' => [
            self::ACFML_GROUP_MODE_TRANSLATION => self::WPML_COPY_CUSTOM_FIELD,
            self::ACFML_GROUP_MODE_LOCALIZATION => self::WPML_COPY_ONCE_CUSTOM_FIELD,
        ],
        'select' => [
            self::ACFML_GROUP_MODE_TRANSLATION => self::WPML_COPY_CUSTOM_FIELD,
            self::ACFML_GROUP_MODE_LOCALIZATION => self::WPML_COPY_ONCE_CUSTOM_FIELD,
        ],
        'checkbox' => [
            self::ACFML_GROUP_MODE_TRANSLATION => self::WPML_COPY_CUSTOM_FIELD,
            self::ACFML_GROUP_MODE_LOCALIZATION => self::WPML_COPY_ONCE_CUSTOM_FIELD,
        ],
        'radio' => [
            self::ACFML_GROUP_MODE_TRANSLATION => self::WPML_COPY_CUSTOM_FIELD,
            self::ACFML_GROUP_MODE_LOCALIZATION => self::WPML_COPY_ONCE_CUSTOM_FIELD,
        ],
        'button_group' => [
            self::ACFML_GROUP_MODE_TRANSLATION => self::WPML_COPY_CUSTOM_FIELD,
            self::ACFML_GROUP_MODE_LOCALIZATION => self::WPML_COPY_ONCE_CUSTOM_FIELD,
        ],
        'true_false' => [
            self::ACFML_GROUP_MODE_TRANSLATION => self::WPML_COPY_CUSTOM_FIELD,
            self::ACFML_GROUP_MODE_LOCALIZATION => self::WPML_COPY_ONCE_CUSTOM_FIELD,
        ],
        'google_map' => [
            self::ACFML_GROUP_MODE_TRANSLATION => self::WPML_COPY_CUSTOM_FIELD,
            self::ACFML_GROUP_MODE_LOCALIZATION => self::WPML_COPY_ONCE_CUSTOM_FIELD,
        ],
        'date_picker' => [
            self::ACFML_GROUP_MODE_TRANSLATION => self::WPML_COPY_CUSTOM_FIELD,
            self::ACFML_GROUP_MODE_LOCALIZATION => self::WPML_COPY_ONCE_CUSTOM_FIELD,
        ],
        'date_time_picker' => [
            self::ACFML_GROUP_MODE_TRANSLATION => self::WPML_COPY_CUSTOM_FIELD,
            self::ACFML_GROUP_MODE_LOCALIZATION => self::WPML_COPY_ONCE_CUSTOM_FIELD,
        ],
        'time_picker' => [
            self::ACFML_GROUP_MODE_TRANSLATION => self::WPML_COPY_CUSTOM_FIELD,
            self::ACFML_GROUP_MODE_LOCALIZATION => self::WPML_COPY_ONCE_CUSTOM_FIELD,
        ],
        'color_picker' => [
            self::ACFML_GROUP_MODE_TRANSLATION => self::WPML_COPY_CUSTOM_FIELD,
            self::ACFML_GROUP_MODE_LOCALIZATION => self::WPML_COPY_ONCE_CUSTOM_FIELD,
        ],
        'icon_picker' => [
            self::ACFML_GROUP_MODE_TRANSLATION => self::WPML_COPY_CUSTOM_FIELD,
            self::ACFML_GROUP_MODE_LOCALIZATION => self::WPML_COPY_ONCE_CUSTOM_FIELD,
        ],
        'message' => [
            self::ACFML_GROUP_MODE_TRANSLATION => self::WPML_TRANSLATE_CUSTOM_FIELD,
            self::ACFML_GROUP_MODE_LOCALIZATION => self::WPML_TRANSLATE_CUSTOM_FIELD,
        ],
        'accordion' => [
            self::ACFML_GROUP_MODE_TRANSLATION => self::WPML_COPY_CUSTOM_FIELD,
            self::ACFML_GROUP_MODE_LOCALIZATION => self::WPML_COPY_ONCE_CUSTOM_FIELD,
        ],
        'tab' => [
            self::ACFML_GROUP_MODE_TRANSLATION => self::WPML_COPY_CUSTOM_FIELD,
            self::ACFML_GROUP_MODE_LOCALIZATION => self::WPML_COPY_ONCE_CUSTOM_FIELD,
        ],
        'group' => [
            self::ACFML_GROUP_MODE_TRANSLATION => self::WPML_COPY_CUSTOM_FIELD,
            self::ACFML_GROUP_MODE_LOCALIZATION => self::WPML_COPY_ONCE_CUSTOM_FIELD,
        ],
        'repeater' => [
            self::ACFML_GROUP_MODE_TRANSLATION => self::WPML_COPY_CUSTOM_FIELD,
            self::ACFML_GROUP_MODE_LOCALIZATION => self::WPML_COPY_ONCE_CUSTOM_FIELD,
        ],
        'flexible_content' => [
            self::ACFML_GROUP_MODE_TRANSLATION => self::WPML_COPY_CUSTOM_FIELD,
            self::ACFML_GROUP_MODE_LOCALIZATION => self::WPML_COPY_ONCE_CUSTOM_FIELD,
        ],
        'clone' => [
            self::ACFML_GROUP_MODE_TRANSLATION => self::WPML_COPY_CUSTOM_FIELD,
            self::ACFML_GROUP_MODE_LOCALIZATION => self::WPML_COPY_ONCE_CUSTOM_FIELD,
        ],
        'link' => [
            self::ACFML_GROUP_MODE_TRANSLATION => self::WPML_COPY_CUSTOM_FIELD,
            self::ACFML_GROUP_MODE_LOCALIZATION => self::WPML_COPY_ONCE_CUSTOM_FIELD,
        ],
        'post_object' => [
            self::ACFML_GROUP_MODE_TRANSLATION => self::WPML_COPY_CUSTOM_FIELD,
            self::ACFML_GROUP_MODE_LOCALIZATION => self::WPML_COPY_ONCE_CUSTOM_FIELD,
        ],
        'page_link' => [
            self::ACFML_GROUP_MODE_TRANSLATION => self::WPML_COPY_CUSTOM_FIELD,
            self::ACFML_GROUP_MODE_LOCALIZATION => self::WPML_COPY_ONCE_CUSTOM_FIELD,
        ],
        'relationship' => [
            self::ACFML_GROUP_MODE_TRANSLATION => self::WPML_COPY_CUSTOM_FIELD,
            self::ACFML_GROUP_MODE_LOCALIZATION => self::WPML_COPY_ONCE_CUSTOM_FIELD,
        ],
        'taxonomy' => [
            self::ACFML_GROUP_MODE_TRANSLATION => self::WPML_COPY_CUSTOM_FIELD,
            self::ACFML_GROUP_MODE_LOCALIZATION => self::WPML_COPY_ONCE_CUSTOM_FIELD,
        ],
        'user' => [
            self::ACFML_GROUP_MODE_TRANSLATION => self::WPML_COPY_CUSTOM_FIELD,
            self::ACFML_GROUP_MODE_LOCALIZATION => self::WPML_COPY_ONCE_CUSTOM_FIELD,
        ],
    ];

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

    private function get_saved_field_modes_by_key(): array {
        $field_modes = get_option( self::FIELD_TRANSLATION_MODES_BY_KEY_OPTION, [] );

        return is_array( $field_modes ) ? $field_modes : [];
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

    private function build_post_meta( int $post_id ): array {
        $all_meta = get_post_meta( $post_id );
        $meta = [];

        foreach ( $all_meta as $key => $value ) {
            if ( isset( $all_meta[ "_{$key}" ] ) ) {
                $meta[ $key ] = $value[0] ?? '';
                $meta[ "_{$key}" ] = $all_meta[ "_{$key}" ][0] ?? '';
            }
        }

        return $meta;
    }

    public function __construct() {
        add_action( 'add_meta_boxes', [ $this, 'register_field_group_mode_meta_box' ] );
        add_action( 'acf/render_field_settings', [ $this, 'add_translation_mode_setting' ] );
        add_filter( 'acf/load_field_group', [ $this, 'inject_field_group_mode' ], 20 );
        add_filter( 'acf/pre_update_field_group', [ $this, 'prepare_field_group_mode_for_save' ], 20 );
        add_filter( 'acf/pre_save_json_file', [ $this, 'inject_field_group_mode_into_json_export' ], 20 );
        add_filter( 'acf/update_field', [ $this, 'sync_wpml_compat_field_settings' ], 20 );
        add_action( 'acf/update_field_group', [ $this, 'save_field_group_mode' ], 5 );
        add_filter( 'acf/pre_load_reference', [ $this, 'handle_pre_load_reference' ], 10, 3 );
        add_filter( 'acf/validate_post_id', [ $this, 'handle_validate_post_id' ], 10, 2 );
        add_filter( 'acf/update_value', [ $this, 'handle_update_value' ], 10, 3 );
        add_filter( 'acf/update_value/type=nav_menu', [ $this, 'normalize_nav_menu_field_value_on_save' ], 20, 3 );
        add_filter( 'acf/load_field', [ $this, 'handle_load_field' ] );
        add_filter( 'acf/pre_load_meta', [ $this, 'handle_pre_load_meta' ], 10, 2 );
        add_filter( 'acf/pre_load_value', [ $this, 'handle_pre_load_value' ], 10, 3 );
        add_filter( 'acf/load_value/type=nav_menu', [ $this, 'translate_nav_menu_field_value' ], 20, 3 );
        add_action( 'acf/update_field_group', [ $this, 'save_translatable_fields_config' ], 20 );
        add_filter( 'manage_acf-field-group_posts_columns', [ $this, 'add_field_group_mode_column' ], 11 );
        add_action( 'manage_acf-field-group_posts_custom_column', [ $this, 'render_field_group_mode_column' ], 10, 2 );
    }

    private function get_context_language(): string {
        return is_admin() ? wp_loc_get_admin_lang() : wp_loc_get_current_lang();
    }

    private function get_post_entity_id( $post_id ): ?int {
        if ( is_numeric( $post_id ) ) {
            $resolved = (int) $post_id;

            return $resolved > 0 ? $resolved : null;
        }

        if ( is_string( $post_id ) && preg_match( '/^post_(\d+)$/', $post_id, $matches ) ) {
            $resolved = (int) $matches[1];

            return $resolved > 0 ? $resolved : null;
        }

        return null;
    }

    private function get_term_context( $post_id ): ?array {
        if ( ! function_exists( 'acf_decode_post_id' ) ) {
            return null;
        }

        $decoded = acf_decode_post_id( $post_id );

        if ( empty( $decoded['type'] ) || $decoded['type'] !== 'term' || empty( $decoded['id'] ) ) {
            return null;
        }

        $term = get_term( (int) $decoded['id'] );

        if ( ! $term instanceof \WP_Term || ! WP_LOC_Terms::is_translatable( $term->taxonomy ) ) {
            return null;
        }

        return [
            'term_id'  => (int) $term->term_id,
            'taxonomy' => (string) $term->taxonomy,
            'post_id'  => $term->taxonomy . '_' . (int) $term->term_id,
        ];
    }

    private function get_term_acf_post_id( int $term_id, string $taxonomy ): string {
        return $taxonomy . '_' . $term_id;
    }

    private function get_term_translation_targets( int $term_id, string $taxonomy ): array {
        $translations = WP_LOC_Terms::get_term_translations( $term_id, $taxonomy );
        $targets = [];

        foreach ( $translations as $translation ) {
            $target_id = WP_LOC_Terms::get_term_id_from_taxonomy_id( (int) ( $translation->element_id ?? 0 ), $taxonomy );

            if ( $target_id && $target_id !== $term_id ) {
                $targets[] = (int) $target_id;
            }
        }

        return $targets;
    }

    private function sync_shared_term_field_value( int $term_id, string $taxonomy, array $field, $value ): void {
        if ( empty( $field['key'] ) || ! function_exists( 'acf_update_value' ) ) {
            return;
        }

        static $syncing = [];

        $source_key = $taxonomy . ':' . $term_id . ':' . $field['key'];

        if ( isset( $syncing[ $source_key ] ) ) {
            return;
        }

        $syncing[ $source_key ] = true;

        foreach ( $this->get_term_translation_targets( $term_id, $taxonomy ) as $target_id ) {
            $target_key = $taxonomy . ':' . $target_id . ':' . $field['key'];

            if ( isset( $syncing[ $target_key ] ) ) {
                continue;
            }

            $syncing[ $target_key ] = true;
            acf_update_value( $value, $this->get_term_acf_post_id( $target_id, $taxonomy ), $field );
            unset( $syncing[ $target_key ] );
        }

        unset( $syncing[ $source_key ] );
    }

    private function sync_copy_once_term_field_value( int $term_id, string $taxonomy, array $field, $value ): void {
        if ( empty( $field['key'] ) || empty( $field['name'] ) || ! function_exists( 'acf_update_value' ) ) {
            return;
        }

        static $syncing = [];

        $source_key = $taxonomy . ':' . $term_id . ':' . $field['key'] . ':copy_once';

        if ( isset( $syncing[ $source_key ] ) ) {
            return;
        }

        $syncing[ $source_key ] = true;

        foreach ( $this->get_term_translation_targets( $term_id, $taxonomy ) as $target_id ) {
            $target_key = $taxonomy . ':' . $target_id . ':' . $field['key'] . ':copy_once';

            if ( isset( $syncing[ $target_key ] ) || metadata_exists( 'term', $target_id, $field['name'] ) ) {
                continue;
            }

            $syncing[ $target_key ] = true;
            acf_update_value( $value, $this->get_term_acf_post_id( $target_id, $taxonomy ), $field );
            unset( $syncing[ $target_key ] );
        }

        unset( $syncing[ $source_key ] );
    }

    private function get_term_copy_once_source_id( int $term_id, string $taxonomy, array $field ): ?int {
        if ( empty( $field['name'] ) ) {
            return null;
        }

        if ( metadata_exists( 'term', $term_id, $field['name'] ) ) {
            return null;
        }

        $translations = WP_LOC_Terms::get_term_translations( $term_id, $taxonomy );
        $current_lang = WP_LOC_Terms::get_term_language( $term_id, $taxonomy );

        if ( ! $current_lang || empty( $translations[ $current_lang ] ) ) {
            return null;
        }

        $source_lang = $translations[ $current_lang ]->source_language_code ?? null;

        if ( ! $source_lang || empty( $translations[ $source_lang ]->element_id ) ) {
            return null;
        }

        return WP_LOC_Terms::get_term_id_from_taxonomy_id( (int) $translations[ $source_lang ]->element_id, $taxonomy );
    }

    private function build_term_meta( int $term_id ): array {
        $all_meta = get_term_meta( $term_id );
        $meta = [];

        foreach ( $all_meta as $key => $value ) {
            if ( isset( $all_meta[ "_{$key}" ] ) ) {
                $meta[ $key ] = $value[0] ?? '';
                $meta[ "_{$key}" ] = $all_meta[ "_{$key}" ][0] ?? '';
            }
        }

        return $meta;
    }

    private function get_translatable_post_types_for_acf(): array {
        static $cached = null;

        if ( is_array( $cached ) ) {
            return $cached;
        }

        $saved = get_option( WP_LOC_Admin_Settings::OPTION_KEY );
        $cached = is_array( $saved ) && ! empty( $saved ) ? $saved : [ 'post', 'page' ];

        return $cached;
    }

    private function is_translatable_post_type_for_acf( string $post_type ): bool {
        return in_array( $post_type, $this->get_translatable_post_types_for_acf(), true );
    }

    private function get_post_translation_targets( int $post_id ): array {
        $post = get_post( $post_id );

        if ( ! $post instanceof \WP_Post || ! $this->is_translatable_post_type_for_acf( $post->post_type ) ) {
            return [];
        }

        $element_type = WP_LOC_DB::post_element_type( $post->post_type );
        $trid = WP_LOC::instance()->db->get_trid( $post_id, $element_type );

        if ( ! $trid ) {
            return [];
        }

        $translations = WP_LOC::instance()->db->get_element_translations( $trid, $element_type );
        $targets = [];

        foreach ( $translations as $translation ) {
            $target_id = isset( $translation->element_id ) ? (int) $translation->element_id : 0;

            if ( $target_id > 0 && $target_id !== $post_id && get_post( $target_id ) instanceof \WP_Post ) {
                $targets[] = $target_id;
            }
        }

        return $targets;
    }

    private function sync_shared_post_field_value( int $post_id, array $field, $value ): void {
        if ( empty( $field['key'] ) || ! function_exists( 'acf_update_value' ) ) {
            return;
        }

        static $syncing = [];

        $source_key = $post_id . ':' . $field['key'];

        if ( isset( $syncing[ $source_key ] ) ) {
            return;
        }

        $syncing[ $source_key ] = true;

        foreach ( $this->get_post_translation_targets( $post_id ) as $target_id ) {
            $target_key = $target_id . ':' . $field['key'];

            if ( isset( $syncing[ $target_key ] ) ) {
                continue;
            }

            $syncing[ $target_key ] = true;
            acf_update_value( $value, $target_id, $field );
            unset( $syncing[ $target_key ] );
        }

        unset( $syncing[ $source_key ] );
    }

    private function sync_copy_once_post_field_value( int $post_id, array $field, $value ): void {
        if ( empty( $field['key'] ) || empty( $field['name'] ) || ! function_exists( 'acf_update_value' ) ) {
            return;
        }

        static $syncing = [];

        $source_key = $post_id . ':' . $field['key'] . ':copy_once';

        if ( isset( $syncing[ $source_key ] ) ) {
            return;
        }

        $syncing[ $source_key ] = true;

        foreach ( $this->get_post_translation_targets( $post_id ) as $target_id ) {
            $target_key = $target_id . ':' . $field['key'] . ':copy_once';

            if ( isset( $syncing[ $target_key ] ) || metadata_exists( 'post', $target_id, $field['name'] ) ) {
                continue;
            }

            $syncing[ $target_key ] = true;
            acf_update_value( $value, $target_id, $field );
            unset( $syncing[ $target_key ] );
        }

        unset( $syncing[ $source_key ] );
    }

    private function get_post_copy_once_source_id( int $post_id, array $field ): ?int {
        $post = get_post( $post_id );

        if ( ! $post instanceof \WP_Post || ! $this->is_translatable_post_type_for_acf( $post->post_type ) ) {
            return null;
        }

        if ( empty( $field['name'] ) ) {
            return null;
        }

        if ( metadata_exists( 'post', $post_id, $field['name'] ) ) {
            return null;
        }

        $element_type = WP_LOC_DB::post_element_type( $post->post_type );
        $trid = WP_LOC::instance()->db->get_trid( $post_id, $element_type );

        if ( ! $trid ) {
            return null;
        }

        $translations = WP_LOC::instance()->db->get_element_translations( $trid, $element_type );
        $current_lang = WP_LOC::instance()->db->get_element_language( $post_id, $element_type );

        if ( ! $current_lang || empty( $translations[ $current_lang ] ) ) {
            return null;
        }

        $source_lang = $translations[ $current_lang ]->source_language_code ?? null;

        if ( ! $source_lang ) {
            return null;
        }

        if ( ! empty( $translations[ $source_lang ]->element_id ) ) {
            return (int) $translations[ $source_lang ]->element_id;
        }

        return null;
    }

    private function get_post_shared_source_id( int $post_id, array $field ): ?int {
        $post = get_post( $post_id );

        if ( ! $post instanceof \WP_Post || ! $this->is_translatable_post_type_for_acf( $post->post_type ) ) {
            return null;
        }

        if ( empty( $field['name'] ) ) {
            return null;
        }

        if ( metadata_exists( 'post', $post_id, $field['name'] ) ) {
            return null;
        }

        $element_type = WP_LOC_DB::post_element_type( $post->post_type );
        $trid = WP_LOC::instance()->db->get_trid( $post_id, $element_type );

        if ( ! $trid ) {
            return null;
        }

        $translations = WP_LOC::instance()->db->get_element_translations( $trid, $element_type );
        $current_lang = WP_LOC::instance()->db->get_element_language( $post_id, $element_type );

        if ( ! $current_lang || empty( $translations[ $current_lang ] ) ) {
            return null;
        }

        $source_lang = $translations[ $current_lang ]->source_language_code ?? null;

        if ( ! $source_lang ) {
            return null;
        }

        if ( ! empty( $translations[ $source_lang ]->element_id ) ) {
            return (int) $translations[ $source_lang ]->element_id;
        }

        return null;
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

    public function register_field_group_mode_meta_box(): void {
        add_meta_box(
            'wp-loc-acf-field-group-setup',
            esc_html__( 'Multilingual Setup', 'wp-loc' ),
            [ $this, 'render_field_group_mode_meta_box' ],
            'acf-field-group',
            'normal',
            'high'
        );
    }

    public function render_field_group_mode_meta_box( WP_Post $post ): void {
        $field_group = function_exists( 'acf_get_field_group' ) ? acf_get_field_group( $post->ID ) : [];
        $current_mode = $this->get_field_group_mode( is_array( $field_group ) ? $field_group : [] );

        wp_nonce_field( 'wp_loc_save_acf_field_group_mode', 'wp_loc_acf_field_group_mode_nonce' );

        $modes = [
            self::ACFML_GROUP_MODE_TRANSLATION => [
                'label' => __( 'Same fields across languages', 'wp-loc' ),
                'description' => __( 'Translate content while keeping the same field structure across all languages. Field order, layouts, and field types stay aligned.', 'wp-loc' ),
            ],
            self::ACFML_GROUP_MODE_LOCALIZATION => [
                'label' => __( 'Different fields across languages', 'wp-loc' ),
                'description' => __( 'Allow a different field structure per language. Field order, layouts, and even field availability can vary between translations.', 'wp-loc' ),
            ],
            self::ACFML_GROUP_MODE_ADVANCED => [
                'label' => __( 'Expert', 'wp-loc' ),
                'description' => __( 'Manually control translation preferences for each field in the group. Best for migrated field groups or advanced setups.', 'wp-loc' ),
            ],
        ];
        ?>
        <div class="wp-loc-acfml-setup">
            <p class="wp-loc-acfml-setup__intro">
                <?php echo esc_html__( 'Select a translation option for this field group.', 'wp-loc' ); ?>
            </p>
            <div class="wp-loc-acfml-setup__list">
                <?php foreach ( $modes as $mode => $config ) : ?>
                    <label class="wp-loc-acfml-mode-row">
                        <input
                            type="radio"
                            name="wp_loc_acf_field_group_mode"
                            value="<?php echo esc_attr( $mode ); ?>"
                            <?php checked( $current_mode, $mode ); ?>
                        />
                        <span class="wp-loc-acfml-mode-row__content">
                            <span class="wp-loc-acfml-mode-row__title"><?php echo esc_html( $config['label'] ); ?></span>
                            <span class="wp-loc-acfml-mode-row__description"><?php echo esc_html( $config['description'] ); ?></span>
                        </span>
                    </label>
                <?php endforeach; ?>
            </div>
        </div>
        <?php
    }

    public function inject_field_group_mode( $field_group ) {
        if ( ! is_array( $field_group ) ) {
            return $field_group;
        }

        $field_group[ self::ACFML_FIELD_GROUP_MODE_KEY ] = $this->get_field_group_mode( $field_group );

        return $field_group;
    }

    public function prepare_field_group_mode_for_save( array $field_group ): array {
        $mode = $this->get_requested_field_group_mode();

        if ( ! $mode ) {
            $mode = $this->get_field_group_mode( $field_group );
        }

        $field_group[ self::ACFML_FIELD_GROUP_MODE_KEY ] = $mode;

        return $field_group;
    }

    public function inject_field_group_mode_into_json_export( array $post ): array {
        if ( empty( $post['key'] ) || ! is_string( $post['key'] ) || ! str_starts_with( $post['key'], 'group_' ) ) {
            return $post;
        }

        $post[ self::ACFML_FIELD_GROUP_MODE_KEY ] = $this->get_field_group_mode( $post );

        if ( ! empty( $post['fields'] ) && is_array( $post['fields'] ) ) {
            $post['fields'] = array_map( [ $this, 'normalize_field_translation_settings' ], $post['fields'] );
        }

        return $post;
    }

    /**
     * Add "Translation Mode" setting to ACF field settings
     */
    public function add_translation_mode_setting( array $field ): void {
        if ( empty( $field['name'] ) ) return;

        $is_group_managed = $this->is_group_managed_field( $field );

        if ( $is_group_managed ) {
            return;
        }

        $current_mode = $this->get_translation_mode( $field );

        acf_render_field_setting( $field, [
            'label'         => __( 'Translation preferences', 'wp-loc' ),
            'instructions'  => __( 'What to do with field value when the post, term, or options page is translated.', 'wp-loc' ),
            'name'          => 'translation_mode',
            'type'          => 'radio',
            'choices'       => [
                'none'         => _x( "Don't translate", 'ACF field translation preference', 'wp-loc' ),
                'shared'       => _x( 'Copy', 'ACF field translation preference', 'wp-loc' ),
                'copy_once'    => _x( 'Copy once', 'ACF field translation preference', 'wp-loc' ),
                'translatable' => _x( 'Translate', 'ACF field translation preference', 'wp-loc' ),
            ],
            'layout'        => 'vertical',
            'value'         => $current_mode,
            'default_value' => $current_mode ?: 'none',
            'ui'            => 0,
        ], true );
    }

    public function add_field_group_mode_column( array $columns ): array {
        $columns[ self::FIELD_GROUP_MODE_COLUMN_KEY ] = __( 'Translation Option', 'wp-loc' );

        return $columns;
    }

    public function render_field_group_mode_column( string $column, int $post_id ): void {
        if ( $column !== self::FIELD_GROUP_MODE_COLUMN_KEY ) {
            return;
        }

        if ( ! function_exists( 'acf_get_field_group' ) ) {
            echo '&mdash;';
            return;
        }

        $field_group = acf_get_field_group( $post_id );

        if ( ! is_array( $field_group ) ) {
            echo '&mdash;';
            return;
        }

        echo esc_html( $this->get_field_group_mode_label( $this->get_field_group_mode( $field_group ) ) );
    }

    public function save_field_group_mode( array $field_group ): void {
        $field_group_id = (int) ( $field_group['ID'] ?? 0 );
        $field_group_key = isset( $field_group['key'] ) && is_string( $field_group['key'] ) ? $field_group['key'] : '';

        $mode = $this->get_requested_field_group_mode();

        if ( ! $mode ) {
            $mode = $this->get_field_group_mode( $field_group );
        }

        if ( $field_group_id ) {
            update_post_meta( $field_group_id, self::ACFML_FIELD_GROUP_MODE_KEY, $mode );
        }

        if ( $field_group_key ) {
            $stored_modes = get_option( self::FIELD_GROUP_MODES_OPTION, [] );

            if ( ! is_array( $stored_modes ) ) {
                $stored_modes = [];
            }

            $stored_modes[ $field_group_key ] = $mode;
            update_option( self::FIELD_GROUP_MODES_OPTION, $stored_modes );
        }

        $field_group[ self::ACFML_FIELD_GROUP_MODE_KEY ] = $mode;

        if ( $mode !== self::ACFML_GROUP_MODE_ADVANCED ) {
            $this->overwrite_all_field_preferences_with_group_mode( $field_group, $mode );
        }
    }

    public function sync_wpml_compat_field_settings( array $field ): array {
        return $this->normalize_field_translation_settings( $field );
    }

    /**
     * Handle saving ACF field values — route to language-specific option for translatable fields
     */
    public function handle_update_value( $value, $post_id, array $field ) {
        if ( ! is_array( $field ) || ! isset( $field['name'] ) ) return $value;

        $translation_mode = $this->get_translation_mode( $field );

        $term_context = $this->get_term_context( $post_id );

        if ( $term_context ) {
            if ( in_array( $translation_mode, [ 'none', 'shared' ], true ) ) {
                $this->sync_shared_term_field_value( $term_context['term_id'], $term_context['taxonomy'], $field, $value );
            } elseif ( $translation_mode === 'copy_once' ) {
                $this->sync_copy_once_term_field_value( $term_context['term_id'], $term_context['taxonomy'], $field, $value );
            }

            return $value;
        }

        if ( ! $this->is_options_post_id( $post_id ) ) {
            $post_entity_id = $this->get_post_entity_id( $post_id );

            if ( $post_entity_id && in_array( $translation_mode, [ 'none', 'shared' ], true ) ) {
                $this->sync_shared_post_field_value( $post_entity_id, $field, $value );
            } elseif ( $post_entity_id && $translation_mode === 'copy_once' ) {
                $this->sync_copy_once_post_field_value( $post_entity_id, $field, $value );
            }

            return $value;
        }

        if ( $translation_mode === 'none' && $this->is_translated_options_post_id( $post_id ) ) {
            $base_post_id = $this->get_base_options_post_id( $post_id );

            if ( $base_post_id && ! empty( $field['name'] ) ) {
                update_option( "{$base_post_id}_{$field['name']}", $value );
            }

            if ( $base_post_id && ! empty( $field['key'] ) && ! empty( $field['name'] ) ) {
                update_option( "_{$base_post_id}_{$field['name']}", $field['key'] );
            }

            return null;
        }

        if ( $translation_mode === 'none' ) {
            return $value;
        }

        if ( in_array( $translation_mode, [ 'translatable', 'copy_once' ], true ) ) {
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
        if ( ! is_admin() ) return $field;

        $field = $this->normalize_field_translation_settings( $field );

        if ( ! isset( $field['name'] ) ) {
            return $field;
        }

        $admin_lang = wp_loc_get_admin_lang();
        $default_lang = WP_LOC_Languages::get_default_language();

        if ( $admin_lang === $default_lang ) return $field;
        $translation_mode = $field['translation_mode'] ?? $this->get_translation_mode( $field );

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
    public function handle_pre_load_reference( $reference, string $field_name, $post_id ) {
        $term_context = $this->get_term_context( $post_id );

        if ( $reference === null && $term_context ) {
            $source_term_id = $this->get_term_copy_once_source_id(
                $term_context['term_id'],
                $term_context['taxonomy'],
                [ 'name' => $field_name ]
            );

            if ( $source_term_id ) {
                $base_reference = get_term_meta( $source_term_id, "_{$field_name}", true );

                if ( is_string( $base_reference ) && $base_reference !== '' ) {
                    return $base_reference;
                }
            }
        }

        if ( $reference !== null || ! $this->is_translated_options_post_id( $post_id ) ) {
            return $reference;
        }

        $base_post_id = $this->get_base_options_post_id( $post_id );

        if ( ! $base_post_id ) {
            return $reference;
        }

        $base_reference = get_option( "_{$base_post_id}_{$field_name}", null );

        return is_string( $base_reference ) && $base_reference !== '' ? $base_reference : $reference;
    }

    public function handle_pre_load_value( $null, $post_id, array $field ) {
        $post_entity_id = $this->get_post_entity_id( $post_id );

        if ( $post_entity_id && in_array( $this->get_translation_mode( $field ), [ 'none', 'shared' ], true ) ) {
            $source_post_id = $this->get_post_shared_source_id( $post_entity_id, $field );

            if ( $source_post_id ) {
                if ( function_exists( 'acf_get_value' ) ) {
                    return acf_get_value( $source_post_id, $field );
                }

                return get_post_meta( $source_post_id, $field['name'], true );
            }
        }

        if ( $post_entity_id && $this->get_translation_mode( $field ) === 'copy_once' ) {
            $source_post_id = $this->get_post_copy_once_source_id( $post_entity_id, $field );

            if ( $source_post_id ) {
                if ( function_exists( 'acf_get_value' ) ) {
                    return acf_get_value( $source_post_id, $field );
                }

                return get_post_meta( $source_post_id, $field['name'], true );
            }
        }

        $term_context = $this->get_term_context( $post_id );

        if ( $term_context ) {
            $translation_mode = $this->get_translation_mode( $field );

            if ( in_array( $translation_mode, [ 'none', 'shared' ], true ) ) {
                $translations = WP_LOC_Terms::get_term_translations( $term_context['term_id'], $term_context['taxonomy'] );
                $current_lang = WP_LOC_Terms::get_term_language( $term_context['term_id'], $term_context['taxonomy'] );

                if ( $current_lang && ! empty( $translations[ $current_lang ]->source_language_code ) ) {
                    $default_lang = WP_LOC_Languages::get_default_language();
                    $shared_term_id = WP_LOC_Terms::get_term_translation( $term_context['term_id'], $term_context['taxonomy'], $default_lang ) ?: $term_context['term_id'];

                    if ( $shared_term_id !== $term_context['term_id'] ) {
                        return get_term_meta( $shared_term_id, $field['name'], true );
                    }
                }
            }

            if ( $translation_mode === 'copy_once' ) {
                $source_term_id = $this->get_term_copy_once_source_id( $term_context['term_id'], $term_context['taxonomy'], $field );

                if ( $source_term_id ) {
                    return get_term_meta( $source_term_id, $field['name'], true );
                }
            }

            return $null;
        }

        if ( ! $this->is_options_post_id( $post_id ) || ! isset( $field['name'] ) ) {
            return $null;
        }

        $translation_mode = $this->get_translation_mode( $field );

        if ( in_array( $translation_mode, [ 'none', 'shared' ], true ) && $this->is_translated_options_post_id( $post_id ) ) {
            $base_post_id = $this->get_base_options_post_id( $post_id );

            if ( $base_post_id ) {
                if ( function_exists( 'acf_get_value' ) ) {
                    return acf_get_value( $base_post_id, $field );
                }

                return get_option( "{$base_post_id}_{$field['name']}", null );
            }
        }

        if ( ! in_array( $translation_mode, [ 'translatable', 'copy_once' ], true ) ) {
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

        if ( $translation_mode === 'copy_once' ) {
            if ( function_exists( 'acf_get_value' ) ) {
                return acf_get_value( $base_post_id, $field );
            }

            return get_option( "{$base_post_id}_{$field['name']}", null );
        }

        return $null;
    }

    /**
     * Inject localized ACF options meta so get_fields('options') sees translatable fields.
     */
    public function handle_pre_load_meta( $null, $post_id ) {
        $term_context = $this->get_term_context( $post_id );

        if ( $term_context ) {
            $meta = $this->build_term_meta( $term_context['term_id'] );
            $field_modes = get_option( 'wp_loc_acf_field_translation_modes', [] );

            foreach ( $field_modes as $field_name => $mode ) {
                if ( $mode === 'copy_once' && ! metadata_exists( 'term', $term_context['term_id'], $field_name ) ) {
                    $source_term_id = $this->get_term_copy_once_source_id(
                        $term_context['term_id'],
                        $term_context['taxonomy'],
                        [ 'name' => $field_name ]
                    );

                    if ( $source_term_id ) {
                        $source_meta = $this->build_term_meta( $source_term_id );

                        if ( array_key_exists( $field_name, $source_meta ) ) {
                            $meta[ $field_name ] = $source_meta[ $field_name ];
                        }

                        if ( array_key_exists( "_{$field_name}", $source_meta ) ) {
                            $meta[ "_{$field_name}" ] = $source_meta[ "_{$field_name}" ];
                        }
                    }
                }

                if ( in_array( $mode, [ 'none', 'shared' ], true ) && ! metadata_exists( 'term', $term_context['term_id'], $field_name ) ) {
                    $default_lang = WP_LOC_Languages::get_default_language();
                    $shared_term_id = WP_LOC_Terms::get_term_translation( $term_context['term_id'], $term_context['taxonomy'], $default_lang ) ?: $term_context['term_id'];

                    if ( $shared_term_id && $shared_term_id !== $term_context['term_id'] ) {
                        $shared_meta = $this->build_term_meta( $shared_term_id );

                        if ( array_key_exists( $field_name, $shared_meta ) ) {
                            $meta[ $field_name ] = $shared_meta[ $field_name ];
                        }

                        if ( array_key_exists( "_{$field_name}", $shared_meta ) ) {
                            $meta[ "_{$field_name}" ] = $shared_meta[ "_{$field_name}" ];
                        }
                    }
                }
            }

            return $meta;
        }

        $post_entity_id = $this->get_post_entity_id( $post_id );

        if ( $post_entity_id ) {
            $meta = $this->build_post_meta( $post_entity_id );
            $field_modes = get_option( 'wp_loc_acf_field_translation_modes', [] );

            foreach ( $field_modes as $field_name => $mode ) {
                if ( ! in_array( $mode, [ 'none', 'shared', 'copy_once' ], true ) || metadata_exists( 'post', $post_entity_id, $field_name ) ) {
                    continue;
                }

                $source_post_id = in_array( $mode, [ 'none', 'shared' ], true )
                    ? $this->get_post_shared_source_id( $post_entity_id, [ 'name' => $field_name ] )
                    : $this->get_post_copy_once_source_id( $post_entity_id, [ 'name' => $field_name ] );

                if ( ! $source_post_id ) {
                    continue;
                }

                $source_meta = $this->build_post_meta( $source_post_id );

                if ( array_key_exists( $field_name, $source_meta ) ) {
                    $meta[ $field_name ] = $source_meta[ $field_name ];
                }

                if ( array_key_exists( "_{$field_name}", $source_meta ) ) {
                    $meta[ "_{$field_name}" ] = $source_meta[ "_{$field_name}" ];
                }
            }

            return $meta;
        }

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
            if ( ! in_array( $mode, [ 'translatable', 'copy_once' ], true ) ) {
                continue;
            }

            if ( array_key_exists( $field_name, $translated_meta ) ) {
                $meta[ $field_name ] = $translated_meta[ $field_name ];
            } else {
                $localized_value = $this->get_translatable_option_value( $language, $field_name, $base_post_id );

                if ( $localized_value !== false ) {
                    $meta[ $field_name ] = $localized_value;
                } elseif ( $mode === 'translatable' ) {
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

        if ( in_array( $this->get_translation_mode( $field ), [ 'translatable', 'copy_once' ], true ) ) {
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
        $field_modes_by_key = $this->get_saved_field_modes_by_key();

        $this->iterate_group_fields( $field_group, function( array $field ) use ( &$field_modes, &$field_modes_by_key, &$translatable_fields ) {
            if ( empty( $field['name'] ) ) {
                return;
            }

            $mode = $this->get_translation_mode( $field );
            $field_modes[ $field['name'] ] = $mode;
            $field_modes_by_key[ $field['key'] ] = $mode;

            if ( in_array( $mode, [ 'translatable', 'copy_once' ], true ) ) {
                $translatable_fields[ $field['name'] ] = $field['key'];
            } else {
                unset( $translatable_fields[ $field['name'] ] );
            }
        } );

        update_option( 'wp_loc_acf_translatable_fields', $translatable_fields );
        update_option( 'wp_loc_acf_field_translation_modes', $field_modes );
        update_option( self::FIELD_TRANSLATION_MODES_BY_KEY_OPTION, $field_modes_by_key );
    }

    /**
     * Resolve the configured translation mode for a field.
     */
    private function get_translation_mode( array $field ): string {
        if ( isset( $field['translation_mode'] ) && in_array( $field['translation_mode'], [ 'none', 'shared', 'copy_once', 'translatable' ], true ) ) {
            return $field['translation_mode'];
        }

        if ( isset( $field['wpml_cf_preferences'] ) ) {
            $mode = $this->wpml_preference_to_translation_mode( (int) $field['wpml_cf_preferences'] );

            if ( $mode !== null ) {
                return $mode;
            }
        }

        $field_modes_by_key = $this->get_saved_field_modes_by_key();

        if ( ! empty( $field['key'] ) && isset( $field_modes_by_key[ $field['key'] ] ) && in_array( $field_modes_by_key[ $field['key'] ], [ 'none', 'shared', 'copy_once', 'translatable' ], true ) ) {
            return $field_modes_by_key[ $field['key'] ];
        }

        if ( empty( $field['name'] ) ) {
            return $this->get_acfml_group_default_translation_mode( $field ) ?? 'none';
        }

        $field_modes = get_option( 'wp_loc_acf_field_translation_modes', [] );

        if ( isset( $field_modes[ $field['name'] ] ) && in_array( $field_modes[ $field['name'] ], [ 'none', 'shared', 'copy_once', 'translatable' ], true ) ) {
            return $field_modes[ $field['name'] ];
        }

        $translatable_fields = get_option( 'wp_loc_acf_translatable_fields', [] );

        if ( isset( $translatable_fields[ $field['name'] ] ) ) {
            return 'translatable';
        }

        return $this->get_acfml_group_default_translation_mode( $field ) ?? 'none';
    }

    private function get_requested_field_group_mode(): ?string {
        if (
            empty( $_POST['wp_loc_acf_field_group_mode_nonce'] ) ||
            ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['wp_loc_acf_field_group_mode_nonce'] ) ), 'wp_loc_save_acf_field_group_mode' )
        ) {
            return null;
        }

        $mode = isset( $_POST['wp_loc_acf_field_group_mode'] )
            ? sanitize_key( wp_unslash( $_POST['wp_loc_acf_field_group_mode'] ) )
            : '';

        return $this->is_valid_field_group_mode( $mode ) ? $mode : null;
    }

    private function is_valid_field_group_mode( string $mode ): bool {
        return in_array(
            $mode,
            [
                self::ACFML_GROUP_MODE_TRANSLATION,
                self::ACFML_GROUP_MODE_LOCALIZATION,
                self::ACFML_GROUP_MODE_ADVANCED,
            ],
            true
        );
    }

    private function get_field_group_mode( array $field_group ): string {
        $mode = $field_group[ self::ACFML_FIELD_GROUP_MODE_KEY ] ?? null;

        if ( is_string( $mode ) && $this->is_valid_field_group_mode( $mode ) ) {
            return $mode;
        }

        $field_group_key = isset( $field_group['key'] ) && is_string( $field_group['key'] ) ? $field_group['key'] : '';

        if ( $field_group_key ) {
            $stored_modes = get_option( self::FIELD_GROUP_MODES_OPTION, [] );

            if ( is_array( $stored_modes ) ) {
                $stored_mode = $stored_modes[ $field_group_key ] ?? null;

                if ( is_string( $stored_mode ) && $this->is_valid_field_group_mode( $stored_mode ) ) {
                    return $stored_mode;
                }
            }
        }

        if ( ! empty( $field_group['local_file'] ) && is_string( $field_group['local_file'] ) ) {
            $file_mode = $this->get_field_group_mode_from_local_json_file( $field_group['local_file'] );

            if ( $file_mode ) {
                return $file_mode;
            }
        }

        $field_group_id = (int) ( $field_group['ID'] ?? 0 );

        if ( $field_group_id ) {
            $stored_mode = get_post_meta( $field_group_id, self::ACFML_FIELD_GROUP_MODE_KEY, true );

            if ( is_string( $stored_mode ) && $this->is_valid_field_group_mode( $stored_mode ) ) {
                return $stored_mode;
            }
        }

        return self::ACFML_GROUP_MODE_ADVANCED;
    }

    private function get_field_group_mode_from_local_json_file( string $file ): ?string {
        if ( ! is_readable( $file ) ) {
            return null;
        }

        $decoded = json_decode( (string) file_get_contents( $file ), true );

        if ( ! is_array( $decoded ) ) {
            return null;
        }

        $mode = $decoded[ self::ACFML_FIELD_GROUP_MODE_KEY ] ?? null;

        return ( is_string( $mode ) && $this->is_valid_field_group_mode( $mode ) ) ? $mode : null;
    }

    private function get_field_group_mode_label( string $mode ): string {
        return match ( $mode ) {
            self::ACFML_GROUP_MODE_TRANSLATION => __( 'Same fields across languages', 'wp-loc' ),
            self::ACFML_GROUP_MODE_LOCALIZATION => __( 'Different fields across languages', 'wp-loc' ),
            default => __( 'Expert', 'wp-loc' ),
        };
    }

    private function is_group_managed_field( array $field ): bool {
        $group = $this->get_field_group_for_field( $field );

        if ( ! is_array( $group ) ) {
            return false;
        }

        $group_mode = $this->get_field_group_mode( $group );

        return in_array( $group_mode, [ self::ACFML_GROUP_MODE_TRANSLATION, self::ACFML_GROUP_MODE_LOCALIZATION ], true );
    }

    private function wpml_preference_to_translation_mode( int $preference ): ?string {
        return match ( $preference ) {
            self::WPML_TRANSLATE_CUSTOM_FIELD => 'translatable',
            self::WPML_COPY_CUSTOM_FIELD => 'shared',
            self::WPML_COPY_ONCE_CUSTOM_FIELD => 'copy_once',
            self::WPML_IGNORE_CUSTOM_FIELD => 'none',
            default => null,
        };
    }

    private function translation_mode_to_wpml_preference( string $mode ): int {
        return match ( $mode ) {
            'translatable' => self::WPML_TRANSLATE_CUSTOM_FIELD,
            'shared' => self::WPML_COPY_CUSTOM_FIELD,
            'copy_once' => self::WPML_COPY_ONCE_CUSTOM_FIELD,
            default => self::WPML_IGNORE_CUSTOM_FIELD,
        };
    }

    private function get_field_group_for_field( array $field ): ?array {
        $parent = $field['parent'] ?? null;

        if ( ! $parent || ! is_string( $parent ) ) {
            return null;
        }

        if ( function_exists( 'acf_get_field_group' ) ) {
            $group = acf_get_field_group( $parent );

            if ( is_array( $group ) && ! empty( $group['key'] ) ) {
                return $group;
            }
        }

        if ( function_exists( 'acf_get_field' ) ) {
            $parent_field = acf_get_field( $parent );

            if ( is_array( $parent_field ) ) {
                return $this->get_field_group_for_field( $parent_field );
            }
        }

        return null;
    }

    private function normalize_field_translation_settings( array $field ): array {
        $mode = $this->get_translation_mode( $field );
        $field['translation_mode'] = $mode;
        $field['wpml_cf_preferences'] = $this->translation_mode_to_wpml_preference( $mode );

        if ( ! empty( $field['sub_fields'] ) && is_array( $field['sub_fields'] ) ) {
            $field['sub_fields'] = array_map( [ $this, 'normalize_field_translation_settings' ], $field['sub_fields'] );
        }

        if ( ! empty( $field['layouts'] ) && is_array( $field['layouts'] ) ) {
            foreach ( $field['layouts'] as $layout_index => $layout ) {
                if ( empty( $layout['sub_fields'] ) || ! is_array( $layout['sub_fields'] ) ) {
                    continue;
                }

                $field['layouts'][ $layout_index ]['sub_fields'] = array_map(
                    [ $this, 'normalize_field_translation_settings' ],
                    $layout['sub_fields']
                );
            }
        }

        return $field;
    }

    private function iterate_group_fields( array $field_group, callable $callback ): void {
        $fields = function_exists( 'acf_get_fields' ) ? acf_get_fields( $field_group ) : [];

        if ( ! is_array( $fields ) ) {
            return;
        }

        $walker = function( array $fields ) use ( &$walker, $callback ): void {
            foreach ( $fields as $field ) {
                if ( ! is_array( $field ) ) {
                    continue;
                }

                $callback( $field );

                if ( ! empty( $field['sub_fields'] ) && is_array( $field['sub_fields'] ) ) {
                    $walker( $field['sub_fields'] );
                }

                if ( ! empty( $field['layouts'] ) && is_array( $field['layouts'] ) ) {
                    foreach ( $field['layouts'] as $layout ) {
                        if ( empty( $layout['sub_fields'] ) || ! is_array( $layout['sub_fields'] ) ) {
                            continue;
                        }

                        $walker( $layout['sub_fields'] );
                    }
                }
            }
        };

        $walker( $fields );
    }

    private function overwrite_all_field_preferences_with_group_mode( array $field_group, string $group_mode ): void {
        $this->iterate_group_fields( $field_group, function( array $field ) use ( $group_mode ) {
            $preference = $this->get_acfml_group_mode_default_preference( $field, $group_mode );

            if ( $preference === null ) {
                return;
            }

            $field['translation_mode'] = $this->wpml_preference_to_translation_mode( $preference ) ?? 'none';
            $field['wpml_cf_preferences'] = $preference;

            acf_update_field( $field );
        } );
    }

    private function get_acfml_group_mode_default_preference( array $field, string $group_mode ): ?int {
        $field_type = isset( $field['type'] ) ? (string) $field['type'] : '';

        if ( ! $field_type || empty( self::ACFML_MODE_DEFAULTS[ $field_type ][ $group_mode ] ) ) {
            return null;
        }

        return (int) apply_filters(
            'acfml_field_group_mode_field_translation_preference',
            self::ACFML_MODE_DEFAULTS[ $field_type ][ $group_mode ],
            $group_mode,
            $field
        );
    }

    private function get_acfml_group_default_translation_mode( array $field ): ?string {
        $group = $this->get_field_group_for_field( $field );

        if ( ! is_array( $group ) ) {
            return null;
        }

        $group_mode = $this->get_field_group_mode( $group );

        if ( ! in_array( $group_mode, [ self::ACFML_GROUP_MODE_TRANSLATION, self::ACFML_GROUP_MODE_LOCALIZATION ], true ) ) {
            return null;
        }

        $preference = $this->get_acfml_group_mode_default_preference( $field, $group_mode );

        if ( $preference === null ) {
            return null;
        }

        return $this->wpml_preference_to_translation_mode( $preference );
    }
}
