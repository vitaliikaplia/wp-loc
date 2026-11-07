# WP-LOC

WP-LOC is a lightweight multilingual plugin for WordPress, built by Vitalii Kaplia for client and personal projects. It replaces WPML on typical custom-built sites — posts, pages, custom post types, categories and tags, menus, media and settings in several languages — without WPML's weight.

It keeps translation links in the same `icl_translations` table and provides the same `icl_*` / `wpml_*` functions and filters as WPML, so an existing WPML site can switch over without converting its data, and most themes and plugins written for WPML keep working.

## What it does

- The default language lives at the site root; every other language gets its own prefix, like `/en/`.
- Each translation is a separate post, term or menu linked into a translation group. The front end gets a language switcher, `hreflang` tags and the right `<html lang>`.
- Shared things stay in sync inside a group: status, featured image, categories and tags. Menus can be synced from the default language in one click.
- Works with Advanced Custom Fields, Yoast SEO, Timber and Yoast Duplicate Post.
- Optional AI translation of titles, term names and menu links through the WordPress 7 AI connectors, or of titles and term names with Chrome's built-in translator. New translation copies can be translated in the background.
- WP-CLI commands: `wp wp-loc create-translations` creates missing translation copies, `wp wp-loc translate` translates posts with AI.
- A one-time wizard adopts the data another multilingual plugin left behind.

## Requirements

WordPress 6.0 or newer (7.0 for the AI features) and PHP 8.1 or newer.

## Installation

Upload the `wp-loc` folder to `wp-content/plugins/` and activate it. A language is added by picking it once as the Site Language in **Settings → General**; after that it is managed under **Multilingual → Languages**. Updates come through the normal WordPress updater, straight from this repository.

## Contributors

Thanks to [Oleg Valko](https://github.com/valko-pro) and [Pavlo Bondarchuk](https://github.com/pavlo-bondarchuk) for their pull requests: fixes for routing, hreflang, ACF, options and translation sync, the list language tools, the backfill command and the AI translation features of 1.13.0.

## License

GPLv2 or later. Author: Vitalii Kaplia — [kaplia.pro](https://kaplia.pro/). Plugin site: [wp-loc.com](https://wp-loc.com/).
