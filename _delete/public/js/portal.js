/**
 * WORLDSymposium Satellite Portal — Frontend JS
 *
 * Handles:
 *   - Session selector switching
 *   - Phase accordion toggle (state persisted in sessionStorage)
 *   - Task checkbox submit (per-task via REST)
 *   - Modal open/close for Review Required + More Info
 *   - Acknowledgment checkbox in Review Required modal
 *   - refreshDashboard() — server-rendered partial swap after any mutation
 *
 * Depends on wsspData (via wp_localize_script):
 *   wsspData.restUrl    — REST API base URL
 *   wsspData.nonce      — WP REST nonce
 *   wsspData.sessionId  — Current session ID
 *   wsspData.formPageUrl — URL to the form embed page
 *
 * @package WSSP
 */
(function() {
    'use strict';

    document.addEventListener('DOMContentLoaded', init);

    function init() {
        initSessionSelector();
        initDateOverride();
        initPhaseAccordions();
        initTaskCheckboxes();
        initModals();
    }

    /* ───────────────────────────────────────────
     * SESSION SELECTOR
     * Switches session by navigating to new URL
     * ─────────────────────────────────────────── */
    function initSessionSelector() {
        var select = document.getElementById('wssp-session-select');
        if (!select) return;

        select.addEventListener('change', function() {
            var key = this.value;
            var base = this.getAttribute('data-permalink') || window.location.pathname;
            var url = base + (base.indexOf('?') > -1 ? '&' : '?') + 'session_key=' + encodeURIComponent(key);
            window.location.href = url;
        });
    }

    /* ───────────────────────────────────────────
     * DATE OVERRIDE (admin dev tool)
     * ─────────────────────────────────────────── */
    function initDateOverride() {
        var select = document.getElementById('wssp-date-override');
        if (!select) return;

        select.addEventListener('change', function() {
            var date = this.value;
            var url = new URL(window.location.href);
            if (date && date !== url.searchParams.get('wssp_date')) {
                var realToday = select.options[0] ? select.options[0].value : '';
                if (date === realToday) {
                    url.searchParams.delete('wssp_date');
                } else {
                    url.searchParams.set('wssp_date', date);
                }
                window.location.href = url.toString();
            }
        });
    }

    /* ───────────────────────────────────────────
     * PHASE ACCORDIONS
     * Collapse/expand phase bodies on header click.
     * State is persisted in sessionStorage.
     * ─────────────────────────────────────────── */
    var ACCORDION_STORAGE_KEY = 'wssp_phase_states';

    function getAccordionStates() {
        try {
            var raw = sessionStorage.getItem(ACCORDION_STORAGE_KEY);
            return raw ? JSON.parse(raw) : null;
        } catch (e) {
            return null;
        }
    }

    function saveAccordionStates() {
        try {
            var states = {};
            document.querySelectorAll('.wssp-phase').forEach(function(phase) {
                var key = phase.getAttribute('data-phase-key');
                var header = phase.querySelector('.wssp-phase__header');
                if (key && header) {
                    states[key] = header.getAttribute('aria-expanded') === 'true';
                }
            });
            sessionStorage.setItem(ACCORDION_STORAGE_KEY, JSON.stringify(states));
        } catch (e) {
            // sessionStorage not available — fail silently
        }
    }

    function initPhaseAccordions() {
        // Restore saved states if available
        var saved = getAccordionStates();
        if (saved) {
            document.querySelectorAll('.wssp-phase').forEach(function(phase) {
                var key = phase.getAttribute('data-phase-key');
                if (key && saved.hasOwnProperty(key)) {
                    var header = phase.querySelector('.wssp-phase__header');
                    var body = phase.querySelector('.wssp-phase__body');
                    if (header && body) {
                        if (saved[key]) {
                            body.style.display = '';
                            header.setAttribute('aria-expanded', 'true');
                        } else {
                            body.style.display = 'none';
                            header.setAttribute('aria-expanded', 'false');
                        }
                    }
                }
            });
        }

        document.querySelectorAll('.wssp-phase__header').forEach(function(header) {
            header.addEventListener('click', function(e) {
                if (e.target.closest('.wssp-phase__info-btn')) return;

                var phase = header.closest('.wssp-phase');
                var body = phase.querySelector('.wssp-phase__body');
                var isOpen = header.getAttribute('aria-expanded') === 'true';

                if (isOpen) {
                    body.style.display = 'none';
                    header.setAttribute('aria-expanded', 'false');
                } else {
                    body.style.display = '';
                    header.setAttribute('aria-expanded', 'true');
                }

                saveAccordionStates();
            });

            header.addEventListener('keydown', function(e) {
                if (e.key === 'Enter' || e.key === ' ') {
                    if (e.target.closest('.wssp-phase__info-btn')) return;
                    e.preventDefault();
                    header.click();
                }
            });
        });
    }

    /* ───────────────────────────────────────────
     * TASK CHECKBOXES
     * Checking a task checkbox submits via REST,
     * then calls refreshDashboard() to update all
     * mutable UI regions from server partials.
     * ─────────────────────────────────────────── */
    function initTaskCheckboxes() {
        document.querySelectorAll('.wssp-task-checkbox').forEach(function(checkbox) {
            checkbox.addEventListener('change', function() {
                var taskKey   = this.getAttribute('data-task-key');
                var sessionId = this.getAttribute('data-session-id');
                var card      = this.closest('.wssp-task-card');
                var cb        = this;

                // Admin unchecking a completed task → reactivate
                if (!this.checked && this.getAttribute('data-admin-reactivate') === '1') {
                    if (!confirm('Reactivate this task? The sponsor will need to complete it again.')) {
                        this.checked = true;
                        return;
                    }

                    cb.disabled = true;

                    apiPost('task/reactivate', {
                        session_id: sessionId,
                        task_key: taskKey
                    })
                    .then(function(data) {
                        if (data.success) {
                            refreshDashboard();
                        } else {
                            cb.checked = true;
                            cb.disabled = false;
                            alert(data.message || 'Could not reactivate this task.');
                        }
                    })
                    .catch(function() {
                        cb.checked = true;
                        cb.disabled = false;
                        alert('Network error. Please try again.');
                    });
                    return;
                }

                // Non-admin unchecking → block it
                if (!this.checked) {
                    this.checked = true;
                    return;
                }

                if (!confirm('Mark this task as complete? This action cannot be undone by you.')) {
                    this.checked = false;
                    return;
                }

                cb.disabled = true;

                apiPost('task/submit', {
                    session_id: sessionId,
                    task_key: taskKey
                })
                .then(function(data) {
                    if (data.success) {
                        // Refresh the full dashboard from server partials
                        refreshDashboard();
                    } else {
                        cb.checked = false;
                        cb.disabled = false;
                        alert(data.message || 'Could not submit this task. Please try again.');
                    }
                })
                .catch(function() {
                    cb.checked = false;
                    cb.disabled = false;
                    alert('Network error. Please try again.');
                });
            });
        });
    }

    /* ───────────────────────────────────────────
     * MODALS
     * Open/close Review Required + More Info modals
     * ─────────────────────────────────────────── */
    function initModals() {
        var backdrop = document.querySelector('.wssp-modal-backdrop');

        // Open modal
        document.querySelectorAll('.wssp-open-modal').forEach(function(btn) {
            btn.addEventListener('click', function(e) {
                e.preventDefault();
                var taskKey   = btn.getAttribute('data-task-key');
                var modalType = btn.getAttribute('data-modal-type');
                var modal = document.querySelector('.wssp-modal[data-task-key="' + taskKey + '"][data-modal-type="' + modalType + '"]');

                if (modal) {
                    modal.style.display = 'flex';
                    if (backdrop) backdrop.style.display = 'block';
                    document.body.style.overflow = 'hidden';
                }
            });
        });

        // Close modal via close button
        document.querySelectorAll('.wssp-modal__close, .wssp-modal__close-btn').forEach(function(btn) {
            btn.addEventListener('click', function() {
                closeAllModals();
            });
        });

        // Close on backdrop click
        if (backdrop) {
            backdrop.addEventListener('click', closeAllModals);
        }

        // Close on Escape key
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') closeAllModals();
        });

        // Acknowledgment checkboxes inside modals
        document.querySelectorAll('.wssp-modal__ack-checkbox').forEach(function(checkbox) {
            checkbox.addEventListener('change', function() {
                if (!this.checked) return;

                var taskKey   = this.getAttribute('data-task-key');
                var sessionId = this.getAttribute('data-session-id');
                var modal     = this.closest('.wssp-modal');
                var ackLabel  = this.closest('.wssp-modal__ack-label');

                this.disabled = true;

                apiPost('task/acknowledge', {
                    session_id: sessionId,
                    task_key: taskKey
                })
                .then(function(data) {
                    if (data.success) {
                        // Mark the modal as acknowledged
                        modal.setAttribute('data-acknowledged', 'true');

                        // Visual feedback inside the modal
                        if (ackLabel) {
                            var ackSection = ackLabel.closest('.wssp-modal__acknowledgment');
                            if (ackSection) {
                                ackSection.innerHTML =
                                    '<div class="wssp-modal__ack-confirmed">' +
                                        '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">' +
                                            '<path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/>' +
                                            '<polyline points="22 4 12 14.01 9 11.01"/>' +
                                        '</svg>' +
                                        ' Acknowledged — you may close this window.' +
                                    '</div>';
                            }
                        }

                        // Refresh the dashboard behind the modal
                        refreshDashboard();
                    } else {
                        checkbox.checked = false;
                        checkbox.disabled = false;
                        alert(data.message || 'Could not save acknowledgment. Please try again.');
                    }
                })
                .catch(function() {
                    checkbox.checked = false;
                    checkbox.disabled = false;
                    alert('Network error. Please try again.');
                });
            });
        });
    }

    function closeAllModals() {
        document.querySelectorAll('.wssp-modal').forEach(function(m) {
            // If a refresh happened while this modal was open, apply the
            // deferred partial now so the modal is fresh for next open.
            if (m._pendingPartial) {
                var pendingHtml = m._pendingPartial;
                delete m._pendingPartial;

                var temp = document.createElement('div');
                temp.innerHTML = pendingHtml.trim();
                var newModal = temp.firstElementChild;
                if (newModal) {
                    newModal.style.display = 'none';
                    m.parentNode.replaceChild(newModal, m);
                    return; // skip hiding — the new element is already hidden
                }
            }

            m.style.display = 'none';
        });
        var backdrop = document.querySelector('.wssp-modal-backdrop');
        if (backdrop) backdrop.style.display = 'none';
        document.body.style.overflow = '';

        // Re-bind modal listeners since some modals may have been replaced
        initModals();
    }

    /* ═══════════════════════════════════════════
     * refreshDashboard()
     *
     * The single function that updates all mutable UI regions.
     * Calls GET /wssp/v1/session/refresh which returns HTML
     * partials rendered by the same PHP templates as the full
     * page load. Then swaps each DOM region with a subtle fade.
     *
     * Preserves: accordion open/close state, scroll position.
     * ═══════════════════════════════════════════ */
    function refreshDashboard() {
        if (!wsspData.sessionId) {
            console.warn('[WSSP] No sessionId — cannot refresh');
            return Promise.resolve();
        }

        console.log('[WSSP] refreshDashboard() called');

        return fetch(wsspData.restUrl + 'session/refresh?session_id=' + wsspData.sessionId, {
            headers: { 'X-WP-Nonce': wsspData.nonce }
        })
        .then(function(res) { return res.json(); })
        .then(function(data) {
            if (!data.success || !data.partials) {
                console.warn('[WSSP] Refresh failed, falling back to reload');
                window.location.reload();
                return;
            }

            var p = data.partials;

            // ─── Save accordion states before swapping ───
            var accordionStates = {};
            document.querySelectorAll('.wssp-phase').forEach(function(phase) {
                var key = phase.getAttribute('data-phase-key');
                var header = phase.querySelector('.wssp-phase__header');
                if (key && header) {
                    accordionStates[key] = header.getAttribute('aria-expanded') === 'true';
                }
            });

            // ─── 1. Session overview (includes progress sidebar) ───
            if (p.session_overview) {
                var overviewRow = document.querySelector('.wssp-overview-row');
                if (overviewRow) {
                    swapWithFade(overviewRow, p.session_overview);
                }
            }

            // ─── 2. Task cards ───
            if (p.task_cards) {
                Object.keys(p.task_cards).forEach(function(taskKey) {
                    var existingCard = document.querySelector('.wssp-task-card[data-task-key="' + taskKey + '"]');
                    if (existingCard) {
                        swapWithFade(existingCard, p.task_cards[taskKey]);
                    }
                });
            }

            // ─── 3. Phase progress counters ───
            if (p.phase_progress) {
                Object.keys(p.phase_progress).forEach(function(phaseKey) {
                    var phaseEl = document.querySelector('.wssp-phase[data-phase-key="' + phaseKey + '"]');
                    if (!phaseEl) return;

                    var metaEl = phaseEl.querySelector('.wssp-phase__meta');
                    if (metaEl) {
                        metaEl.innerHTML = p.phase_progress[phaseKey].html;
                    }
                });
            }

            // ─── 4. Task modals — swap so modal type stays in sync ───
            // Skip any modal that is currently open (display: flex) so we
            // don't yank the modal out from under the user mid-read.
            if (p.task_modals) {
                Object.keys(p.task_modals).forEach(function(taskKey) {
                    var existingModal = document.querySelector('.wssp-modal[data-task-key="' + taskKey + '"]');
                    if (existingModal) {
                        var isOpen = existingModal.style.display === 'flex';
                        if (isOpen) {
                            // Modal is visible — defer the swap. Store the
                            // fresh HTML so closeAllModals() can apply it.
                            existingModal._pendingPartial = p.task_modals[taskKey];
                        } else {
                            swapWithFade(existingModal, p.task_modals[taskKey]);
                        }
                    }
                });
            }

            // ─── 5. Restore accordion states ───
            Object.keys(accordionStates).forEach(function(key) {
                var phase = document.querySelector('.wssp-phase[data-phase-key="' + key + '"]');
                if (!phase) return;

                var header = phase.querySelector('.wssp-phase__header');
                var body = phase.querySelector('.wssp-phase__body');
                if (!header || !body) return;

                if (accordionStates[key]) {
                    body.style.display = '';
                    header.setAttribute('aria-expanded', 'true');
                } else {
                    body.style.display = 'none';
                    header.setAttribute('aria-expanded', 'false');
                }
            });

            // ─── 6. Re-bind event listeners on swapped elements ───
            rebindAfterRefresh();

            console.log('[WSSP] Dashboard refresh complete');
        })
        .catch(function(err) {
            console.error('[WSSP] Refresh error:', err);
            // Don't reload on network errors — the page is still usable
        });
    }

    // Expose refreshDashboard globally for form-drawer.js
    window.refreshDashboard = refreshDashboard;

    /**
     * Swap an existing DOM element with new HTML, using a subtle fade.
     * The new HTML replaces the element in-place.
     */
    function swapWithFade(existingEl, newHtml) {
        // Parse the new HTML into a DOM element
        var temp = document.createElement('div');
        temp.innerHTML = newHtml.trim();
        var newEl = temp.firstElementChild;

        if (!newEl) return;

        // Apply the fade transition
        newEl.style.opacity = '0';
        newEl.style.transition = 'opacity 0.25s ease';

        existingEl.parentNode.replaceChild(newEl, existingEl);

        // Trigger reflow, then fade in
        newEl.offsetHeight; // force reflow
        newEl.style.opacity = '1';

        // Clean up the inline style after transition
        setTimeout(function() {
            newEl.style.removeProperty('opacity');
            newEl.style.removeProperty('transition');
        }, 300);
    }

    /**
     * Re-bind event listeners after DOM elements have been swapped.
     * Called after refreshDashboard() replaces task cards and overview.
     */
    function rebindAfterRefresh() {
        // Re-bind task checkboxes
        initTaskCheckboxes();

        // Re-bind modal open buttons (both task and phase info buttons)
        initModals();

        // Re-bind form drawer open buttons (handled by form-drawer.js)
        if (window.rebindFormDrawerButtons) {
            window.rebindFormDrawerButtons();
        }

        // Re-bind phase accordion click handlers
        document.querySelectorAll('.wssp-phase__header').forEach(function(header) {
            // Remove existing listeners by cloning (clean approach)
            // Skip — we preserve accordion state via accordionStates above
            // The headers aren't swapped, only the bodies' task cards are
        });
    }

    /* ───────────────────────────────────────────
     * HELPERS
     * ─────────────────────────────────────────── */

    /**
     * POST to a WSSP REST endpoint.
     */
    function apiPost(endpoint, data) {
        return fetch(wsspData.restUrl + endpoint, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-WP-Nonce': wsspData.nonce
            },
            body: JSON.stringify(data)
        })
        .then(function(res) { return res.json(); });
    }

    /**
     * Update phase progress count.
     * Kept as a fallback for edge cases, but refreshDashboard()
     * now handles this via server partials.
     */
    function updatePhaseProgress(phaseEl) {
        if (!phaseEl) return;

        var cards = phaseEl.querySelectorAll('.wssp-task-card');
        var total = 0;
        var done  = 0;

        cards.forEach(function(card) {
            if (card.style.display === 'none') return;
            if (!card.querySelector('.wssp-task-checkbox') && !card.querySelector('.wssp-task-card__checkbox-spacer')) return;
            if (card.querySelector('.wssp-task-checkbox')) {
                total++;
                if (card.classList.contains('wssp-task-card--done')) done++;
            }
        });

        var progressEl = phaseEl.querySelector('.wssp-phase__progress');
        if (progressEl && total > 0) {
            progressEl.innerHTML = '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg> ' + done + '/' + total + ' completed';
            if (done === total) {
                progressEl.classList.add('wssp-phase__progress--done');
            } else {
                progressEl.classList.remove('wssp-phase__progress--done');
            }
        }
    }

    // Expose for fallback use
    window.updatePhaseProgress = updatePhaseProgress;

})();
