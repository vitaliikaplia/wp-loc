<?php
/** Run on a site without a persistent object cache: wp eval-file wp-content/plugins/wp-loc/tests/term-cache.php */
if (!defined('WP_CLI') || !WP_CLI) { exit; }
if (!class_exists('WP_LOC_Terms')) { WP_CLI::error('Active WP LOC is required.'); }
// The checks flush the object cache to start cold; on a persistent cache that would wipe the site's cache.
if (wp_using_ext_object_cache()) { WP_CLI::error('Run on a site without a persistent object cache.'); }

global $wpdb;
$queries = 0;
add_filter('query', static function ($sql) use (&$queries) { $queries++; return $sql; }, PHP_INT_MIN);
add_filter('pre_http_request', static function () { throw new RuntimeException('Cache check blocked HTTP.'); }, PHP_INT_MAX);
$assertions = 0;
$check = static function ($condition, $message) use (&$assertions) {
    $assertions++;
    if (!$condition) { throw new RuntimeException($message); }
};
$count = static function (callable $callback) use (&$queries) { $before = $queries; $callback(); return $queries - $before; };
$db = WP_LOC::instance()->db;
$table = $db->get_table();
$languages = array_keys(WP_LOC_Languages::get_active_languages());

// Ground truth straight from the tables: term_id => [tt_id, language, [language => translated term_id]].
$truth = static function (string $taxonomy, int $limit, array $term_ids = []) use ($wpdb, $table) {
    $type = WP_LOC_DB::tax_element_type($taxonomy);
    $only = $term_ids ? ' AND tt.term_id IN (' . implode(',', array_map('intval', $term_ids)) . ')' : '';
    $rows = $wpdb->get_results($wpdb->prepare(
        "SELECT tt.term_id, tt.term_taxonomy_id, t.trid, t.language_code FROM {$wpdb->term_taxonomy} tt
         LEFT JOIN {$table} t ON t.element_id = tt.term_taxonomy_id AND t.element_type = %s
         WHERE tt.taxonomy = %s{$only} ORDER BY tt.term_id LIMIT %d", $type, $taxonomy, $limit));
    $trids = array_filter(array_map(static fn($row) => (int) $row->trid, $rows));
    $groups = [];
    if ($trids) {
        $members = $wpdb->get_results($wpdb->prepare(
            "SELECT t.trid, t.language_code, tt.term_id FROM {$table} t
             LEFT JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = t.element_id AND tt.taxonomy = %s
             WHERE t.element_type = %s AND t.trid IN (" . implode(',', array_unique($trids)) . ')', $taxonomy, $type));
        foreach ($members as $member) {
            $lang = WP_LOC_DB::from_db_language_code($member->language_code) ?: $member->language_code;
            // A group with two rows of one language has no single answer; leave it out.
            $groups[(int) $member->trid][$lang] = isset($groups[(int) $member->trid][$lang]) ? false : ($member->term_id ? (int) $member->term_id : null);
        }
    }
    $result = [];
    foreach ($rows as $row) {
        $group = $groups[(int) $row->trid] ?? [];
        if (in_array(false, $group, true)) { continue; }
        $result[(int) $row->term_id] = [
            'tt' => (int) $row->term_taxonomy_id,
            'lang' => $row->language_code ? (WP_LOC_DB::from_db_language_code($row->language_code) ?: $row->language_code) : null,
            'translations' => $group,
        ];
    }
    return $result;
};
$verify = static function (string $taxonomy, array $expected) use ($check, $languages) {
    foreach ($expected as $term_id => $term) {
        $check(WP_LOC_Terms::get_term_taxonomy_id($term_id, $taxonomy) === $term['tt'], "{$taxonomy} {$term_id}: term_taxonomy_id");
        $check(WP_LOC_Terms::get_term_id_from_taxonomy_id($term['tt'], $taxonomy) === $term_id, "{$taxonomy} {$term_id}: term_id from term_taxonomy_id");
        $check(WP_LOC_Terms::get_term_language($term_id, $taxonomy) === $term['lang'], "{$taxonomy} {$term_id}: language");
        foreach ($languages as $lang) {
            $check(WP_LOC_Terms::get_term_translation($term_id, $taxonomy, $lang) === ($term['translations'][$lang] ?? null), "{$taxonomy} {$term_id}: translation to {$lang}");
        }
    }
};

try {
    // 1. Cached lookups answer exactly what the tables say — cold, primed in bulk and warm.
    foreach (['category', 'post_tag', 'nav_menu'] as $taxonomy) {
        $expected = $truth($taxonomy, 400);
        if (!$expected) { continue; }
        wp_cache_flush();
        $verify($taxonomy, $expected);
        $check($count(static fn() => $verify($taxonomy, $expected)) === 0, "{$taxonomy}: a second pass runs no queries");
        wp_cache_flush();
        WP_LOC_Terms::prime_term_translations(array_keys($expected), $taxonomy);
        $check($count(static fn() => $verify($taxonomy, $expected)) === 0, "{$taxonomy}: primed lookups run no queries");
        // Unknown IDs are cached as misses, not looked up again.
        $missing = (int) $wpdb->get_var("SELECT MAX(term_taxonomy_id) FROM {$wpdb->term_taxonomy}") + 100000;
        $check(WP_LOC_Terms::get_term_taxonomy_id($missing, $taxonomy) === null && WP_LOC_Terms::get_term_id_from_taxonomy_id($missing, $taxonomy) === null, "{$taxonomy}: unknown IDs");
        $check($count(static fn() => WP_LOC_Terms::get_term_taxonomy_id($missing, $taxonomy) . WP_LOC_Terms::get_term_id_from_taxonomy_id($missing, $taxonomy)) === 0, "{$taxonomy}: misses are cached");
    }

    // 2. Frontend term output: a repeated pass over the categories costs no queries.
    $categories = get_terms(['taxonomy' => 'category', 'hide_empty' => false, 'fields' => 'ids', 'number' => 24]);
    wp_cache_flush();
    $render = static function () use ($categories) {
        foreach ($categories as $id) { $term = get_term($id, 'category'); get_term_link($term, 'category'); }
    };
    $first = $count($render);
    $second = $count($render);
    $check($second === 0, "Second pass over categories runs {$second} queries");
    WP_CLI::log(sprintf('%d categories x (get_term + get_term_link): %d queries cold, %d warm', count($categories), $first, $second));

    // 3. Writes, inside a transaction that is rolled back.
    $wpdb->query('START TRANSACTION');
    try {
        $auto_increment = static fn($table_name) => (int) $wpdb->get_var($wpdb->prepare(
            'SELECT AUTO_INCREMENT FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s', $table_name));
        $next_term = max((int) $wpdb->get_var("SELECT MAX(term_id) FROM {$wpdb->terms}") + 1, $auto_increment($wpdb->terms));
        $next_tt = max((int) $wpdb->get_var("SELECT MAX(term_taxonomy_id) FROM {$wpdb->term_taxonomy}") + 1, $auto_increment($wpdb->term_taxonomy));
        // Cache misses for the IDs the new terms are about to take.
        for ($i = 0; $i < 60; $i++) {
            WP_LOC_Terms::get_term_taxonomy_id($next_term + $i, 'post_tag');
            WP_LOC_Terms::get_term_id_from_taxonomy_id($next_tt + $i, 'post_tag');
        }

        $created = wp_insert_term('WPLOC cache check ' . wp_generate_password(8, false, false), 'post_tag');
        $check(!is_wp_error($created), 'Test tag is created');
        [$term_id, $tt_id] = [(int) $created['term_id'], (int) $created['term_taxonomy_id']];
        $check($term_id < $next_term + 60 && $tt_id < $next_tt + 60, 'The new tag took a pre-cached ID, so the check below is meaningful');
        $expected = $truth('post_tag', 100, [$term_id]);
        $check(isset($expected[$term_id]) && $expected[$term_id]['lang'] !== null, 'The new tag has a language row');
        $verify('post_tag', $expected);

        // Language links change: the tag moves to another language, then a second tag joins its group.
        $default = WP_LOC_Languages::get_default_language();
        $other = current(array_diff($languages, [$default]));
        $tag_ids = [$term_id];
        if ($other && count($expected[$term_id]['translations']) === 1) {
            $db->set_element_language($tt_id, 'tax_post_tag', $other);
            $verify('post_tag', $truth('post_tag', 100, $tag_ids));
            $check(WP_LOC_Terms::get_term_language($term_id, 'post_tag') === $other, 'Moved tag reports its new language');
            $joined = wp_insert_term('WPLOC join check ' . wp_generate_password(8, false, false), 'post_tag');
            $db->set_element_language((int) $joined['term_taxonomy_id'], 'tax_post_tag', $default, $db->get_trid($tt_id, 'tax_post_tag'));
            $tag_ids[] = (int) $joined['term_id'];
            $verify('post_tag', $truth('post_tag', 100, $tag_ids));
            $check(WP_LOC_Terms::get_term_translation($term_id, 'post_tag', $default) === (int) $joined['term_id'], 'Joined tag is the translation');
        }

        // Renaming keeps the pair and the group.
        wp_update_term($term_id, 'post_tag', ['name' => 'WPLOC renamed check ' . wp_generate_password(8, false, false)]);
        $expected = $truth('post_tag', 100, $tag_ids);
        $verify('post_tag', $expected);
        $siblings = array_filter($expected[$term_id]['translations'], static fn($id) => $id && $id !== $term_id);

        // term_exists() inside wp_delete_term() is language-scoped; the tag may be in another language now.
        $check(WP_LOC_Terms::without_language_filter(static fn() => wp_delete_term($term_id, 'post_tag')) === true, 'Test tag is deleted');
        $check(!$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->term_taxonomy} WHERE term_id IN (" . implode(',', array_merge([$term_id], $siblings)) . ') AND taxonomy = %s', 'post_tag')), 'The tag and its translations are gone from the table');
        foreach (array_merge([$term_id], $siblings) as $deleted) {
            $check(WP_LOC_Terms::get_term_taxonomy_id($deleted, 'post_tag') === null, "Deleted tag {$deleted} has no cached term_taxonomy_id");
        }
        $check(WP_LOC_Terms::get_term_id_from_taxonomy_id($tt_id, 'post_tag') === null, 'Deleted tag has no cached term_id');
        $check(WP_LOC_Terms::get_term_language($term_id, 'post_tag') === null, 'Deleted tag has no language');

        // A shared term split by core: its term_taxonomy row moves to a new term_id.
        $shared = wp_insert_term('WPLOC split check ' . wp_generate_password(8, false, false), 'link_category');
        $wpdb->insert($wpdb->term_taxonomy, ['term_id' => $shared['term_id'], 'taxonomy' => 'post_format', 'description' => '', 'parent' => 0, 'count' => 0]);
        $shared_tt = (int) $wpdb->insert_id;
        $check(WP_LOC_Terms::get_term_taxonomy_id((int) $shared['term_id'], 'post_format') === $shared_tt, 'Shared term pair before the split');
        $new_term_id = (int) _split_shared_term((int) $shared['term_id'], $shared_tt);
        $check($new_term_id && $new_term_id !== (int) $shared['term_id'], 'Core split the shared term');
        $check(WP_LOC_Terms::get_term_id_from_taxonomy_id($shared_tt, 'post_format') === $new_term_id, 'Split row resolves to the new term');
        $check(WP_LOC_Terms::get_term_taxonomy_id((int) $shared['term_id'], 'post_format') === null, 'Old term lost the split row');
        $check(WP_LOC_Terms::get_term_taxonomy_id($new_term_id, 'post_format') === $shared_tt, 'New term owns the split row');

        // A row changed by plain SQL, then announced with clean_term_cache().
        $moved = wp_insert_term('WPLOC move check ' . wp_generate_password(8, false, false), 'link_category');
        [$moved_id, $moved_tt] = [(int) $moved['term_id'], (int) $moved['term_taxonomy_id']];
        $check(WP_LOC_Terms::get_term_taxonomy_id($moved_id, 'link_category') === $moved_tt && WP_LOC_Terms::get_term_taxonomy_id($moved_id, 'post_format') === null, 'Pair before the move');
        $wpdb->update($wpdb->term_taxonomy, ['taxonomy' => 'post_format'], ['term_taxonomy_id' => $moved_tt]);
        clean_term_cache($moved_id, 'link_category');
        clean_term_cache($moved_id, 'post_format');
        $check(WP_LOC_Terms::get_term_taxonomy_id($moved_id, 'link_category') === null && WP_LOC_Terms::get_term_id_from_taxonomy_id($moved_tt, 'link_category') === null, 'Old taxonomy forgets the moved row');
        $check(WP_LOC_Terms::get_term_taxonomy_id($moved_id, 'post_format') === $moved_tt && WP_LOC_Terms::get_term_id_from_taxonomy_id($moved_tt, 'post_format') === $moved_id, 'New taxonomy finds the moved row');

        // Options read without filters follow add, update and delete at once, autoloaded or not.
        foreach ([false, true] as $autoload) {
            $name = 'wp_loc_cache_check_' . wp_generate_password(8, false, false);
            $check(WP_LOC_Options::get_unfiltered_option($name) === [false, null], 'Missing option');
            $check($count(static fn() => WP_LOC_Options::get_unfiltered_option($name)) === 0, 'Missing option is cached');
            add_option($name, ['a' => 1], '', $autoload);
            $check(WP_LOC_Options::get_unfiltered_option($name) === [true, ['a' => 1]], 'Added option');
            $check($count(static fn() => WP_LOC_Options::get_unfiltered_option($name)) === 0, 'Added option is cached');
            update_option($name, 'two');
            $check(WP_LOC_Options::get_unfiltered_option($name) === [true, 'two'], 'Updated option');
            delete_option($name);
            $check(WP_LOC_Options::get_unfiltered_option($name) === [false, null], 'Deleted option');
        }

        // A localized blogname is served from the cache and follows its updates.
        if ($other) {
            $base = get_option('blogname');
            $localized = 'blogname_' . WP_LOC_DB::to_db_language_code($other);
            WP_LOC_Routing::switch_language($other);
            try {
                update_option($localized, 'WPLOC name A');
                $check(get_option('blogname') === 'WPLOC name A', 'Localized blogname');
                get_option('page_on_front');
                $check($count(static fn() => get_option('blogname') . get_option('page_on_front') . get_option('blogname') . get_option('page_on_front')) === 0, 'Localized options repeat without queries');
                update_option($localized, 'WPLOC name B');
                $check(get_option('blogname') === 'WPLOC name B', 'Updated localized blogname');
                delete_option($localized);
                $check(get_option('blogname') === $base, 'Deleted localized blogname falls back to the base value');
            } finally {
                WP_LOC_Routing::switch_language($default);
            }
        }
    } finally {
        $wpdb->query('ROLLBACK');
        wp_cache_flush();
    }
} catch (Throwable $error) {
    WP_CLI::error($error->getMessage() . ' (after ' . $assertions . ' assertions)');
}

WP_CLI::success("Term and option caches: {$assertions} assertions passed.");
