<?php
namespace ReedCRM;

if ( ! defined( 'ABSPATH' ) ) exit;

class Admin {
    public static function init(){
        add_action('admin_menu', [__CLASS__, 'admin_menu']);
        add_action('admin_init', [__CLASS__, 'register_settings']);
        add_action('admin_enqueue_scripts', [__CLASS__, 'enqueue_assets']);
        add_action('wp_ajax_reedcrm_test_connection', [__CLASS__, 'ajax_test_connection']);
    }

    public static function enqueue_assets($hook){
        if ( strpos($hook, 'settings') === false ) return;
        wp_enqueue_style('reedcrm-admin', plugins_url('../assets/css/admin.css', __FILE__), array(), defined('WP_REEDCRM_VERSION') ? WP_REEDCRM_VERSION : '1.0.0');
        wp_enqueue_script('reedcrm-admin', plugins_url('../assets/js/admin.js', __FILE__), array('jquery'), defined('WP_REEDCRM_VERSION') ? WP_REEDCRM_VERSION : '1.0.0', true);
        
        wp_localize_script('reedcrm-admin', 'reedcrm_ajax', [
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('reedcrm_test_connection_nonce'),
            'testing_text' => esc_html__('Test en cours...', 'reedcrm'),
            'success_text' => esc_html__('Connexion réussie!', 'reedcrm'),
            'error_text' => esc_html__('Erreur de connexion', 'reedcrm')
        ]);
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

    public static function ajax_test_connection(){
        check_ajax_referer('reedcrm_test_connection_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('Accès non autorisé', 'reedcrm'));
        }

        $api_key = sanitize_text_field($_POST['api_key'] ?? '');
        $api_url = esc_url_raw($_POST['api_url'] ?? '');

        if (empty($api_key) || empty($api_url)) {
            wp_send_json_error(esc_html__('API Key et URL sont requis', 'reedcrm'));
        }

        $result = self::test_dolibarr_connection($api_url, $api_key);
        
        if ($result['success']) {
            wp_send_json_success($result['message']);
        } else {
            wp_send_json_error($result['message']);
        }
    }

    private static function test_dolibarr_connection($api_url, $api_key){
        $url = rtrim($api_url, '/') . '/api/index.php/easycrm/testRights';

        $response = wp_remote_post($url, [
            'timeout' => 15,
            'headers' => [
                'Content-Type' => 'application/json',
                'DOLAPIKEY' => $api_key
            ],
            'body' => json_encode([
                'login' => '',
                'password' => '',
                'entity' => ''
            ])
        ]);

        if (is_wp_error($response)) {
            return [
                'success' => false,
                'message' => sprintf(esc_html__('Erreur de connexion: %s', 'reedcrm'), $response->get_error_message())
            ];
        }

        $response_code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);

        if ($response_code === 200) {
            return [
                'success' => true,
                'message' => esc_html__('Connexion à Dolibarr réussie!', 'reedcrm')
            ];
        } else {
            return [
                'success' => false,
                'message' => sprintf(esc_html__('Erreur HTTP %d: %s', 'reedcrm'), $response_code, $body)
            ];
        }
    }

    public static function settings_page(){
        if ( ! current_user_can('manage_options') ) return;
        
        // Check connection status on page load
        $api_key = get_option('reedcrm_api_key', '');
        $api_url = get_option('reedcrm_api_url', '');
        $connection_status = '';
        
        if (!empty($api_key) && !empty($api_url)) {
            $test_result = self::test_dolibarr_connection($api_url, $api_key);
            if ($test_result['success']) {
                $connection_status = '<div class="notice notice-success"><p><strong>' . esc_html__('Statut:', 'reedcrm') . '</strong> ' . esc_html__('Connecté à Dolibarr', 'reedcrm') . '</p></div>';
            } else {
                $connection_status = '<div class="notice notice-error"><p><strong>' . esc_html__('Statut:', 'reedcrm') . '</strong> ' . esc_html__('Non connecté à Dolibarr', 'reedcrm') . '</p></div>';
            }
        } else {
            $connection_status = '<div class="notice notice-info"><p><strong>' . esc_html__('Statut:', 'reedcrm') . '</strong> ' . esc_html__('Configuration requise', 'reedcrm') . '</p></div>';
        }
        ?>
        <div class="wrap">
            <h1><?php echo esc_html__('ReedCRM Settings','reedcrm'); ?></h1>
            <?php echo $connection_status; ?>
            <form method="post" action="options.php" id="reedcrm-settings-form">
                <?php
                settings_fields('reedcrm_settings_group');
                do_settings_sections('reedcrm-settings');
                ?>
                <p class="submit">
                    <?php submit_button(esc_html__('Enregistrer les modifications', 'reedcrm'), 'primary', 'submit', false); ?>
                </p>
                <div id="connection-result" style="margin-top: 10px;"></div>
            </form>
        </div>
        <?php
    }
}
