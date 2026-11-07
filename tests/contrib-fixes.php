<?php
/**
 * Checks for the fixes and features taken from contributed pull requests (#1, #2, #3, #5, #6, #7, #8, #9, #10, #12, #13).
 * Run on a site with at least two languages and without a persistent object cache:
 * wp eval-file wp-content/plugins/wp-loc/tests/contrib-fixes.php
 * Every write happens inside a transaction that is rolled back.
 */
if (!defined('WP_CLI') || !WP_CLI) { exit; }
if (!class_exists('WP_LOC')) { WP_CLI::error('Active WP LOC is required.'); }
if (wp_using_ext_object_cache()) { WP_CLI::error('Run on a site without a persistent object cache.'); }

global $wpdb;
add_filter('pre_http_request', static function () { throw new RuntimeException('Check blocked HTTP.'); }, PHP_INT_MAX);
$assertions = 0;
$check = static function ($condition, $message) use (&$assertions) {
    $assertions++;
    if (!$condition) { throw new RuntimeException($message); }
};
$db = WP_LOC::instance()->db;
$table = $db->get_table();
$default = WP_LOC_Languages::get_default_language();
$other = current(array_diff(array_keys(WP_LOC_Languages::get_active_languages()), [$default])) ?: null;
if (!$other) { WP_CLI::error('A second active language is required.'); }

try {
    WP_CLI::log('#5 attachment image filter');
    // #5: core passes the caller's attachment ID to the filter untouched.
    foreach (['', null, '0', false] as $empty_id) {
        $check(wp_get_attachment_image_src($empty_id) === false, 'Empty attachment ID ' . var_export($empty_id, true) . ' returns false');
    }
    $attachment_id = (int) $wpdb->get_var($wpdb->prepare(
        "SELECT p.ID FROM {$wpdb->posts} p INNER JOIN {$table} t ON t.element_id = p.ID AND t.element_type = 'post_attachment'
         WHERE p.post_type = 'attachment' AND p.post_mime_type LIKE 'image/%%' AND t.language_code = %s ORDER BY p.ID LIMIT 1",
        WP_LOC_DB::to_db_language_code($default) ?: $default));
    if ($attachment_id) {
        WP_LOC_Routing::switch_language($other);
        try {
            $translated = $db->get_element_translation($attachment_id, 'post_attachment', $other);
            $image = wp_get_attachment_image_src((string) $attachment_id, 'thumbnail');
            $check(is_array($image), 'A numeric-string attachment ID still resolves');
            if ($translated && $translated !== $attachment_id) {
                $check($image === wp_get_attachment_image_src($translated, 'thumbnail'), 'The image resolves to the attachment of the current language');
            }
        } finally {
            WP_LOC_Routing::switch_language($default);
        }
    }

    WP_CLI::log('#8 by-name look-up');
    // #8: a post type fixed by the rewrite rule restricts the by-name look-up, and media never wins it.
    $routing = WP_LOC::instance()->routing;
    $post = $wpdb->get_row($wpdb->prepare(
        "SELECT p.ID, p.post_name FROM {$wpdb->posts} p INNER JOIN {$table} t ON t.element_id = p.ID AND t.element_type = 'post_post'
         WHERE p.post_type = 'post' AND p.post_status = 'publish' AND t.language_code = %s ORDER BY p.ID LIMIT 1",
        WP_LOC_DB::to_db_language_code($default) ?: $default));
    $check((bool) $post, 'A published default-language post exists');
    $resolved = $routing->handle_request(['name' => $post->post_name]);
    $check((int) ($resolved['p'] ?? 0) === (int) $post->ID && ($resolved['post_type'] ?? '') === 'post', 'A post name without a post type resolves to the post');
    $resolved = $routing->handle_request(['name' => $post->post_name, 'post_type' => 'page']);
    $check(empty($resolved['p']) && ($resolved['post_type'] ?? '') === 'page', 'A post name under a page rule does not resolve to the post');
    $resolved = $routing->handle_request(['name' => $post->post_name, 'post_type' => [['post']]]);
    $check(empty($resolved['p']), 'A nested post_type array resolves nothing');
    $media_slug = $wpdb->get_var("SELECT post_name FROM {$wpdb->posts} WHERE post_type = 'attachment' AND post_name <> '' ORDER BY ID LIMIT 1");
    if ($media_slug) {
        $resolved = $routing->handle_request(['name' => $media_slug, 'post_type' => 'post']);
        $check(($resolved['post_type'] ?? '') !== 'attachment' && empty($resolved['p']), 'A media slug under a post rule does not resolve to the attachment');
        $resolved = $routing->handle_request(['name' => $media_slug]);
        $check(($resolved['post_type'] ?? '') !== 'attachment', 'A bare media slug is left to core');
    }

    $wpdb->query('START TRANSACTION');
    try {
        WP_CLI::log('#9 empty option translation');
        // #9: an empty option translation falls back to the default-language value.
        $base = get_option('blogname');
        $localized = 'blogname_' . WP_LOC_DB::to_db_language_code($other);
        WP_LOC_Routing::switch_language($other);
        try {
            update_option($localized, '');
            $check(get_option('blogname') === $base, 'An empty blogname translation falls back to the base value');
            update_option($localized, 'WPLOC contrib name');
            $check(get_option('blogname') === 'WPLOC contrib name', 'A filled blogname translation is served');
        } finally {
            WP_LOC_Routing::switch_language($default);
        }

        WP_CLI::log('#13 sibling writes');
        // #13: sibling writes inside the source save do not see the source's submitted form.
        $pair = $wpdb->get_row($wpdb->prepare(
            "SELECT s.element_id AS source_id, o.element_id AS sibling_id FROM {$table} s
             INNER JOIN {$table} o ON o.trid = s.trid AND o.element_type = s.element_type AND o.element_id <> s.element_id
             INNER JOIN {$wpdb->posts} sp ON sp.ID = s.element_id AND sp.post_status = 'publish'
             INNER JOIN {$wpdb->posts} op ON op.ID = o.element_id
             WHERE s.element_type = 'post_post' AND s.language_code = %s ORDER BY s.element_id LIMIT 1",
            WP_LOC_DB::to_db_language_code($default) ?: $default));
        $check((bool) $pair, 'A translated post pair exists');
        $check(WP_LOC_Admin_Settings::should_sync_post_attributes(), 'Post attribute sync is on');
        $seen = [];
        $reader = static function ($post_id) use (&$seen) {
            $seen[] = (int) $post_id;
            if (isset($_POST['wp_loc_test_seo'])) {
                update_post_meta((int) $post_id, '_wp_loc_test_seo', $_POST['wp_loc_test_seo']);
            }
        };
        add_action('wp_insert_post', $reader, 10);
        $posted = $_POST;
        $_POST = ['post_ID' => (string) $pair->source_id, 'wp_loc_test_seo' => 'SOURCE FORM'];
        try {
            wp_update_post(['ID' => (int) $pair->source_id]);
            $check(in_array((int) $pair->sibling_id, $seen, true), 'The sibling was written during the source save');
            $check(get_post_meta((int) $pair->source_id, '_wp_loc_test_seo', true) === 'SOURCE FORM', 'The source sees its own form');
            $check(!metadata_exists('post', (int) $pair->sibling_id, '_wp_loc_test_seo'), 'The sibling write does not see the source form');
            $check(($_POST['wp_loc_test_seo'] ?? null) === 'SOURCE FORM', 'The submitted form is restored after the sibling write');
        } finally {
            remove_action('wp_insert_post', $reader, 10);
            $_POST = $posted;
        }

        // #2: a parent of another post type is translated as its own type; without a translation
        // the sibling keeps its own parent, while a parent of the same type keeps the old fallback.
        WP_CLI::log('#2 translated parent of another post type');
        $make = static function (string $type, string $lang, int $parent = 0, ?int $trid = null) use ($db) {
            WP_LOC_Content::$suspend_new_post_registration = true;
            try {
                $id = wp_insert_post(['post_type' => $type, 'post_status' => 'publish', 'post_title' => "WPLOC parent check {$type} {$lang}", 'post_parent' => $parent], true);
            } finally {
                WP_LOC_Content::$suspend_new_post_registration = false;
            }
            if (is_wp_error($id)) { throw new RuntimeException($id->get_error_message()); }
            $new_trid = $db->set_element_language((int) $id, WP_LOC_DB::post_element_type($type), $lang, $trid);
            return [(int) $id, $trid ?? $new_trid];
        };
        [$page, $page_trid] = $make('page', $default);
        [$page_other] = $make('page', $other, 0, $page_trid);
        [$lone_page] = $make('page', $default);
        [$child, $child_trid] = $make('post', $default, $page);
        [$child_other] = $make('post', $other, 0, $child_trid);
        $content = WP_LOC::instance()->content;
        $content->sync_translations($child, get_post($child));
        clean_post_cache($child_other);
        $check((int) get_post($child_other)->post_parent === $page_other, 'A post under a page gets the page translation as its parent');
        wp_update_post(['ID' => $child, 'post_parent' => $lone_page]);
        clean_post_cache($child_other);
        $check((int) get_post($child_other)->post_parent === $page_other, 'Without a parent translation of another type the sibling keeps its own parent');
        [$subpage, $subpage_trid] = $make('page', $default, $lone_page);
        [$subpage_other] = $make('page', $other, 0, $subpage_trid);
        $content->sync_translations($subpage, get_post($subpage));
        clean_post_cache($subpage_other);
        $check((int) get_post($subpage_other)->post_parent === $lone_page, 'A same-type parent without a translation keeps the source parent');

        // #3: ACF values — sub-fields of a translated options container, and fields without a mode.
        if (function_exists('acf_add_local_field_group') && WP_LOC_Admin_Settings::is_acf_compat_enabled()) {
            WP_CLI::log('#3 ACF options containers and unconfigured fields');
            $flush_values = static function () { if (function_exists('acf_get_store')) { acf_get_store('values')->reset(); } };
            acf_add_local_field_group([
                'key' => 'group_wploc_contrib_check',
                'title' => 'WPLOC contrib check',
                'fields' => [
                    ['key' => 'field_wploc_check_rows', 'name' => 'wploc_check_rows', 'type' => 'repeater', 'wpml_cf_preferences' => 2,
                     'sub_fields' => [['key' => 'field_wploc_check_rows_label', 'name' => 'label', 'type' => 'text']]],
                    ['key' => 'field_wploc_check_plain', 'name' => 'wploc_check_plain', 'type' => 'text'],
                ],
                'location' => [[['param' => 'post_type', 'operator' => '==', 'value' => 'post']]],
            ]);
            update_field('field_wploc_check_rows', [['field_wploc_check_rows_label' => 'Base row']], 'options');
            WP_LOC_Routing::switch_language($other);
            try {
                update_field('field_wploc_check_rows', [['field_wploc_check_rows_label' => 'Translated row']], 'options');
                $flush_values();
                $rows = get_field('wploc_check_rows', 'options');
                $check(($rows[0]['label'] ?? null) === 'Translated row', 'A translated options repeater shows its own sub-values');
            } finally {
                WP_LOC_Routing::switch_language($default);
            }
            $flush_values();
            $rows = get_field('wploc_check_rows', 'options');
            $check(($rows[0]['label'] ?? null) === 'Base row', 'The default-language options repeater keeps its sub-values');

            $source = (int) $pair->source_id;
            $sibling = (int) $pair->sibling_id;
            update_field('field_wploc_check_plain', 'Own translation value', $sibling);
            update_field('field_wploc_check_plain', 'Source value 1', $source);
            $flush_values();
            $check(get_post_meta($sibling, 'wploc_check_plain', true) === 'Source value 1', 'By default a field without a mode is copied from the source on save');
            update_option(WP_LOC_Admin_Settings::ACF_MIRROR_UNCONFIGURED_FIELDS_OPTION_KEY, 0);
            update_field('field_wploc_check_plain', 'Own translation value', $sibling);
            update_field('field_wploc_check_plain', 'Source value 2', $source);
            $flush_values();
            $check(get_post_meta($sibling, 'wploc_check_plain', true) === 'Own translation value', 'With copying off the translation keeps its own value');
            delete_post_meta($sibling, 'wploc_check_plain');
            $flush_values();
            $check(get_field('wploc_check_plain', $sibling) === 'Source value 2', 'With copying off a translation without a value shows the source value');
        }

        // #12: creation actions carry the source language; add-on settings tabs register and save.
        WP_CLI::log('#12 creation actions and settings tabs');
        $created = [];
        $record = static function (...$args) use (&$created) { $created[] = $args; };
        add_action('wp_loc_translation_created', $record, 10, 4);
        [$lone_post] = $make('post', $default);
        WP_LOC::instance()->content->create_translations($lone_post);
        remove_action('wp_loc_translation_created', $record, 10);
        $post_event = current(array_filter($created, static fn($args) => $args[1] === $lone_post));
        $check($post_event && $post_event[2] === $other && $post_event[3] === $default, 'wp_loc_translation_created passes the target and the source language');

        // wp_loc_term_translation_created is checked over HTTP (the add-tag form and the "+" button):
        // outside a real admin request core's duplicate-name check rejects the same-name copy.

        $settings = new ReflectionClass('WP_LOC_Admin_Settings');
        $settings_instance = $settings->newInstanceWithoutConstructor();
        $tabs = $settings->getMethod('get_tabs');
        $tabs->setAccessible(true);
        $current_tab = $settings->getMethod('get_current_tab');
        $current_tab->setAccessible(true);
        $add_tab = static fn(array $tabs) => $tabs + ['wploc_addon' => 'Add-on'];
        add_filter('wp_loc_settings_tabs', $add_tab);
        $get = $_GET;
        $_GET['tab'] = 'wploc_addon';
        $check($current_tab->invoke($settings_instance) === 'wploc_addon', 'An add-on tab registered with wp_loc_settings_tabs can be opened');
        $_GET['tab'] = 'wploc_unknown';
        $check($current_tab->invoke($settings_instance) === WP_LOC_Admin_Settings::TAB_CONTENT, 'An unknown tab falls back to the first tab');
        $_GET = $get;
        $admins = get_users(['role' => 'administrator', 'number' => 1, 'fields' => 'ID']);
        $previous_user = get_current_user_id();
        wp_set_current_user((int) ($admins[0] ?? 0));
        $saved = [];
        $save_hook = static function () use (&$saved) { $saved[] = current_action(); throw new RuntimeException('wploc-saved'); };
        add_action('wp_loc_settings_save_wploc_addon', $save_hook);
        add_action('wp_loc_settings_save_wploc_unknown', $save_hook);
        $post = $_POST;
        try {
            foreach (['wploc_unknown', 'wploc_addon'] as $tab) {
                $_POST = ['wp_loc_settings_nonce' => wp_create_nonce('wp_loc_save_settings'), 'wp_loc_settings_tab' => $tab];
                try { $settings_instance->handle_save(); } catch (RuntimeException $stop) { if ($stop->getMessage() !== 'wploc-saved') { throw $stop; } }
            }
        } finally {
            $_POST = $post;
            remove_filter('wp_loc_settings_tabs', $add_tab);
            remove_action('wp_loc_settings_save_wploc_addon', $save_hook);
            remove_action('wp_loc_settings_save_wploc_unknown', $save_hook);
            wp_set_current_user($previous_user);
        }
        $check($saved === ['wp_loc_settings_save_wploc_addon'], 'Only a registered tab fires its save action');

        // #6: Quick/Bulk Edit sets the language of a post without translations and moves its terms;
        // a post with translations keeps its language.
        WP_CLI::log('#6 language change from the post list');
        $category_pair = $wpdb->get_row($wpdb->prepare(
            "SELECT s.element_id AS source_tt, o.element_id AS other_tt FROM {$table} s
             INNER JOIN {$table} o ON o.trid = s.trid AND o.element_type = s.element_type
             WHERE s.element_type = 'tax_category' AND s.language_code = %s AND o.language_code = %s LIMIT 1",
            WP_LOC_DB::to_db_language_code($default) ?: $default, WP_LOC_DB::to_db_language_code($other) ?: $other));
        update_option(WP_LOC_Admin_Settings::LIST_LANGUAGE_EDITING_OPTION_KEY, 1);
        $editor = new WP_LOC_Admin_Language_Editor();
        $admins = get_users(['role' => 'administrator', 'number' => 1, 'fields' => 'ID']);
        $previous_user = get_current_user_id();
        wp_set_current_user((int) ($admins[0] ?? 0));
        $request = $_REQUEST;
        try {
            WP_LOC_Content::$suspend_new_post_registration = true;
            $loose = wp_insert_post(['post_type' => 'post', 'post_status' => 'draft', 'post_title' => 'WPLOC language change check'], true);
            WP_LOC_Content::$suspend_new_post_registration = false;
            $check(!is_wp_error($loose) && !$db->get_element_language($loose, 'post_post'), 'A post without a language exists for the check');
            if ($category_pair) {
                // get_term_by() would hand back the context language's sibling; read the IDs raw.
                $source_cat = (int) WP_LOC_Terms::get_term_id_from_taxonomy_id((int) $category_pair->source_tt, 'category');
                $other_cat = (int) WP_LOC_Terms::get_term_id_from_taxonomy_id((int) $category_pair->other_tt, 'category');
                WP_LOC_Terms::without_language_filter(static fn() => wp_set_object_terms($loose, [$source_cat], 'category'));
            }
            $_REQUEST[WP_LOC_Admin_Language_Editor::FIELD_NAME] = $other;
            $_REQUEST[WP_LOC_Admin_Language_Editor::NONCE_FIELD] = wp_create_nonce(WP_LOC_Admin_Language_Editor::NONCE_ACTION);
            $editor->handle_save($loose, get_post($loose));
            $check($db->get_element_language($loose, 'post_post') === $other, 'Quick Edit sets the language of a post without one');
            if ($category_pair) {
                $cats = WP_LOC_Terms::without_language_filter(static fn() => wp_get_object_terms($loose, 'category', ['fields' => 'ids']));
                $check(array_map('intval', $cats) === [$other_cat], 'Its category moves to the translation in the new language');
            }
            $_REQUEST[WP_LOC_Admin_Language_Editor::FIELD_NAME] = $other;
            $editor->handle_save((int) $pair->source_id, get_post((int) $pair->source_id));
            $check($db->get_element_language((int) $pair->source_id, 'post_post') === $default, 'A post with translations keeps its language');
            $_REQUEST[WP_LOC_Admin_Language_Editor::FIELD_NAME] = '';
            [$alone] = $make('post', $default);
            $editor->handle_save($alone, get_post($alone));
            $check($db->get_element_language($alone, 'post_post') === $default, 'No Change leaves the language alone');
        } finally {
            $_REQUEST = $request;
            WP_LOC_Content::$suspend_new_post_registration = false;
            wp_set_current_user($previous_user);
        }

        // #7: wp wp-loc create-translations creates only the asked language, from originals only.
        WP_CLI::log('#7 wp wp-loc create-translations');
        $languages = array_keys(WP_LOC_Languages::get_active_languages());
        $third = current(array_diff($languages, [$default, $other])) ?: null;
        [$backfill_source] = $make('post', $default);
        [$backfill_other] = $make('post', $other);
        $cli = new WP_LOC_CLI_Backfill();
        ob_start();
        $only = $backfill_source . ',' . $backfill_other;
        $cli->create_translations([], ['post_type' => 'post', 'status' => 'publish', 'lang' => $other, 'include' => $only]);
        $cli->create_translations([], ['post_type' => 'post', 'status' => 'publish', 'lang' => $default, 'include' => $only]);
        ob_end_clean();
        $group = static fn(int $id) => $db->get_element_translations($db->get_trid($id, 'post_post'), 'post_post');
        $check(isset($group($backfill_source)[$other]), 'The backfill creates the asked language');
        if ($third) {
            $check(!isset($group($backfill_source)[$third]), 'The backfill does not create other languages than --lang');
        }
        $check(isset($group($backfill_other)[$default]), 'An original in a non-default language is a source too');
        $copy = (int) $group($backfill_source)[$other]->element_id;
        $check(count($group($copy)) === count($group($backfill_source)), 'A translation is not used as a source of its own copies');

        // #1: AI translation of copies (a stand-in translator through wp_loc_pre_translate_content),
        // wp wp-loc translate, background translation of new copies, and the switcher in menus.
        WP_CLI::log('#1 AI copies, translate command, background task, menu switcher');
        $fake = static fn($pre, $content, $language) => '[' . $language . '] ' . $content;
        add_filter('wp_loc_pre_translate_content', $fake, 10, 3);
        try {
            $check(WP_LOC_AI::can_translate(), 'A wp_loc_pre_translate_content answer counts as a translator');
            [$ai_source] = $make('post', $default);
            wp_update_post(['ID' => $ai_source, 'post_content' => 'WPLOC body', 'post_excerpt' => 'WPLOC excerpt']);
            WP_LOC::instance()->content->create_translations($ai_source, [$other]);
            $ai_copy = (int) $db->get_element_translation($ai_source, 'post_post', $other);
            $check($ai_copy && get_post_status($ai_copy) === 'draft', 'create_translations makes a draft copy');
            $language_name = WP_LOC_AI::get_target_language_name($other);
            $check(WP_LOC_AI::translate_post_fields(get_post($ai_source), $ai_copy, $other, ['title', 'content', 'excerpt'], true) === true, 'translate_post_fields succeeds');
            clean_post_cache($ai_copy);
            clean_post_cache($ai_source);
            $copy = get_post($ai_copy);
            $check(str_starts_with($copy->post_title, '[' . $language_name . '] ') && $copy->post_content === '[' . $language_name . '] WPLOC body', 'The copy gets the translated title and content');
            $check($copy->post_name === sanitize_title($copy->post_title), 'A new copy gets the slug of its translated title');
            $check(get_post_status($ai_source) === 'publish', 'Filling in the draft copy leaves the source published');

            [$cli_source] = $make('post', $default);
            ob_start();
            (new WP_LOC_CLI_Translate())->post([(string) $cli_source], ['to' => $other]);
            ob_end_clean();
            $cli_copy = (int) $db->get_element_translation($cli_source, 'post_post', $other);
            $check($cli_copy && str_starts_with(get_post($cli_copy)->post_title, '[' . $language_name . '] '), 'wp wp-loc translate post creates and translates the copy');
            $check(get_post_status($cli_source) === 'publish', 'The translate command leaves the source published');

            update_option(WP_LOC_Admin_Settings::AI_TRANSLATE_NEW_COPIES_OPTION_KEY, 1);
            [$bg_source] = $make('post', $default);
            WP_LOC::instance()->content->create_translations($bg_source, [$other]);
            $bg_copy = (int) $db->get_element_translation($bg_source, 'post_post', $other);
            $check((bool) wp_next_scheduled('wp_loc_ai_translate_copy', [$bg_copy, $bg_source, $other]), 'A new copy schedules its background translation');
            do_action('wp_loc_ai_translate_copy', $bg_copy, $bg_source, $other);
            clean_post_cache($bg_copy);
            $check(str_starts_with(get_post($bg_copy)->post_title, '[' . $language_name . '] '), 'The background task translates the copy');
            [$edited_source] = $make('post', $default);
            WP_LOC::instance()->content->create_translations($edited_source, [$other]);
            $edited_copy = (int) $db->get_element_translation($edited_source, 'post_post', $other);
            WP_LOC_Content::without_group_sync(static fn() => wp_update_post(['ID' => $edited_copy, 'post_title' => 'Edited by hand']));
            do_action('wp_loc_ai_translate_copy', $edited_copy, $edited_source, $other);
            clean_post_cache($edited_copy);
            $check(get_post($edited_copy)->post_title === 'Edited by hand', 'A copy edited before the background task keeps its text');
            update_option(WP_LOC_Admin_Settings::AI_TRANSLATE_NEW_COPIES_OPTION_KEY, 0);
        } finally {
            remove_filter('wp_loc_pre_translate_content', $fake, 10);
        }

        $menu_id = wp_create_nav_menu('WPLOC switcher check');
        $check(!is_wp_error($menu_id), 'A check menu was created');
        wp_update_nav_menu_item($menu_id, 0, ['menu-item-title' => 'WPLOC home', 'menu-item-url' => home_url('/'), 'menu-item-status' => 'publish', 'menu-item-type' => 'custom']);
        $render = static fn() => (string) wp_nav_menu(['menu' => $menu_id, 'echo' => false, 'fallback_cb' => false]);
        $check(!str_contains($render(), 'wp-loc-menu-language'), 'Menus have no language items by default');
        update_option(WP_LOC_Admin_Settings::MENU_SWITCHER_OPTION_KEY, 1);
        $menu = $render();
        $check(substr_count($menu, 'wp-loc-menu-language-') >= count(WP_LOC_Languages::get_active_languages()) - (WP_LOC_Admin_Settings::hide_current_language_in_switcher() ? 1 : 0), 'With the setting the menu ends with the languages');
        $check(!preg_match('/hreflang="ua"/', $menu), 'Menu language links use the language tag, not the URL slug');
        update_option(WP_LOC_Admin_Settings::MENU_SWITCHER_LOCATION_OPTION_KEY, 'wploc_other_location');
        $check(!str_contains($render(), 'wp-loc-menu-language'), 'A menu outside the chosen location gets no languages');

        // #10: a plugin can opt its own screen into localized option values.
        WP_CLI::log('#10 localized options screen filter');
        $locale = WP_LOC_Languages::get_language_locale($other);
        update_option('blogname_' . WP_LOC_DB::to_db_language_code($other), 'WPLOC screen name');
        $cookie = $_COOKIE['admin_lang'] ?? null;
        $_COOKIE['admin_lang'] = $locale;
        $screen = (object) ['id' => 'wploc_page_settings', 'base' => 'wploc_page_settings', 'parent_base' => 'wploc'];
        $opt_in = static fn($is_settings, $screen) => $is_settings || $screen->id === 'wploc_page_settings';
        try {
            $base_name = get_option('blogname');
            WP_LOC::instance()->options->filter_admin_options($screen);
            $check(get_option('blogname') === $base_name, 'A plugin screen without the filter keeps the default-language value');
            add_filter('wp_loc_is_localized_options_screen', $opt_in, 10, 2);
            WP_LOC::instance()->options->filter_admin_options($screen);
            $check(get_option('blogname') === 'WPLOC screen name', 'A screen opted in with wp_loc_is_localized_options_screen shows the admin-language value');
        } finally {
            remove_filter('wp_loc_is_localized_options_screen', $opt_in, 10);
            if ($cookie === null) { unset($_COOKIE['admin_lang']); } else { $_COOKIE['admin_lang'] = $cookie; }
        }
    } finally {
        $wpdb->query('ROLLBACK');
        wp_cache_flush();
    }
} catch (Throwable $error) {
    WP_CLI::error($error->getMessage() . ' (after ' . $assertions . ' assertions)');
}

WP_CLI::success($assertions . ' assertions passed.');
