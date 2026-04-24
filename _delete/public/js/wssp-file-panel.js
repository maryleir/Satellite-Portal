/**
 * WSSP File Panel — container-agnostic upload + review UI.
 *
 * Runs the file-review panel (version history, status change, comments,
 * new upload) inside ANY DOM container. The sponsor-facing portal uses
 * this from the form-drawer; the admin File Review Queue uses it inside
 * an inline expansion row. Both call the same REST endpoints and render
 * the same server-generated HTML, so every review action behaves
 * identically in both contexts.
 *
 * Public API:
 *   WSSPFilePanel.load(rootEl, sessionId, fileType, opts)
 *   WSSPFilePanel.bind(rootEl, sessionId, fileType, opts)
 *
 * `rootEl` is any element that will own the panel HTML (its innerHTML
 * is replaced on load and on each successful mutation). `opts` is an
 * optional object:
 *     onAfterMutation  — function called after any successful status/
 *                         comment/upload that re-rendered the panel.
 *                         The portal passes refreshDashboard here; the
 *                         admin page passes its own refresher.
 *     loadingHandlers  — { showLoading, hideLoading, showError } for
 *                         callers who want custom spinner/error UI.
 *                         Defaults render a minimal inline spinner.
 *
 * Depends on the global `wsspData` being present (restUrl + nonce).
 * Both consumers (public shortcode, admin queue page) localize it.
 *
 * @package WSSP
 */
(function(global) {
    'use strict';

    /**
     * Default loading/error UI — minimal, inline, works in any container.
     * Callers can override via opts.loadingHandlers.
     */
    function defaultHandlers(rootEl) {
        return {
            showLoading: function() {
                rootEl.innerHTML =
                    '<div class="wssp-file-panel__loading" style="padding:16px;color:#646970;">' +
                    '<em>Loading file panel…</em></div>';
            },
            hideLoading: function() { /* innerHTML replace clears it */ },
            showError: function(msg) {
                rootEl.innerHTML =
                    '<div class="wssp-file-panel__error" style="padding:16px;color:#b32d2e;">' +
                    (msg || 'Could not load the file panel. Please reload and try again.') +
                    '</div>';
            }
        };
    }

    /**
     * Resolve `wsspData` strictly — we want a loud failure if a caller
     * forgot to localize it, because every REST call depends on it.
     */
    function requireData() {
        if (typeof global.wsspData === 'undefined') {
            throw new Error('[WSSP File Panel] wsspData not found — did the page forget to localize it?');
        }
        return global.wsspData;
    }

    /**
     * After any successful write, swap in the new panel HTML and
     * re-bind all handlers. Optionally notify the caller so it can
     * refresh surrounding UI (e.g. the portal dashboard progress bars
     * or the admin queue's aging column).
     */
    function replacePanelHtml(rootEl, html, sessionId, fileType, opts) {
        rootEl.innerHTML = html;
        bind(rootEl, sessionId, fileType, opts);
        if (opts && typeof opts.onAfterMutation === 'function') {
            opts.onAfterMutation();
        }
    }

    /**
     * Load the panel HTML via REST and render it into rootEl.
     */
    function load(rootEl, sessionId, fileType, opts) {
        opts = opts || {};
        var handlers = opts.loadingHandlers || defaultHandlers(rootEl);
        var data = requireData();

        handlers.showLoading();

        fetch(
            data.restUrl +
                'file-uploads?session_id=' + encodeURIComponent(sessionId) +
                '&file_type=' + encodeURIComponent(fileType),
            { headers: { 'X-WP-Nonce': data.nonce } }
        )
        .then(function(res) { return res.json(); })
        .then(function(body) {
            handlers.hideLoading();
            if (!body || !body.success) {
                handlers.showError(body && body.message);
                return;
            }
            rootEl.innerHTML = body.html || '';
            bind(rootEl, sessionId, fileType, opts);
        })
        .catch(function(err) {
            handlers.hideLoading();
            handlers.showError();
            // Don't swallow — surface for debugging while keeping UX clean.
            if (global.console && console.error) {
                console.error('[WSSP File Panel] load error:', err);
            }
        });
    }

    /**
     * Bind every interactive handler on the panel currently inside rootEl.
     * Called on initial load, and again after every mutation that replaces
     * the panel HTML. Handlers are scoped to rootEl so multiple panels can
     * coexist on the same page without interfering.
     */
    function bind(rootEl, sessionId, fileType, opts) {
        opts = opts || {};
        var data = requireData();

        var panel        = rootEl.querySelector('.wssp-upload');
        if (!panel) return;

        var dropzone     = rootEl.querySelector('#wssp-dropzone');
        var fileInput    = rootEl.querySelector('#wssp-file-input');
        var browse       = rootEl.querySelector('.wssp-upload__browse');
        var staged       = rootEl.querySelector('#wssp-staged');
        var stagedName   = rootEl.querySelector('#wssp-staged-filename');
        var stagedRemove = rootEl.querySelector('#wssp-staged-remove');
        var stagedSubmit = rootEl.querySelector('#wssp-staged-submit');
        var pendingFile  = null;

        function stageFile(file) {
            pendingFile = file;
            if (stagedName) stagedName.textContent = file.name;
            if (dropzone)   dropzone.style.display = 'none';
            if (staged)     staged.style.display = '';
        }

        // ─── Dropzone events ───
        if (dropzone && fileInput) {
            if (browse) {
                browse.addEventListener('click', function(e) {
                    e.stopPropagation();
                    fileInput.click();
                });
            }
            dropzone.addEventListener('click', function(e) {
                if (e.target === fileInput || e.target === browse) return;
                fileInput.click();
            });
            fileInput.addEventListener('change', function() {
                if (fileInput.files && fileInput.files.length > 0) {
                    stageFile(fileInput.files[0]);
                }
            });
            dropzone.addEventListener('dragover', function(e) {
                e.preventDefault();
                e.stopPropagation();
                dropzone.classList.add('wssp-upload__dropzone--dragover');
            });
            dropzone.addEventListener('dragleave', function(e) {
                e.preventDefault();
                e.stopPropagation();
                dropzone.classList.remove('wssp-upload__dropzone--dragover');
            });
            dropzone.addEventListener('drop', function(e) {
                e.preventDefault();
                e.stopPropagation();
                dropzone.classList.remove('wssp-upload__dropzone--dragover');
                if (e.dataTransfer.files && e.dataTransfer.files.length > 0) {
                    stageFile(e.dataTransfer.files[0]);
                }
            });
        }

        // ─── Staged: remove file ───
        if (stagedRemove) {
            stagedRemove.addEventListener('click', function() {
                pendingFile = null;
                if (fileInput) fileInput.value = '';
                if (staged)    staged.style.display = 'none';
                if (dropzone)  dropzone.style.display = '';
            });
        }

        // ─── Staged: upload file ───
        if (stagedSubmit) {
            stagedSubmit.addEventListener('click', function() {
                if (!pendingFile) return;
                stagedSubmit.disabled = true;
                stagedSubmit.textContent = 'Uploading…';
                uploadFile(rootEl, sessionId, fileType, pendingFile, opts);
            });
        }

        // ─── Status change: show/hide change-note textarea ───
        panel.querySelectorAll('.wssp-upload__status-select').forEach(function(select) {
            var entryId  = select.getAttribute('data-entry-id');
            var textarea = panel.querySelector('.wssp-upload__change-note[data-entry-id="' + entryId + '"]');
            if (!textarea) return;

            function toggleTextarea() {
                if (select.value.indexOf('Changes Required') !== -1) {
                    textarea.style.display = 'block';
                    textarea.required = true;
                } else {
                    textarea.style.display = 'none';
                    textarea.required = false;
                    textarea.value = '';
                }
            }
            toggleTextarea();
            select.addEventListener('change', toggleTextarea);
        });

        // ─── Status change: save ───
        panel.querySelectorAll('.wssp-upload__status-save').forEach(function(btn) {
            btn.addEventListener('click', function() {
                var entryId  = btn.getAttribute('data-entry-id');
                var select   = panel.querySelector('.wssp-upload__status-select[data-entry-id="' + entryId + '"]');
                var textarea = panel.querySelector('.wssp-upload__change-note[data-entry-id="' + entryId + '"]');
                if (!select) return;

                var changeNote        = textarea ? textarea.value.trim() : '';
                var isChangesRequired = select.value.indexOf('Changes Required') !== -1;

                if (isChangesRequired && !changeNote) {
                    if (textarea) {
                        textarea.focus();
                        textarea.style.borderColor = '#ef4444';
                    }
                    alert('Please describe what changes are needed.');
                    return;
                }

                btn.disabled = true;
                btn.textContent = 'Saving…';

                var payload = { session_id: sessionId, status: select.value };
                if (changeNote) payload.change_note = changeNote;

                fetch(data.restUrl + 'file-uploads/' + entryId + '/status', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-WP-Nonce': data.nonce
                    },
                    body: JSON.stringify(payload)
                })
                .then(function(res) { return res.json(); })
                .then(function(body) {
                    if (body.success && body.html) {
                        replacePanelHtml(rootEl, body.html, sessionId, fileType, opts);
                    } else {
                        btn.disabled = false;
                        btn.textContent = 'Update';
                        alert(body.message || 'Could not update status.');
                    }
                })
                .catch(function() {
                    btn.disabled = false;
                    btn.textContent = 'Update';
                    alert('Network error. Please try again.');
                });
            });
        });

        // ─── VBI (Virtual Bag Insert) save ───
        panel.querySelectorAll('.wssp-upload__vbi-save').forEach(function(btn) {
            btn.addEventListener('click', function() {
                var selected = panel.querySelector('input[name="wssp_virtual_bag_insert_file"]:checked');
                if (!selected) return;

                btn.disabled = true;
                btn.textContent = 'Saving…';

                fetch(data.restUrl + 'file-uploads/vbi', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-WP-Nonce': data.nonce
                    },
                    body: JSON.stringify({ session_id: sessionId, vbi_value: selected.value })
                })
                .then(function(res) { return res.json(); })
                .then(function(body) {
                    if (body.success && body.html) {
                        replacePanelHtml(rootEl, body.html, sessionId, fileType, opts);
                    } else {
                        btn.disabled = false;
                        btn.textContent = 'Save Selection';
                        alert(body.message || 'Could not save selection.');
                    }
                })
                .catch(function() {
                    btn.disabled = false;
                    btn.textContent = 'Save Selection';
                    alert('Network error. Please try again.');
                });
            });
        });

        // ─── Add a note (comment Post button) ───
        panel.querySelectorAll('.wssp-upload__note-submit').forEach(function(btn) {
            btn.addEventListener('click', function() {
                var entryId = btn.getAttribute('data-entry-id');
                var input   = panel.querySelector('.wssp-upload__note-add-input[data-entry-id="' + entryId + '"]');
                if (!input || !input.value.trim()) return;

                btn.disabled = true;
                btn.textContent = 'Posting…';

                fetch(data.restUrl + 'file-uploads/' + entryId + '/comments', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-WP-Nonce': data.nonce
                    },
                    body: JSON.stringify({ session_id: sessionId, comment: input.value.trim() })
                })
                .then(function(res) { return res.json(); })
                .then(function(body) {
                    if (body.success && body.html) {
                        replacePanelHtml(rootEl, body.html, sessionId, fileType, opts);
                    } else {
                        btn.disabled = false;
                        btn.textContent = 'Post';
                        alert(body.message || 'Could not post note.');
                    }
                })
                .catch(function() {
                    btn.disabled = false;
                    btn.textContent = 'Post';
                    alert('Network error. Please try again.');
                });
            });

            // Enter key submits the note (Shift+Enter for newline)
            var input = panel.querySelector(
                '.wssp-upload__note-add-input[data-entry-id="' + btn.getAttribute('data-entry-id') + '"]'
            );
            if (input) {
                input.addEventListener('keydown', function(e) {
                    if (e.key === 'Enter' && !e.shiftKey) {
                        e.preventDefault();
                        btn.click();
                    }
                });
            }
        });
    }

    /**
     * Upload a file via XHR with progress tracking.
     * Kept separate from the REST writes because only this path needs
     * multipart + progress events.
     */
    function uploadFile(rootEl, sessionId, fileType, file, opts) {
        var data = requireData();

        var dropContent  = rootEl.querySelector('.wssp-upload__dropzone-content');
        var progressEl   = rootEl.querySelector('.wssp-upload__progress');
        var progressFill = rootEl.querySelector('.wssp-upload__progress-fill');
        var progressText = rootEl.querySelector('.wssp-upload__progress-text');
        var noteInput    = rootEl.querySelector('#wssp-upload-note');

        if (!progressEl) return;

        if (dropContent)  dropContent.style.display = 'none';
        progressEl.style.display = '';
        progressFill.style.width = '0%';
        progressText.textContent = 'Uploading ' + file.name + '…';

        var formData = new FormData();
        formData.append('file', file);
        formData.append('session_id', sessionId);
        formData.append('file_type', fileType);
        if (noteInput && noteInput.value.trim()) {
            formData.append('note', noteInput.value.trim());
        }

        var xhr = new XMLHttpRequest();

        xhr.upload.addEventListener('progress', function(e) {
            if (e.lengthComputable) {
                var pct = Math.round((e.loaded / e.total) * 100);
                progressFill.style.width = pct + '%';
                progressText.textContent = 'Uploading ' + file.name + '… ' + pct + '%';
            }
        });

        xhr.addEventListener('load', function() {
            var body;
            try { body = JSON.parse(xhr.responseText); } catch (e) { body = null; }

            if (xhr.status >= 200 && xhr.status < 300 && body && body.success) {
                progressFill.style.width = '100%';
                progressText.textContent = 'Upload complete!';

                if (opts && typeof opts.onAfterMutation === 'function') {
                    opts.onAfterMutation();
                }

                // Brief success pause, then swap in the fresh panel HTML.
                setTimeout(function() {
                    if (body.html) {
                        replacePanelHtml(rootEl, body.html, sessionId, fileType, opts);
                    } else {
                        load(rootEl, sessionId, fileType, opts);
                    }
                }, 600);
            } else {
                var msg = (body && body.message) ? body.message : 'Upload failed. Please try again.';
                progressText.textContent = msg;
                progressFill.style.width = '0%';

                // Server told us the file was approved mid-upload — reload
                // to show the approved-state panel.
                if (body && body.approved) {
                    if (opts && typeof opts.onAfterMutation === 'function') {
                        opts.onAfterMutation();
                    }
                    setTimeout(function() {
                        load(rootEl, sessionId, fileType, opts);
                    }, 1500);
                } else {
                    setTimeout(function() {
                        if (dropContent) dropContent.style.display = '';
                        progressEl.style.display = 'none';
                        var staged = rootEl.querySelector('#wssp-staged');
                        if (staged) staged.style.display = '';
                    }, 3000);
                }
            }
        });

        xhr.addEventListener('error', function() {
            progressText.textContent = 'Network error. Please try again.';
            setTimeout(function() {
                if (dropContent) dropContent.style.display = '';
                progressEl.style.display = 'none';
            }, 3000);
        });

        xhr.open('POST', data.restUrl + 'file-uploads');
        xhr.setRequestHeader('X-WP-Nonce', data.nonce);
        xhr.send(formData);
    }

    /* ─── Public surface ─── */
    global.WSSPFilePanel = {
        load: load,
        bind: bind
    };

})(window);
