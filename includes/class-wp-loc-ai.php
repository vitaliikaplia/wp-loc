<?php

if ( ! defined( 'ABSPATH' ) ) exit;

class WP_LOC_AI {

    public static function is_core_ai_available(): bool {
        return function_exists( 'wp_ai_client_prompt' )
            && class_exists( '\\WordPress\\AiClient\\AiClient' );
    }

    public static function get_connected_providers(): array {
        if ( ! self::is_core_ai_available() ) {
            return [];
        }

        try {
            $registry = \WordPress\AiClient\AiClient::defaultRegistry();
            $providers = [];

            foreach ( $registry->getRegisteredProviderIds() as $provider_id ) {
                if ( ! $registry->isProviderConfigured( $provider_id ) ) {
                    continue;
                }

                $provider_class = $registry->getProviderClassName( $provider_id );
                $metadata = $provider_class::metadata();
                $providers[ $provider_id ] = $metadata->getName();
            }

            return $providers;
        } catch ( \Throwable $exception ) {
            return [];
        }
    }

    public static function get_provider_models( string $provider_id ): array {
        $provider_id = sanitize_key( $provider_id );

        if ( $provider_id === '' || ! self::is_core_ai_available() ) {
            return [];
        }

        try {
            $registry = \WordPress\AiClient\AiClient::defaultRegistry();

            if ( ! $registry->hasProvider( $provider_id ) || ! $registry->isProviderConfigured( $provider_id ) ) {
                return [];
            }

            $requirements = new \WordPress\AiClient\Providers\Models\DTO\ModelRequirements(
                [ \WordPress\AiClient\Providers\Models\Enums\CapabilityEnum::textGeneration() ],
                []
            );
            $models = [];

            foreach ( $registry->findProviderModelsMetadataForSupport( $provider_id, $requirements ) as $metadata ) {
                $models[ $metadata->getId() ] = $metadata->getName();
            }

            return $models;
        } catch ( \Throwable $exception ) {
            return [];
        }
    }

    public static function get_target_language_name( string $lang ): string {
        $normalized = sanitize_key( $lang );
        $locale = WP_LOC_Languages::get_language_locale( $normalized );

        $map = [
            'ua' => 'Ukrainian',
            'uk' => 'Ukrainian',
            'uk_ua' => 'Ukrainian',
            'en' => 'English',
            'en_us' => 'English',
            'en_gb' => 'English',
            'ru' => 'Russian',
            'ru_ru' => 'Russian',
        ];

        if ( isset( $map[ $normalized ] ) ) {
            return $map[ $normalized ];
        }

        $locale_key = strtolower( str_replace( '-', '_', $locale ) );

        if ( isset( $map[ $locale_key ] ) ) {
            return $map[ $locale_key ];
        }

        return WP_LOC_Languages::get_language_display_name( $locale );
    }

    public static function get_response( string $prompt, ?string $system = null ) {
        if ( trim( $prompt ) === '' ) {
            return new WP_Error( 'wp_loc_ai_empty_prompt', __( 'The AI prompt is empty.', 'wp-loc' ) );
        }

        if ( ! self::is_core_ai_available() ) {
            return new WP_Error( 'wp_loc_ai_client_unavailable', __( 'AI translation requires WordPress 7.0 or newer.', 'wp-loc' ) );
        }

        $provider_id = WP_LOC_Admin_Settings::get_ai_engine();

        if ( $provider_id === '' ) {
            return new WP_Error( 'wp_loc_ai_provider_unavailable', __( 'No connected AI provider is available.', 'wp-loc' ) );
        }

        $model_id = WP_LOC_Admin_Settings::get_ai_model( $provider_id );

        if ( $model_id === '' ) {
            return new WP_Error( 'wp_loc_ai_model_unavailable', __( 'No text-generation model is available for the selected AI provider.', 'wp-loc' ) );
        }

        try {
            $registry = \WordPress\AiClient\AiClient::defaultRegistry();
            $model = $registry->getProviderModel( $provider_id, $model_id );
            $builder = wp_ai_client_prompt( $prompt )->using_model( $model );

            if ( is_string( $system ) && trim( $system ) !== '' ) {
                $builder->using_system_instruction( $system );
            }

            $response = $builder->generate_text();
        } catch ( \Throwable $exception ) {
            return new WP_Error( 'wp_loc_ai_request_failed', $exception->getMessage() );
        }

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        if ( ! is_string( $response ) || trim( $response ) === '' ) {
            return new WP_Error( 'wp_loc_ai_empty_response', __( 'The AI provider returned an empty response.', 'wp-loc' ) );
        }

        return $response;
    }

    public static function translate_content( string $content, string $target_lang ): string {
        $prompt = sprintf(
            'Translate the following content into natural %1$s. The source may be a short CTA, menu label, button text, sentence, or HTML fragment. Always translate the text itself when possible, even if it is very short. Preserve all HTML formatting and structure exactly when it exists. Do not add explanations. Return only the translated result wrapped in <result></result>. Content: %2$s',
            $target_lang,
            $content
        );

        $result = self::run_translation_prompt( $prompt );

        if ( self::should_retry_same_text_translation( $content, $result ) ) {
            $retry_prompt = sprintf(
                'Translate this content into %1$s. Do not keep the original wording unchanged unless it is a proper name, URL, or brand that should stay identical. Preserve existing HTML exactly. Return only the translated result wrapped in <result></result>. Content: %2$s',
                $target_lang,
                $content
            );

            $retry_result = self::run_translation_prompt( $retry_prompt );

            if ( $retry_result !== '' ) {
                $result = $retry_result;
            }
        }

        return $result;
    }

    private static function is_probable_refusal_response( string $result ): bool {
        $normalized = trim( wp_strip_all_tags( html_entity_decode( $result, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) ) );

        if ( $normalized === '' ) {
            return false;
        }

        $normalized = self::lowercase( (string) preg_replace( '/\s+/u', ' ', $normalized ) );

        $patterns = [
            "i'm sorry",
            'i’m sorry',
            'sorry, i can',
            'i cannot assist',
            "i can't assist",
            'i can’t assist',
            'i cannot help',
            "i can't help",
            'i can’t help',
            "i'm unable to",
            'i’m unable to',
            'as an ai',
            'i cannot comply',
            "i can't comply",
            'i can’t comply',
        ];

        foreach ( $patterns as $pattern ) {
            if ( str_contains( $normalized, $pattern ) ) {
                return true;
            }
        }

        return false;
    }

    private static function run_translation_prompt( string $prompt ): string {
        $response = self::get_response( $prompt );

        if ( is_wp_error( $response ) || ! is_string( $response ) ) {
            return '';
        }

        if ( preg_match( '/<result>(.*?)<\/result>/is', $response, $matches ) ) {
            $result = $matches[1];
        } else {
            $result = $response;
        }

        $result = preg_replace( '/^<p>\s*```+\s*html\s*<\/p>\s*/i', '', $result );
        $result = preg_replace( '/^<p>\s*~~~+\s*html\s*<\/p>\s*/i', '', $result );
        $result = preg_replace( '/\s*<p>\s*```+\s*<\/p>$/i', '', $result );
        $result = preg_replace( '/\s*<p>\s*~~~+\s*<\/p>$/i', '', $result );
        $result = preg_replace( '/^```+\s*html\s*/i', '', $result );
        $result = preg_replace( '/^~~~+\s*html\s*/i', '', $result );
        $result = preg_replace( '/\s*```+\s*$/i', '', $result );
        $result = preg_replace( '/\s*~~~+\s*$/i', '', $result );
        $result = str_ireplace( [ '<result>', '</result>' ], '', $result );
        $result = stripslashes( $result );

        for ( $i = 0; $i < 3; $i++ ) {
            $result = str_replace( [ '\\&quot;', '\&quot;', '&quot;' ], '"', $result );
            $result = str_replace( [ '\\&amp;', '\&amp;' ], '&', $result );
            $result = str_replace( [ '\\&lt;', '\&lt;' ], '<', $result );
            $result = str_replace( [ '\\&gt;', '\&gt;' ], '>', $result );
            $result = str_replace( [ '\\&apos;', '\&apos;', '&apos;' ], "'", $result );
            $result = str_replace( '\"', '"', $result );
            $result = str_replace( "\\'", "'", $result );
            $result = stripslashes( $result );
        }

        $result = preg_replace_callback( '/\\&([a-z]+);/i', function( $matches ) {
            $entity = '&' . $matches[1] . ';';
            $decoded = html_entity_decode( $entity, ENT_QUOTES | ENT_HTML5, 'UTF-8' );

            return $decoded !== $entity ? $decoded : $entity;
        }, $result );

        if ( self::is_probable_refusal_response( $result ) ) {
            return '';
        }

        return trim( (string) $result );
    }

    private static function should_retry_same_text_translation( string $source, string $result ): bool {
        $normalize = static function( string $value ): string {
            $value = wp_strip_all_tags( $value );
            $value = html_entity_decode( $value, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
            $value = preg_replace( '/\s+/u', ' ', $value );

            return trim( self::lowercase( (string) $value ) );
        };

        $normalized_source = $normalize( $source );
        $normalized_result = $normalize( $result );

        if ( $normalized_source === '' || $normalized_result === '' ) {
            return false;
        }

        return $normalized_source === $normalized_result;
    }

    /**
     * Lowercase for comparisons. WordPress polyfills mb_substr() and mb_strlen() but not
     * mb_strtolower(), so without mbstring only ASCII is folded instead of a fatal error.
     */
    private static function lowercase( string $value ): string {
        return function_exists( 'mb_strtolower' ) ? mb_strtolower( $value ) : strtolower( $value );
    }


}
