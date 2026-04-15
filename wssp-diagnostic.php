<?php
/**
 * WSSP Smartsheet Diagnostic
 * 
 * Drop this file into the plugin root (ws-satellite-portal/) and 
 * visit: yoursite.com/wp-admin/admin.php?page=wssp-dashboard&wssp_diag=1
 *
 * It will show you:
 *   - Which file the WSSP_Smartsheet class is loaded from
 *   - Whether pull_session accepts dry_run parameter
 *   - Whether the constructor accepts audit parameter
 *   - The bootstrap line from ws-satellite-portal.php
 *
 * DELETE THIS FILE after diagnosing.
 */

add_action( 'admin_notices', function() {
    if ( empty( $_GET['wssp_diag'] ) ) return;
    if ( ! current_user_can( 'manage_options' ) ) return;

    echo '<div class="notice notice-info" style="padding: 20px;">';
    echo '<h2>WSSP Smartsheet Diagnostic</h2>';

    // 1. Check if class exists
    if ( ! class_exists( 'WSSP_Smartsheet' ) ) {
        echo '<p style="color: red;"><strong>WSSP_Smartsheet class not found!</strong></p>';
        echo '</div>';
        return;
    }

    // 2. Where is the class file?
    $ref = new ReflectionClass( 'WSSP_Smartsheet' );
    $file = $ref->getFileName();
    echo '<p><strong>Class loaded from:</strong> <code>' . esc_html( $file ) . '</code></p>';
    echo '<p><strong>File modified:</strong> ' . esc_html( date( 'Y-m-d H:i:s', filemtime( $file ) ) ) . '</p>';

    // 3. Does pull_session accept dry_run?
    $method = $ref->getMethod( 'pull_session' );
    $params = $method->getParameters();
    $param_names = array_map( function( $p ) { return '$' . $p->getName(); }, $params );
    echo '<p><strong>pull_session params:</strong> <code>' . esc_html( implode( ', ', $param_names ) ) . '</code></p>';

    $has_dry_run = false;
    foreach ( $params as $p ) {
        if ( $p->getName() === 'dry_run' ) {
            $has_dry_run = true;
            break;
        }
    }
    echo '<p><strong>Has $dry_run parameter:</strong> ' . ( $has_dry_run ? '✅ YES' : '❌ NO — old version is still loaded!' ) . '</p>';

    // 4. Does constructor accept audit?
    $constructor = $ref->getConstructor();
    $c_params = $constructor->getParameters();
    $c_names = array_map( function( $p ) { return '$' . $p->getName(); }, $c_params );
    echo '<p><strong>Constructor params:</strong> <code>' . esc_html( implode( ', ', $c_names ) ) . '</code></p>';

    $has_audit = false;
    foreach ( $c_params as $p ) {
        if ( $p->getName() === 'audit' ) {
            $has_audit = true;
            break;
        }
    }
    echo '<p><strong>Has $audit parameter:</strong> ' . ( $has_audit ? '✅ YES' : '❌ NO — old version!' ) . '</p>';

    // 5. Check bootstrap line
    $bootstrap_file = WSSP_PLUGIN_DIR . 'ws-satellite-portal.php';
    if ( file_exists( $bootstrap_file ) ) {
        $contents = file_get_contents( $bootstrap_file );
        if ( preg_match( '/new WSSP_Smartsheet\([^)]+\)/', $contents, $matches ) ) {
            echo '<p><strong>Bootstrap instantiation:</strong> <code>' . esc_html( $matches[0] ) . '</code></p>';
            if ( strpos( $matches[0], '$audit' ) !== false ) {
                echo '<p>✅ $audit is being passed to constructor</p>';
            } else {
                echo '<p>❌ $audit is NOT being passed — update the bootstrap line!</p>';
            }
        }
    }

    // 6. Check if OPcache is active
    if ( function_exists( 'opcache_get_status' ) ) {
        $status = @opcache_get_status( false );
        if ( $status && $status['opcache_enabled'] ) {
            echo '<p>⚠️ <strong>OPcache is enabled.</strong> If you replaced the file but see old code, try: ';
            echo '<code>opcache_reset()</code> or restart PHP-FPM.</p>';
        }
    }

    echo '<hr><p><em>Delete this diagnostic file after use: <code>' . esc_html( __FILE__ ) . '</code></em></p>';
    echo '</div>';
});
