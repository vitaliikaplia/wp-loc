<?php

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * WP-CLI commands for WP-LOC.
 */
class WP_LOC_CLI_Backfill {

	/**
	 * Create missing translations for posts — a full structural copy of each source.
	 *
	 * For every source post — the original of its translation group, in any language, or a
	 * post without a language yet (registered in the default language) — that has no
	 * translation in the target language(s), this creates a draft copy linked via WP-LOC
	 * through the same path as saving a post: all meta, the language-resolved thumbnail and
	 * language-mapped terms. Source TEXT (title/content/excerpt) is copied as-is; it is not
	 * translated. Idempotent: translations that already exist are skipped, and posts that are
	 * themselves translations are never used as a source.
	 *
	 * ## OPTIONS
	 *
	 * [--post_type=<types>]
	 * : Comma-separated post types to process. Defaults to every translatable post type.
	 *
	 * [--lang=<slug>]
	 * : Only create the translation in this language. Defaults to every active language
	 * except the source's own.
	 *
	 * [--include=<ids>]
	 * : Comma-separated post IDs to limit the run to.
	 *
	 * [--status=<statuses>]
	 * : Comma-separated source post statuses to include.
	 * ---
	 * default: publish,draft,private
	 * ---
	 *
	 * [--limit=<n>]
	 * : Stop after this many source posts got (or, with --dry-run, would get) translations.
	 * 0 = no limit.
	 * ---
	 * default: 0
	 * ---
	 *
	 * [--dry-run]
	 * : Report what would be created without creating anything.
	 *
	 * ## EXAMPLES
	 *
	 *     wp wp-loc create-translations --dry-run
	 *     wp wp-loc create-translations --post_type=product --lang=ru
	 *     wp wp-loc create-translations --post_type=post,page --lang=en --status=publish --limit=20
	 *     wp wp-loc create-translations --include=12,15 --lang=en
	 *
	 * @subcommand create-translations
	 * @when after_wp_load
	 */
	public function create_translations( $args, $assoc_args ) {
		global $wpdb;

		$loc = WP_LOC::instance();
		$db  = $loc->db;

		$translatable = array_values( array_filter(
			(array) apply_filters( 'wp_loc_translatable_post_types', [ 'post', 'page' ] ),
			'post_type_exists'
		) );
		$post_types = $this->csv( WP_CLI\Utils\get_flag_value( $assoc_args, 'post_type', implode( ',', $translatable ) ) );
		$statuses   = $this->csv( WP_CLI\Utils\get_flag_value( $assoc_args, 'status', 'publish,draft,private' ) );
		$limit      = (int) WP_CLI\Utils\get_flag_value( $assoc_args, 'limit', 0 );
		$dry_run    = (bool) WP_CLI\Utils\get_flag_value( $assoc_args, 'dry-run', false );
		$lang_flag  = (string) WP_CLI\Utils\get_flag_value( $assoc_args, 'lang', '' );
		$include    = array_values( array_filter( array_map( 'absint', $this->csv( WP_CLI\Utils\get_flag_value( $assoc_args, 'include', '' ) ) ) ) );

		$active  = array_keys( WP_LOC_Languages::get_active_languages() );
		$default = WP_LOC_Languages::get_default_language();

		if ( $lang_flag !== '' && ! in_array( $lang_flag, $active, true ) ) {
			WP_CLI::error( "Unknown language '{$lang_flag}'. Active languages: " . implode( ', ', $active ) );
		}

		if ( count( $active ) < 2 ) {
			WP_CLI::error( 'No target languages to create (only one language is active).' );
		}

		$not_translatable = array_diff( $post_types, $translatable );
		if ( $not_translatable ) {
			WP_CLI::error( 'Not a translatable post type: ' . implode( ', ', $not_translatable ) . '. Translatable: ' . implode( ', ', $translatable ) );
		}

		if ( empty( $post_types ) || empty( $statuses ) ) {
			WP_CLI::error( 'Nothing to process: no post types or statuses.' );
		}

		// Straight from the table: WP_Query would apply the language filter of the CLI context.
		$source_ids = array_map( 'intval', $wpdb->get_col( $wpdb->prepare(
			"SELECT ID FROM {$wpdb->posts}
			 WHERE post_type IN (" . implode( ', ', array_fill( 0, count( $post_types ), '%s' ) ) . ")
			   AND post_status IN (" . implode( ', ', array_fill( 0, count( $statuses ), '%s' ) ) . ")"
			. ( $include ? ' AND ID IN (' . implode( ', ', $include ) . ')' : '' ) . "
			 ORDER BY ID ASC",
			array_merge( $post_types, $statuses )
		) ) );

		WP_CLI::log( sprintf(
			'%d candidate %s post(s); target language(s): %s%s',
			count( $source_ids ),
			implode( '/', $post_types ),
			$lang_flag !== '' ? $lang_flag : 'every missing one',
			$dry_run ? '  [DRY RUN]' : ''
		) );

		$sources = 0; $copies = 0; $complete = 0; $not_source = 0; $errors = 0;
		$progress = WP_CLI\Utils\make_progress_bar( 'Creating translations', count( $source_ids ) );

		foreach ( $source_ids as $post_id ) {
			$progress->tick();

			if ( $limit > 0 && $sources >= $limit ) {
				continue;
			}

			$element_type = WP_LOC_DB::post_element_type( (string) get_post_type( $post_id ) );
			$lang         = $db->get_element_language( $post_id, $element_type );
			$trid         = $db->get_trid( $post_id, $element_type );
			$existing     = $trid ? $db->get_element_translations( $trid, $element_type ) : [];

			// A translation is never a source; the original of a group can be in any language.
			if ( $lang && ! empty( $existing[ $lang ]->source_language_code ) ) {
				$not_source++;
				continue;
			}

			$source_lang = $lang ?: $default;
			$targets     = $lang_flag !== '' ? [ $lang_flag ] : $active;
			$missing     = array_values( array_filter( $targets, static function ( $l ) use ( $existing, $source_lang ) {
				return $l !== $source_lang && ! isset( $existing[ $l ] );
			} ) );

			if ( empty( $missing ) ) {
				$complete++;
				continue;
			}

			$sources++;

			if ( $dry_run ) {
				$copies += count( $missing );
				continue;
			}

			try {
				$loc->content->create_translations( $post_id, $missing );

				$trid  = $db->get_trid( $post_id, $element_type );
				$after = $trid ? $db->get_element_translations( $trid, $element_type ) : [];
				$made  = count( array_filter( $missing, static fn( $l ) => isset( $after[ $l ] ) ) );
				$copies += $made;

				if ( $made < count( $missing ) ) {
					$errors++;
					WP_CLI::warning( sprintf( 'Post %d: created %d of %d missing translation(s).', $post_id, $made, count( $missing ) ) );
				}
			} catch ( \Throwable $e ) {
				$errors++;
				WP_CLI::warning( "Post {$post_id}: " . $e->getMessage() );
			}
		}

		$progress->finish();

		WP_CLI::success( sprintf(
			'%s %d translation(s) for %d source post(s); %d already complete, %d are translations themselves%s.',
			$dry_run ? 'Would create' : 'Created',
			$copies,
			$sources,
			$complete,
			$not_source,
			$errors ? ", {$errors} with problems" : ''
		) );
	}

	private function csv( $value ): array {
		return array_values( array_filter( array_map( 'trim', explode( ',', (string) $value ) ) ) );
	}
}
