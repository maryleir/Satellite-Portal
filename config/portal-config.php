<?php
/**
 * Portal configuration — behavioral overrides and system config.
 *
 * Phase structure, task definitions, content, form mappings, and deadlines
 * are owned by the WSSP Task Content plugin (wssp_tc). This file provides
 * only the behavioral layer that the portal needs for rendering logic,
 * conditional visibility, file upload routing, and vendor workflows.
 *
 * TASK BEHAVIOR:
 *   Tasks not listed in 'task_behavior' default to:
 *     type => 'form', owner => 'sponsor', no conditions
 *   Only list tasks that need an override.
 *
 * @package WSSP
 */

defined( 'ABSPATH' ) || exit;

return array(

    /* ═══════════════════════════════════════════
     * SATELLITE SYMPOSIUM
     * ═══════════════════════════════════════════ */
    'satellite' => array(
        'label' => 'Satellite Symposium',

        /* ─── TASK BEHAVIOR OVERRIDES ─────────── *
         * Only tasks that differ from the default
         * (type=form, owner=sponsor) need entries.
         * ─────────────────────────────────────── */
        'task_behavior' => array(

            // Upload tasks — route to file upload handler
            'upload-invitation'             => array( 'type' => 'upload', 'file_type' => 'invite' ),
            'upload-fts'                    => array( 'type' => 'upload', 'file_type' => 'fts' ),
            'upload-virtual-bag-insert'     => array( 'type' => 'upload', 'file_type' => 'virtual_bag_insert', 'condition' => 'separate_vbi_upload' ),
            'upload-hotel-door-drop'        => array( 'type' => 'upload', 'file_type' => 'door_drop',          'condition' => 'door_drop_addon' ),
            'upload-program-advertisement'  => array( 'type' => 'upload', 'file_type' => 'program_ad',         'condition' => 'program_ad_addon' ),
            'upload-agenda'                 => array( 'type' => 'upload', 'file_type' => 'agenda' ),
            'upload-podium-sign'            => array( 'type' => 'upload', 'file_type' => 'podium_sign' ),

            // Info tasks — logistics-owned, read-only for sponsors
            'food-beverage'              => array( 'type' => 'info', 'owner' => 'logistics' ),
            'room-location-setup'        => array( 'type' => 'info', 'owner' => 'logistics' ),

            // Review/approval tasks
            'backplate-approval'         => array( 'type' => 'review_approval' ),
            
            // Non-completable tasks — form available but no checkbox
            'logistics-contacts'         => array( 'completable' => false ),

            // Tasks in OTHER phases that are gated by an add-on purchase.
            // The 'addon' slug must match the derived slug from manage-add-ons
            // (task slug minus '-addon' suffix, hyphens to underscores).
            'push-notifications'            => array( 'addon' => 'push_notification', 'condition' => 'push_notification_addon' ),
            'approve-recording-upload'      => array( 'type' => 'approval', 'addon' => 'recording', 'condition' => 'recording_approval_required' ),
            'recording-approval-required'   => array( 'condition' => 'recording_addon' ),

            // Conditional visibility — CE vs Non-CE path
            'ce-accreditation'           => array( 'condition' => 'ce_path' ),
            'non-ce-accreditation'       => array( 'condition' => 'non_ce_path' ),
        ),

        /* ─── FILE TYPES ─────────────────────── */
        'file_types' => array(
            'invite'               => array( 'label' => 'Invitation',               'ext' => array( 'pdf' ) ),
            'fts'                  => array( 'label' => 'Fabric Tension Stand',     'ext' => array( 'pdf' ) ),
            'virtual_bag_insert'   => array( 'label' => 'Virtual Bag Insert',       'ext' => array( 'pdf' ) ),
            'signage_graphic'      => array( 'label' => 'Print-Ready Graphic File', 'ext' => array( 'pdf' ) ),
            'presentation'         => array( 'label' => 'PowerPoint Presentation',  'ext' => array( 'pptx', 'ppt', 'pdf' ) ),
            'door_drop'            => array( 'label' => 'Door Drop',                'ext' => array( 'pdf' ), 'addon' => 'door_drop' ),
            'program_ad'           => array( 'label' => 'Program Advertisement',    'ext' => array( 'pdf' ), 'addon' => 'program_ad' ),
            'agenda'               => array( 'label' => 'Agenda / Faculty Bios',    'ext' => array( 'pdf' ) ),
            'podium_sign'          => array( 'label' => 'Podium Sign',              'ext' => array( 'png', 'jpg' ) ),
            'backplate'            => array( 'label' => 'On Demand Backplate',      'ext' => array( 'png', 'jpg' ), 'provided_by' => 'logistics' ),
            'company_logo'         => array( 'label' => 'Company Logo',             'ext' => array( 'png', 'jpg', 'svg', 'eps' ) ),
            'ce_letter'            => array( 'label' => 'CE Accreditation Letter',  'ext' => array( 'pdf' ) ),
        ),

        /* ─── VENDOR VIEWS ───────────────────── */
        'vendor_views' => array(
            'av' => array(
                'fields' => array(
                    'wssp_av_rehearsal_confirm', 'wssp_av_stage_setup', 'wssp_av_stage_details',
                    'wssp_av_laptop_choice', 'wssp_av_confidence_monitor', 'wssp_av_laser_pointer',
                    'wssp_av_speaker_timer', 'wssp_av_wireless_remote', 'wssp_av_microphones_needed',
                    'wssp_av_ipad_order', 'wssp_av_ipad_quantity', 'wssp_av_additional_needs',
                    'wssp_av_contacts_list', 'wssp_digital_qa_platform',
                    'wssp_od_recording_approval_required',
                ),
                'trigger_status' => 'av_confirmed',
            ),
            'print' => array(
                'file_types'     => array( 'signage_graphic', 'invite' ),
                'trigger_status' => 'print_approved',
            ),
            'hotel' => array(
                'fields'         => array( 'wssp_av_stage_setup', 'wssp_av_stage_details' ),
                'trigger_status' => 'hotel_confirmed',
            ),
        ),
    ),

);