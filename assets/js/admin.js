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

    // Term edit screen — create single translation via AJAX
    $(document).on('click', '.wp-loc-create-single-term-translation', function() {
        const $btn = $(this);
        const termId = $btn.data('term-id');
        const taxonomy = $btn.data('taxonomy');
        const lang = $btn.data('lang');

        $btn.prop('disabled', true).text('…');

        $.post(wpLocAdmin.ajaxUrl, {
            action: 'wp_loc_create_term_translation',
            nonce: wpLocAdmin.nonce,
            term_id: termId,
            taxonomy: taxonomy,
            lang: lang
        }, function(response) {
            if (response.success) {
                const $container = $('#wp-loc-term-translations');

                $.post(wpLocAdmin.ajaxUrl, {
                    action: 'wp_loc_refresh_term_translations',
                    nonce: wpLocAdmin.nonce,
                    term_id: termId,
                    taxonomy: taxonomy
                }, function(refreshResponse) {
                    if (refreshResponse.success) {
                        $container.html(refreshResponse.data.html);
                    }
                });
            } else {
                $btn.prop('disabled', false).text('+');
                if (response.data && response.data.message) {
                    window.alert(response.data.message);
                }
            }
        }).fail(function() {
            $btn.prop('disabled', false).text('+');
            window.alert('Failed to create term translation.');
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

            const switcher = document.createElement('div');
            switcher.className = 'wp-loc-gutenberg-lang-badge';
            const hasDropdown = Array.isArray(wpLocAdmin.gutenbergLanguages) && wpLocAdmin.gutenbergLanguages.length > 1;

            if (hasDropdown) {
                switcher.setAttribute('tabindex', '0');
            } else {
                switcher.classList.add('is-static');
            }

            let menuHtml = '';
            if (hasDropdown) {
                menuHtml += '<div class="wp-loc-gutenberg-lang-dropdown">';

                wpLocAdmin.gutenbergLanguages.forEach(function(lang) {
                    const itemClass = lang.active ? 'wp-loc-gutenberg-lang-item is-active' : 'wp-loc-gutenberg-lang-item';
                    const flag = '<img src="' + lang.flag + '" alt="' + lang.name + '" />';
                    const label = '<span>' + lang.name + '</span>';

                    if (lang.active || !lang.url) {
                        menuHtml += '<span class="' + itemClass + '">' + flag + label + '</span>';
                    } else {
                        menuHtml += '<a href="' + lang.url + '" class="' + itemClass + '">' + flag + label + '</a>';
                    }
                });

                menuHtml += '</div>';
            }

            switcher.innerHTML =
                '<span class="wp-loc-gutenberg-lang-current" aria-label="' + wpLocAdmin.adminLangName + '" title="' + wpLocAdmin.adminLangName + '">' +
                    '<img src="' + wpLocAdmin.adminLangFlag + '" alt="' + wpLocAdmin.adminLangName + '" />' +
                    '<span class="wp-loc-gutenberg-lang-label">' + wpLocAdmin.adminLangName + '</span>' +
                '</span>' +
                menuHtml;

            const toolsGroup = toolbar.querySelector('.editor-document-tools');
            if (toolsGroup && toolsGroup.nextSibling) {
                toolbar.insertBefore(switcher, toolsGroup.nextSibling);
            } else {
                toolbar.appendChild(switcher);
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
