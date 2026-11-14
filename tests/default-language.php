<?php
/**
 * Checks for changing the default language (WP_LOC_Language_Setup::switch_default_language() and a
 * WPLANG change) and for adding a language. Run on a site with at least two active languages,
 * WordPress translation files already installed for both, ACF Pro and no persistent object cache:
 * wp eval-file wp-content/plugins/wp-loc/tests/default-language.php
 *
 * The default language really changes and changes back (the switch commits its own transaction),
 * so export the options table first and import it afterwards if you need the exact rows back.
 * The test removes its own fixtures and returns WPLANG, the default language and the language list.
 * HTTP is blocked: nothing is downloaded. What each language shows is read in a new WP-CLI process
 * (eval-file this-file snapshot <user_id>), the way the next page load reads it.
 */
if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) { exit; }
if ( ! class_exists( 'WP_LOC' ) ) { WP_CLI::error( 'Active WP LOC is required.' ); }
if ( wp_using_ext_object_cache() ) { WP_CLI::error( 'Run on a site without a persistent object cache.' ); }
if ( ! function_exists( 'acf_add_local_field_group' ) ) { WP_CLI::error( 'ACF Pro is required.' ); }

global $wpdb;
add_filter( 'pre_http_request', static function () { return new WP_Error( 'blocked', 'Check blocked HTTP.' ); }, PHP_INT_MAX );
$assertions = 0;
$check = static function ( $condition, $message ) use ( &$assertions ) {
	$assertions++;
	if ( ! $condition ) { throw new RuntimeException( $message ); }
};

$a = WP_LOC_Languages::get_default_language();
$b = current( array_diff( array_keys( WP_LOC_Languages::get_active_languages() ), [ $a ] ) ) ?: null;
if ( ! $b ) { WP_CLI::error( 'A second active language is required.' ); }
$locale_a = WP_LOC_Languages::get_language_locale( $a );
$locale_b = WP_LOC_Languages::get_language_locale( $b );
foreach ( [ $locale_a, $locale_b ] as $locale ) {
	if ( $locale !== 'en_US' && ! in_array( $locale, get_available_languages(), true ) ) { WP_CLI::error( "Install the WordPress translation files for {$locale} first." ); }
}
$code_a = WP_LOC_DB::to_db_language_code( $a );
$code_b = WP_LOC_DB::to_db_language_code( $b );

$initial = [
	'WPLANG'    => get_option( 'WPLANG', null ),
	'default'   => get_option( WP_LOC_Languages::DEFAULT_LANGUAGE_OPTION_KEY, null ),
	'languages' => get_option( 'wp_loc_languages' ),
];
WP_CLI::log( "default {$a} ({$locale_a}), second {$b} ({$locale_b})" );

// Fixtures: two multilingual options, ACF fields on an options page and on users, a test user.
$p = 'options';
$register_fixtures = static function () {
$options_page = array_key_first( (array) acf_get_options_pages() ) ?: 'wploc-test';
acf_add_local_field_group( [
	'key'      => 'group_wploc_switch_test',
	'title'    => 'WP-LOC switch test',
	'fields'   => [
		[ 'key' => 'field_wploc_t_title', 'name' => 'wploc_t_title', 'label' => 'T', 'type' => 'text', 'translation_mode' => 'translatable' ],
		[ 'key' => 'field_wploc_t_title_extra', 'name' => 'wploc_t_title_extra', 'label' => 'TE', 'type' => 'text', 'translation_mode' => 'translatable' ],
		[ 'key' => 'field_wploc_t_rep', 'name' => 'wploc_t_rep', 'label' => 'R', 'type' => 'repeater', 'translation_mode' => 'translatable', 'sub_fields' => [
			[ 'key' => 'field_wploc_t_txt', 'name' => 'wploc_t_txt', 'label' => 'X', 'type' => 'text' ],
		] ],
		[ 'key' => 'field_wploc_t_shared', 'name' => 'wploc_t_shared', 'label' => 'S', 'type' => 'text', 'translation_mode' => 'shared' ],
	],
	'location' => [ [ [ 'param' => 'options_page', 'operator' => '==', 'value' => $options_page ] ] ],
] );
acf_add_local_field_group( [
	'key'      => 'group_wploc_switch_user_test',
	'title'    => 'WP-LOC switch user test',
	'fields'   => [
		[ 'key' => 'field_wploc_u_bio', 'name' => 'wploc_u_bio', 'label' => 'B', 'type' => 'text', 'translation_mode' => 'translatable' ],
	],
	'location' => [ [ [ 'param' => 'user_form', 'operator' => '==', 'value' => 'all' ] ] ],
] );
do_action( 'wp_loc_multilingual_options', 'wp_loc_test_ml' );
do_action( 'wp_loc_multilingual_options', 'wp_loc_test_ml_empty' );
};
$register_fixtures();

$rows = [
	'wp_loc_test_ml'                    => 'A-ml',
	"wp_loc_test_ml_{$code_b}"          => 'B-ml',
	'wp_loc_test_ml_empty'              => 'A-only',
	"wp_loc_test_ml_empty_{$code_b}"    => '',
	"{$p}_wploc_t_title"                => 'A title',
	"_{$p}_wploc_t_title"               => 'field_wploc_t_title',
	"{$p}_{$b}_wploc_t_title"           => 'B title',
	"_{$p}_{$b}_wploc_t_title"          => 'field_wploc_t_title',
	"{$p}_wploc_t_title_extra"          => 'A extra',
	"_{$p}_wploc_t_title_extra"         => 'field_wploc_t_title_extra',
	"{$p}_wploc_t_rep"                  => 3,
	"_{$p}_wploc_t_rep"                 => 'field_wploc_t_rep',
	"{$p}_{$b}_wploc_t_rep"             => 2,
	"_{$p}_{$b}_wploc_t_rep"            => 'field_wploc_t_rep',
	"{$p}_wploc_t_shared"               => 'Shared',
	"_{$p}_wploc_t_shared"              => 'field_wploc_t_shared',
];
for ( $i = 0; $i < 3; $i++ ) {
	$rows[ "{$p}_wploc_t_rep_{$i}_wploc_t_txt" ] = "A row {$i}";
	$rows[ "_{$p}_wploc_t_rep_{$i}_wploc_t_txt" ] = 'field_wploc_t_txt';
}
for ( $i = 0; $i < 2; $i++ ) {
	$rows[ "{$p}_{$b}_wploc_t_rep_{$i}_wploc_t_txt" ] = "B row {$i}";
	$rows[ "_{$p}_{$b}_wploc_t_rep_{$i}_wploc_t_txt" ] = 'field_wploc_t_txt';
}

$user_id = 0;
$fixture_like = [ 'wp\_loc\_test\_ml%', "%wploc\_t\_%" ];
$cleanup = static function () use ( $wpdb, &$user_id, $fixture_like ) {
	foreach ( $fixture_like as $like ) {
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $like ) );
	}
	wp_cache_delete( 'alloptions', 'options' );
	wp_cache_delete( 'notoptions', 'options' );
	if ( $user_id ) {
		require_once ABSPATH . 'wp-admin/includes/user.php';
		wp_delete_user( $user_id );
	}
};

/** What each language shows: the options, ACF values on options pages and on the test user. */
$read_snapshot = static function ( int $user_id ) use ( $p ) {
	$out = [];
	$names = array_keys( (array) ( new ReflectionProperty( 'WP_LOC_Options', 'multilingual_options' ) )->getValue() );
	$acf_fields = [];
	foreach ( (array) acf_get_field_groups() as $group ) {
		foreach ( (array) acf_get_fields( $group ) as $field ) {
			if ( ! empty( $field['name'] ) ) { $acf_fields[ $field['name'] ] = true; }
		}
	}
	$post_ids = array_unique( array_merge( [ $p ], array_filter( array_map( static fn( $page ) => $page['post_id'] ?? '', (array) acf_get_options_pages() ) ) ) );
	foreach ( array_keys( WP_LOC_Languages::get_active_languages() ) as $lang ) {
		WP_LOC_Routing::switch_language( $lang );
		acf_get_store( 'values' )->reset();
		foreach ( $names as $name ) {
			$out[ $lang ][ $name ] = get_option( $name );
		}
		foreach ( $post_ids as $post_id ) {
			foreach ( array_keys( $acf_fields ) as $name ) {
				$out[ $lang ][ "{$post_id}:{$name}" ] = get_field( $name, $post_id, false );
			}
		}
		$out[ $lang ]['user:wploc_u_bio'] = get_field( 'wploc_u_bio', 'user_' . $user_id, false );
	}
	return $out;
};

if ( ( $args[0] ?? '' ) === 'snapshot' ) {
	echo wp_json_encode( $read_snapshot( (int) ( $args[1] ?? 0 ) ) );
	return;
}

$snapshot = static function () use ( &$user_id ) {
	$file = WP_LOC_PATH . 'tests/default-language.php';
	$result = WP_CLI::runcommand( 'eval-file ' . escapeshellarg( $file ) . ' snapshot ' . (int) $user_id, [ 'launch' => true, 'return' => 'all', 'exit_error' => false ] );
	$json = '';
	foreach ( preg_split( '/\R/', (string) $result->stdout ) as $line ) {
		if ( str_starts_with( $line, '{' ) ) { $json = $line; }
	}
	$data = json_decode( $json, true );
	if ( ! is_array( $data ) ) {
		throw new RuntimeException( 'The snapshot process failed: ' . trim( (string) $result->stderr ) );
	}
	return $data;
};
$normalize = static function ( $value ) use ( &$normalize ) {
	if ( is_array( $value ) ) {
		return array_map( $normalize, $value );
	}
	return $value === null || $value === false ? '' : (string) $value;
};
// The two languages that swap places show what they showed. A third language without a value of
// its own showed the default language's and now shows the new default language's (checked apart).
$diff = static function ( array $before, array $after ) use ( $normalize, $a, $b ): string {
	$lines = [];
	foreach ( array_intersect_key( $before, [ $a => true, $b => true ] ) as $lang => $values ) {
		foreach ( $values as $key => $value ) {
			// Stored and resolved values differ in type ("416" / 416), and an empty value is '' or null.
			if ( $normalize( $after[ $lang ][ $key ] ?? null ) !== $normalize( $value ) ) {
				$lines[] = "{$lang} {$key}: " . wp_json_encode( $value ) . ' -> ' . wp_json_encode( $after[ $lang ][ $key ] ?? null );
			}
		}
	}
	return implode( '; ', $lines );
};
$raw = static function ( $name ) {
	[ $exists, $value ] = WP_LOC_Options::get_unfiltered_option( $name );
	return $exists ? $value : '(none)';
};

try {
	$cleanup();
	foreach ( $rows as $name => $value ) {
		update_option( $name, $value );
	}
	$user_id = (int) wp_insert_user( [ 'user_login' => 'wploc_switch_' . wp_generate_password( 6, false ), 'user_pass' => wp_generate_password( 24 ), 'role' => 'subscriber' ] );
	$check( $user_id > 0, 'A test user was created' );
	update_user_meta( $user_id, 'wploc_u_bio', 'A bio' );
	update_user_meta( $user_id, '_wploc_u_bio', 'field_wploc_u_bio' );
	update_user_meta( $user_id, "wploc_u_bio_{$code_b}", 'B bio' );
	update_user_meta( $user_id, "_wploc_u_bio_{$code_b}", 'field_wploc_u_bio' );

	$before = $snapshot();
	$check( $before[ $b ]['wp_loc_test_ml'] === 'B-ml' && $before[ $a ]['wp_loc_test_ml'] === 'A-ml', 'The fixture option shows per language' );
	$check( $before[ $b ][ "{$p}:wploc_t_title" ] === 'B title' && $before[ $a ][ "{$p}:wploc_t_title" ] === 'A title', 'The ACF fixture shows per language' );
	$check( $before[ $b ]['user:wploc_u_bio'] === 'B bio' && $before[ $a ]['user:wploc_u_bio'] === 'A bio', 'The user fixture shows per language' );

	WP_CLI::log( "switch to {$b} from the language manager" );
	$result = WP_LOC_Language_Setup::switch_default_language( $b );
	$check( is_array( $result ) && $result['from'] === $a && $result['to'] === $b, 'The switch reports ' . $a . ' -> ' . $b . ( is_wp_error( $result ) ? ': ' . $result->get_error_message() : '' ) );
	$check( WP_LOC_Languages::get_default_language() === $b, 'The default language is ' . $b );
	$check( (string) get_option( 'WPLANG' ) === ( $locale_b === 'en_US' ? '' : $locale_b ), 'WPLANG follows the default language' );
	$check( get_option( WP_LOC_Languages::DEFAULT_LANGUAGE_OPTION_KEY ) === $b, 'The default is stored' );
	$check( count( WP_LOC_Languages::get_languages() ) === count( (array) $initial['languages'] ), 'No language was added' );

	$after = $snapshot();
	$check( $diff( $before, $after ) === '', 'Every language shows what it showed: ' . $diff( $before, $after ) );
	foreach ( array_diff( array_keys( $after ), [ $a, $b ] ) as $third ) {
		$check( $after[ $third ]['wp_loc_test_ml'] === 'B-ml' && $after[ $third ][ "{$p}:wploc_t_title" ] === 'B title', "{$third}, without values of its own, now shows the new default language's" );
	}

	$check( $raw( 'wp_loc_test_ml' ) === 'B-ml' && $raw( "wp_loc_test_ml_{$code_a}" ) === 'A-ml' && $raw( "wp_loc_test_ml_{$code_b}" ) === '(none)', 'The option moved: base is B, A has its own row' );
	$check( $raw( 'wp_loc_test_ml_empty' ) === 'A-only' && $raw( "wp_loc_test_ml_empty_{$code_a}" ) === '(none)' && $raw( "wp_loc_test_ml_empty_{$code_b}" ) === '(none)', 'An empty translation does not move, its row is gone' );
	$check( $raw( "{$p}_wploc_t_title" ) === 'B title' && $raw( "{$p}_{$a}_wploc_t_title" ) === 'A title' && $raw( "_{$p}_{$a}_wploc_t_title" ) === 'field_wploc_t_title', 'The ACF value and its reference moved' );
	$check( $raw( "{$p}_{$b}_wploc_t_title" ) === '(none)' && $raw( "_{$p}_{$b}_wploc_t_title" ) === '(none)', 'B rows of the ACF value are gone' );
	$check( $raw( "{$p}_wploc_t_title_extra" ) === 'A extra' && $raw( "{$p}_{$a}_wploc_t_title_extra" ) === '(none)', 'A field B has no value for keeps its base value (B\'s fallback), A falls back to it too' );
	$check( $raw( "{$p}_{$a}_wploc_t_title" ) === 'A title', 'Rows of a longer field name are not taken for sub-values of a shorter one' );
	$check( (int) $raw( "{$p}_wploc_t_rep" ) === 2 && (int) $raw( "{$p}_{$a}_wploc_t_rep" ) === 3 && $raw( "{$p}_{$a}_wploc_t_rep_2_wploc_t_txt" ) === 'A row 2', 'The repeater moved whole' );
	$check( $raw( "{$p}_wploc_t_shared" ) === 'Shared' && $raw( "{$p}_{$a}_wploc_t_shared" ) === '(none)', 'A shared field stays in the base' );
	$check( get_user_meta( $user_id, 'wploc_u_bio', true ) === 'B bio' && get_user_meta( $user_id, "wploc_u_bio_{$code_a}", true ) === 'A bio' && ! metadata_exists( 'user', $user_id, "wploc_u_bio_{$code_b}" ), 'The user value moved' );
	$check( get_user_meta( $user_id, "_wploc_u_bio_{$code_a}", true ) === 'field_wploc_u_bio' && get_user_meta( $user_id, '_wploc_u_bio', true ) === 'field_wploc_u_bio', 'User references are in place' );

	$again = WP_LOC_Language_Setup::switch_default_language( $b );
	$check( is_wp_error( $again ) && $again->get_error_code() === 'wp_loc_same_language', 'The current default cannot be made default again' );

	WP_CLI::log( "switch back to {$a} through the site language (Settings → General)" );
	update_option( 'WPLANG', $locale_a === 'en_US' ? '' : $locale_a );
	WP_LOC_Languages::flush();
	$check( WP_LOC_Languages::get_default_language() === $a, 'A WPLANG change makes ' . $a . ' the default again' );
	$back = $snapshot();
	$check( $diff( $before, $back ) === '', 'After the round trip every language shows what it showed: ' . $diff( $before, $back ) );
	$check( $raw( 'wp_loc_test_ml' ) === 'A-ml' && $raw( "wp_loc_test_ml_{$code_b}" ) === 'B-ml' && $raw( "wp_loc_test_ml_{$code_a}" ) === '(none)', 'The option is back in its rows' );

	WP_CLI::log( 'a default language set apart from the site language keeps it' );
	update_option( WP_LOC_Languages::DEFAULT_LANGUAGE_OPTION_KEY, $b );
	WP_LOC_Languages::flush();
	$check( WP_LOC_Languages::get_default_language() === $b, 'The explicit default is ' . $b . ' while WPLANG is ' . $locale_a );
	$other_locale = WP_LOC_Languages::get_language_slug( 'pl_PL' ) === null ? 'pl_PL' : 'cs_CZ';
	if ( WP_LOC_Languages::get_language_slug( $other_locale ) === null ) {
		$languages_before = get_option( 'wp_loc_languages' );
		update_option( 'WPLANG', $other_locale === 'en_US' ? '' : $other_locale );
		WP_LOC_Languages::flush();
		$check( WP_LOC_Languages::get_default_language() === $b, 'A WPLANG change does not move a default that was set apart' );
		update_option( 'wp_loc_languages', $languages_before );
		update_option( 'WPLANG', $locale_a === 'en_US' ? '' : $locale_a );
	}
	update_option( WP_LOC_Languages::DEFAULT_LANGUAGE_OPTION_KEY, $a );
	WP_LOC_Languages::flush();

	WP_CLI::log( 'adding a language' );
	$admins = get_users( [ 'role' => 'administrator', 'fields' => 'ID', 'number' => 1 ] );
	wp_set_current_user( (int) ( $admins[0] ?? 0 ) );
	$addable = WP_LOC_Language_Setup::get_addable_languages();
	$addable_locales = wp_list_pluck( $addable, 'locale' );
	$check( ! in_array( $locale_a, $addable_locales, true ) && ! in_array( $locale_b, $addable_locales, true ), 'Configured languages are not offered' );
	$unknown = WP_LOC_Language_Setup::add_language( 'xx_XX' );
	$check( is_wp_error( $unknown ) && $unknown->get_error_code() === 'wp_loc_unknown_locale', 'An unknown locale is refused' );
	$installed_new = current( array_filter( $addable, static fn( $language ) => $language['installed'] ) );
	if ( $installed_new ) {
		$added = WP_LOC_Language_Setup::add_language( $installed_new['locale'] );
		$check( is_array( $added ) && $added['files'] === 'present', 'An installed locale is added without downloading' );
		$languages = WP_LOC_Languages::get_languages();
		$check( ! empty( $languages[ $added['slug'] ]['enabled'] ) && $languages[ $added['slug'] ]['locale'] === $installed_new['locale'], 'The added language is enabled' );
		$check( WP_LOC_Languages::get_default_language() === $a && (string) get_option( 'WPLANG' ) === (string) $initial['WPLANG'], 'Adding changes neither the default nor the site language' );
	} else {
		WP_CLI::log( '  no installed, unconfigured locale to add here' );
	}
	$reflection = new ReflectionMethod( 'WP_LOC_Language_Setup', 'unique_slug' );
	$reflection->setAccessible( true );
	$taken = [ 'en' => [], 'en-gb' => [] ];
	$check( $reflection->invoke( null, 'en_GB', $taken ) === 'en-gb-2', 'A taken slug gets a unique one' );
	$check( $reflection->invoke( null, 'pl_PL', $taken ) === 'pl', 'A free slug comes from the locale' );
} catch ( Throwable $e ) {
	$failure = $e->getMessage() . ' (after ' . $assertions . ' passed checks)';
} finally {
	$cleanup();
	update_option( 'wp_loc_languages', $initial['languages'] );
	if ( $initial['WPLANG'] === null ) { delete_option( 'WPLANG' ); } else { update_option( 'WPLANG', $initial['WPLANG'] ); }
	if ( $initial['default'] === null ) { delete_option( WP_LOC_Languages::DEFAULT_LANGUAGE_OPTION_KEY ); } else { update_option( WP_LOC_Languages::DEFAULT_LANGUAGE_OPTION_KEY, $initial['default'] ); }
	WP_LOC_Languages::flush();
}

if ( isset( $failure ) ) {
	WP_CLI::error( $failure );
}

WP_CLI::success( $assertions . ' checks passed.' );
