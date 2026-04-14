<?php defined( 'ABSPATH' ) || exit; ?>

<div class="wrap wssp-admin">
    <h1>Satellite Portal — All Sessions
        <a href="<?php echo admin_url( 'admin.php?page=wssp-add-session' ); ?>" class="page-title-action">Add Session</a>
    </h1>
    
    <?php if ( isset( $_GET['ss_pulled_all'] ) ) : ?>
    <div class="notice notice-success is-dismissible">
        <p><?php echo esc_html( urldecode( $_GET['ss_msg'] ?? '' ) ); ?>
           (<?php echo absint( $_GET['ss_count'] ?? 0 ); ?> sessions updated)</p>
    </div>
    <?php endif; ?>
    <?php if ( isset( $_GET['ss_token_saved'] ) ) : ?>
        <div class="notice notice-success is-dismissible"><p>API token saved.</p></div>
    <?php endif; ?>
 
    <div class="wssp-card" style="margin-bottom: 20px;">
        <h2>Smartsheet</h2>
        <div style="display: flex; gap: 16px; align-items: flex-end;"> 
            <!-- Pull All -->
            <form method="post" action="<?php echo admin_url( 'admin-post.php' ); ?>" style="display:inline;">
                <?php wp_nonce_field( 'wssp_smartsheet_pull_all' ); ?>
                <input type="hidden" name="action" value="wssp_smartsheet_pull_all">
                <button type="submit" class="button button-primary"
                        onclick="return confirm('Pull data from Smartsheet for all sessions?');">
                    ↓ Sync All from Smartsheet
                </button>
            </form>
        </div>
    </div>

    <?php if ( empty( $sessions ) ) : ?>
        <p>No sessions have been created yet. <a href="<?php echo admin_url( 'admin.php?page=wssp-add-session' ); ?>">Create the first session.</a></p>
    <?php else : ?>
        <table class="wp-list-table widefat fixed striped">
            <thead>
                <tr>
                    <th style="width:100px;">Code</th>
                    <th>Short Name</th>
                    <th>Event Type</th>
                    <th style="width:120px;">Rollup Status</th>
                    <th style="width:80px;">Users</th>
                    <th style="width:160px;">Created</th>
                    <th style="width:100px;">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ( $sessions as $s ) : ?>
                    <tr>
                        <td>
                            <strong>
                                <a href="<?php echo admin_url( 'admin.php?page=wssp-manage-session&session_id=' . $s['id'] ); ?>">
                                    <?php echo esc_html( $s['session_code'] ); ?>
                                </a>
                            </strong>
                        </td>
                        <td><?php echo esc_html( $s['short_name'] ); ?></td>
                        <td><?php echo esc_html( ucfirst( $s['event_type'] ) ); ?></td>
                        <td>
                            <span class="wssp-status wssp-status--<?php echo esc_attr( $s['rollup_status'] ); ?>">
                                <?php echo esc_html( str_replace( '_', ' ', $s['rollup_status'] ) ); ?>
                            </span>
                        </td>
                        <td><?php echo esc_html( $s['user_count'] ); ?></td>
                        <td><?php echo esc_html( $s['created_at'] ); ?></td>
                        <td>
                            <a href="<?php echo admin_url( 'admin.php?page=wssp-manage-session&session_id=' . $s['id'] ); ?>">Manage</a>
                            |
                            <a href="<?php echo admin_url( 'admin.php?page=wssp-add-session&session_id=' . $s['id'] ); ?>">Edit</a>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>
