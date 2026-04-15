<?php
/**
 * Smartsheet field mapping.
 *
 * Maps Smartsheet column IDs to portal data locations.
 * Each entry defines:
 *   - ss_column_id:  Smartsheet column ID (from API)
 *   - ss_title:      Smartsheet column title (for reference only)
 *   - portal_key:    Where to store in the portal
 *   - portal_store:  'meta' (wssp_session_meta), 'session' (wssp_sessions table), or 'skip'
 *   - direction:     'pull' (SS→portal), 'push' (portal→SS), 'both', or 'skip'
 *   - type:          'text', 'checkbox', 'picklist', 'date' — for value conversion
 *
 * Sheet ID: 519319206186884
 *
 * @package WSSP
 */

defined( 'ABSPATH' ) || exit;

return array(

    'sheet_id' => '519319206186884',

    /**
     * Column that identifies which row = which session.
     * Matched against wssp_sessions.session_code.
     */
    'match_column' => array(
        'ss_column_id' => 3858497140379524,
        'ss_title'     => '#',
        'portal_field' => 'session_code',
    ),

    /**
     * Field mappings.
     */
    'columns' => array(

        // ─── Session identifiers (pull from SS) ───
        array(
            'ss_column_id' => 6110296954064772,
            'ss_title'     => 'KEY',
            'portal_key'   => 'ss_key',
            'portal_store' => 'meta',
            'direction'    => 'pull',
            'type'         => 'text',
        ),
        array(
            'ss_column_id' => 3858497140379524,
            'ss_title'     => '#',
            'portal_key'   => 'session_code',
            'portal_store' => 'session',
            'direction'    => 'skip', // Used for matching, not syncing
            'type'         => 'text',
        ),
        array(
            'ss_column_id' => 2782537698283396,
            'ss_title'     => 'Short Name',
            'portal_key'   => 'short_name',
            'portal_store' => 'session',
            'direction'    => 'pull',
            'type'         => 'text',
       ),

        // ─── Schedule (pull from SS) ───
        array(
            'ss_column_id' => 8362096767750020,
            'ss_title'     => 'Day',
            'portal_key'   => 'session_day',
            'portal_store' => 'meta',
            'direction'    => 'pull',
            'type'         => 'text',
        ),
        array(
            'ss_column_id' => 1043747373272964,
            'ss_title'     => 'Date',
            'portal_key'   => 'session_date',
            'portal_store' => 'meta',
            'direction'    => 'pull',
            'type'         => 'date',
        ),
        array(
            'ss_column_id' => 5547347000643460,
            'ss_title'     => 'Time',
            'portal_key'   => 'session_time',
            'portal_store' => 'meta',
            'direction'    => 'pull',
            'type'         => 'text',
        ),
        array(
            'ss_column_id' => 3295547186958212,
            'ss_title'     => 'Location',
            'portal_key'   => 'session_location',
            'portal_store' => 'meta',
            'direction'    => 'pull',
            'type'         => 'text',
        ),

        // ─── Sponsor info (pull from SS) ───
        array(
            'ss_column_id' => 7799146814328708,
            'ss_title'     => 'Sponsor',
            'portal_key'   => 'sponsor_name',
            'portal_store' => 'meta',
            'direction'    => 'pull',
            'type'         => 'text',
        ),
        array(
            'ss_column_id' => 128953698963332,
            'ss_title'     => 'Company Contact (do not include in logistics)',
            'portal_key'   => 'company_contact',
            'portal_store' => 'meta',
            'direction'    => 'pull',
            'type'         => 'text',
        ),

        // ─── Contacts (pull from SS initially, push from portal) ───
        array(
            'ss_column_id' => 2169647280115588,
            'ss_title'     => 'Topic',
            'portal_key'   => 'topic',
            'portal_store' => 'meta',
            'direction'    => 'both',
            'type'         => 'text',
        ),
        array(
            'ss_column_id' => 6673246907486084,
            'ss_title'     => 'Final Title',
            'portal_key'   => 'wssp_program_title',
            'portal_store' => 'formidable',
            'direction'    => 'push',
            'type'         => 'text',
        ),

        // ─── Sponsor-entered data (push to SS) ───
        array(
            'ss_column_id' => 4421447093800836,
            'ss_title'     => 'CME',
            'portal_key'   => 'wssp_program_ce_status',
            'portal_store' => 'formidable',
            'direction'    => 'push',
            'type'         => 'picklist',
        ),
        array(
            'ss_column_id' => 8925046721171332,
            'ss_title'     => 'Audience Restriction',
            'portal_key'   => 'wssp_program_audience_type',
            'portal_store' => 'formidable',
            'direction'    => 'push',
            'type'         => 'text',
        ),
        array(
            'ss_column_id' => 4632553326333828,
            'ss_title'     => 'Contacts for Logistics',
            'portal_key'   => 'contacts_for_logistics',
            'portal_store' => 'formidable',
            'direction'    => 'push',
            'type'         => 'text',
        ),
        array(
            'ss_column_id' => 2380753512648580,
            'ss_title'     => 'Emails for Logistics',
            'portal_key'   => 'emails_for_logistics',
            'portal_store' => 'formidable',
            'direction'    => 'push',
            'type'         => 'text',
        ),
        array(
            'ss_column_id' => 6884353140019076,
            'ss_title'     => 'Lead Retrival Contact',
            'portal_key'   => 'lead_retrieval_contact',
            'portal_store' => 'formidable',
            'direction'    => 'push',
            'type'         => 'text',
        ),

        // ─── Lead retrieval tracking (pull from SS) ───
        array(
            'ss_column_id' => 1254853605805956,
            'ss_title'     => 'Lead Retrival #',
            'portal_key'   => 'lead_retrieval_number',
            'portal_store' => 'meta',
            'direction'    => 'pull',
            'type'         => 'text',
        ),
        array(
            'ss_column_id' => 7236196860907396,
            'ss_title'     => 'Lead retrival',
            'portal_key'   => 'lead_retrieval_count',
            'portal_store' => 'meta',
            'direction'    => 'pull',
            'type'         => 'text',
        ),
        array(
            'ss_column_id' => 5758453233176452,
            'ss_title'     => 'Lead Retrival Report Sent',
            'portal_key'   => 'lead_report_sent',
            'portal_store' => 'meta',
            'direction'    => 'both',
            'type'         => 'checkbox',
        ),
        array(
            'ss_column_id' => 1606697326694276,
            'ss_title'     => 'In Person Count',
            'portal_key'   => 'in_person_count',
            'portal_store' => 'meta',
            'direction'    => 'pull',
            'type'         => 'text',
        ),

        // ─── Add-on purchases (pull from SS) ───
        array(
            'ss_column_id' => 691903652384644,
            'ss_title'     => '2nd FTS - Purchased',
            'portal_key'   => 'addon_additional_fts',
            'portal_store' => 'meta',
            'direction'    => 'pull',
            'type'         => 'picklist',
            'value_map'    => array( 'Yes' => 'yes', 'No' => '', 'Hold' => 'hold' ),
        ),
        array(
            'ss_column_id' => 5195503279755140,
            'ss_title'     => 'Push Notification - Purchased',
            'portal_key'   => 'addon_push_notification',
            'portal_store' => 'meta',
            'direction'    => 'pull',
            'type'         => 'picklist',
            'value_map'    => array( 'Yes' => 'yes', 'No' => '', 'Hold' => 'hold' ),
        ),
        array(
            'ss_column_id' => 2943703466069892,
            'ss_title'     => 'Door Drop - Purchased',
            'portal_key'   => 'addon_hotel_door_drop',
            'portal_store' => 'meta',
            'direction'    => 'pull',
            'type'         => 'picklist',
            'value_map'    => array( 'Yes' => 'yes', 'No' => '', 'Hold' => 'hold' ),
        ),
        array(
            'ss_column_id' => 7447303093440388,
            'ss_title'     => 'Program Advertisement - Purchased',
            'portal_key'   => 'addon_program_advertisement',
            'portal_store' => 'meta',
            'direction'    => 'pull',
            'type'         => 'picklist',
            'value_map'    => array( 'Yes' => 'yes', 'No' => '', 'Hold' => 'hold' ),
        ),
        array(
            'ss_column_id' => 8573203000283012,
            'ss_title'     => 'Recording - Purchased',
            'portal_key'   => 'addon_recording',
            'portal_store' => 'meta',
            'direction'    => 'pull',
            'type'         => 'picklist',
            'value_map'    => array( 'Yes' => 'yes', 'No' => '', 'Hold' => 'hold' ),
        ),

        // ─── AV (mixed: pull contact from SS, push request status) ───
        array(
            'ss_column_id' => 6321403186597764,
            'ss_title'     => 'Assigned AV Contact',
            'portal_key'   => 'av_contact_name',
            'portal_store' => 'meta',
            'direction'    => 'pull',
            'type'         => 'text',
        ),
        array(
            'ss_column_id' => 1817803559227268,
            'ss_title'     => 'AV Request Submitted',
            'portal_key'   => 'av_request_submitted',
            'portal_store' => 'meta',
            'direction'    => 'push',
            'type'         => 'checkbox',
        ),

        // ─── Rehearsal (pull from SS) ───
        array(
            'ss_column_id' => 8291728023572356,
            'ss_title'     => 'Rehearsal Day',
            'portal_key'   => 'rehearsal_day',
            'portal_store' => 'meta',
            'direction'    => 'pull',
            'type'         => 'text',
        ),
        array(
            'ss_column_id' => 973378629095300,
            'ss_title'     => 'Rehearsal Date',
            'portal_key'   => 'rehearsal_date',
            'portal_store' => 'meta',
            'direction'    => 'pull',
            'type'         => 'date',
        ),
        array(
            'ss_column_id' => 5476978256465796,
            'ss_title'     => 'Allotted Time Slot',
            'portal_key'   => 'rehearsal_time',
            'portal_store' => 'meta',
            'direction'    => 'pull',
            'type'         => 'text',
        ),
        array(
            'ss_column_id' => 3225178442780548,
            'ss_title'     => 'CONFIRMED - Rehearsal Time',
            'portal_key'   => 'wssp_av_rehearsal_confirm',
            'portal_store' => 'formidable',
            'direction'    => 'push',
            'type'         => 'picklist',
        ),

        // ─── On Demand (push from portal) ───
        array(
            'ss_column_id' => 410428675673988,
            'ss_title'     => 'Needs Approval Before Uploading',
            'portal_key'   => 'wssp_od_recording_approval_required',
            'portal_store' => 'formidable',
            'direction'    => 'push',
            'type'         => 'checkbox',
        ),
        array(
            'ss_column_id' => 4914028303044484,
            'ss_title'     => 'Approved To Be Uploaded OnDemand',
            'portal_key'   => 'od_approved_for_upload',
            'portal_store' => 'meta',
            'direction'    => 'push',
            'type'         => 'checkbox',
        ),
        array(
            'ss_column_id' => 2662228489359236,
            'ss_title'     => 'Backplate Template Approved',
            'portal_key'   => 'wssp_od_backplate_approved',
            'portal_store' => 'formidable',
            'direction'    => 'push',
            'type'         => 'checkbox',
        ),

        // ─── Q&A (push from portal) ───
        array(
            'ss_column_id' => 7165828116729732,
            'ss_title'     => 'What Type Of Q&A Will Be Used',
            'portal_key'   => 'wssp_qa_platform',
            'portal_store' => 'formidable',
            'direction'    => 'push',
            'type'         => 'picklist',
        ),
        array(
            'ss_column_id' => 1536328582516612,
            'ss_title'     => 'Q&A Moderator',
            'portal_key'   => 'wssp_moderator_name',
            'portal_store' => 'formidable',
            'direction'    => 'push',
            'type'         => 'text',
        ),

        // ─── On Demand count (pull from SS) ───
        array(
            'ss_column_id' => 6602878163308420,
            'ss_title'     => 'OnDemand Count',
            'portal_key'   => 'on_demand_count',
            'portal_store' => 'meta',
            'direction'    => 'pull',
            'type'         => 'text',
        ),

        // ─── Notes columns (SS-only, not synced) ───
        array(
            'ss_column_id' => 8010253046861700,
            'ss_title'     => 'Notes',
            'portal_key'   => '',
            'portal_store' => 'skip',
            'direction'    => 'skip',
            'type'         => 'text',
        ),
        array(
            'ss_column_id' => 4069603372912516,
            'ss_title'     => 'AV Notes',
            'portal_key'   => '',
            'portal_store' => 'skip',
            'direction'    => 'skip',
            'type'         => 'text',
        ),
        array(
            'ss_column_id' => 6039928209887108,
            'ss_title'     => 'Q&A Notes',
            'portal_key'   => '',
            'portal_store' => 'skip',
            'direction'    => 'skip',
            'type'         => 'text',
        ),
        array(
            'ss_column_id' => 3788128396201860,
            'ss_title'     => 'Rehearsal Notes',
            'portal_key'   => '',
            'portal_store' => 'skip',
            'direction'    => 'skip',
            'type'         => 'text',
        ),
        array(
            'ss_column_id' => 7728778070151044,
            'ss_title'     => 'Stage/ Furniture Set Up Notes',
            'portal_key'   => '',
            'portal_store' => 'skip',
            'direction'    => 'skip',
            'type'         => 'text',
        ),
        array(
            'ss_column_id' => 2099278535937924,
            'ss_title'     => 'Furniture/Stage Order Confirmed by Freeman',
            'portal_key'   => '',
            'portal_store' => 'skip',
            'direction'    => 'skip',
            'type'         => 'text',
        ),
        array(
            'ss_column_id' => 3506653419491204,
            'ss_title'     => 'Meeting Planner Badge Names Submitted',
            'portal_key'   => '',
            'portal_store' => 'skip',
            'direction'    => 'skip',
            'type'         => 'picklist',
        ),
    ),
);