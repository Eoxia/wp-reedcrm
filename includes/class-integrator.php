<?php
/**
 * Gravity Forms Integration for ReedCRM
 *
 * @package ReedCRM
 */

namespace ReedCRM;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Gravity Forms Integration Class
 */
class Integrator {

    /**
     * Initialize hooks
     */
    public static function init_hooks() {
        add_filter( 'gform_entry_list_columns', [ __CLASS__, 'add_easycrm_column' ] );
        add_filter( 'gform_entries_column_filter', [ __CLASS__, 'populate_easycrm_column' ], 10, 4 );
        add_filter( 'gform_entry_list_bulk_actions', [ __CLASS__, 'add_bulk_action' ], 10, 2 );
        add_action( 'gform_entry_list_action', [ __CLASS__, 'handle_bulk_action' ], 10, 3 );
    }

    /**
     * Add EasyCRM status column to entry list
     */
    public static function add_easycrm_column( $columns ) {
        $position = count( $columns ) - 1;

        return array_slice( $columns, 0, $position, true )
            + [ 'easycrm' => 'Ajout EasyCRM' ]
            + array_slice( $columns, $position, null, true );
    }

    /**
     * Populate EasyCRM status column
     */
    public static function populate_easycrm_column( $value, $form_id, $field_id, $entry ) {
        if ( $field_id === 'easycrm' ) {

            $dolibarr_url = API_Client::get_api_url();

            if ( ! empty( gform_get_meta( $entry['id'], 'easycrm_project_id' ) ) ) {
                return '<span><a href="' . esc_url( $dolibarr_url . '/projet/card.php?id=' . gform_get_meta( $entry['id'], 'easycrm_project_id' ) ) . '" style="color: #32CD32; font-weight: bold;" target="_blank">Importé <span class="dashicons dashicons-external"></span></a></span>';
            } else {
                return '<span style="color: #DC143C; font-weight: bold;">Non importé</span>';
            }
        }
        return $value;
    }

    /**
     * Add custom bulk action
     */
    public static function add_bulk_action( $actions, $form_id ) {
        return array_merge( [ 'send_dolibarr' => 'Envoyer dans Dolibarr' ], $actions );
    }

    /**
     * Handle bulk action execution
     */
    public static function handle_bulk_action( $action, $entries, $form_id ) {
        if ( $action === 'send_dolibarr' ) {
            $form   = \GFAPI::get_form( $form_id );
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

            $projects       = [];
            $success_count  = 0;
            $error_count    = 0;
            $errors         = [];

            foreach ( $entries as $entry_id ) {
                $entry     = \GFAPI::get_entry( $entry_id );
                $projectId = gform_get_meta( $entry_id, 'easycrm_project_id' );

                if ( ! empty( $projectId ) ) {
                    // Update projet existant
                    $result = API_Client::put(
                        'projects/' . $projectId,
                        [ 'date_start' => strtotime( $entry['date_created'] ) ]
                    );

                    if ( ! $result ) {
                        $error_count++;
                        $errors[] = "Entrée ID $entry_id : Erreur lors de la mise à jour du projet dans Dolibarr.";
                    }

                    continue;
                }

                // Nouveau projet
                $projects[ $entry_id ] = [];

                foreach ( $fields as $field_id => $field_label ) {
                    if ( isset( $entry[ $field_id ] ) ) {
                        $projects[ $entry_id ][ $field_label ] = $entry[ $field_id ];
                    }
                }

                $result = API_Client::post(
                    'easycrm/createProject',
                    [
                        'title'      => $projects[ $entry_id ]['Société'] ?? '',
                        'lastname'   => $projects[ $entry_id ]['Nom'] ?? '',
                        'firstname'  => $projects[ $entry_id ]['Prénom'] ?? '',
                        'email'      => $projects[ $entry_id ]['E-mail'] ?? '',
                        'phone'      => $projects[ $entry_id ]['Téléphone'] ?? '',
                        'date_start' => strtotime( $entry['date_created'] ),
                    ]
                );

                if ( ! $result ) {
                    $error_count++;
                    $errors[] = "Entrée ID $entry_id : Erreur lors de l'envoi vers Dolibarr.";
                } else {
                    $success_count++;
                    gform_update_meta( $entry_id, 'easycrm_project_id', $result->project_id );
                }
            }

            // Redirection avec feedback
            $redirect_url = add_query_arg(
                [
                    'success_count' => $success_count,
                    'error_count'   => $error_count,
                    'errors'        => $error_count > 0 ? base64_encode( json_encode( $errors ) ) : '',
                ],
                wp_get_referer()
            );

            wp_redirect( $redirect_url );
            exit;
        }
    }
}