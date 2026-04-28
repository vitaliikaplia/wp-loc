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

    const text = function(key, fallback) {
        return i18n[key] || fallback;
    };

    const escapeHtml = function(value) {
        return String(value || '').replace(/[&<>"']/g, function(char) {
            return {
                '&': '&amp;',
                '<': '&lt;',
                '>': '&gt;',
                '"': '&quot;',
                "'": '&#039;'
            }[char];
        });
    };

    const confidenceLabel = function(confidence) {
        const labels = {
            exact: text('matchExact', 'Exact match'),
            normalized: text('matchNormalized', 'Slug normalized'),
            locale: text('matchLocale', 'Locale match'),
            code: text('matchCode', 'Code match'),
            wordpress: text('matchWordPress', 'WordPress match'),
            fallback: text('matchFallback', 'Needs review'),
            manual: text('matchManual', 'Manual match')
        };

        return labels[confidence] || labels.fallback;
    };

    const format = function(template, replacements) {
        return String(template || '').replace(/%([0-9]+)\$s/g, function(match, index) {
            return replacements[parseInt(index, 10) - 1] || '';
        });
    };

    const renderSummary = function(scan) {
        const compatible = scan.compatible || {};
        const details = scan.details || {};
        const languages = scan.languages || {};
        const languageTargets = Array.isArray(scan.language_targets) ? scan.language_targets : [];
        const defaultSourceCode = languages.default_source_code || '';
        const defaultCode = languages.default_code || '';
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
                const displayName = lang.display_name || lang.code;
                const sourceCode = lang.source_code || lang.code;
                const selectedCode = lang.code || sourceCode;
                const isDefault = (defaultSourceCode && sourceCode === defaultSourceCode) || (!defaultSourceCode && defaultCode && selectedCode === defaultCode);
                const targetOptions = languageTargets.map(function(target) {
                    const selected = target.code === selectedCode ? ' selected' : '';
                    return '<option value="' + escapeHtml(target.code) + '"' +
                        ' data-locale="' + escapeHtml(target.locale) + '"' +
                        ' data-display-name="' + escapeHtml(target.display_name) + '"' +
                        selected +
                    '>' + escapeHtml(target.display_name + ' (' + target.code + ' / ' + target.locale + ')') + '</option>';
                }).join('');

                return '<li class="wp-loc-db-wizard__language-row' + (isDefault ? ' is-default' : '') + '">' +
                    '<div class="wp-loc-db-wizard__language-source">' +
                        '<strong>' + escapeHtml(displayName) + '</strong>' +
                        '<span>' + escapeHtml(sourceCode) + '</span>' +
                        '<code>' + escapeHtml(lang.locale || lang.code) + '</code>' +
                        '<em class="wp-loc-db-wizard__match wp-loc-db-wizard__match--' + escapeHtml(lang.confidence || 'fallback') + '">' + escapeHtml(confidenceLabel(lang.confidence || 'fallback')) + '</em>' +
                        (isDefault ? '<em class="wp-loc-db-wizard__default-language">' + escapeHtml(text('defaultLanguage', 'Default language')) + '</em>' : '') +
                    '</div>' +
                    '<label class="wp-loc-db-wizard__language-target">' +
                        '<span>' + escapeHtml(text('adoptAs', 'Adopt as')) + '</span>' +
                        '<select data-language-map="' + escapeHtml(sourceCode) + '">' + targetOptions + '</select>' +
                    '</label>' +
                '</li>';
            }).join('') + '</ul>'
            : '<p class="wp-loc-db-wizard__muted">' + text('noLanguages', 'No language records were found for import.') + '</p>';

        const tableList = tableRows.length
            ? '<ul>' + tableRows.map(function(table) {
                return '<li><span>' + table.label + '</span><strong>' + number(table.rows) + '</strong></li>';
            }).join('') + '</ul>'
            : '<p class="wp-loc-db-wizard__muted">' + text('noCleanup', 'No removable legacy data was found. Your database is already clean.') + '</p>';
        const detailList = function(items, emptyText) {
            if (!Array.isArray(items) || !items.length) {
                return '<p class="wp-loc-db-wizard__muted">' + emptyText + '</p>';
            }

            return '<ul>' + items.map(function(item) {
                return '<li><span>' + item.label + '</span><strong>' + number(item.count) + '</strong></li>';
            }).join('') + '</ul>';
        };

        return '<div class="wp-loc-db-wizard__metrics">' +
                renderMetric(text('translationLinksKept', 'translation links kept'), compatible.translation_rows) +
                renderMetric(text('postRecordsRecognized', 'post records recognized'), compatible.posts) +
                renderMetric(text('termRecordsRecognized', 'term records recognized'), compatible.terms) +
                renderMetric(text('menuRecordsRecognized', 'menu records recognized'), compatible.menus) +
                renderMetric(text('mediaRecordsRecognized', 'media records recognized'), compatible.attachments) +
                renderMetric(text('languagesDetected', 'languages detected'), languages.count) +
                renderMetric(text('localizedOptionsDetected', 'localized options detected'), details.localized_options) +
                renderMetric(text('fieldPreferencesDetected', 'field preferences detected'), details.field_preferences) +
            '</div>' +
            '<div class="wp-loc-db-wizard__columns">' +
                '<section class="wp-loc-db-wizard__panel wp-loc-db-wizard__panel--languages">' +
                    '<h3>' + text('languagesToAdopt', 'Languages to adopt') + '</h3>' +
                    languageList +
                '</section>' +
                '<section class="wp-loc-db-wizard__panel wp-loc-db-wizard__panel--content-types">' +
                    '<h3>' + text('contentTypesFound', 'Content types found') + '</h3>' +
                    detailList(details.post_types, text('noPostTypes', 'No translated post types were found.')) +
                '</section>' +
                '<section class="wp-loc-db-wizard__panel wp-loc-db-wizard__panel--taxonomies">' +
                    '<h3>' + text('taxonomiesFound', 'Taxonomies found') + '</h3>' +
                    detailList(details.taxonomies, text('noTaxonomies', 'No translated taxonomies were found.')) +
                '</section>' +
                '<section class="wp-loc-db-wizard__panel wp-loc-db-wizard__panel--legacy">' +
                    '<h3>' + text('legacyDataToRemove', 'Legacy data to remove') + '</h3>' +
                    tableList +
                    '<p class="wp-loc-db-wizard__muted">' + format(text('cleanupRowsSummary', '%1$s option rows and %2$s meta rows are marked for cleanup.'), [
                        number(legacy.options || 0),
                        number(removableMetaRows - parseInt(legacy.options || 0, 10))
                    ]) + '</p>' +
                '</section>' +
            '</div>' +
            '<input type="hidden" data-removable-total value="' + (removableTableRows + removableMetaRows) + '" />';
    };

    const renderResult = function(result) {
        return '<div class="wp-loc-db-wizard__metrics">' +
                renderMetric(text('languagesImported', 'languages imported'), result.languages_imported) +
                renderMetric(text('legacyTablesRemoved', 'legacy tables removed'), result.tables_removed) +
                renderMetric(text('optionRowsRemoved', 'option rows removed'), result.options_removed) +
                renderMetric(text('metaRowsRemoved', 'meta rows removed'), result.meta_removed) +
                renderMetric(text('translationLinksKept', 'translation links kept'), result.kept_rows) +
            '</div>' +
            '<p>' + text('optimizationReady', 'WP-LOC is ready to continue with the optimized multilingual database.') + '</p>';
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
        const languageMapping = {};

        modal.querySelectorAll('[data-language-map]').forEach(function(select) {
            const option = select.options[select.selectedIndex];
            languageMapping[select.getAttribute('data-language-map')] = {
                code: select.value,
                locale: option ? option.getAttribute('data-locale') || '' : '',
                display_name: option ? option.getAttribute('data-display-name') || '' : ''
            };
        });

        if (!window.confirm(i18n.confirmApply || 'Are you sure you want to continue? Legacy multilingual service data will be permanently removed and this action cannot be undone.')) {
            return;
        }

        showStep('progress');
        progressText.textContent = i18n.optimizing || 'Optimizing database...';

        request('wp_loc_db_optimization_apply', {
            language_mapping: JSON.stringify(languageMapping)
        }).done(function(response) {
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
        if (!window.confirm(i18n.confirmDismiss || 'Are you sure you do not want to optimize the database? This wizard will be dismissed and WP-LOC will continue without cleaning legacy multilingual data.')) {
            return;
        }

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
                if (config.redirectUrl) {
                    window.location.href = config.redirectUrl;
                } else {
                    closeForThisPage();
                }
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
