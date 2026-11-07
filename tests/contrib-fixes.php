<?php
/**
 * Checks for the fixes taken from contributed pull requests (#2, #3, #5, #8, #9, #13).
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
    } finally {
        $wpdb->query('ROLLBACK');
        wp_cache_flush();
    }
} catch (Throwable $error) {
    WP_CLI::error($error->getMessage() . ' (after ' . $assertions . ' assertions)');
}

WP_CLI::success($assertions . ' assertions passed.');
