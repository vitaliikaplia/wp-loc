# WP-LOC

Lightweight multilingual plugin for WordPress.

## Features

- **Language management** — Multilingual > Languages page with drag-and-drop ordering (auto-saves via AJAX)
- **Automatic language detection** — install a language in WP General Settings, it auto-appears in WP-LOC. Delete from WP-LOC — removes language files too.
- **Post/page translations** — auto-create translation drafts, translation metabox in editor with per-language `+` button for on-demand creation
- **Non-translatable post types** — work correctly with language URL prefixes (shared content across languages)
- **URL structure** — `/ua/page-slug/`, `/en/page-slug/`, default language without prefix
- **Admin language switcher** — in the admin bar with flags, cookie-based
- **Frontend language switcher** — `wp_loc_get_lang_switcher()` for templates
- **SEO** — hreflang alternate tags, canonical URLs, proper `<html lang="">`
- **Localized options** — `blogname`, `blogdescription`, `page_on_front`, `page_for_posts` per language
- **Third-party compatibility** — `icl_object_id()`, `$sitepress`, `ICL_LANGUAGE_CODE`, common multilingual filters
- **ACF integration** — field-level translation mode (shared/translatable) for options pages
- **Ukrainian slug** — `uk` locale → `ua` URL slug out of the box

## Requirements

- WordPress 6.0+
- PHP 8.1+

## Installation

1. Upload `wp-loc` folder to `/wp-content/plugins/`
2. Activate the plugin
3. Go to **WP General Settings** and install the languages you need — they auto-appear in **Multilingual > Languages**
4. Configure language slugs, display names and ordering in **Multilingual > Languages**
5. Select translatable post types in **Multilingual > Settings**

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

// Get translated post ID
$translated_id = icl_object_id( $post_id, 'page', true, 'en' );

// Register a multilingual option
do_action( 'wp_loc_multilingual_options', 'my_custom_option' );
```

## License

GPLv2 or later.

## Author

Vitalii Kaplia — [vitaliikaplia.com](https://vitaliikaplia.com/)
