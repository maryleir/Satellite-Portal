<?php
/**
 * Meeting Planner CRUD — REST endpoints for managing meeting planner entries.
 *
 * Replaces the Formidable View + iframe approach with direct CRUD operations
 * that return server-rendered HTML partials. No iframe, no view ID dependency,
 * no filter flash.
 *
 * Entries are stored as Formidable entries in the 'wssp-sat-meeting-planners'
 * form, linked to a session via the 'wssp-sat-mp-sat-key' hidden field.
 *
 * The address field (wssp-sat-mp-address) uses Formidable's serialized array
 * format: a:5:{s:5:"line1";s:...;s:4:"city";s:...;s:5:"state";s:...;
 *              s:3:"zip";s:...;s:7:"country";s:...;}
 *
 * Endpoints:
 *   GET    /meeting-planners              — List + add form HTML
 *   POST   /meeting-planners              — Create new planner
 *   PUT    /meeting-planners/(?P<id>\d+)  — Update existing planner
 *   DELETE /meeting-planners/(?P<id>\d+)  — Delete planner
 *
 * @package WSSP
 */

defined( 'ABSPATH' ) || exit;

class WSSP_REST_Meeting_Planners {

    /** @var string REST namespace. */
    private $namespace = 'wssp/v1';

    /** @var WSSP_Session_Access */
    private $access;

    /** @var string Formidable form key for meeting planners. */
    private $form_key = 'wssp-sat-meeting-planners';

    /** @var string Field key that links entries to sessions. */
    private $session_key_field = 'wssp-sat-mp-sat-key';

    /** @var string Field key for the user ID. */
    private $uid_field = 'wssp-sat-mp-uid';

    /**
     * Visible field definitions: field_key => [ label, type, required, placeholder, size ]
     *
     * 'size' controls grid span: 'full' = full row, 'half' = half row, 'small' = narrow.
     * The address field is special — it maps to 5 sub-fields that serialize into
     * Formidable's address format.
     */
    private $fields = array(
        'wssp-sat-mp-first-name' => array( 'label' => 'First Name',  'type' => 'text',  'required' => true,  'placeholder' => '',              'size' => 'span-5' ),
        'wssp-sat-mp-last-name'  => array( 'label' => 'Last Name',   'type' => 'text',  'required' => true,  'placeholder' => '',              'size' => 'span-5' ),
        'wssp-sat-mp-degrees'    => array( 'label' => 'Degree(s)',   'type' => 'text',  'required' => false, 'placeholder' => 'e.g. PhD, MD', 'size' => 'span-2' ),
        'wssp-sat-mp-company'    => array( 'label' => 'Company',     'type' => 'text',  'required' => true,  'placeholder' => '',              'size' => 'span-12' ),
        'wssp-sat-mp-email'      => array( 'label' => 'Email',       'type' => 'email', 'required' => true,  'placeholder' => '',              'size' => 'span-6' ),
        'wssp-sat-mp-mobile'     => array( 'label' => 'Mobile',      'type' => 'tel',   'required' => true, 'placeholder' => '',              'size' => 'span-6' ),
    );

    /**
     * Address sub-fields (rendered separately, serialized into wssp-sat-mp-address).
     */
    private $address_fields = array(
        'line1'   => array( 'label' => 'Address',  'required' => true, 'placeholder' => 'Street address',     'size' => 'span-12' ),
        'city'    => array( 'label' => 'City',     'required' => true, 'placeholder' => '',                    'size' => 'span-5' ),
        'state'   => array( 'label' => 'State',    'required' => true, 'placeholder' => '',                    'size' => 'span-4' ),
        'zip'     => array( 'label' => 'Zip',      'required' => true, 'placeholder' => '',                    'size' => 'span-3' ),
        'country' => array( 'label' => 'Country',  'required' => true, 'placeholder' => 'e.g. United States', 'size' => 'span-12' ),
    );

    public function __construct( WSSP_Session_Access $access ) {
        $this->access = $access;
        add_action( 'rest_api_init', array( $this, 'register_routes' ) );
    }

    /* ───────────────────────────────────────────
     * ROUTE REGISTRATION
     * ─────────────────────────────────────────── */

    public function register_routes() {

        register_rest_route( $this->namespace, '/meeting-planners', array(
            'methods'             => 'GET',
            'callback'            => array( $this, 'get_planners' ),
            'permission_callback' => array( $this, 'check_logged_in' ),
            'args'                => array(
                'session_id' => array( 'required' => true, 'sanitize_callback' => 'absint' ),
            ),
        ));

        register_rest_route( $this->namespace, '/meeting-planners', array(
            'methods'             => 'POST',
            'callback'            => array( $this, 'create_planner' ),
            'permission_callback' => array( $this, 'check_logged_in' ),
        ));

        // POST /meeting-planners/{id}/update — Update planner
        register_rest_route( $this->namespace, '/meeting-planners/(?P<id>\d+)/update', array(
            'methods'             => 'POST',
            'callback'            => array( $this, 'update_planner' ),
            'permission_callback' => array( $this, 'check_logged_in' ),
        ));

        // POST /meeting-planners/{id}/delete — Delete planner
        register_rest_route( $this->namespace, '/meeting-planners/(?P<id>\d+)/delete', array(
            'methods'             => 'POST',
            'callback'            => array( $this, 'delete_planner' ),
            'permission_callback' => array( $this, 'check_logged_in' ),
        ));
    }

    public function check_logged_in() {
        return is_user_logged_in();
    }

    /* ───────────────────────────────────────────
     * GET — List planners + add form
     * ─────────────────────────────────────────── */

    public function get_planners( $request ) {
        $session_id = $request->get_param( 'session_id' );
        $user_id    = get_current_user_id();

        if ( ! $this->access->user_can_access( $user_id, $session_id ) ) {
            return new WP_REST_Response( array( 'success' => false, 'message' => 'Access denied.' ), 403 );
        }

        $session = $this->get_session( $session_id );
        if ( ! $session ) {
            return new WP_REST_Response( array( 'success' => false, 'message' => 'Session not found.' ), 404 );
        }

        $can_edit = $this->can_edit( $user_id, $session_id );
        $entries  = $this->query_entries( $session['session_key'] );

        $html = $this->render_planner_panel( $entries, $session_id, $session['session_key'], $can_edit );

        return new WP_REST_Response( array(
            'success' => true,
            'html'    => $html,
            'count'   => count( $entries ),
        ), 200 );
    }

    /* ───────────────────────────────────────────
     * POST — Create new planner
     * ─────────────────────────────────────────── */

    public function create_planner( $request ) {
        $session_id = absint( $request->get_param( 'session_id' ) );
        $user_id    = get_current_user_id();

        if ( ! $this->access->user_can_access( $user_id, $session_id ) ) {
            return new WP_REST_Response( array( 'success' => false, 'message' => 'Access denied.' ), 403 );
        }

        $session = $this->get_session( $session_id );
        if ( ! $session ) {
            return new WP_REST_Response( array( 'success' => false, 'message' => 'Session not found.' ), 404 );
        }

        $values = $this->extract_and_validate( $request );
        if ( is_wp_error( $values ) ) {
            return new WP_REST_Response( array(
                'success' => false,
                'message' => $values->get_error_message(),
                'errors'  => $values->get_error_data(),
            ), 400 );
        }

        $form_id = $this->get_form_id();
        if ( ! $form_id ) {
            return new WP_REST_Response( array( 'success' => false, 'message' => 'Meeting planner form not found.' ), 500 );
        }

        $field_map = $this->get_field_id_map( $form_id );
        if ( empty( $field_map ) ) {
            return new WP_REST_Response( array( 'success' => false, 'message' => 'Could not resolve form fields.' ), 500 );
        }

        $item_meta = $this->build_item_meta( $values, $request, $field_map, $session['session_key'], $user_id );

        $entry_id = FrmEntry::create( array(
            'form_id'   => $form_id,
            'item_meta' => $item_meta,
        ) );

        if ( ! $entry_id || is_wp_error( $entry_id ) ) {
            return new WP_REST_Response( array( 'success' => false, 'message' => 'Could not create entry.' ), 500 );
        }

        $can_edit = $this->can_edit( $user_id, $session_id );
        $entries  = $this->query_entries( $session['session_key'] );
        $html     = $this->render_planner_panel( $entries, $session_id, $session['session_key'], $can_edit );

        return new WP_REST_Response( array(
            'success' => true,
            'html'    => $html,
            'count'   => count( $entries ),
        ), 200 );
    }

    /* ───────────────────────────────────────────
     * PUT — Update planner
     * ─────────────────────────────────────────── */

    public function update_planner( $request ) {
        $entry_id   = absint( $request->get_param( 'id' ) );
        $session_id = absint( $request->get_param( 'session_id' ) );
        $user_id    = get_current_user_id();

        if ( ! $this->access->user_can_access( $user_id, $session_id ) ) {
            return new WP_REST_Response( array( 'success' => false, 'message' => 'Access denied.' ), 403 );
        }

        $session = $this->get_session( $session_id );
        if ( ! $session ) {
            return new WP_REST_Response( array( 'success' => false, 'message' => 'Session not found.' ), 404 );
        }

        if ( ! $this->entry_belongs_to_session( $entry_id, $session['session_key'] ) ) {
            return new WP_REST_Response( array( 'success' => false, 'message' => 'Entry not found.' ), 404 );
        }

        $values = $this->extract_and_validate( $request );
        if ( is_wp_error( $values ) ) {
            return new WP_REST_Response( array(
                'success' => false,
                'message' => $values->get_error_message(),
                'errors'  => $values->get_error_data(),
            ), 400 );
        }

        $form_id   = $this->get_form_id();
        $field_map = $this->get_field_id_map( $form_id );

        $item_meta = $this->build_item_meta( $values, $request, $field_map, $session['session_key'], $user_id );

        // Update each field's meta individually.
        // For array values (like the address field), FrmEntryMeta::update_entry_meta()
        // has a cache key bug with arrays AND double-serializes if we pre-serialize.
        // So we use a direct DB update for array values instead.
        global $wpdb;
        $meta_table = $wpdb->prefix . 'frm_item_metas';

        foreach ( $item_meta as $field_id => $value ) {
            if ( is_array( $value ) ) {
                // Direct DB update for array values — serialize once, skip cache
                $serialized = maybe_serialize( $value );
                $exists = $wpdb->get_var( $wpdb->prepare(
                    "SELECT id FROM {$meta_table} WHERE item_id = %d AND field_id = %d",
                    $entry_id, $field_id
                ) );
                if ( $exists ) {
                    $wpdb->update(
                        $meta_table,
                        array( 'meta_value' => $serialized ),
                        array( 'item_id' => $entry_id, 'field_id' => $field_id ),
                        array( '%s' ),
                        array( '%d', '%d' )
                    );
                } else {
                    $wpdb->insert(
                        $meta_table,
                        array( 'item_id' => $entry_id, 'field_id' => $field_id, 'meta_value' => $serialized ),
                        array( '%d', '%d', '%s' )
                    );
                }
            } else {
                FrmEntryMeta::update_entry_meta( $entry_id, $field_id, null, $value );
            }
        }

        // Clear Formidable's entry cache so it picks up the changes
        wp_cache_delete( $entry_id, 'frm_entry' );

        $can_edit = $this->can_edit( $user_id, $session_id );
        $entries  = $this->query_entries( $session['session_key'] );
        $html     = $this->render_planner_panel( $entries, $session_id, $session['session_key'], $can_edit );

        return new WP_REST_Response( array(
            'success' => true,
            'html'    => $html,
            'count'   => count( $entries ),
        ), 200 );
    }

    /* ───────────────────────────────────────────
     * DELETE — Delete planner
     * ─────────────────────────────────────────── */

    public function delete_planner( $request ) {
        $entry_id   = absint( $request->get_param( 'id' ) );
        $session_id = absint( $request->get_param( 'session_id' ) );
        $user_id    = get_current_user_id();

        if ( ! $this->access->user_can_access( $user_id, $session_id ) ) {
            return new WP_REST_Response( array( 'success' => false, 'message' => 'Access denied.' ), 403 );
        }

        $session = $this->get_session( $session_id );
        if ( ! $session ) {
            return new WP_REST_Response( array( 'success' => false, 'message' => 'Session not found.' ), 404 );
        }

        if ( ! $this->entry_belongs_to_session( $entry_id, $session['session_key'] ) ) {
            return new WP_REST_Response( array( 'success' => false, 'message' => 'Entry not found.' ), 404 );
        }

        FrmEntry::destroy( $entry_id );

        $can_edit = $this->can_edit( $user_id, $session_id );
        $entries  = $this->query_entries( $session['session_key'] );
        $html     = $this->render_planner_panel( $entries, $session_id, $session['session_key'], $can_edit );

        return new WP_REST_Response( array(
            'success' => true,
            'html'    => $html,
            'count'   => count( $entries ),
        ), 200 );
    }

    /* ───────────────────────────────────────────
     * RENDERING
     * ─────────────────────────────────────────── */

    private function render_planner_panel( $entries, $session_id, $session_key, $can_edit ) {
        ob_start();
        ?>
        <div class="wssp-mp" data-session-id="<?php echo esc_attr( $session_id ); ?>">

            <?php if ( ! empty( $entries ) ) : ?>
                <div class="wssp-mp__list">
                    <?php foreach ( $entries as $entry ) : ?>
                        <?php echo $this->render_planner_row( $entry, $can_edit ); ?>
                    <?php endforeach; ?>
                </div>
            <?php else : ?>
                <div class="wssp-mp__empty">
                    <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
                        <path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/>
                        <circle cx="9" cy="7" r="4"/>
                        <path d="M23 21v-2a4 4 0 0 0-3-3.87"/>
                        <path d="M16 3.13a4 4 0 0 1 0 7.75"/>
                    </svg>
                    <p>No meeting planners added yet.</p>
                </div>
            <?php endif; ?>

            <?php if ( $can_edit ) : ?>
                <div class="wssp-mp__add-section">
                    <button type="button" class="wssp-mp__add-toggle" id="wssp-mp-add-toggle">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
                            <line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/>
                        </svg>
                        Add Meeting Planner
                    </button>

                    <div class="wssp-mp__add-form" id="wssp-mp-add-form" style="display:none;">
                        <h4 class="wssp-mp__form-title">New Meeting Planner</h4>
                        <?php echo $this->render_form_fields(); ?>
                        <div class="wssp-mp__form-actions">
                            <button type="button" class="wssp-btn wssp-btn--primary wssp-mp__save-btn" data-action="create">
                                Add Planner
                            </button>
                            <button type="button" class="wssp-btn wssp-btn--outline wssp-mp__cancel-btn">
                                Cancel
                            </button>
                        </div>
                    </div>
                </div>
            <?php endif; ?>
        </div>
        <?php
        return ob_get_clean();
    }

    private function render_planner_row( $entry, $can_edit ) {
        $id      = $entry['id'];
        $first   = $entry['wssp-sat-mp-first-name'] ?? '';
        $last    = $entry['wssp-sat-mp-last-name'] ?? '';
        $degrees = $entry['wssp-sat-mp-degrees'] ?? '';
        $company = $entry['wssp-sat-mp-company'] ?? '';
        $email   = $entry['wssp-sat-mp-email'] ?? '';
        $mobile  = $entry['wssp-sat-mp-mobile'] ?? '';

        // Parse address (may be serialized array or plain string)
        $address = $this->parse_address( $entry['wssp-sat-mp-address'] ?? '' );

        $name_display = esc_html( trim( "$first $last" ) );
        if ( $degrees ) {
            $name_display .= ', <span class="wssp-mp__degrees">' . esc_html( $degrees ) . '</span>';
        }

        // Build address display string
        $address_parts = array_filter( array(
            $address['line1'] ?? '',
            $address['city'] ?? '',
            trim( ( $address['state'] ?? '' ) . ' ' . ( $address['zip'] ?? '' ) ),
            $address['country'] ?? '',
        ) );
        $address_display = implode( ', ', $address_parts );

        ob_start();
        ?>
        <div class="wssp-mp__row" data-entry-id="<?php echo esc_attr( $id ); ?>">
            <div class="wssp-mp__row-display">
                <div class="wssp-mp__row-main">
                    <div class="wssp-mp__name"><?php echo $name_display; ?></div>
                    <div class="wssp-mp__detail"><?php echo esc_html( $company ); ?></div>
                    <?php if ( $email || $mobile ) : ?>
                        <div class="wssp-mp__contact">
                            <?php if ( $email ) : ?>
                                <span><?php echo esc_html( $email ); ?></span>
                            <?php endif; ?>
                            <?php if ( $email && $mobile ) : ?>
                                <span class="wssp-mp__sep">&middot;</span>
                            <?php endif; ?>
                            <?php if ( $mobile ) : ?>
                                <span><?php echo esc_html( $mobile ); ?></span>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                    <?php if ( $address_display ) : ?>
                        <div class="wssp-mp__contact"><?php echo esc_html( $address_display ); ?></div>
                    <?php endif; ?>
                </div>
                <?php if ( $can_edit ) : ?>
                    <div class="wssp-mp__row-actions">
                        <button type="button" class="wssp-mp__edit-btn" title="Edit">
                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/>
                                <path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/>
                            </svg>
                        </button>
                        <button type="button" class="wssp-mp__delete-btn" title="Delete">
                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <polyline points="3 6 5 6 21 6"/>
                                <path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/>
                                <path d="M10 11v6"/><path d="M14 11v6"/>
                                <path d="M9 6V4a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v2"/>
                            </svg>
                        </button>
                    </div>
                <?php endif; ?>
            </div>

            <?php if ( $can_edit ) : ?>
                <div class="wssp-mp__row-edit" style="display:none;">
                    <h4 class="wssp-mp__form-title">Edit Meeting Planner</h4>
                    <?php echo $this->render_form_fields( $entry ); ?>
                    <div class="wssp-mp__form-actions">
                        <button type="button" class="wssp-btn wssp-btn--primary wssp-mp__save-btn" data-action="update" data-entry-id="<?php echo esc_attr( $id ); ?>">
                            Save Changes
                        </button>
                        <button type="button" class="wssp-btn wssp-btn--outline wssp-mp__cancel-btn">
                            Cancel
                        </button>
                    </div>
                </div>
            <?php endif; ?>
        </div>
        <?php
        return ob_get_clean();
    }

    /**
     * Render form fields for add/edit.
     *
     * Layout:
     *   Row 1: First Name (third) | Last Name (third) | Degree(s) (third)
     *   Row 2: Company (full)
     *   Row 3: Email (half) | Mobile (half)
     *   Row 4: Address line1 (full)
     *   Row 5: City (third) | State (third) | Zip (third)
     *   Row 6: Country (full)
     */
    private function render_form_fields( $entry = null ) {
        // Parse existing address for edit mode
        $address = $entry ? $this->parse_address( $entry['wssp-sat-mp-address'] ?? '' ) : array();

        ob_start();
        ?>
        <div class="wssp-mp__fields">
            <?php // ─── Standard fields ───
            foreach ( $this->fields as $field_key => $def ) :
                $value = $entry ? ( $entry[ $field_key ] ?? '' ) : '';
                $required_attr = $def['required'] ? 'required' : '';
                $required_star = $def['required'] ? ' <span class="wssp-mp__required">*</span>' : '';
                $size_class    = 'wssp-mp__field--' . $def['size'];
            ?>
                <div class="wssp-mp__field <?php echo esc_attr( $size_class ); ?> <?php echo $def['required'] ? 'wssp-mp__field--required' : ''; ?>"
                     data-field-key="<?php echo esc_attr( $field_key ); ?>">
                    <label class="wssp-mp__label">
                        <?php echo $def['label'] . $required_star; ?>
                    </label>
                    <input type="<?php echo esc_attr( $def['type'] ); ?>"
                           class="wssp-mp__input"
                           name="<?php echo esc_attr( $field_key ); ?>"
                           value="<?php echo esc_attr( $value ); ?>"
                           placeholder="<?php echo esc_attr( $def['placeholder'] ); ?>"
                           <?php echo $required_attr; ?>>
                </div>
            <?php endforeach; ?>

            <?php // ─── Address sub-fields ───
            foreach ( $this->address_fields as $sub_key => $def ) :
                $value = $address[ $sub_key ] ?? '';
                $size_class    = 'wssp-mp__field--' . $def['size'];
                $required_attr = $def['required'] ? 'required' : '';
                $required_star = $def['required'] ? ' <span class="wssp-mp__required">*</span>' : '';
            ?>
                <div class="wssp-mp__field <?php echo esc_attr( $size_class ); ?> <?php echo $def['required'] ? 'wssp-mp__field--required' : ''; ?>"
                     data-field-key="address_<?php echo esc_attr( $sub_key ); ?>">
                    <label class="wssp-mp__label">
                        <?php echo $def['label'] . $required_star; ?>
                    </label>
                    <input type="text"
                           class="wssp-mp__input"
                           name="address_<?php echo esc_attr( $sub_key ); ?>"
                           value="<?php echo esc_attr( $value ); ?>"
                           placeholder="<?php echo esc_attr( $def['placeholder'] ); ?>"
                           <?php echo $required_attr; ?>>
                </div>
            <?php endforeach; ?>
        </div>
        <?php
        return ob_get_clean();
    }

    /* ───────────────────────────────────────────
     * DATA ACCESS
     * ─────────────────────────────────────────── */

    private function query_entries( $session_key ) {
        global $wpdb;

        $form_id = $this->get_form_id();
        if ( ! $form_id ) return array();

        $field_map = $this->get_field_id_map( $form_id );
        if ( empty( $field_map ) ) return array();

        $sk_field_id = $field_map[ $this->session_key_field ] ?? null;
        if ( ! $sk_field_id ) return array();

        $entry_ids = $wpdb->get_col( $wpdb->prepare(
            "SELECT e.id
             FROM {$wpdb->prefix}frm_items e
             INNER JOIN {$wpdb->prefix}frm_item_metas m ON (e.id = m.item_id)
             WHERE e.form_id = %d
             AND m.field_id = %d
             AND m.meta_value = %s
             AND e.is_draft = 0
             ORDER BY e.created_at ASC",
            $form_id,
            $sk_field_id,
            $session_key
        ));

        if ( empty( $entry_ids ) ) return array();

        $id_to_key = array_flip( $field_map );

        $placeholders = implode( ',', array_fill( 0, count( $entry_ids ), '%d' ) );
        $meta_rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT item_id, field_id, meta_value
             FROM {$wpdb->prefix}frm_item_metas
             WHERE item_id IN ({$placeholders})",
            ...$entry_ids
        ), ARRAY_A );

        $entries_data = array();
        foreach ( $entry_ids as $eid ) {
            $entries_data[ $eid ] = array( 'id' => $eid );
        }

        foreach ( $meta_rows as $row ) {
            $eid       = $row['item_id'];
            $field_id  = $row['field_id'];
            $field_key = $id_to_key[ $field_id ] ?? null;

            if ( $field_key && isset( $entries_data[ $eid ] ) ) {
                $entries_data[ $eid ][ $field_key ] = $row['meta_value'];
            }
        }

        return array_values( $entries_data );
    }

    private function get_form_id() {
        if ( ! class_exists( 'FrmForm' ) ) return null;
        $form = FrmForm::getOne( $this->form_key );
        return $form ? (int) $form->id : null;
    }

    private function get_field_id_map( $form_id ) {
        global $wpdb;

        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT id, field_key FROM {$wpdb->prefix}frm_fields WHERE form_id = %d",
            $form_id
        ), ARRAY_A );

        $map = array();
        foreach ( $rows as $row ) {
            $map[ $row['field_key'] ] = (int) $row['id'];
        }
        return $map;
    }

    private function entry_belongs_to_session( $entry_id, $session_key ) {
        global $wpdb;

        $form_id     = $this->get_form_id();
        $field_map   = $this->get_field_id_map( $form_id );
        $sk_field_id = $field_map[ $this->session_key_field ] ?? null;

        if ( ! $sk_field_id ) return false;

        $value = $wpdb->get_var( $wpdb->prepare(
            "SELECT meta_value FROM {$wpdb->prefix}frm_item_metas
             WHERE item_id = %d AND field_id = %d",
            $entry_id, $sk_field_id
        ));

        return $value === $session_key;
    }

    /* ───────────────────────────────────────────
     * VALIDATION
     * ─────────────────────────────────────────── */

    private function extract_and_validate( $request ) {
        $values = array();
        $errors = array();

        // Standard fields
        foreach ( $this->fields as $field_key => $def ) {
            $raw   = $request->get_param( $field_key );
            $value = sanitize_text_field( $raw ?? '' );

            if ( $def['required'] && $value === '' ) {
                $errors[ $field_key ] = $def['label'] . ' is required.';
                continue;
            }

            if ( $def['type'] === 'email' && $value !== '' && ! is_email( $value ) ) {
                $errors[ $field_key ] = 'Please enter a valid email address.';
                continue;
            }

            $values[ $field_key ] = $value;
        }

        // Address sub-fields — collected into $values['_address']
        $addr = array();
        foreach ( $this->address_fields as $sub_key => $def ) {
            $raw = $request->get_param( 'address_' . $sub_key );
            $value = sanitize_text_field( $raw ?? '' );

            if ( $def['required'] && $value === '' ) {
                $errors[ 'address_' . $sub_key ] = $def['label'] . ' is required.';
            }

            $addr[ $sub_key ] = $value;
        }
        $values['_address'] = $addr;

        if ( ! empty( $errors ) ) {
            $first_error = reset( $errors );
            return new WP_Error( 'validation_failed', $first_error, $errors );
        }

        return $values;
    }

    /* ───────────────────────────────────────────
     * ITEM META BUILDER
     * ─────────────────────────────────────────── */

    /**
     * Build the Formidable item_meta array from validated values.
     *
     * Handles:
     *   - Standard text fields → field_id => value
     *   - Address field → field_id => serialized array (Formidable format)
     *   - Session key → hidden field
     *   - User ID → hidden field
     */
    private function build_item_meta( $values, $request, $field_map, $session_key, $user_id ) {
        $item_meta = array();

        // Standard fields
        foreach ( $this->fields as $field_key => $def ) {
            if ( isset( $values[ $field_key ] ) && isset( $field_map[ $field_key ] ) ) {
                $item_meta[ $field_map[ $field_key ] ] = $values[ $field_key ];
            }
        }

        // Address — pass as array for FrmEntry::create() compatibility.
        // The update path serializes individually before calling FrmEntryMeta.
        if ( isset( $field_map['wssp-sat-mp-address'] ) && isset( $values['_address'] ) ) {
            $item_meta[ $field_map['wssp-sat-mp-address'] ] = $values['_address'];
        }

        // Session key (hidden)
        if ( isset( $field_map[ $this->session_key_field ] ) ) {
            $item_meta[ $field_map[ $this->session_key_field ] ] = $session_key;
        }

        // User ID (hidden)
        if ( isset( $field_map[ $this->uid_field ] ) ) {
            $item_meta[ $field_map[ $this->uid_field ] ] = $user_id;
        }

        return $item_meta;
    }

    /* ───────────────────────────────────────────
     * ADDRESS HELPERS
     * ─────────────────────────────────────────── */

    /**
     * Parse a Formidable address value into an associative array.
     *
     * Formidable stores addresses as serialized PHP arrays:
     *   a:5:{s:5:"line1";s:11:"2222 Street";s:4:"city";...}
     *
     * Also handles: plain strings, already-unserialized arrays, empty values.
     *
     * @param mixed $raw Raw value from the database.
     * @return array  Keys: line1, city, state, zip, country
     */
    private function parse_address( $raw ) {
        $defaults = array( 'line1' => '', 'city' => '', 'state' => '', 'zip' => '', 'country' => '' );

        if ( empty( $raw ) ) {
            return $defaults;
        }

        // Already an array (e.g., from Formidable's API)
        if ( is_array( $raw ) ) {
            return array_merge( $defaults, $raw );
        }

        // Try unserializing
        $parsed = maybe_unserialize( $raw );
        if ( is_array( $parsed ) ) {
            return array_merge( $defaults, $parsed );
        }

        // Plain string — treat as country only (legacy fallback)
        $defaults['country'] = (string) $raw;
        return $defaults;
    }

    /* ───────────────────────────────────────────
     * HELPERS
     * ─────────────────────────────────────────── */

    private function get_session( $session_id ) {
        global $wpdb;
        return $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}wssp_sessions WHERE id = %d",
            $session_id
        ), ARRAY_A );
    }

    private function can_edit( $user_id, $session_id ) {
        if ( current_user_can( 'edit_others_posts' ) ) return true;

        $sessions = $this->access->get_user_sessions( $user_id );
        foreach ( $sessions as $link ) {
            if ( (int) $link['session_id'] === (int) $session_id ) {
                return in_array( $link['role'] ?? '', array( 'sponsor_primary', 'sponsor_collaborator' ), true );
            }
        }
        return false;
    }
}
