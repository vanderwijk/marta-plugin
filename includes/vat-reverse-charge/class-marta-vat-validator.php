<?php
/**
 * VAT validator.
 *
 * @package Marta
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Marta_VAT_Validator {

	/**
	 * VIES client.
	 *
	 * @var Marta_VIES_Client
	 */
	private $vies;

	/**
	 * VAT formats.
	 *
	 * @var array<string,string>
	 */
	private $formats;

	/**
	 * Constructor.
	 *
	 * @param Marta_VIES_Client $vies VIES client.
	 */
	public function __construct( Marta_VIES_Client $vies ) {
		$this->vies    = $vies;
		$this->formats = require __DIR__ . '/data/eu-vat-formats.php';
	}

	/**
	 * Validate VAT and call VIES.
	 *
	 * @param string $billing_country Country code.
	 * @param string $raw_vat Raw VAT number.
	 * @return array<string,mixed>
	 */
	public function validate( string $billing_country, string $raw_vat ): array {
		$billing_country = strtoupper( trim( $billing_country ) );
		$raw_vat         = strtoupper( trim( $raw_vat ) );

		if ( ! $this->is_eu_country( $billing_country ) ) {
			return array(
				'status' => 'invalid',
				'reason' => 'not_eu',
			);
		}

		$normalized = preg_replace( '/[\s\.\-]/', '', $raw_vat );
		$prefix     = substr( $normalized, 0, 2 );

		if ( isset( $this->formats[ $prefix ] ) ) {
			if ( $prefix !== $billing_country && !( 'EL' === $prefix && 'GR' === $billing_country ) ) {
				return array(
					'status' => 'invalid',
					'reason' => 'country_mismatch',
				);
			}

			$normalized = substr( $normalized, 2 );
		}

		if ( 'GR' === $billing_country ) {
			$billing_country = 'EL';
		}

		if ( ! isset( $this->formats[ $billing_country ] ) ) {
			return array(
				'status' => 'invalid',
				'reason' => 'format',
			);
		}

		if ( ! preg_match( $this->formats[ $billing_country ], $normalized ) ) {
			return array(
				'status' => 'invalid',
				'reason' => 'format',
			);
		}

		$result = $this->vies->check( $billing_country, $normalized );

		if ( 'invalid' === $result['status'] ) {
			$result['reason'] = 'vies_invalid';
		}

		$result['country_code'] = $billing_country;
		$result['vat_number']   = $normalized;

		return $result;
	}

	/**
	 * Determine if country is in VAT format map.
	 *
	 * @param string $country_code Country code.
	 * @return bool
	 */
	public function is_eu_country( string $country_code ): bool {
		$country_code = strtoupper( $country_code );

		if ( 'GR' === $country_code ) {
			$country_code = 'EL';
		}

		return isset( $this->formats[ $country_code ] );
	}
}
