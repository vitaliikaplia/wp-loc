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
  class-wp-loc-languages.php        → Language config stored in wp_options
  class-wp-loc-routing.php          → Rewrite rules, URL lang prefix, locale switching
  class-wp-loc-admin.php            → Admin bar switcher, post filtering, translation metabox, AJAX translation creation
  class-wp-loc-admin-languages.php  → Multilingual > Languages page (WP_List_Table, AJAX sort, language file cleanup)
  class-wp-loc-admin-settings.php   → Multilingual > Settings page (translatable post types)
  class-wp-loc-content.php          → Auto-create translation drafts for new posts
  class-wp-loc-frontend.php         → Lang switcher, hreflang, canonical, html lang attr, non-translatable post type support
  class-wp-loc-options.php          → Localized WP options (blogname, page_on_front, etc.)
  class-wp-loc-compat.php           → Third-party compatibility layer (icl_object_id, $sitepress, wpml_* filters)
  class-wp-loc-acf.php              → ACF field-level translation (conditional load)
  class-wp-loc-media.php            → Media attachment language assignment
assets/
  flags/                           → SVG country flags
  scss/admin.scss                  → SCSS source (compiled by Prepros)
  css/admin.min.css                → Compiled/minified CSS
  js/admin.js                      → Admin JS source
  js/admin.min.js                  → Minified JS (compiled by Prepros)
languages/                         → .po/.mo translation files (uk, ru_RU)
```

### Key design decisions
- **DB**: Uses `{prefix}icl_translations` table with **strict WPML-compatible schema** — same table name, same columns (translation_id, element_type, element_id, trid, language_code, source_language_code), same indexes, same element_type format (`post_page`, `post_post`, `post_{cpt}`, `tax_{taxonomy}`). This ensures zero-effort bidirectional migration with WPML. **Any changes to the DB schema MUST preserve this compatibility.**
- **Language config**: Stored in `wp_options` key `wp_loc_languages`, keyed by slug. NOT in a separate DB table.
- **URL structure**: Default language has no prefix. Additional languages: `/{slug}/page-name/`.
- **Ukrainian slug**: `uk` locale maps to `ua` slug via `WP_LOC_Languages::$locale_slug_map`. Filterable via `wp_loc_locale_slug_map`.
- **Admin language**: Cookie-based (`admin_lang` cookie stores WP locale).
- **No post meta for translations**: Everything goes through `icl_translations`. No `_lang`, no `_translation_group`.
- **Language management flow**: No separate "Add Language" UI. Languages are added automatically when the user installs a language via WP General Settings (`on_wplang_change` hook). Deleting via Multilingual > Languages removes the language AND its `.mo`/`.po`/`.json`/`.l10n.php` files so it also disappears from WP General Settings.
- **Non-translatable post types**: Posts not in `icl_translations` still work with language URL prefixes (LEFT JOIN fallback in routing and frontend filtering).
- **Assets**: SCSS compiled via Prepros (external tool, no npm). All CSS/JS extracted from PHP into `assets/` — no inline styles or scripts.
- **AJAX operations**: Language sort order auto-saves on drag. Single translation creation via `+` button in metabox (no page reload).

### Important functions
- `wp_loc_get_current_lang()` — current language slug (frontend)
- `wp_loc_get_current_locale()` — current WP locale (frontend)
- `wp_loc_get_admin_lang()` — admin-selected language slug
- `wp_loc_get_lang_switcher()` — array of languages with URLs for templates
- `WP_LOC::instance()->db` — access DB layer for icl_translations queries
- `WP_LOC_Languages::locale_to_slug()` — convert WP locale to URL slug (filterable)
- `WP_LOC_Languages::get_display_name()` — get display name for language switchers

### Compat layer (class-wp-loc-compat.php)
Only loads when no other multilingual plugin is active (`ICL_SITEPRESS_VERSION` not defined). Provides:
- `icl_object_id()`, `icl_get_languages()`, `wpml_object_id_filter()`
- Filters: `wpml_object_id`, `wpml_current_language`, `wpml_default_language`, `wpml_active_languages`
- Constants: `ICL_LANGUAGE_CODE`, `ICL_LANGUAGE_NAME`
- Global `$sitepress` mock object
- `wpml_multilingual_options` action

### Hooks/filters for customization
- `wp_loc_translatable_post_types` — array of post types (default: `['post', 'page']`)
- `wp_loc_locale_slug_map` — override locale → slug mapping
- `wp_loc_default_multilingual_options` — option names to localize
- `wp_loc_multilingual_options` — action to register an option as multilingual

### AJAX endpoints
- `wp_loc_create_translation` — create a single translation for a post+language (used by metabox `+` button)
- `wp_loc_save_order` — save language sort order after drag-and-drop

## Development notes
- PHP 8.1+ required (uses `str_starts_with`, arrow functions, named arguments)
- No npm, no webpack — Prepros handles SCSS→CSS and JS minification
- SCSS source: `assets/scss/admin.scss` → output: `assets/css/admin.min.css`
- JS source: `assets/js/admin.js` → output: `assets/js/admin.min.js`
- Translations: `languages/wp-loc-uk.po` (Ukrainian), `languages/wp-loc-ru_RU.po` (Russian). Compile with `msgfmt`.
- ACF module only loads when ACF plugin is active
- Admin classes only instantiate on `is_admin()`
- Rewrite rules auto-flush via `wp_loc_flush_rewrite_rules` option flag (checked on `init`)
- Deactivation: flushes rewrite rules to remove language prefixes
- Uninstall (`uninstall.php`): removes all `wp_loc_*` options, localized options, ACF localized options, `_wp_loc_is_new` post meta, drops `icl_translations` table

### Naming conventions
- Plugin slug / text domain: `wp-loc` (hyphen)
- PHP classes: `WP_LOC_*`, functions: `wp_loc_*`, constants: `WP_LOC_*`
- DB options: `wp_loc_*` (underscore)
- CSS classes: `wp-loc-*` (hyphen)
- JS variables: `wpLoc*` (camelCase)
- Admin page slug: `wp-loc` (hyphen)
- AJAX actions: `wp_loc_*` (underscore)
