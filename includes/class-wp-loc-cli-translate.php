<?php

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Translate posts with the AI provider.
 *
 * wp wp-loc translate post|posts, rebuilt from pull request #1 on the current code: the translation copy comes from
 * WP_LOC_Content::create_translations() (meta, thumbnail, terms, as on save) and the text from
 * WP_LOC_AI::translate_post_fields() (the AI provider set in Connectors, or an add-on answering
 * wp_loc_pre_translate_content). The browser translator cannot run here.
 */
class WP_LOC_CLI_Translate {

	/**
	 * Translate one post into a language with the AI provider.
	 *
	 * Creates the translation copy when it is missing and translates its title, content and
	 * excerpt from the source. An existing translation is left alone unless --force is given,
	 * so edited translations are not overwritten.
	 *
	 * ## OPTIONS
	 *
	 * <post-id>
	 * : Source post ID — the original of its translation group.
	 *
	 * --to=<lang>
	 * : Target language slug.
	 *
	 * [--fields=<fields>]
	 * : Comma-separated fields to translate.
	 * ---
	 * default: title,content,excerpt
	 * ---
	 *
	 * [--force]
	 * : Translate an existing translation again, overwriting its fields.
	 *
	 * ## EXAMPLES
	 *
	 *     wp wp-loc translate post 123 --to=en
	 *     wp wp-loc translate post 123 --to=ru --fields=title --force
	 *
	 * @when after_wp_load
	 */
	public function post( $args, $assoc_args ) {
		$post_id = isset( $args[0] ) ? absint( $args[0] ) : 0;
		$target  = $this->get_target_language( $assoc_args );
		$fields  = $this->get_fields( $assoc_args );
		$force   = (bool) WP_CLI\Utils\get_flag_value( $assoc_args, 'force', false );

		$this->assert_can_translate();

		$result = $this->translate_one( $post_id, $target, $fields, $force );

		if ( is_wp_error( $result ) ) {
			WP_CLI::error( $result->get_error_message() );
		}

		WP_CLI::success( $result );
	}

	/**
	 * Translate many posts into a language with the AI provider.
	 *
	 * For every original (not a translation) of the given types and statuses: create the copy in
	 * --to when it is missing and translate it. Existing translations are skipped unless --force.
	 *
	 * ## OPTIONS
	 *
	 * --to=<lang>
	 * : Target language slug.
	 *
	 * [--post_type=<types>]
	 * : Comma-separated post types. Defaults to every translatable post type.
	 *
	 * [--include=<ids>]
	 * : Comma-separated post IDs to limit the run to.
	 *
	 * [--status=<statuses>]
	 * : Comma-separated source post statuses.
	 * ---
	 * default: publish
	 * ---
	 *
	 * [--fields=<fields>]
	 * : Comma-separated fields to translate.
	 * ---
	 * default: title,content,excerpt
	 * ---
	 *
	 * [--limit=<n>]
	 * : Stop after this many posts were translated. 0 = no limit.
	 * ---
	 * default: 0
	 * ---
	 *
	 * [--force]
	 * : Translate existing translations again, overwriting their fields.
	 *
	 * [--dry-run]
	 * : List what would be translated without calling the AI provider.
	 *
	 * ## EXAMPLES
	 *
	 *     wp wp-loc translate posts --to=en --dry-run
	 *     wp wp-loc translate posts --to=ru --post_type=post --limit=20
	 *     wp wp-loc translate posts --to=en --include=12,15 --fields=title,excerpt
	 *
	 * @when after_wp_load
	 */
	public function posts( $args, $assoc_args ) {
		global $wpdb;

		$db           = WP_LOC::instance()->db;
		$target       = $this->get_target_language( $assoc_args );
		$fields       = $this->get_fields( $assoc_args );
		$force        = (bool) WP_CLI\Utils\get_flag_value( $assoc_args, 'force', false );
		$dry_run      = (bool) WP_CLI\Utils\get_flag_value( $assoc_args, 'dry-run', false );
		$limit        = (int) WP_CLI\Utils\get_flag_value( $assoc_args, 'limit', 0 );
		$statuses     = $this->csv( WP_CLI\Utils\get_flag_value( $assoc_args, 'status', 'publish' ) );
		$include      = array_values( array_filter( array_map( 'absint', $this->csv( WP_CLI\Utils\get_flag_value( $assoc_args, 'include', '' ) ) ) ) );
		$translatable = array_values( array_filter( (array) apply_filters( 'wp_loc_translatable_post_types', [ 'post', 'page' ] ), 'post_type_exists' ) );
		$post_types   = $this->csv( WP_CLI\Utils\get_flag_value( $assoc_args, 'post_type', implode( ',', $translatable ) ) );

		if ( array_diff( $post_types, $translatable ) ) {
			WP_CLI::error( 'Not a translatable post type: ' . implode( ', ', array_diff( $post_types, $translatable ) ) . '. Translatable: ' . implode( ', ', $translatable ) );
		}

		if ( empty( $post_types ) || empty( $statuses ) ) {
			WP_CLI::error( 'Nothing to process: no post types or statuses.' );
		}

		if ( ! $dry_run ) {
			$this->assert_can_translate();
		}

		// Straight from the table: WP_Query would apply the language filter of the CLI context.
		$ids = array_map( 'intval', $wpdb->get_col( $wpdb->prepare(
			"SELECT ID FROM {$wpdb->posts}
			 WHERE post_type IN (" . implode( ', ', array_fill( 0, count( $post_types ), '%s' ) ) . ")
			   AND post_status IN (" . implode( ', ', array_fill( 0, count( $statuses ), '%s' ) ) . ")"
			. ( $include ? ' AND ID IN (' . implode( ', ', $include ) . ')' : '' ) . "
			 ORDER BY ID ASC",
			array_merge( $post_types, $statuses )
		) ) );

		$done = 0; $skipped = 0; $failed = 0;

		foreach ( $ids as $post_id ) {
			if ( $limit > 0 && $done >= $limit ) {
				break;
			}

			$element_type = WP_LOC_DB::post_element_type( (string) get_post_type( $post_id ) );
			$lang         = $db->get_element_language( $post_id, $element_type );
			$trid         = $db->get_trid( $post_id, $element_type );
			$group        = $trid ? $db->get_element_translations( $trid, $element_type ) : [];

			// Only originals: a translation is translated from its own source.
			if ( ( $lang && ! empty( $group[ $lang ]->source_language_code ) ) || $lang === $target ) {
				$skipped++;
				continue;
			}

			if ( isset( $group[ $target ] ) && ! $force ) {
				$skipped++;
				continue;
			}

			if ( $dry_run ) {
				WP_CLI::log( sprintf( '#%d %s → %s', $post_id, get_the_title( $post_id ), $target ) );
				$done++;
				continue;
			}

			$result = $this->translate_one( $post_id, $target, $fields, $force );

			if ( is_wp_error( $result ) ) {
				$failed++;
				WP_CLI::warning( sprintf( '#%d: %s', $post_id, $result->get_error_message() ) );
				continue;
			}

			$done++;
			WP_CLI::log( $result );
		}

		WP_CLI::success( sprintf(
			'%s %d post(s) into %s; %d skipped (translations, already translated, or in %s)%s.',
			$dry_run ? 'Would translate' : 'Translated',
			$done,
			$target,
			$skipped,
			$target,
			$failed ? ", {$failed} failed" : ''
		) );
	}

	/**
	 * Create the translation in $target when missing and translate it from $post_id.
	 *
	 * @return string|\WP_Error A one-line report.
	 */
	private function translate_one( int $post_id, string $target, array $fields, bool $force ) {
		$source = get_post( $post_id );

		if ( ! $source instanceof \WP_Post || ! WP_LOC_Admin_Settings::is_translatable( $source->post_type ) ) {
			return new \WP_Error( 'wp_loc_cli_source', sprintf( 'Post %d does not exist or is not translatable.', $post_id ) );
		}

		$db           = WP_LOC::instance()->db;
		$element_type = WP_LOC_DB::post_element_type( $source->post_type );
		$lang         = $db->get_element_language( $post_id, $element_type );
		$trid         = $db->get_trid( $post_id, $element_type );
		$group        = $trid ? $db->get_element_translations( $trid, $element_type ) : [];

		if ( $lang && ! empty( $group[ $lang ]->source_language_code ) ) {
			return new \WP_Error( 'wp_loc_cli_not_source', sprintf( 'Post %d is a translation; translate its source instead.', $post_id ) );
		}

		if ( $lang === $target ) {
			return new \WP_Error( 'wp_loc_cli_same_language', sprintf( 'Post %d is already in %s.', $post_id, $target ) );
		}

		$created = false;

		if ( isset( $group[ $target ] ) ) {
			if ( ! $force ) {
				return new \WP_Error( 'wp_loc_cli_exists', sprintf( 'Post %d already has a %s translation (#%d); use --force to translate it again.', $post_id, $target, (int) $group[ $target ]->element_id ) );
			}

			$target_id = (int) $group[ $target ]->element_id;
		} else {
			WP_LOC::instance()->content->create_translations( $post_id, [ $target ] );
			$target_id = (int) $db->get_element_translation( $post_id, $element_type, $target );
			$created = true;

			if ( ! $target_id ) {
				return new \WP_Error( 'wp_loc_cli_copy', sprintf( 'Could not create the %s translation of post %d.', $target, $post_id ) );
			}
		}

		$result = WP_LOC_AI::translate_post_fields( $source, $target_id, $target, $fields, $created );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return sprintf( '#%d → #%d (%s)%s', $post_id, $target_id, $target, $created ? ', new copy' : ', translated again' );
	}

	private function get_target_language( array $assoc_args ): string {
		$target = sanitize_key( (string) WP_CLI\Utils\get_flag_value( $assoc_args, 'to', '' ) );
		$active = array_keys( WP_LOC_Languages::get_active_languages() );

		if ( ! in_array( $target, $active, true ) ) {
			WP_CLI::error( "Unknown or missing --to language. Active languages: " . implode( ', ', $active ) );
		}

		return $target;
	}

	private function get_fields( array $assoc_args ): array {
		$fields  = $this->csv( WP_CLI\Utils\get_flag_value( $assoc_args, 'fields', 'title,content,excerpt' ) );
		$unknown = array_diff( $fields, [ 'title', 'content', 'excerpt' ] );

		if ( $unknown || ! $fields ) {
			WP_CLI::error( 'Fields can be title, content and excerpt.' );
		}

		return $fields;
	}

	private function assert_can_translate(): void {
		if ( ! WP_LOC_AI::can_translate() ) {
			WP_CLI::error( 'No AI provider is connected: set one up in Settings > Connectors and choose it on the AI tab of the WP-LOC settings.' );
		}
	}

	private function csv( $value ): array {
		return array_values( array_filter( array_map( 'trim', explode( ',', (string) $value ) ) ) );
	}
}
