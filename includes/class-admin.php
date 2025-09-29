<?php
namespace ReedCRM;

if ( ! defined( 'ABSPATH' ) ) exit;

class Admin {
    public static function init(){
        add_action('admin_menu', [__CLASS__, 'admin_menu']);
        add_action('admin_init', [__CLASS__, 'register_settings']);
        add_action('admin_enqueue_scripts', [__CLASS__, 'enqueue_assets']);
    }

    public static function enqueue_assets($hook){
        if ( strpos($hook, 'settings') === false ) return;
        wp_enqueue_style('reedcrm-admin', plugins_url('../assets/css/admin.css', __FILE__), array(), defined('WP_REEDCRM_VERSION') ? WP_REEDCRM_VERSION : '1.0.0');
        wp_enqueue_script('reedcrm-admin', plugins_url('../assets/js/admin.js', __FILE__), array('jquery'), defined('WP_REEDCRM_VERSION') ? WP_REEDCRM_VERSION : '1.0.0', true);
    }

    public static function admin_menu(){
        add_menu_page(
            esc_html__( 'ReedCRM','reedcrm'),         // Titre page
            esc_html__( 'ReedCRM','reedcrm'),         // Label menu
            'manage_options',                // Capability
            'reedcrm-settings',              // Slug
            [__CLASS__, 'settings_page'],    // Callback
            'dashicons-database',            // Icône du menu (exemple)
            25                               // Position dans le menu
        );
    }

    public static function register_settings(){
        register_setting('reedcrm_settings_group', 'reedcrm_api_key', [
            'type' => 'string',
            'sanitize_callback' => 'sanitize_text_field',
            'default' => ''
        ]);
        register_setting('reedcrm_settings_group', 'reedcrm_api_url', [
            'type' => 'string',
            'sanitize_callback' => 'esc_url_raw',
            'default' => ''
        ]);

        add_settings_section('reedcrm_main', esc_html__('Configuration API','reedcrm'), function(){ echo '<p>' . esc_html__('Entrez la clé API et l\'URL.','reedcrm') . '</p>'; }, 'reedcrm-settings');

        add_settings_field('reedcrm_api_key', esc_html__('API Key','reedcrm'), [__CLASS__, 'field_api_key'], 'reedcrm-settings', 'reedcrm_main');
        add_settings_field('reedcrm_api_url', esc_html__('API URL','reedcrm'), [__CLASS__, 'field_api_url'], 'reedcrm-settings', 'reedcrm_main');
    }


    public static function field_api_key(){
        $val = get_option('reedcrm_api_key', '');
        printf('<input type="text" name="reedcrm_api_key" value="%s" class="regular-text" />', esc_attr($val));
    }

    public static function field_api_url(){
        $val = get_option('reedcrm_api_url', '');
        printf('<input type="url" name="reedcrm_api_url" value="%s" class="regular-text" placeholder="https://api.exemple.com" />', esc_url($val));
    }

    public static function settings_page(){
        if ( ! current_user_can('manage_options') ) return;
        ?>
        <div class="wrap">
            <h1><?php echo esc_html__('ReedCRM Settings','reedcrm'); ?></h1>
            <form method="post" action="options.php">
                <?php
                settings_fields('reedcrm_settings_group');
                do_settings_sections('reedcrm-settings');
                submit_button();
                ?>
            </form>
        </div>
        <?php
    }
}
