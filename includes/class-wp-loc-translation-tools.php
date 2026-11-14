<?php

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Translation-group tools for AI agents: read, link and create translations of posts and terms.
 *
 * The logic of the wploc_* tools that Simple MCP used to carry (its class-simple-mcp-tools-wploc.php,
 * version 2.7.1), moved into WP-LOC and limited to WP-LOC itself. WP_LOC_Abilities exposes the
 * three methods as WordPress Abilities; each returns an array for the agent or a WP_Error whose
 * text tells the agent what to fix.
 *
 * Model: every language is a separate post or term linked by a shared trid in icl_translations;
 * element_type is post_{type} or tax_{taxonomy}. The table stores a term's term_taxonomy_id, these
 * tools take and return term_id. Languages are given as wpml code ('uk'), URL slug ('ua'), locale
 * ('uk_UA') or a case variant, resolved strictly against the configured languages; only wpml codes
 * are written and returned. Reads go straight to the table (exact rows, duplicate slots visible);
 * writes go through WP_LOC_DB and are re-read and verified.
 */
class WP_LOC_Translation_Tools {

	/** System and internal post types never translated through these tools. */
	const INTERNAL_TYPES = [ 'attachment', 'revision', 'nav_menu_item', 'custom_css', 'customize_changeset',
		'oembed_cache', 'user_request', 'wp_global_styles', 'wp_font_family', 'wp_font_face' ];

	/** Post meta never duplicated into a translation (exact keys / prefixes); see the wp_loc_translation_skip_meta filter. */
	const SKIP_META = [ '_edit_lock', '_edit_last', '_wp_old_slug', '_wp_old_date', '_wp_desired_post_slug',
		'_encloseme', '_pingme', '_yoast_wpseo_canonical', '_wp_attached_file', '_wp_attachment_metadata' ];
	const SKIP_META_PREFIX = [ '_wp_loc_', '_dp_', '_wp_trash_meta_', '_simple_mcp_', 'wpml', '_wpml', '_icl' ];

	/** ACF field types whose stored value holds object IDs that must point at the target language. */
	const ACF_REL_TYPES = [ 'image', 'file', 'gallery', 'post_object', 'relationship', 'page_link', 'taxonomy' ];

	/** Tool names the agent sees for the three abilities (Simple MCP turns «/» into «-»). */
	const TOOL_GET    = 'wp-loc-get-translations';
	const TOOL_LINK   = 'wp-loc-link-translation';
	const TOOL_CREATE = 'wp-loc-create-translation';

	// ── Languages ────────────────────────────────────────────────────────

	private static function db(): WP_LOC_DB {
		return WP_LOC::instance()->db;
	}

	/** Comparison form of a code/slug/locale: lowercase, '-' → '_'. */
	private static function lnorm( $s ): string {
		return strtolower( str_replace( '-', '_', trim( (string) $s ) ) );
	}

	/**
	 * Configured languages keyed by wpml code: code => [code, slug, locale, name, active, default].
	 * Disabled languages are listed with active:false.
	 */
	public static function languages(): array {
		$out     = [];
		$default = WP_LOC_Languages::get_default_language();

		foreach ( WP_LOC_Languages::get_languages() as $slug => $data ) {
			if ( ! is_array( $data ) ) {
				continue;
			}

			$slug = (string) $slug;
			$code = strtolower( (string) ( WP_LOC_DB::to_db_language_code( $slug ) ?: $slug ) );

			if ( $code === '' || isset( $out[ $code ] ) ) {
				continue;
			}

			$out[ $code ] = [
				'code'    => $code,
				'slug'    => $slug,
				'locale'  => (string) ( $data['locale'] ?? '' ),
				'name'    => WP_LOC_Languages::get_display_name( $slug ),
				'active'  => ! empty( $data['enabled'] ),
				'default' => $slug === $default,
			];
		}

		return $out;
	}

	public static function default_code(): ?string {
		foreach ( self::languages() as $code => $language ) {
			if ( $language['default'] ) {
				return $code;
			}
		}

		return null;
	}

	/** "uk (slug ua, default), en (slug en, disabled)" — for error messages. */
	private static function lang_list(): string {
		$parts = [];

		foreach ( self::languages() as $code => $language ) {
			$flags = [];
			if ( $language['slug'] !== $code ) $flags[] = 'slug ' . $language['slug'];
			if ( $language['default'] ) $flags[] = 'default';
			if ( ! $language['active'] ) $flags[] = 'disabled';
			$parts[] = $code . ( $flags ? ' (' . implode( ', ', $flags ) . ')' : '' );
		}

		return $parts ? implode( ', ', $parts ) : 'none configured';
	}

	/**
	 * Strictly resolve a language given as wpml code, URL slug, locale or a case variant. Matching
	 * order: slug, code, locale, then the primary subtag when it identifies exactly one language.
	 * Disabled languages only with $allow_inactive.
	 *
	 * @return array|WP_Error [code, slug, default, active, locale, name]
	 */
	public static function resolve_lang( $lang, bool $allow_inactive = false ) {
		$raw   = is_scalar( $lang ) ? trim( (string) $lang ) : '';
		$langs = self::languages();

		if ( $raw === '' ) {
			return new WP_Error( 'bad_lang', 'A language is required. Valid languages: ' . self::lang_list() . '.' );
		}

		$n     = self::lnorm( $raw );
		$match = null;

		foreach ( [ 'slug', 'code', 'locale' ] as $field ) {
			foreach ( $langs as $language ) {
				if ( $language[ $field ] !== '' && self::lnorm( $language[ $field ] ) === $n ) {
					$match = $language;
					break 2;
				}
			}
		}

		if ( ! $match && strpos( $n, '_' ) !== false ) {
			$primary    = explode( '_', $n )[0];
			$candidates = [];

			foreach ( $langs as $code => $language ) {
				foreach ( [ $language['slug'], $language['code'], $language['locale'] ] as $value ) {
					if ( $value !== '' && explode( '_', self::lnorm( $value ) )[0] === $primary ) {
						$candidates[ $code ] = $language;
						break;
					}
				}
			}

			if ( count( $candidates ) === 1 ) {
				$match = reset( $candidates );
			}
		}

		if ( ! $match ) {
			return new WP_Error( 'bad_lang', 'Unknown language "' . mb_substr( $raw, 0, 40 ) . '". Valid languages: ' . self::lang_list() . '.' );
		}

		if ( ! $match['active'] && ! $allow_inactive ) {
			return new WP_Error( 'inactive_lang', 'Language "' . $match['code'] . '" is configured but disabled on this site. '
				. 'Pass allow_inactive:true to prepare content for it anyway. Valid languages: ' . self::lang_list() . '.' );
		}

		return $match;
	}

	/** Normalize a code read from the table (it may hold a legacy slug such as 'ua'). */
	private static function norm_code( $code ): ?string {
		$code = strtolower( trim( (string) $code ) );

		if ( $code === '' ) {
			return null;
		}

		$normalized = WP_LOC_DB::to_db_language_code( $code );

		return $normalized ? strtolower( (string) $normalized ) : $code;
	}

	/** Language argument for WP_LOC_DB: the slug of a configured language, else the code. */
	private static function lang_arg( string $code ): string {
		$langs = self::languages();

		return isset( $langs[ $code ] ) ? $langs[ $code ]['slug'] : $code;
	}

	// ── Sync ─────────────────────────────────────────────────────────────

	private static function attr_sync_on(): bool {
		return WP_LOC_Admin_Settings::should_sync_post_attributes();
	}

	/** One-line warning when saving a translation propagates attributes to the others; null otherwise. */
	public static function sync_note(): ?string {
		$parts = [];

		if ( WP_LOC_Admin_Settings::should_sync_post_attributes() ) {
			$parts[] = 'status, author, password, menu_order, page template and parent (mapped per language)';
		}
		if ( WP_LOC_Admin_Settings::should_sync_post_taxonomies() ) {
			$parts[] = 'terms of translatable taxonomies (mapped, replacing theirs)';
		}
		if ( WP_LOC_Admin_Settings::should_sync_featured_image() ) {
			$parts[] = 'featured-image changes (mapped)';
		}

		if ( ! $parts ) {
			return null;
		}

		return 'wp-loc sync is ON: saving ANY translation of a post pushes to every other translation in its group, the source included: '
			. implode( '; ', $parts ) . '.';
	}

	// ── Elements ─────────────────────────────────────────────────────────

	/**
	 * Normalize element_type: nav_menu / tax_* / taxonomy name / post_* / post type name.
	 *
	 * @return string|WP_Error '' when empty.
	 */
	private static function norm_etype( $type ) {
		$type = trim( (string) $type );

		if ( $type === '' ) {
			return '';
		}

		$orig = $type;

		if ( $type === 'nav_menu' ) {
			$type = 'tax_nav_menu';
		} elseif ( strpos( $type, 'tax_' ) !== 0 && taxonomy_exists( $type ) ) {
			$type = 'tax_' . $type;
		} elseif ( strpos( $type, 'tax_' ) !== 0 && strpos( $type, 'post_' ) !== 0 && post_type_exists( $type ) ) {
			$type = 'post_' . $type;
		}

		if ( strpos( $type, 'tax_' ) === 0 && taxonomy_exists( substr( $type, 4 ) ) ) return $type;
		if ( strpos( $type, 'post_' ) === 0 && post_type_exists( substr( $type, 5 ) ) ) return $type;
		if ( post_type_exists( $orig ) ) return 'post_' . $orig;

		return new WP_Error( 'bad_etype', 'Unknown element_type "' . substr( $orig, 0, 60 ) . '": expected post_{post type} or tax_{taxonomy} of an existing type.' );
	}

	/** [term_id, term_taxonomy_id] of a term_id in a taxonomy (raw: language filters never swap the term). */
	private static function term_row( $term_id, $tax ): ?array {
		global $wpdb;

		$row = $wpdb->get_row( $wpdb->prepare(
			"SELECT term_id, term_taxonomy_id FROM {$wpdb->term_taxonomy} WHERE term_id = %d AND taxonomy = %s LIMIT 1",
			(int) $term_id,
			(string) $tax
		), ARRAY_A );

		return $row ? [ 'term_id' => (int) $row['term_id'], 'term_taxonomy_id' => (int) $row['term_taxonomy_id'] ] : null;
	}

	private static function term_row_by_tt( $tt_id, $tax ): ?array {
		global $wpdb;

		$row = $wpdb->get_row( $wpdb->prepare(
			"SELECT term_id, term_taxonomy_id FROM {$wpdb->term_taxonomy} WHERE term_taxonomy_id = %d AND taxonomy = %s LIMIT 1",
			(int) $tt_id,
			(string) $tax
		), ARRAY_A );

		return $row ? [ 'term_id' => (int) $row['term_id'], 'term_taxonomy_id' => (int) $row['term_taxonomy_id'] ] : null;
	}

	/**
	 * Registered taxonomies a term_id belongs to (normally one). Rows of taxonomies nobody registers
	 * any more (left behind by another multilingual plugin) are ignored.
	 */
	private static function term_taxonomies( $term_id ): array {
		global $wpdb;

		$taxes = array_unique( array_map( 'strval', (array) $wpdb->get_col( $wpdb->prepare(
			"SELECT taxonomy FROM {$wpdb->term_taxonomy} WHERE term_id = %d",
			(int) $term_id
		) ) ) );

		return array_values( array_filter( $taxes, 'taxonomy_exists' ) );
	}

	/** Raw name/slug/description/parent of a term. */
	private static function term_info( $term_id, $tax ): ?array {
		global $wpdb;

		$row = $wpdb->get_row( $wpdb->prepare(
			"SELECT t.name, t.slug, tt.description, tt.parent FROM {$wpdb->terms} t
			 INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_id = t.term_id
			 WHERE t.term_id = %d AND tt.taxonomy = %s LIMIT 1",
			(int) $term_id,
			(string) $tax
		), ARRAY_A );

		return $row ? [
			'name'        => (string) $row['name'],
			'slug'        => (string) $row['slug'],
			'description' => (string) $row['description'],
			'parent'      => (int) $row['parent'],
		] : null;
	}

	/**
	 * Resolve an element from the arguments (kind / taxonomy / element_type) to
	 * [kind, id (post ID|term_id), eid (post ID|term_taxonomy_id), etype, type (post type|taxonomy), note].
	 * $tt_fallback (reads only): an ID that is no term_id in the taxonomy may be read as a term_taxonomy_id.
	 *
	 * @return array|WP_Error
	 */
	public static function resolve_element( $id, array $args, ?string $default_kind = null, bool $tt_fallback = false ) {
		$id = (int) $id;

		if ( $id <= 0 ) {
			return new WP_Error( 'bad_id', 'A positive element ID is required.' );
		}

		$kind = isset( $args['kind'] ) && $args['kind'] !== '' ? (string) $args['kind'] : null;

		if ( $kind !== null && ! in_array( $kind, [ 'post', 'term' ], true ) ) {
			return new WP_Error( 'bad_kind', 'kind must be "post" or "term".' );
		}

		$etype = self::norm_etype( $args['element_type'] ?? '' );
		if ( is_wp_error( $etype ) ) {
			return $etype;
		}

		$tax = isset( $args['taxonomy'] ) && $args['taxonomy'] !== '' ? sanitize_key( (string) $args['taxonomy'] ) : '';
		if ( $tax !== '' && ! taxonomy_exists( $tax ) ) {
			return new WP_Error( 'bad_tax', 'Unknown taxonomy "' . $tax . '".' );
		}

		if ( $etype !== '' ) {
			$element_kind = strpos( $etype, 'tax_' ) === 0 ? 'term' : 'post';

			if ( $kind !== null && $kind !== $element_kind ) {
				return new WP_Error( 'kind_conflict', 'kind "' . $kind . '" contradicts element_type "' . $etype . '".' );
			}

			$kind = $element_kind;

			if ( $element_kind === 'term' ) {
				$element_tax = substr( $etype, 4 );

				if ( $tax !== '' && $tax !== $element_tax ) {
					return new WP_Error( 'kind_conflict', 'taxonomy "' . $tax . '" contradicts element_type "' . $etype . '".' );
				}

				$tax = $element_tax;
			}
		}

		if ( $tax !== '' ) {
			if ( $kind === 'post' ) {
				return new WP_Error( 'kind_conflict', 'taxonomy is only valid for kind "term".' );
			}

			$kind = 'term';
		}

		if ( $kind === null ) {
			$kind = $default_kind;
		}

		$note = null;

		if ( $kind === null ) {
			// Post IDs and term_ids are independent sequences: an existing post wins, a term in a
			// translatable taxonomy with the same ID is only mentioned in the note.
			$post  = get_post( $id );
			$taxes = self::term_taxonomies( $id );

			if ( ! $post && ! $taxes ) {
				return new WP_Error( 'not_found', 'No post or term with ID ' . $id . '.' );
			}

			$kind = $post ? 'post' : 'term';
			$also = $post ? array_values( array_filter( $taxes, [ __CLASS__, 'is_translatable_taxonomy' ] ) ) : [];

			if ( $also ) {
				$note = 'ID ' . $id . ' was resolved as the post (' . $post->post_type . '); a term with the same term_id exists in '
					. implode( ', ', $also ) . ' — pass kind:"term" for it.';
			}
		}

		if ( $kind === 'post' ) {
			$post = get_post( $id );

			if ( ! $post ) {
				return new WP_Error( 'not_found', 'Post #' . $id . ' not found (for a term pass kind:"term").' );
			}

			if ( $etype !== '' && $etype !== 'post_' . $post->post_type ) {
				return new WP_Error( 'etype_mismatch', 'element_type "' . $etype . '" does not match post #' . $id . ' (post type "' . $post->post_type . '").' );
			}

			return [ 'kind' => 'post', 'id' => (int) $post->ID, 'eid' => (int) $post->ID, 'etype' => 'post_' . $post->post_type, 'type' => $post->post_type, 'note' => $note ];
		}

		if ( $tax === '' ) {
			$taxes = self::term_taxonomies( $id );

			if ( ! $taxes ) {
				return new WP_Error( 'not_found', 'Term ' . $id . ' not found.' );
			}

			if ( count( $taxes ) > 1 ) {
				return new WP_Error( 'ambiguous_tax', 'term_id ' . $id . ' exists in several taxonomies (' . implode( ', ', $taxes ) . '); pass taxonomy.' );
			}

			$tax = $taxes[0];
		}

		$row = self::term_row( $id, $tax );

		if ( ! $row ) {
			$row = $tt_fallback ? self::term_row_by_tt( $id, $tax ) : null;

			if ( ! $row ) {
				return new WP_Error( 'not_found', 'Term ' . $id . ' (term_id) not found in taxonomy "' . $tax . '".' );
			}

			$note = 'ID ' . $id . ' is not a term_id in "' . $tax . '"; it was read as a term_taxonomy_id (term_id ' . $row['term_id'] . '). Pass term IDs.';
		}

		return [ 'kind' => 'term', 'id' => $row['term_id'], 'eid' => $row['term_taxonomy_id'], 'etype' => 'tax_' . $tax, 'type' => $tax, 'note' => $note ];
	}

	/** Post type translatable on this site and not a system type. */
	public static function is_translatable_post_type( $post_type ): bool {
		$post_type = (string) $post_type;

		if ( $post_type === '' || ! post_type_exists( $post_type ) || in_array( $post_type, self::INTERNAL_TYPES, true ) || strpos( $post_type, 'acf-' ) === 0 ) {
			return false;
		}

		return WP_LOC_Admin_Settings::is_translatable( $post_type );
	}

	/** Taxonomy translatable on this site (navigation menus included). */
	public static function is_translatable_taxonomy( $tax ): bool {
		$tax = (string) $tax;

		if ( $tax === '' || ! taxonomy_exists( $tax ) ) {
			return false;
		}

		if ( $tax === 'nav_menu' ) {
			return true;
		}

		return WP_LOC_Terms::is_translatable( $tax );
	}

	private static function ref_translatable( array $ref ): bool {
		return $ref['kind'] === 'post' ? self::is_translatable_post_type( $ref['type'] ) : self::is_translatable_taxonomy( $ref['type'] );
	}

	// ── Permissions ──────────────────────────────────────────────────────

	/** Refusal that names the missing native capability, so the agent sees it is a WordPress right, not a failure. */
	private static function forbidden( string $action ): WP_Error {
		$user = wp_get_current_user();

		return new WP_Error( 'forbidden', 'Refused: user "' . ( $user && $user->exists() ? $user->user_login : '?' )
			. '" has no WordPress capability for this (' . $action . '). Ask an administrator to extend the role if needed.' );
	}

	/**
	 * Can the current user read the post (as the REST API and wp-admin decide): a published post of a
	 * viewable type without a password — anyone; anything else needs edit_post, a private one read_post.
	 * Attachments follow their parent.
	 */
	public static function can_read_post( $post, int $depth = 0 ): bool {
		$post = get_post( $post );

		if ( ! $post ) {
			return false;
		}

		if ( $post->post_type === 'attachment' ) {
			$parent = ( $post->post_parent && $depth < 5 ) ? get_post( $post->post_parent ) : null;

			return $parent ? self::can_read_post( $parent, $depth + 1 ) : true;
		}

		$no_password = (string) $post->post_password === '';

		if ( $post->post_status === 'publish' && $no_password && is_post_type_viewable( $post->post_type ) ) {
			return true;
		}

		if ( current_user_can( 'edit_post', $post->ID ) ) {
			return true;
		}

		return $post->post_status === 'private' && $no_password && current_user_can( 'read_post', $post->ID );
	}

	/** Can the current user read the term (public taxonomy, or edit_term)? */
	public static function can_read_term( $term_id, $tax ): bool {
		$taxonomy = get_taxonomy( $tax );

		if ( $taxonomy && ( $taxonomy->public || $taxonomy->publicly_queryable ) ) {
			return true;
		}

		return current_user_can( 'edit_term', (int) $term_id );
	}

	/** Object-level edit right: true or the missing capability. */
	public static function can_edit_ref( array $ref ) {
		if ( $ref['kind'] === 'post' ) {
			return current_user_can( 'edit_post', $ref['id'] ) ?: 'edit_post #' . $ref['id'];
		}

		return current_user_can( 'edit_term', $ref['id'] ) ?: 'edit_term #' . $ref['id'] . ' (' . $ref['type'] . ')';
	}

	private static function is_publish_status( $status ): bool {
		return in_array( $status, [ 'publish', 'private', 'future' ], true );
	}

	private static function can_publish_type( $post_type ): bool {
		$object = get_post_type_object( $post_type );
		$cap    = $object && ! empty( $object->cap->publish_posts ) ? $object->cap->publish_posts : 'publish_posts';

		return current_user_can( $cap );
	}

	/**
	 * A registered, non-internal status ('future' needs a date at least a minute ahead, or WordPress
	 * would publish at once).
	 *
	 * @return string|WP_Error
	 */
	private static function validate_status( $status, $date = null ) {
		$status  = sanitize_key( (string) $status );
		$allowed = array_values( array_unique( array_keys( get_post_stati( [ 'internal' => false ] ) ) ) );

		if ( ! in_array( $status, $allowed, true ) ) {
			return new WP_Error( 'bad_status', 'Unknown or internal status "' . $status . '". Allowed: ' . implode( ', ', $allowed ) . '.' );
		}

		if ( $status === 'future' ) {
			$timestamp = $date ? strtotime( get_gmt_from_date( (string) $date ) . ' +0000' ) : false;

			if ( ! $timestamp || $timestamp - time() < MINUTE_IN_SECONDS ) {
				return new WP_Error( 'bad_future', 'Status "future" needs a date at least a minute ahead, otherwise WordPress publishes the post at once.' );
			}
		}

		return $status;
	}

	// ── icl_translations reads ───────────────────────────────────────────

	private static function table(): string {
		return self::db()->get_table();
	}

	private static function shape_row( array $row ): array {
		$source = isset( $row['source_language_code'] ) ? self::norm_code( $row['source_language_code'] ) : null;

		return [
			'translation_id'  => (int) $row['translation_id'],
			'element_id'      => (int) $row['element_id'],
			'trid'            => (int) $row['trid'],
			'language'        => self::norm_code( $row['language_code'] ),
			'source_language' => $source,
		];
	}

	/** The element's own row [translation_id, element_id, trid, language, source_language] or null. */
	private static function row( $eid, $etype ): ?array {
		global $wpdb;

		$row = $wpdb->get_row( $wpdb->prepare(
			'SELECT translation_id, element_id, trid, language_code, source_language_code FROM ' . self::table()
			. ' WHERE element_id = %d AND element_type = %s ORDER BY translation_id LIMIT 1',
			(int) $eid,
			(string) $etype
		), ARRAY_A );

		return $row ? self::shape_row( $row ) : null;
	}

	/** All rows of a group for this element type (rows without an element are skipped). */
	private static function group( $trid, $etype ): array {
		global $wpdb;

		if ( ! $trid ) {
			return [];
		}

		$rows = $wpdb->get_results( $wpdb->prepare(
			'SELECT translation_id, element_id, trid, language_code, source_language_code FROM ' . self::table()
			. ' WHERE trid = %d AND element_type = %s ORDER BY translation_id',
			(int) $trid,
			(string) $etype
		), ARRAY_A );

		$out = [];

		foreach ( (array) $rows as $row ) {
			if ( (int) $row['element_id'] > 0 ) {
				$out[] = self::shape_row( $row );
			}
		}

		return $out;
	}

	private static function original_row( array $rows ): ?array {
		foreach ( $rows as $row ) {
			if ( $row['source_language'] === null ) {
				return $row;
			}
		}

		return null;
	}

	private static function rows_in_lang( array $rows, $code ): array {
		return array_values( array_filter( $rows, static function ( $row ) use ( $code ) {
			return $row['language'] === $code;
		} ) );
	}

	/** Public ID of a table element: the post ID, or the term_id of a term_taxonomy_id. */
	private static function public_id( $eid, $etype ): int {
		if ( strpos( $etype, 'tax_' ) !== 0 ) {
			return (int) $eid;
		}

		$row = self::term_row_by_tt( $eid, substr( $etype, 4 ) );

		return $row ? $row['term_id'] : 0;
	}

	/** [code => public id] of a group (first row per language). */
	private static function group_map( array $rows, $etype ): array {
		$out = [];

		foreach ( $rows as $row ) {
			if ( ! isset( $out[ $row['language'] ] ) ) {
				$out[ $row['language'] ] = self::public_id( $row['element_id'], $etype );
			}
		}

		return $out;
	}

	// ── Writes ───────────────────────────────────────────────────────────

	/**
	 * Write one element's row. $trid null = a new group of its own; $source null = original.
	 * WP_LOC_DB keeps an existing trid when given null, so the row is dropped first.
	 */
	private static function set_lang( $eid, $etype, $code, $trid, $source ): void {
		if ( ! $trid && self::row( $eid, $etype ) ) {
			self::db()->delete_element( (int) $eid, (string) $etype );
		}

		self::db()->set_element_language(
			(int) $eid,
			(string) $etype,
			self::lang_arg( (string) $code ),
			$trid ? (int) $trid : null,
			$source !== null ? self::lang_arg( (string) $source ) : null
		);
	}

	/** Remove a row that points at an element which no longer exists. */
	private static function drop_row( array $row, $etype ): void {
		self::db()->delete_element( (int) $row['element_id'], (string) $etype );
	}

	/**
	 * After the original left a group: promote one remaining member (default language first, then
	 * language order, then the oldest row). [language, id] or null when nothing had to change.
	 */
	private static function promote_original( $trid, $etype ): ?array {
		$rows = self::group( $trid, $etype );

		if ( ! $rows || self::original_row( $rows ) ) {
			return null;
		}

		$pick  = null;
		$order = array_keys( self::languages() );
		array_unshift( $order, (string) self::default_code() );

		foreach ( $order as $code ) {
			foreach ( $rows as $row ) {
				if ( $row['language'] === $code ) {
					$pick = $row;
					break 2;
				}
			}
		}

		if ( ! $pick ) {
			$pick = $rows[0];
		}

		self::make_original( $trid, $etype, $pick );

		return [ 'language' => $pick['language'], 'id' => self::public_id( $pick['element_id'], $etype ) ];
	}

	/** Make $original (a row of the group) its original; every other member's source points at its language. */
	private static function make_original( $trid, $etype, array $original ): void {
		foreach ( self::group( $trid, $etype ) as $row ) {
			self::set_lang( $row['element_id'], $etype, $row['language'], $trid,
				$row['translation_id'] === $original['translation_id'] ? null : $original['language'] );
		}
	}

	/**
	 * Give a registered element a group of its own (keeping its language); promote a new original in
	 * the old group if needed.
	 *
	 * @return array|WP_Error
	 */
	private static function detach_row( $eid, $etype, array $row ) {
		self::set_lang( $eid, $etype, $row['language'], null, null );
		$new = self::row( $eid, $etype );

		if ( ! $new || $new['trid'] === $row['trid'] || $new['language'] !== $row['language'] ) {
			return new WP_Error( 'detach_failed', 'Could not take element ' . self::public_id( $eid, $etype ) . ' out of translation group ' . $row['trid'] . '.' );
		}

		$promoted = $row['source_language'] === null ? self::promote_original( $row['trid'], $etype ) : null;

		return [
			'trid'            => $row['trid'],
			'language'        => $row['language'],
			'source_language' => $row['source_language'],
			'detached'        => true,
			'new_trid'        => $new['trid'],
			'new_original'    => $promoted,
		];
	}

	/**
	 * Serialize translation-group changes across parallel calls (MySQL named lock). The name is the
	 * one Simple MCP uses for its own group changes (safe_delete detaching a post, registering the
	 * language of a new post), so its writes and these never interleave.
	 */
	private static function with_group_lock( callable $fn ) {
		global $wpdb;

		$name = 'smcp_wploc_' . md5( DB_NAME . '|' . $wpdb->prefix . '|' . get_current_blog_id() );

		if ( (int) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, %d)', $name, 10 ) ) !== 1 ) {
			return new WP_Error( 'locked', 'Another translation change is in progress — retry in a few seconds.' );
		}

		try {
			return $fn();
		} finally {
			$wpdb->query( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $name ) );
		}
	}

	// ── get-translations ─────────────────────────────────────────────────

	/** @return array|WP_Error */
	public static function get_translations( array $args ) {
		$ref = self::resolve_element( $args['element_id'] ?? 0, $args, null, true );

		if ( is_wp_error( $ref ) ) {
			return $ref;
		}

		if ( $ref['kind'] === 'post' ) {
			if ( ! self::can_read_post( $ref['id'] ) ) {
				return self::forbidden( 'read post #' . $ref['id'] );
			}
		} elseif ( ! self::can_read_term( $ref['id'], $ref['type'] ) ) {
			return self::forbidden( 'edit_term #' . $ref['id'] . ' (' . $ref['type'] . ')' );
		}

		$langs = self::languages();
		$out   = [ 'kind' => $ref['kind'], 'element_id' => $ref['id'], 'element_type' => $ref['etype'] ];

		if ( $ref['kind'] === 'term' ) {
			$out['taxonomy']         = $ref['type'];
			$out['term_taxonomy_id'] = $ref['eid'];
		} else {
			$out['post_type'] = $ref['type'];
		}

		$out['translatable']     = self::ref_translatable( $ref );
		$out['default_language'] = self::default_code();

		if ( $ref['note'] ) {
			$out['note'] = $ref['note'];
		}

		$row = self::row( $ref['eid'], $ref['etype'] );

		if ( ! $row ) {
			$out += [
				'trid'         => null,
				'language'     => null,
				'is_original'  => null,
				'translations' => (object) [],
				'missing'      => array_keys( array_filter( $langs, static function ( $language ) { return $language['active']; } ) ),
			];
			$out['note'] = trim( ( $out['note'] ?? '' ) . ' The element has no language yet (not registered in any translation group).' );

			return $out;
		}

		$rows         = self::group( $row['trid'], $ref['etype'] );
		$translations = [];
		$conflicts    = [];

		foreach ( $rows as $member ) {
			$code  = $member['language'];
			$entry = $ref['kind'] === 'post'
				? self::member_post( $member, $code, $langs )
				: self::member_term( $member, $ref['type'], $code, $langs );

			if ( isset( $translations[ $code ] ) ) {
				$conflicts[ $code ][] = $entry['id'] ?? ( $entry['term_id'] ?? null );
				continue;
			}

			$translations[ $code ] = $entry;
		}

		foreach ( $conflicts as $code => $ids ) {
			array_unshift( $conflicts[ $code ], $translations[ $code ]['id'] ?? ( $translations[ $code ]['term_id'] ?? null ) );
			$conflicts[ $code ] = array_values( array_filter( $conflicts[ $code ], static function ( $value ) { return $value !== null; } ) );
		}

		$original = self::original_row( $rows );
		$missing  = [];

		foreach ( $langs as $code => $language ) {
			if ( $language['active'] && ! isset( $translations[ $code ] ) ) {
				$missing[] = $code;
			}
		}

		$out += [
			'trid'         => $row['trid'],
			'language'     => $row['language'],
			'is_original'  => $row['source_language'] === null,
			'original'     => $original ? [ 'language' => $original['language'], 'id' => self::public_id( $original['element_id'], $ref['etype'] ) ] : null,
			'translations' => $translations ?: (object) [],
			'missing'      => $missing,
		];

		if ( $conflicts ) {
			$out['conflicts'] = $conflicts;
		}

		if ( $ref['kind'] === 'post' && ( $note = self::sync_note() ) ) {
			$out['sync_note'] = $note;
		}

		return $out;
	}

	/** One post member of a group, as visible to the current user. */
	private static function member_post( array $row, $code, array $langs ): array {
		$base = [ 'is_original' => $row['source_language'] === null, 'language_active' => isset( $langs[ $code ] ) && $langs[ $code ]['active'] ];
		$post = get_post( $row['element_id'] );

		if ( ! $post ) {
			return [ 'id' => $row['element_id'], 'status' => null, 'missing' => true ] + $base;
		}

		if ( ! self::can_read_post( $post ) ) {
			return [ 'readable' => false ] + $base;
		}

		return [ 'id' => (int) $post->ID, 'status' => $post->post_status, 'title' => $post->post_title, 'post_type' => $post->post_type ] + $base;
	}

	/** One term member of a group, as visible to the current user. */
	private static function member_term( array $row, $tax, $code, array $langs ): array {
		$base = [ 'is_original' => $row['source_language'] === null, 'language_active' => isset( $langs[ $code ] ) && $langs[ $code ]['active'] ];
		$term = self::term_row_by_tt( $row['element_id'], $tax );

		if ( ! $term ) {
			return [ 'term_id' => null, 'term_taxonomy_id' => $row['element_id'], 'missing' => true ] + $base;
		}

		if ( ! self::can_read_term( $term['term_id'], $tax ) ) {
			return [ 'readable' => false ] + $base;
		}

		$info = self::term_info( $term['term_id'], $tax );

		return [ 'term_id' => $term['term_id'], 'term_taxonomy_id' => $term['term_taxonomy_id'], 'name' => $info['name'] ?? '', 'slug' => $info['slug'] ?? '' ] + $base;
	}

	// ── link-translation ─────────────────────────────────────────────────

	/** @return array|WP_Error */
	public static function link_translation( array $args ) {
		$S = self::resolve_element( $args['source_id'] ?? 0, $args );

		if ( is_wp_error( $S ) ) {
			return new WP_Error( $S->get_error_code(), 'source: ' . $S->get_error_message() );
		}

		$T = self::resolve_element( $args['target_id'] ?? 0, [ 'kind' => $S['kind'] ] + ( $S['kind'] === 'term' ? [ 'taxonomy' => $S['type'] ] : [] ) );

		if ( is_wp_error( $T ) ) {
			return new WP_Error( $T->get_error_code(), 'target: ' . $T->get_error_message() );
		}

		if ( $S['etype'] !== $T['etype'] ) {
			return new WP_Error( 'type_mismatch', 'Source and target must be the same ' . ( $S['kind'] === 'post' ? 'post type' : 'taxonomy' )
				. ': source is "' . $S['type'] . '", target is "' . $T['type'] . '".' );
		}

		if ( $S['eid'] === $T['eid'] ) {
			return new WP_Error( 'same_element', 'source_id and target_id are the same element — an element cannot be its own translation.' );
		}

		if ( ! self::ref_translatable( $S ) ) {
			return new WP_Error( 'not_translatable', '"' . $S['type'] . '" is not translatable on this site (WP-LOC settings).' );
		}

		foreach ( [ $S, $T ] as $ref ) {
			$can = self::can_edit_ref( $ref );

			if ( $can !== true ) {
				return self::forbidden( $can );
			}
		}

		if ( $S['kind'] === 'post' ) {
			// A trashed member is a time bomb: emptying the trash deletes its whole group, and restoring
			// it pushes its restored status to every translation (attribute sync).
			foreach ( [ 'source' => $S, 'target' => $T ] as $what => $ref ) {
				$status = get_post_status( $ref['id'] );

				if ( in_array( $status, [ 'trash', 'auto-draft', 'inherit' ], true ) ) {
					return new WP_Error( 'bad_status', 'The ' . $what . ' post #' . $ref['id'] . ' has status "' . $status . '"; restore it (or pick another post) before linking it — '
						. 'a trashed member deletes its whole translation group when the trash is emptied.' );
				}
			}
		}

		$L = self::resolve_lang( $args['lang'] ?? '', ! empty( $args['allow_inactive'] ) );

		if ( is_wp_error( $L ) ) {
			return $L;
		}

		$code  = $L['code'];
		$etype = $S['etype'];

		return self::with_group_lock( static function () use ( $S, $T, $L, $code, $etype, $args ) {
			$srow     = self::row( $S['eid'], $etype );
			$trow     = self::row( $T['eid'], $etype );
			$register = false;
			$SL       = null;

			if ( isset( $args['source_language'] ) && $args['source_language'] !== '' ) {
				$SL = self::resolve_lang( $args['source_language'], true );

				if ( is_wp_error( $SL ) ) {
					return new WP_Error( $SL->get_error_code(), 'source_language: ' . $SL->get_error_message() );
				}
			}

			if ( $srow ) {
				$src_lang = $srow['language'];

				if ( $SL && $SL['code'] !== $src_lang ) {
					return new WP_Error( 'source_language', 'The source is registered as "' . $src_lang . '", not "' . $SL['code'] . '". This tool does not relabel a source; omit source_language.' );
				}
			} else {
				if ( ! $SL ) {
					return new WP_Error( 'source_language', 'The source (' . $S['kind'] . ' ' . $S['id'] . ') has no language yet. Pass source_language (one of: ' . self::lang_list()
						. ') — it will be registered in it.' );
				}

				$src_lang = $SL['code'];
				$register = true;
			}

			if ( $code === $src_lang ) {
				return new WP_Error( 'same_language', 'The target language "' . $code . '" equals the source\'s language — an element cannot be its own translation.' );
			}

			$group     = $srow ? self::group( $srow['trid'], $etype ) : [];
			$orig      = self::original_row( $group );
			$orig_lang = $orig ? $orig['language'] : $src_lang;
			$prev      = $trow ? [ 'trid' => $trow['trid'], 'language' => $trow['language'], 'source_language' => $trow['source_language'] ] : null;

			if ( $srow && $trow && $trow['trid'] === $srow['trid'] ) {
				if ( $trow['language'] === $code ) {
					return [
						'trid'         => $srow['trid'],
						'unchanged'    => true,
						'language'     => $code,
						'source'       => [ 'id' => $S['id'], 'language' => $src_lang, 'registered' => false ],
						'target'       => [ 'id' => $T['id'], 'language' => $code, 'previous' => $prev ],
						'translations' => self::group_map( $group, $etype ),
					];
				}

				if ( $trow['source_language'] === null ) {
					return new WP_Error( 'target_is_original', 'The target is the ORIGINAL ("' . $trow['language'] . '") of this group; relabeling it as "' . $code
						. '" would leave the group without an original. Link a different element instead.' );
				}
			}

			$moving = null;

			if ( $trow && ( ! $srow || $trow['trid'] !== $srow['trid'] ) ) {
				$tgroup = self::group( $trow['trid'], $etype );

				if ( count( $tgroup ) > 1 ) {
					if ( empty( $args['move'] ) ) {
						return new WP_Error( 'other_group', 'The target already belongs to another translation group (trid ' . $trow['trid'] . ': '
							. wp_json_encode( self::group_map( $tgroup, $etype ) ) . '). Pass move:true to take it out of that group'
							. ( $trow['source_language'] === null ? ' (it is that group\'s original — another member will be promoted)' : '' ) . '.' );
					}

					$moving = $trow;
				}
			}

			$occupants = $srow ? array_values( array_filter( self::rows_in_lang( $group, $code ), static function ( $row ) use ( $T ) {
				return $row['element_id'] !== $T['eid'];
			} ) ) : [];

			foreach ( $occupants as $occupant ) {
				$oid = self::public_id( $occupant['element_id'], $etype );

				if ( $occupant['source_language'] === null ) {
					return new WP_Error( 'slot_original', 'The "' . $code . '" slot holds the group\'s original (' . $S['kind'] . ' ' . $oid . '); it cannot be replaced.' );
				}

				$exists = $S['kind'] === 'post' ? (bool) get_post( $occupant['element_id'] ) : (bool) $oid;

				if ( empty( $args['replace'] ) ) {
					return new WP_Error( 'slot_taken', 'The "' . $code . '" slot of this group is already held by ' . $S['kind'] . ' ' . ( $oid ?: $occupant['element_id'] )
						. ( $exists ? '' : ' (which no longer exists)' ) . '. Pass replace:true to detach it (it is kept as a standalone "' . $code . '" '
						. $S['kind'] . ') and link the target instead.' );
				}

				if ( $exists ) {
					$can = self::can_edit_ref( [ 'kind' => $S['kind'], 'id' => $oid, 'eid' => $occupant['element_id'], 'etype' => $etype, 'type' => $S['type'] ] );

					if ( $can !== true ) {
						return self::forbidden( $can );
					}
				}
			}

			// Writes.
			if ( $register ) {
				self::set_lang( $S['eid'], $etype, $src_lang, null, null );
				$srow = self::row( $S['eid'], $etype );

				if ( ! $srow || $srow['language'] !== $src_lang ) {
					return new WP_Error( 'register_failed', 'Could not register the source as "' . $src_lang . '".' );
				}
			}

			$trid     = $srow['trid'];
			$replaced = [];

			foreach ( $occupants as $occupant ) {
				$oid    = self::public_id( $occupant['element_id'], $etype );
				$exists = $S['kind'] === 'post' ? (bool) get_post( $occupant['element_id'] ) : (bool) $oid;

				if ( $exists ) {
					$detached = self::detach_row( $occupant['element_id'], $etype, $occupant );

					if ( is_wp_error( $detached ) ) {
						return $detached;
					}

					$replaced[] = [ 'id' => $oid, 'language' => $code, 'new_trid' => $detached['new_trid'] ];
				} else {
					self::drop_row( $occupant, $etype );
					$replaced[] = [ 'id' => $occupant['element_id'], 'language' => $code, 'stale_row_removed' => true ];
				}
			}

			$moved_from = null;

			if ( $moving ) {
				$detached = self::detach_row( $T['eid'], $etype, $moving );

				if ( is_wp_error( $detached ) ) {
					return $detached;
				}

				$moved_from = [ 'trid' => $moving['trid'], 'new_original' => $detached['new_original'] ];
			}

			self::set_lang( $T['eid'], $etype, $code, $trid, $orig_lang );

			// Verify.
			$s2    = self::row( $S['eid'], $etype );
			$t2    = self::row( $T['eid'], $etype );
			$after = $s2 ? self::group( $s2['trid'], $etype ) : [];

			if ( ! $s2 || ! $t2 || $s2['trid'] !== $t2['trid'] || $t2['language'] !== $code || $s2['language'] !== $src_lang
				|| count( self::rows_in_lang( $after, $code ) ) !== 1 ) {
				return new WP_Error( 'not_verified', 'The link was written but did not verify: source ' . wp_json_encode( $s2 ) . ', target ' . wp_json_encode( $t2 )
					. '. Check with ' . self::TOOL_GET . '.' );
			}

			$out = [
				'trid'         => $t2['trid'],
				'unchanged'    => false,
				'language'     => $code,
				'source'       => [ 'id' => $S['id'], 'language' => $src_lang, 'registered' => $register ],
				'target'       => [ 'id' => $T['id'], 'language' => $t2['language'], 'source_language' => $t2['source_language'], 'previous' => $prev ],
				'replaced'     => $replaced,
				'moved_from'   => $moved_from,
				'translations' => self::group_map( $after, $etype ),
			];

			$warnings = [];

			if ( $S['note'] ) {
				$warnings[] = 'source: ' . $S['note'];
			}
			if ( ! $L['active'] ) {
				$warnings[] = 'Language "' . $code . '" is disabled on this site; the translation is not visible until it is enabled.';
			}
			if ( $S['kind'] === 'post' && ( $warning = self::attr_mismatch( $S['id'], $T['id'] ) ) ) {
				$warnings[] = $warning;
			}
			if ( $warnings ) {
				$out['warnings'] = $warnings;
			}
			if ( $S['kind'] === 'post' && ( $note = self::sync_note() ) ) {
				$out['sync_note'] = $note;
			}

			return $out;
		} );
	}

	/**
	 * With attribute sync on: a warning when two group members differ in status/author/password — the
	 * next save of either pushes its own values to the whole group. null otherwise.
	 */
	private static function attr_mismatch( $source_id, $target_id ): ?string {
		if ( ! self::attr_sync_on() ) {
			return null;
		}

		$source = get_post( $source_id );
		$target = get_post( $target_id );

		if ( ! $source || ! $target ) {
			return null;
		}

		$diff = [];

		foreach ( [ 'post_status' => 'status', 'post_author' => 'author', 'post_password' => 'password' ] as $field => $label ) {
			if ( (string) $source->$field !== (string) $target->$field ) {
				$diff[] = $label;
			}
		}

		if ( ! $diff ) {
			return null;
		}

		return 'The target\'s ' . implode( '/', $diff ) . ( count( $diff ) > 1 ? ' differ' : ' differs' ) . ' from the source\'s (status "' . $target->post_status . '" vs "' . $source->post_status . '"): with wp-loc attribute sync '
			. 'the next save of ANY member of the group pushes its own ' . implode( '/', $diff ) . ' to all of them. To keep the source\'s values, save the source first.';
	}

	// ── create-translation ───────────────────────────────────────────────

	/** @return array|WP_Error */
	public static function create_translation( array $args ) {
		$ref = self::resolve_element( $args['source_id'] ?? 0, $args, 'post' );

		if ( is_wp_error( $ref ) ) {
			return new WP_Error( $ref->get_error_code(), 'source: ' . $ref->get_error_message() );
		}

		return $ref['kind'] === 'term' ? self::create_term_translation( $ref, $args ) : self::create_post_translation( $ref, $args );
	}

	/**
	 * Source language for create: the registered one, or the required source_language.
	 *
	 * @return array|WP_Error [code, register?]
	 */
	private static function source_language( ?array $row, array $args, string $what ) {
		$SL = null;

		if ( isset( $args['source_language'] ) && $args['source_language'] !== '' ) {
			$SL = self::resolve_lang( $args['source_language'], true );

			if ( is_wp_error( $SL ) ) {
				return new WP_Error( $SL->get_error_code(), 'source_language: ' . $SL->get_error_message() );
			}
		}

		if ( $row ) {
			if ( $SL && $SL['code'] !== $row['language'] ) {
				return new WP_Error( 'source_language', 'The source is registered as "' . $row['language'] . '", not "' . $SL['code'] . '". Omit source_language (or fix the source first with ' . self::TOOL_LINK . ').' );
			}

			return [ $row['language'], false ];
		}

		if ( ! $SL ) {
			return new WP_Error( 'source_language', 'The source (' . $what . ') has no language yet. Pass source_language (one of: ' . self::lang_list() . ') — it will be registered in it.' );
		}

		return [ $SL['code'], true ];
	}

	/** @return array|WP_Error */
	private static function create_post_translation( array $ref, array $args ) {
		$post = get_post( $ref['id'] );

		if ( ! $post ) {
			return new WP_Error( 'not_found', 'Post #' . $ref['id'] . ' not found.' );
		}

		if ( $post->post_type === 'revision' || wp_is_post_revision( $post ) || wp_is_post_autosave( $post ) ) {
			return new WP_Error( 'revision', '#' . $post->ID . ' is a revision or autosave, not a post. Translate its parent post #' . (int) $post->post_parent . '.' );
		}

		if ( ! current_user_can( 'edit_post', $post->ID ) ) {
			return self::forbidden( 'edit_post #' . $post->ID );
		}

		if ( ! self::is_translatable_post_type( $post->post_type ) ) {
			return new WP_Error( 'not_translatable', 'Post type "' . $post->post_type . '" is not translatable on this site (WP-LOC settings), or is a system type'
				. ( $post->post_type === 'attachment' ? ' (media translations are managed by WP-LOC itself)' : '' ) . '.' );
		}

		if ( in_array( $post->post_status, [ 'trash', 'auto-draft', 'inherit' ], true ) ) {
			return new WP_Error( 'bad_status', 'Source #' . $post->ID . ' has status "' . $post->post_status . '" — restore it (or pick a real post) before translating it.' );
		}

		$type_object = get_post_type_object( $post->post_type );
		// create_posts registered as false (e.g. log post types) natively denies everyone.
		$create_cap = ( $type_object && isset( $type_object->cap->create_posts ) ) ? $type_object->cap->create_posts : 'edit_posts';

		if ( ! $create_cap ) {
			return self::forbidden( 'create_posts (' . $post->post_type . ' — creating items is disabled)' );
		}

		if ( ! current_user_can( $create_cap ) ) {
			return self::forbidden( $create_cap . ' (' . $post->post_type . ')' );
		}

		$L = self::resolve_lang( $args['lang'] ?? '', ! empty( $args['allow_inactive'] ) );

		if ( is_wp_error( $L ) ) {
			return $L;
		}

		$code  = $L['code'];
		$etype = 'post_' . $post->post_type;

		// Status: with attribute sync the whole group must share it (the next save pushes it everywhere).
		$sync = self::attr_sync_on();
		$want = isset( $args['status'] ) && $args['status'] !== '' ? sanitize_key( (string) $args['status'] ) : null;

		if ( $sync ) {
			$status = $post->post_status;

			if ( $want !== null && $want !== $status ) {
				return new WP_Error( 'status_sync', 'wp-loc attribute sync is on: the copy is created with the source\'s status "' . $status . '", because saving any translation pushes '
					. 'status, author and password to the whole group — a "' . $want . '" copy would turn the source "' . $want . '" on its first edit. '
					. 'Omit status, or change the status of the whole group on the source.' );
			}
		} else {
			$status = self::validate_status( $want ?? 'draft', $want === 'future' ? $post->post_date : null );

			if ( is_wp_error( $status ) ) {
				return $status;
			}
		}

		return self::with_group_lock( static function () use ( $post, $L, $code, $etype, $status, $sync, $args ) {
			$srow   = self::row( $post->ID, $etype );
			$source = self::source_language( $srow, $args, 'post #' . $post->ID );

			if ( is_wp_error( $source ) ) {
				return $source;
			}

			[ $src_lang, $register ] = $source;

			if ( $code === $src_lang ) {
				return new WP_Error( 'same_language', 'The target language "' . $code . '" equals the source\'s language — nothing to create; edit the source directly.' );
			}

			// An existing translation in the slot?
			$group = $srow ? self::group( $srow['trid'], $etype ) : [];
			$stale = [];

			foreach ( self::rows_in_lang( $group, $code ) as $occupant ) {
				$existing = get_post( $occupant['element_id'] );

				if ( ! $existing ) {
					$stale[] = $occupant;
					continue;
				}

				if ( $existing->post_status === 'trash' ) {
					return new WP_Error( 'slot_trashed', 'The "' . $code . '" slot is held by post #' . $existing->ID . ', which is in the trash. Restore it with status "' . $post->post_status . '"'
						. ( $sync ? ' (wp-loc sync pushes the status you restore it with to the whole group, the source included)' : '' )
						. ', or link another post into the slot with ' . self::TOOL_LINK . ' replace:true — then retry.' );
				}

				if ( ! self::can_read_post( $existing ) ) {
					return new WP_Error( 'slot_unreadable', 'The "' . $code . '" slot is already held by a post you cannot access.' );
				}

				return [
					'new_id'   => (int) $existing->ID,
					'existing' => true,
					'language' => $code,
					'trid'     => $occupant['trid'],
					'status'   => $existing->post_status,
					'title'    => $existing->post_title,
					'note'     => 'A "' . $code . '" translation already exists; edit it instead of creating another one.',
				];
			}

			if ( self::is_publish_status( $status ) && ! self::can_publish_type( $post->post_type ) ) {
				return self::forbidden( 'publish_posts (' . $post->post_type . ', status "' . $status . '")' );
			}

			// Parent: the target-language translation of the source's parent, or an explicit one.
			$parent      = 0;
			$parent_info = null;

			if ( array_key_exists( 'parent', $args ) && $args['parent'] !== null && $args['parent'] !== '' ) {
				$parent = (int) $args['parent'];

				if ( $parent > 0 ) {
					if ( ! is_post_type_hierarchical( $post->post_type ) ) {
						return new WP_Error( 'bad_parent', 'parent applies to hierarchical post types only ("' . $post->post_type . '" is not).' );
					}

					$parent_post = get_post( $parent );

					if ( ! $parent_post || $parent_post->post_type !== $post->post_type || $parent_post->post_status === 'trash' ) {
						return new WP_Error( 'bad_parent', 'parent #' . $parent . ' must be an existing, non-trashed ' . $post->post_type . '.' );
					}

					if ( ! self::can_read_post( $parent_post ) ) {
						return self::forbidden( 'read post #' . $parent );
					}

					$parent_row = self::row( $parent_post->ID, $etype );

					if ( ! $parent_row || $parent_row['language'] !== $code ) {
						return new WP_Error( 'bad_parent', 'parent #' . $parent . ' is not a "' . $code . '" ' . $post->post_type . ' (its language: ' . ( $parent_row ? $parent_row['language'] : 'none' ) . ').' );
					}
				}

				$parent_info = [ 'source' => (int) $post->post_parent, 'id' => $parent, 'explicit' => true ];
			} elseif ( $post->post_parent ) {
				$parent_post = get_post( $post->post_parent );

				if ( $parent_post && $parent_post->post_type === $post->post_type ) {
					$mapped = self::post_in_lang( $parent_post->ID, $code );

					if ( ! $mapped ) {
						return new WP_Error( 'parent_untranslated', 'The source\'s parent #' . $parent_post->ID . ' has no "' . $code . '" translation. Translate the parent first '
							. '(' . self::TOOL_CREATE . ' source_id:' . $parent_post->ID . '), or pass parent:0 for a top-level copy (or parent:<id> of an existing "' . $code . '" ' . $post->post_type . ').' );
					}

					$parent      = $mapped;
					$parent_info = [ 'source' => (int) $parent_post->ID, 'id' => $parent, 'mapped' => true ];
				} else {
					$parent      = (int) $post->post_parent;
					$parent_info = [ 'source' => $parent, 'id' => $parent, 'mapped' => false ];
				}
			}

			// Terms: translatable taxonomies → the target-language terms; others keep the same terms.
			$terms = [];

			foreach ( get_object_taxonomies( $post->post_type ) as $tax ) {
				$translatable = self::is_translatable_taxonomy( $tax );
				$plan         = [ 'ids' => [], 'unmapped' => [] ];

				foreach ( self::object_term_ids( $post->ID, $tax ) as $term_id ) {
					$mapped = $translatable ? self::term_in_lang( $term_id, $tax, $code ) : $term_id;

					if ( ! $mapped ) {
						$plan['unmapped'][] = $term_id;
						$mapped             = $term_id;
					}

					$plan['ids'][] = (int) $mapped;
				}

				$plan['ids']   = array_values( array_unique( $plan['ids'] ) );
				$terms[ $tax ] = $plan;
			}

			if ( $register ) {
				self::set_lang( $post->ID, $etype, $src_lang, null, null );
				$srow = self::row( $post->ID, $etype );

				if ( ! $srow || $srow['language'] !== $src_lang ) {
					return new WP_Error( 'register_failed', 'Could not register the source as "' . $src_lang . '".' );
				}

				delete_post_meta( $post->ID, '_wp_loc_is_new' );
			}

			foreach ( $stale as $occupant ) {
				self::drop_row( $occupant, $etype );
			}

			$trid      = $srow['trid'];
			$orig      = self::original_row( self::group( $trid, $etype ) );
			$orig_lang = $orig ? $orig['language'] : $src_lang;

			$postarr = [
				'post_type'      => $post->post_type,
				'post_status'    => $status,
				'post_title'     => $post->post_title,
				'post_content'   => $post->post_content,
				'post_excerpt'   => $post->post_excerpt,
				'post_parent'    => $parent,
				'menu_order'     => $post->menu_order,
				'post_password'  => $post->post_password,
				'post_author'    => $post->post_author,
				'comment_status' => $post->comment_status,
				'ping_status'    => $post->ping_status,
			];

			if ( $sync || $status === 'future' ) {
				$postarr['post_date']     = $post->post_date;
				$postarr['post_date_gmt'] = $post->post_date_gmt;
			}

			if ( ! empty( $terms['category']['ids'] ) ) {
				$postarr['post_category'] = $terms['category']['ids'];
			}

			// WP-LOC must not treat the copy as a brand-new post (it would register it in the admin
			// language, auto-create drafts and sync attributes) — it is registered below.
			$was = WP_LOC_Content::$suspend_new_post_registration;
			WP_LOC_Content::$suspend_new_post_registration = true;

			try {
				$new = self::with_raw_terms( static function () use ( $postarr ) {
					return wp_insert_post( wp_slash( $postarr ), true );
				} );
			} finally {
				WP_LOC_Content::$suspend_new_post_registration = $was;
			}

			if ( is_wp_error( $new ) || ! $new ) {
				return new WP_Error( 'duplicate_failed', 'Duplicate failed: ' . ( is_wp_error( $new ) ? $new->get_error_message() : 'wp_insert_post returned 0' )
					. ( $register ? ' (the source was registered as "' . $src_lang . '")' : '' ) );
			}

			$new = (int) $new;

			try {
				$result = self::fill_post_copy( $post, $new, $code, $etype, $trid, $orig_lang, $status, $parent, $terms );
			} catch ( \Throwable $e ) {
				$result = new WP_Error( 'copy_failed', $e->getMessage() );
			}

			if ( is_wp_error( $result ) ) {
				self::discard_post( $new, $etype );

				return new WP_Error( $result->get_error_code(), 'Creating the translation failed and the copy #' . $new . ' was deleted: ' . $result->get_error_message()
					. ( $register ? ' (the source stays registered as "' . $src_lang . '")' : '' ) );
			}

			$warnings = $result['warnings'];

			if ( ! $L['active'] ) {
				$warnings[] = 'Language "' . $code . '" is disabled on this site; the copy is not visible until it is enabled.';
			}

			if ( $sync && self::is_publish_status( $status ) ) {
				$warnings[] = 'The copy is "' . $status . '" like its source (wp-loc attribute sync), so it is live with source-language content until you translate it.';
			}

			$out = [
				'new_id'   => $new,
				'existing' => false,
				'language' => $code,
				'trid'     => $trid,
				'status'   => get_post_status( $new ),
				'slug'     => get_post_field( 'post_name', $new ),
				'source'   => [ 'id' => (int) $post->ID, 'language' => $src_lang, 'registered' => $register ],
			];

			if ( $parent_info ) {
				$out['parent'] = $parent_info;
			}

			$out += $result['report'];

			if ( $warnings ) {
				$out['warnings'] = $warnings;
			}

			if ( $note = self::sync_note() ) {
				$out['sync_note'] = $note;
			}

			$out['next'] = 'Translate the copy (post #' . $new . '): its title, content, excerpt and fields are still in the source language.';

			/** This action is documented in includes/class-wp-loc-content.php */
			do_action( 'wp_loc_translation_created', $new, (int) $post->ID, self::lang_arg( $code ), self::lang_arg( $src_lang ) );

			return $out;
		} );
	}

	/**
	 * Fill a freshly inserted copy: meta (raw), featured image, ACF relational IDs, terms, the
	 * translation link and the slug, then verify.
	 *
	 * @return array|WP_Error [report => [...], warnings => [...]]
	 */
	private static function fill_post_copy( \WP_Post $post, int $new, string $code, string $etype, int $trid, ?string $orig_lang, string $status, int $parent, array $terms ) {
		global $wpdb;

		$warnings = [];
		$report   = [];

		// Meta, copied as stored: no unslash damage, no object instantiation.
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT meta_key, meta_value FROM {$wpdb->postmeta} WHERE post_id = %d ORDER BY meta_id",
			$post->ID
		), ARRAY_A );
		$present = array_flip( array_map( 'strval', (array) $wpdb->get_col( $wpdb->prepare(
			"SELECT DISTINCT meta_key FROM {$wpdb->postmeta} WHERE post_id = %d",
			$new
		) ) ) );

		$copied  = [];
		$skipped = [];
		$thumb   = 0;

		foreach ( (array) $rows as $row ) {
			$key = (string) $row['meta_key'];

			if ( $key === '_thumbnail_id' ) {
				$thumb = (int) $row['meta_value'];
				continue;
			}

			if ( self::skip_meta( $key, $post, $code ) ) {
				$skipped[ $key ] = true;
				continue;
			}

			if ( isset( $present[ $key ] ) ) {
				$wpdb->delete( $wpdb->postmeta, [ 'post_id' => $new, 'meta_key' => $key ], [ '%d', '%s' ] );
				unset( $present[ $key ] );
			}

			if ( ! $wpdb->insert( $wpdb->postmeta, [ 'post_id' => $new, 'meta_key' => $key, 'meta_value' => $row['meta_value'] ], [ '%d', '%s', '%s' ] ) ) {
				return new WP_Error( 'meta_copy', 'Could not copy meta "' . $key . '": ' . $wpdb->last_error );
			}

			$copied[ (int) $wpdb->insert_id ] = [ $key, (string) $row['meta_value'] ];
		}

		if ( $thumb ) {
			// mapped: true = a target-language attachment is used; false = none exists (source kept); null = language-neutral attachment.
			$mapped  = self::map_rel_id( $thumb, [ 'type' => 'image' ], $code );
			$tpost   = get_post( $thumb );
			$neutral = ! $tpost || ! self::row( $thumb, 'post_' . $tpost->post_type );
			$tid     = $mapped ?: $thumb;

			$wpdb->delete( $wpdb->postmeta, [ 'post_id' => $new, 'meta_key' => '_thumbnail_id' ], [ '%d', '%s' ] );
			$wpdb->insert( $wpdb->postmeta, [ 'post_id' => $new, 'meta_key' => '_thumbnail_id', 'meta_value' => (string) $tid ], [ '%d', '%s', '%s' ] );
			$report['featured_image'] = [ 'source' => $thumb, 'id' => $tid, 'mapped' => $neutral ? null : (bool) $mapped ];
		}

		wp_cache_delete( $new, 'post_meta' );
		[ $acf_mapped, $acf_unmapped ] = self::map_acf_relations( $new, $copied, $code );
		wp_cache_delete( $new, 'post_meta' );

		$report['meta'] = [ 'copied' => count( $copied ), 'skipped' => array_keys( $skipped ) ];

		if ( $acf_mapped || $acf_unmapped ) {
			$report['acf_ids'] = [ 'mapped' => $acf_mapped, 'unmapped' => $acf_unmapped ];
		}

		// Terms (language filters off, so target-language term IDs are accepted), then verify.
		$term_report = [];

		foreach ( $terms as $tax => $plan ) {
			$set = self::with_raw_terms( static function () use ( $new, $plan, $tax ) {
				return wp_set_object_terms( $new, $plan['ids'], $tax, false );
			} );

			if ( is_wp_error( $set ) ) {
				return new WP_Error( 'terms', 'Could not assign ' . $tax . ' terms: ' . $set->get_error_message() );
			}

			$got  = self::object_term_ids( $new, $tax );
			$want = $plan['ids'];
			sort( $got );
			sort( $want );

			if ( $got !== $want ) {
				return new WP_Error( 'terms', 'The ' . $tax . ' terms did not save as planned (' . wp_json_encode( $want ) . ' → ' . wp_json_encode( $got ) . ').' );
			}

			if ( ! $plan['ids'] ) {
				continue;
			}

			$term_report[ $tax ] = [ 'ids' => $plan['ids'] ] + ( $plan['unmapped'] ? [ 'unmapped' => $plan['unmapped'] ] : [] );

			if ( $plan['unmapped'] ) {
				$warnings[] = $tax . ' terms ' . implode( ', ', $plan['unmapped'] ) . ' have no "' . $code . '" translation and were kept as they are.';
			}
		}

		if ( $term_report ) {
			$report['terms'] = $term_report;
		}

		if ( $acf_unmapped ) {
			$warnings[] = 'Some ACF relational IDs have no "' . $code . '" translation and were kept (see acf_ids.unmapped).';
		}

		if ( isset( $report['featured_image'] ) && $report['featured_image']['mapped'] === false ) {
			$warnings[] = 'The featured image #' . $thumb . ' has no "' . $code . '" translation; the source\'s image was kept.';
		}

		// Link into the source's group and verify the slot.
		self::set_lang( $new, $etype, $code, $trid, $orig_lang );
		delete_post_meta( $new, '_wp_loc_is_new' );

		$new_row = self::row( $new, $etype );

		if ( ! $new_row || $new_row['trid'] !== (int) $trid || $new_row['language'] !== $code ) {
			return new WP_Error( 'link', 'The copy could not be registered as the "' . $code . '" translation (row: ' . wp_json_encode( $new_row ) . ').' );
		}

		if ( count( self::rows_in_lang( self::group( $trid, $etype ), $code ) ) !== 1 ) {
			return new WP_Error( 'link', 'The "' . $code . '" slot of group ' . $trid . ' would hold two posts.' );
		}

		// Slug: unique within the target language now that the copy has one (drafts keep an empty slug).
		if ( $post->post_name !== '' && ! in_array( $status, [ 'draft', 'pending', 'auto-draft' ], true ) ) {
			$slug = wp_unique_post_slug( $post->post_name, $new, $status, $post->post_type, $parent );

			if ( $slug !== get_post_field( 'post_name', $new ) ) {
				$wpdb->update( $wpdb->posts, [ 'post_name' => $slug ], [ 'ID' => $new ], [ '%s' ], [ '%d' ] );
			}
		}

		clean_post_cache( $new );

		$saved                      = get_post( $new );
		$report['content_verified'] = self::content_matches( $saved ? $saved->post_content : '', $post->post_content );

		if ( ! $report['content_verified'] ) {
			$warnings[] = 'The copied content differs from the source — WordPress filtered it on save (usually kses for a user without unfiltered_html). Compare the two posts and fix it.';
		}

		return [ 'report' => $report, 'warnings' => $warnings ];
	}

	/** Delete a failed copy without cascading: drop its translation row first (WP-LOC deletes whole groups). */
	private static function discard_post( int $new, string $etype ): void {
		$row = self::row( $new, $etype );

		if ( $row ) {
			self::drop_row( $row, $etype );
		}

		wp_delete_post( $new, true );
	}

	/** @return array|WP_Error */
	private static function create_term_translation( array $ref, array $args ) {
		$tax   = $ref['type'];
		$etype = $ref['etype'];

		if ( ! self::is_translatable_taxonomy( $tax ) ) {
			return new WP_Error( 'not_translatable', 'Taxonomy "' . $tax . '" is not translatable on this site (WP-LOC settings).' );
		}

		$can = self::can_edit_ref( $ref );

		if ( $can !== true ) {
			return self::forbidden( $can );
		}

		$taxonomy = get_taxonomy( $tax );
		$cap      = ( $taxonomy && ! empty( $taxonomy->cap->edit_terms ) ) ? $taxonomy->cap->edit_terms : 'manage_categories';

		if ( ! current_user_can( $cap ) ) {
			return self::forbidden( $cap . ' (' . $tax . ')' );
		}

		$L = self::resolve_lang( $args['lang'] ?? '', ! empty( $args['allow_inactive'] ) );

		if ( is_wp_error( $L ) ) {
			return $L;
		}

		$code = $L['code'];

		if ( isset( $args['status'] ) && $args['status'] !== '' ) {
			return new WP_Error( 'bad_status', 'status applies to posts only.' );
		}

		return self::with_group_lock( static function () use ( $ref, $tax, $etype, $L, $code, $args ) {
			$srow   = self::row( $ref['eid'], $etype );
			$source = self::source_language( $srow, $args, 'term ' . $ref['id'] );

			if ( is_wp_error( $source ) ) {
				return $source;
			}

			[ $src_lang, $register ] = $source;

			if ( $code === $src_lang ) {
				return new WP_Error( 'same_language', 'The target language "' . $code . '" equals the source\'s language — nothing to create.' );
			}

			$group = $srow ? self::group( $srow['trid'], $etype ) : [];
			$stale = [];

			foreach ( self::rows_in_lang( $group, $code ) as $occupant ) {
				$term = self::term_row_by_tt( $occupant['element_id'], $tax );

				if ( ! $term ) {
					$stale[] = $occupant;
					continue;
				}

				$info = self::term_info( $term['term_id'], $tax );

				return [
					'new_term_id'      => $term['term_id'],
					'term_taxonomy_id' => $term['term_taxonomy_id'],
					'existing'         => true,
					'language'         => $code,
					'trid'             => $occupant['trid'],
					'name'             => $info['name'] ?? '',
					'note'             => 'A "' . $code . '" translation already exists; edit it instead of creating another one.',
				];
			}

			$src = self::term_info( $ref['id'], $tax );

			if ( ! $src ) {
				return new WP_Error( 'not_found', 'Source term ' . $ref['id'] . ' not found.' );
			}

			$parent      = 0;
			$parent_info = null;

			if ( array_key_exists( 'parent', $args ) && $args['parent'] !== null && $args['parent'] !== '' ) {
				$parent = (int) $args['parent'];

				if ( $parent > 0 ) {
					if ( ! is_taxonomy_hierarchical( $tax ) ) {
						return new WP_Error( 'bad_parent', 'parent applies to hierarchical taxonomies only ("' . $tax . '" is not).' );
					}

					$parent_term = self::term_row( $parent, $tax );
					$parent_row  = $parent_term ? self::row( $parent_term['term_taxonomy_id'], $etype ) : null;

					if ( ! $parent_term || ! $parent_row || $parent_row['language'] !== $code ) {
						return new WP_Error( 'bad_parent', 'parent ' . $parent . ' must be an existing "' . $code . '" term of "' . $tax . '".' );
					}
				}

				$parent_info = [ 'source' => $src['parent'], 'id' => $parent, 'explicit' => true ];
			} elseif ( $src['parent'] && is_taxonomy_hierarchical( $tax ) ) {
				$mapped = self::term_in_lang( $src['parent'], $tax, $code );

				if ( ! $mapped ) {
					return new WP_Error( 'parent_untranslated', 'The source\'s parent term ' . $src['parent'] . ' has no "' . $code . '" translation. Translate the parent first, or pass parent:0 for a top-level term.' );
				}

				$parent      = $mapped;
				$parent_info = [ 'source' => $src['parent'], 'id' => $parent, 'mapped' => true ];
			}

			$name = isset( $args['name'] ) && trim( (string) $args['name'] ) !== '' ? (string) $args['name'] : $src['name'];
			$slug = isset( $args['slug'] ) && trim( (string) $args['slug'] ) !== '' ? sanitize_title( (string) $args['slug'] ) : $src['slug'];
			$desc = isset( $args['description'] ) ? (string) $args['description'] : $src['description'];

			if ( $register ) {
				self::set_lang( $ref['eid'], $etype, $src_lang, null, null );
				$srow = self::row( $ref['eid'], $etype );

				if ( ! $srow || $srow['language'] !== $src_lang ) {
					return new WP_Error( 'register_failed', 'Could not register the source as "' . $src_lang . '".' );
				}
			}

			foreach ( $stale as $occupant ) {
				self::drop_row( $occupant, $etype );
			}

			$trid      = $srow['trid'];
			$orig      = self::original_row( self::group( $trid, $etype ) );
			$orig_lang = $orig ? $orig['language'] : $src_lang;

			$inserted = self::with_term_lang( $code, static function () use ( $name, $tax, $slug, $desc, $parent ) {
				return wp_insert_term( wp_slash( $name ), $tax, [ 'slug' => $slug, 'description' => wp_slash( $desc ), 'parent' => $parent ] );
			} );

			if ( is_wp_error( $inserted ) || empty( $inserted['term_taxonomy_id'] ) ) {
				return new WP_Error( 'term_failed', 'Creating the term failed: ' . ( is_wp_error( $inserted ) ? $inserted->get_error_message() : 'no term returned' )
					. ' — pass a different name or slug.' . ( $register ? ' (The source was registered as "' . $src_lang . '".)' : '' ) );
			}

			$new_id = (int) $inserted['term_id'];
			$new_tt = (int) $inserted['term_taxonomy_id'];

			self::set_lang( $new_tt, $etype, $code, $trid, $orig_lang );
			$new_row = self::row( $new_tt, $etype );

			if ( ! $new_row || $new_row['trid'] !== (int) $trid || $new_row['language'] !== $code || count( self::rows_in_lang( self::group( $trid, $etype ), $code ) ) !== 1 ) {
				if ( $new_row ) {
					self::drop_row( $new_row, $etype );
				}

				wp_delete_term( $new_id, $tax );

				return new WP_Error( 'link', 'The new term could not be linked as the "' . $code . '" translation and was deleted.' );
			}

			$info = self::term_info( $new_id, $tax );
			$out  = [
				'new_term_id'      => $new_id,
				'term_taxonomy_id' => $new_tt,
				'existing'         => false,
				'language'         => $code,
				'trid'             => (int) $trid,
				'taxonomy'         => $tax,
				'name'             => $info['name'] ?? $name,
				'slug'             => $info['slug'] ?? $slug,
				'source'           => [ 'term_id' => $ref['id'], 'language' => $src_lang, 'registered' => $register ],
			];

			if ( $parent_info ) {
				$out['parent'] = $parent_info;
			}

			$warnings = [];

			if ( ! $L['active'] ) {
				$warnings[] = 'Language "' . $code . '" is disabled on this site; the term is not visible until it is enabled.';
			}

			if ( isset( $info['slug'] ) && $info['slug'] !== $slug ) {
				$warnings[] = 'The slug "' . $slug . '" was taken, so WordPress saved "' . $info['slug'] . '"; pass slug to choose another one.';
			}

			if ( $warnings ) {
				$out['warnings'] = $warnings;
			}

			/** This action is documented in includes/class-wp-loc-terms.php */
			do_action( 'wp_loc_term_translation_created', $new_id, (int) $ref['id'], $tax, self::lang_arg( $code ), self::lang_arg( $src_lang ) );

			return $out;
		} );
	}

	// ── Create helpers ───────────────────────────────────────────────────

	/** The non-trashed target-language translation of a post or attachment (itself when already in it), or null. */
	private static function post_in_lang( $post_id, $code ): ?int {
		$post = get_post( (int) $post_id );

		if ( ! $post ) {
			return null;
		}

		$etype = 'post_' . $post->post_type;
		$row   = self::row( $post->ID, $etype );

		if ( ! $row ) {
			return null;
		}

		if ( $row['language'] === $code ) {
			return (int) $post->ID;
		}

		foreach ( self::rows_in_lang( self::group( $row['trid'], $etype ), $code ) as $member ) {
			$translation = get_post( $member['element_id'] );

			if ( $translation && $translation->post_status !== 'trash' ) {
				return (int) $translation->ID;
			}
		}

		return null;
	}

	/** The target-language translation (term_id) of a term, or null. */
	private static function term_in_lang( $term_id, $tax, $code ): ?int {
		$term = self::term_row( $term_id, $tax );

		if ( ! $term ) {
			return null;
		}

		$etype = 'tax_' . $tax;
		$row   = self::row( $term['term_taxonomy_id'], $etype );

		if ( ! $row ) {
			return null;
		}

		if ( $row['language'] === $code ) {
			return (int) $term_id;
		}

		foreach ( self::rows_in_lang( self::group( $row['trid'], $etype ), $code ) as $member ) {
			$translation = self::term_row_by_tt( $member['element_id'], $tax );

			if ( $translation ) {
				return $translation['term_id'];
			}
		}

		return null;
	}

	/** term_ids of an object in a taxonomy (raw: no language filter hides any of them). */
	private static function object_term_ids( $object_id, $tax ): array {
		global $wpdb;

		return array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare(
			"SELECT tt.term_id FROM {$wpdb->term_relationships} tr
			 INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id
			 WHERE tr.object_id = %d AND tt.taxonomy = %s ORDER BY tt.term_id",
			(int) $object_id,
			(string) $tax
		) ) );
	}

	/**
	 * Run $fn with WP-LOC's term language handling off: the term-query language filter is removed
	 * (term_exists() sees every language) and get_term() no longer swaps a term for its
	 * current-language translation (wpml_disable_term_adjust_id).
	 */
	private static function with_raw_terms( callable $fn ) {
		$terms   = WP_LOC::instance()->terms;
		$removed = null;

		if ( is_object( $terms ) ) {
			$priority = has_filter( 'terms_clauses', [ $terms, 'filter_terms_clauses' ] );

			if ( $priority !== false ) {
				remove_filter( 'terms_clauses', [ $terms, 'filter_terms_clauses' ], $priority );
				$removed = $priority;
			}
		}

		add_filter( 'wpml_disable_term_adjust_id', '__return_true', 999 );

		try {
			return $fn();
		} finally {
			remove_filter( 'wpml_disable_term_adjust_id', '__return_true', 999 );

			if ( $removed !== null ) {
				add_filter( 'terms_clauses', [ $terms, 'filter_terms_clauses' ], $removed, 3 );
			}
		}
	}

	/** Run $fn (a term insert) in the target language's term context, so duplicate checks and slugs are per language. */
	private static function with_term_lang( string $code, callable $fn ) {
		$slug = self::lang_arg( $code );

		wp_loc_get_current_lang(); // pin the request language before $_REQUEST changes

		$saved = [];

		foreach ( [ 'lang', 'wp_loc_lang' ] as $key ) {
			$saved[ $key ]   = array_key_exists( $key, $_REQUEST ) ? $_REQUEST[ $key ] : null;
			$_REQUEST[ $key ] = $slug;
		}

		$callback = static function ( $args ) use ( $slug ) {
			if ( is_array( $args ) && ! isset( $args['lang'] ) ) {
				$args['lang'] = $slug;
			}

			return $args;
		};

		add_filter( 'get_terms_args', $callback, 999 );

		try {
			return $fn();
		} finally {
			remove_filter( 'get_terms_args', $callback, 999 );

			foreach ( $saved as $key => $value ) {
				if ( $value === null ) {
					unset( $_REQUEST[ $key ] );
				} else {
					$_REQUEST[ $key ] = $value;
				}
			}
		}
	}

	/**
	 * Should a meta key stay out of the translation? Filter wp_loc_translation_skip_meta($skip, $key,
	 * $source_post, $code); the simple_mcp_translation_skip_meta filter of the earlier Simple MCP tool
	 * is still honoured.
	 */
	private static function skip_meta( string $key, \WP_Post $post, string $code ): bool {
		$skip = in_array( $key, self::SKIP_META, true );

		if ( ! $skip ) {
			foreach ( self::SKIP_META_PREFIX as $prefix ) {
				if ( strpos( $key, $prefix ) === 0 ) {
					$skip = true;
					break;
				}
			}
		}

		$skip = (bool) apply_filters( 'simple_mcp_translation_skip_meta', $skip, $key, $post, $code );

		return (bool) apply_filters( 'wp_loc_translation_skip_meta', $skip, $key, $post, $code );
	}

	/** Unserialize a stored meta value without instantiating objects; null when it holds objects or is broken. */
	private static function raw_value( $raw ) {
		if ( ! is_serialized( $raw ) ) {
			return $raw;
		}

		$value = @unserialize( $raw, [ 'allowed_classes' => false ] );

		if ( $value === false && $raw !== 'b:0;' ) {
			return null;
		}

		$has_object = false;
		$walk       = static function ( $item ) use ( &$walk, &$has_object ) {
			if ( is_object( $item ) ) {
				$has_object = true;
				return;
			}

			if ( is_array( $item ) ) {
				foreach ( $item as $child ) {
					$walk( $child );
				}
			}
		};
		$walk( $value );

		return $has_object ? null : $value;
	}

	/**
	 * Point post-level ACF image/file/gallery/post_object/relationship/page_link/taxonomy values of the
	 * copy at their target-language translations. Updates the copied rows in place.
	 *
	 * @return array [mapped [{key, field}], unmapped [{key, id}]]
	 */
	private static function map_acf_relations( int $new, array $copied, string $code ): array {
		global $wpdb;

		if ( ! function_exists( 'acf_get_field' ) || ! $copied ) {
			return [ [], [] ];
		}

		$by_key = [];

		foreach ( $copied as $meta_id => $pair ) {
			$by_key[ $pair[0] ][] = $meta_id;
		}

		$mapped   = [];
		$unmapped = [];

		foreach ( $copied as $meta_id => $pair ) {
			[ $key, $raw ] = $pair;

			if ( $key === '' || $key[0] === '_' || empty( $by_key[ '_' . $key ] ) ) {
				continue;
			}

			$field_key = (string) $copied[ $by_key[ '_' . $key ][0] ][1];

			if ( strpos( $field_key, 'field_' ) !== 0 ) {
				continue;
			}

			$field = acf_get_field( $field_key );

			if ( ! is_array( $field ) || ! in_array( $field['type'] ?? '', self::ACF_REL_TYPES, true ) ) {
				continue;
			}

			$value = self::raw_value( $raw );

			if ( $value === null || $value === '' || $value === [] ) {
				continue;
			}

			$missing = [];
			$changed = false;
			$one     = static function ( $id ) use ( $field, $code, &$missing, &$changed ) {
				if ( ! is_numeric( $id ) || (int) $id <= 0 ) {
					return $id;
				}

				$target = self::map_rel_id( (int) $id, $field, $code );

				if ( $target === null ) {
					$missing[] = (int) $id;
					return $id;
				}

				if ( $target !== (int) $id ) {
					$changed = true;
					return is_string( $id ) ? (string) $target : $target;
				}

				return $id;
			};

			$new_value = is_array( $value ) ? array_map( $one, $value ) : $one( $value );

			foreach ( $missing as $id ) {
				$unmapped[] = [ 'key' => $key, 'id' => $id ];
			}

			if ( $changed ) {
				$wpdb->update( $wpdb->postmeta, [ 'meta_value' => is_array( $new_value ) ? serialize( $new_value ) : (string) $new_value ], [ 'meta_id' => (int) $meta_id ], [ '%s' ], [ '%d' ] );
				$mapped[] = [ 'key' => $key, 'field' => (string) ( $field['name'] ?? $key ) ];
			}
		}

		wp_cache_delete( $new, 'post_meta' );

		return [ array_slice( $mapped, 0, 100 ), array_slice( $unmapped, 0, 100 ) ];
	}

	/** Target-language ID for one relational value; the same ID when the object is not translated; null when a translation is missing. */
	private static function map_rel_id( int $id, array $field, string $code ): ?int {
		$type = $field['type'] ?? '';

		if ( $type === 'taxonomy' ) {
			$tax = (string) ( $field['taxonomy'] ?? '' );

			if ( ! self::is_translatable_taxonomy( $tax ) || ! self::term_row( $id, $tax ) ) {
				return $id;
			}

			return self::term_in_lang( $id, $tax, $code );
		}

		$post = get_post( $id );

		if ( ! $post ) {
			return $id;
		}

		if ( in_array( $type, [ 'image', 'file', 'gallery' ], true ) && $post->post_type !== 'attachment' ) {
			return $id;
		}

		if ( $post->post_type !== 'attachment' && ! self::is_translatable_post_type( $post->post_type ) ) {
			return $id;
		}

		if ( ! self::row( $post->ID, 'post_' . $post->post_type ) ) {
			return $id; // never registered → language-neutral
		}

		return self::post_in_lang( $id, $code );
	}

	/**
	 * Did the body save as intended: byte for byte, or — since ACF re-encodes block attributes on
	 * content_save_pre — the same parsed block tree. kses changes give different values and stay visible.
	 */
	private static function content_matches( $saved, $expected ): bool {
		$saved    = (string) $saved;
		$expected = (string) $expected;

		if ( $saved === $expected ) {
			return true;
		}

		if ( strpos( $expected, '<!-- wp:' ) === false && strpos( $saved, '<!-- wp:' ) === false ) {
			return false;
		}

		return self::same_tree( parse_blocks( $saved ), parse_blocks( $expected ) );
	}

	/** Strict deep comparison of parse_blocks trees: strings byte for byte, numbers by value, arrays key by key. */
	private static function same_tree( $a, $b, int $depth = 0 ): bool {
		if ( is_array( $a ) || is_array( $b ) ) {
			if ( ! is_array( $a ) || ! is_array( $b ) || count( $a ) !== count( $b ) || $depth > 512 ) {
				return false;
			}

			foreach ( $a as $key => $value ) {
				if ( ! array_key_exists( $key, $b ) || ! self::same_tree( $value, $b[ $key ], $depth + 1 ) ) {
					return false;
				}
			}

			return true;
		}

		if ( ( is_int( $a ) || is_float( $a ) ) && ( is_int( $b ) || is_float( $b ) ) ) {
			return $a == $b;
		}

		return $a === $b;
	}
}
