/**
 * WP-LOC Admin Scripts
 */

(function($) {
    'use strict';

    const i18n = wpLocAdmin && wpLocAdmin.i18n ? wpLocAdmin.i18n : {};

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

        $('.wp-loc-slug-input').on('input blur', function() {
            const value = $(this).val();
            if (typeof value === 'string') {
                $(this).val(value.toLowerCase().trim().replace(/\s+/g, '-'));
            }
        });

        $('.wp-loc-languages-form').on('submit', function(event) {
            const slugSet = new Set();
            const displayNameSet = new Set();
            let firstInvalidField = null;
            let errorMessage = '';

            $(this).find('.wp-loc-slug-input, .wp-loc-display-name-input').each(function() {
                this.setCustomValidity('');
            });

            $(this).find('tbody tr').each(function() {
                const $row = $(this);
                const $slugInput = $row.find('.wp-loc-slug-input');
                const $displayNameInput = $row.find('.wp-loc-display-name-input');
                const slugValue = String($slugInput.val() || '').trim().toLowerCase();
                const displayNameValue = String($displayNameInput.val() || '').trim();
                const displayNameKey = displayNameValue.toLocaleLowerCase();

                if (!slugValue) {
                    errorMessage = i18n.missingSlug || 'Slug is required.';
                    $slugInput[0].setCustomValidity(errorMessage);
                    firstInvalidField = firstInvalidField || $slugInput;
                    return false;
                }

                if (slugSet.has(slugValue)) {
                    errorMessage = i18n.duplicateSlug || 'Each language slug must be unique.';
                    $slugInput[0].setCustomValidity(errorMessage);
                    firstInvalidField = firstInvalidField || $slugInput;
                    return false;
                }
                slugSet.add(slugValue);

                if (!displayNameValue) {
                    errorMessage = i18n.missingDisplayName || 'Display name is required.';
                    $displayNameInput[0].setCustomValidity(errorMessage);
                    firstInvalidField = firstInvalidField || $displayNameInput;
                    return false;
                }

                if (displayNameSet.has(displayNameKey)) {
                    errorMessage = i18n.duplicateDisplayName || 'Each display name must be unique.';
                    $displayNameInput[0].setCustomValidity(errorMessage);
                    firstInvalidField = firstInvalidField || $displayNameInput;
                    return false;
                }
                displayNameSet.add(displayNameKey);
            });

            if (firstInvalidField) {
                event.preventDefault();
                window.alert(errorMessage);
                firstInvalidField.trigger('focus');
            }
        });
    }

    $(document).on('click', '.wp-loc-delete-link', function(event) {
        const message = i18n.confirmDeleteLanguage
            ? i18n.confirmDeleteLanguage
            : 'Delete this language?';

        if (!window.confirm(message)) {
            event.preventDefault();
        }
    });

    $(document).on('click', '.wp-loc-config-delete', function(event) {
        const message = i18n.confirmDeleteWpmlConfig
            ? i18n.confirmDeleteWpmlConfig
            : 'Delete this legacy config file?';

        if (!window.confirm(message)) {
            event.preventDefault();
        }
    });

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

    // Nav menus — inject multilingual controls and keep quick search language-aware
    (function initNavMenus() {
        const dataNode = document.getElementById('wp-loc-nav-menu-data');
        const form = document.getElementById('update-nav-menu');

        if (!dataNode || !form) {
            return;
        }

        const fields = {
            wp_loc_nav_menu_lang: dataNode.dataset.lang || '',
            wp_loc_nav_menu_trid: dataNode.dataset.trid || '',
            wp_loc_translation_of: dataNode.dataset.translationOf || ''
        };

        const ensureFields = function(targetForm) {
            if (!targetForm) {
                return;
            }

            Object.entries(fields).forEach(function(entry) {
                const name = entry[0];
                const value = entry[1];
                let input = targetForm.querySelector('input[name="' + name + '"]');

                if (!input) {
                    input = document.createElement('input');
                    input.type = 'hidden';
                    input.name = name;
                    targetForm.appendChild(input);
                }

                input.value = value;
            });
        };

        ensureFields(form);
        ensureFields(document.getElementById('nav-menu-meta'));

        if ($ && !window.wpLocNavMenuQuickSearchLangBound) {
            window.wpLocNavMenuQuickSearchLangBound = true;

            $.ajaxPrefilter(function(options, originalOptions) {
                const ajaxUrl = (typeof window.ajaxurl === 'string') ? window.ajaxurl : '';
                const requestUrl = typeof options.url === 'string' ? options.url : '';
                const data = originalOptions && originalOptions.data;
                const isQuickSearchObject = data && typeof data === 'object' && data.action === 'menu-quick-search';
                const isQuickSearchString = typeof data === 'string' && data.indexOf('action=menu-quick-search') !== -1;

                if (!requestUrl || !ajaxUrl || requestUrl.indexOf(ajaxUrl) !== 0) {
                    return;
                }

                if (!isQuickSearchObject && !isQuickSearchString) {
                    return;
                }

                const currentMenuInput = document.getElementById('menu');
                const currentMenuId = currentMenuInput ? currentMenuInput.value : '';
                const currentLang = fields.wp_loc_nav_menu_lang || '';

                if (typeof options.data === 'string') {
                    const params = new URLSearchParams(options.data);

                    if (currentLang && !params.has('wp_loc_nav_menu_lang')) {
                        params.set('wp_loc_nav_menu_lang', currentLang);
                    }

                    if (currentLang && !params.has('lang')) {
                        params.set('lang', currentLang);
                    }

                    if (currentMenuId && !params.has('menu')) {
                        params.set('menu', currentMenuId);
                    }

                    options.data = params.toString();
                    return;
                }

                options.data = Object.assign({}, options.data || {}, {
                    menu: currentMenuId || (options.data && options.data.menu) || '',
                    wp_loc_nav_menu_lang: currentLang || (options.data && options.data.wp_loc_nav_menu_lang) || '',
                    lang: currentLang || (options.data && options.data.lang) || ''
                });
            });
        }

        if (dataNode.dataset.sourceName) {
            const menuName = document.getElementById('menu-name');
            if (menuName && !menuName.value) {
                menuName.value = dataNode.dataset.sourceNameLocalized || '';
            }
        }

        const publishingAction = form.querySelector('.major-publishing-actions .publishing-action');
        if (!publishingAction || form.querySelector('.wp-loc-nav-menu-controls')) {
            return;
        }

        const showTranslations = dataNode.dataset.showTranslations === '1';
        const translationsHtml = dataNode.dataset.translationsHtml || '';
        const messageHtml = dataNode.dataset.messageHtml || '';
        const wrapper = document.createElement('div');
        wrapper.className = 'wp-loc-nav-menu-controls';

        wrapper.innerHTML =
            (showTranslations
                ? '<div class="wp-loc-nav-menu-translations">' +
                    '<strong>' + (dataNode.dataset.translationsLabel || '') + '</strong>' +
                    '<div class="wp-loc-nav-menu-translations-links">' + translationsHtml + '</div>' +
                  '</div>'
                : '') +
            messageHtml;

        publishingAction.before(wrapper);
    })();

    // Reading Settings — disable page selects for non-default languages
    if (wpLocAdmin.disablePageSelectsMessage) {
        const selects = document.querySelectorAll('#page_on_front, #page_for_posts');
        selects.forEach(function(el) {
            el.disabled = true;
            el.classList.add('wp-loc-disabled-select');
            el.title = wpLocAdmin.disablePageSelectsMessage;
        });
    }

    // Protected category terms — hide bulk checkboxes via classes
    if (Array.isArray(wpLocAdmin.protectedCategoryTermIds) && wpLocAdmin.protectedCategoryTermIds.length) {
        document.body.classList.add('wp-loc-hide-term-bulk');

        wpLocAdmin.protectedCategoryTermIds.forEach(function(termId) {
            const row = document.getElementById('tag-' + termId);
            if (row) {
                row.classList.add('wp-loc-protected-term-row');
            }
        });
    }

    // Protected term edit screen — hide delete link
    if (wpLocAdmin.hideProtectedTermDeleteLink) {
        document.body.classList.add('wp-loc-hide-term-delete-link');
    }

    // Menus Sync — Ajax refresh/apply and compact details UI
    (function initMenuSync() {
        const page = document.querySelector('.wp-loc-menu-sync-page');

        if (!page || !window.wpLocAdmin) {
            return;
        }

        const feedback = page.querySelector('.wp-loc-menu-sync-feedback');
        const content = page.querySelector('.wp-loc-menu-sync-content');
        const i18n = Object.assign({
            requestFailed: 'Request failed.',
            previewRefreshed: 'Preview refreshed.',
            menuSyncComplete: 'Menu sync complete.'
        }, wpLocAdmin.i18n || {});
        let busy = false;

        const setFeedback = function(message, type) {
            if (!feedback) {
                return;
            }

            feedback.className = 'wp-loc-menu-sync-feedback';

            if (!message) {
                feedback.innerHTML = '';
                return;
            }

            if (type) {
                feedback.classList.add('is-' + type);
            }

            feedback.innerHTML = '<p>' + message + '</p>';
        };

        const setBusy = function(nextBusy) {
            busy = nextBusy;
            page.classList.toggle('is-busy', nextBusy);

            page.querySelectorAll('.wp-loc-menu-sync-apply, .wp-loc-menu-sync-refresh').forEach(function(button) {
                button.disabled = nextBusy;
            });
        };

        const collectSelection = function() {
            const sync = {};

            page.querySelectorAll('.wp-loc-menu-sync-checkbox input:checked').forEach(function(input) {
                const match = input.name.match(/^sync\[(\d+)\]\[([^\]]+)\]$/);

                if (!match) {
                    return;
                }

                const menuId = match[1];
                const lang = match[2];

                if (!sync[menuId]) {
                    sync[menuId] = {};
                }

                sync[menuId][lang] = 1;
            });

            return sync;
        };

        const request = function(action, data, onSuccess) {
            if (busy) {
                return;
            }

            setBusy(true);
            setFeedback('', '');

            $.post(wpLocAdmin.ajaxUrl, Object.assign({
                action: action,
                nonce: wpLocAdmin.nonce
            }, data || {})).done(function(response) {
                if (!response || !response.success) {
                    const message = response && response.data && response.data.message ? response.data.message : i18n.requestFailed;
                    setFeedback(message, 'error');
                    return;
                }

                if (response.data && response.data.html && content) {
                    content.innerHTML = response.data.html;
                }

                if (typeof onSuccess === 'function') {
                    onSuccess(response.data || {});
                }
            }).fail(function() {
                setFeedback(i18n.requestFailed, 'error');
            }).always(function() {
                setBusy(false);
            });
        };

        page.addEventListener('click', function(event) {
            const toggle = event.target.closest('.wp-loc-menu-sync-toggle-details');

            if (toggle) {
                event.preventDefault();
                const details = toggle.parentElement.querySelector('.wp-loc-menu-sync-details');
                const expanded = toggle.getAttribute('aria-expanded') === 'true';

                toggle.setAttribute('aria-expanded', expanded ? 'false' : 'true');
                if (details) {
                    details.hidden = expanded;
                }
                return;
            }

            if (event.target.closest('.wp-loc-menu-sync-select-all')) {
                event.preventDefault();
                page.querySelectorAll('.wp-loc-menu-sync-checkbox input').forEach(function(input) {
                    input.checked = true;
                });
                return;
            }

            if (event.target.closest('.wp-loc-menu-sync-deselect-all')) {
                event.preventDefault();
                page.querySelectorAll('.wp-loc-menu-sync-checkbox input').forEach(function(input) {
                    input.checked = false;
                });
                return;
            }

            if (event.target.closest('.wp-loc-menu-sync-refresh')) {
                event.preventDefault();
                request('wp_loc_menu_sync_preview', {}, function() {
                    setFeedback(i18n.previewRefreshed, 'success');
                });
                return;
            }

            if (event.target.closest('.wp-loc-menu-sync-apply')) {
                event.preventDefault();
                const sync = collectSelection();

                request('wp_loc_menu_sync_apply', { sync: sync }, function(data) {
                    setFeedback(data.message || i18n.menuSyncComplete, 'success');
                });
            }
        });
    })();

    (function initAiTranslateTool() {
        const page = document.querySelector('.wp-loc-tools-page');
        const tool = page ? page.querySelector('.wp-loc-ai-translate-tool') : null;

        if (!page || !tool || !window.wpLocAdmin) {
            return;
        }

        const feedback = tool.querySelector('.wp-loc-ai-translate-feedback');
        const targetSelect = tool.querySelector('.wp-loc-ai-target-lang');
        const submitButton = tool.querySelector('.wp-loc-ai-translate-submit');
        const i18n = Object.assign({
            requestFailed: 'Request failed.',
            translateFailed: 'Translation failed.',
            translating: 'Translating...',
            translationInserted: 'Translation inserted into the editor.',
            emptyTextToTranslate: 'Enter text to translate first.',
            selectTargetLanguage: 'Select a target language.'
        }, wpLocAdmin.i18n || {});
        let busy = false;

        const editorId = 'wp_loc_ai_translate_editor';

        const getEditorContent = function() {
            if (window.tinyMCE && window.tinyMCE.get(editorId)) {
                return window.tinyMCE.get(editorId).getContent();
            }

            const textarea = document.getElementById(editorId);
            return textarea ? textarea.value : '';
        };

        const hasTranslatableContent = function(content) {
            const textOnly = String(content || '')
                .replace(/<[^>]*>/g, ' ')
                .replace(/&nbsp;/gi, ' ')
                .replace(/\s+/g, ' ')
                .trim();

            return textOnly.length > 0;
        };

        const setEditorContent = function(content) {
            if (window.tinyMCE && window.tinyMCE.get(editorId)) {
                const editor = window.tinyMCE.get(editorId);

                editor.undoManager.transact(function() {
                    editor.setContent(content, { format: 'raw' });
                });
                editor.nodeChanged();
                editor.save();
                editor.fire('change');
                editor.fire('input');
                editor.focus();
            }

            const textarea = document.getElementById(editorId);
            if (textarea) {
                textarea.value = content;
            }
        };

        const setFeedback = function(message, type) {
            if (!feedback) {
                return;
            }

            feedback.className = 'wp-loc-menu-sync-feedback wp-loc-ai-translate-feedback';

            if (!message) {
                feedback.innerHTML = '';
                return;
            }

            if (type) {
                feedback.classList.add('is-' + type);
            }

            feedback.innerHTML = '<p>' + message + '</p>';
        };

        const setBusy = function(nextBusy) {
            busy = nextBusy;

            [submitButton, targetSelect].forEach(function(button) {
                if (button) {
                    button.disabled = nextBusy;
                }
            });

            if (!nextBusy && submitButton && targetSelect) {
                submitButton.disabled = !targetSelect.value;
            }
        };

        if (targetSelect && submitButton) {
            const syncTranslateButtonState = function() {
                submitButton.disabled = busy || !targetSelect.value;
            };

            targetSelect.addEventListener('change', syncTranslateButtonState);
            syncTranslateButtonState();
        }

        if (submitButton) {
            submitButton.addEventListener('click', function() {
                if (busy) {
                    return;
                }

                const content = getEditorContent();
                const targetLang = targetSelect ? targetSelect.value : '';

                if (!hasTranslatableContent(content)) {
                    setFeedback(i18n.emptyTextToTranslate, 'error');
                    return;
                }

                if (!targetLang) {
                    setFeedback(i18n.selectTargetLanguage, 'error');
                    return;
                }

                setBusy(true);
                setFeedback(i18n.translating, 'success');

                $.post(wpLocAdmin.ajaxUrl, {
                    action: 'wp_loc_ai_translate',
                    nonce: wpLocAdmin.nonce,
                    content: content,
                    target_lang: targetLang
                }).done(function(response) {
                    if (!response || !response.success || !response.data || !response.data.content) {
                        const message = response && response.data && response.data.message ? response.data.message : i18n.translateFailed;
                        setFeedback(message, 'error');
                        return;
                    }

                    setEditorContent(response.data.content);
                    setFeedback(response.data.message || i18n.translationInserted, 'success');
                }).fail(function() {
                    setFeedback(i18n.requestFailed, 'error');
                }).always(function() {
                    setBusy(false);
                });
            });
        }
    })();

    (function initAcfFieldGroupMultilingualSetup() {
        if (!$('body.post-type-acf-field-group').length || !$('#wp-loc-acf-field-group-setup').length) {
            return;
        }

        const modeSelector = 'input[name="wp_loc_acf_field_group_mode"]';

        const isExpertMode = function() {
            return $(modeSelector + ':checked').val() === 'advanced';
        };

        const toggleFieldTranslationPreferences = function() {
            const shouldShow = isExpertMode();

            $('.acf-field-setting-translation_mode, .acf-field[data-name="translation_mode"]').each(function() {
                $(this).toggle(shouldShow);
            });
        };

        $(document).on('change', modeSelector, toggleFieldTranslationPreferences);
        toggleFieldTranslationPreferences();

        if (typeof MutationObserver !== 'undefined') {
            const observer = new MutationObserver(function() {
                toggleFieldTranslationPreferences();
            });

            observer.observe(document.body, {
                childList: true,
                subtree: true
            });
        }
    })();

    (function initAiKeyTests() {
        const page = document.querySelector('.wp-loc-settings-page');
        if (!page || !window.wpLocAdmin) {
            return;
        }

        const i18n = Object.assign({
            testing: 'Testing...',
            test: 'Test',
            apiKeyEmpty: 'API key is empty.',
            requestFailed: 'Request failed.'
        }, wpLocAdmin.i18n || {});

        const setStatus = function(container, message, type) {
            if (!container) {
                return;
            }

            container.className = 'wp-loc-ai-key-status';

            if (type) {
                container.classList.add('is-' + type);
            }

            container.textContent = message || '';
        };

        $(document).on('click', '.wp-loc-ai-key-test', function() {
            const button = this;
            const provider = button.getAttribute('data-provider') || '';
            const row = button.closest('.wp-loc-ai-key-row');
            const cell = row ? row.parentElement : null;
            const input = row ? row.querySelector('input[type="password"], input[type="text"]') : null;
            const modelSelect = cell ? cell.querySelector('.wp-loc-ai-model-select') : null;
            const status = row ? row.querySelector('.wp-loc-ai-key-status') : null;
            const apiKey = input ? String(input.value || '').trim() : '';
            const model = modelSelect ? String(modelSelect.value || '').trim() : '';

            if (!apiKey) {
                setStatus(status, i18n.apiKeyEmpty, 'error');
                return;
            }

            button.disabled = true;
            button.textContent = i18n.testing;
            setStatus(status, '', '');

            $.post(wpLocAdmin.ajaxUrl, {
                action: 'wp_loc_test_ai_key',
                nonce: wpLocAdmin.nonce,
                provider: provider,
                api_key: apiKey,
                model: model
            }).done(function(response) {
                if (!response || !response.success) {
                    const message = response && response.data && response.data.message ? response.data.message : i18n.requestFailed;
                    setStatus(status, message, 'error');
                    return;
                }

                setStatus(status, response.data.message || 'OK', 'success');
            }).fail(function(xhr) {
                const message = xhr && xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message
                    ? xhr.responseJSON.data.message
                    : i18n.requestFailed;
                setStatus(status, message, 'error');
            }).always(function() {
                button.disabled = false;
                button.textContent = i18n.test;
            });
        });
    })();

    (function initTitleTranslateModal() {
        if (!window.wpLocAdmin) {
            return;
        }

        const ensureModal = function() {
            let modal = document.querySelector('.wp-loc-title-translate-modal');
            if (modal) {
                return modal;
            }

            const wrapper = document.createElement('div');
            wrapper.className = 'wp-loc-title-translate-modal';
            wrapper.hidden = true;
            wrapper.innerHTML =
                '<div class="wp-loc-title-translate-modal__backdrop"></div>' +
                '<div class="wp-loc-title-translate-modal__dialog" role="dialog" aria-modal="true" aria-labelledby="wp-loc-title-translate-modal-title">' +
                    '<button type="button" class="wp-loc-title-translate-modal__close" aria-label="' + (wpLocAdmin.i18n && wpLocAdmin.i18n.closeLabel ? wpLocAdmin.i18n.closeLabel : 'Close') + '">×</button>' +
                    '<h2 id="wp-loc-title-translate-modal-title">' + (wpLocAdmin.i18n && wpLocAdmin.i18n.translateTitle ? wpLocAdmin.i18n.translateTitle : 'Translate title') + '</h2>' +
                    '<p class="wp-loc-title-translate-modal__description">' + (wpLocAdmin.i18n && wpLocAdmin.i18n.chooseTargetLanguage ? wpLocAdmin.i18n.chooseTargetLanguage : 'Choose target language') + '</p>' +
                    '<div class="wp-loc-title-translate-modal__targets"></div>' +
                    '<label class="wp-loc-title-translate-modal__slug-toggle">' +
                        '<input type="checkbox" class="wp-loc-title-translate-update-slug" checked />' +
                        '<span>' + (wpLocAdmin.i18n && wpLocAdmin.i18n.autoUpdateSlug ? wpLocAdmin.i18n.autoUpdateSlug : 'Automatically update the slug from the translated title') + '</span>' +
                    '</label>' +
                    '<div class="wp-loc-title-translate-modal__status" aria-live="polite"></div>' +
                '</div>';

            document.body.appendChild(wrapper);
            return wrapper;
        };

        const modal = ensureModal();

        const targetsWrap = modal.querySelector('.wp-loc-title-translate-modal__targets');
        const statusWrap = modal.querySelector('.wp-loc-title-translate-modal__status');
        const slugToggleWrap = modal.querySelector('.wp-loc-title-translate-modal__slug-toggle');
        const slugToggleInput = modal.querySelector('.wp-loc-title-translate-update-slug');
        const closeSelectors = '.wp-loc-title-translate-modal__close, .wp-loc-title-translate-modal__backdrop';
        const i18n = Object.assign({
            translating: 'Translating...',
            translateTitle: 'Translate title',
            translateTermName: 'Translate term name',
            chooseTargetLanguage: 'Choose target language',
            autoUpdateSlug: 'Automatically update the slug from the translated title',
            noTitleToTranslate: 'There is no title to translate.',
            noTermNameToTranslate: 'There is no term name to translate.',
            noAvailableTranslationTargets: 'There are no available translation targets for this post.',
            noAvailableTermTranslationTargets: 'There are no available translation targets for this term.',
            titleTranslateSuccess: 'Title translated successfully.',
            termNameTranslateSuccess: 'Term name translated successfully.',
            requestFailed: 'Request failed.'
        }, wpLocAdmin.i18n || {});

        let currentPostId = 0;
        let currentTermId = 0;
        let currentTaxonomy = '';
        let currentTitle = '';
        let currentTargets = [];
        let currentSource = 'list';
        let currentEntityType = 'post';

        const setStatus = function(message, type) {
            if (!statusWrap) {
                return;
            }

            statusWrap.className = 'wp-loc-title-translate-modal__status';
            if (type) {
                statusWrap.classList.add('is-' + type);
            }
            statusWrap.textContent = message || '';
        };

        const closeModal = function() {
            modal.hidden = true;
            document.body.classList.remove('wp-loc-title-translate-open');
            currentPostId = 0;
            currentTermId = 0;
            currentTaxonomy = '';
            currentTitle = '';
            currentTargets = [];
            currentSource = 'list';
            currentEntityType = 'post';
            if (targetsWrap) {
                targetsWrap.innerHTML = '';
            }
            if (slugToggleInput) {
                slugToggleInput.checked = true;
            }
            setStatus('', '');
        };

        const updateClassicPermalinkUi = function(newSlug) {
            if (!newSlug) {
                return;
            }

            const realSlugInput = document.querySelector('#post_name');
            const slugInput = document.querySelector('#new-post-slug');
            const editableFull = document.querySelector('#editable-post-name-full');
            const editableShort = document.querySelector('#editable-post-name');
            const permalinkAnchor = document.querySelector('#sample-permalink a');
            const viewLink = document.querySelector('#view-post-btn a');

            if (realSlugInput) {
                realSlugInput.value = newSlug;
            }

            if (slugInput) {
                slugInput.value = newSlug;
            }

            if (editableFull) {
                editableFull.textContent = newSlug;
            }

            if (editableShort) {
                editableShort.textContent = newSlug;
            }

            const updateHrefSlug = function(href) {
                if (!href) {
                    return href;
                }

                try {
                    const url = new URL(href, window.location.origin);
                    const parts = url.pathname.split('/').filter(Boolean);

                    if (!parts.length) {
                        return href;
                    }

                    parts[parts.length - 1] = newSlug;
                    url.pathname = '/' + parts.join('/') + '/';
                    return url.toString();
                } catch (error) {
                    return href;
                }
            };

            if (permalinkAnchor) {
                permalinkAnchor.href = updateHrefSlug(permalinkAnchor.href);
                permalinkAnchor.textContent = permalinkAnchor.href;
            }

            if (viewLink) {
                viewLink.href = updateHrefSlug(viewLink.href);
            }
        };

        const titleNode = modal.querySelector('#wp-loc-title-translate-modal-title');

        const openModal = function(config) {
            currentPostId = parseInt(config.postId || '0', 10);
            currentTermId = parseInt(config.termId || '0', 10);
            currentTaxonomy = config.taxonomy || '';
            currentTitle = config.title || '';
            currentTargets = Array.isArray(config.targets) ? config.targets : [];
            currentSource = config.source || 'list';
            currentEntityType = config.entityType || 'post';
            if (targetsWrap) {
                targetsWrap.innerHTML = '';
            }
            if (slugToggleWrap) {
                slugToggleWrap.hidden = currentEntityType !== 'post';
            }
            if (slugToggleInput) {
                slugToggleInput.checked = true;
            }
            setStatus('', '');
            if (titleNode) {
                titleNode.textContent = config.modalTitle || i18n.translateTitle;
            }

            if (!currentTargets.length) {
                setStatus(
                    currentEntityType === 'term'
                        ? (i18n.noAvailableTermTranslationTargets || i18n.noAvailableTranslationTargets)
                        : i18n.noAvailableTranslationTargets,
                    'error'
                );
            } else if (targetsWrap) {
                currentTargets.forEach(function(target) {
                    const button = document.createElement('button');
                    button.type = 'button';
                    button.className = 'wp-loc-title-translate-target';
                    button.setAttribute('data-lang', target.lang || '');
                    button.innerHTML =
                        '<img src="' + (target.flag || '') + '" alt="" />' +
                        '<span>' + (target.name || target.lang || '') + '</span>';
                    targetsWrap.appendChild(button);
                });
            }

            modal.hidden = false;
            document.body.classList.add('wp-loc-title-translate-open');
        };

        $(document).on('click', '.wp-loc-translate-post-title', function(event) {
            event.preventDefault();

            const postId = parseInt(this.getAttribute('data-post-id') || '0', 10);
            const title = String(this.getAttribute('data-current-title') || '').trim();
            const rawTargets = this.getAttribute('data-targets') || '[]';
            let targets = [];

            try {
                targets = JSON.parse(rawTargets);
            } catch (error) {
                targets = [];
            }

            if (!title) {
                window.alert(i18n.noTitleToTranslate);
                return;
            }

            openModal({
                postId: postId,
                title: title,
                targets: targets,
                source: 'list',
                entityType: 'post',
                modalTitle: i18n.translateTitle
            });
        });

        $(document).on('click', '.wp-loc-translate-term-name', function(event) {
            event.preventDefault();

            const termId = parseInt(this.getAttribute('data-term-id') || '0', 10);
            const taxonomy = String(this.getAttribute('data-taxonomy') || '');
            const title = String(this.getAttribute('data-current-title') || '').trim();
            const rawTargets = this.getAttribute('data-targets') || '[]';
            let targets = [];

            try {
                targets = JSON.parse(rawTargets);
            } catch (error) {
                targets = [];
            }

            if (!title) {
                window.alert(i18n.noTermNameToTranslate || i18n.noTitleToTranslate);
                return;
            }

            openModal({
                termId: termId,
                taxonomy: taxonomy,
                title: title,
                targets: targets,
                source: 'list',
                entityType: 'term',
                modalTitle: i18n.translateTermName || i18n.translateTitle
            });
        });

        const renderGutenbergButton = function() {
            const config = wpLocAdmin.gutenbergTitleTranslate;
            if (!config || !config.postId || !Array.isArray(config.targets) || !config.targets.length) {
                return;
            }

            const titleWrapper = document.querySelector('.edit-post-visual-editor__post-title-wrapper, .editor-post-title');
            if (!titleWrapper || titleWrapper.querySelector('.wp-loc-gutenberg-title-translate')) {
                return;
            }

            const button = document.createElement('button');
            button.type = 'button';
            button.className = 'wp-loc-gutenberg-title-translate';
            button.setAttribute('aria-label', i18n.translateTitle);
            button.setAttribute('title', i18n.translateTitle);
            button.innerHTML = '<span class="dashicons dashicons-translation" aria-hidden="true"></span>';

            titleWrapper.classList.add('wp-loc-has-gutenberg-title-translate');
            titleWrapper.appendChild(button);
        };

        const renderClassicButton = function() {
            const config = wpLocAdmin.classicTitleTranslate;
            if (!config || !config.postId || !Array.isArray(config.targets) || !config.targets.length) {
                return;
            }

            const titleWrap = document.querySelector('#titlediv #titlewrap');
            const titleInput = document.querySelector('#title');

            if (!titleWrap || !titleInput || titleWrap.querySelector('.wp-loc-classic-title-translate')) {
                return;
            }

            const button = document.createElement('button');
            button.type = 'button';
            button.className = 'wp-loc-classic-title-translate';
            button.setAttribute('aria-label', i18n.translateTitle);
            button.setAttribute('title', i18n.translateTitle);
            button.innerHTML = '<span class="dashicons dashicons-translation" aria-hidden="true"></span>';

            titleWrap.classList.add('wp-loc-has-classic-title-translate');
            titleWrap.appendChild(button);
        };

        const renderTermEditButton = function() {
            const dataNode = document.querySelector('#wp-loc-term-edit-translate-data');
            const nameInput = document.querySelector('#name');

            if (!dataNode || !nameInput) {
                return;
            }

            let targets = [];

            try {
                targets = JSON.parse(dataNode.getAttribute('data-targets') || '[]');
            } catch (error) {
                targets = [];
            }

            if (!targets.length) {
                return;
            }

            const inputWrap = nameInput.closest('td');

            if (!inputWrap || inputWrap.querySelector('.wp-loc-term-edit-translate')) {
                return;
            }

            const button = document.createElement('button');
            button.type = 'button';
            button.className = 'wp-loc-term-edit-translate';
            button.setAttribute('aria-label', i18n.translateTermName || i18n.translateTitle);
            button.setAttribute('title', i18n.translateTermName || i18n.translateTitle);
            button.setAttribute('data-term-id', dataNode.getAttribute('data-term-id') || '');
            button.setAttribute('data-taxonomy', dataNode.getAttribute('data-taxonomy') || '');
            button.setAttribute('data-current-title', dataNode.getAttribute('data-current-title') || '');
            button.setAttribute('data-targets', dataNode.getAttribute('data-targets') || '[]');
            button.innerHTML = '<span class="dashicons dashicons-translation" aria-hidden="true"></span>';

            inputWrap.classList.add('wp-loc-has-term-edit-translate');
            inputWrap.appendChild(button);
        };

        if (typeof wp !== 'undefined' && wp.data && wp.data.subscribe && wpLocAdmin.gutenbergTitleTranslate) {
            renderGutenbergButton();
            wp.data.subscribe(function() {
                renderGutenbergButton();
            });
        }

        if (wpLocAdmin.classicTitleTranslate) {
            renderClassicButton();
        }

        renderTermEditButton();

        $(document).on('click', '.wp-loc-gutenberg-title-translate', function(event) {
            event.preventDefault();

            const config = wpLocAdmin.gutenbergTitleTranslate || {};
            const editor = typeof wp !== 'undefined' && wp.data ? wp.data.select('core/editor') : null;
            const title = editor && typeof editor.getEditedPostAttribute === 'function'
                ? String(editor.getEditedPostAttribute('title') || '').trim()
                : '';

            if (!title) {
                window.alert(i18n.noTitleToTranslate);
                return;
            }

            openModal({
                postId: parseInt(config.postId || '0', 10),
                title: title,
                targets: config.targets || [],
                source: 'gutenberg',
                entityType: 'post',
                modalTitle: i18n.translateTitle
            });
        });

        $(document).on('click', '.wp-loc-classic-title-translate', function(event) {
            event.preventDefault();

            const config = wpLocAdmin.classicTitleTranslate || {};
            const titleInput = document.querySelector('#title');
            const title = titleInput ? String(titleInput.value || '').trim() : '';

            if (!title) {
                window.alert(i18n.noTitleToTranslate);
                return;
            }

            openModal({
                postId: parseInt(config.postId || '0', 10),
                title: title,
                targets: config.targets || [],
                source: 'classic',
                entityType: 'post',
                modalTitle: i18n.translateTitle
            });
        });

        $(document).on('click', '.wp-loc-term-edit-translate', function(event) {
            event.preventDefault();

            const termId = parseInt(this.getAttribute('data-term-id') || '0', 10);
            const taxonomy = String(this.getAttribute('data-taxonomy') || '');
            const nameInput = document.querySelector('#name');
            const title = nameInput ? String(nameInput.value || '').trim() : '';
            const rawTargets = this.getAttribute('data-targets') || '[]';
            let targets = [];

            try {
                targets = JSON.parse(rawTargets);
            } catch (error) {
                targets = [];
            }

            if (!title) {
                window.alert(i18n.noTermNameToTranslate || i18n.noTitleToTranslate);
                return;
            }

            openModal({
                termId: termId,
                taxonomy: taxonomy,
                title: title,
                targets: targets,
                source: 'term-edit',
                entityType: 'term',
                modalTitle: i18n.translateTermName || i18n.translateTitle
            });
        });

        $(document).on('click', closeSelectors, function() {
            closeModal();
        });

        $(document).on('keydown', function(event) {
            if (event.key === 'Escape' && !modal.hidden) {
                closeModal();
            }
        });

        $(document).on('click', '.wp-loc-title-translate-target', function() {
            const button = this;
            const targetLang = button.getAttribute('data-lang') || '';

            if ((currentEntityType === 'post' && !currentPostId) || (currentEntityType === 'term' && !currentTermId) || !currentTitle.trim()) {
                setStatus(currentEntityType === 'term' ? (i18n.noTermNameToTranslate || i18n.noTitleToTranslate) : i18n.noTitleToTranslate, 'error');
                return;
            }

            modal.classList.add('is-loading');
            setStatus(i18n.translating, '');

            const payload = {
                nonce: wpLocAdmin.nonce,
                target_lang: targetLang
            };

            if (currentEntityType === 'term') {
                payload.action = 'wp_loc_translate_term_name';
                payload.term_id = currentTermId;
                payload.taxonomy = currentTaxonomy;
            } else {
                payload.action = 'wp_loc_translate_post_title';
                payload.post_id = currentPostId;
                payload.update_slug = slugToggleInput && slugToggleInput.checked ? '1' : '0';
                if (currentSource === 'gutenberg' || currentSource === 'classic') {
                    payload.editor_context = '1';
                } else if (currentSource === 'list') {
                    payload.current_post_only = '1';
                }
            }

            $.post(wpLocAdmin.ajaxUrl, {
                ...payload
            }).done(function(response) {
                if (!response || !response.success) {
                    const message = response && response.data && response.data.message ? response.data.message : i18n.requestFailed;
                    setStatus(message, 'error');
                    return;
                }

                setStatus(
                    response.data.message || (currentEntityType === 'term'
                        ? (i18n.termNameTranslateSuccess || i18n.titleTranslateSuccess)
                        : i18n.titleTranslateSuccess),
                    'success'
                );
                if (currentSource !== 'list' && currentEntityType === 'post') {
                    if (currentSource === 'gutenberg') {
                        if (typeof wp !== 'undefined' && wp.data && wp.data.dispatch) {
                            const postUpdate = {
                                title: response.data.new_title || currentTitle
                            };

                            if (response.data.slug_updated && response.data.new_slug) {
                                postUpdate.slug = response.data.new_slug;
                            }

                            wp.data.dispatch('core/editor').editPost(postUpdate);
                        }
                    } else if (currentSource === 'classic') {
                        const titleInput = document.querySelector('#title');
                        if (titleInput) {
                            titleInput.value = response.data.new_title || currentTitle;
                            $(titleInput).trigger('input').trigger('change');
                        }

                        if (response.data.slug_updated && response.data.new_slug) {
                            updateClassicPermalinkUi(response.data.new_slug);
                        }
                    } else if (currentSource === 'term-edit') {
                        const nameInput = document.querySelector('#name');
                        const slugInput = document.querySelector('#slug');
                        const dataNode = document.querySelector('#wp-loc-term-edit-translate-data');

                        if (nameInput) {
                            nameInput.value = response.data.new_title || currentTitle;
                            $(nameInput).trigger('input').trigger('change');
                        }

                        if (slugInput && response.data.new_slug) {
                            slugInput.value = response.data.new_slug;
                            $(slugInput).trigger('input').trigger('change');
                        }

                        if (dataNode) {
                            dataNode.setAttribute('data-current-title', response.data.new_title || currentTitle);
                        }
                    }

                    window.setTimeout(function() {
                        closeModal();
                    }, 1000);
                } else {
                    window.setTimeout(function() {
                        window.location.reload();
                    }, 2000);
                }
            }).fail(function(xhr) {
                const message = xhr && xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message
                    ? xhr.responseJSON.data.message
                    : i18n.requestFailed;
                setStatus(message, 'error');
            }).always(function() {
                modal.classList.remove('is-loading');
            });
        });
    })();

})(jQuery);
