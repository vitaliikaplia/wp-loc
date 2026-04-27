# WP-LOC

Lightweight multilingual plugin for WordPress.

## Features

- **Language management** — Multilingual > Languages page with drag-and-drop ordering (auto-saves via AJAX)
- **Automatic language detection** — install a language in WP General Settings, it auto-appears in WP-LOC. Delete from WP-LOC — removes language files too.
- **Post/page translations** — auto-create translation drafts, translation metabox in editor with per-language `+` button for on-demand creation
- **Bidirectional post taxonomy sync** — when a translated post changes its multilingual categories/tags/custom taxonomies, sibling posts receive the mapped term translations in their own language
- **Taxonomy/term translations** — migration-compatible term translation groups for `category`, `post_tag`, and selected custom taxonomies
- **Term translation UI** — translation column in term lists, translation panel on term edit screens, and per-language `+` buttons for on-demand term translation creation
- **Automatic term translation creation** — creating a term can auto-create sibling translations in all active languages
- **Hierarchical taxonomy sync** — translated parent terms are mapped automatically when creating/editing child terms
- **Same slug in different languages** — term slugs are unique per language, not globally
- **Cascade delete for term translations** — deleting any translated term deletes the whole translation group
- **Protected default category group** — default category and all its translations cannot be deleted
- **Multilingual nav menus** — translated menu groups, translated menu items, language-aware menu locations, auto-created menu translations, and cascade deletion for menu translation groups
- **Tools page** — Multilingual > Tools with tabbed utilities for WP Menus Sync, AI Translation, and Config Migration
- **WP Menus Sync** — AJAX preview/apply for syncing menu structure from the default language to secondary languages
- **AI-assisted custom menu links** — optional AI translation for `custom` nav menu items during menu sync, while preserving URLs and other menu item settings, with safe fallback when the AI provider refuses a short-field translation
- **AI Translation tool** — TinyMCE-based AJAX translator for formatted HTML content, with translated content inserted back into the editor without reloading the page
- **Config Migration tool** — detects legacy multilingual config files (`wpml-config.xml`), reads only translatable post types and taxonomies, generates lightweight `wp-loc-config.xml`, and can remove theme-level legacy config files
- **Database Optimization Wizard** — appears in admin after activation, scans multilingual data left by another plugin, adopts compatible translation links, imports languages, detects translated post types/taxonomies/options, and removes obsolete service data after confirmation
- **Manual language mapping during optimization** — the wizard auto-matches detected languages through a built-in registry, shows match confidence, and lets admins override the target WP-LOC language before applying cleanup
- **Language registry** — central locale/code/slug/name/flag normalization for common WordPress and multilingual-plugin language codes, including aliases like `uk` → `ua` and legacy `iw` → `he`
- **Separate URL slugs and compatibility codes** — languages can use URL slugs like `ua` while compatible database/API language codes remain `uk`
- **Non-translatable post types** — work correctly with language URL prefixes (shared content across languages)
- **Translatable post type detection** — if compatible translation rows already exist, WP-LOC can detect translated custom post types and taxonomies and merge them into runtime settings
- **Frontend/admin query filtering** — translatable posts are filtered by the current language for main, secondary, AJAX, REST, and Gutenberg preview `WP_Query` calls when filters are not suppressed
- **URL structure** — `/ua/page-slug/`, `/en/page-slug/`, default language without prefix
- **Admin language switcher** — in the admin bar with flags, cookie-based
- **Frontend language switcher** — `wp_loc_get_lang_switcher()`, `wp_loc_get_language_switcher_html()`, `wp_loc_the_language_switcher()` with translated post, custom post type, taxonomy, and archive URLs
- **SEO** — hreflang alternate tags, canonical URLs, proper `<html lang="">`
- **Yoast SEO compatibility** — localized `wpseo_titles` / `wpseo_social` / `wpseo_rss` options, translated primary category resolution, copied Yoast term SEO meta for translated terms, multilingual sitemap alternate links, stripped category-base compatibility, and Yoast indexable invalidation after multilingual updates
- **Localized options** — `blogname`, `blogdescription`, `page_on_front`, `page_for_posts` per language, including localized front page / posts page routing
- **AI settings** — choose OpenAI / Claude / Gemini, store API keys, and enable AI translation for custom menu links during menu sync
- **Translation workflow settings** — control automatic creation of post, term, and menu translations from **Multilingual > Settings > Content Translation**
- **Sync policy settings** — control taxonomy sync, featured image sync, and shared post-attribute sync for translation groups
- **Switcher behavior settings** — control whether the frontend switcher shows flags and names, hides the current language, hides untranslated targets, or falls back to language home URLs
- **Integration toggles** — enable or disable ACF compatibility, Yoast compatibility, and Yoast sitemap alternate links from **Multilingual > Settings > Integrations**
- **Third-party compatibility** — `icl_object_id()`, `$sitepress`, `ICL_LANGUAGE_CODE`, common multilingual filters
- **ACF integration** — ACFML-like field/group translation config for DB, local JSON, and PHP-registered field groups, plus language-aware `options_{lang}` routing for options pages
- **ACF field translation modes** — `shared`, `copy_once`, `translatable`, and editable shared-value `none` behavior for multilingual field workflows
- **ACF media/relation mapping** — translated attachment, post, term, and nav menu IDs are resolved per language for fields like `image`, `file`, `gallery`, `post_object`, `page_link`, `relationship`, `taxonomy`, and `nav_menu`
- **ACF container field support** — multilingual behavior for `group`, `repeater`, `flexible_content`, and `clone` fields across options pages, posts/pages, and term edit screens
- **ACF nav_menu field support** — translated menu values resolve to the correct menu in the current language context
- **Timber integration** — Twig functions `wp_loc_language_switcher()` and `wp_loc_languages()`
- **Activation safety** — on activation, WP-LOC deactivates known conflicting multilingual add-ons instead of deleting them
- **Ukrainian slug** — `uk` locale → `ua` URL slug out of the box

## Requirements

- WordPress 6.0+
- PHP 8.1+

## Installation

1. Upload `wp-loc` folder to `/wp-content/plugins/`
2. Activate the plugin
3. If the Database Optimization Wizard appears, review the scan, adjust language mapping if needed, and apply or dismiss it
4. Go to **WP General Settings** and install any additional languages you need — they auto-appear in **Multilingual > Languages**
5. Configure language slugs, display names and ordering in **Multilingual > Languages**
6. Select or review translatable post types and taxonomies in **Multilingual > Settings**
7. Configure **Multilingual > Settings** tabs for content workflow, switcher behavior, integrations, and AI provider settings
8. Use **Multilingual > Tools** for WP Menus Sync, the AI Translation tool, and Config Migration

## Usage

### In PHP templates
```php
// Get current language
$lang = wp_loc_get_current_lang(); // 'ua', 'en', 'ru'

// Get language switcher
$switcher = wp_loc_get_lang_switcher();
foreach ( $switcher as $lang ) {
    echo '<a href="' . $lang['url'] . '" class="' . ($lang['active'] ? 'active' : '') . '">';
    echo '<img src="' . $lang['flag'] . '" /> ' . $lang['name'];
    echo '</a>';
}

// Or render ready-to-use markup:
wp_loc_the_language_switcher();

// Get translated term ID
$translated_term_id = icl_object_id( $term_id, 'category', true, 'en' );

// Get translated post ID
$translated_id = icl_object_id( $post_id, 'page', true, 'en' );

// Register a multilingual option
do_action( 'wp_loc_multilingual_options', 'my_custom_option' );
```

### Multilingual menus

- Create the source menu in the default language
- WP-LOC auto-creates sibling menus in the other active languages
- Menu locations are assigned from the default-language menu and resolved automatically per language on the frontend
- Use **Multilingual > Tools > WP Menus Sync** to sync structure/order/options from the default-language menu to translated menus
- If **Try to translate custom nav menu links with AI during menu sync** is enabled in **Multilingual > Settings > Content Translation**, custom menu links are translated with the selected AI engine during sync; otherwise they are duplicated 1:1 with the same title, URL, and item settings
- If an AI provider returns a refusal or unusable short-text response for a custom menu link field, WP-LOC keeps the original field value instead of saving the refusal text into the translated menu item
- Automatic menu creation can be disabled from **Multilingual > Settings > Content Translation** if you prefer to create translated menus manually

### AI tools

- In **Multilingual > Settings > AI**, choose the translation engine (`OpenAI`, `Claude`, or `Gemini`) and provide the matching API key
- In **Multilingual > Tools > AI Translation**, paste or write formatted content in the TinyMCE editor, choose a target language, and translate it via AJAX without reloading the page
- The translated HTML is inserted back into the editor while preserving formatting

### Config migration

- In **Multilingual > Tools > Config Migration**, WP-LOC scans the active theme, parent theme, and active plugins for legacy multilingual config files such as `wpml-config.xml`
- WP-LOC reads only `custom-types` and `taxonomies` from supported legacy config files; other config sections are ignored on purpose
- You can generate a lightweight `wp-loc-config.xml` from the current WP-LOC settings
- You can also generate `wp-loc-config.xml` from a detected legacy config source
- Theme-level legacy config files can be removed from the same screen after migration; plugin-level files are shown as read-only

### Database optimization wizard

- The wizard opens automatically for admins after activation while its status is `pending`
- Closing the modal with the `X` only hides it for the current page load; dismissing optimization stores that choice and stops the automatic modal
- A plugin-row **Wizard** action remains available while the wizard has not been completed, so admins can return to it after dismissing
- The scan summarizes compatible translation links, detected content types, taxonomies, media, menus, localized options, language records, and removable service data
- Detected languages are normalized through `WP_LOC_Language_Registry`; the wizard shows match confidence and lets admins manually map each detected source language to a WP-LOC target language
- Imported languages preserve compatible language codes and detected switcher display names where available, so URL slugs and database/API codes do not have to be identical
- Applying optimization imports or updates WP-LOC languages, adopts compatible translation links, imports detected translatable post types/taxonomies into settings, cleans obsolete options/meta/tables, and marks the wizard as completed
- The apply flow requires confirmation because removed service data is not restored by WP-LOC

### ACF options pages

- `shared` fields stay on the base ACF options post ID (`options`)
- `translatable` fields are routed through language-aware ACF options post IDs like `options_en` / `options_ru`
- `copy_once` container fields inherit from the source language until the translated options page stores its own value
- Both `get_field( 'field_name', 'options' )` and `get_fields( 'options' )` resolve translated values in the current language context
- `nav_menu` ACF fields resolve to the translated menu for the current language

### ACF content fields

- The same multilingual ACF logic works for translatable posts/pages, classic editor post screens, Gutenberg page screens, and multilingual term edit screens
- `shared` fields resolve the source-language value but map media/post/term/menu references into the current language
- `copy_once` fields inherit the source-language value until the translated post/term/options page stores its own independent value
- Container fields such as `group`, `repeater`, `flexible_content`, and `clone` preserve their ACF structure while mapping nested media and relation values per language

### Yoast SEO

- WP-LOC loads a dedicated Yoast compatibility layer only when Yoast SEO is active
- The Yoast compatibility layer and Yoast sitemap alternate links can be toggled separately in **Multilingual > Settings > Integrations**
- Global Yoast options such as `wpseo_titles`, `wpseo_social`, and `wpseo_rss` can be localized per language through the same multilingual options model used by WP-LOC
- Yoast primary category meta is resolved to the translated term in the current post language
- Yoast taxonomy SEO meta is copied into translated terms so translated archives keep their own SEO title/description state
- Yoast indexables are invalidated after multilingual post, term, and global-option updates so Yoast can rebuild its cached SEO data
- Yoast XML sitemaps gain `xhtml:link` alternate-language entries for translated posts, pages, terms, and first archive links
- Yoast `stripcategorybase` rewrites remain compatible with multilingual category slugs
- Yoast News can reuse the current post language code for publication-language output when the addon is active

### In Twig (Timber)
```twig
{{ wp_loc_language_switcher() }}

{% for lang in wp_loc_languages() %}
  <a href="{{ lang.url }}" class="{{ lang.active ? 'active' : '' }}">{{ lang.name }}</a>
{% endfor %}
```

## Taxonomy Notes

- Enable multilingual behavior per taxonomy in **Multilingual > Settings**
- Term translations are stored in the `icl_translations` table as `tax_{taxonomy}` rows using `term_taxonomy_id`
- Category, tag, and custom taxonomy archive URLs are language-aware
- For translatable posts, multilingual taxonomy assignments sync across the whole post translation group
- If a translated term does not exist for the current language, the frontend switcher falls back to the language home URL
- Wrong-language term archive URLs return `404`

## Routing Notes

- Translated singular URLs resolve by language, post type, and slug, so translated posts from different post types can safely share the same slug
- Custom post type translations with identical slugs across languages resolve to their translated post instead of redirecting back to the default-language post
- Compatibility switcher APIs such as `icl_get_languages()` use the same translated URLs as WP-LOC's native switcher helpers

## Compatibility Note

WP-LOC can interoperate with sites that already use the `icl_translations` table and legacy multilingual config files such as `wpml-config.xml`.

WP-LOC is an independent open-source project. It is not affiliated with, endorsed by, or sponsored by any third-party multilingual plugin vendor.

## License

GPLv2 or later.

## Author

Vitalii Kaplia — [vitaliikaplia.com](https://vitaliikaplia.com/)
