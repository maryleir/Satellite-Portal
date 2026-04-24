<?php
/**
 * Form Drawer — Right-side sliding panel for Formidable forms.
 *
 * This renders a single reusable drawer container at the bottom of
 * the dashboard. When a sponsor clicks "Open Form", JavaScript loads
 * the form into this drawer via the REST API.
 *
 * @package WSSP
 */

defined( 'ABSPATH' ) || exit;
?>

<!-- Form Drawer Backdrop -->
<div class="wssp-drawer-backdrop" style="display:none;"></div>

<!-- Form Drawer Panel -->
<div class="wssp-drawer" style="display:none;" aria-hidden="true">
    <div class="wssp-drawer__header">
        <div class="wssp-drawer__header-left">
            <h2 class="wssp-drawer__title"></h2>
            <span class="wssp-drawer__subtitle"></span>
        </div>
        <button class="wssp-drawer__close" aria-label="Close form">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/>
            </svg>
        </button>
    </div>

    <div class="wssp-drawer__body">
        <!-- Loading state -->
        <div class="wssp-drawer__loading">
            <div class="wssp-drawer__spinner"></div>
            <p>Loading form…</p>
        </div>

        <!-- Form content loaded here via AJAX -->
        <div class="wssp-drawer__content"></div>

        <!-- Error state -->
        <div class="wssp-drawer__error" style="display:none;">
            <p>Could not load the form. Please try again or contact support.</p>
            <button class="wssp-btn wssp-btn--outline wssp-drawer__retry">Retry</button>
        </div>
    </div>
</div>