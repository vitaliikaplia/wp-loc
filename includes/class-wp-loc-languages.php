<?php

if ( ! defined( 'ABSPATH' ) ) exit;

class WP_LOC_Languages {

    public const DEFAULT_LANGUAGE_OPTION_KEY = 'wp_loc_default_language';

    private static $languages = null;
    private static $default_language = null;

    /**
     * Locale → preferred URL slug mapping.
     * Override the naive "first 2 chars of locale" logic.
     * Filterable via 'wp_loc_locale_slug_map'.
     */
    private static $locale_slug_map = [
        'uk'    => 'ua',  // Ukrainian → ua (country code, not language code)
        'en_US' => 'en',
        'en_GB' => 'en',
        'he_IL' => 'he',
        'zh_CN' => 'zh',
        'zh_TW' => 'zh-tw',
        'pt_BR' => 'pt-br',
        'pt_PT' => 'pt',
        'nb_NO' => 'no',
        'nn_NO' => 'nn',
    ];

    /**
     * Get the preferred slug for a given locale
     */
    public static function locale_to_slug( string $locale ): string {
        $map = apply_filters( 'wp_loc_locale_slug_map', self::$locale_slug_map );

        if ( isset( $map[ $locale ] ) ) {
            return $map[ $locale ];
        }

        if ( class_exists( 'WP_LOC_Language_Registry' ) ) {
            return WP_LOC_Language_Registry::slug_from_locale( $locale );
        }

        return strtolower( substr( $locale, 0, 2 ) );
    }

    public function __construct() {
        $this->ensure_current_language_exists();
        add_action( 'update_option_WPLANG', [ $this, 'on_wplang_change' ], 10, 2 );
    }

    private function ensure_current_language_exists(): void {
        $langs = self::get_languages();

        if ( ! empty( $langs ) ) {
            return;
        }

        $locale = self::get_raw_option( 'WPLANG', 'en_US' ) ?: 'en_US';
        $slug = self::locale_to_slug( $locale );

        update_option( 'wp_loc_languages', [
            $slug => [
                'locale'       => $locale,
                'enabled'      => true,
                'display_name' => self::get_language_display_name( $locale ),
                'wpml_code'    => class_exists( 'WP_LOC_Language_Registry' ) ? WP_LOC_Language_Registry::wpml_code_from_locale( $locale ) : $slug,
            ],
        ] );

        self::$languages = null;
        self::$default_language = null;
    }

    /**
     * Get all configured languages
     *
     * @return array [ 'ua' => ['locale' => 'uk', 'enabled' => true, 'wpml_code' => 'uk'], 'en' => [...], ... ]
     */
    public static function get_languages(): array {
        if ( self::$languages !== null ) {
            return self::$languages;
        }

        self::$languages = get_option( 'wp_loc_languages', [] );
        foreach ( self::$languages as $slug => &$language ) {
            if ( is_array( $language ) && empty( $language['wpml_code'] ) ) {
                $language['wpml_code'] = class_exists( 'WP_LOC_Language_Registry' )
                    ? WP_LOC_Language_Registry::wpml_code_from_locale( (string) ( $language['locale'] ?? $slug ) )
                    : ( $slug === 'ua' ? 'uk' : sanitize_key( (string) $slug ) );
            }
        }
        unset( $language );

        return self::$languages;
    }

    /**
     * Get only active (enabled) languages
     */
    public static function get_active_languages(): array {
        return array_filter( self::get_languages(), fn( $lang ) => ! empty( $lang['enabled'] ) );
    }

    /**
     * Get default language slug
     */
    public static function get_default_language(): string {
        if ( self::$default_language !== null ) {
            return self::$default_language;
        }

        static $resolving = false;
        $languages = self::get_active_languages();
        if ( $resolving ) {
            return $languages ? array_key_first( $languages ) : 'en';
        }

        $resolving = true;
        $system_locale = self::get_raw_option( 'WPLANG', 'en_US' ) ?: 'en_US';
        $configured_default = sanitize_key( (string) self::get_raw_option( self::DEFAULT_LANGUAGE_OPTION_KEY, '' ) );

        if ( $configured_default && isset( $languages[ $configured_default ] ) && ! empty( $languages[ $configured_default ]['enabled'] ) ) {
            $resolving = false;
            return self::$default_language = $configured_default;
        }

        foreach ( $languages as $slug => $data ) {
            $locale = $data['locale'] ?? '';
            if ( $locale === $system_locale ) {
                $resolving = false;
                return self::$default_language = $slug;
            }
        }

        $resolving = false;
        return self::$default_language = ( $languages ? array_key_first( $languages ) : 'en' );
    }

    public static function set_default_language( string $slug ): bool {
        $slug = sanitize_key( $slug );
        $languages = self::get_active_languages();

        if ( ! $slug || ! isset( $languages[ $slug ] ) ) {
            return false;
        }

        update_option( self::DEFAULT_LANGUAGE_OPTION_KEY, $slug );
        self::$default_language = null;

        return true;
    }

    private static function get_raw_option( string $option, $default = false ) {
        global $wpdb;

        if ( ! $wpdb ) {
            return get_option( $option, $default );
        }

        $value = $wpdb->get_var( $wpdb->prepare(
            "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s LIMIT 1",
            $option
        ) );

        return $value === null ? $default : maybe_unserialize( $value );
    }

    /**
     * Get additional (non-default) language slugs
     */
    public static function get_additional_languages(): array {
        $default = self::get_default_language();
        $active = self::get_active_languages();
        return array_values( array_filter( array_keys( $active ), fn( $slug ) => $slug !== $default ) );
    }

    /**
     * Get WP locale for a language slug
     */
    public static function get_language_locale( string $slug ): string {
        $languages = self::get_languages();
        return $languages[ $slug ]['locale'] ?? $slug;
    }

    public static function get_wpml_code( string $slug ): string {
        $languages = self::get_languages();

        if ( ! empty( $languages[ $slug ]['wpml_code'] ) ) {
            return sanitize_key( (string) $languages[ $slug ]['wpml_code'] );
        }

        if ( class_exists( 'WP_LOC_Language_Registry' ) ) {
            return WP_LOC_Language_Registry::wpml_code_from_locale( (string) ( $languages[ $slug ]['locale'] ?? $slug ) );
        }

        return $slug === 'ua' ? 'uk' : sanitize_key( $slug );
    }

    /**
     * Get display name for a language slug (for switchers)
     */
    public static function get_display_name( string $slug ): string {
        $languages = self::get_languages();
        return $languages[ $slug ]['display_name'] ?? strtoupper( $slug );
    }

    /**
     * Get language slug for a WP locale
     */
    public static function get_language_slug( string $locale ): ?string {
        foreach ( self::get_languages() as $slug => $data ) {
            if ( ( $data['locale'] ?? '' ) === $locale ) {
                return $slug;
            }
        }
        return null;
    }

    /**
     * Get installed locales from .mo files
     */
    public static function get_installed_locales(): array {
        $base = WP_CONTENT_DIR . '/languages';
        $locales = [ 'en_US' ];

        foreach ( glob( "$base/*.mo" ) as $file ) {
            $basename = basename( $file, '.mo' );

            if ( str_starts_with( $basename, 'admin-' ) ) continue;
            if ( str_starts_with( $basename, 'admin-network-' ) ) continue;
            if ( str_starts_with( $basename, 'continents-cities' ) ) continue;
            if ( str_starts_with( $basename, 'network-' ) ) continue;

            $locales[] = $basename;
        }

        return array_unique( $locales );
    }

    /**
     * Get native display name for a locale
     */
    public static function get_language_display_name( string $locale ): string {
        if ( class_exists( 'WP_LOC_Language_Registry' ) ) {
            $normalized = WP_LOC_Language_Registry::normalize_external_language( '', $locale );

            if ( ! empty( $normalized['display_name'] ) ) {
                return $normalized['display_name'];
            }
        }

        if ( $locale === 'en_US' ) {
            return 'English';
        }

        if ( ! function_exists( 'wp_get_available_translations' ) ) {
            require_once ABSPATH . 'wp-admin/includes/translation-install.php';
        }

        $translations = wp_get_available_translations();

        if ( isset( $translations[ $locale ] ) ) {
            return $translations[ $locale ]['native_name'];
        }

        foreach ( $translations as $data ) {
            if ( str_starts_with( $data['language'], $locale ) ) {
                return $data['native_name'];
            }
        }

        return ucfirst( str_replace( '_', '-', $locale ) );
    }

    /**
     * Get flag URL for a language slug or locale
     */
    public static function get_flag_url( string $code ): string {
        if ( class_exists( 'WP_LOC_Language_Registry' ) ) {
            return esc_url( WP_LOC_URL . 'assets/flags/' . WP_LOC_Language_Registry::flag_from_locale( $code ) . '.svg' );
        }

        $exceptions = [
            'uk' => 'ua',
            'en' => 'us',
            'he' => 'il',
            'zh' => 'cn',
            'bel' => 'by',
        ];

        $code = strtolower( $code );

        if ( isset( $exceptions[ $code ] ) ) {
            $country_code = $exceptions[ $code ];
        } elseif ( strpos( $code, '_' ) !== false ) {
            [ , $country ] = explode( '_', $code, 2 );
            $country_code = strtolower( $country );
        } else {
            $country_code = $code;
        }

        return esc_url( WP_LOC_URL . 'assets/flags/' . $country_code . '.svg' );
    }

    /**
     * Update languages when WPLANG option changes
     */
    public function on_wplang_change( $old, $new ): void {
        $langs = self::get_languages();

        $slug = self::locale_to_slug( $new ?: 'en_US' );

        if ( ! isset( $langs[ $slug ] ) ) {
            $langs[ $slug ] = [
                'locale'    => $new ?: 'en_US',
                'enabled'   => true,
                'wpml_code' => class_exists( 'WP_LOC_Language_Registry' ) ? WP_LOC_Language_Registry::wpml_code_from_locale( $new ?: 'en_US' ) : $slug,
            ];
        } else {
            $langs[ $slug ]['enabled'] = true;
            if ( empty( $langs[ $slug ]['wpml_code'] ) ) {
                $langs[ $slug ]['wpml_code'] = class_exists( 'WP_LOC_Language_Registry' ) ? WP_LOC_Language_Registry::wpml_code_from_locale( $new ?: 'en_US' ) : $slug;
            }
        }

        update_option( 'wp_loc_languages', $langs );
        update_option( 'wp_loc_flush_rewrite_rules', true );
        self::$languages = null;
        self::$default_language = null;
    }

    /**
     * Reset static cache
     */
    public static function flush(): void {
        self::$languages = null;
        self::$default_language = null;
    }
}
