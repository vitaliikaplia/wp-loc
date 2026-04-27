(function($) {
    'use strict';

    const config = window.wpLocDbOptimizationWizard || {};
    const i18n = config.i18n || {};
    const modal = document.querySelector('[data-wp-loc-db-wizard]');

    if (!modal) {
        return;
    }

    const showStep = function(stepName) {
        modal.querySelectorAll('.wp-loc-db-wizard__step').forEach(function(step) {
            step.classList.toggle('is-active', step.getAttribute('data-step') === stepName);
        });
    };

    const closeForThisPage = function() {
        modal.hidden = true;
        document.body.classList.remove('wp-loc-db-wizard-open');
    };

    const open = function() {
        modal.hidden = false;
        document.body.classList.add('wp-loc-db-wizard-open');
        showStep('intro');
    };

    const request = function(action, data) {
        return $.post(config.ajaxUrl, Object.assign({
            action: action,
            nonce: config.nonce
        }, data || {}));
    };

    const number = function(value) {
        return new Intl.NumberFormat().format(parseInt(value || 0, 10));
    };

    const renderMetric = function(label, value) {
        return '<div class="wp-loc-db-wizard__metric">' +
            '<strong>' + number(value) + '</strong>' +
            '<span>' + label + '</span>' +
        '</div>';
    };

    const renderSummary = function(scan) {
        const compatible = scan.compatible || {};
        const details = scan.details || {};
        const languages = scan.languages || {};
        const legacy = scan.legacy || {};
        const tableRows = Array.isArray(legacy.tables) ? legacy.tables : [];
        const removableTableRows = tableRows.reduce(function(total, item) {
            return total + parseInt(item.rows || 0, 10);
        }, 0);
        const removableMetaRows =
            parseInt(legacy.options || 0, 10) +
            parseInt(legacy.postmeta || 0, 10) +
            parseInt(legacy.usermeta || 0, 10) +
            parseInt(legacy.commentmeta || 0, 10);

        const languageList = Array.isArray(languages.items) && languages.items.length
            ? '<ul>' + languages.items.map(function(lang) {
                return '<li><strong>' + lang.code + '</strong> <span>' + (lang.locale || lang.code) + '</span></li>';
            }).join('') + '</ul>'
            : '<p class="wp-loc-db-wizard__muted">No language records were found for import.</p>';

        const tableList = tableRows.length
            ? '<ul>' + tableRows.map(function(table) {
                return '<li><span>' + table.label + '</span><strong>' + number(table.rows) + '</strong></li>';
            }).join('') + '</ul>'
            : '<p class="wp-loc-db-wizard__muted">' + (i18n.noCleanup || 'No removable legacy data was found.') + '</p>';
        const detailList = function(items, emptyText) {
            if (!Array.isArray(items) || !items.length) {
                return '<p class="wp-loc-db-wizard__muted">' + emptyText + '</p>';
            }

            return '<ul>' + items.map(function(item) {
                return '<li><span>' + item.label + '</span><strong>' + number(item.count) + '</strong></li>';
            }).join('') + '</ul>';
        };

        return '<div class="wp-loc-db-wizard__metrics">' +
                renderMetric('translation links kept', compatible.translation_rows) +
                renderMetric('post records recognized', compatible.posts) +
                renderMetric('term records recognized', compatible.terms) +
                renderMetric('menu records recognized', compatible.menus) +
                renderMetric('media records recognized', compatible.attachments) +
                renderMetric('languages detected', languages.count) +
                renderMetric('localized options detected', details.localized_options) +
                renderMetric('field preferences detected', details.field_preferences) +
            '</div>' +
            '<div class="wp-loc-db-wizard__columns">' +
                '<section>' +
                    '<h3>Kept by WP-LOC</h3>' +
                    '<p>Compatible translation links remain in place and continue powering posts, pages, media, terms, and menus.</p>' +
                '</section>' +
                '<section>' +
                    '<h3>Content types found</h3>' +
                    detailList(details.post_types, 'No translated post types were found.') +
                '</section>' +
                '<section>' +
                    '<h3>Taxonomies found</h3>' +
                    detailList(details.taxonomies, 'No translated taxonomies were found.') +
                '</section>' +
                '<section>' +
                    '<h3>Languages to adopt</h3>' +
                    languageList +
                '</section>' +
                '<section>' +
                    '<h3>Legacy data to remove</h3>' +
                    tableList +
                    '<p class="wp-loc-db-wizard__muted">' + number(legacy.options || 0) + ' option rows and ' + number(removableMetaRows - parseInt(legacy.options || 0, 10)) + ' meta rows are marked for cleanup.</p>' +
                '</section>' +
            '</div>' +
            '<input type="hidden" data-removable-total value="' + (removableTableRows + removableMetaRows) + '" />';
    };

    const renderResult = function(result) {
        return '<div class="wp-loc-db-wizard__metrics">' +
                renderMetric('languages imported', result.languages_imported) +
                renderMetric('legacy tables removed', result.tables_removed) +
                renderMetric('option rows removed', result.options_removed) +
                renderMetric('meta rows removed', result.meta_removed) +
                renderMetric('translation links kept', result.kept_rows) +
            '</div>' +
            '<p>WP-LOC is ready to continue with the optimized multilingual database.</p>';
    };

    const scan = function() {
        const summary = modal.querySelector('[data-summary]');
        const loading = modal.querySelector('[data-loading-scan]');
        const applyButton = modal.querySelector('[data-action="apply"]');

        showStep('scan');
        summary.hidden = true;
        summary.innerHTML = '';
        loading.hidden = false;
        loading.textContent = i18n.scanning || 'Scanning database...';
        applyButton.disabled = true;

        request('wp_loc_db_optimization_scan').done(function(response) {
            if (!response || !response.success) {
                loading.textContent = i18n.requestFailed || 'Request failed. Please try again.';
                return;
            }

            summary.innerHTML = renderSummary(response.data || {});
            summary.hidden = false;
            loading.hidden = true;
            applyButton.disabled = false;
        }).fail(function() {
            loading.textContent = i18n.requestFailed || 'Request failed. Please try again.';
        });
    };

    const apply = function() {
        const result = modal.querySelector('[data-result]');
        const progressText = modal.querySelector('[data-progress-text]');

        showStep('progress');
        progressText.textContent = i18n.optimizing || 'Optimizing database...';

        request('wp_loc_db_optimization_apply').done(function(response) {
            if (!response || !response.success) {
                progressText.textContent = i18n.requestFailed || 'Request failed. Please try again.';
                return;
            }

            result.innerHTML = renderResult(response.data || {});
            showStep('done');
        }).fail(function() {
            progressText.textContent = i18n.requestFailed || 'Request failed. Please try again.';
        });
    };

    const dismiss = function() {
        request('wp_loc_db_optimization_dismiss').always(function() {
            closeForThisPage();
        });
    };

    modal.addEventListener('click', function(event) {
        const close = event.target.closest('[data-wp-loc-db-wizard-close]');
        const action = event.target.closest('[data-action]');

        if (close) {
            event.preventDefault();
            closeForThisPage();
            return;
        }

        if (!action) {
            return;
        }

        event.preventDefault();

        switch (action.getAttribute('data-action')) {
            case 'start':
                scan();
                break;
            case 'dismiss':
                dismiss();
                break;
            case 'apply':
                apply();
                break;
            case 'finish':
                closeForThisPage();
                break;
        }
    });

    document.addEventListener('keydown', function(event) {
        if (event.key === 'Escape' && !modal.hidden) {
            closeForThisPage();
        }
    });

    window.setTimeout(open, 350);
})(jQuery);
