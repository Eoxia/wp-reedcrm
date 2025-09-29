<?php
/*
 * Plugin Name: ReedCRM
 * Description: Hook an existing plugin and add items to its lists. Admin page to set API key.
 * Version: 0.1
 * Text Domain: reedcrm
 * Domain Path: /languages
 * License:     GPLv3
 */

if ( ! defined( 'ABSPATH' ) ) exit;

require_once __DIR__ . '/includes/class-plugin.php';

add_action( 'plugins_loaded', function() {
    \ReedCRM\Plugin::get_instance();
});
