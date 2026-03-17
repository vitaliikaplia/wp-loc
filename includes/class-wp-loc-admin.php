<?php

if ( ! defined( 'ABSPATH' ) ) exit;

class WP_LOC_Admin {

    public function __construct() {
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );
        add_action( 'admin_bar_menu', [ $this, 'admin_bar_lang_switcher' ], 100 );
        add_action( 'admin_init', [ $this, 'handle_lang_switch' ] );
        add_action( 'admin_init', [ $this, 'sync_cookie_with_post' ] );
        add_action( 'pre_get_posts', [ $this, 'filter_posts_by_language' ] );
        add_action( 'add_meta_boxes', [ $this, 'add_translation_meta_box' ] );
        add_action( 'admin_init', [ $this, 'handle_create_translations' ] );
        add_action( 'wp_ajax_wp_loc_create_translation', [ $this, 'ajax_create_translation' ] );
        add_action( 'wp_ajax_wp_loc_refresh_metabox', [ $this, 'ajax_refresh_metabox' ] );
        add_action( 'admin_init', [ $this, 'restrict_non_default_language_creation' ] );
        add_filter( 'get_sample_permalink_html', [ $this, 'add_lang_prefix_to_permalink' ], 10, 4 );
        add_filter( 'get_pages', [ $this, 'filter_pages_by_language' ] );
        add_filter( 'wp_count_posts', [ $this, 'filter_count_posts' ], 10, 3 );
        add_action( 'current_screen', [ $this, 'filter_views_mine_count' ] );
        add_action( 'current_screen', [ $this, 'register_language_columns' ] );
    }

    /**
     * Enqueue admin CSS and JS
     */
    public function enqueue_assets(): void {
        $screen = get_current_screen();
        $deps   = [ 'jquery' ];
        $admin_lang = self::get_admin_lang();
        $admin_locale = self::get_admin_locale();
        $gutenberg_languages = [];
        $editing_post_id = isset( $_GET['post'] ) ? (int) $_GET['post'] : 0;

        // Add wp-data dependency on post edit screens for Gutenberg metabox refresh
        if ( $screen && $screen->is_block_editor ) {
            $deps[] = 'wp-data';
            if ( $editing_post_id && get_post( $editing_post_id ) ) {
                $base_url = remove_query_arg( [ 'paged', 'wp_loc_lang' ] );

                foreach ( WP_LOC_Languages::get_active_languages() as $slug => $data ) {
                    $locale = $data['locale'] ?? $slug;

                    $gutenberg_languages[] = [
                        'code'   => $slug,
                        'name'   => WP_LOC_Languages::get_display_name( $slug ),
                        'flag'   => WP_LOC_Languages::get_flag_url( $locale ),
                        'url'    => $slug === $admin_lang ? '' : add_query_arg( 'wp_loc_lang', $slug, $base_url ),
                        'active' => $slug === $admin_lang,
                    ];
                }
            }
        }

        wp_enqueue_style( 'wp-loc-admin', WP_LOC_URL . 'assets/css/admin.min.css', [], WP_LOC_VERSION );
        wp_enqueue_script( 'wp-loc-admin', WP_LOC_URL . 'assets/js/admin.min.js', $deps, WP_LOC_VERSION, true );
        wp_localize_script( 'wp-loc-admin', 'wpLocAdmin', [
            'ajaxUrl'       => admin_url( 'admin-ajax.php' ),
            'nonce'         => wp_create_nonce( 'wp_loc_ajax' ),
            'adminLang'     => $admin_lang,
            'adminLangName' => WP_LOC_Languages::get_display_name( $admin_lang ),
            'adminLangFlag' => WP_LOC_Languages::get_flag_url( $admin_locale ),
            'gutenbergLanguages' => $gutenberg_languages,
        ] );
    }

    /**
     * Get admin-selected language slug (from cookie)
     */
    public static function get_admin_lang(): string {
        $active = WP_LOC_Languages::get_active_languages();
        $cookie_locale = $_COOKIE['admin_lang'] ?? null;

        if ( $cookie_locale ) {
            foreach ( $active as $slug => $data ) {
                if ( ( $data['locale'] ?? '' ) === $cookie_locale ) {
                    return $slug;
                }
            }
        }

        return WP_LOC_Languages::get_default_language();
    }

    /**
     * Get admin-selected locale (from cookie)
     */
    public static function get_admin_locale(): string {
        return $_COOKIE['admin_lang'] ?? WP_LOC_Languages::get_language_locale( WP_LOC_Languages::get_default_language() );
    }

    /**
     * Admin bar language switcher
     */
    public function admin_bar_lang_switcher( $wp_admin_bar ): void {
        if ( ! is_admin() ) return;

        $active = WP_LOC_Languages::get_active_languages();
        if ( count( $active ) < 2 ) return;

        $selected_slug = self::get_admin_lang();
        $selected_locale = $active[ $selected_slug ]['locale'] ?? $selected_slug;
        $selected_flag = WP_LOC_Languages::get_flag_url( $selected_locale );

        $wp_admin_bar->add_node( [
            'id'    => 'wp_loc_lang_switcher',
            'title' => '<img src="' . esc_url( $selected_flag ) . '" />' . esc_html( WP_LOC_Languages::get_display_name( $selected_slug ) ),
            'meta'  => [ 'class' => 'wp-loc-lang-switcher' ],
        ] );

        foreach ( $active as $slug => $data ) {
            if ( $slug === $selected_slug ) continue;

            $locale = $data['locale'] ?? $slug;
            $flag = WP_LOC_Languages::get_flag_url( $locale );
            $url = add_query_arg( 'wp_loc_lang', $slug, remove_query_arg( 'paged' ) );

            $wp_admin_bar->add_node( [
                'id'     => 'wp_loc_lang_' . $slug,
                'title'  => '<img src="' . esc_url( $flag ) . '" />' . esc_html( WP_LOC_Languages::get_display_name( $slug ) ),
                'parent' => 'wp_loc_lang_switcher',
                'href'   => esc_url( $url ),
            ] );
        }
    }

    /**
     * Handle admin language switch
     */
    public function handle_lang_switch(): void {
        if ( ! isset( $_GET['wp_loc_lang'] ) ) return;

        $slug = sanitize_text_field( $_GET['wp_loc_lang'] );
        $active = WP_LOC_Languages::get_active_languages();

        if ( ! isset( $active[ $slug ] ) ) return;

        $locale = $active[ $slug ]['locale'] ?? $slug;
        setcookie( 'admin_lang', $locale, time() + DAY_IN_SECONDS * 30, ADMIN_COOKIE_PATH, COOKIE_DOMAIN );
        $_COOKIE['admin_lang'] = $locale;

        // If editing a post, redirect to its translation
        if ( isset( $_GET['post'], $_GET['action'] ) && $_GET['action'] === 'edit' ) {
            $post_id = (int) $_GET['post'];
            $post_type = get_post_type( $post_id );
            $element_type = WP_LOC_DB::post_element_type( $post_type );

            $translated_id = WP_LOC::instance()->db->get_element_translation( $post_id, $element_type, $slug );

            if ( $translated_id ) {
                wp_redirect( admin_url( 'post.php?post=' . $translated_id . '&action=edit' ) );
                exit;
            }
        }

        wp_redirect( remove_query_arg( 'wp_loc_lang' ) );
        exit;
    }

    /**
     * Sync admin_lang cookie when editing a post
     */
    public function sync_cookie_with_post(): void {
        global $pagenow;

        if ( $pagenow !== 'post.php' || ! isset( $_GET['post'] ) ) return;

        $post_id = (int) $_GET['post'];
        $post = get_post( $post_id );
        if ( ! $post ) return;

        $element_type = WP_LOC_DB::post_element_type( $post->post_type );
        $lang = WP_LOC::instance()->db->get_element_language( $post_id, $element_type );
        if ( ! $lang ) return;

        $locale = WP_LOC_Languages::get_language_locale( $lang );

        if ( ! isset( $_COOKIE['admin_lang'] ) || $_COOKIE['admin_lang'] !== $locale ) {
            setcookie( 'admin_lang', $locale, time() + DAY_IN_SECONDS * 30, ADMIN_COOKIE_PATH, COOKIE_DOMAIN );
            $_COOKIE['admin_lang'] = $locale;
        }
    }

    /**
     * Filter post list by current admin language
     */
    public function filter_posts_by_language( \WP_Query $query ): void {
        if ( ! is_admin() || ! $query->is_main_query() ) return;

        $screen = get_current_screen();
        if ( ! $screen || ! in_array( $screen->base, [ 'edit' ], true ) ) return;

        $post_type = $query->get( 'post_type' ) ?: 'post';
        if ( is_array( $post_type ) ) $post_type = $post_type[0];

        if ( ! WP_LOC_Admin_Settings::is_translatable( $post_type ) ) return;

        $lang = self::get_admin_lang();
        $element_type = WP_LOC_DB::post_element_type( $post_type );
        $table = WP_LOC::instance()->db->get_table();

        add_filter( 'posts_join', function ( $join ) use ( $table, $element_type ) {
            global $wpdb;
            $join .= " LEFT JOIN {$table} AS wp_loc_t ON {$wpdb->posts}.ID = wp_loc_t.element_id AND wp_loc_t.element_type = '" . esc_sql( $element_type ) . "'";
            return $join;
        } );

        add_filter( 'posts_where', function ( $where ) use ( $lang ) {
            global $wpdb;
            $where .= $wpdb->prepare(
                " AND (wp_loc_t.language_code = %s OR wp_loc_t.language_code IS NULL)",
                $lang
            );
            return $where;
        } );
    }

    /**
     * Add translation meta box to post editor
     */
    public function add_translation_meta_box(): void {
        $post_types = apply_filters( 'wp_loc_translatable_post_types', [ 'post', 'page' ] );

        foreach ( $post_types as $post_type ) {
            add_meta_box(
                'wp_loc_translations',
                __( 'Translations', 'wp-loc' ),
                [ $this, 'render_translation_meta_box' ],
                $post_type,
                'side',
                'high'
            );
        }
    }

    /**
     * Render translation meta box
     */
    public function render_translation_meta_box( \WP_Post $post ): void {
        // Don't show for unsaved posts
        if ( $post->post_status === 'auto-draft' ) {
            echo '<p class="wp-loc-metabox-message">' . esc_html__( 'Save the post first to manage translations.', 'wp-loc' ) . '</p>';
            return;
        }

        $db = WP_LOC::instance()->db;
        $element_type = WP_LOC_DB::post_element_type( $post->post_type );
        $trid = $db->get_trid( $post->ID, $element_type );

        // No trid yet — post not registered, translations will be created on save
        if ( ! $trid ) {
            $url = add_query_arg( [
                'wp_loc_create_translations' => 1,
                '_wpnonce' => wp_create_nonce( 'wp_loc_create_translations_' . $post->ID ),
                'post'     => $post->ID,
            ] );
            echo '<a href="' . esc_url( $url ) . '" class="button button-primary wp-loc-create-translations">' . esc_html__( 'Create translations', 'wp-loc' ) . '</a>';
            return;
        }

        $translations = $db->get_element_translations( $trid, $element_type );

        // If some languages are missing translations, show create button
        $active = WP_LOC_Languages::get_active_languages();
        $missing = false;
        foreach ( array_keys( $active ) as $slug ) {
            if ( ! isset( $translations[ $slug ] ) ) {
                $missing = true;
                break;
            }
        }
        if ( $missing ) {
            $url = add_query_arg( [
                'wp_loc_create_translations' => 1,
                '_wpnonce' => wp_create_nonce( 'wp_loc_create_translations_' . $post->ID ),
                'post'     => $post->ID,
            ] );
            echo '<a href="' . esc_url( $url ) . '" class="button wp-loc-create-translations wp-loc-create-translations-gap">' . esc_html__( 'Create translations', 'wp-loc' ) . '</a>';
        }
        $current_lang = $db->get_element_language( $post->ID, $element_type );

        echo '<ul class="wp-loc-translations-list">';
        foreach ( $active as $slug => $data ) {
            $locale = $data['locale'] ?? $slug;
            $flag = WP_LOC_Languages::get_flag_url( $locale );
            $is_active = ( $slug === $current_lang );
            $has_translation = isset( $translations[ $slug ] );
            $translated_post = $has_translation ? get_post( $translations[ $slug ]->element_id ) : null;
            $edit_link = $has_translation ? get_edit_post_link( $translations[ $slug ]->element_id ) : '';
            $status = $translated_post ? $translated_post->post_status : '';

            $li_class = $is_active ? ' class="wp-loc-lang-active"' : '';

            echo '<li' . $li_class . '>';

            if ( $has_translation ) {
                $is_published = $translated_post && $translated_post->post_status === 'publish';
                $icon_class = $is_published ? 'wp-loc-t-published' : 'wp-loc-t-draft';
                $flag_img = '<img class="wp-loc-flag" src="' . esc_url( $flag ) . '" alt="" />';
                $pencil = '<span class="wp-loc-pencil">✎</span>';

                echo '<a href="' . esc_url( $edit_link ) . '" class="wp-loc-metabox-link" title="' . esc_attr__( 'Edit translation', 'wp-loc' ) . '">';
                echo '<span class="wp-loc-t ' . esc_attr( $icon_class ) . '" aria-hidden="true">' . $flag_img . $pencil . '</span>';
                echo '<span class="wp-loc-metabox-link-text">' . esc_html( WP_LOC_Languages::get_display_name( $slug ) ) . '</span>';
                echo '</a>';
            } else {
                echo '<img class="wp-loc-flag-small" src="' . esc_url( $flag ) . '" alt="" />';
                echo '<span class="wp-loc-lang-name-missing">' . esc_html( WP_LOC_Languages::get_display_name( $slug ) ) . '</span>';
                echo '<button type="button" class="button button-small wp-loc-create-single-translation" data-post-id="' . esc_attr( $post->ID ) . '" data-lang="' . esc_attr( $slug ) . '">';
                echo '+';
                echo '</button>';
            }

            if ( $is_active ) {
                echo '<span class="wp-loc-status-active">&#9679;</span>';
            } elseif ( $status ) {
                $status_label = $status === 'publish' ? '&#10003;' : ucfirst( $status );
                $status_class = $status === 'publish' ? 'wp-loc-status-published' : 'wp-loc-status-draft';
                echo '<span class="' . $status_class . '">' . $status_label . '</span>';
            }

            echo '</li>';
        }
        echo '</ul>';
    }

    /**
     * Handle create translations action
     */
    public function handle_create_translations(): void {
        if ( ! isset( $_GET['wp_loc_create_translations'], $_GET['_wpnonce'], $_GET['post'] ) ) return;

        $post_id = (int) $_GET['post'];
        if ( ! current_user_can( 'edit_post', $post_id ) ) return;
        if ( ! wp_verify_nonce( $_GET['_wpnonce'], 'wp_loc_create_translations_' . $post_id ) ) return;

        WP_LOC::instance()->content->create_translations( $post_id );

        wp_redirect( admin_url( 'post.php?post=' . $post_id . '&action=edit' ) );
        exit;
    }

    /**
     * AJAX: create a single translation for a specific language
     */
    public function ajax_create_translation(): void {
        check_ajax_referer( 'wp_loc_ajax', 'nonce' );

        $post_id   = (int) ( $_POST['post_id'] ?? 0 );
        $lang_slug = sanitize_text_field( $_POST['lang'] ?? '' );

        if ( ! $post_id || ! $lang_slug ) {
            wp_send_json_error( [ 'message' => 'Missing parameters' ] );
        }

        if ( ! current_user_can( 'edit_post', $post_id ) ) {
            wp_send_json_error( [ 'message' => 'Permission denied' ] );
        }

        $post = get_post( $post_id );
        if ( ! $post ) {
            wp_send_json_error( [ 'message' => 'Post not found' ] );
        }

        $db           = WP_LOC::instance()->db;
        $element_type = WP_LOC_DB::post_element_type( $post->post_type );
        $current_lang = $db->get_element_language( $post_id, $element_type );
        $trid         = $db->get_trid( $post_id, $element_type );

        if ( ! $trid ) {
            $trid = $db->set_element_language( $post_id, $element_type, $current_lang ?: wp_loc_get_admin_lang() );
        }

        // Check if translation already exists
        $existing = $db->get_element_translations( $trid, $element_type );
        if ( isset( $existing[ $lang_slug ] ) ) {
            $existing_id = (int) $existing[ $lang_slug ]->element_id;
            wp_send_json_success( [
                'edit_url' => get_edit_post_link( $existing_id, 'raw' ),
                'status'   => get_post_status( $existing_id ),
            ] );
        }

        // Create the translation draft
        $meta         = get_post_meta( $post_id );
        $thumbnail_id = get_post_thumbnail_id( $post_id );

        $duplicate_id = wp_insert_post( [
            'post_title'    => $post->post_title,
            'post_content'  => $post->post_content,
            'post_excerpt'  => $post->post_excerpt,
            'post_status'   => $post->post_status,
            'post_type'     => $post->post_type,
            'post_parent'   => $post->post_parent,
            'menu_order'    => $post->menu_order,
            'post_password' => $post->post_password,
            'post_author'   => $post->post_author,
            'post_date'     => $post->post_date,
            'post_date_gmt' => $post->post_date_gmt,
        ] );

        if ( ! $duplicate_id || is_wp_error( $duplicate_id ) ) {
            wp_send_json_error( [ 'message' => 'Failed to create post' ] );
        }

        $source_lang = $current_lang ?: wp_loc_get_admin_lang();
        $db->set_element_language( $duplicate_id, $element_type, $lang_slug, $trid, $source_lang );

        // Fix slug — wp_insert_post may have added "-2" because icl_translations
        // registration happens after insert; now that language is set, re-apply original slug
        wp_update_post( [
            'ID'        => $duplicate_id,
            'post_name' => $post->post_name,
        ] );

        // Copy meta
        foreach ( $meta as $key => $values ) {
            if ( str_starts_with( $key, '_wp_loc_' ) ) continue;
            if ( $key === '_edit_lock' || $key === '_edit_last' ) continue;

            foreach ( $values as $value ) {
                add_post_meta( $duplicate_id, $key, maybe_unserialize( $value ) );
            }
        }

        if ( $thumbnail_id ) {
            $attachment_element_type = WP_LOC_DB::post_element_type( 'attachment' );
            $translated_thumb = $db->get_element_translation( (int) $thumbnail_id, $attachment_element_type, $lang_slug );
            set_post_thumbnail( $duplicate_id, $translated_thumb ?: $thumbnail_id );
        }

        wp_send_json_success( [
            'edit_url' => get_edit_post_link( $duplicate_id, 'raw' ),
            'status'   => get_post_status( $duplicate_id ),
        ] );
    }

    /**
     * AJAX: refresh translation metabox HTML
     */
    public function ajax_refresh_metabox(): void {
        check_ajax_referer( 'wp_loc_ajax', 'nonce' );

        $post_id = (int) ( $_POST['post_id'] ?? 0 );
        if ( ! $post_id || ! current_user_can( 'edit_post', $post_id ) ) {
            wp_send_json_error();
        }

        $post = get_post( $post_id );
        if ( ! $post ) {
            wp_send_json_error();
        }

        ob_start();
        $this->render_translation_meta_box( $post );
        wp_send_json_success( [ 'html' => ob_get_clean() ] );
    }

    /**
     * Block creating new content when admin language is not default
     */
    public function restrict_non_default_language_creation(): void {
        $admin_lang = self::get_admin_lang();
        $default_lang = WP_LOC_Languages::get_default_language();

        if ( $admin_lang === $default_lang ) return;

        global $pagenow;

        // Block post-new.php for translatable post types only
        if ( $pagenow === 'post-new.php' ) {
            $new_post_type = $_GET['post_type'] ?? 'post';
            if ( ! WP_LOC_Admin_Settings::is_translatable( $new_post_type ) ) return;
            wp_die(
                __( 'Creating new content is only available in the primary language of the site. Switch to the default language first.', 'wp-loc' ),
                __( 'Action not allowed', 'wp-loc' ),
                [ 'back_link' => true ]
            );
        }

        // Block creating new terms (but allow if it's a translation via trid)
        add_filter( 'pre_insert_term', function ( $term ) {
            if ( isset( $_REQUEST['trid'] ) || isset( $_REQUEST['wp_loc_source_term'] ) ) {
                return $term;
            }
            wp_die(
                __( 'Creating new content is only available in the primary language of the site. Switch to the default language first.', 'wp-loc' ),
                __( 'Action not allowed', 'wp-loc' ),
                [ 'back_link' => true ]
            );
        } );

        // Block creating new menus
        if ( $pagenow === 'nav-menus.php' && isset( $_REQUEST['action'] ) && $_REQUEST['action'] === 'create-nav-menu' ) {
            wp_die(
                __( 'Creating new content is only available in the primary language of the site. Switch to the default language first.', 'wp-loc' ),
                __( 'Action not allowed', 'wp-loc' ),
                [ 'back_link' => true ]
            );
        }
    }

    /**
     * Filter post counts to show only posts in the current admin language
     */
    public function filter_count_posts( $counts, $type, $perm ): object {
        if ( ! is_admin() ) return $counts;
        if ( ! WP_LOC_Admin_Settings::is_translatable( $type ) ) return $counts;

        $lang = self::get_admin_lang();
        $element_type = WP_LOC_DB::post_element_type( $type );
        $table = WP_LOC::instance()->db->get_table();

        global $wpdb;

        $results = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT p.post_status, COUNT(*) AS num_posts
                 FROM {$wpdb->posts} p
                 LEFT JOIN {$table} t ON p.ID = t.element_id AND t.element_type = %s
                 WHERE p.post_type = %s AND (t.language_code = %s OR t.language_code IS NULL)
                 GROUP BY p.post_status",
                $element_type,
                $type,
                $lang
            )
        );

        // Reset all counts to 0
        foreach ( get_post_stati() as $state ) {
            $counts->$state = 0;
        }

        // Fill in actual counts
        foreach ( $results as $row ) {
            $counts->{$row->post_status} = (int) $row->num_posts;
        }

        return $counts;
    }

    /**
     * Fix "Mine" count in post list views to respect language filter
     */
    public function filter_views_mine_count( $screen ): void {
        if ( ! $screen || $screen->base !== 'edit' ) return;

        $post_type = $screen->post_type ?: 'post';

        if ( ! WP_LOC_Admin_Settings::is_translatable( $post_type ) ) return;

        add_filter( "views_edit-{$post_type}", function ( $views ) use ( $post_type ) {
            if ( ! isset( $views['mine'] ) ) return $views;

            $lang = self::get_admin_lang();
            $element_type = WP_LOC_DB::post_element_type( $post_type );
            $table = WP_LOC::instance()->db->get_table();

            global $wpdb;

            $count = (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT COUNT(*)
                 FROM {$wpdb->posts} p
                 INNER JOIN {$table} t ON p.ID = t.element_id AND t.element_type = %s AND t.language_code = %s
                 WHERE p.post_type = %s
                   AND p.post_author = %d
                   AND p.post_status IN ('publish', 'draft', 'pending', 'private', 'future')",
                $element_type,
                $lang,
                $post_type,
                get_current_user_id()
            ) );

            $views['mine'] = preg_replace(
                '/\(\d+\)/',
                '(' . $count . ')',
                $views['mine']
            );

            return $views;
        } );
    }

    /**
     * Filter get_pages() to show only pages in the current admin language
     */
    public function filter_pages_by_language( array $pages ): array {
        if ( ! is_admin() ) return $pages;
        if ( ! WP_LOC_Admin_Settings::is_translatable( 'page' ) ) return $pages;

        $lang = self::get_admin_lang();
        $db = WP_LOC::instance()->db;

        return array_filter( $pages, function ( $page ) use ( $db, $lang ) {
            $element_type = WP_LOC_DB::post_element_type( $page->post_type );
            $page_lang = $db->get_element_language( $page->ID, $element_type );

            // Show pages without language assignment (not yet registered)
            if ( ! $page_lang ) return true;

            return $page_lang === $lang;
        } );
    }

    /**
     * Register language columns for translatable post types
     */
    public function register_language_columns( $screen ): void {
        if ( ! $screen || $screen->base !== 'edit' ) return;

        $post_type = $screen->post_type ?: 'post';
        if ( ! WP_LOC_Admin_Settings::is_translatable( $post_type ) ) return;

        $active = WP_LOC_Languages::get_active_languages();
        $admin_lang = self::get_admin_lang();
        $other_langs = array_diff_key( $active, [ $admin_lang => true ] );

        if ( empty( $other_langs ) ) return;

        // Single "Translations" column after title
        add_filter( "manage_{$post_type}_posts_columns", function ( $columns ) {
            $new_columns = [];
            foreach ( $columns as $key => $label ) {
                $new_columns[ $key ] = $label;
                if ( $key === 'title' ) {
                    $new_columns['wp_loc_translations'] = __( 'Translations', 'wp-loc' );
                }
            }
            return $new_columns;
        } );

        // Render: flag for each other language, pencil on hover
        add_action( "manage_{$post_type}_posts_custom_column", function ( $column, $post_id ) use ( $other_langs, $post_type ) {
            if ( $column !== 'wp_loc_translations' ) return;

            $db = WP_LOC::instance()->db;
            $element_type = WP_LOC_DB::post_element_type( $post_type );

            $items = [];
            foreach ( $other_langs as $slug => $data ) {
                $locale = $data['locale'] ?? $slug;
                $flag = WP_LOC_Languages::get_flag_url( $locale );
                $name = esc_attr( WP_LOC_Languages::get_display_name( $slug ) );
                $flag_img = '<img class="wp-loc-flag" src="' . esc_url( $flag ) . '" title="' . $name . '" />';
                $pencil = '<span class="wp-loc-pencil">✎</span>';

                $translated_id = $db->get_element_translation( $post_id, $element_type, $slug );

                if ( $translated_id ) {
                    $translated_post = get_post( $translated_id );
                    $edit_link = get_edit_post_link( $translated_id );
                    $is_published = $translated_post && $translated_post->post_status === 'publish';
                    $cls = $is_published ? 'wp-loc-t-published' : 'wp-loc-t-draft';
                    $items[] = '<a href="' . esc_url( $edit_link ) . '" class="wp-loc-t ' . $cls . '" title="' . esc_attr__( 'Edit translation', 'wp-loc' ) . '">' . $flag_img . $pencil . '</a>';
                } else {
                    $items[] = '<span class="wp-loc-t wp-loc-t-missing">' . $flag_img . '</span>';
                }
            }

            echo implode( '', $items );
        }, 10, 2 );

        // Dynamic column width
        $width = count( $other_langs ) * 34 + 10;
        add_action( 'admin_head', function () use ( $width ) {
            echo '<style>.column-wp_loc_translations { width: ' . (int) $width . 'px; }</style>';
        } );
    }

    /**
     * Add language prefix to permalink in post editor
     */
    public function add_lang_prefix_to_permalink( $html, $post_id, $new_title, $new_slug ) {
        $post_type = get_post_type( $post_id );
        if ( ! WP_LOC_Admin_Settings::is_translatable( $post_type ) ) return $html;

        $element_type = WP_LOC_DB::post_element_type( $post_type );
        $lang = WP_LOC::instance()->db->get_element_language( $post_id, $element_type );

        if ( ! $lang ) return $html;

        $default = WP_LOC_Languages::get_default_language();
        if ( $lang === $default ) return $html;

        return str_replace( home_url(), home_url( "/{$lang}" ), $html );
    }
}

/**
 * Global helper: get admin-selected language slug
 */
function wp_loc_get_admin_lang(): string {
    return WP_LOC_Admin::get_admin_lang();
}
