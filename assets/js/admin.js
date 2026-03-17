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
                const name = $li.find('.wp-loc-lang-name-missing').text();
                $li.find('.wp-loc-lang-name-missing, .wp-loc-create-single-translation').remove();
                const statusClass = response.data.status === 'publish' ? 'wp-loc-status-published' : 'wp-loc-status-draft';
                const statusLabel = response.data.status === 'publish' ? '✓' : 'Draft';
                $li.find('.wp-loc-flag-small').after(
                    '<a href="' + response.data.edit_url + '">' + name + '</a>' +
                    '<span class="' + statusClass + '">' + statusLabel + '</span>'
                );
            } else {
                $btn.prop('disabled', false).text('+');
            }
        }).fail(function() {
            $btn.prop('disabled', false).text('+');
        });
    });

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
