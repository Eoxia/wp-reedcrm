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

        // Add form settings using modern API
        add_filter( 'gform_form_settings_fields', [ __CLASS__, 'add_form_settings_fields' ], 10, 2 );

        add_action( 'gform_field_advanced_settings', [ __CLASS__, 'reedcrm_advanced_settings'], 10, 2 );
        //Action to inject supporting script to the form editor page
        add_action( 'gform_editor_js', [ __CLASS__, 'editor_script'] );
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
                    $fields[ $field->id ] = $field->reedcrm_dolibarr_field ?? (!empty($field->adminLabel) ? $field->adminLabel : $field->label);
                }
            }

            $projects       = [];
            $success_count  = 0;
            $error_count    = 0;
            $errors         = [];

            foreach ( $entries as $entry_id ) {
                $entry     = \GFAPI::get_entry( $entry_id );

                $notes       = \GFAPI::get_notes( array( 'entry_id' => $entry_id ) );
                $description = '';
                foreach ( $notes as $note ) {
                    if (empty($note->user_id)) {
                        continue;
                    }
                    $description .= $note->user_name . ' (' . $note->date_created . "): " . $note->value . "<br>";
                }

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
                    'reedcrm/createProject',
                    [
                        'title'      => $projects[ $entry_id ]['title'] ?? $projects[ $entry_id ]['Société'] ?? '',
                        'lastname'   => $projects[ $entry_id ]['lastname'] ?? $projects[ $entry_id ]['Nom'] ?? '',
                        'firstname'  => $projects[ $entry_id ]['firstname'] ?? $projects[ $entry_id ]['Prénom'] ?? '',
                        'email'      => $projects[ $entry_id ]['email'] ?? $projects[ $entry_id ]['E-mail'] ?? '',
                        'phone'      => $projects[ $entry_id ]['phone'] ?? $projects[ $entry_id ]['Téléphone'] ?? '',
                        'date_start' => strtotime( $entry['date_created'] ),
                        'description'=> $projects[ $entry_id ]['Commentaires'] ?? '',
                        'categories' => !empty($form['reedcrm_categorie']) && $form['reedcrm_categorie'] != 1 ? $form['reedcrm_categorie'] : ''
                    ]
                );

                if ( ! $result ) {
                    $error_count++;
                    $errors[] = "Entrée ID $entry_id : Erreur lors de l'envoi vers Dolibarr.";
                } else {
                    $success_count++;
                    gform_delete_meta( $entry_id, 'easycrm_project_id' );
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

    /**
     * Add form settings for EasyCRM auto-sending using modern API
     */
    public static function add_form_settings_fields( $fields, $form ) {

        $res = API_Client::get('categories');
        $project_type_id = current($res)->MAP_ID->project;
        $filtered = array_filter($res, fn($cat) => $cat->type == $project_type_id);

        $categories = array_map(function ($item) {
            return [
                'label' => $item->label,
                'value' => $item->id,
            ];
        }, $filtered);
        $categories[] = ['label' => __('Selectionnez une catégorie'), 'value' => -1];

        $fields['reedcrm_settings'] = [
            'title'       => esc_html__( 'Paramètres ReedCRM', 'reedcrm' ),
            'description' => '',
            'fields'      => [
                [
                    'name'          => 'reedcrm_auto_send',
                    'type'          => 'checkbox',
                    'label'         => esc_html__( 'Envoi automatique vers Dolibarr', 'reedcrm' ),
                    'description'   => esc_html__( 'Activer l\'envoi automatique des entrées vers Dolibarr via cron', 'reedcrm' ),
                    'choices'       => [
                        [
                            'name'  => 'easycrm_auto_send',
                            'label' => esc_html__( 'Activer l\'envoi automatique', 'reedcrm' ),
                            'value' => '1'
                        ]
                    ],
                    'default_value' => '0'
                ],
                [
                    'name'        => 'reedcrm_categorie',
                    'type'        => 'select',
                    'label'       => esc_html__( 'Catégorie par défaut', 'reedcrm' ),
                    'description' => esc_html__( 'Choisissez la catégorie par défaut du projet dans Dolibarr', 'reedcrm' ),
                    'choices'     => $categories,
                    'default_value' => -1
                ]
            ]
        ];

        return $fields;
    }

    /**
     * Check if auto-send is enabled for a form
     */
    public static function is_auto_send_enabled( $form_id ) {
        $form = \GFAPI::get_form( $form_id );
        return isset( $form['reedcrm_auto_send'] ) && $form['reedcrm_auto_send'] === '1';
    }

    public static function reedcrm_advanced_settings( $position, $form_id ) {
        //create settings on position -1 (right after Field Label)
        if ( $position == -1 ) {
            ?>
            <li class="reedcrm_dolibarr_field_setting field_setting">
                <label class="section_label"><?= _e('Champ Dolibarr', 'reedcrm') ?></label>
                <select id="field_reedcrm_dolibarr_name_value" onchange="SetFieldProperty('reedcrm_dolibarr_field', this.value);">
                    <option value="">-- Sélectionnez --</option>
                    <option value="title"><?php _e("Titre", 'reedcrm'); ?></option>
                    <option value="lastname-firstname"><?php _e("Nom et Prénom", 'reedcrm'); ?></option>
                    <option value="lastname"><?php _e("Nom", 'reedcrm'); ?></option>
                    <option value="firstname"><?php _e("Prénom", 'reedcrm'); ?></option>
                    <option value="email"><?php _e("E-mail", 'reedcrm'); ?></option>
                    <option value="phone"><?php _e("Téléphone", 'reedcrm'); ?></option>
                </select>
            </li>
            <?php
        }
    }

    public static function editor_script(){
        ?>
        <script type='text/javascript'>
            jQuery(document).bind('gform_load_field_settings', function(event, field, form){
                jQuery('.reedcrm_dolibarr_field_setting select').val(field.reedcrm_dolibarr_field || '');
                jQuery('.reedcrm_dolibarr_field_setting').show();
            });
        </script>
        <?php
    }
}