<?php
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
    exit;
}

// Optionally clean up options
delete_option('reedcrm_api_key');
delete_option('reedcrm_debug');
