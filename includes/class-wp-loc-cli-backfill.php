<?php

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * WP-CLI commands for WP-LOC.
 */
class WP_LOC_CLI_Backfill {

	/**
	 * Create missing translations for posts — a full structural copy of each source.
	 *
	 * For every source post in the default language that does not yet have a translation
	 * in the target language(s), this creates a draft copy linked via WP-LOC: all meta,
	 * the language-resolved thumbnail and — for WooCommerce products — its own variations
	 * and language-mapped term assignments. Source TEXT (title/content/excerpt) is copied
	 * as-is; it is not translated. Idempotent: sources that already have the translation
	 * are skipped.
	 *
	 * ## OPTIONS
	 *
	 * [--post_type=<types>]
	 * : Comma-separated post types to process.
	 * ---
	 * default: product
	 * ---
	 *
	 * [--lang=<slug>]
	 * : Target language slug to ensure a translation for. Defaults to every active
	 * non-default language.
	 *
	 * [--status=<statuses>]
	 * : Comma-separated source post statuses to include.
	 * ---
	 * default: publish,draft,private
	 * ---
	 *
	 * [--limit=<n>]
	 * : Maximum number of source posts to process. 0 = no limit.
	 * ---
	 * default: 0
	 * ---
	 *
	 * [--dry-run]
	 * : Report what would be created without creating anything.
	 *
	 * ## EXAMPLES
	 *
	 *     wp wp-loc create-translations --post_type=product --lang=ru
	 *     wp wp-loc create-translations --post_type=product --lang=ru --status=publish --limit=20
	 *     wp wp-loc create-translations --post_type=product --lang=ru --dry-run
	 *
	 * @subcommand create-translations
	 * @when after_wp_load
	 */
	public function create_translations( $args, $assoc_args ) {
		$loc = WP_LOC::instance();
		$db  = $loc->db;

		$post_types = $this->csv( WP_CLI\Utils\get_flag_value( $assoc_args, 'post_type', 'product' ) );
		$statuses   = $this->csv( WP_CLI\Utils\get_flag_value( $assoc_args, 'status', 'publish,draft,private' ) );
		$limit      = (int) WP_CLI\Utils\get_flag_value( $assoc_args, 'limit', 0 );
		$dry_run    = (bool) WP_CLI\Utils\get_flag_value( $assoc_args, 'dry-run', false );
		$lang_flag  = WP_CLI\Utils\get_flag_value( $assoc_args, 'lang', '' );

		$active  = array_keys( WP_LOC_Languages::get_active_languages() );
		$default = WP_LOC_Languages::get_default_language();

		if ( $lang_flag && ! in_array( $lang_flag, $active, true ) ) {
			WP_CLI::error( "Unknown language '{$lang_flag}'. Active languages: " . implode( ', ', $active ) );
		}

		$targets = $lang_flag ? [ $lang_flag ] : array_values( array_diff( $active, [ $default ] ) );
		if ( empty( $targets ) ) {
			WP_CLI::error( 'No target languages to create (only the default language is active).' );
		}

		$source_ids = get_posts( [
			'post_type'        => $post_types,
			'post_status'      => $statuses,
			'posts_per_page'   => $limit > 0 ? $limit : -1,
			'orderby'          => 'ID',
			'order'            => 'ASC',
			'fields'           => 'ids',
			'suppress_filters' => true, // do not language-filter the source list
			'no_found_rows'    => true,
		] );

		WP_CLI::log( sprintf(
			'%d candidate %s post(s); target language(s): %s%s',
			count( $source_ids ),
			implode( '/', $post_types ),
			implode( ', ', $targets ),
			$dry_run ? '  [DRY RUN]' : ''
		) );

		$created = 0; $skipped = 0; $not_source = 0; $errors = 0;
		$progress = WP_CLI\Utils\make_progress_bar( 'Creating translations', count( $source_ids ) );

		foreach ( $source_ids as $post_id ) {
			$progress->tick();

			$element_type = WP_LOC_DB::post_element_type( get_post_type( $post_id ) );

			// Only the default-language post is a valid source (skip translations themselves).
			$lang = WP_LOC_DB::from_db_language_code( $db->get_element_language( $post_id, $element_type ) );
			if ( $lang && $lang !== $default ) {
				$not_source++;
				continue;
			}

			$trid     = $db->get_trid( $post_id, $element_type );
			$existing = $trid ? $db->get_element_translations( $trid, $element_type ) : [];

			$missing = array_filter( $targets, static function ( $l ) use ( $existing ) {
				return ! isset( $existing[ $l ] );
			} );

			if ( empty( $missing ) ) {
				$skipped++;
				continue;
			}

			if ( $dry_run ) {
				$created++;
				continue;
			}

			try {
				$loc->content->create_translations( $post_id );
				$created++;
			} catch ( \Throwable $e ) {
				$errors++;
				WP_CLI::warning( "Post {$post_id}: " . $e->getMessage() );
			}
		}

		$progress->finish();

		WP_CLI::success( sprintf(
			'%s: %d %s, %d already had it, %d not source-language%s.',
			$dry_run ? 'Would create' : 'Created',
			$created,
			$dry_run ? 'would be created' : 'created',
			$skipped,
			$not_source,
			$errors ? ", {$errors} error(s)" : ''
		) );
	}

	private function csv( $value ): array {
		return array_values( array_filter( array_map( 'trim', explode( ',', (string) $value ) ) ) );
	}
}
