<?php
/**
 * Dates & Deadlines Smartsheet configuration.
 *
 * Maps column IDs from the WORLD 2027 Dates and Deadlines sheet.
 *
 * Sheet layout:
 *   Col A: Item       — human-readable label
 *   Col B: Category   — Sponsor / Abstracts / WORLD
 *   Col C: 2026 Date  — prior year reference (not synced)
 *   Col D: 2027 Date  — current year reference (not synced)
 *   Col E: shortcode  — the shortcode key(s) to register
 *   Col F: Date       — canonical date value (DATE column type)
 *   Col G: Tags       — multi-line: Satellite, Exhibitors, Abstracts, etc.
 *   Col H: Notes      — editorial notes
 *
 * Sheet ID: 3779755649224580
 *
 * To get column IDs: GET /sheets/3779755649224580 in Postman,
 * look at the `columns` array, and fill in below. If column IDs
 * are left empty, the sync class will auto-detect by matching
 * column titles — but explicit IDs are preferred for reliability.
 *
 * @package WSSP
 */

defined( 'ABSPATH' ) || exit;

return array(

    'sheet_id' => '3779755649224580',

    /**
     * Column IDs — fill these in from the Smartsheet API response.
     *
     * Required: 'shortcode' and 'date'.
     * Optional: 'item', 'category', 'tags', 'notes' (auto-detected if empty).
     *
     * Leave the entire 'columns' array empty to use title-based auto-detection.
     */
    'columns' => array(
        'item'      => 0,  // TODO: Column ID for "Item" (Col A)
        'category'  => 0,  // TODO: Column ID for "Category" (Col B)
        'shortcode' => 0,  // TODO: Column ID for "shortcode" (Col E)
        'date'      => 0,  // TODO: Column ID for "Date" (Col F)
        'tags'      => 0,  // TODO: Column ID for "Tags" (Col G)
        'notes'     => 0,  // TODO: Column ID for "Notes" (Col H)
    ),

);
