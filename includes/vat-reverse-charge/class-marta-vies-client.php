<?php
/**
 * VIES client.
 *
 * @package Marta
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Marta_VIES_Client {

	/**
	 * Validate a VAT number against VIES.
	 *
	 * @param string $country_code Billing country code.
	 * @param string $vat_number VAT number without country prefix.
	 * @return array<string,mixed>
	 */
	public function check( string $country_code, string $vat_number ): array {
		$country_code = strtoupper( trim( $country_code ) );
		$vat_number   = strtoupper( preg_replace( '/[^A-Za-z0-9]/', '', trim( $vat_number ) ) );
		/**
		 * Filter VIES result for local tests or custom integrations.
		 *
		 * Return an array with keys status/name/address/checked_at/raw to short-circuit HTTP calls.
		 *
		 * @param array<string,mixed>|null $mock_result Mock result.
		 * @param string                   $country_code Country code.
		 * @param string                   $vat_number VAT number without prefix.
		 */
		$mock_result = apply_filters( 'marta_vies_mock_result', null, $country_code, $vat_number );
		if ( is_array( $mock_result ) && isset( $mock_result['status'] ) ) {
			return wp_parse_args(
				$mock_result,
				$this->base_result( (string) $mock_result['status'], null )
			);
		}
		$cache_key    = $this->build_cache_key( $country_code, $vat_number );
		$cached       = get_transient( $cache_key );

		if ( is_array( $cached ) && isset( $cached['status'] ) ) {
			return $cached;
		}

		$envelope = '<?xml version="1.0" encoding="UTF-8"?>'
			. '<soap:Envelope xmlns:soap="http://schemas.xmlsoap.org/soap/envelope/"'
			. ' xmlns:tns="urn:ec.europa.eu:taxud:vies:services:checkVat:types">'
			. '<soap:Body><tns:checkVat>'
			. '<tns:countryCode>' . esc_html( $country_code ) . '</tns:countryCode>'
			. '<tns:vatNumber>' . esc_html( $vat_number ) . '</tns:vatNumber>'
			. '</tns:checkVat></soap:Body></soap:Envelope>';

		/*
		 * Transport choice: PHP streams (file_get_contents) rather than wp_remote_post.
		 *
		 * Reason: on hosts running OpenSSL 3.x (e.g. WP Engine on Ubuntu 22.04, PHP 8.2)
		 * the EU VIES TLS endpoint closes its TCP connection without sending a TLS
		 * close_notify alert. cURL linked against OpenSSL 3 treats that as a fatal
		 * SSL_R_UNEXPECTED_EOF_WHILE_READING error (cURL error 56), so wp_remote_post
		 * always fails. PHP's stream wrapper handles the same shutdown gracefully and
		 * returns the response body, so we go straight to streams here. We retain a
		 * wp_remote_post fallback for environments where allow_url_fopen is disabled.
		 */
		$body  = null;
		$error = null;

		if ( ini_get( 'allow_url_fopen' ) ) {
			$ctx = stream_context_create(
				array(
					'http' => array(
						'method'           => 'POST',
						'header'           =>
							"Content-Type: text/xml; charset=utf-8\r\n" .
							"SOAPAction: \r\n" .
							"User-Agent: marta-plugin/1.0 (+https://martaonline.eu)\r\n" .
							"Accept: text/xml\r\n" .
							"Connection: close\r\n",
						'content'          => $envelope,
						'timeout'          => 8,
						'ignore_errors'    => true,
						'protocol_version' => 1.1,
					),
					'ssl'  => array(
						'verify_peer'      => true,
						'verify_peer_name' => true,
						'SNI_enabled'      => true,
					),
				)
			);

			$capture_error = null;
			set_error_handler(
				static function ( $errno, $errstr ) use ( &$capture_error ) {
					$capture_error = $errstr;
					return true;
				}
			);
			$body = @file_get_contents( 'https://ec.europa.eu/taxation_customs/vies/services/checkVatService', false, $ctx );
			restore_error_handler();

			if ( false === $body ) {
				$error = 'stream_error: ' . ( $capture_error ? $capture_error : 'unknown' );
				$body  = null;
			} else {
				$status_line = isset( $http_response_header[0] ) ? $http_response_header[0] : '';
				if ( false === strpos( $status_line, ' 200 ' ) ) {
					$error = 'stream_http_status: ' . $status_line;
					$body  = null;
				}
			}
		} else {
			// Fallback: wp_remote_post (works on hosts with allow_url_fopen disabled and
			// without the OpenSSL 3 close_notify issue).
			$response = wp_remote_post(
				'https://ec.europa.eu/taxation_customs/vies/services/checkVatService',
				array(
					'timeout'     => 8,
					'httpversion' => '1.1',
					'headers'     => array(
						'Content-Type' => 'text/xml; charset=utf-8',
						'SOAPAction'   => '',
						'User-Agent'   => 'marta-plugin/1.0 (+https://martaonline.eu)',
						'Accept'       => 'text/xml',
					),
					'body'        => $envelope,
				)
			);

			if ( is_wp_error( $response ) ) {
				$error = 'wp_error: ' . $response->get_error_message();
			} else {
				$http_code = (int) wp_remote_retrieve_response_code( $response );
				$body      = (string) wp_remote_retrieve_body( $response );
				if ( 200 !== $http_code || '' === $body ) {
					$error = 'http_' . $http_code;
					$body  = null;
				}
			}
		}

		if ( null === $body ) {
			$this->log_unreachable( $country_code, $vat_number, (string) $error );

			return $this->base_result( 'unreachable', null );
		}

		$previous_libxml_state = libxml_use_internal_errors( true );
		$xml                   = simplexml_load_string( $body );
		libxml_clear_errors();
		libxml_use_internal_errors( $previous_libxml_state );

		if ( false === $xml ) {
			$this->log_unreachable( $country_code, $vat_number, 'xml_parse_failed' );

			return $this->base_result( 'unreachable', null );
		}

		$valid_nodes = $xml->xpath( '//*[local-name()="valid"]' );
		$name_nodes  = $xml->xpath( '//*[local-name()="name"]' );
		$addr_nodes  = $xml->xpath( '//*[local-name()="address"]' );
		$fault_nodes = $xml->xpath( '//*[local-name()="Fault"]' );

		if ( $fault_nodes ) {
			$this->log_unreachable( $country_code, $vat_number, 'soap_fault' );

			return $this->base_result( 'unreachable', null );
		}

		$is_valid = $valid_nodes && 'true' === strtolower( trim( (string) $valid_nodes[0] ) );
		$name     = $name_nodes ? trim( (string) $name_nodes[0] ) : null;
		$address  = $addr_nodes ? trim( (string) $addr_nodes[0] ) : null;

		$result = array(
			'status'     => $is_valid ? 'valid' : 'invalid',
			'name'       => ( $name && '---' !== $name ) ? $name : null,
			'address'    => ( $address && '---' !== $address ) ? $address : null,
			'checked_at' => gmdate( 'c' ),
			'raw'        => null,
		);

		if ( $is_valid ) {
			set_transient( $cache_key, $result, 30 * DAY_IN_SECONDS );
		} else {
			set_transient( $cache_key, $result, DAY_IN_SECONDS );
		}

		return $result;
	}

	/**
	 * Delete a cached result for a VAT pair.
	 *
	 * @param string $country_code Country code.
	 * @param string $vat_number VAT number.
	 * @return void
	 */
	public function delete_cache( string $country_code, string $vat_number ): void {
		delete_transient( $this->build_cache_key( $country_code, $vat_number ) );
	}

	/**
	 * Build cache key.
	 *
	 * @param string $country_code Country code.
	 * @param string $vat_number VAT number.
	 * @return string
	 */
	private function build_cache_key( string $country_code, string $vat_number ): string {
		$normalized = strtoupper( preg_replace( '/\s+/', '', $vat_number ) );
		$hash       = md5( $normalized );

		return 'marta_vies_v1_' . strtolower( $country_code ) . '_' . $hash;
	}

	/**
	 * Base result structure.
	 *
	 * @param string               $status Result status.
	 * @param array<string,mixed>|null $raw Raw payload.
	 * @return array<string,mixed>
	 */
	private function base_result( string $status, ?array $raw ): array {
		return array(
			'status'     => $status,
			'name'       => null,
			'address'    => null,
			'checked_at' => gmdate( 'c' ),
			'raw'        => $raw,
		);
	}

	/**
	 * Log unreachable VIES calls with masked VAT.
	 *
	 * @param string $country_code Country code.
	 * @param string $vat_number VAT number.
	 * @param string $reason Failure reason.
	 * @return void
	 */
	private function log_unreachable( string $country_code, string $vat_number, string $reason ): void {
		$masked = $this->mask_vat( $vat_number );
		error_log( sprintf( 'Marta VIES unreachable [%s%s]: %s', strtoupper( $country_code ), $masked, $reason ) );
	}

	/**
	 * Mask VAT number for logs.
	 *
	 * @param string $vat_number VAT number.
	 * @return string
	 */
	private function mask_vat( string $vat_number ): string {
		$length = strlen( $vat_number );

		if ( $length <= 4 ) {
			return str_repeat( '*', $length );
		}

		return substr( $vat_number, 0, 2 ) . str_repeat( '*', $length - 4 ) . substr( $vat_number, -2 );
	}
}
