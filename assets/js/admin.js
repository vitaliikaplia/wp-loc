/**
 * WP-LOC Admin Scripts
 */

(function($) {
    'use strict';

    // Languages page — sortable table rows with auto-save
    if ($('.wp-loc-languages-table').length) {
        const $tbody = $('.wp-loc-languages-table tbody');
        $tbody.sortable({
            handle: '.lang-drag-handle',
            update: function() {
                const order = [];
                $tbody.find('tr').each(function() {
                    const locale = $(this).data('locale');
                    if (locale) order.push(locale);
                });
                $('#wp_loc_languages_order').val(order.join(','));

                $.post(wpLocAdmin.ajaxUrl, {
                    action: 'wp_loc_save_order',
                    nonce: wpLocAdmin.nonce,
                    order: order
                });
            }
        });
    }

    // Metabox — create single translation via AJAX
    $(document).on('click', '.wp-loc-create-single-translation', function() {
        const $btn = $(this);
        const $li = $btn.closest('li');
        const postId = $btn.data('post-id');
        const lang = $btn.data('lang');

        $btn.prop('disabled', true).text('…');

        $.post(wpLocAdmin.ajaxUrl, {
            action: 'wp_loc_create_translation',
            nonce: wpLocAdmin.nonce,
            post_id: postId,
            lang: lang
        }, function(response) {
            if (response.success) {
                const $metabox = $('#wp_loc_translations .inside');

                $.post(wpLocAdmin.ajaxUrl, {
                    action: 'wp_loc_refresh_metabox',
                    nonce: wpLocAdmin.nonce,
                    post_id: postId
                }, function(refreshResponse) {
                    if (refreshResponse.success) {
                        $metabox.html(refreshResponse.data.html);
                    }
                });
            } else {
                $btn.prop('disabled', false).text('+');
            }
        }).fail(function() {
            $btn.prop('disabled', false).text('+');
        });
    });

    // Gutenberg — refresh translation metabox after first save (auto-draft → saved)
    if (typeof wp !== 'undefined' && wp.data && wp.data.subscribe) {
        let wasSaving = false;

        wp.data.subscribe(function() {
            const editor = wp.data.select('core/editor');
            if (!editor) return;

            const isSaving = editor.isSavingPost() && !editor.isAutosavingPost();

            if (wasSaving && !isSaving) {
                const postId = editor.getCurrentPostId();
                const $metabox = $('#wp_loc_translations .inside');

                if ($metabox.find('.wp-loc-metabox-message').length || $metabox.find('.wp-loc-create-translations').length) {
                    $.post(wpLocAdmin.ajaxUrl, {
                        action: 'wp_loc_refresh_metabox',
                        nonce: wpLocAdmin.nonce,
                        post_id: postId
                    }, function(response) {
                        if (response.success) {
                            $metabox.html(response.data.html);
                        }
                    });
                }
            }

            wasSaving = isSaving;
        });
    }

    // Gutenberg — show current admin language flag in the header toolbar
    if (wpLocAdmin.adminLangFlag && wpLocAdmin.adminLangName) {
        const renderGutenbergLanguageBadge = function() {
            const toolbar = document.querySelector('.edit-post-header-toolbar');
            if (!toolbar || toolbar.querySelector('.wp-loc-gutenberg-lang-badge')) {
                return;
            }

            const badge = document.createElement('span');
            badge.className = 'wp-loc-gutenberg-lang-badge';
            badge.title = wpLocAdmin.adminLangName;
            badge.setAttribute('aria-label', wpLocAdmin.adminLangName);
            badge.innerHTML = '<img src="' + wpLocAdmin.adminLangFlag + '" alt="' + wpLocAdmin.adminLangName + '" />';

            const toolsGroup = toolbar.querySelector('.editor-document-tools');
            if (toolsGroup && toolsGroup.nextSibling) {
                toolbar.insertBefore(badge, toolsGroup.nextSibling);
            } else {
                toolbar.appendChild(badge);
            }
        };

        renderGutenbergLanguageBadge();

        const observer = new MutationObserver(function() {
            renderGutenbergLanguageBadge();
        });

        observer.observe(document.body, {
            childList: true,
            subtree: true
        });
    }

    // Reading Settings — disable page selects for non-default languages
    if (window.wpLocDisablePageSelects) {
        const selects = document.querySelectorAll('#page_on_front, #page_for_posts');
        selects.forEach(function(el) {
            el.disabled = true;
            el.style.opacity = '0.6';
            el.title = window.wpLocDisablePageSelects;
        });
    }

})(jQuery);
