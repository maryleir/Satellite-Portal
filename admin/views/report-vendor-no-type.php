<?php
/**
 * View: Vendor Report — fallback when vendor has no type assigned.
 *
 * Shown when a user with the wssp_vendor role has no wssp_vendor_type
 * meta set on their account. Logistics needs to assign a type before
 * the report can render.
 *
 * @package WSSP
 */

defined( 'ABSPATH' ) || exit;

?>

<div class="wrap wssp-admin wssp-report wssp-vrp">
    <h1>Vendor Report</h1>
    <div class="wssp-card">
        <h2>Account setup not complete</h2>
        <p>
            Your account is set up as a vendor, but no vendor type
            (AV, Print, or Hotel) has been assigned to it yet.
        </p>
        <p>
            Please contact the logistics team — they'll set the type
            on your account, and your report will appear here on your
            next login.
        </p>
    </div>
</div>
