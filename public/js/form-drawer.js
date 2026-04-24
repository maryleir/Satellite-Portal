/**
 * Form Drawer — Right-side sliding panel for Formidable forms.
 *
 * After a form submit, calls window.refreshDashboard() (from portal.js)
 * to update all mutable UI regions via server-rendered partials.
 *
 * Supports:
 *   - Readonly mode: completed tasks show disabled fields + Close button
 *   - CRUD mode: meeting planners loaded via REST with inline edit (no iframe)
 *
 * @package WSSP
 */
(function() {
    'use strict';

    /** Form keys that use the custom CRUD panel instead of an iframe. */
    var CRUD_FORM_KEYS = ['wssp-sat-meeting-planners'];

    document.addEventListener('DOMContentLoaded', initFormDrawer);

    function initFormDrawer() {
        var drawer   = document.querySelector('.wssp-drawer');
        var backdrop = document.querySelector('.wssp-drawer-backdrop');

        if (!drawer || !backdrop) return;

        var titleEl    = drawer.querySelector('.wssp-drawer__title');
        var subtitleEl = drawer.querySelector('.wssp-drawer__subtitle');
        var loading    = drawer.querySelector('.wssp-drawer__loading');
        var content    = drawer.querySelector('.wssp-drawer__content');
        var errorEl    = drawer.querySelector('.wssp-drawer__error');
        var closeBtn   = drawer.querySelector('.wssp-drawer__close');
        var retryBtn   = drawer.querySelector('.wssp-drawer__retry');

        var currentUrl = null;
        var formDirty  = false;
        var currentIframe = null;
        var currentTaskKey = null;
        var currentSessionId = null;
        var currentReadonly = false;
        var currentMode = 'iframe'; // 'iframe', 'crud', or 'upload'
        var currentFileType = null;

        // ─── Listen for messages from the iframe ───
        window.addEventListener('message', function(e) {
            if (e.data && typeof e.data === 'object' && e.data.type && e.data.type.indexOf('wssp_') === 0) {
                console.log('[WSSP Drawer] Message received:', e.data);
            }

            if (e.data && e.data.type === 'wssp_form_submitted') {
                formDirty = true;
                console.log('[WSSP Drawer] Form submitted — refreshing dashboard');

                if (window.refreshDashboard) {
                    window.refreshDashboard();
                }
            }
        });

        // ─── Bind open buttons (initial + after refresh) ───
        bindOpenButtons();
        bindUploadButtons();

        window.rebindFormDrawerButtons = function() {
            bindOpenButtons();
            bindUploadButtons();
        };

        function bindOpenButtons() {
            document.querySelectorAll('.wssp-open-form-drawer').forEach(function(btn) {
                if (btn._wsspBound) return;
                btn._wsspBound = true;

                btn.addEventListener('click', function(e) {
                    e.preventDefault();

                    var formKey     = btn.getAttribute('data-form-key');
                    var sessionId   = btn.getAttribute('data-session-id');
                    var taskLabel   = btn.getAttribute('data-task-label') || 'Form';
                    var fileType    = btn.getAttribute('data-file-type') || '';
                    var fieldKeys   = btn.getAttribute('data-field-keys') || '';
                    var isReadonly  = btn.getAttribute('data-readonly') === 'true';

                    currentTaskKey   = btn.getAttribute('data-task-key') || '';
                    currentSessionId = btn.getAttribute('data-session-id') || '';
                    currentReadonly  = isReadonly;

                    var subtitleHtml   = btn.getAttribute('data-subtitle-html') || '';
                    var plainSubtitle  = btn.getAttribute('data-subtitle') || '';

                    titleEl.textContent = taskLabel;

                    if (subtitleHtml) {
                        subtitleEl.innerHTML = subtitleHtml;
                    } else if (plainSubtitle) {
                        subtitleEl.textContent = plainSubtitle;
                    } else {
                        subtitleEl.textContent = isReadonly
                            ? 'Viewing submitted responses'
                            : 'Complete the fields for this task';
                    }

                    openDrawer();

                    // ─── Route: CRUD panel or iframe ───
                    if (CRUD_FORM_KEYS.indexOf(formKey) !== -1) {
                        currentMode = 'crud';
                        loadCrudPanel(sessionId);
                    } else {
                        currentMode = 'iframe';

                        var urlParams  = new URLSearchParams(window.location.search);
                        var sessionKey = urlParams.get('session_key') || '';
                        var formPageUrl = wsspData.formPageUrl;
                        var separator   = formPageUrl.indexOf('?') > -1 ? '&' : '?';

                        currentUrl = formPageUrl + separator +
                            'form_key=' + encodeURIComponent(formKey) +
                            '&session_key=' + encodeURIComponent(sessionKey);

                        if (fileType) {
                            currentUrl += '&file_type=' + encodeURIComponent(fileType);
                        }
                        if (fieldKeys) {
                            currentUrl += '&fields=' + encodeURIComponent(fieldKeys);
                        }
                        if (isReadonly) {
                            currentUrl += '&readonly=1';
                        }

                        loadForm(currentUrl);
                    }
                });
            });
        }

        function bindUploadButtons() {
            document.querySelectorAll('.wssp-open-upload-drawer').forEach(function(btn) {
                if (btn._wsspBound) return;
                btn._wsspBound = true;

                btn.addEventListener('click', function(e) {
                    e.preventDefault();

                    var sessionId  = btn.getAttribute('data-session-id');
                    var taskLabel  = btn.getAttribute('data-task-label') || 'Upload';
                    var fileType   = btn.getAttribute('data-file-type') || '';
                    var isReadonly = btn.getAttribute('data-readonly') === 'true';
                    var formKey    = btn.getAttribute('data-form-key') || '';
                    var fieldKeys  = btn.getAttribute('data-field-keys') || '';

                    currentTaskKey   = btn.getAttribute('data-task-key') || '';
                    currentSessionId = sessionId;
                    currentReadonly  = isReadonly;
                    currentMode      = 'upload';
                    currentFileType  = fileType;

                    var subtitleHtml = btn.getAttribute('data-subtitle-html') || '';

                    titleEl.textContent = taskLabel;

                    if (subtitleHtml) {
                        subtitleEl.innerHTML = subtitleHtml;
                    } else {
                        subtitleEl.textContent = isReadonly
                            ? 'Viewing uploaded files'
                            : 'Upload and manage files for this task';
                    }

                    openDrawer();
                    loadUploadPanel(sessionId, fileType, isReadonly, formKey, fieldKeys);
                });
            });
        }

        // ─── Close ───
        closeBtn.addEventListener('click', closeDrawer);
        backdrop.addEventListener('click', closeDrawer);

        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape' && drawer.classList.contains('wssp-drawer--open')) {
                closeDrawer();
            }
        });

        if (retryBtn) {
            retryBtn.addEventListener('click', function() {
                if (currentMode === 'crud' && currentSessionId) {
                    loadCrudPanel(currentSessionId);
                } else if (currentMode === 'upload' && currentSessionId && currentFileType) {
                    loadUploadPanel(currentSessionId, currentFileType, currentReadonly);
                } else if (currentUrl) {
                    loadForm(currentUrl);
                }
            });
        }

        function openDrawer() {
            drawer.style.display = 'flex';
            backdrop.style.display = 'block';
            document.body.style.overflow = 'hidden';
            drawer.offsetHeight;
            drawer.classList.add('wssp-drawer--open');
            backdrop.classList.add('wssp-drawer-backdrop--visible');
            drawer.setAttribute('aria-hidden', 'false');
            formDirty = false;
        }

        function closeDrawer() {
            drawer.classList.remove('wssp-drawer--open');
            backdrop.classList.remove('wssp-drawer-backdrop--visible');
            drawer.setAttribute('aria-hidden', 'true');
            document.body.style.overflow = '';

            setTimeout(function() {
                drawer.style.display = 'none';
                backdrop.style.display = 'none';
                loading.style.display = 'none';
                errorEl.style.display = 'none';
                content.innerHTML = '';
                currentIframe = null;
                currentTaskKey = null;
                currentSessionId = null;
                currentReadonly = false;
                currentMode = 'iframe';
                currentFileType = null;
                formDirty = false;
            }, 300);
        }

        /* ═══════════════════════════════════════════
         * IFRAME MODE — Standard Formidable forms
         * ═══════════════════════════════════════════ */

        function loadForm(url) {
            loading.style.display = '';
            content.innerHTML = '';
            content.style.display = 'none';
            errorEl.style.display = 'none';

            var iframe = document.createElement('iframe');
            iframe.className = 'wssp-drawer__iframe';
            iframe.setAttribute('frameborder', '0');
            iframe.setAttribute('allowtransparency', 'true');
            iframe.src = url;

            currentIframe = iframe;

            iframe.addEventListener('load', function() {
                loading.style.display = 'none';
                content.style.display = '';

                if (currentReadonly) {
                    applyReadonlyMode(iframe);
                }

                resizeRTEFields(iframe);
            });

            iframe.addEventListener('error', function() {
                loading.style.display = 'none';
                errorEl.style.display = '';
            });

            content.appendChild(iframe);
        }

        function applyReadonlyMode(iframe) {
            try {
                var doc = iframe.contentDocument || iframe.contentWindow.document;
                if (!doc) return;

                setTimeout(function() {
                    var inputs = doc.querySelectorAll(
                        'input, select, textarea, button[type="submit"], input[type="submit"]'
                    );
                    inputs.forEach(function(input) {
                        input.disabled = true;
                        input.style.opacity = '0.7';
                        input.style.cursor = 'default';
                    });

                    var editorIframes = doc.querySelectorAll('iframe[id*="_ifr"]');
                    editorIframes.forEach(function(edIframe) {
                        try {
                            var edDoc = edIframe.contentDocument || edIframe.contentWindow.document;
                            if (edDoc && edDoc.body) {
                                edDoc.body.contentEditable = false;
                                edDoc.body.style.opacity = '0.7';
                                edDoc.body.style.cursor = 'default';
                            }
                        } catch (ex) {}
                    });

                    var submitDivs = doc.querySelectorAll('.frm_submit');
                    submitDivs.forEach(function(div) { div.style.display = 'none'; });

                    var notice = doc.createElement('div');
                    notice.style.cssText = 'background:#f0f9ff; border:1px solid #bae6fd; color:#0369a1; ' +
                        'padding:12px 16px; border-radius:8px; margin-bottom:16px; font-size:14px;';
                    notice.innerHTML = '<strong>View Only</strong> — This task has been marked complete. Fields are locked.';
                    var formEl = doc.querySelector('.frm_forms') || doc.body;
                    formEl.insertBefore(notice, formEl.firstChild);

                    var closeContainer = doc.createElement('div');
                    closeContainer.style.cssText = 'text-align:right; padding:16px 0; margin-top:16px; border-top:1px solid #e5e5e5;';
                    var closeButton = doc.createElement('button');
                    closeButton.type = 'button';
                    closeButton.textContent = 'Close';
                    closeButton.style.cssText = 'padding:8px 24px; background:#f5f5f5; border:1px solid #ddd; ' +
                        'border-radius:6px; cursor:pointer; font-size:14px; color:#333;';
                    closeButton.addEventListener('click', closeDrawer);
                    closeButton.addEventListener('mouseenter', function() { closeButton.style.background = '#e8e8e8'; });
                    closeButton.addEventListener('mouseleave', function() { closeButton.style.background = '#f5f5f5'; });
                    closeContainer.appendChild(closeButton);
                    formEl.appendChild(closeContainer);
                }, 400);
            } catch (err) {
                console.warn('[WSSP Drawer] Could not apply readonly mode:', err);
            }
        }

        function resizeRTEFields(iframe) {
            try {
                var doc = iframe.contentDocument || iframe.contentWindow.document;
                if (!doc) return;

                setTimeout(function() {
                    var resizeContainers = doc.querySelectorAll('.iframe-resize .wp-editor-wrap');
                    resizeContainers.forEach(function(wrapper) {
                        var editorIframe = wrapper.querySelector('iframe[id*="field_"][id*="_ifr"]');
                        if (editorIframe) {
                            editorIframe.style.height = '320px';
                            editorIframe.style.minHeight = '140px';
                            editorIframe.style.overflowY = 'auto';
                            editorIframe.style.resize = 'vertical';
                            editorIframe.style.border = '1px solid #ddd';
                            editorIframe.style.borderRadius = '4px';
                            wrapper.style.minHeight = '460px';
                            wrapper.style.overflow = 'visible';
                        }
                    });

                    var satelliteIframe = doc.getElementById('field_wssp_satellite_description_ifr');
                    if (satelliteIframe) {
                        satelliteIframe.style.height = '320px';
                        satelliteIframe.style.minHeight = '140px';
                        satelliteIframe.style.overflowY = 'auto';
                        satelliteIframe.style.resize = 'vertical';
                    }
                }, 400);
            } catch (err) {
                console.warn('Could not access iframe content for RTE resize', err);
            }
        }

        /* ═══════════════════════════════════════════
         * CRUD MODE — Meeting Planners (no iframe)
         * ═══════════════════════════════════════════ */

        /**
         * Load the meeting planner CRUD panel via REST.
         */
        function loadCrudPanel(sessionId) {
            loading.style.display = '';
            content.innerHTML = '';
            content.style.display = 'none';
            errorEl.style.display = 'none';

            fetch(wsspData.restUrl + 'meeting-planners?session_id=' + sessionId, {
                headers: { 'X-WP-Nonce': wsspData.nonce }
            })
            .then(function(res) { return res.json(); })
            .then(function(data) {
                loading.style.display = 'none';

                if (!data.success) {
                    errorEl.style.display = '';
                    return;
                }

                content.style.display = '';
                content.innerHTML = data.html;
                bindCrudEvents();
            })
            .catch(function(err) {
                console.error('[WSSP Drawer] CRUD load error:', err);
                loading.style.display = 'none';
                errorEl.style.display = '';
            });
        }

        /**
         * Bind all event handlers for the CRUD panel.
         */
        function bindCrudEvents() {
            var panel = content.querySelector('.wssp-mp');
            if (!panel) return;

            var sessionId = panel.getAttribute('data-session-id');

            // ─── Add toggle ───
            var addToggle = panel.querySelector('#wssp-mp-add-toggle');
            var addForm   = panel.querySelector('#wssp-mp-add-form');
            if (addToggle && addForm) {
                addToggle.addEventListener('click', function() {
                    addToggle.style.display = 'none';
                    addForm.style.display = '';
                    var firstInput = addForm.querySelector('.wssp-mp__input');
                    if (firstInput) firstInput.focus();
                });
            }

            // ─── Cancel buttons (add + edit) ───
            panel.querySelectorAll('.wssp-mp__cancel-btn').forEach(function(btn) {
                btn.addEventListener('click', function() {
                    var editForm = btn.closest('.wssp-mp__row-edit');
                    if (editForm) {
                        editForm.style.display = 'none';
                        var row = btn.closest('.wssp-mp__row');
                        if (row) {
                            row.querySelector('.wssp-mp__row-display').style.display = '';
                        }
                    } else if (addForm) {
                        addForm.style.display = 'none';
                        clearFormErrors(addForm);
                        if (addToggle) addToggle.style.display = '';
                    }
                });
            });

            // ─── Edit buttons ───
            panel.querySelectorAll('.wssp-mp__edit-btn').forEach(function(btn) {
                btn.addEventListener('click', function() {
                    var row = btn.closest('.wssp-mp__row');
                    if (!row) return;

                    row.querySelector('.wssp-mp__row-display').style.display = 'none';
                    var editForm = row.querySelector('.wssp-mp__row-edit');
                    if (editForm) {
                        editForm.style.display = '';
                        var firstInput = editForm.querySelector('.wssp-mp__input');
                        if (firstInput) firstInput.focus();
                    }
                });
            });

            // ─── Delete buttons ───
            panel.querySelectorAll('.wssp-mp__delete-btn').forEach(function(btn) {
                btn.addEventListener('click', function() {
                    var row = btn.closest('.wssp-mp__row');
                    if (!row) return;

                    var entryId = row.getAttribute('data-entry-id');
                    var name = row.querySelector('.wssp-mp__name');
                    var nameText = name ? name.textContent.trim() : 'this planner';

                    if (!confirm('Delete ' + nameText + '? This cannot be undone.')) return;

                    panel.classList.add('wssp-mp--loading');

                    fetch(wsspData.restUrl + 'meeting-planners/' + entryId + '/delete?session_id=' + sessionId, {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            'X-WP-Nonce': wsspData.nonce
                        }
                    })
                    .then(function(res) { return res.json(); })
                    .then(function(data) {
                        if (data.success) {
                            content.innerHTML = data.html;
                            bindCrudEvents();
                        } else {
                            panel.classList.remove('wssp-mp--loading');
                            alert(data.message || 'Could not delete. Please try again.');
                        }
                    })
                    .catch(function() {
                        panel.classList.remove('wssp-mp--loading');
                        alert('Network error. Please try again.');
                    });
                });
            });

            // ─── Save buttons (create + update) ───
            panel.querySelectorAll('.wssp-mp__save-btn').forEach(function(btn) {
                btn.addEventListener('click', function() {
                    var action  = btn.getAttribute('data-action');
                    var entryId = btn.getAttribute('data-entry-id') || '';
                    var form    = btn.closest('.wssp-mp__add-form') || btn.closest('.wssp-mp__row-edit');
                    if (!form) return;

                    // Collect field values
                    var fields = {};
                    var hasError = false;
                    clearFormErrors(form);

                    form.querySelectorAll('.wssp-mp__field').forEach(function(fieldEl) {
                        var key   = fieldEl.getAttribute('data-field-key');
                        var input = fieldEl.querySelector('.wssp-mp__input');
                        if (!key || !input) return;

                        var value = input.value.trim();
                        fields[key] = value;

                        if (input.hasAttribute('required') && value === '') {
                            input.classList.add('wssp-mp__input--error');
                            hasError = true;
                        }

                        if (input.type === 'email' && value !== '' && value.indexOf('@') === -1) {
                            input.classList.add('wssp-mp__input--error');
                            hasError = true;
                        }
                    });

                    if (hasError) return;

                    fields.session_id = sessionId;

                    var url    = wsspData.restUrl + 'meeting-planners' + (action === 'update' ? '/' + entryId + '/update' : '');
                    var method = 'POST';

                    btn.disabled = true;
                    btn.textContent = action === 'update' ? 'Saving…' : 'Adding…';
                    panel.classList.add('wssp-mp--loading');

                    fetch(url, {
                        method: method,
                        headers: {
                            'Content-Type': 'application/json',
                            'X-WP-Nonce': wsspData.nonce
                        },
                        body: JSON.stringify(fields)
                    })
                    .then(function(res) { return res.json(); })
                    .then(function(data) {
                        if (data.success) {
                            content.innerHTML = data.html;
                            bindCrudEvents();
                        } else {
                            panel.classList.remove('wssp-mp--loading');
                            btn.disabled = false;
                            btn.textContent = action === 'update' ? 'Save Changes' : 'Add Planner';

                            if (data.errors) {
                                Object.keys(data.errors).forEach(function(fieldKey) {
                                    var fieldEl = form.querySelector('.wssp-mp__field[data-field-key="' + fieldKey + '"]');
                                    if (fieldEl) {
                                        var input = fieldEl.querySelector('.wssp-mp__input');
                                        if (input) input.classList.add('wssp-mp__input--error');
                                    }
                                });
                            }
                            if (data.message) {
                                showFormError(form, data.message);
                            }
                        }
                    })
                    .catch(function() {
                        panel.classList.remove('wssp-mp--loading');
                        btn.disabled = false;
                        btn.textContent = action === 'update' ? 'Save Changes' : 'Add Planner';
                        alert('Network error. Please try again.');
                    });
                });
            });
        }

        function clearFormErrors(form) {
            form.querySelectorAll('.wssp-mp__input--error').forEach(function(input) {
                input.classList.remove('wssp-mp__input--error');
            });
            var errorMsg = form.querySelector('.wssp-mp__error');
            if (errorMsg) errorMsg.remove();
        }

        function showFormError(form, message) {
            var existing = form.querySelector('.wssp-mp__error');
            if (existing) existing.remove();

            var errorDiv = document.createElement('div');
            errorDiv.className = 'wssp-mp__error';
            errorDiv.textContent = message;

            var fields = form.querySelector('.wssp-mp__fields');
            if (fields) {
                fields.parentNode.insertBefore(errorDiv, fields);
            } else {
                form.insertBefore(errorDiv, form.firstChild);
            }
        }

        /* ═══════════════════════════════════════════
         * UPLOAD MODE — Delegate to WSSPFilePanel
         * Shared with the admin File Review Queue page.
         * All panel rendering + REST writes live there.
         * ═══════════════════════════════════════════ */

        /**
         * Load the upload panel into the drawer via the shared module.
         *
         * We hand WSSPFilePanel our drawer's `content` element plus a
         * mutation callback that refreshes the dashboard's progress bars
         * after any successful status/upload/comment.
         */
        function loadUploadPanel(sessionId, fileType, isReadonly) {
            if (!window.WSSPFilePanel) {
                console.error('[WSSP Drawer] WSSPFilePanel module missing — check that wssp-file-panel.js is enqueued before form-drawer.js');
                errorEl.style.display = '';
                return;
            }

            // Bridge the drawer's existing loading/error UI into the
            // module so the spinner + retry button behave as before.
            var handlers = {
                showLoading: function() {
                    loading.style.display  = '';
                    content.style.display  = 'none';
                    errorEl.style.display  = 'none';
                    content.innerHTML      = '';
                },
                hideLoading: function() {
                    loading.style.display  = 'none';
                    content.style.display  = '';
                },
                showError: function() {
                    errorEl.style.display  = '';
                }
            };

            window.WSSPFilePanel.load(content, sessionId, fileType, {
                loadingHandlers: handlers,
                onAfterMutation: function() {
                    if (window.refreshDashboard) window.refreshDashboard();
                }
            });
        }
    }
})();
