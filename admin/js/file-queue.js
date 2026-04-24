/**
 * WSSP File Review Queue — admin-side bootstrap.
 *
 * Wires the per-row "Review" buttons on the File Review Queue page to
 * inline-expand a review panel below the row. The panel itself is
 * rendered and managed by the shared WSSPFilePanel module — the SAME
 * module the public portal uses for its file drawer. So any change
 * to review behavior (status, comments, upload, VBI) is automatically
 * reflected here; we don't maintain a second implementation.
 *
 * This file is intentionally thin: it handles only the expand/collapse
 * toggle and the "load panel on first expand" bridge. Everything else
 * is in wssp-file-panel.js.
 *
 * @package WSSP
 */
(function() {
    'use strict';

    document.addEventListener('DOMContentLoaded', function() {
        if (typeof window.WSSPFilePanel === 'undefined') {
            console.error('[WSSP File Queue] WSSPFilePanel not loaded — check enqueue order.');
            return;
        }

        // Event delegation — one listener on the table handles every row,
        // so rows added dynamically (if we ever do ajax pagination) still
        // work without rebinding.
        var table = document.querySelector('.wssp-frq__table');
        if (!table) return;

        table.addEventListener('click', function(e) {
            var btn = e.target.closest('.wssp-frq__review-toggle');
            if (!btn) return;
            e.preventDefault();
            toggle(btn);
        });
    });

    /**
     * Expand or collapse the review panel for a row.
     * On first expand, fetches and renders the panel. Subsequent expands
     * reuse the already-rendered panel (which has stayed live since its
     * last render; status/comments update it in place).
     */
    function toggle(btn) {
        var targetId = btn.getAttribute('data-expand-target');
        var row      = document.getElementById(targetId);
        if (!row) return;

        var isOpen = !row.hidden;
        if (isOpen) {
            row.hidden = true;
            btn.setAttribute('aria-expanded', 'false');
            btn.textContent = 'Review';
            return;
        }

        row.hidden = false;
        btn.setAttribute('aria-expanded', 'true');
        btn.textContent = 'Hide';

        var host = row.querySelector('.wssp-frq__panel-host');
        if (!host) return;

        // First-time expand → load. Flag on the element so re-expanding
        // after status-change mutations doesn't blow away the live panel.
        if (host.dataset.loaded === '1') return;
        host.dataset.loaded = '1';

        var sessionId = parseInt(host.getAttribute('data-session-id'), 10);
        var fileType  = host.getAttribute('data-file-type');

        window.WSSPFilePanel.load(host, sessionId, fileType, {
            onAfterMutation: function() {
                // No equivalent of the portal's dashboard refresh here,
                // but we DO want the queue row's visible status badge to
                // catch up. Simplest honest approach: do nothing in-place
                // and let the planner reload the queue if they want updated
                // aging/status counters. A future enhancement could re-fetch
                // the single row, but that's beyond v1 scope.
            }
        });
    }
})();
