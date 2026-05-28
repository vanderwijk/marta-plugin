<?php
/**
 * VIES transport diagnostics.
 *
 * Temporary tool to determine which PHP HTTP transport on this host can
 * successfully reach the EU VIES SOAP endpoint. After we know which one
 * works, the VIES client should be patched to use it and this file deleted.
 *
 * Access: WP Admin -> Tools -> Marta VIES Diagnostics (requires manage_options).
 *
 * @package Marta
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Marta_VAT_Diagnostics {

	private const ENDPOINT     = 'https://ec.europa.eu/taxation_customs/vies/services/checkVatService';
	private const REST_ENDPOINT = 'https://ec.europa.eu/taxation_customs/vies/rest-api/check-vat-number';
	private const COUNTRY      = 'DE';
	private const VAT_NUMBER   = '129273398'; // SAP SE — known-valid, safe to use as a public test value.

	/**
	 * Register the admin tools page.
	 */
	public function register(): void {
		add_action( 'admin_menu', array( $this, 'add_menu' ) );
	}

	public function add_menu(): void {
		add_management_page(
			'Marta VIES Diagnostics',
			'Marta VIES Diagnostics',
			'manage_options',
			'marta-vies-diagnostics',
			array( $this, 'render_page' )
		);
	}

	public function render_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Insufficient permissions.' );
		}

		echo '<div class="wrap"><h1>Marta VIES Diagnostics</h1>';
		echo '<p>This page runs three different PHP HTTP transports against the EU VIES SOAP endpoint and reports which (if any) succeed. It is meant as a one-off diagnostic; delete the class file once the cause is identified.</p>';

		$this->section_environment();
		$this->section_transport_curl();
		$this->section_transport_streams();
		$this->section_transport_soapclient();
		$this->section_transport_rest_streams();

		echo '</div>';
	}

	private function h2( string $title ): void {
		echo '<h2 style="margin-top:2em">' . esc_html( $title ) . '</h2>';
	}

	private function kv( string $key, string $value ): void {
		echo '<p><strong>' . esc_html( $key ) . ':</strong> <code>' . esc_html( $value ) . '</code></p>';
	}

	private function pre( string $value ): void {
		echo '<pre style="background:#f6f7f7;border:1px solid #ccd0d4;padding:10px;max-height:300px;overflow:auto;white-space:pre-wrap;word-break:break-word">'
			. esc_html( $value )
			. '</pre>';
	}

	private function section_environment(): void {
		$this->h2( '0. Environment' );
		$this->kv( 'PHP version', PHP_VERSION );
		$this->kv( 'OpenSSL (PHP-linked)', defined( 'OPENSSL_VERSION_TEXT' ) ? OPENSSL_VERSION_TEXT : 'unknown' );
		$curl = function_exists( 'curl_version' ) ? curl_version() : array();
		$this->kv( 'cURL version', isset( $curl['version'] ) ? $curl['version'] : 'unknown' );
		$this->kv( 'cURL SSL backend', isset( $curl['ssl_version'] ) ? $curl['ssl_version'] : 'unknown' );
		$this->kv( 'soap extension loaded', extension_loaded( 'soap' ) ? 'yes' : 'NO' );
		$this->kv( 'allow_url_fopen', ini_get( 'allow_url_fopen' ) ? 'yes' : 'NO' );
		$this->kv( 'WordPress version', get_bloginfo( 'version' ) );
	}

	private function envelope(): string {
		return '<?xml version="1.0" encoding="UTF-8"?>'
			. '<soap:Envelope xmlns:soap="http://schemas.xmlsoap.org/soap/envelope/"'
			. ' xmlns:tns="urn:ec.europa.eu:taxud:vies:services:checkVat:types">'
			. '<soap:Body><tns:checkVat>'
			. '<tns:countryCode>' . self::COUNTRY . '</tns:countryCode>'
			. '<tns:vatNumber>' . self::VAT_NUMBER . '</tns:vatNumber>'
			. '</tns:checkVat></soap:Body></soap:Envelope>';
	}

	private function section_transport_curl(): void {
		$this->h2( '1. Transport: wp_remote_post (cURL) — current method' );

		$start    = microtime( true );
		$response = wp_remote_post(
			self::ENDPOINT,
			array(
				'timeout'     => 8,
				'httpversion' => '1.1',
				'headers'     => array(
					'Content-Type' => 'text/xml; charset=utf-8',
					'SOAPAction'   => '',
					'User-Agent'   => 'marta-plugin/diagnostics',
					'Accept'       => 'text/xml',
				),
				'body'        => $this->envelope(),
			)
		);
		$elapsed = number_format( ( microtime( true ) - $start ) * 1000, 0 );

		if ( is_wp_error( $response ) ) {
			$this->kv( 'Result', 'FAIL (WP_Error)' );
			$this->kv( 'Elapsed (ms)', $elapsed );
			$this->kv( 'Error code', $response->get_error_code() );
			$this->pre( $response->get_error_message() );

			return;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$body = (string) wp_remote_retrieve_body( $response );
		$this->kv( 'Result', 200 === $code ? 'OK' : 'HTTP ' . $code );
		$this->kv( 'Elapsed (ms)', $elapsed );
		$this->kv( 'Body length', (string) strlen( $body ) );
		$this->kv( 'Contains <valid>true</valid>', false !== stripos( $body, '<valid>true</valid>' ) ? 'yes' : 'no' );
		$this->pre( substr( $body, 0, 1500 ) );
	}

	private function section_transport_streams(): void {
		$this->h2( '2. Transport: file_get_contents (PHP streams)' );

		if ( ! ini_get( 'allow_url_fopen' ) ) {
			$this->kv( 'Result', 'SKIPPED (allow_url_fopen is off)' );

			return;
		}

		$ctx = stream_context_create(
			array(
				'http' => array(
					'method'        => 'POST',
					'header'        =>
						"Content-Type: text/xml; charset=utf-8\r\n" .
						"SOAPAction: \r\n" .
						"User-Agent: marta-plugin/diagnostics\r\n" .
						"Accept: text/xml\r\n" .
						"Connection: close\r\n",
					'content'       => $this->envelope(),
					'timeout'       => 8,
					'ignore_errors' => true,
					'protocol_version' => 1.1,
				),
				'ssl'  => array(
					'verify_peer'      => true,
					'verify_peer_name' => true,
					'SNI_enabled'      => true,
				),
			)
		);

		$start = microtime( true );
		set_error_handler(
			function ( $errno, $errstr ) use ( &$capture_err ) {
				$capture_err = $errstr;

				return true;
			}
		);
		$body = @file_get_contents( self::ENDPOINT, false, $ctx );
		restore_error_handler();
		$elapsed = number_format( ( microtime( true ) - $start ) * 1000, 0 );

		if ( false === $body ) {
			$this->kv( 'Result', 'FAIL' );
			$this->kv( 'Elapsed (ms)', $elapsed );
			$this->pre( isset( $capture_err ) ? $capture_err : '(no error message captured)' );

			return;
		}

		$status_line = isset( $http_response_header[0] ) ? $http_response_header[0] : 'unknown';
		$this->kv( 'Result', 'OK' );
		$this->kv( 'Elapsed (ms)', $elapsed );
		$this->kv( 'Status line', $status_line );
		$this->kv( 'Body length', (string) strlen( $body ) );
		$this->kv( 'Contains <valid>true</valid>', false !== stripos( $body, '<valid>true</valid>' ) ? 'yes' : 'no' );
		$this->pre( substr( $body, 0, 1500 ) );
	}

	private function section_transport_soapclient(): void {
		$this->h2( '3. Transport: PHP SoapClient' );

		if ( ! class_exists( 'SoapClient' ) ) {
			$this->kv( 'Result', 'SKIPPED (soap extension not loaded)' );

			return;
		}

		$start = microtime( true );
		try {
			$client = new SoapClient(
				self::ENDPOINT . '?wsdl',
				array(
					'connection_timeout' => 8,
					'cache_wsdl'         => WSDL_CACHE_NONE,
					'user_agent'         => 'marta-plugin/diagnostics',
					'exceptions'         => true,
				)
			);

			$result  = $client->checkVat(
				array(
					'countryCode' => self::COUNTRY,
					'vatNumber'   => self::VAT_NUMBER,
				)
			);
			$elapsed = number_format( ( microtime( true ) - $start ) * 1000, 0 );

			$valid = isset( $result->valid ) ? ( $result->valid ? 'true' : 'false' ) : 'missing';
			$name  = isset( $result->name ) ? (string) $result->name : '';
			$this->kv( 'Result', 'OK' );
			$this->kv( 'Elapsed (ms)', $elapsed );
			$this->kv( 'valid', $valid );
			$this->kv( 'name', $name );
			$this->pre( print_r( $result, true ) );
		} catch ( \Throwable $e ) {
			$elapsed = number_format( ( microtime( true ) - $start ) * 1000, 0 );
			$this->kv( 'Result', 'FAIL (' . get_class( $e ) . ')' );
			$this->kv( 'Elapsed (ms)', $elapsed );
			$this->pre( $e->getMessage() );
		}
	}

	private function section_transport_rest_streams(): void {
		$this->h2( '4. Transport: REST endpoint via wp_remote_post (sanity check)' );

		$start    = microtime( true );
		$response = wp_remote_post(
			self::REST_ENDPOINT,
			array(
				'timeout'     => 8,
				'httpversion' => '1.1',
				'headers'     => array(
					'Content-Type' => 'application/json',
					'User-Agent'   => 'marta-plugin/diagnostics',
					'Accept'       => 'application/json',
				),
				'body'        => wp_json_encode(
					array(
						'countryCode' => self::COUNTRY,
						'vatNumber'   => self::VAT_NUMBER,
					)
				),
			)
		);
		$elapsed = number_format( ( microtime( true ) - $start ) * 1000, 0 );

		if ( is_wp_error( $response ) ) {
			$this->kv( 'Result', 'FAIL (WP_Error)' );
			$this->kv( 'Elapsed (ms)', $elapsed );
			$this->kv( 'Error code', $response->get_error_code() );
			$this->pre( $response->get_error_message() );

			return;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$body = (string) wp_remote_retrieve_body( $response );
		$this->kv( 'Result', 200 === $code ? 'OK' : 'HTTP ' . $code );
		$this->kv( 'Elapsed (ms)', $elapsed );
		$this->kv( 'Body length', (string) strlen( $body ) );
		$this->pre( substr( $body, 0, 1500 ) );
	}
}
