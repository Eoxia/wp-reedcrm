<?php
namespace ReedCRM;

if ( ! defined( 'ABSPATH' ) ) exit;

class Plugin {
    private static $instance;

    public static function get_instance(){
        if ( null === self::$instance ) {
            self::$instance = new self();
            self::$instance->setup();
        }
        return self::$instance;
    }

    public function setup(){
        // i18n
        load_plugin_textdomain('reedcrm', false, dirname(plugin_basename(__FILE__), 2) . '/languages');

        require_once __DIR__ . '/class-admin.php';
        require_once __DIR__ . '/class-api-client.php';
        require_once __DIR__ . '/class-integrator.php';

        Admin::init();
        API_Client::init();
        Integrator::init_hooks();
        register_activation_hook( dirname(__DIR__) . '/reedcrm.php', [ $this, 'activate' ] );
        register_deactivation_hook( dirname(__DIR__) . '/reedcrm.php', [ $this, 'deactivate' ] );
    }

    public function activate(){
        if ( ! get_option('reedcrm_api_key') ) {
            add_option('reedcrm_api_key', '');
        }
    }

    public function deactivate(){
        // optionnel : cleanup
    }
}
