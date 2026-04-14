<?php defined( 'ABSPATH' ) || exit; ?>

<div class="wrap wssp-admin">
    <h1>
        <?php echo esc_html( $session['session_code'] ); ?>
        <?php if ( $session['short_name'] ) : ?>
            — <?php echo esc_html( $session['short_name'] ); ?>
        <?php endif; ?>
        <span class="wssp-status wssp-status--<?php echo esc_attr( $session['rollup_status'] ); ?>">
            <?php echo esc_html( str_replace( '_', ' ', $session['rollup_status'] ) ); ?>
        </span>
    </h1>

    <p>
        <a href="<?php echo admin_url( 'admin.php?page=wssp-add-session&session_id=' . $session['id'] ); ?>">Edit Session Details</a>
        &nbsp;|&nbsp;
        <a href="<?php echo admin_url( 'admin.php?page=wssp-dashboard' ); ?>">&laquo; Back to All Sessions</a>
    </p>

    <?php if ( isset( $_GET['saved'] ) ) : ?>
        <div class="notice notice-success is-dismissible"><p>Session saved.</p></div>
    <?php endif; ?>
    <?php if ( isset( $_GET['linked'] ) ) : ?>
        <div class="notice notice-success is-dismissible"><p>User linked to session.</p></div>
    <?php endif; ?>
    <?php if ( isset( $_GET['unlinked'] ) ) : ?>
        <div class="notice notice-success is-dismissible"><p>User removed from session.</p></div>
    <?php endif; ?>
    <?php if ( isset( $_GET['meta_saved'] ) ) : ?>
        <div class="notice notice-success is-dismissible"><p>Session details saved.</p></div>
    <?php endif; ?>

    <!-- ─── Session Info ─── -->
    <div class="wssp-card">
        <h2>Session Details</h2>
        <table class="form-table">
            <tr><th>Session Code</th><td><?php echo esc_html( $session['session_code'] ); ?></td></tr>
            <tr><th>Short Name</th><td><?php echo esc_html( $session['short_name'] ); ?></td></tr>
            <tr><th>Event Type</th><td><?php echo esc_html( ucfirst( $session['event_type'] ) ); ?></td></tr>
            <tr><th>Rollup Status</th><td><?php echo esc_html( $session['rollup_status'] ); ?></td></tr>
            <tr><th>Smartsheet Row ID</th><td><?php echo esc_html( $session['smartsheet_row_id'] ?: '(not linked)' ); ?></td></tr>
            <tr><th>Created</th><td><?php echo esc_html( $session['created_at'] ); ?></td></tr>
            <tr><th>Last Updated</th><td><?php echo esc_html( $session['updated_at'] ); ?></td></tr>
        </table>
    </div>

    <!-- ─── Linked Users ─── -->
    <div class="wssp-card">
        <h2>Linked Users</h2>

        <?php if ( ! empty( $session_users ) ) : ?>
            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th>Name</th>
                        <th>Email</th>
                        <th>Role</th>
                        <th>Added By</th>
                        <th>Date Added</th>
                        <th style="width:80px;">Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ( $session_users as $su ) : ?>
                        <tr>
                            <td><?php echo esc_html( $su['display_name'] ); ?></td>
                            <td><?php echo esc_html( $su['user_email'] ); ?></td>
                            <td>
                                <span class="wssp-role wssp-role--<?php echo esc_attr( $su['role'] ); ?>">
                                    <?php echo esc_html( str_replace( '_', ' ', $su['role'] ) ); ?>
                                </span>
                            </td>
                            <td>
                                <?php
                                if ( $su['added_by'] ) {
                                    $adder = get_userdata( $su['added_by'] );
                                    echo $adder ? esc_html( $adder->display_name ) : 'User #' . $su['added_by'];
                                } else {
                                    echo '—';
                                }
                                ?>
                            </td>
                            <td><?php echo esc_html( $su['created_at'] ); ?></td>
                            <td>
                                <form method="post" action="<?php echo admin_url( 'admin-post.php' ); ?>" style="display:inline;">
                                    <?php wp_nonce_field( 'wssp_unlink_user' ); ?>
                                    <input type="hidden" name="action" value="wssp_unlink_user">
                                    <input type="hidden" name="session_id" value="<?php echo esc_attr( $session['id'] ); ?>">
                                    <input type="hidden" name="user_id" value="<?php echo esc_attr( $su['user_id'] ); ?>">
                                    <button type="submit" class="button-link wssp-text-danger"
                                            onclick="return confirm('Remove this user from the session?');">
                                        Remove
                                    </button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php else : ?>
            <p>No users linked to this session yet.</p>
        <?php endif; ?>

        <!-- Link User Form -->
        <h3>Link a User</h3>
        <form method="post" action="<?php echo admin_url( 'admin-post.php' ); ?>" class="wssp-inline-form">
            <?php wp_nonce_field( 'wssp_link_user' ); ?>
            <input type="hidden" name="action" value="wssp_link_user">
            <input type="hidden" name="session_id" value="<?php echo esc_attr( $session['id'] ); ?>">

            <label for="user_id">User:</label>
            <select name="user_id" id="user_id" required>
                <option value="">— Select User —</option>
                <?php foreach ( $all_users as $u ) : ?>
                    <option value="<?php echo esc_attr( $u->ID ); ?>">
                        <?php echo esc_html( $u->display_name . ' (' . $u->user_email . ')' ); ?>
                    </option>
                <?php endforeach; ?>
            </select>

            <label for="role">Role:</label>
            <select name="role" id="role">
                <option value="sponsor_primary">Sponsor (Primary)</option>
                <option value="sponsor_collaborator">Sponsor (Collaborator)</option>
                <option value="vendor_av">Vendor — AV</option>
                <option value="vendor_print">Vendor — Print</option>
            </select>

            <button type="submit" class="button button-primary">Link User</button>
        </form>
    </div>

 
<!-- ─── Smartsheet Sync ─── -->
<?php if ( isset( $_GET['ss_pulled'] ) ) : ?>
    <div class="notice notice-success is-dismissible">
        <p><?php echo esc_html( urldecode( $_GET['ss_msg'] ?? 'Synced from Smartsheet.' ) ); ?></p>
    </div>
<?php endif; ?>
<?php if ( isset( $_GET['ss_pushed'] ) ) : ?>
    <div class="notice notice-success is-dismissible">
        <p><?php echo esc_html( urldecode( $_GET['ss_msg'] ?? 'Pushed to Smartsheet.' ) ); ?></p>
    </div>
<?php endif; ?>
<?php if ( isset( $_GET['ss_error'] ) ) : ?>
    <div class="notice notice-error is-dismissible">
        <p><?php echo esc_html( urldecode( $_GET['ss_msg'] ?? 'Smartsheet sync error.' ) ); ?></p>
    </div>
<?php endif; ?>
 
<div class="wssp-card">
    <h2>Smartsheet Sync</h2>
    <p>
        Row ID: <code><?php echo esc_html( $session['smartsheet_row_id'] ?: 'Not linked' ); ?></code>
    </p>
    <div style="display: flex; gap: 8px; margin-top: 12px;">
        <!-- Pull from SS -->
        <form method="post" action="<?php echo admin_url( 'admin-post.php' ); ?>" style="display:inline;">
            <?php wp_nonce_field( 'wssp_smartsheet_pull' ); ?>
            <input type="hidden" name="action" value="wssp_smartsheet_pull">
            <input type="hidden" name="session_id" value="<?php echo esc_attr( $session['id'] ); ?>">
            <button type="submit" class="button button-secondary">
                ↓ Pull from Smartsheet
            </button>
        </form>
 
        <!-- Push to SS -->
        <form method="post" action="<?php echo admin_url( 'admin-post.php' ); ?>" style="display:inline;">
            <?php wp_nonce_field( 'wssp_smartsheet_push' ); ?>
            <input type="hidden" name="action" value="wssp_smartsheet_push">
            <input type="hidden" name="session_id" value="<?php echo esc_attr( $session['id'] ); ?>">
            <button type="submit" class="button button-secondary">
                ↑ Push to Smartsheet
            </button>
        </form>
    </div>
</div>
 
<!-- ─── Session Meta (details, add-ons, backplate) ─── -->
<?php include WSSP_PLUGIN_DIR . 'admin/views/session-meta-fields.php'; ?>
    
</div>