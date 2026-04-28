# WP-LOC — Lightweight Multilingual Plugin

## What is this?
A WordPress multilingual plugin.

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
  class-wp-loc-admin.php            → Admin bar switcher, post filtering, translation metabox, AJAX translation creation
  class-wp-loc-admin-languages.php  → Multilingual > Languages page (WP_List_Table, AJAX sort, language file cleanup)
  class-wp-loc-admin-settings.php   → Multilingual > Settings page (tabs for content, switcher, AI settings)
  class-wp-loc-ai.php               → AI provider wrapper (OpenAI / Claude / Gemini) and HTML-safe translation helpers
  class-wp-loc-content.php          → Auto-create translation drafts for new posts, sync shared post props and multilingual taxonomy assignments
  class-wp-loc-frontend.php         → Lang switcher, hreflang, canonical, html lang attr, non-translatable post type support
  class-wp-loc-menus.php            → WPML-like multilingual nav menus, menu translation groups, menu item cloning, menu assignment mapping
  class-wp-loc-menu-sync.php        → Multilingual > Tools page (tabs for WP Menus Sync, AI Translation, Config Migration)
  class-wp-loc-options.php          → Localized WP options (blogname, page_on_front, etc.)
  class-wp-loc-compat.php           → Third-party compatibility layer (icl_object_id, $sitepress, wpml_* filters, nav_menu/object language helpers)
  class-wp-loc-acf.php              → ACF field/group translation compatibility (DB, local JSON, PHP-registered groups) + ACFML-like options post_id routing (`options_{lang}`)
  class-wp-loc-media.php            → Media attachment language assignment
  class-wp-loc-terms.php            → Taxonomy/term translations, term admin UI, term routing, duplicate slugs per language
  class-wp-loc-timber.php           → Timber/Twig integration for switcher helpers
assets/
  flags/                           → SVG country flags
  scss/admin.scss                  → SCSS source (compiled by Prepros)
  scss/_db-optimization-wizard.scss → Wizard SCSS partial imported by admin.scss
  css/admin.min.css                → Compiled/minified CSS
  js/admin.js                      → Admin JS source
  js/db-optimization-wizard.js      → Wizard JS source prepended into admin.js by Prepros
  js/admin.min.js                  → Minified JS (compiled by Prepros)
languages/                         → .po/.mo translation files (uk, ru_RU)
```

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
- **Database Optimization Wizard**: Activation sets `wp_loc_db_optimization_wizard_status` to `pending`. The wizard opens for admins until completed or dismissed, has scan/apply/dismiss AJAX endpoints, imports compatible languages/display names/switcher display names, adopts existing `icl_translations` links, imports detected translatable post types/taxonomies into WP-LOC settings, and removes obsolete service data only after explicit confirmation.
- **Wizard default language import**: The wizard reads the legacy multilingual default language from stored sitepress settings and writes it to `wp_loc_default_language` after language mapping is applied, so the no-prefix URL language matches the migrated site.
- **Wizard language mapping**: Scan output includes detected source languages, normalized target languages, match confidence, and `language_targets`. Apply accepts `language_mapping` JSON and validates that mapped target languages are valid and unique before changing scan data or cleaning anything.
- **Wizard copy**: User-facing wizard text must avoid naming a replaced third-party plugin directly; use generic wording such as "another multilingual plugin" or "legacy multilingual data".
- **Term translations**: Taxonomies use the same WPML-compatible `icl_translations` table with `element_type = tax_{taxonomy}` and `element_id = term_taxonomy_id` (not `term_id`). This mapping must stay WPML-compatible.
- **Nav menus**: Menus are WPML-compatible translation groups stored as `tax_nav_menu` rows using `term_taxonomy_id`. Menu items are stored as `post_nav_menu_item` rows. Theme menu locations are normalized to default-language menu IDs and resolved back to the current language on read.
- **Language management flow**: No separate "Add Language" UI. Languages are added automatically when the user installs a language via WP General Settings (`on_wplang_change` hook). Deleting via Multilingual > Languages removes the language AND its `.mo`/`.po`/`.json`/`.l10n.php` files so it also disappears from WP General Settings.
- **Non-translatable post types**: Posts not in `icl_translations` still work with language URL prefixes (LEFT JOIN fallback in routing and frontend filtering).
- **Query filtering**: `WP_LOC_Frontend::filter_posts_by_language()` filters main, secondary, REST, AJAX, and Gutenberg preview `WP_Query` calls for translatable post types when `suppress_filters` is false. Do not restrict it back to only the main query; WPML-style theme queries rely on this behavior.
- **Frontend AJAX language context**: `admin-ajax.php` is technically an admin request, but frontend AJAX handlers must use the frontend language. `WP_LOC_Routing` persists `wp_loc_current_language`, `wp_loc_current_locale`, `_icl_current_language`, and `wp-wpml_current_language` cookies, resolves language from request/cookie/referer, and switches locale on AJAX bootstrap. Do not make frontend AJAX fall back to `wp_loc_get_admin_lang()`.
- **Runtime translatable detection**: Settings can merge configured post types/taxonomies with element types detected from `icl_translations`, so migrated custom post types/taxonomies continue filtering even before the admin manually saves settings.
- **Translatable taxonomies**: Enabled from `Multilingual > Settings` and filtered via `wp_loc_translatable_taxonomies`.
- **Term slugs**: The same term slug is allowed in different languages. Uniqueness is enforced per language, not globally.
- **Hierarchical taxonomies**: Parent term relationships are translated and synced across sibling translations. When creating/editing a translated child term, the parent must be mapped to the translated parent in the same language.
- **Post term sync**: For translatable taxonomies assigned to translatable posts, term relationships sync across the whole post translation group in both directions. Saving any translation becomes the source of truth; sibling posts receive the mapped term translations in their own language.
- **Term deletion**: Deleting any translated term cascades to its whole translation group. Default category protection must apply to the full translation group, including row actions, bulk delete, and edit screen delete links.
- **Frontend term URLs**: Taxonomy archives must resolve by translated term slug/path, wrong-language term URLs should 404, and the frontend language switcher must return translated term archive URLs.
- **Frontend hreflang**: `WP_LOC_Frontend::output_hreflang_tags()` should use the native switcher URL resolution for translated singular, front page, posts page, and term archive contexts. `hreflang` values should come from locales (`en-US`, `ru-RU`, `uk`), include `x-default` for the default language URL, and skip untranslated targets. If Yoast is active, WP-LOC should not print a duplicate canonical tag.
- **Singular routing**: Translated singular URLs must resolve by language, post type, and slug. For pages use `page_id`; for posts/CPTs use `p`, `post_type`, and `name`. Do not collapse CPT matches into `page_id`, because migrated projects may have identical slugs across languages and post types.
- **Compatibility switcher URLs**: `icl_get_languages()` / `wpml_active_languages` should reuse native WP-LOC translated URLs for the current object/archive where possible, including author archives, search results, date archives, core post type archives, pagination, and query arguments, falling back to language home URLs only when no translation exists or settings request fallback behavior.
- **Posts page / front page routing**: Localized `page_on_front` and `page_for_posts` must resolve correctly per language without canonical redirects back to the default language. Theme integrations that previously depended on `ICL_LANGUAGE_CODE` may need a `wp_loc_get_current_lang()` fallback during bootstrap.
- **ACF options architecture**: ACF options pages are routed through language-aware post IDs like `options_en` / `options_ru`, close to ACFML behavior. `shared` fields stay on the base options post ID and must be ignored when saving translated options pages, `none` fields remain editable shared values on the base options post ID, and `translatable` fields read/write from the translated options post ID. `get_field('options')` and `get_fields('options')` must both work with this model.
- **ACF user fields**: ACF fields attached to WordPress users use WPML-compatible language-suffixed user meta for `translatable` fields, e.g. `author_archive_name_ru` / `author_archive_name_en`, while the default language stays on the canonical meta key. User profile saves preserve the selected admin language so translated user fields do not overwrite default-language values.
- **ACF shared field saves**: Read-only `shared` fields on translated posts, terms, and options pages must be ignored during save. Only the source-language entity may push shared values to its translations; translated entities must never push empty disabled ACF payloads back into the translation group.
- **ACF shared field UI**: `shared` fields should become readonly only for translated entities/options pages, not merely because the admin language differs from the default language. Source-language entities can be non-default languages and must remain editable.
- **ACF config compatibility**: `wp-loc` must read and export ACFML-compatible field group mode (`acfml_field_group_mode`) and field preferences (`wpml_cf_preferences`) consistently whether ACF field groups come from the DB, local JSON, or `acf_add_local_field_group()` PHP registration. Local JSON export should preserve these settings.
- **ACF nav_menu fields**: `nav_menu` ACF fields are mapped through menu translations so option values and formatted field output resolve to the menu in the current language context.
- **ACF picker queries**: ACF `post_object`, `page_link`, `relationship`, `taxonomy`, and `nav_menu` picker choices must be scoped to the current editor language. Query hooks should pass `lang` / `suppress_filters=false` so picker results do not mix content from all languages.
- **AI settings**: `Multilingual > Settings > AI` stores provider selection, provider API keys, and an opt-in flag for AI-assisted custom nav menu link translation during menu sync.
- **AI translation tool**: `Multilingual > Tools > AI Translation` uses TinyMCE + AJAX to translate formatted HTML content and insert the translated result back into the editor without reloading the page.
- **AI-assisted menu sync**: When enabled in settings, `WP Menus Sync` attempts to translate custom nav menu links (`custom` items) with AI while preserving URL/target/classes/XFN and tracking source hashes so preview can detect whether custom-link translations are up to date.
- **Config migration tool**: `Multilingual > Tools > Config Migration` scans the active theme / parent theme / active plugins for `wpml-config.xml`, reads only `custom-types` and `taxonomies`, can generate a lightweight `wp-loc-config.xml`, and can remove theme-level `wpml-config.xml` after migration.
- **Assets**: SCSS compiled via Prepros (external tool, no npm). All CSS/JS extracted from PHP into `assets/` — no inline styles or scripts.
- **AJAX operations**: Language sort order auto-saves on drag. Single translation creation via `+` button in metabox (no page reload). `WP Menus Sync` preview/apply and `AI Translation` both run via AJAX.

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
- `WP_LOC_Terms::get_term_translation()` — get translated `term_id` for a target language
- `WP_LOC_Terms::get_term_url_for_language()` — build frontend URL for a term translation in a specific language
- `WP_LOC_AI::translate_content()` — translate formatted content while preserving HTML
- `WP_LOC_AI::get_target_language_name()` — normalize a WP-LOC language slug/locale into a stable AI target language label
- `WP_LOC_Language_Registry::normalize_external_language()` — normalize an external language code/locale/name into WP-LOC code, locale, display name, flag, and confidence
- `WP_LOC_Language_Registry::wpml_code_from_slug()` — convert an internal WP-LOC slug into the compatible language code used by legacy APIs/DB rows
- `WP_LOC_Language_Registry::slug_from_wpml_code()` — convert a compatible language code from imported data into the configured WP-LOC URL slug
- `WP_LOC_Language_Registry::get_language_options()` — registry-backed target list for wizard language mapping controls

### Compat layer (class-wp-loc-compat.php)
Only loads when no other multilingual plugin is active (`ICL_SITEPRESS_VERSION` not defined). Provides:
- `icl_object_id()`, `icl_get_languages()`, `wpml_object_id_filter()`
- Filters: `wpml_object_id`, `wpml_current_language`, `wpml_default_language`, `wpml_active_languages`
- Constants: `ICL_LANGUAGE_CODE`, `ICL_LANGUAGE_NAME`
- Global `$sitepress` mock object
- `wpml_multilingual_options` action
- `nav_menu` handling compatible with WPML-style lookups (`icl_object_id`, `wpml_object_id`, `wpml_element_language_code`)
- WPML-like public language APIs return compatible language codes such as `uk`; native WP-LOC URL helpers continue using configured slugs such as `ua`

### Hooks/filters for customization
- `wp_loc_translatable_post_types` — array of post types (default: `['post', 'page']`)
- `wp_loc_translatable_taxonomies` — array of taxonomy slugs with multilingual behavior
- `wp_loc_locale_slug_map` — override locale → slug mapping
- `wp_loc_default_multilingual_options` — option names to localize
- `wp_loc_multilingual_options` — action to register an option as multilingual

### AJAX endpoints
- `wp_loc_create_translation` — create a single translation for a post+language (used by metabox `+` button)
- `wp_loc_create_term_translation` — create a single translation for a term+language
- `wp_loc_refresh_term_translations` — refresh term translation controls after AJAX creation
- `wp_loc_save_order` — save language sort order after drag-and-drop
- `wp_loc_menu_sync_preview` — refresh `WP Menus Sync` preview grid
- `wp_loc_menu_sync_apply` — apply selected `WP Menus Sync` operations
- `wp_loc_ai_translate` — translate HTML content in the `Tools > AI Translation` tab
- `wp_loc_db_optimization_dismiss` — mark the Database Optimization Wizard as dismissed
- `wp_loc_db_optimization_scan` — scan existing multilingual data and return wizard summary/mapping data
- `wp_loc_db_optimization_apply` — apply validated language mapping, adopt compatible data, and clean obsolete service data

## Development notes
- PHP 8.1+ required (uses `str_starts_with`, arrow functions, named arguments)
- No npm, no webpack — Prepros handles SCSS→CSS and JS minification
- SCSS source: `assets/scss/admin.scss` plus partials such as `assets/scss/_db-optimization-wizard.scss` → output: `assets/css/admin.min.css`
- JS source: `assets/js/admin.js`, with `//@prepros-prepend db-optimization-wizard.js`, → output: `assets/js/admin.min.js`
- Translations: `languages/wp-loc-uk.po` (Ukrainian), `languages/wp-loc-ru_RU.po` (Russian). Compile with `msgfmt`.
- `.po` headers include the full WordPress Poedit keyword list, including context-aware `_x`/`esc_html_x`/`esc_attr_x`, so Poedit can extract contextual strings correctly.
- ACF module only loads when ACF plugin is active
- Timber integration only loads when Timber is present
- Admin classes only instantiate on `is_admin()`
- Settings are tabbed. Saving one settings tab must never wipe values from the other tabs.
- Do not edit `assets/css/admin.min.css` or `assets/js/admin.min.js` manually. Prepros compiles them from `assets/scss/admin.scss` and `assets/js/admin.js`.
- If you change `assets/scss/admin.scss`, an imported partial, `assets/js/admin.js`, or a prepended JS source such as `assets/js/db-optimization-wizard.js`, Prepros must rebuild `assets/css/admin.min.css` / `assets/js/admin.min.js` for the admin UI to reflect the change.
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
