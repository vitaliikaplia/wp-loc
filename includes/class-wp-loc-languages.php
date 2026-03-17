<?php

if ( ! defined( 'ABSPATH' ) ) exit;

class WP_LOC_Languages {

    private static $languages = null;

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

        return strtolower( substr( $locale, 0, 2 ) );
    }

    public function __construct() {
        add_action( 'update_option_WPLANG', [ $this, 'on_wplang_change' ], 10, 2 );
    }

    /**
     * Get all configured languages
     *
     * @return array [ 'uk' => ['locale' => 'uk', 'enabled' => true], 'en' => [...], ... ]
     */
    public static function get_languages(): array {
        if ( self::$languages !== null ) {
            return self::$languages;
        }

        self::$languages = get_option( 'wp_loc_languages', [] );

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
        $languages = self::get_active_languages();
        $system_locale = get_option( 'WPLANG' ) ?: 'en_US';

        foreach ( $languages as $slug => $data ) {
            $locale = $data['locale'] ?? '';
            if ( $locale === $system_locale ) {
                return $slug;
            }
        }

        return $languages ? array_key_first( $languages ) : 'en';
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
                'locale'  => $new ?: 'en_US',
                'enabled' => true,
            ];
        } else {
            $langs[ $slug ]['enabled'] = true;
        }

        update_option( 'wp_loc_languages', $langs );
        update_option( 'wp_loc_flush_rewrite_rules', true );
        self::$languages = null;
    }

    /**
     * Reset static cache
     */
    public static function flush(): void {
        self::$languages = null;
    }
}
