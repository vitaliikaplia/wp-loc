<?php

if ( ! defined( 'ABSPATH' ) ) exit;

class WP_LOC_CLI {

    /**
     * Translate a single post into a target language.
     *
     * ## OPTIONS
     *
     * <post-id>
     * : Source post ID.
     *
     * --to=<lang>
     * : Target WP-LOC language slug.
     *
     * [--provider=<provider>]
     * : openai, claude, gemini, or chrome_ai. Defaults to saved settings.
     *
     * [--fields=<fields>]
     * : Comma-separated fields: title,content,excerpt.
     *
     * [--status=<status>]
     * : Status for newly created translation drafts. Default: draft.
     */
    public function post( array $args, array $assoc_args ): void {
        $post_id = isset( $args[0] ) ? (int) $args[0] : 0;
        $target_lang = isset( $assoc_args['to'] ) ? sanitize_key( (string) $assoc_args['to'] ) : '';

        if ( ! $post_id || ! $target_lang ) {
            \WP_CLI::error( 'Usage: wp wp-loc translate post <post-id> --to=<lang> [--provider=<provider>]' );
        }

        $result = $this->service()->translate_post( $post_id, $target_lang, $this->build_args( $assoc_args ) );
        $this->report_result( $result );
    }

    /**
     * Translate multiple posts selected by WP_Query args.
     *
     * ## OPTIONS
     *
     * --to=<lang>
     * : Target WP-LOC language slug.
     *
     * [--source-lang=<lang>]
     * : Only select posts registered in this source language.
     *
     * [--post_type=<type>]
     * : Source post type. Default: post.
     *
     * [--post__in=<ids>]
     * : Comma-separated source post IDs.
     *
     * [--posts_per_page=<number>]
     * : Limit posts. Default: 5.
     *
     * [--provider=<provider>]
     * : openai, claude, gemini, or chrome_ai.
     *
     * [--fields=<fields>]
     * : Comma-separated fields: title,content,excerpt.
     */
    public function posts( array $args, array $assoc_args ): void {
        $target_lang = isset( $assoc_args['to'] ) ? sanitize_key( (string) $assoc_args['to'] ) : '';

        if ( ! $target_lang ) {
            \WP_CLI::error( 'Missing --to=<lang>.' );
        }

        $query_args = [
            'post_type' => isset( $assoc_args['post_type'] ) ? sanitize_key( (string) $assoc_args['post_type'] ) : 'post',
            'post_status' => [ 'publish', 'draft', 'pending', 'private', 'future' ],
            'posts_per_page' => isset( $assoc_args['posts_per_page'] ) ? max( 1, (int) $assoc_args['posts_per_page'] ) : 5,
            'orderby' => 'date',
            'order' => 'DESC',
            'fields' => 'ids',
            'suppress_filters' => true,
        ];

        if ( ! empty( $assoc_args['post__in'] ) ) {
            $query_args['post__in'] = array_values( array_filter( array_map( 'intval', explode( ',', (string) $assoc_args['post__in'] ) ) ) );
            $query_args['orderby'] = 'post__in';
            $query_args['posts_per_page'] = count( $query_args['post__in'] );
        }

        $source_lang = isset( $assoc_args['source-lang'] ) ? sanitize_key( (string) $assoc_args['source-lang'] ) : '';
        $post_ids = get_posts( $query_args );

        if ( $source_lang ) {
            $post_ids = array_values( array_filter( $post_ids, function( $post_id ) use ( $source_lang, $query_args ) {
                return WP_LOC::instance()->db->get_element_language( (int) $post_id, WP_LOC_DB::post_element_type( (string) $query_args['post_type'] ) ) === $source_lang;
            } ) );
        }

        if ( empty( $post_ids ) ) {
            \WP_CLI::warning( 'No source posts found.' );
            return;
        }

        $ok = 0;
        $queued = 0;

        foreach ( $post_ids as $post_id ) {
            $result = $this->service()->translate_post( (int) $post_id, $target_lang, $this->build_args( $assoc_args ) );

            if ( is_wp_error( $result ) ) {
                \WP_CLI::warning( sprintf( '#%d: %s', (int) $post_id, $result->get_error_message() ) );
                continue;
            }

            $ok++;
            $queued += ! empty( $result['queued'] ) ? 1 : 0;
            \WP_CLI::log( sprintf( '#%d -> #%d%s', (int) $result['source_id'], (int) $result['target_id'], ! empty( $result['queued'] ) ? ' queued for Chrome AI' : ' translated' ) );
        }

        \WP_CLI::success( sprintf( 'Processed %d posts. %d queued for Chrome AI.', $ok, $queued ) );
    }

    /**
     * Show Chrome AI translation queue status.
     */
    public function queue( array $args, array $assoc_args ): void {
        $counts = WP_LOC_Translation_Service::get_queue_counts();
        \WP_CLI::log( sprintf( 'Pending: %d', (int) $counts['pending'] ) );
        \WP_CLI::log( sprintf( 'Completed: %d', (int) $counts['completed'] ) );
        \WP_CLI::log( 'Open Multilingual > Tools > AI Translation in Chrome and click "Process Chrome AI queue".' );
    }

    private function service(): WP_LOC_Translation_Service {
        return WP_LOC::instance()->translation_service;
    }

    private function build_args( array $assoc_args ): array {
        return [
            'provider' => isset( $assoc_args['provider'] ) ? sanitize_key( (string) $assoc_args['provider'] ) : WP_LOC_Admin_Settings::get_ai_engine(),
            'fields' => isset( $assoc_args['fields'] ) ? explode( ',', (string) $assoc_args['fields'] ) : [ 'title', 'content', 'excerpt' ],
            'status' => isset( $assoc_args['status'] ) ? sanitize_key( (string) $assoc_args['status'] ) : 'draft',
            'source_lang' => isset( $assoc_args['source-lang'] ) ? sanitize_key( (string) $assoc_args['source-lang'] ) : '',
        ];
    }

    private function report_result( $result ): void {
        if ( is_wp_error( $result ) ) {
            \WP_CLI::error( $result->get_error_message() );
        }

        if ( ! empty( $result['queued'] ) ) {
            \WP_CLI::success( sprintf( 'Created/copied target post #%d and queued it for Chrome AI translation.', (int) $result['target_id'] ) );
            return;
        }

        \WP_CLI::success( sprintf( 'Translated source post #%d into target post #%d.', (int) $result['source_id'], (int) $result['target_id'] ) );
    }
}
