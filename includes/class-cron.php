<?php
namespace ReedCRM;

use Error;

if ( ! defined( 'ABSPATH' ) ) exit;

class Cron {

    const HOOK_NAME = 'reedcrm_sync_dolibarr';

    public static function init(){
        add_action('init', [__CLASS__, 'schedule_cron']);
        add_action(self::HOOK_NAME, [__CLASS__, 'execute_sync']);
        add_filter('cron_schedules', [__CLASS__, 'add_custom_intervals']);

        // Hook pour gérer l'activation/désactivation du cron lors de la sauvegarde des options
        add_action('update_option_reedcrm_cron_enabled', [__CLASS__, 'handle_cron_toggle'], 10, 2);
        add_action('update_option_reedcrm_cron_interval', [__CLASS__, 'handle_interval_change'], 10, 2);
    }

    public static function add_custom_intervals($schedules){

        $schedules['every_5_minutes'] = [
            'interval' => 5 * MINUTE_IN_SECONDS,
            'display' => esc_html__('Toutes les 5 minutes', 'reedcrm')
        ];

        $schedules['every_15_minutes'] = [
            'interval' => 15 * MINUTE_IN_SECONDS,
            'display' => esc_html__('Toutes les 15 minutes', 'reedcrm')
        ];

        $schedules['every_30_minutes'] = [
            'interval' => 30 * MINUTE_IN_SECONDS,
            'display' => esc_html__('Toutes les 30 minutes', 'reedcrm')
        ];

        return $schedules;
    }

    public static function schedule_cron(){
        $cron_enabled = get_option('reedcrm_cron_enabled', false);

        if ($cron_enabled && !wp_next_scheduled(self::HOOK_NAME)) {
            $interval = get_option('reedcrm_cron_interval', 'hourly');
            error_log('ReedCRM: Scheduling cron with interval ' . $interval);
            $r = wp_schedule_event(time(), $interval, self::HOOK_NAME);
            error_log($r == false ? 'ReedCRM: Failed to schedule cron' : 'ReedCRM: Cron scheduled successfully');
        }
    }

    public static function unschedule_cron(){
        $timestamp = wp_next_scheduled(self::HOOK_NAME);
        if ($timestamp) {
            wp_unschedule_event($timestamp, self::HOOK_NAME);
        }
    }

    public static function handle_cron_toggle($old_value, $new_value){
        if ($new_value) {
            // Activer le cron
            if (!wp_next_scheduled(self::HOOK_NAME)) {
                $interval = get_option('reedcrm_cron_interval', 'hourly');
                wp_schedule_event(time(), $interval, self::HOOK_NAME);
            }
        } else {
            // Désactiver le cron
            self::unschedule_cron();
        }
    }

    public static function handle_interval_change($old_value, $new_value){
        $cron_enabled = get_option('reedcrm_cron_enabled', false);

        if ($cron_enabled) {
            // Reprogrammer avec le nouvel intervalle
            self::unschedule_cron();
            wp_schedule_event(time(), $new_value, self::HOOK_NAME);
        }
    }

    public static function execute_sync(){
        $api_key = get_option('reedcrm_api_key', '');
        $api_url = get_option('reedcrm_api_url', '');

        if (empty($api_key) || empty($api_url)) {
            error_log('ReedCRM: Configuration API manquante pour la synchronisation');
            return false;
        }

        try {
            $result = self::sync_with_dolibarr($api_url, $api_key);

            if ($result['success']) {
                error_log('ReedCRM: Synchronisation réussie - ' . $result['message']);
            } else {
                error_log('ReedCRM: Erreur de synchronisation - ' . $result['message']);
            }

            return $result['success'];

        } catch (Exception $e) {
            error_log('ReedCRM: Exception lors de la synchronisation - ' . $e->getMessage());
            return false;
        }
    }

    private static function sync_with_dolibarr($api_url, $api_key){

        // list all Gravityforms forms
        if (class_exists('GFAPI')) {
            $forms = \GFAPI::get_forms(); // Récupère tous les formulaires
            foreach ($forms as $form) {
                if (!isset($form['easycrm_auto_send']) || $form['easycrm_auto_send'] !== '1') {
                    continue; // Ignorer les formulaires non marqués pour la synchronisation
                }

                $fields = [];

                foreach ( $form['fields'] as $field ) {
                    if ( ! empty( $field->inputs ) ) {
                        foreach ( $field->inputs as $input ) {
                            $fields[ $input['id'] ] = $input['label'];
                        }
                    } else {
                        $fields[ $field->id ] = !empty($field->adminLabel) ? $field->adminLabel : $field->label;
                    }
                }

                $entries = \GFAPI::get_entries($form['id']);

                $projects       = [];
                $success_count  = 0;
                $error_count    = 0;
                $errors         = [];

                foreach ( $entries as $entry ) {
                    $projectId = gform_get_meta( $entry['id'], 'easycrm_project_id' );

                    if ( ! empty( $projectId ) ) {
                        // Update projet existant
                        $result = API_Client::put(
                            'projects/' . $projectId,
                            [ 'date_start' => strtotime( $entry['date_created'] ) ]
                        );

                        if ( ! $result ) {
                            $error_count++;
                            $errors[] = "Entrée ID {$entry['id']} : Erreur lors de la mise à jour du projet dans Dolibarr.";
                        }
                        continue;
                    }

                    // Nouveau projet
                    $projects[ $entry['id'] ] = [];

                    foreach ( $fields as $field_id => $field_label ) {
                        if ( isset( $entry[ $field_id ] ) ) {
                            $projects[ $entry['id'] ][ $field_label ] = $entry[ $field_id ];
                        }
                    }

                    $result = API_Client::post(
                        'reedcrm/createProject',
                        [
                            'title'      => $projects[ $entry['id'] ]['Société'] ?? '',
                            'lastname'   => $projects[ $entry['id'] ]['Nom'] ?? '',
                            'firstname'  => $projects[ $entry['id'] ]['Prénom'] ?? '',
                            'email'      => $projects[ $entry['id'] ]['E-mail'] ?? '',
                            'phone'      => $projects[ $entry['id'] ]['Téléphone'] ?? '',
                            'date_start' => strtotime( $entry['date_created'] ),
                        ]
                    );

                    if ( ! $result ) {
                        $error_count++;
                        $errors[] = "Entrée ID {$entry['id']} : Erreur lors de l'envoi vers Dolibarr.";
                    } else {
                        $success_count++;
                        gform_update_meta( $entry['id'], 'easycrm_project_id', $result->project_id );
                    }
                }
            }
        }

        return [
            'success' => true,
            'message' => $data['message'] ?? 'Synchronisation terminée'
        ];
    }

    public static function get_next_scheduled(){
        $timestamp = wp_next_scheduled(self::HOOK_NAME);
        return $timestamp ? $timestamp : false;
    }

    public static function is_scheduled(){
        return wp_next_scheduled(self::HOOK_NAME) !== false;
    }

    public static function force_sync(){
        return self::execute_sync();
    }
}
