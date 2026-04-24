<?php
/**
 * Field rendering helper for portal templates.
 *
 * Provides consistent output escaping based on content type.
 * Replaces ad-hoc esc_html() / wp_kses_post() / do_shortcode()
 * calls scattered across templates.
 *
 * Usage:
 *   echo wssp_render_field( $value, 'rich' );       // HTML + shortcodes
 *   echo wssp_render_field( $value, 'plain' );      // Escaped plain text
 *   echo wssp_render_field( $value, 'nl2br' );      // Plain text with line breaks
 *
 * Field conventions (which mode to use):
 *   'rich'  — description, form_instructions, section->content, date_range_content
 *   'plain' — title, acknowledgment_text, section->heading
 *   'nl2br' — Formidable textarea values displayed outside forms
 *
 * @package WSSP
 */

defined( 'ABSPATH' ) || exit;

/**
 * Render a field value with appropriate escaping.
 *
 * @param string $value The raw field value.
 * @param string $mode  Rendering mode: 'rich', 'plain', or 'nl2br'.
 * @return string Safe HTML output.
 */
function wssp_render_field( $value, $mode = 'rich' ) {
    if ( empty( $value ) ) {
        return '';
    }

    switch ( $mode ) {
        case 'rich':
            // HTML allowed, shortcodes resolved.
            // Use for: description, form_instructions, section content,
            //          date_range_content, any CMS-authored rich content.
            return do_shortcode( wpautop( wp_kses_post( $value ) ) );

        case 'plain':
            // All HTML stripped, no shortcodes.
            // Use for: title, acknowledgment_text, section headings.
            return esc_html( $value );

        case 'nl2br':
            // Plain text with newlines converted to <br>.
            // Use for: Formidable textarea values displayed outside forms.
            return wp_kses_post( nl2br( esc_html( $value ) ) );

        default:
            return esc_html( $value );
    }
}