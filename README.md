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
- **Non-translatable post types** — work correctly with language URL prefixes (shared content across languages)
- **URL structure** — `/ua/page-slug/`, `/en/page-slug/`, default language without prefix
- **Admin language switcher** — in the admin bar with flags, cookie-based
- **Frontend language switcher** — `wp_loc_get_lang_switcher()`, `wp_loc_get_language_switcher_html()`, `wp_loc_the_language_switcher()` with translated post and term archive URLs
- **SEO** — hreflang alternate tags, canonical URLs, proper `<html lang="">`
- **Localized options** — `blogname`, `blogdescription`, `page_on_front`, `page_for_posts` per language, including localized front page / posts page routing
- **AI settings** — choose OpenAI / Claude / Gemini, store API keys, and enable AI translation for custom menu links during menu sync
- **Third-party compatibility** — `icl_object_id()`, `$sitepress`, `ICL_LANGUAGE_CODE`, common multilingual filters
- **ACF integration** — ACFML-like field/group translation config for DB, local JSON, and PHP-registered field groups, plus language-aware `options_{lang}` routing for options pages
- **ACF field translation modes** — `shared`, `copy_once`, `translatable`, and editable shared-value `none` behavior for multilingual field workflows
- **ACF media/relation mapping** — translated attachment, post, term, and nav menu IDs are resolved per language for fields like `image`, `file`, `gallery`, `post_object`, `page_link`, `relationship`, `taxonomy`, and `nav_menu`
- **ACF container field support** — multilingual behavior for `group`, `repeater`, `flexible_content`, and `clone` fields across options pages, posts/pages, and term edit screens
- **ACF nav_menu field support** — translated menu values resolve to the correct menu in the current language context
- **Timber integration** — Twig functions `wp_loc_language_switcher()` and `wp_loc_languages()`
- **Ukrainian slug** — `uk` locale → `ua` URL slug out of the box

## Requirements

- WordPress 6.0+
- PHP 8.1+

## Installation

1. Upload `wp-loc` folder to `/wp-content/plugins/`
2. Activate the plugin
3. Go to **WP General Settings** and install the languages you need — they auto-appear in **Multilingual > Languages**
4. Configure language slugs, display names and ordering in **Multilingual > Languages**
5. Select translatable post types and taxonomies in **Multilingual > Settings**
6. Configure **Multilingual > Settings** tabs, including AI provider settings if you want to use AI translation features
7. Use **Multilingual > Tools** for WP Menus Sync, the AI Translation tool, and Config Migration

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

## Compatibility Note

WP-LOC can interoperate with sites that already use the `icl_translations` table and legacy multilingual config files such as `wpml-config.xml`.

WP-LOC is an independent open-source project. It is not affiliated with, endorsed by, or sponsored by any third-party multilingual plugin vendor.
- Category, tag, and custom taxonomy archive URLs are language-aware
- For translatable posts, multilingual taxonomy assignments sync across the whole post translation group
- If a translated term does not exist for the current language, the frontend switcher falls back to the language home URL
- Wrong-language term archive URLs return `404`

## License

GPLv2 or later.

## Author

Vitalii Kaplia — [vitaliikaplia.com](https://vitaliikaplia.com/)
