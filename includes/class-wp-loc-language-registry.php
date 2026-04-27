<?php

if ( ! defined( 'ABSPATH' ) ) exit;

class WP_LOC_Language_Registry {

    private static $languages = [
        'af' => [ 'locale' => 'af', 'slug' => 'af', 'flag' => 'za', 'name' => 'Afrikaans' ],
        'ar' => [ 'locale' => 'ar', 'slug' => 'ar', 'flag' => 'sa', 'name' => 'العربية' ],
        'az' => [ 'locale' => 'az', 'slug' => 'az', 'flag' => 'az', 'name' => 'Azərbaycan dili' ],
        'be' => [ 'locale' => 'bel', 'slug' => 'be', 'flag' => 'by', 'name' => 'Беларуская мова' ],
        'bg' => [ 'locale' => 'bg_BG', 'slug' => 'bg', 'flag' => 'bg', 'name' => 'Български' ],
        'bn' => [ 'locale' => 'bn_BD', 'slug' => 'bn', 'flag' => 'bd', 'name' => 'বাংলা' ],
        'bs' => [ 'locale' => 'bs_BA', 'slug' => 'bs', 'flag' => 'ba', 'name' => 'Bosanski' ],
        'ca' => [ 'locale' => 'ca', 'slug' => 'ca', 'flag' => 'es', 'name' => 'Català' ],
        'cs' => [ 'locale' => 'cs_CZ', 'slug' => 'cs', 'flag' => 'cz', 'name' => 'Čeština' ],
        'cy' => [ 'locale' => 'cy', 'slug' => 'cy', 'flag' => 'gb', 'name' => 'Cymraeg' ],
        'da' => [ 'locale' => 'da_DK', 'slug' => 'da', 'flag' => 'dk', 'name' => 'Dansk' ],
        'de' => [ 'locale' => 'de_DE', 'slug' => 'de', 'flag' => 'de', 'name' => 'Deutsch' ],
        'el' => [ 'locale' => 'el', 'slug' => 'el', 'flag' => 'gr', 'name' => 'Ελληνικά' ],
        'en' => [ 'locale' => 'en_US', 'slug' => 'en', 'flag' => 'us', 'name' => 'English' ],
        'eo' => [ 'locale' => 'eo', 'slug' => 'eo', 'flag' => 'eo', 'name' => 'Esperanto' ],
        'es' => [ 'locale' => 'es_ES', 'slug' => 'es', 'flag' => 'es', 'name' => 'Español' ],
        'et' => [ 'locale' => 'et', 'slug' => 'et', 'flag' => 'ee', 'name' => 'Eesti' ],
        'eu' => [ 'locale' => 'eu', 'slug' => 'eu', 'flag' => 'es', 'name' => 'Euskara' ],
        'fa' => [ 'locale' => 'fa_IR', 'slug' => 'fa', 'flag' => 'ir', 'name' => 'فارسی' ],
        'fi' => [ 'locale' => 'fi', 'slug' => 'fi', 'flag' => 'fi', 'name' => 'Suomi' ],
        'fr' => [ 'locale' => 'fr_FR', 'slug' => 'fr', 'flag' => 'fr', 'name' => 'Français' ],
        'ga' => [ 'locale' => 'ga', 'slug' => 'ga', 'flag' => 'ie', 'name' => 'Gaeilge' ],
        'gl' => [ 'locale' => 'gl_ES', 'slug' => 'gl', 'flag' => 'es', 'name' => 'Galego' ],
        'he' => [ 'locale' => 'he_IL', 'slug' => 'he', 'flag' => 'il', 'name' => 'עברית' ],
        'hi' => [ 'locale' => 'hi_IN', 'slug' => 'hi', 'flag' => 'in', 'name' => 'हिन्दी' ],
        'hr' => [ 'locale' => 'hr', 'slug' => 'hr', 'flag' => 'hr', 'name' => 'Hrvatski' ],
        'hu' => [ 'locale' => 'hu_HU', 'slug' => 'hu', 'flag' => 'hu', 'name' => 'Magyar' ],
        'hy' => [ 'locale' => 'hy', 'slug' => 'hy', 'flag' => 'am', 'name' => 'Հայերեն' ],
        'id' => [ 'locale' => 'id_ID', 'slug' => 'id', 'flag' => 'id', 'name' => 'Bahasa Indonesia' ],
        'is' => [ 'locale' => 'is_IS', 'slug' => 'is', 'flag' => 'is', 'name' => 'Íslenska' ],
        'it' => [ 'locale' => 'it_IT', 'slug' => 'it', 'flag' => 'it', 'name' => 'Italiano' ],
        'ja' => [ 'locale' => 'ja', 'slug' => 'ja', 'flag' => 'jp', 'name' => '日本語' ],
        'ka' => [ 'locale' => 'ka_GE', 'slug' => 'ka', 'flag' => 'ge', 'name' => 'ქართული' ],
        'kk' => [ 'locale' => 'kk', 'slug' => 'kk', 'flag' => 'kz', 'name' => 'Қазақ тілі' ],
        'ko' => [ 'locale' => 'ko_KR', 'slug' => 'ko', 'flag' => 'kr', 'name' => '한국어' ],
        'lt' => [ 'locale' => 'lt_LT', 'slug' => 'lt', 'flag' => 'lt', 'name' => 'Lietuvių kalba' ],
        'lv' => [ 'locale' => 'lv', 'slug' => 'lv', 'flag' => 'lv', 'name' => 'Latviešu valoda' ],
        'mk' => [ 'locale' => 'mk_MK', 'slug' => 'mk', 'flag' => 'mk', 'name' => 'Македонски' ],
        'mn' => [ 'locale' => 'mn', 'slug' => 'mn', 'flag' => 'mn', 'name' => 'Монгол' ],
        'ms' => [ 'locale' => 'ms_MY', 'slug' => 'ms', 'flag' => 'my', 'name' => 'Bahasa Melayu' ],
        'mt' => [ 'locale' => 'mt_MT', 'slug' => 'mt', 'flag' => 'mt', 'name' => 'Malti' ],
        'nb' => [ 'locale' => 'nb_NO', 'slug' => 'no', 'flag' => 'no', 'name' => 'Norsk bokmål' ],
        'ne' => [ 'locale' => 'ne_NP', 'slug' => 'ne', 'flag' => 'np', 'name' => 'नेपाली' ],
        'nl' => [ 'locale' => 'nl_NL', 'slug' => 'nl', 'flag' => 'nl', 'name' => 'Nederlands' ],
        'pa' => [ 'locale' => 'pa_IN', 'slug' => 'pa', 'flag' => 'in', 'name' => 'ਪੰਜਾਬੀ' ],
        'pl' => [ 'locale' => 'pl_PL', 'slug' => 'pl', 'flag' => 'pl', 'name' => 'Polski' ],
        'pt-br' => [ 'locale' => 'pt_BR', 'slug' => 'pt-br', 'flag' => 'br', 'name' => 'Português do Brasil' ],
        'pt-pt' => [ 'locale' => 'pt_PT', 'slug' => 'pt', 'flag' => 'pt', 'name' => 'Português' ],
        'ro' => [ 'locale' => 'ro_RO', 'slug' => 'ro', 'flag' => 'ro', 'name' => 'Română' ],
        'ru' => [ 'locale' => 'ru_RU', 'slug' => 'ru', 'flag' => 'ru', 'name' => 'Русский' ],
        'sk' => [ 'locale' => 'sk_SK', 'slug' => 'sk', 'flag' => 'sk', 'name' => 'Slovenčina' ],
        'sl' => [ 'locale' => 'sl_SI', 'slug' => 'sl', 'flag' => 'si', 'name' => 'Slovenščina' ],
        'sq' => [ 'locale' => 'sq', 'slug' => 'sq', 'flag' => 'al', 'name' => 'Shqip' ],
        'sr' => [ 'locale' => 'sr_RS', 'slug' => 'sr', 'flag' => 'rs', 'name' => 'Српски језик' ],
        'sv' => [ 'locale' => 'sv_SE', 'slug' => 'sv', 'flag' => 'se', 'name' => 'Svenska' ],
        'ta' => [ 'locale' => 'ta_IN', 'slug' => 'ta', 'flag' => 'in', 'name' => 'தமிழ்' ],
        'te' => [ 'locale' => 'te', 'slug' => 'te', 'flag' => 'in', 'name' => 'తెలుగు' ],
        'th' => [ 'locale' => 'th', 'slug' => 'th', 'flag' => 'th', 'name' => 'ไทย' ],
        'tr' => [ 'locale' => 'tr_TR', 'slug' => 'tr', 'flag' => 'tr', 'name' => 'Türkçe' ],
        'uk' => [ 'locale' => 'uk', 'slug' => 'ua', 'flag' => 'ua', 'name' => 'Українська' ],
        'ur' => [ 'locale' => 'ur', 'slug' => 'ur', 'flag' => 'pk', 'name' => 'اردو' ],
        'uz' => [ 'locale' => 'uz_UZ', 'slug' => 'uz', 'flag' => 'uz', 'name' => 'O‘zbekcha' ],
        'vi' => [ 'locale' => 'vi', 'slug' => 'vi', 'flag' => 'vn', 'name' => 'Tiếng Việt' ],
        'zh-hans' => [ 'locale' => 'zh_CN', 'slug' => 'zh', 'flag' => 'cn', 'name' => '简体中文' ],
        'zh-hant' => [ 'locale' => 'zh_TW', 'slug' => 'zh-tw', 'flag' => 'tw', 'name' => '繁體中文' ],
    ];

    private static $aliases = [
        'iw' => 'he',
        'no' => 'nb',
        'pt' => 'pt-pt',
        'pt_br' => 'pt-br',
        'pt-br' => 'pt-br',
        'pt_pt' => 'pt-pt',
        'pt-pt' => 'pt-pt',
        'zh' => 'zh-hans',
        'zh_cn' => 'zh-hans',
        'zh-cn' => 'zh-hans',
        'zh_hans' => 'zh-hans',
        'zh-hans' => 'zh-hans',
        'zh_tw' => 'zh-hant',
        'zh-tw' => 'zh-hant',
        'zh_hant' => 'zh-hant',
        'zh-hant' => 'zh-hant',
    ];

    public static function normalize_external_language( string $code, string $locale = '', string $display_name = '' ): array {
        $source_code = self::normalize_code( $code );
        $locale = self::normalize_locale( $locale );
        $display_name = trim( sanitize_text_field( $display_name ) );
        $match = self::find_language( $source_code, $locale );

        $resolved_locale = $locale ?: ( $match['locale'] ?? str_replace( '-', '_', $source_code ) );
        $slug = $match['slug'] ?? self::slug_from_locale( $resolved_locale ?: $source_code );
        $name = $display_name ?: ( $match['name'] ?? self::display_name_from_locale( $resolved_locale ?: $source_code ) );
        $flag = $match['flag'] ?? self::flag_from_locale( $resolved_locale ?: $slug );
        $confidence = $match['confidence'] ?? 'fallback';

        if ( $locale && $match && $locale === ( $match['locale'] ?? '' ) ) {
            $confidence = 'exact';
        } elseif ( $match && $source_code && $slug !== $source_code ) {
            $confidence = 'normalized';
        }

        return [
            'source_code' => $source_code,
            'code'        => sanitize_key( $slug ),
            'wpml_code'   => self::wpml_code_from_slug( $slug ),
            'locale'      => $resolved_locale,
            'display_name' => $name,
            'flag'        => sanitize_key( $flag ),
            'confidence'  => $confidence,
        ];
    }

    public static function slug_from_locale( string $locale ): string {
        $normalized_locale = self::normalize_locale( $locale );
        $match = self::find_language( '', $normalized_locale );

        if ( $match && ! empty( $match['slug'] ) ) {
            return sanitize_key( $match['slug'] );
        }

        return sanitize_key( strtolower( str_replace( '_', '-', substr( $normalized_locale ?: $locale, 0, strpos( $normalized_locale ?: $locale, '_' ) ?: 2 ) ) ) );
    }

    public static function flag_from_locale( string $locale ): string {
        $normalized_locale = self::normalize_locale( $locale );
        $match = self::find_language( '', $normalized_locale );

        if ( $match && ! empty( $match['flag'] ) ) {
            return sanitize_key( $match['flag'] );
        }

        if ( strpos( $normalized_locale, '_' ) !== false ) {
            [ , $country ] = explode( '_', $normalized_locale, 2 );
            return sanitize_key( strtolower( $country ) );
        }

        $slug = self::slug_from_locale( $normalized_locale ?: $locale );
        $exceptions = [ 'en' => 'us', 'ua' => 'ua', 'uk' => 'ua', 'he' => 'il', 'kk' => 'kz' ];

        return $exceptions[ $slug ] ?? $slug;
    }

    public static function get_languages(): array {
        return apply_filters( 'wp_loc_language_registry', self::$languages );
    }

    public static function get_language_options(): array {
        $options = [];

        foreach ( self::get_languages() as $code => $language ) {
            $slug = sanitize_key( (string) ( $language['slug'] ?? $code ) );
            if ( ! $slug ) {
                continue;
            }

            $options[ $slug ] = [
                'code'         => $slug,
                'registry_code'=> sanitize_key( (string) $code ),
                'wpml_code'    => sanitize_key( (string) $code ),
                'locale'       => sanitize_text_field( (string) ( $language['locale'] ?? $slug ) ),
                'display_name' => sanitize_text_field( (string) ( $language['name'] ?? strtoupper( $slug ) ) ),
                'flag'         => sanitize_key( (string) ( $language['flag'] ?? self::flag_from_locale_without_translation_lookup( (string) ( $language['locale'] ?? $slug ) ) ) ),
            ];
        }

        uasort( $options, static fn( array $a, array $b ): int => strcasecmp( $a['display_name'], $b['display_name'] ) );

        return array_values( $options );
    }

    private static function find_language( string $code, string $locale ): ?array {
        $languages = self::get_languages();
        $canonical_code = self::canonical_code( $code );

        if ( $canonical_code && isset( $languages[ $canonical_code ] ) ) {
            return $languages[ $canonical_code ] + [ 'confidence' => 'code' ];
        }

        if ( $locale ) {
            foreach ( $languages as $language ) {
                if ( isset( $language['locale'] ) && strtolower( $language['locale'] ) === strtolower( $locale ) ) {
                    return $language + [ 'confidence' => 'locale' ];
                }
            }
        }

        return self::find_wordpress_translation( $code, $locale );
    }

    private static function find_wordpress_translation( string $code, string $locale ): ?array {
        $translations = self::get_wordpress_translations();
        $candidates = array_filter( array_unique( [ $locale, str_replace( '-', '_', $code ), $code ] ) );

        foreach ( $candidates as $candidate ) {
            if ( isset( $translations[ $candidate ] ) ) {
                return self::language_from_translation( $translations[ $candidate ] );
            }
        }

        foreach ( $translations as $translation ) {
            $language = (string) ( $translation['language'] ?? '' );
            if ( $code && str_starts_with( strtolower( $language ), strtolower( $code ) . '_' ) ) {
                return self::language_from_translation( $translation );
            }
        }

        return null;
    }

    private static function language_from_translation( array $translation ): array {
        $locale = (string) ( $translation['language'] ?? '' );

        return [
            'locale' => $locale,
            'slug' => self::slug_from_locale_without_translation_lookup( $locale ),
            'flag' => self::flag_from_locale_without_translation_lookup( $locale ),
            'name' => (string) ( $translation['native_name'] ?? $locale ),
            'confidence' => 'wordpress',
        ];
    }

    private static function display_name_from_locale( string $locale ): string {
        $translations = self::get_wordpress_translations();

        if ( isset( $translations[ $locale ] ) ) {
            return (string) ( $translations[ $locale ]['native_name'] ?? $locale );
        }

        return ucfirst( str_replace( [ '_', '-' ], ' ', $locale ) );
    }

    private static function get_wordpress_translations(): array {
        if ( ! function_exists( 'wp_get_available_translations' ) ) {
            require_once ABSPATH . 'wp-admin/includes/translation-install.php';
        }

        return wp_get_available_translations();
    }

    private static function normalize_code( string $code ): string {
        return sanitize_key( strtolower( str_replace( '_', '-', trim( $code ) ) ) );
    }

    private static function normalize_locale( string $locale ): string {
        $locale = trim( $locale );

        return preg_match( '/^[a-z]{2,3}[_-][A-Za-z]{2,4}$/', $locale )
            ? preg_replace_callback( '/^([a-z]{2,3})[_-]([A-Za-z]{2,4})$/', static fn( array $matches ): string => strtolower( $matches[1] ) . '_' . strtoupper( $matches[2] ), $locale )
            : $locale;
    }

    private static function canonical_code( string $code ): string {
        return self::$aliases[ $code ] ?? $code;
    }

    public static function wpml_code_from_slug( string $slug ): string {
        $slug = sanitize_key( strtolower( trim( $slug ) ) );
        $languages = self::get_languages();

        foreach ( $languages as $code => $language ) {
            if ( sanitize_key( (string) ( $language['slug'] ?? $code ) ) === $slug ) {
                return sanitize_key( (string) $code );
            }
        }

        return sanitize_key( self::canonical_code( $slug ) ?: $slug );
    }

    public static function wpml_code_from_locale( string $locale ): string {
        $locale = self::normalize_locale( $locale );
        $languages = self::get_languages();

        foreach ( $languages as $code => $language ) {
            if ( strtolower( (string) ( $language['locale'] ?? '' ) ) === strtolower( $locale ) ) {
                return sanitize_key( (string) $code );
            }
        }

        $base = strtolower( strtok( $locale, '_' ) ?: $locale );

        return sanitize_key( self::canonical_code( self::normalize_code( $base ) ) ?: $base );
    }

    public static function slug_from_wpml_code( string $code ): string {
        $code = self::canonical_code( self::normalize_code( $code ) );
        $languages = self::get_languages();

        if ( isset( $languages[ $code ] ) ) {
            return sanitize_key( (string) ( $languages[ $code ]['slug'] ?? $code ) );
        }

        return sanitize_key( $code );
    }

    private static function slug_from_locale_without_translation_lookup( string $locale ): string {
        $locale = self::normalize_locale( $locale );
        $code = self::normalize_code( str_replace( '_', '-', $locale ) );
        $canonical = self::canonical_code( $code );
        $languages = self::get_languages();

        if ( isset( $languages[ $canonical ]['slug'] ) ) {
            return sanitize_key( $languages[ $canonical ]['slug'] );
        }

        $base = strtolower( strtok( $locale, '_' ) ?: $locale );
        $base = self::canonical_code( self::normalize_code( $base ) );

        return isset( $languages[ $base ]['slug'] ) ? sanitize_key( $languages[ $base ]['slug'] ) : sanitize_key( $base );
    }

    private static function flag_from_locale_without_translation_lookup( string $locale ): string {
        if ( strpos( $locale, '_' ) !== false ) {
            [ , $country ] = explode( '_', $locale, 2 );
            return sanitize_key( strtolower( $country ) );
        }

        $slug = self::slug_from_locale_without_translation_lookup( $locale );
        $languages = self::get_languages();

        foreach ( $languages as $language ) {
            if ( ( $language['slug'] ?? '' ) === $slug && ! empty( $language['flag'] ) ) {
                return sanitize_key( $language['flag'] );
            }
        }

        return $slug;
    }
}
