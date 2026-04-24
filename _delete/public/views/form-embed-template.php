<?php
/**
 * Template Name: WSSP Form Embed
 *
 * Lightweight page template that renders a single Formidable form inside the drawer iframe.
 *
 * Supports:
 *   ?form_key=wssp-sat-session-data&session_key=xxx
 *   ?form_key=wssp-sat-session-data&fields=wssp_program_title,wssp_satellite_description&session_key=xxx
 *
 * For multi-entry forms (e.g., meeting planners), renders the existing entries
 * list via [display-frm-data] above the add-new form. Inline edit updates
 * trigger a full page reload to restore the filtered view.
 *
 * @package WSSP
 */

defined( 'ABSPATH' ) || exit;

// ─── Auth check ───
if ( ! is_user_logged_in() ) {
    wp_die( 'Please log in to access this form.', 'Login Required', array( 'response' => 403 ) );
}

// ─── Parameters ───
$form_key    = sanitize_text_field( $_GET['form_key'] ?? '' );
$fields      = sanitize_text_field( $_GET['fields'] ?? '' );   // comma-separated field keys
$session_key = sanitize_text_field( $_GET['session_key'] ?? '' );
$file_type   = sanitize_text_field( $_GET['file_type'] ?? '' );

if ( empty( $form_key ) || empty( $session_key ) ) {
    wp_die( 'Missing required parameters.', 'Error', array( 'response' => 400 ) );
}

// ─── Whitelist ───
$allowed_prefixes = array( 'wssp-sat-', 'wssp-iet-' );
$is_allowed = false;
foreach ( $allowed_prefixes as $prefix ) {
    if ( strpos( $form_key, $prefix ) === 0 ) {
        $is_allowed = true;
        break;
    }
}
if ( ! $is_allowed ) {
    wp_die( 'Invalid form.', 'Error', array( 'response' => 400 ) );
}

// ─── Session access check ───
$access = new WSSP_Session_Access();
$session = $access->get_session_by_key( $session_key );

if ( ! $session ) {
    wp_die( 'Session not found.', 'Error', array( 'response' => 404 ) );
}

$user_id = get_current_user_id();
if ( ! $access->user_can_access( $user_id, $session['id'] ) ) {
    wp_die( 'You do not have access to this session.', 'Access Denied', array( 'response' => 403 ) );
}

// ─── Get Form ID ───
$form_id = 0;
if ( class_exists( 'FrmForm' ) ) {
    $form = FrmForm::getOne( $form_key );
    if ( $form ) {
        $form_id = $form->id;
    }
}

// ─── Find existing entry (for single-entry forms like session data) ───
$entry_id = 0;
if ( $form_id && class_exists( 'FrmField' ) && class_exists( 'FrmEntry' ) ) {
    global $wpdb;

    $session_key_field_id = $wpdb->get_var( $wpdb->prepare(
        "SELECT id FROM {$wpdb->prefix}frm_fields
         WHERE form_id = %d
         AND (field_key LIKE '%%session_key%%' OR field_key = 'wssp_sat_key')
         LIMIT 1",
        $form_id
    ));

    if ( ! $session_key_field_id ) {
        $parent_form_id = $wpdb->get_var( $wpdb->prepare(
            "SELECT parent_form_id FROM {$wpdb->prefix}frm_forms WHERE id = %d",
            $form_id
        ));
        if ( $parent_form_id ) {
            $session_key_field_id = $wpdb->get_var( $wpdb->prepare(
                "SELECT id FROM {$wpdb->prefix}frm_fields
                 WHERE form_id = %d
                 AND (field_key LIKE '%%session_key%%' OR field_key = 'wssp_sat_key')
                 LIMIT 1",
                $parent_form_id
            ));
            if ( $session_key_field_id ) {
                $form_id = $parent_form_id;
            }
        }
    }

    if ( $session_key_field_id ) {
        $entry_id = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT e.id
             FROM {$wpdb->prefix}frm_items e
             INNER JOIN {$wpdb->prefix}frm_item_metas m ON (e.id = m.item_id)
             WHERE e.form_id = %d
             AND m.field_id = %d
             AND m.meta_value = %s
             AND e.is_draft = 0
             ORDER BY e.id DESC
             LIMIT 1",
            $form_id,
            $session_key_field_id,
            $session_key
        ));
    }
}

// ─── Multi-entry form detection ───
// Forms that support multiple entries per session (e.g., meeting planners)
// get a list view above the add-new form.
$multi_entry_views = array(
    'wssp-sat-meeting-planners' => 2167,
    // Add other multi-entry form_key => view_id mappings here
);
$is_multi_entry = isset( $multi_entry_views[ $form_key ] );
$view_id        = $multi_entry_views[ $form_key ] ?? 0;

// ─── Build the form shortcode ───
$shortcode = '[formidable key="' . esc_attr( $form_key ) . '"';

if ( ! empty( $fields ) ) {
    $clean_fields = trim( $fields, ', ' );
    $shortcode .= ' fields="' . esc_attr( $clean_fields ) . '"';
}

// Only attach entry_id for single-entry forms (edit existing entry)
// Multi-entry forms always render as a new entry form
if ( $entry_id && ! $is_multi_entry ) {
    $shortcode .= ' entry_id="' . esc_attr( $entry_id ) . '"';
}

$shortcode .= ']';

?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
    <meta charset="<?php bloginfo( 'charset' ); ?>">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <?php wp_head(); ?>
    <style>
        body {
            margin: 0;
            padding: 20px 24px;
            background: #fff;
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
            font-size: 14px;
            color: #333;
            line-height: 1.5;
        }
        #wpadminbar { display: none !important; }
        html { margin-top: 0 !important; }
        .frm_forms { max-width: 100%; }

        .frm_message,
        .frm_message p {
            background: #f0fdf4;
            border: 1px solid #bbf7d0;
            color: #16a34a;
            padding: 16px;
            border-radius: 8px;
            font-size: 14px;
            line-height: 1.5;
        }
        .frm_error_style {
            background: #fef2f2;
            border: 1px solid #fecaca;
            color: #dc2626;
            padding: 12px 16px;
            border-radius: 8px;
            margin-bottom: 16px;
        }

        /* ─── Multi-entry list styles ─── */
        .wssp-entry-list {
            margin-bottom: 8px;
        }
        .wssp-entry-list hr {
            margin: 24px 0;
            border: none;
            border-top: 1px solid #e5e5e5;
        }

        /* Cancel button next to Update */
        .wssp-cancel-edit {
            margin-left: 8px;
            padding: 6px 16px;
            background: #f5f5f5;
            border: 1px solid #ddd;
            border-radius: 4px;
            cursor: pointer;
            font-size: 13px;
            line-height: 1.5;
            vertical-align: middle;
        }
        .wssp-cancel-edit:hover {
            background: #e8e8e8;
            border-color: #ccc;
        }
    </style>
</head>
<body>

<?php
// ─── Render multi-entry list view (if applicable) ───
if ( $is_multi_entry && $view_id ) {
    echo '<div class="wssp-entry-list">';
    echo do_shortcode( '[display-frm-data id=' . intval( $view_id ) . ' filter=limited wpautop=0]' );
    echo '<hr>';
    echo '</div>';
}

// ─── Render the form ───
echo do_shortcode( $shortcode );
?>

<?php wp_footer(); ?>

<script>
/**
 * WSSP Form Embed — Parent notifications & multi-entry helpers.
 */
(function() {
    'use strict';

    var isInIframe = window.parent !== window;
    var isMultiEntry = <?php echo $is_multi_entry ? 'true' : 'false'; ?>;

    console.log('[WSSP Form Embed] Script loaded. In iframe:', isInIframe, 'multiEntry:', isMultiEntry, 'jQuery:', typeof jQuery !== 'undefined');

    /* ═══════════════════════════════════════════
     * PARENT NOTIFICATION
     * Tells the drawer when a form is submitted
     * so the task card can update its status.
     * ═══════════════════════════════════════════ */

    function notifyParent() {
        if (!isInIframe) return;
        console.log('[WSSP Form Embed] Sending wssp_form_submitted to parent');
        window.parent.postMessage({
            type: 'wssp_form_submitted',
            formKey: '<?php echo esc_js( $form_key ); ?>'
        }, '*');
    }

    // Method 1: Success message already on page (full-page reload after submit)
    var existingMessage = document.querySelector('.frm_message, .frm_message_text, .with_frm_style .frm_message');
    if (existingMessage) {
        console.log('[WSSP Form Embed] SUCCESS — found existing .frm_message on page load');
        notifyParent();
        if (!isMultiEntry) return; // Single-entry forms can stop here
    }

    // Method 2: MutationObserver — watch for .frm_message being added
    var submitObserver = new MutationObserver(function(mutations) {
        for (var i = 0; i < mutations.length; i++) {
            var mutation = mutations[i];
            for (var j = 0; j < mutation.addedNodes.length; j++) {
                var node = mutation.addedNodes[j];
                if (node.nodeType === 1 && node.classList &&
                    (node.classList.contains('frm_message') ||
                     node.classList.contains('frm_message_text') ||
                     (node.querySelector && node.querySelector('.frm_message')))) {

                    console.log('[WSSP Form Embed] SUCCESS — .frm_message added to DOM');
                    notifyParent();
                    submitObserver.disconnect();

                    // Multi-entry: reload to show updated list
                    if (isMultiEntry) {
                        setTimeout(function() { window.location.reload(); }, 800);
                    }
                    return;
                }
            }
        }
    });
    submitObserver.observe(document.body, { childList: true, subtree: true });

    // Method 3: Formidable jQuery events
    if (typeof jQuery !== 'undefined') {
        jQuery(document).on('frmFormComplete frmAfterSubmit', function() {
            console.log('[WSSP Form Embed] SUCCESS — Formidable jQuery event fired');
            notifyParent();

            if (isMultiEntry) {
                setTimeout(function() { window.location.reload(); }, 800);
            }
        });
    }

    // Method 4: Poll after submit button click
    document.addEventListener('click', function(e) {
        var btn = e.target.closest('.frm_submit button, .frm_submit input[type="submit"]');
        if (!btn) return;
        console.log('[WSSP Form Embed] Submit clicked — polling for success');

        var pollCount = 0;
        var pollInterval = setInterval(function() {
            pollCount++;
            var msg = document.querySelector('.frm_message, .frm_message_text, .with_frm_style .frm_message');
            if (msg) {
                console.log('[WSSP Form Embed] SUCCESS — found via polling');
                clearInterval(pollInterval);
                notifyParent();

                if (isMultiEntry) {
                    setTimeout(function() { window.location.reload(); }, 800);
                }
            }
            if (pollCount > 30) {
                clearInterval(pollInterval);
            }
        }, 500);
    });

    /* ═══════════════════════════════════════════
     * MULTI-ENTRY: INLINE EDIT HELPERS
     * Adds a Cancel button and reloads after
     * inline edit updates to restore the filtered
     * list view.
     * ═══════════════════════════════════════════ */

    if (!isMultiEntry) return;

    // ─── Inject Cancel button when inline edit form loads ───
    document.addEventListener('click', function(e) {
        var editLink = e.target.closest('.frm_inplace_edit');
        if (!editLink) return;

        var entryId = editLink.dataset.entryid;
        if (!entryId) return;

        // Wait for Formidable to load the edit form via AJAX
        setTimeout(function() {
            var container = document.getElementById('frm_container_' + entryId);
            if (!container) return;

            // Don't add duplicate cancel buttons
            if (container.querySelector('.wssp-cancel-edit')) return;

            var submitDiv = container.querySelector('.frm_submit');
            if (!submitDiv) return;

            var cancelBtn = document.createElement('button');
            cancelBtn.type = 'button';
            cancelBtn.className = 'wssp-cancel-edit';
            cancelBtn.textContent = 'Cancel';
            cancelBtn.addEventListener('click', function() {
                window.location.reload();
            });

            submitDiv.appendChild(cancelBtn);
        }, 500);
    });

    // ─── Reload after inline edit update to restore filtered list ───
    // Formidable replaces the edit form with the view content after
    // a successful update, but loses the session_key filter.
    // A full reload hits the iframe URL which has session_key intact.
    var entryList = document.querySelector('.wssp-entry-list');
    if (entryList) {
        var editObserver = new MutationObserver(function(mutations) {
            for (var i = 0; i < mutations.length; i++) {
                var mutation = mutations[i];

                // Look for the edit form being removed (replaced with view content)
                for (var j = 0; j < mutation.removedNodes.length; j++) {
                    var node = mutation.removedNodes[j];
                    if (node.nodeType === 1 && node.querySelector &&
                        (node.querySelector('form') || node.querySelector('.frm_forms'))) {

                        console.log('[WSSP Form Embed] Inline edit form removed — reloading');
                        notifyParent();
                        setTimeout(function() { window.location.reload(); }, 500);
                        editObserver.disconnect();
                        return;
                    }
                }
            }
        });
        editObserver.observe(entryList, { childList: true, subtree: true });
    }
})();
</script>
</body>
</html>