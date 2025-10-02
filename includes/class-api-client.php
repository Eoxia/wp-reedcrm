<?php
namespace ReedCRM;

if ( ! defined( 'ABSPATH' ) ) exit;

class API_Client {
    public static function init(){}

    public static function get_api_key(){
        return get_option('reedcrm_api_key', '');
    }

    public static function get_api_url(){
        return get_option('reedcrm_api_url', '');
    }

    public static function post( $end_point, $data = array(), $method = 'POST' ) {
		$dolibarr_url = API_Client::get_api_url();

		if ( substr( trim( $dolibarr_url ), strlen( $dolibarr_url ) - 1, 1 ) === '/' ) {
			$dolibarr_url = substr( trim( $dolibarr_url ), 0, strlen( $dolibarr_url ) - 1 );
		}

		$api_url = $dolibarr_url . '/api/index.php/' . $end_point;

		$request = wp_remote_post( $api_url, array(
			'method'    => $method,
			'blocking'  => true,
			'headers'   => array(
				'Content-type' => 'application/json',
				'DOLAPIKEY'    => API_Client::get_api_key(),
			),
			//@todo: Grave selon moi.
			'sslverify' => false,
			'body'      => json_encode( $data ),
		) );

		if ( ! is_wp_error( $request ) ) {
			return json_decode( $request['body'] );
		}

		return false;
	}

	/**
	 * Requête PUT.
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 *
	 * @param  string $end_point L'url a appeler.
	 * @param  array  $data      Les données du formulaire.
	 *
	 * @return array|boolean     Retournes les données de la requête ou false.
	 */
	public static function put( $end_point, $data ) {
		return API_Client::post( $end_point, $data, 'PUT' );
	}

	/**
	 * Requête GET.
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 *
	 * @param string $end_point L'url a appeler.
	 *
	 * @return array|boolean    Retournes les données de la requête ou false.
	 */
	public static function get( $end_point ) {
		$dolibarr_url = API_Client::get_api_url();

		if ( substr( trim( $dolibarr_url ), strlen( $dolibarr_url ) - 1, 1 ) === '/' ) {
			$dolibarr_url = substr( trim( $dolibarr_url ), 0, strlen( $dolibarr_url ) - 1 );
		}

		$api_url = $dolibarr_url . '/api/index.php/' . $end_point;

		$request = wp_remote_get( $api_url, array(
			'headers' => array(
				'Content-type' => 'application/json',
				'DOLAPIKEY'    => API_Client::get_api_key(),
			),
		) );

		if ( ! is_wp_error( $request ) ) {
			if ( 200 === $request['response']['code'] ) {
				if ( strpos( $end_point, 'documents?modulepart=product' ) !== false ) {
					return json_decode(wp_remote_retrieve_body($request), true);
				}
				return json_decode( wp_remote_retrieve_body($request) );
			} else {
				$body = json_decode( wp_remote_retrieve_body($request), true );
				set_transient( 'wps_request_error', $body['error']['message'] ?? '', 60 );
			}
		}

		return false;
	}
}
