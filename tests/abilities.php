<?php
/**
 * Checks for the AI-agent tools registered as WordPress Abilities (wp-loc/get-translations,
 * wp-loc/link-translation, wp-loc/create-translation). Run on a site with WordPress 6.9+, at least
 * two languages and no persistent object cache:
 * wp eval-file wp-content/plugins/wp-loc/tests/abilities.php
 * Every write happens inside a transaction that is rolled back.
 */
if (!defined('WP_CLI') || !WP_CLI) { exit; }
if (!class_exists('WP_LOC')) { WP_CLI::error('Active WP LOC is required.'); }
if (wp_using_ext_object_cache()) { WP_CLI::error('Run on a site without a persistent object cache.'); }
if (!function_exists('wp_get_ability')) { WP_CLI::error('WordPress 6.9+ (Abilities API) is required.'); }

global $wpdb;
add_filter('pre_http_request', static function () { throw new RuntimeException('Check blocked HTTP.'); }, PHP_INT_MAX);
$assertions = 0;
$check = static function ($condition, $message) use (&$assertions) {
    $assertions++;
    if (!$condition) { throw new RuntimeException($message); }
};
$default = WP_LOC_Languages::get_default_language();
$other = current(array_diff(array_keys(WP_LOC_Languages::get_active_languages()), [$default])) ?: null;
if (!$other) { WP_CLI::error('A second active language is required.'); }
$code_default = WP_LOC_DB::to_db_language_code($default);
$code_other = WP_LOC_DB::to_db_language_code($other);
$admins = get_users(['role' => 'administrator', 'fields' => 'ID', 'number' => 1]);
$previous_user = get_current_user_id();

/** Call an ability the way an MCP server does: validate, check permissions, execute. */
$call = static function ($name, array $input) {
    $ability = wp_get_ability($name);
    $input = $ability->normalize_input($input);
    $valid = $ability->validate_input($input);
    if (is_wp_error($valid)) { return $valid; }
    if ($ability->check_permissions($input) !== true) { return new WP_Error('permission', 'denied'); }
    return $ability->execute($input);
};

/** A fresh post that WP-LOC does not register on save (no language yet). */
$make_post = static function ($title, $type = 'post', $status = 'draft') {
    WP_LOC_Content::$suspend_new_post_registration = true;
    try {
        return (int) wp_insert_post(['post_title' => $title, 'post_type' => $type, 'post_status' => $status, 'post_content' => '<!-- wp:paragraph --><p>' . $title . '</p><!-- /wp:paragraph -->'], true);
    } finally {
        WP_LOC_Content::$suspend_new_post_registration = false;
    }
};

try {
    WP_CLI::log('registration');
    foreach (['wp-loc/get-translations', 'wp-loc/link-translation', 'wp-loc/create-translation'] as $name) {
        $ability = wp_get_ability($name);
        $check($ability instanceof WP_Ability, $name . ' is registered');
        $check($ability->get_category() === 'wp-loc', $name . ' is in the wp-loc category');
        $meta = $ability->get_meta();
        $check(($meta['mcp']['public'] ?? null) === true, $name . ' is flagged for MCP');
        $check(isset($meta['annotations']['readonly']), $name . ' has annotations');
        $schema = $ability->get_input_schema();
        $check(($schema['additionalProperties'] ?? null) === false, $name . ' refuses unknown input keys');
        foreach ($schema['properties'] as $prop => $def) {
            $check(!empty($def['description']), $name . ' describes ' . $prop);
        }
        $length = mb_strlen($ability->get_description() . ' (WordPress ability ' . $name . ')');
        $check($length <= 2048, $name . ' description fits 2048 characters with the suffix Simple MCP adds (' . $length . ')');
    }

    WP_CLI::log('languages');
    foreach ([$default, $code_default, strtoupper($code_default), WP_LOC_Languages::get_language_locale($default)] as $variant) {
        $lang = WP_LOC_Translation_Tools::resolve_lang($variant);
        $check(!is_wp_error($lang) && $lang['code'] === $code_default, 'Language "' . $variant . '" resolves to ' . $code_default);
    }
    $check(is_wp_error(WP_LOC_Translation_Tools::resolve_lang('xx-nope')), 'An unknown language is refused');
    $check(WP_LOC_Translation_Tools::default_code() === $code_default, 'The default language is reported as a wpml code');

    wp_set_current_user((int) ($admins[0] ?? 0));
    $wpdb->query('START TRANSACTION');
    try {
        WP_CLI::log('input validation and permissions');
        $result = $call('wp-loc/get-translations', ['element_id' => 1, 'bogus' => 1]);
        $check(is_wp_error($result), 'An unknown input key is refused by the schema');
        $result = $call('wp-loc/get-translations', ['element_id' => 999999999]);
        $check(is_wp_error($result) && $result->get_error_code() === 'not_found', 'A missing element is a not_found error, not a permission error');

        WP_CLI::log('create-translation of a post without a language');
        $source = $make_post('WP-LOC abilities source');
        $check($source > 0, 'A source post was created');
        $result = $call('wp-loc/create-translation', ['source_id' => $source, 'lang' => $other]);
        $check(is_wp_error($result) && $result->get_error_code() === 'source_language', 'A source without a language needs source_language');
        $result = $call('wp-loc/create-translation', ['source_id' => $source, 'lang' => $code_other, 'source_language' => $default]);
        $check(is_array($result) && empty($result['existing']) && $result['language'] === $code_other, 'The translation is created in ' . $code_other);
        $check($result['source']['registered'] === true && $result['source']['language'] === $code_default, 'The source was registered in the default language');
        $check($result['content_verified'] === true, 'The copied content is verified');
        $copy = (int) $result['new_id'];
        $check(get_post_status($copy) === get_post_status($source), 'With attribute sync the copy takes the source status');
        $check(WP_LOC::instance()->db->get_element_language($copy, 'post_post') === $other, 'The copy is registered in ' . $other);
        $check(WP_LOC::instance()->db->get_trid($copy, 'post_post') === WP_LOC::instance()->db->get_trid($source, 'post_post'), 'The copy shares the source trid');
        $check(!metadata_exists('post', $copy, '_wp_loc_is_new'), 'The copy is not flagged as a new post');

        $again = $call('wp-loc/create-translation', ['source_id' => $source, 'lang' => $other]);
        $check(is_array($again) && $again['existing'] === true && (int) $again['new_id'] === $copy, 'A second call returns the existing translation');
        $same = $call('wp-loc/create-translation', ['source_id' => $source, 'lang' => $default]);
        $check(is_wp_error($same) && $same->get_error_code() === 'same_language', 'The source language is refused as a target');
        if (WP_LOC_Admin_Settings::should_sync_post_attributes()) {
            // As in Simple MCP's tool, a status other than the source's is refused before the slot is looked at.
            $status = $call('wp-loc/create-translation', ['source_id' => $source, 'lang' => $other, 'status' => 'publish']);
            $check(is_wp_error($status) && $status->get_error_code() === 'status_sync', 'With attribute sync a status other than the source\'s is refused');
        }

        WP_CLI::log('get-translations');
        $group = $call('wp-loc/get-translations', ['element_id' => $source]);
        $check(is_array($group) && $group['kind'] === 'post' && $group['language'] === $code_default && $group['is_original'] === true, 'The source is the original in ' . $code_default);
        $check((int) ($group['translations'][$code_other]['id'] ?? 0) === $copy, 'The group lists the copy under ' . $code_other);
        $check(!in_array($code_other, $group['missing'], true), $code_other . ' is not missing');
        $check(is_int($group['trid']) && $group['trid'] > 0, 'The group has a trid');

        WP_CLI::log('link-translation');
        $other_post = $make_post('WP-LOC abilities other');
        $taken = $call('wp-loc/link-translation', ['source_id' => $source, 'target_id' => $other_post, 'lang' => $other]);
        $check(is_wp_error($taken) && $taken->get_error_code() === 'slot_taken', 'A held slot is refused without replace');
        $linked = $call('wp-loc/link-translation', ['source_id' => $source, 'target_id' => $other_post, 'lang' => $other, 'replace' => true]);
        $check(is_array($linked) && $linked['unchanged'] === false && (int) $linked['translations'][$code_other] === $other_post, 'replace:true links the target into the slot');
        $check((int) ($linked['replaced'][0]['id'] ?? 0) === $copy, 'The replaced copy is reported');
        $check(WP_LOC::instance()->db->get_trid($copy, 'post_post') !== WP_LOC::instance()->db->get_trid($source, 'post_post'), 'The replaced copy got a group of its own');
        $check(WP_LOC::instance()->db->get_element_language($copy, 'post_post') === $other, 'The replaced copy keeps its language');
        $unchanged = $call('wp-loc/link-translation', ['source_id' => $source, 'target_id' => $other_post, 'lang' => $code_other]);
        $check(is_array($unchanged) && $unchanged['unchanged'] === true, 'Linking again changes nothing');
        $original = $call('wp-loc/link-translation', ['source_id' => $other_post, 'target_id' => $source, 'lang' => $other]);
        $check(is_wp_error($original), 'The original cannot be relabeled');
        $self = $call('wp-loc/link-translation', ['source_id' => $source, 'target_id' => $source, 'lang' => $other]);
        $check(is_wp_error($self) && $self->get_error_code() === 'same_element', 'An element cannot be its own translation');
        $moved = $call('wp-loc/link-translation', ['source_id' => $copy, 'target_id' => $other_post, 'lang' => $default, 'source_language' => $other]);
        $check(is_wp_error($moved) && $moved->get_error_code() === 'other_group', 'A target in another group needs move:true');

        WP_CLI::log('create-translation of a term');
        $term = wp_insert_term('WP-LOC abilities term', 'category');
        $check(!is_wp_error($term), 'A source term was created');
        $term_id = (int) $term['term_id'];
        WP_LOC::instance()->db->set_element_language((int) $term['term_taxonomy_id'], 'tax_category', $default);
        $term_result = $call('wp-loc/create-translation', ['source_id' => $term_id, 'kind' => 'term', 'lang' => $other, 'name' => 'WP-LOC abilities term ' . $other]);
        $check(is_array($term_result) && empty($term_result['existing']) && $term_result['language'] === $code_other, 'The term translation is created');
        $new_tt = (int) $term_result['term_taxonomy_id'];
        $check(WP_LOC::instance()->db->get_trid($new_tt, 'tax_category') === WP_LOC::instance()->db->get_trid((int) $term['term_taxonomy_id'], 'tax_category'), 'The new term shares the source trid');
        $term_group = $call('wp-loc/get-translations', ['element_id' => $term_id, 'kind' => 'term']);
        $check(is_array($term_group) && (int) ($term_group['translations'][$code_other]['term_id'] ?? 0) === (int) $term_result['new_term_id'], 'The term group lists the new term');

        WP_CLI::log('permissions of a user without rights');
        wp_set_current_user(0);
        foreach ([
            ['wp-loc/get-translations', ['element_id' => $source]],
            ['wp-loc/link-translation', ['source_id' => $source, 'target_id' => $other_post, 'lang' => $other]],
            ['wp-loc/create-translation', ['source_id' => $source, 'lang' => $other]],
        ] as [$name, $input]) {
            $check(wp_get_ability($name)->check_permissions($input) === false, $name . ' is refused to a visitor for a draft');
        }
        $check(wp_get_ability('wp-loc/get-translations')->check_permissions(['element_id' => 999999999]) === false, 'Input that resolves to nothing still needs a logged-in user');
    } finally {
        $wpdb->query('ROLLBACK');
        wp_set_current_user($previous_user);
    }

    WP_CLI::log('setting');
    $check(WP_LOC_Admin_Settings::are_abilities_enabled() === (bool) get_option('wp_loc_enable_abilities', true), 'The setting getter reads the option, on by default');
} catch (Throwable $e) {
    WP_CLI::error($e->getMessage() . ' (after ' . $assertions . ' passed checks)');
}

WP_CLI::success($assertions . ' checks passed.');
