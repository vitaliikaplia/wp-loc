<?php

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * WP-LOC's tools for AI agents as WordPress Abilities (WordPress 6.9+).
 *
 * Registers wp-loc/get-translations, wp-loc/link-translation and wp-loc/create-translation with
 * the MCP flag of the official WordPress MCP Adapter (meta.mcp.public), so an MCP server offers
 * them to agents: Simple MCP 2.7+ shows them as the tools wp-loc-get-translations and so on and
 * hides its own built-in wploc_* copies of them for users who see plugin tools. Without an MCP
 * server the abilities just sit in the core registry. The logic is WP_LOC_Translation_Tools.
 *
 * Off with the "AI agents" option on the Integrations tab; on WordPress before 6.9 nothing loads.
 */
class WP_LOC_Abilities {

	const CATEGORY = 'wp-loc';

	public function __construct() {
		if ( ! self::is_available() || ! WP_LOC_Admin_Settings::are_abilities_enabled() ) {
			return;
		}

		add_action( 'wp_abilities_api_categories_init', [ $this, 'register_category' ] );
		add_action( 'wp_abilities_api_init', [ $this, 'register_abilities' ] );
	}

	/** Whether this WordPress has the Abilities API (6.9+). */
	public static function is_available(): bool {
		return function_exists( 'wp_register_ability' ) && function_exists( 'wp_register_ability_category' );
	}

	public function register_category(): void {
		wp_register_ability_category( self::CATEGORY, [
			'label'       => 'WP-LOC',
			'description' => 'Languages and translations of the site.',
		] );
	}

	public function register_abilities(): void {
		$kind = [
			'type'        => 'string',
			'enum'        => [ 'post', 'term' ],
			'description' => 'post (IDs are post IDs) or term (IDs are term_id). Optional: implied by element_type/taxonomy; without any of them an existing post with that ID wins.',
		];
		$taxonomy = [
			'type'        => 'string',
			'description' => 'Taxonomy of a term (e.g. category); implies kind "term". Auto-detected from the term_id when omitted.',
		];
		$element_type = [
			'type'        => 'string',
			'description' => 'Optional legacy selector: post_{post type} or tax_{taxonomy} (a bare post type / taxonomy name is accepted). Must match the real object.',
		];
		$lang = [
			'type'        => 'string',
			'description' => 'Target language: wpml code, URL slug or locale ("uk", "ua", "uk_UA", "en-US"). Unknown or disabled languages are refused.',
		];
		$allow_inactive = [
			'type'        => 'boolean',
			'description' => 'Allow a language that is configured but disabled (default false).',
		];
		$source_language = [
			'type'        => 'string',
			'description' => 'Language of a source that has none registered yet. If the source already has one, it must match.',
		];

		wp_register_ability( 'wp-loc/get-translations', [
			'label'               => 'Get translations',
			'description'         => 'Resolve the translation group of a post or term so you edit the right ID (every language is a separate post/term). '
				. 'kind "post": element_id is a post ID; kind "term": element_id is a term_id (taxonomy auto-detected or given). '
				. 'Without kind/element_type/taxonomy an existing post wins; if a term in a translatable taxonomy has the same term_id the result carries a note (pass kind:"term" for the term). '
				. 'Returns {trid, language, is_original, default_language, translations:{code:{id, status, title, post_type, is_original, language_active}}, missing:[active languages without a translation]}; '
				. 'for terms each member is {term_id, term_taxonomy_id, name, slug, is_original, language_active}. Codes are wpml codes ("uk"), not URL slugs. '
				. 'Members you cannot read are listed as {readable:false} without an ID; trashed members show status "trash"; two elements in one language are reported in conflicts. '
				. 'For posts the result also carries sync_note when saving one translation propagates attributes to the others.',
			'category'            => self::CATEGORY,
			'input_schema'        => [
				'type'                 => 'object',
				'properties'           => [
					'element_id'   => [ 'type' => 'integer', 'description' => 'Post ID, or term_id for kind "term".' ],
					'kind'         => $kind,
					'taxonomy'     => $taxonomy,
					'element_type' => $element_type,
				],
				'required'             => [ 'element_id' ],
				'additionalProperties' => false,
			],
			'execute_callback'    => [ $this, 'execute_get' ],
			'permission_callback' => [ $this, 'can_get' ],
			'meta'                => [
				'mcp'         => [ 'public' => true ],
				'annotations' => [ 'readonly' => true, 'destructive' => false, 'idempotent' => true ],
			],
		] );

		wp_register_ability( 'wp-loc/link-translation', [
			'label'               => 'Link translation',
			'description'         => 'Link an EXISTING post or term as the {lang} translation of a source: puts the target into the source\'s translation group (trid). '
				. 'Both must be the same post type (or taxonomy), translatable, and editable by you (edit_post / edit_term on each); trashed and auto-draft posts are refused. '
				. 'Refused: source equal to target; lang equal to the source\'s language; a slot already held by another element (replace:true detaches that element into its own group — it is kept, not deleted; the group\'s original cannot be replaced); '
				. 'a target that belongs to another group with translations (move:true takes it out; if it was that group\'s original, another member is promoted to original); relabeling the group\'s original. '
				. 'A source without a language needs source_language and is then registered in it (source.registered:true); a source that has one is never relabeled. '
				. 'The result is re-read from the database: {trid, unchanged, source, target:{id, language, previous}, replaced, moved_from, translations, warnings?}. '
				. 'With attribute sync on, warnings flags a target whose status/author/password differ from the source\'s: the next save of any member pushes its own values to the whole group.',
			'category'            => self::CATEGORY,
			'input_schema'        => [
				'type'                 => 'object',
				'properties'           => [
					'source_id'       => [ 'type' => 'integer', 'description' => 'Post ID or term_id of the source.' ],
					'target_id'       => [ 'type' => 'integer', 'description' => 'Post ID or term_id to link as the {lang} translation.' ],
					'lang'            => $lang,
					'kind'            => $kind,
					'taxonomy'        => $taxonomy,
					'element_type'    => $element_type,
					'source_language' => $source_language,
					'replace'         => [ 'type' => 'boolean', 'description' => 'Detach the element currently holding the {lang} slot (default false).' ],
					'move'            => [ 'type' => 'boolean', 'description' => 'Allow taking the target out of another translation group (default false).' ],
					'allow_inactive'  => $allow_inactive,
				],
				'required'             => [ 'source_id', 'target_id', 'lang' ],
				'additionalProperties' => false,
			],
			'execute_callback'    => [ $this, 'execute_link' ],
			'permission_callback' => [ $this, 'can_link' ],
			'meta'                => [
				'mcp'         => [ 'public' => true ],
				'annotations' => [ 'readonly' => false, 'destructive' => true, 'idempotent' => true ],
			],
		] );

		wp_register_ability( 'wp-loc/create-translation', [
			'label'               => 'Create translation',
			'description'         => 'Create the {lang} translation of a source post by duplicating it, and link the copy into the source\'s translation group. '
				. 'If the {lang} slot is already held, that translation is returned (existing:true) instead; a trashed post in the slot is an error. '
				. 'Copies title, content (checked: content_verified), excerpt, author, password, menu_order, comment/ping status, page template and the post meta as stored (except edit locks, old slugs/dates, trash markers, backups, multilingual internals). '
				. 'Maps to their {lang} translations: the parent (if it has none, the call fails unless you pass parent, 0 = top level), the featured image, terms of translatable taxonomies (others keep the same terms) '
				. 'and post-level ACF image/file/gallery/post_object/relationship/page_link/taxonomy IDs; IDs without a translation are kept and listed as unmapped. '
				. 'Status: with attribute sync on, the copy takes the source\'s status and dates (a published source gives a published, still untranslated copy) and a different status is refused, '
				. 'because saving any translation pushes status, author and password to the whole group; otherwise status defaults to "draft" ("future" copies the source\'s date). '
				. 'A non-draft copy gets the source\'s slug made unique within {lang}; a draft keeps an empty slug. '
				. 'Only translatable, non-system post types; trashed sources are refused. A source without a language needs source_language. '
				. 'kind "term" creates a term translation instead (name/slug/description default to the source\'s; the parent is mapped the same way). '
				. 'Next: translate the copy (new_id) — its texts and fields are still in the source language.',
			'category'            => self::CATEGORY,
			'input_schema'        => [
				'type'                 => 'object',
				'properties'           => [
					'source_id'       => [ 'type' => 'integer', 'description' => 'Source post ID (or term_id for kind "term").' ],
					'lang'            => $lang,
					'kind'            => [ 'type' => 'string', 'enum' => [ 'post', 'term' ], 'description' => 'post (default) or term.' ],
					'taxonomy'        => $taxonomy,
					'element_type'    => $element_type,
					'status'          => [ 'type' => 'string', 'description' => 'Posts: status of the copy (default "draft"; with attribute sync on it must equal the source\'s status).' ],
					'source_language' => $source_language,
					'parent'          => [ 'type' => 'integer', 'description' => 'Parent of the copy: an existing {lang} post/term ID, or 0 for top level. Default: the {lang} translation of the source\'s parent.' ],
					'name'            => [ 'type' => 'string', 'description' => 'Terms only: name of the new term (default: the source\'s name).' ],
					'slug'            => [ 'type' => 'string', 'description' => 'Terms only: slug of the new term (default: the source\'s slug, made unique).' ],
					'description'     => [ 'type' => 'string', 'description' => 'Terms only: description (default: the source\'s).' ],
					'allow_inactive'  => $allow_inactive,
				],
				'required'             => [ 'source_id', 'lang' ],
				'additionalProperties' => false,
			],
			'execute_callback'    => [ $this, 'execute_create' ],
			'permission_callback' => [ $this, 'can_create' ],
			'meta'                => [
				'mcp'         => [ 'public' => true ],
				'annotations' => [ 'readonly' => false, 'destructive' => false, 'idempotent' => true ],
			],
		] );
	}

	// Permission checks: native capabilities on the objects the call names. Input that does not
	// resolve to an element passes here, so the call itself can tell the agent what is wrong.

	public function can_get( $input = null ): bool {
		$input = (array) $input;
		$ref   = WP_LOC_Translation_Tools::resolve_element( $input['element_id'] ?? 0, $input, null, true );

		if ( is_wp_error( $ref ) ) {
			return is_user_logged_in();
		}

		return $ref['kind'] === 'post'
			? WP_LOC_Translation_Tools::can_read_post( $ref['id'] )
			: WP_LOC_Translation_Tools::can_read_term( $ref['id'], $ref['type'] );
	}

	public function can_link( $input = null ): bool {
		$input  = (array) $input;
		$source = WP_LOC_Translation_Tools::resolve_element( $input['source_id'] ?? 0, $input );

		if ( is_wp_error( $source ) ) {
			return is_user_logged_in();
		}

		if ( WP_LOC_Translation_Tools::can_edit_ref( $source ) !== true ) {
			return false;
		}

		$target = WP_LOC_Translation_Tools::resolve_element(
			$input['target_id'] ?? 0,
			[ 'kind' => $source['kind'] ] + ( $source['kind'] === 'term' ? [ 'taxonomy' => $source['type'] ] : [] )
		);

		return is_wp_error( $target ) || WP_LOC_Translation_Tools::can_edit_ref( $target ) === true;
	}

	public function can_create( $input = null ): bool {
		$input  = (array) $input;
		$source = WP_LOC_Translation_Tools::resolve_element( $input['source_id'] ?? 0, $input, 'post' );

		if ( is_wp_error( $source ) ) {
			return is_user_logged_in();
		}

		return WP_LOC_Translation_Tools::can_edit_ref( $source ) === true;
	}

	public function execute_get( $input = null ) {
		return WP_LOC_Translation_Tools::get_translations( (array) $input );
	}

	public function execute_link( $input = null ) {
		return WP_LOC_Translation_Tools::link_translation( (array) $input );
	}

	public function execute_create( $input = null ) {
		return WP_LOC_Translation_Tools::create_translation( (array) $input );
	}
}
