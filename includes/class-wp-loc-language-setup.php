<?php

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Adding a language and changing the default one, the way Settings → General does it.
 *
 * Adding installs WordPress's translation files for the locale (core, then the installed plugins
 * and themes), like picking it as the Site Language, but leaves the site language as it is.
 * The default language and the WordPress site language (WPLANG) are one setting: making a language
 * the default also sets WPLANG, and a WPLANG change makes that language the default. The data kept
 * in "base" rows for the default language (localized options, Yoast settings, ACF options pages
 * and user fields) moves with it, so every language keeps showing what it showed before.
 */
class WP_LOC_Language_Setup {

	/** True while switch_default_language() runs, so its own WPLANG update is not taken for a new switch. */
	private static $switching = false;

	public static function is_switching(): bool {
		return self::$switching;
	}

	/**
	 * Languages WordPress offers that are not configured yet, for the "Add language" list:
	 * installed ones first, then those WordPress can download (when this site may install them).
	 *
	 * @return array<int,array{locale:string,name:string,installed:bool}>
	 */
	public static function get_addable_languages(): array {
		if ( ! function_exists( 'wp_get_available_translations' ) ) {
			require_once ABSPATH . 'wp-admin/includes/translation-install.php';
		}

		$configured = [];

		foreach ( WP_LOC_Languages::get_languages() as $data ) {
			$configured[ (string) ( $data['locale'] ?? '' ) ] = true;
		}

		$installed = array_merge( [ 'en_US' ], get_available_languages() );
		$translations = self::can_install_files() ? wp_get_available_translations() : [];
		$languages = [];

		foreach ( $installed as $locale ) {
			if ( isset( $configured[ $locale ] ) || isset( $languages[ $locale ] ) ) {
				continue;
			}

			$languages[ $locale ] = [
				'locale'    => $locale,
				'name'      => $translations[ $locale ]['native_name'] ?? WP_LOC_Languages::get_language_display_name( $locale ),
				'installed' => true,
			];
		}

		foreach ( $translations as $locale => $translation ) {
			$locale = (string) $locale;

			if ( isset( $configured[ $locale ] ) || isset( $languages[ $locale ] ) ) {
				continue;
			}

			$languages[ $locale ] = [
				'locale'    => $locale,
				'name'      => (string) ( $translation['native_name'] ?? $locale ),
				'installed' => false,
			];
		}

		return array_values( $languages );
	}

	/** Whether this site may download translation files (capability, file mods, direct file access). */
	public static function can_install_files(): bool {
		if ( ! current_user_can( 'install_languages' ) ) {
			return false;
		}

		if ( ! function_exists( 'wp_can_install_language_pack' ) ) {
			require_once ABSPATH . 'wp-admin/includes/translation-install.php';
		}

		return wp_can_install_language_pack();
	}

	/**
	 * Install WordPress's translation files for a locale, as Settings → General does when a new
	 * Site Language is saved, then the translations of the installed plugins and themes.
	 *
	 * @return string "present" (nothing to install), "installed", "not_allowed" or "failed".
	 */
	public static function install_language_files( string $locale ): string {
		if ( $locale === 'en_US' || in_array( $locale, get_available_languages(), true ) ) {
			return 'present';
		}

		if ( ! self::can_install_files() ) {
			return 'not_allowed';
		}

		if ( ! wp_download_language_pack( $locale ) || ! in_array( $locale, get_available_languages(), true ) ) {
			return 'failed';
		}

		self::install_extension_translations( $locale );

		return 'installed';
	}

	/**
	 * Translations of the installed plugins and themes for a locale that has just been installed.
	 * WordPress would fetch them with its next update check; this asks for them now.
	 */
	private static function install_extension_translations( string $locale ): int {
		require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';

		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 300 );
		}

		// The update checks send the installed languages, which now include this one.
		wp_clean_update_cache();
		wp_update_plugins();
		wp_update_themes();

		$updates = array_values( array_filter(
			wp_get_translation_updates(),
			static fn( $update ): bool => is_object( $update ) && ( $update->language ?? '' ) === $locale
		) );

		if ( ! $updates ) {
			return 0;
		}

		$upgrader = new Language_Pack_Upgrader( new Automatic_Upgrader_Skin() );
		$results = $upgrader->bulk_upgrade( $updates, [ 'clear_update_cache' => false ] );

		return is_array( $results ) ? count( array_filter( $results, static fn( $result ): bool => $result === true || ( $result && ! is_wp_error( $result ) ) ) ) : 0;
	}

	/**
	 * Add a configured language for a locale WordPress knows, with its translation files.
	 * It is enabled; the default language and the site language do not change.
	 *
	 * @return array{slug:string,files:string}|WP_Error
	 */
	public static function add_language( string $locale ) {
		$locale = trim( $locale );
		$addable = wp_list_pluck( self::get_addable_languages(), 'locale' );

		if ( $locale === '' || ! in_array( $locale, $addable, true ) ) {
			return new WP_Error( 'wp_loc_unknown_locale', __( 'This language cannot be added.', 'wp-loc' ) );
		}

		$files = self::install_language_files( $locale );
		$slug = self::register_language( $locale );

		update_option( 'wp_loc_flush_rewrite_rules', true );

		return [
			'slug'  => $slug,
			'files' => $files,
		];
	}

	/**
	 * Put a locale into wp_loc_languages, or enable it if it is there. Returns its slug.
	 */
	public static function register_language( string $locale ): string {
		$languages = WP_LOC_Languages::get_languages();
		$slug = WP_LOC_Languages::get_language_slug( $locale );

		if ( $slug !== null ) {
			$languages[ $slug ]['enabled'] = true;

			if ( empty( $languages[ $slug ]['wpml_code'] ) ) {
				$languages[ $slug ]['wpml_code'] = WP_LOC_Language_Registry::wpml_code_from_locale( $locale );
			}
		} else {
			$slug = self::unique_slug( $locale, $languages );
			$languages[ $slug ] = [
				'locale'       => $locale,
				'enabled'      => true,
				'display_name' => strtoupper( $slug ),
				'wpml_code'    => WP_LOC_Language_Registry::wpml_code_from_locale( $locale ),
			];
		}

		update_option( 'wp_loc_languages', $languages );
		WP_LOC_Languages::flush();

		return $slug;
	}

	private static function unique_slug( string $locale, array $languages ): string {
		$slug = sanitize_title( WP_LOC_Languages::locale_to_slug( $locale ) );

		if ( $slug === '' || isset( $languages[ $slug ] ) ) {
			$slug = sanitize_title( strtolower( str_replace( '_', '-', $locale ) ) );
		}

		$base = $slug;

		for ( $i = 2; isset( $languages[ $slug ] ); $i++ ) {
			$slug = $base . '-' . $i;
		}

		return $slug;
	}

	/**
	 * Make an enabled language the default one. Moves the data kept for the default language,
	 * stores the choice and, unless $update_wplang is false (WPLANG has changed already),
	 * sets the WordPress site language to it.
	 *
	 * @param string      $to            Slug of the new default language.
	 * @param string|null $from          Slug of the current default language; resolved when null.
	 * @param bool        $update_wplang Whether to set WPLANG to the new language's locale.
	 * @return array{from:string,to:string,moved:array,files:string}|WP_Error
	 */
	public static function switch_default_language( string $to, ?string $from = null, bool $update_wplang = true ) {
		global $wpdb;

		$to = sanitize_key( $to );
		$from = $from !== null ? sanitize_key( $from ) : WP_LOC_Languages::get_default_language();
		$active = WP_LOC_Languages::get_active_languages();

		if ( ! isset( $active[ $to ] ) ) {
			return new WP_Error( 'wp_loc_inactive_language', __( 'Only an enabled language can be the default one.', 'wp-loc' ) );
		}

		if ( $to === $from ) {
			return new WP_Error( 'wp_loc_same_language', __( 'This language is already the default one.', 'wp-loc' ) );
		}

		$locale = (string) ( $active[ $to ]['locale'] ?? $to );
		$files = $update_wplang ? self::install_language_files( $locale ) : 'present';
		$moved = [];

		self::$switching = true;
		$wpdb->query( 'START TRANSACTION' );

		try {
			$moved['options'] = WP_LOC::instance()->options->move_default_language_values( $from, $to );

			if ( WP_LOC::instance()->acf instanceof WP_LOC_ACF ) {
				$moved += WP_LOC::instance()->acf->move_default_language_values( $from, $to );
			}

			update_option( WP_LOC_Languages::DEFAULT_LANGUAGE_OPTION_KEY, $to );

			if ( $update_wplang ) {
				update_option( 'WPLANG', $locale === 'en_US' ? '' : $locale );
			}

			$wpdb->query( 'COMMIT' );
		} catch ( Throwable $e ) {
			$wpdb->query( 'ROLLBACK' );
			wp_cache_flush();

			return new WP_Error( 'wp_loc_switch_failed', $e->getMessage() );
		} finally {
			self::$switching = false;
			WP_LOC_Languages::flush();
		}

		update_option( 'wp_loc_flush_rewrite_rules', true );

		/**
		 * Fires after the default language has changed and its data has moved.
		 *
		 * @param string $to   Slug of the new default language.
		 * @param string $from Slug of the previous default language.
		 */
		do_action( 'wp_loc_default_language_changed', $to, $from );

		return [
			'from'  => $from,
			'to'    => $to,
			'moved' => $moved,
			'files' => $files,
		];
	}
}
