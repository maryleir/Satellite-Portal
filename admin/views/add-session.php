<?php defined( 'ABSPATH' ) || exit; ?>

<div class="wrap wssp-admin">
    <h1><?php echo $editing ? 'Edit Session' : 'Add New Session'; ?></h1>

    <?php if ( isset( $_GET['error'] ) && $_GET['error'] === 'missing_code' ) : ?>
        <div class="notice notice-error"><p>Session code is required.</p></div>
    <?php endif; ?>

    <form method="post" action="<?php echo admin_url( 'admin-post.php' ); ?>">
        <?php wp_nonce_field( 'wssp_save_session' ); ?>
        <input type="hidden" name="action" value="wssp_save_session">
        <input type="hidden" name="session_id" value="<?php echo esc_attr( $session['id'] ?? '' ); ?>">

        <table class="form-table">
            <tr>
                <th><label for="session_code">Session Code</label></th>
                <td>
                    <input type="text" name="session_code" id="session_code"
                           value="<?php echo esc_attr( $session['session_code'] ?? '' ); ?>"
                           class="regular-text" required
                           placeholder="e.g. SAT01, SAT02">
                    <p class="description">Unique identifier. Used in file naming and Smartsheet matching.</p>
                </td>
            </tr>
            <tr>
                <th><label for="short_name">Short Name</label></th>
                <td>
                    <input type="text" name="short_name" id="short_name"
                           value="<?php echo esc_attr( $session['short_name'] ?? '' ); ?>"
                           class="regular-text"
                           placeholder="e.g. ACMEPHARMA">
                    <p class="description">Used in file naming: SAT01_Acme-Pharma_FTS_v1.pdf. Preserves case and hyphens. Spaces become hyphens; special characters are stripped.</p>
                </td>
            </tr>
            <tr>
                <th><label for="event_type">Event Type</label></th>
                <td>
                    <select name="event_type" id="event_type">
                        <?php foreach ( $event_types as $type ) : ?>
                            <option value="<?php echo esc_attr( $type ); ?>"
                                <?php selected( $session['event_type'] ?? 'satellite', $type ); ?>>
                                <?php echo esc_html( ucfirst( $type ) ); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </td>
            </tr>
        </table>

        <?php submit_button( $editing ? 'Update Session' : 'Create Session' ); ?>
    </form>
</div>
