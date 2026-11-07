<?php

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Quick Edit + Bulk Edit support for reassigning a post's language.
 *
 * Touches only the icl_translations row — never wp_posts content.
 */
class WP_LOC_Admin_Language_Editor {

    const FIELD_NAME       = 'wp_loc_language_change';
    const NONCE_FIELD      = 'wp_loc_language_editor_nonce';
    const NONCE_ACTION     = 'wp_loc_language_editor';
    const TRANSIENT_PREFIX = 'wp_loc_lang_editor_result_';
    const COLUMN_KEY       = 'wp_loc_translations';

    public function __construct() {
        add_action( 'admin_init', [ $this, 'register_hooks' ] );
        add_action( 'admin_notices', [ $this, 'maybe_render_notice' ] );
        add_action( 'admin_footer-edit.php', [ $this, 'output_inline_script' ] );
    }

    public function register_hooks(): void {
        $post_types = $this->translatable_post_types();
        if ( empty( $post_types ) ) {
            return;
        }

        // bulk/quick edit boxes fire once per registered column on the screen;
        // we early-return inside the callback when the column name does not match.
        add_action( 'bulk_edit_custom_box', [ $this, 'render_bulk_edit_box' ], 10, 2 );
        add_action( 'quick_edit_custom_box', [ $this, 'render_quick_edit_box' ], 10, 2 );

        foreach ( $post_types as $post_type ) {
            add_action( "manage_{$post_type}_posts_custom_column", [ $this, 'render_inline_data' ], 20, 2 );
            add_action( "save_post_{$post_type}", [ $this, 'handle_save' ], 20, 2 );
        }
    }

    public function render_bulk_edit_box( string $column_name, string $post_type ): void {
        if ( $column_name !== self::COLUMN_KEY ) return;
        if ( ! $this->is_supported_post_type( $post_type ) ) return;

        $languages = WP_LOC_Languages::get_active_languages();
        if ( count( $languages ) < 2 ) return;

        $this->render_box( $languages, true );
    }

    public function render_quick_edit_box( string $column_name, string $post_type ): void {
        if ( $column_name !== self::COLUMN_KEY ) return;
        if ( ! $this->is_supported_post_type( $post_type ) ) return;

        $languages = WP_LOC_Languages::get_active_languages();
        if ( count( $languages ) < 2 ) return;

        $this->render_box( $languages, false );
    }

    private function render_box( array $languages, bool $is_bulk ): void {
        ?>
        <fieldset class="inline-edit-col-right inline-edit-wp-loc">
            <div class="inline-edit-col">
                <label class="inline-edit-language alignleft">
                    <span class="title"><?php esc_html_e( 'Language', 'wp-loc' ); ?></span>
                    <select name="<?php echo esc_attr( self::FIELD_NAME ); ?>">
                        <?php if ( $is_bulk ) : ?>
                            <option value=""><?php esc_html_e( '— No Change —', 'wp-loc' ); ?></option>
                        <?php endif; ?>
                        <?php foreach ( $languages as $slug => $data ) : ?>
                            <option value="<?php echo esc_attr( $slug ); ?>">
                                <?php echo esc_html( WP_LOC_Languages::get_display_name( $slug ) ); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </label>
            </div>
            <?php wp_nonce_field( self::NONCE_ACTION, self::NONCE_FIELD, false ); ?>
        </fieldset>
        <?php
    }

    public function render_inline_data( string $column, int $post_id ): void {
        if ( $column !== self::COLUMN_KEY ) return;

        $post_type = get_post_type( $post_id );
        if ( ! $post_type || ! $this->is_supported_post_type( $post_type ) ) return;

        $element_type = WP_LOC_DB::post_element_type( $post_type );
        $current_lang = WP_LOC::instance()->db->get_element_language( $post_id, $element_type );

        if ( ! $current_lang ) return;

        printf(
            '<span class="wp-loc-inline-lang" data-lang="%s" style="display:none;"></span>',
            esc_attr( $current_lang )
        );
    }

    public function handle_save( int $post_id, WP_Post $post ): void {
        if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) return;
        if ( wp_is_post_revision( $post_id ) ) return;
        if ( ! current_user_can( 'edit_post', $post_id ) ) return;

        // Our field is present only when posted from quick-edit or bulk-edit forms.
        if ( ! isset( $_REQUEST[ self::FIELD_NAME ] ) ) return;

        $target = sanitize_key( (string) wp_unslash( $_REQUEST[ self::FIELD_NAME ] ) );
        if ( $target === '' ) return; // "No Change" in bulk edit.

        $nonce = isset( $_REQUEST[ self::NONCE_FIELD ] )
            ? sanitize_text_field( wp_unslash( $_REQUEST[ self::NONCE_FIELD ] ) )
            : '';
        if ( ! $nonce || ! wp_verify_nonce( $nonce, self::NONCE_ACTION ) ) return;

        $languages = WP_LOC_Languages::get_active_languages();
        if ( ! isset( $languages[ $target ] ) ) return;

        $post_type = $post->post_type;
        if ( ! $this->is_supported_post_type( $post_type ) ) return;

        $db           = WP_LOC::instance()->db;
        $element_type = WP_LOC_DB::post_element_type( $post_type );
        $current_lang = $db->get_element_language( $post_id, $element_type );

        if ( $current_lang === $target ) {
            $this->record_result( 'unchanged', $post_id );
            return;
        }

        $trid = $db->get_trid( $post_id, $element_type );

        // Trid collision check: if this trid already has another post in target language, skip.
        if ( $trid ) {
            $translations = $db->get_element_translations( $trid, $element_type );
            if ( isset( $translations[ $target ] ) && (int) $translations[ $target ]->element_id !== $post_id ) {
                $this->record_result( 'skipped_collision', $post_id );
                return;
            }
        }

        $db->set_element_language( $post_id, $element_type, $target, $trid );
        $this->record_result( 'changed', $post_id );
    }

    public function maybe_render_notice(): void {
        $screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
        if ( ! $screen || $screen->base !== 'edit' ) return;

        $key    = $this->transient_key();
        $result = get_transient( $key );
        if ( ! $result || ! is_array( $result ) ) return;

        delete_transient( $key );

        $changed = (int) ( $result['changed'] ?? 0 );
        $skipped = isset( $result['skipped'] ) && is_array( $result['skipped'] ) ? $result['skipped'] : [];

        if ( $changed > 0 ) {
            printf(
                '<div class="notice notice-success is-dismissible"><p>%s</p></div>',
                esc_html( sprintf(
                    /* translators: %d: number of posts */
                    _n( 'Language updated for %d post.', 'Language updated for %d posts.', $changed, 'wp-loc' ),
                    $changed
                ) )
            );
        }

        if ( ! empty( $skipped ) ) {
            $ids = implode( ', ', array_map( 'intval', $skipped ) );
            printf(
                '<div class="notice notice-warning is-dismissible"><p>%s</p></div>',
                esc_html( sprintf(
                    /* translators: 1: number of skipped posts, 2: comma-separated list of IDs */
                    _n(
                        'Skipped %1$d post due to translation conflict (target language already taken in its translation group): %2$s',
                        'Skipped %1$d posts due to translation conflicts (target language already taken in their translation groups): %2$s',
                        count( $skipped ),
                        'wp-loc'
                    ),
                    count( $skipped ),
                    $ids
                ) )
            );
        }
    }

    public function output_inline_script(): void {
        $screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
        if ( ! $screen || $screen->base !== 'edit' ) return;
        if ( ! $this->is_supported_post_type( (string) $screen->post_type ) ) return;

        $field = esc_js( self::FIELD_NAME );
        ?>
        <script>
        ( function ( $ ) {
            if ( typeof inlineEditPost === 'undefined' || ! inlineEditPost.edit ) return;

            var origEdit = inlineEditPost.edit;
            inlineEditPost.edit = function ( id ) {
                origEdit.apply( this, arguments );

                var postId = 0;
                if ( typeof id === 'object' ) {
                    postId = parseInt( this.getId( id ), 10 );
                }
                if ( ! postId ) return;

                var lang = $( '#post-' + postId ).find( '.wp-loc-inline-lang' ).data( 'lang' );
                if ( ! lang ) return;

                $( 'tr#edit-' + postId + ' select[name="<?php echo $field; ?>"]' ).val( lang );
            };
        } )( jQuery );
        </script>
        <?php
    }

    private function translatable_post_types(): array {
        $types = (array) apply_filters( 'wp_loc_translatable_post_types', [ 'post', 'page' ] );
        $types = array_filter( array_map( static fn( $t ) => sanitize_key( (string) $t ), $types ) );
        $types = array_filter( $types, 'post_type_exists' );
        return array_values( array_unique( $types ) );
    }

    private function is_supported_post_type( string $post_type ): bool {
        if ( ! $post_type ) return false;
        return in_array( $post_type, $this->translatable_post_types(), true );
    }

    private function transient_key(): string {
        return self::TRANSIENT_PREFIX . get_current_user_id();
    }

    private function record_result( string $kind, int $post_id ): void {
        $key     = $this->transient_key();
        $current = get_transient( $key );
        if ( ! is_array( $current ) ) {
            $current = [ 'changed' => 0, 'unchanged' => 0, 'skipped' => [] ];
        }

        if ( $kind === 'changed' ) {
            $current['changed'] = ( (int) ( $current['changed'] ?? 0 ) ) + 1;
        } elseif ( $kind === 'unchanged' ) {
            $current['unchanged'] = ( (int) ( $current['unchanged'] ?? 0 ) ) + 1;
        } elseif ( $kind === 'skipped_collision' ) {
            $current['skipped'][] = $post_id;
        }

        set_transient( $key, $current, 60 );
    }
}
