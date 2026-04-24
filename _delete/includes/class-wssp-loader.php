<?php
/**
 * Hook registration and global behaviours.
 *
 * This class is intentionally minimal. The portal exists within
 * a larger WordPress site that already handles login, navigation,
 * and content access. The plugin only needs to:
 * - Control access within its own shortcode output
 * - Provide the WSSP REST API nonce
 *
 * Login redirects, wp-admin blocking, and admin bar hiding are
 * NOT handled here — the existing site infrastructure manages that.
 *
 * @package WSSP
 */

defined( 'ABSPATH' ) || exit;

class WSSP_Loader {

    /** @var WSSP_Config */
    private $config;

    /** @var WSSP_Session_Access */
    private $access;

    /** @var WSSP_Audit_Log */
    private $audit;

    public function __construct( WSSP_Config $config, WSSP_Session_Access $access, WSSP_Audit_Log $audit ) {
        $this->config = $config;
        $this->access = $access;
        $this->audit  = $audit;
    }
}