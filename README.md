# WP-LOC

Lightweight multilingual plugin for WordPress.

## Features

- **Language management** — Multilingual > Languages page with drag-and-drop ordering (auto-saves via AJAX)
- **Automatic language detection** — install a language in WP General Settings, it auto-appears in WP-LOC. Delete from WP-LOC — removes language files too.
- **Post/page translations** — auto-create translation drafts, translation metabox in editor with per-language `+` button for on-demand creation
- **Bidirectional post taxonomy sync** — when a translated post changes its multilingual categories/tags/custom taxonomies, sibling posts receive the mapped term translations in their own language
- **Taxonomy/term translations** — WPML-compatible term translation groups for `category`, `post_tag`, and selected custom taxonomies
- **Term translation UI** — translation column in term lists, translation panel on term edit screens, and per-language `+` buttons for on-demand term translation creation
- **Automatic term translation creation** — creating a term can auto-create sibling translations in all active languages
- **Hierarchical taxonomy sync** — translated parent terms are mapped automatically when creating/editing child terms
- **Same slug in different languages** — term slugs are unique per language, not globally
- **Cascade delete for term translations** — deleting any translated term deletes the whole translation group
- **Protected default category group** — default category and all its translations cannot be deleted
- **Multilingual nav menus** — WPML-like translated menu groups, translated menu items, language-aware menu locations, auto-created menu translations, and cascade deletion for menu translation groups
- **WP Menus Sync** — Multilingual > WP Menus Sync page with AJAX preview/apply for syncing menu structure from the default language to secondary languages
- **Non-translatable post types** — work correctly with language URL prefixes (shared content across languages)
- **URL structure** — `/ua/page-slug/`, `/en/page-slug/`, default language without prefix
- **Admin language switcher** — in the admin bar with flags, cookie-based
- **Frontend language switcher** — `wp_loc_get_lang_switcher()`, `wp_loc_get_language_switcher_html()`, `wp_loc_the_language_switcher()` with translated post and term archive URLs
- **SEO** — hreflang alternate tags, canonical URLs, proper `<html lang="">`
- **Localized options** — `blogname`, `blogdescription`, `page_on_front`, `page_for_posts` per language, including localized front page / posts page routing
- **Third-party compatibility** — `icl_object_id()`, `$sitepress`, `ICL_LANGUAGE_CODE`, common multilingual filters
- **ACF integration** — field-level translation mode (`none` / `shared` / `translatable`) for options pages with ACFML-like language-aware `options_{lang}` routing
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
6. Use **Multilingual > WP Menus Sync** if you want to sync secondary-language menus from the default-language menu structure

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
- Use **Multilingual > WP Menus Sync** to sync structure/order/options from the default-language menu to translated menus

### ACF options pages

- `shared` fields stay on the base ACF options post ID (`options`)
- `translatable` fields are routed through language-aware ACF options post IDs like `options_en` / `options_ru`
- Both `get_field( 'field_name', 'options' )` and `get_fields( 'options' )` resolve translated values in the current language context
- `nav_menu` ACF fields resolve to the translated menu for the current language

### In Twig (Timber)
```twig
{{ wp_loc_language_switcher() }}

{% for lang in wp_loc_languages() %}
  <a href="{{ lang.url }}" class="{{ lang.active ? 'active' : '' }}">{{ lang.name }}</a>
{% endfor %}
```

## Taxonomy Notes

- Enable multilingual behavior per taxonomy in **Multilingual > Settings**
- Term translations are stored in the WPML-compatible `icl_translations` table as `tax_{taxonomy}` rows using `term_taxonomy_id`
- Category, tag, and custom taxonomy archive URLs are language-aware
- For translatable posts, multilingual taxonomy assignments sync across the whole post translation group
- If a translated term does not exist for the current language, the frontend switcher falls back to the language home URL
- Wrong-language term archive URLs return `404`

## License

GPLv2 or later.

## Author

Vitalii Kaplia — [vitaliikaplia.com](https://vitaliikaplia.com/)
