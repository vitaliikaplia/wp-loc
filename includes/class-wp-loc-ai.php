<?php

if ( ! defined( 'ABSPATH' ) ) exit;

class WP_LOC_AI {

    public static function test_provider( string $provider, string $api_key, ?string $model = null ) {
        $provider = sanitize_key( $provider );
        $api_key = trim( $api_key );
        $model = is_string( $model ) ? trim( $model ) : null;

        if ( $api_key === '' ) {
            return new WP_Error( 'wp_loc_ai_missing_api_key', __( 'API key is empty.', 'wp-loc' ) );
        }

        $prompt = 'Reply with OK only.';

        $response = match ( $provider ) {
            'claude' => self::get_claude_response( $prompt, null, $api_key, $model ),
            'gemini' => self::get_gemini_response( $prompt, null, $api_key, $model ),
            default  => self::get_openai_response( $prompt, null, $api_key, $model ),
        };

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        return [
            'provider' => $provider,
            'message'  => __( 'Connection successful.', 'wp-loc' ),
        ];
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
        $engine = WP_LOC_Admin_Settings::get_ai_engine();

        return match ( $engine ) {
            'claude' => self::get_claude_response( $prompt, $system ),
            'gemini' => self::get_gemini_response( $prompt, $system ),
            default  => self::get_openai_response( $prompt, $system ),
        };
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

        return trim( (string) $result );
    }

    private static function should_retry_same_text_translation( string $source, string $result ): bool {
        $normalize = static function( string $value ): string {
            $value = wp_strip_all_tags( $value );
            $value = html_entity_decode( $value, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
            $value = preg_replace( '/\s+/u', ' ', $value );

            return trim( mb_strtolower( (string) $value ) );
        };

        $normalized_source = $normalize( $source );
        $normalized_result = $normalize( $result );

        if ( $normalized_source === '' || $normalized_result === '' ) {
            return false;
        }

        return $normalized_source === $normalized_result;
    }


    public static function get_openai_response( string $prompt, ?string $system = null, ?string $api_key_override = null, ?string $model_override = null ) {
        $api_key = $api_key_override ?: WP_LOC_Admin_Settings::get_openai_api_key();
        $model = $model_override ?: WP_LOC_Admin_Settings::get_openai_model();

        if ( ! $api_key || ! $prompt ) {
            return new WP_Error( 'wp_loc_ai_openai_config', __( 'OpenAI is not configured.', 'wp-loc' ) );
        }

        $payload = [
            'model'             => $model,
            'input'             => $prompt,
            'max_output_tokens' => 2048,
        ];

        if ( $system ) {
            $payload['instructions'] = $system;
        }

        $response = wp_remote_post(
            'https://api.openai.com/v1/responses',
            [
                'headers' => [
                    'Content-Type'  => 'application/json',
                    'Authorization' => 'Bearer ' . $api_key,
                ],
                'body'    => wp_json_encode( $payload, JSON_UNESCAPED_UNICODE ),
                'timeout' => 45,
            ]
        );

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        $code = wp_remote_retrieve_response_code( $response );
        $body = wp_remote_retrieve_body( $response );
        $data = json_decode( $body, true );

        if ( $code !== 200 ) {
            $message = $data['error']['message'] ?? ( 'OpenAI HTTP ' . $code );
            return new WP_Error( 'wp_loc_ai_openai_http', $message, [ 'status' => $code, 'body' => $body ] );
        }

        if ( ! empty( $data['output'][0]['content'] ) && is_array( $data['output'][0]['content'] ) ) {
            foreach ( $data['output'][0]['content'] as $content_part ) {
                if ( isset( $content_part['type'], $content_part['text'] ) && $content_part['type'] === 'output_text' ) {
                    return $content_part['text'];
                }
            }
        }

        return new WP_Error( 'wp_loc_ai_openai_payload', __( 'OpenAI returned an empty response.', 'wp-loc' ) );
    }

    public static function get_claude_response( string $prompt, ?string $system = null, ?string $api_key_override = null, ?string $model_override = null ) {
        $api_key = $api_key_override ?: WP_LOC_Admin_Settings::get_claude_api_key();
        $model = $model_override ?: WP_LOC_Admin_Settings::get_claude_model();

        if ( ! $api_key || ! $prompt ) {
            return new WP_Error( 'wp_loc_ai_claude_config', __( 'Claude is not configured.', 'wp-loc' ) );
        }

        $payload = [
            'model' => $model,
            'max_tokens' => 2048,
            'messages' => [
                [
                    'role' => 'user',
                    'content' => $prompt,
                ],
            ],
        ];

        if ( $system ) {
            $payload['system'] = $system;
        }

        $response = wp_remote_post(
            'https://api.anthropic.com/v1/messages',
            [
                'headers' => [
                    'Content-Type' => 'application/json',
                    'x-api-key' => $api_key,
                    'anthropic-version' => '2023-06-01',
                ],
                'body' => wp_json_encode( $payload, JSON_UNESCAPED_UNICODE ),
                'timeout' => 45,
            ]
        );

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        $code = wp_remote_retrieve_response_code( $response );
        $body = wp_remote_retrieve_body( $response );
        $data = json_decode( $body, true );

        if ( $code !== 200 ) {
            $message = $data['error']['message'] ?? ( 'Claude HTTP ' . $code );
            return new WP_Error( 'wp_loc_ai_claude_http', $message, [ 'status' => $code, 'body' => $body ] );
        }

        return $data['content'][0]['text'] ?? new WP_Error( 'wp_loc_ai_claude_payload', __( 'Claude returned an empty response.', 'wp-loc' ) );
    }

    public static function get_gemini_response( string $prompt, ?string $system = null, ?string $api_key_override = null, ?string $model_override = null ) {
        $api_key = $api_key_override ?: WP_LOC_Admin_Settings::get_gemini_api_key();
        $model = $model_override ?: WP_LOC_Admin_Settings::get_gemini_model();

        if ( ! $api_key || ! $prompt ) {
            return new WP_Error( 'wp_loc_ai_gemini_config', __( 'Gemini is not configured.', 'wp-loc' ) );
        }

        $text = $system ? $system . "\n\n" . $prompt : $prompt;
        $payload = [
            'contents' => [
                [
                    'role' => 'user',
                    'parts' => [
                        [ 'text' => $text ],
                    ],
                ],
            ],
            'generationConfig' => [
                'maxOutputTokens' => 2048,
            ],
        ];

        $response = wp_remote_post(
            'https://generativelanguage.googleapis.com/v1beta/models/' . rawurlencode( $model ) . ':generateContent?key=' . rawurlencode( $api_key ),
            [
                'headers' => [
                    'Content-Type' => 'application/json',
                ],
                'body' => wp_json_encode( $payload, JSON_UNESCAPED_UNICODE ),
                'timeout' => 45,
            ]
        );

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        $code = wp_remote_retrieve_response_code( $response );
        $body = wp_remote_retrieve_body( $response );
        $data = json_decode( $body, true );

        if ( $code !== 200 ) {
            $message = $data['error']['message'] ?? ( 'Gemini HTTP ' . $code );
            return new WP_Error( 'wp_loc_ai_gemini_http', $message, [ 'status' => $code, 'body' => $body ] );
        }

        return $data['candidates'][0]['content']['parts'][0]['text'] ?? new WP_Error( 'wp_loc_ai_gemini_payload', __( 'Gemini returned an empty response.', 'wp-loc' ) );
    }
}
