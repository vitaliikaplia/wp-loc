# WP-LOC — Lightweight Multilingual Plugin

## What is this?
A lightweight, WPML/ICL-compatible WordPress multilingual plugin: it reuses the WPML `{prefix}icl_translations` table and ships an `icl_*`/`wpml_*` compatibility layer, so it can replace WPML and migrate existing multilingual data with near-zero effort.

## Architecture

### Plugin structure
```
wp-loc.php                          → Entry point, constants, activation/deactivation hooks
uninstall.php                       → Full cleanup on plugin deletion (options, meta, icl_translations table)
includes/
  class-wp-loc.php                  → Singleton orchestrator, loads all modules
  class-wp-loc-db.php               → icl_translations table CRUD (the core)
  class-wp-loc-db-optimization-wizard.php → Activation/admin wizard for adopting compatible multilingual data and cleaning obsolete service data
  class-wp-loc-language-registry.php → Language code/locale/slug/name/flag normalization registry used by migration and language helpers
  class-wp-loc-languages.php        → Language config stored in wp_options
  class-wp-loc-routing.php          → Rewrite rules, URL lang prefix, locale switching
  class-wp-loc-admin.php            → Admin bar switcher, post/link filtering, translation metabox, AJAX translation and AI title controls
  class-wp-loc-admin-languages.php  → Multilingual > Languages page (WP_List_Table, AJAX sort, language file cleanup)
  class-wp-loc-admin-settings.php   → Multilingual > Settings page (content, switcher, integrations, and AI tabs)
  class-wp-loc-ai.php               → WordPress AI Client / Connectors adapter and HTML-safe translation helpers
  class-wp-loc-content.php          → Auto-create translation drafts, sync shared props/taxonomies, cascade permanent post deletion
  class-wp-loc-frontend.php         → Lang switcher, hreflang, canonical, html lang attr, non-translatable post type support
  class-wp-loc-menus.php            → WPML-like multilingual nav menus, menu translation groups, menu item cloning, menu assignment mapping
  class-wp-loc-menu-sync.php        → Multilingual > Tools page (tabs for WP Menus Sync and Config Migration)
  class-wp-loc-options.php          → Localized WP options (blogname, page_on_front, custom registered options, etc.)
  class-wp-loc-compat.php           → Third-party compatibility layer (icl_object_id, $sitepress, wpml_* filters/actions, nav_menu/object language helpers)
  class-wp-loc-acf.php              → ACF field/group translation compatibility (DB, local JSON, PHP-registered groups) + ACFML-like options post_id routing (`options_{lang}`)
  class-wp-loc-yoast.php            → Yoast SEO compatibility layer (localized global options, primary terms, term SEO meta, sitemap alternates, indexable invalidation)
  class-wp-loc-media.php            → Linked attachment translations, media filtering, featured-image mapping, deletion/file safety
  class-wp-loc-terms.php            → Taxonomy/term translations, term admin/AI UI, term routing, duplicate slugs per language
  class-wp-loc-timber.php           → Timber/Twig integration for switcher helpers
  class-wp-loc-github-updater.php   → GitHub branch-based updater using the remote plugin header Version value
  class-wp-loc-duplicate-post.php   → Yoast Duplicate Post integration: clones the whole translation group and links copies as a new group
assets/
  flags/                           → SVG country flags
  scss/admin.scss                  → SCSS source (auto-compiled by Mini Prepros)
  scss/_db-optimization-wizard.scss → Wizard SCSS partial imported by admin.scss
  css/admin.min.css                → Compiled/minified CSS
  js/admin.js                      → Admin JS source
  js/db-optimization-wizard.js      → Wizard JS source prepended into admin.js by Mini Prepros
  js/admin.min.js                  → Minified JS (auto-compiled by Mini Prepros)
languages/                         → POT template and PO/MO translation files (uk, ru_RU)
```

### Admin screens and menu order
- `admin.php?page=wp-loc` — **Languages**, the top-level **Multilingual** page and first submenu (top-level menu position `80`)
- `admin.php?page=wp-loc-tools` — **Tools**, second submenu; tabs are `menu-sync` and `config-migration`
- `admin.php?page=wp-loc-settings` — **Settings**, third submenu; tabs are `content`, `switcher`, `integrations`, and `ai`
- All three screens require the `manage_options` capability. Their submenu order is enforced by `admin_menu` priorities `10`, `15`, and `20` respectively.

### Key design decisions
- **DB**: Uses `{prefix}icl_translations` table with **strict WPML-compatible schema** — same table name, same columns (translation_id, element_type, element_id, trid, language_code, source_language_code), same indexes, same element_type format (`post_page`, `post_post`, `post_{cpt}`, `tax_{taxonomy}`). This ensures zero-effort bidirectional migration with WPML. **Any changes to the DB schema MUST preserve this compatibility.**
- **Language config**: Stored in `wp_options` key `wp_loc_languages`, keyed by slug. NOT in a separate DB table.
- **Default language config**: Stored in `wp_options` key `wp_loc_default_language` when explicitly imported or configured. If absent, WP-LOC falls back to the WordPress `WPLANG` locale match and then the first active language.
- **Language registry**: `WP_LOC_Language_Registry` is the central mapping source for external codes/locales/display names/flags. Migration and language helpers should use it before adding special-case language logic. It normalizes aliases such as `uk` → `ua` and legacy `iw` → `he`, and supports broad WordPress locale coverage.
- **Slug vs compatibility code**: URL slugs and compatibility/database language codes are separate. Example: Ukrainian uses URL slug `ua`, locale `uk`, and compatible language code `uk`. DB rows in `icl_translations.language_code` should use the compatible code, while WP-LOC public helpers return internal slugs unless they intentionally mimic WPML APIs.
- **URL structure**: Default language has no prefix. Additional languages: `/{slug}/page-name/`.
- **Ukrainian slug**: `uk` locale maps to `ua` slug via `WP_LOC_Languages::$locale_slug_map`. Filterable via `wp_loc_locale_slug_map`.
- **Admin language**: Cookie-based (`admin_lang` cookie stores WP locale).
- **No post meta for translations**: Everything goes through `icl_translations`. No `_lang`, no `_translation_group`.
- **Activation behavior**: `wp-loc.php` deactivates known conflicting multilingual add-ons on activation. It does not delete plugin files or database tables.
- **GitHub updater**: `WP_LOC_GITHUB_BRANCH` points to the branch used for update checks (`master` by default). `WP_LOC_GitHub_Updater` plugs into WordPress' native plugin update transient, reads the remote `wp-loc.php` header from `vitaliikaplia/wp-loc`, compares the remote `Version:` header with `WP_LOC_VERSION`, caches checks in a site transient, and provides the branch ZIP as the update package. It does not run its own updater loop, so standard WordPress update disabling hooks still control whether updates are checked/applied.
- **Database Optimization Wizard**: Activation sets `wp_loc_db_optimization_wizard_status` to `pending`. The wizard opens for admins until completed or dismissed, has scan/apply/dismiss AJAX endpoints, imports compatible languages/display names/switcher display names, adopts existing `icl_translations` links, imports detected translatable post types/taxonomies into WP-LOC settings, and removes obsolete service data only after explicit confirmation.
- **Wizard default language import**: The wizard reads the legacy multilingual default language from stored sitepress settings and writes it to `wp_loc_default_language` after language mapping is applied, so the no-prefix URL language matches the migrated site.
- **Wizard language mapping**: Scan output includes detected source languages, normalized target languages, match confidence, and `language_targets`. Apply accepts `language_mapping` JSON and validates that mapped target languages are valid and unique before changing scan data or cleaning anything.
- **Wizard copy**: User-facing wizard text must avoid naming a replaced third-party plugin directly; use generic wording such as "another multilingual plugin" or "legacy multilingual data".
- **Term translations**: Taxonomies use the same WPML-compatible `icl_translations` table with `element_type = tax_{taxonomy}` and `element_id = term_taxonomy_id` (not `term_id`). This mapping must stay WPML-compatible.
- **Nav menus**: Menus are WPML-compatible translation groups stored as `tax_nav_menu` rows using `term_taxonomy_id`. Menu items are stored as `post_nav_menu_item` rows. Theme menu locations are normalized to default-language menu IDs and resolved back to the current language on read.
- **Language management flow**: No separate "Add Language" UI. Languages are added automatically when the user installs a language via WP General Settings (`on_wplang_change` hook). Deleting via Multilingual > Languages removes the language AND its `.mo`/`.po`/`.json`/`.l10n.php` files so it also disappears from WP General Settings.
- **Non-translatable post types**: Posts not in `icl_translations` still work with language URL prefixes (LEFT JOIN fallback in routing and frontend filtering).
- **Query filtering**: `WP_LOC_Frontend::filter_posts_by_language()` filters main, secondary, REST, AJAX, and Gutenberg preview `WP_Query` calls for translatable post types when `suppress_filters` is false. Do not restrict it back to only the main query; WPML-style theme queries rely on this behavior.
- **Frontend AJAX language context**: `admin-ajax.php` is technically an admin request, but frontend AJAX handlers must use the frontend language. `WP_LOC_Routing` persists `wp_loc_current_language`, `wp_loc_current_locale`, `_icl_current_language`, and `wp-wpml_current_language` cookies, resolves language from request/referer/cookie, and switches locale on AJAX bootstrap. Do not make frontend AJAX fall back to `wp_loc_get_admin_lang()`.
- **Referer outranks the cookie for AJAX** (`get_ajax_language_context()`, 1.7.0). The referer is the URL of the page the request came from, so it states the language the visitor is looking at right now. The cookie is written during a render, and a page served from a full-page cache never renders: PHP does not run, no `Set-Cookie` goes out, and the cookie can lag a language switch behind. No shared cache can emit `Set-Cookie` without handing one visitor's cookies to the next, so no caching layer can fix this from its side. The cookie stays as the fallback for requests that arrive without a usable referer (`Referrer-Policy: no-referrer`, stripping proxies, some extensions).
- **A prefix-less same-site referer means the default language** (`get_referer_language_context()`). The default language is precisely the one served without a URL prefix, so returning `null` for it would hand every default-language page back to the cookie — exactly where a stale cookie does its damage. Referer parsing strips the home path first (subdirectory installs), accepts the site-relative `_wp_http_referer` value that `wp_get_referer()` returns, and ignores cross-origin referers.
- **Frontend-vs-admin AJAX detection**: only a referer pointing into wp-admin marks a request as admin (`is_frontend_ajax_request()`). A request with no referer at all counts as frontend. Do not key this on the presence of a language cookie — that classifies a first-time visitor, who has no cookies yet, as an admin request and silently skips localized option lookups for them. Admin/frontend referer matching compares host and path separately, so a referer differing from `admin_url()` only by scheme (TLS-terminating proxy) is still recognized.
- **Language resolution must survive re-entry** (1.7.1). Resolving the current language reads options: `is_frontend_ajax_request()` reaches `get_option()` through `wp_get_referer()` → `wp_validate_redirect()` → `home_url()` and through `admin_url()`, and `get_current_lang()` reads language configuration. Third-party code that filters options **and** asks WP-LOC which language it is in — a theme registering a generic `pre_option` filter that calls `$sitepress->get_current_language()` is the real-world case — re-enters these methods, and the two call each other until the process runs out of memory. Both `WP_LOC_Routing::get_current_lang()` and `WP_LOC_Routing::is_frontend_ajax_request()` therefore carry a `static $resolving` guard, reset in `finally`. Re-entrant calls answer with the default language and `true` respectively; the outer call still computes and memoizes the real answer. **Both guards are load-bearing** — either one alone leaves a live cycle (verified by disabling each in turn). Any new option read inside language resolution inherits this hazard, so keep the guards and prefer `WP_LOC_Languages::get_raw_option()`-style direct SQL for anything on the critical path, as `get_default_language()` already does.
- **Never write a cookie whose value has not changed** (`persist_frontend_language_context()`, 1.6.0). `set_locale()` runs on every frontend request, so an unconditional write meant four `Set-Cookie` headers on every page view. That is invisible to the visitor but fatal to shared caching: a response carrying `Set-Cookie` is personalised by definition, and CDNs will not store it — the site could never be cached at the edge. The write is now guarded by a value comparison. This is safe because language comes from the URL prefix, not the cookie; the cookie only carries context into same-origin AJAX, and `get_referer_language_context()` already covers the case where it is absent. Since 1.7.0 the referer is consulted before the cookie, which is what makes this safe on a cached page, where no write happens at all.
- **Runtime language switching**: `do_action( 'wpml_switch_language', $lang )` and `$sitepress->switch_lang( $lang )` must temporarily switch WP-LOC's current language and WordPress locale. This is required for background/admin flows such as payment webhooks, cron notifications, and transactional emails that render content in a user's preferred frontend language. Code that switches language is expected to switch back to the previous/default language after rendering.
- **Localized options compatibility**: Options registered through either `wp_loc_multilingual_options` or compatible `wpml_multilingual_options` must be read per language on the frontend, frontend AJAX, and explicit runtime language-switch contexts. Admin option filtering must support standard settings pages and custom Settings submenu pages. Option lookup must try compatible language-code suffixes and internal URL-slug suffixes, so `ua` URL setups can still read legacy-compatible `*_uk` option rows.
- **Runtime translatable detection**: Settings merge configured post types/taxonomies with element types detected from `icl_translations`, even when saved settings already exist. This keeps migrated custom post types/taxonomies language-filtered after partial/older settings saves, and keeps the Settings UI checked for detected types such as `feedback`.
- **Detected translation visibility**: The Content Translation settings show the number of existing `icl_translations` records next to each detected post type. These counts explain why runtime detection keeps an unchecked/previously configured post type multilingual. Taxonomies are detected and merged too, but currently do not display counts.
- **Selectable content types**: Multilingual settings list public post types/taxonomies and all registered custom non-public post types/taxonomies, including custom objects without an admin UI. Internal non-public WordPress objects and specially handled objects such as attachments, revisions, nav menu items, `nav_menu`, and `post_format` remain excluded.
- **Native link picker**: `WP_LOC_Admin::filter_link_query_by_language()` scopes WordPress's `wp_link_query()` results to the current editor language for translatable post types. This also covers ACF link fields that invoke WordPress's native Insert/edit Link modal.
- **AI title controls**: Post list row actions, Gutenberg title buttons, and Classic Editor title buttons must render for every post type accepted by `WP_LOC_Admin_Settings::is_translatable()`. Do not special-case only `post`/`page` or restrict title translation UI by editor mode beyond choosing the Gutenberg vs classic control.
- **Post lifecycle**: Shared post attributes include `post_status`, so Trash state follows the group when attribute synchronization is enabled. `WP_LOC_Content::handle_delete_post()` always cascades a permanent deletion across every sibling of a translatable post and removes all associated `icl_translations` rows; non-translatable types only have the deleted element row cleaned.
- **Multilingual media**: New uploads are registered in the current language and cloned as linked attachment posts for other active languages while sharing `_wp_attached_file` and generated metadata. Admin media list/grid/modal queries are language-filtered, frontend image lookup resolves the current-language attachment, featured-image sync maps translated attachment IDs, and cascade deletion must delete the physical file at most once.
- **Translatable taxonomies**: Enabled from `Multilingual > Settings` and filtered via `wp_loc_translatable_taxonomies`.
- **Term slugs**: The same term slug is allowed in different languages. Uniqueness is enforced per language, not globally.
- **Hierarchical taxonomies**: Parent term relationships are translated and synced across sibling translations. When creating/editing a translated child term, the parent must be mapped to the translated parent in the same language.
- **Post term sync**: For translatable taxonomies assigned to translatable posts, term relationships sync across the whole post translation group in both directions. Saving any translation becomes the source of truth; sibling posts receive the mapped term translations in their own language. Synchronization also runs after `set_object_terms` so REST/Gutenberg term updates made after `save_post` are included. Registered terms from another language are removed from the source post, and a missing target translation must never fall back to attaching the source-language term.
- **Term deletion**: Deleting any translated term cascades to its whole translation group. Default category protection must apply to the full translation group, including row actions, bulk delete, and edit screen delete links.
- **Frontend term URLs**: Taxonomy archives must resolve by translated term slug/path, including nested hierarchical term paths with duplicate slugs across languages. Wrong-language term URLs should 404, and the frontend language switcher must return translated term archive URLs.
- **Yoast term SEO meta must only ever be gap-filled** (`WP_LOC_Yoast::filter_wpseo_taxonomy_meta()`, fixed 1.7.2). `wpseo_taxonomy_meta` is a single option holding every term's SEO meta, and Yoast saves one term by reading that option through `get_option()`, replacing one entry, and writing the whole array back (`WPSEO_Taxonomy_Meta::save_clean_values()` → `get_tax_meta()`/`save_tax_meta()`). **Anything an `option_wpseo_taxonomy_meta` filter injects is therefore persisted on the next term save.** The filter may only fill entries that are missing or empty, always from the group's source-language term, and must never overwrite a sibling that has its own meta — doing so permanently destroys the per-language SEO this integration exists to provide. The result must also not depend on the request language, or the whole group collapses onto whichever language happened to trigger the save. Any code deciding whether a term already has meta, or writing the array back, must read through `get_raw_term_meta_option()` with the filter suspended; reading it filtered is what silently disabled `copy_term_meta_from_source_translation()` before 1.7.2.
- **Frontend hreflang**: `WP_LOC_Frontend::output_hreflang_tags()` should use the native switcher URL resolution for translated singular, front page, posts page, and term archive contexts. `hreflang` values should come from locales (`en-US`, `ru-RU`, `uk`), include `x-default` for the default language URL, and skip untranslated targets. Both emitters (head tags and the Yoast sitemap alternates) pass the value through the `wp_loc_hreflang_code` filter (`($hreflang, $lang_code)`, 1.8.0) so a site can override the emitted code (e.g. region-less `en` instead of `en-US`) without changing the locale config; `x-default` bypasses the filter. (1.8.0)
- **Translated front page permalink = language root** (`WP_LOC_Routing::add_lang_prefix_to_link()`, 1.8.0). WP core's `get_page_link()` collapses only the CURRENT language context's front page to `home_url('/')` (it compares against the localized `page_on_front` option), so `get_permalink()` of a translated front page called from another language context — the Yoast page sitemap `<loc>`, breadcrumb indexables, any cross-language enumeration — leaked the raw slug URL (`/en/home/`, a 301 to `/en/`). The permalink filter now resolves the raw `page_on_front` through the translation group per target language (memoized per request in `$front_page_ids`) and returns the language root (`/`, `/en/`) for any front-page translation regardless of the request's language. Note Yoast CACHES permalinks in its indexables table — after deploying this fix run a Yoast reindex (`wp yoast index --reindex`) or invalidate the affected indexables, or stale `/en/home/` URLs keep serving from the cache.
- **Canonical redirects**: WordPress canonical redirects must never strip or replace a valid non-default language URL prefix. WP-LOC should allow canonical cleanup only when the redirect keeps the requested language prefix intact.
- **Singular routing**: Translated singular URLs must resolve by language, post type, and slug. Frontend by-slug resolution must only consider post types accepted by `is_post_type_viewable()` so a non-public object cannot leak through a matching public slug. For hierarchical pages and hierarchical post types, resolve the full path in the requested language before falling back to WordPress `get_page_by_path()`, because migrated projects may reuse identical parent/child slugs across languages. For pages use `page_id`; for posts/CPTs use `p`, `post_type`, and `name`. Do not collapse CPT matches into `page_id`, because migrated projects may have identical slugs across languages and post types.
- **Compatibility switcher URLs**: `icl_get_languages()` / `wpml_active_languages` should reuse native WP-LOC translated URLs for the current object/archive where possible, including author archives, search results, date archives, core post type archives, pagination, and query arguments, falling back to language home URLs only when no translation exists or settings request fallback behavior.
- **Posts page / front page routing**: Localized `page_on_front` and `page_for_posts` must resolve correctly per language without canonical redirects back to the default language. Theme integrations that previously depended on `ICL_LANGUAGE_CODE` may need a `wp_loc_get_current_lang()` fallback during bootstrap.
- **Posts page editor parity**: On post editor screens, `WP_LOC_Options::filter_admin_options()` must expose the localized `page_on_front` / `page_for_posts` IDs. This lets WordPress itself recognize translated posts pages in Classic Editor and Gutenberg, show the native latest-posts notice, and apply the standard posts-page editor restrictions.
- **ACF options architecture**: ACF options pages are routed through language-aware post IDs like `options_en` / `options_ru`, close to ACFML behavior. `shared` fields stay on the base options post ID and must be ignored when saving translated options pages, `none` fields remain editable shared values on the base options post ID, and `translatable` fields read/write from the translated options post ID. `get_field('options')` and `get_fields('options')` must both work with this model.
- **ACF user fields**: ACF fields attached to WordPress users use WPML-compatible language-suffixed user meta for `translatable` fields, e.g. `author_archive_name_ru` / `author_archive_name_en`, while the default language stays on the canonical meta key. User profile saves preserve the selected admin language so translated user fields do not overwrite default-language values.
- **ACF shared field saves**: Read-only `shared` fields on translated posts, terms, and options pages must be ignored during save. Only the source-language entity may push shared values to its translations; translated entities must never push empty disabled ACF payloads back into the translation group.
- **ACF shared field UI**: `shared` fields should become readonly only for translated entities/options pages, not merely because the admin language differs from the default language. Source-language entities can be non-default languages and must remain editable.
- **ACF config compatibility**: `wp-loc` must read and export ACFML-compatible field group mode (`acfml_field_group_mode`) and field preferences (`wpml_cf_preferences`) consistently whether ACF field groups come from the DB, local JSON, or `acf_add_local_field_group()` PHP registration. Local JSON export should preserve these settings.
- **ACF nav_menu fields**: `nav_menu` ACF fields are mapped through menu translations so option values and formatted field output resolve to the menu in the current language context.
- **ACF group resolution must not re-enter** (`WP_LOC_ACF::get_field_group_for_field()`, fixed 1.8.1). Resolving a sub-field's owning group walks up via `acf_get_field()` on its container, which re-fires `acf/load_field` for that container — whose `normalize_field_translation_settings()` maps straight back over its `sub_fields` and lands in the same lookup. Nothing broke that cycle, so any `repeater` or `group` field recursed until PHP exhausted its memory limit; every ACF options page rendered its metabox title and then died before printing a single field, while `debug.log` filled with a stack trace hundreds of thousands of frames deep. The lookup now keeps a per-parent re-entry stack and a cache of successful resolutions. Only successful lookups are cached — a `null` may be the guard talking, and storing it would poison the entry for every later caller.
- **ACF picker queries**: ACF `post_object`, `page_link`, `relationship`, `taxonomy`, and `nav_menu` picker choices must be scoped to the current editor language. Query hooks should pass `lang` / `suppress_filters=false` so picker results do not mix content from all languages.
- **Duplicate Post integration**: When Yoast Duplicate Post is active, cloning a translatable post must clone its whole translation group. `WP_LOC_Duplicate_Post` hooks `duplicate_post_after_duplicated` (priority 100, after Duplicate Post copies meta/children/attachments/comments/taxonomies), registers the copy as the source of a new `trid`, and duplicates each sibling translation through `duplicate_post_create_duplicate()` so copied data follows the user's Duplicate Post settings, then links each sibling copy into the new group with `source_language_code` pointing at the copied source. During a copy operation it suspends WP-LOC's default new-post registration via `WP_LOC_Content::$suspend_new_post_registration` (toggled on `duplicate_post_pre_copy` / `duplicate_post_post_copy` with a depth counter) so copies are not double-registered or given empty auto-draft translations. The **Rewrite & Republish** flow must stay untouched: it uses Duplicate Post's OOP duplicator, never fires `duplicate_post_after_duplicated`, and merges back into the original, which keeps its existing links. The class only registers Duplicate Post's own hooks, so it is a no-op when the plugin is absent.
- **AI settings**: `Multilingual > Settings > AI` detects providers configured through the WordPress 7 Connectors API, stores the selected provider and per-provider text-generation model, and never stores provider API keys. The opt-in flag for AI-assisted custom nav menu link translation remains on the Content Translation tab.
- **AI runtime**: AI-assisted title, term-name, and custom nav menu link translations use the WordPress 7 AI Client (`wp_ai_client_prompt()`) with the provider/model selected from the configured connector registry. On older WordPress versions, non-AI plugin features continue working and AI calls fail gracefully.
- **AI-assisted menu sync**: When enabled in settings, `WP Menus Sync` attempts to translate custom nav menu links (`custom` items) with AI while preserving URL/target/classes/XFN and tracking source hashes so preview can detect whether custom-link translations are up to date.
- **Config migration tool**: `Multilingual > Tools > Config Migration` scans the active theme / parent theme / active plugins for `wpml-config.xml`, reads only `custom-types` and `taxonomies`, can generate a lightweight `wp-loc-config.xml`, and can remove theme-level `wpml-config.xml` after migration.
- **Assets**: SCSS and JavaScript are auto-compiled by the Mini Prepros PhpStorm watcher using the existing `prepros.config`. All CSS/JS is extracted from PHP into `assets/` — no inline styles or scripts.
- **AJAX operations**: Language sort order auto-saves on drag. Single translation creation via `+` button in metabox (no page reload). `WP Menus Sync` preview/apply runs via AJAX.

### Important functions
- `wp_loc_get_current_lang()` — current language slug (frontend)
- `wp_loc_get_current_locale()` — current WP locale (frontend)
- `wp_loc_get_admin_lang()` — admin-selected language slug
- `wp_loc_get_lang_switcher()` — array of languages with URLs for templates
- `wp_loc_get_language_switcher_html()` / `wp_loc_the_language_switcher()` — ready-to-render frontend switcher markup
- `WP_LOC::instance()->db` — access DB layer for icl_translations queries
- `WP_LOC::instance()->menus` — multilingual nav menus helper / sync engine entry point
- `WP_LOC::instance()->ai` — AI helper / provider wrapper entry point
- `WP_LOC_Languages::locale_to_slug()` — convert WP locale to URL slug (filterable)
- `WP_LOC_Languages::get_display_name()` — get display name for language switchers
- `WP_LOC_Admin_Settings::is_translatable()` / `is_translatable_taxonomy()` — runtime multilingual type checks that include saved and detected configuration
- `WP_LOC_Terms::get_term_translation()` — get translated `term_id` for a target language
- `WP_LOC_Terms::get_term_language()` — get the internal language slug assigned to a term
- `WP_LOC_Terms::get_term_url_for_language()` — build frontend URL for a term translation in a specific language
- `WP_LOC_AI::translate_content()` — translate formatted content while preserving HTML
- `WP_LOC_AI::get_target_language_name()` — normalize a WP-LOC language slug/locale into a stable AI target language label
- `WP_LOC_Language_Registry::normalize_external_language()` — normalize an external language code/locale/name into WP-LOC code, locale, display name, flag, and confidence
- `WP_LOC_Language_Registry::wpml_code_from_slug()` — convert an internal WP-LOC slug into the compatible language code used by legacy APIs/DB rows
- `WP_LOC_Language_Registry::slug_from_wpml_code()` — convert a compatible language code from imported data into the configured WP-LOC URL slug
- `WP_LOC_Language_Registry::get_language_options()` — registry-backed target list for wizard language mapping controls

### Compat layer (class-wp-loc-compat.php)
Only loads when no other multilingual plugin is active (`ICL_SITEPRESS_VERSION` not defined). Provides:
- Functions: `icl_object_id()`, `icl_get_languages()`, `icl_get_default_language()`, `wpml_get_default_language()`, `wpml_get_current_language()`, `wpml_add_translatable_content()`, `wpml_object_id_filter()`
- Filters: `wpml_post_language_details`, `wpml_object_id`, `wpml_current_language`, `wpml_default_language`, `wpml_home_url`, `wpml_active_languages`, `wpml_is_translated_taxonomy`, `wpml_element_trid`, `wpml_get_element_translations`, `wpml_element_language_code`, `wpml_element_language_details`
- Actions: `wpml_switch_language`, `wpml_set_element_language_details`; `WP_LOC_Options` separately consumes compatible `wpml_multilingual_options` registrations
- Constants: `ICL_LANGUAGE_CODE`, `ICL_LANGUAGE_NAME`, plus WPML asset-suppression constants `ICL_DONT_LOAD_NAVIGATION_CSS`, `ICL_DONT_LOAD_LANGUAGE_SELECTOR_CSS`, `ICL_DONT_LOAD_LANGUAGES_JS`
- Global `$sitepress` mock object
- Keeps the compatible `icl_sitepress_settings` default/active-language values synchronized with WP-LOC
- `nav_menu` handling compatible with WPML-style lookups (`icl_object_id`, `wpml_object_id`, `wpml_element_language_code`)
- WPML-like public language APIs return compatible language codes such as `uk`; native WP-LOC URL helpers continue using configured slugs such as `ua`

### Hooks/filters for customization
- `wp_loc_translatable_post_types` — array of post types (default: `['post', 'page']`)
- `wp_loc_translatable_taxonomies` — array of taxonomy slugs with multilingual behavior
- `wp_loc_locale_slug_map` — override locale → slug mapping
- `wp_loc_language_registry` — filter the central external language code/locale/name/flag registry
- `wp_loc_default_multilingual_options` — option names to localize
- `wp_loc_multilingual_options` — action to register an option as multilingual
- `wp_loc_duplicate_translation_group` — enable/disable cloning the translation group during Yoast Duplicate Post copies (default: `true`)

### AJAX endpoints
- `wp_loc_create_translation` — create a single translation for a post+language (used by metabox `+` button)
- `wp_loc_refresh_metabox` — refresh the post translation metabox after an AJAX operation
- `wp_loc_translate_post_title` — AI-translate a post title (and optionally its slug) into a target language
- `wp_loc_create_term_translation` — create a single translation for a term+language
- `wp_loc_refresh_term_translations` — refresh term translation controls after AJAX creation
- `wp_loc_translate_term_name` — AI-translate a term name (and optionally its slug) into a target language
- `wp_loc_save_order` — save language sort order after drag-and-drop
- `wp_loc_menu_sync_preview` — refresh `WP Menus Sync` preview grid
- `wp_loc_menu_sync_apply` — apply selected `WP Menus Sync` operations
- `wp_loc_db_optimization_dismiss` — mark the Database Optimization Wizard as dismissed
- `wp_loc_db_optimization_scan` — scan existing multilingual data and return wizard summary/mapping data
- `wp_loc_db_optimization_apply` — apply validated language mapping, adopt compatible data, and clean obsolete service data

### Admin POST endpoints
- `wp_loc_restart_db_optimization_wizard` — reset the wizard to `pending` and return to the Plugins screen (capability and nonce protected)

## Development notes
- PHP 8.1+ required (uses `str_starts_with`, arrow functions, named arguments)
- No project npm or webpack workflow — the Mini Prepros PhpStorm plugin watches the project and uses `prepros.config` for SCSS→CSS, JavaScript prepend/concatenation, and minification
- SCSS source: `assets/scss/admin.scss` plus partials such as `assets/scss/_db-optimization-wizard.scss` → output: `assets/css/admin.min.css`
- JS source: `assets/js/admin.js`, with `//@prepros-prepend db-optimization-wizard.js`, → output: `assets/js/admin.min.js`
- Translations: `languages/wp-loc.pot`, `languages/wp-loc-uk.po` (Ukrainian), and `languages/wp-loc-ru_RU.po` (Russian). Compile `.po` files with `msgfmt`.
- `.po` headers include the full WordPress Poedit keyword list, including context-aware `_x`/`esc_html_x`/`esc_attr_x`, so Poedit can extract contextual strings correctly.
- ACF module only loads when ACF plugin is active
- Timber integration only loads when Timber is present
- `WP_LOC_Admin_Settings` loads on admin and frontend because its type filters/helpers are runtime dependencies; UI-only classes (`WP_LOC_Admin`, `WP_LOC_Admin_Languages`, `WP_LOC_Menu_Sync`, the wizard, and Duplicate Post integration) instantiate on `is_admin()`
- `WP_LOC_Duplicate_Post` instantiates on `is_admin()` and is inert without Yoast Duplicate Post (it only registers Duplicate Post's own hooks)
- Settings are tabbed. Saving one settings tab must never wipe values from the other tabs.
- Do not edit `assets/css/admin.min.css` or `assets/js/admin.min.js` manually. Mini Prepros compiles them from `assets/scss/admin.scss` and `assets/js/admin.js`.
- Mini Prepros is expected to be running automatically for this project in PhpStorm. Do not launch the desktop Prepros application for routine builds.
- After changing `assets/scss/admin.scss`, an imported partial, `assets/js/admin.js`, or a prepended JS source such as `assets/js/db-optimization-wizard.js`, wait for Mini Prepros and verify that `assets/css/admin.min.css` / `assets/js/admin.min.js` changed. If they do not update, report that the watcher is not running instead of editing compiled files manually.
- Release version bumps must keep the `Version:` header in `wp-loc.php`, `WP_LOC_VERSION`, and `languages/wp-loc.pot`'s `Project-Id-Version` synchronized.
- Rewrite rules auto-flush via `wp_loc_flush_rewrite_rules` option flag (checked on `init`)
- Deactivation: flushes rewrite rules to remove language prefixes
- Uninstall (`uninstall.php`): removes all `wp_loc_*` options, localized options, ACF language-aware options values, `_wp_loc_is_new` post meta, drops `icl_translations` table

### Naming conventions
- Plugin slug / text domain: `wp-loc` (hyphen)
- PHP classes: `WP_LOC_*`, functions: `wp_loc_*`, constants: `WP_LOC_*`
- DB options: `wp_loc_*` (underscore)
- CSS classes: `wp-loc-*` (hyphen)
- JS variables: `wpLoc*` (camelCase)
- Admin page slug: `wp-loc` (hyphen)
- AJAX actions: `wp_loc_*` (underscore)
