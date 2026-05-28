<?php
/**
 * VAT reverse-charge self test command.
 *
 * @package Marta
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Marta_VAT_Self_Test {

	/**
	 * Register command.
	 *
	 * @return void
	 */
	public function register(): void {
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			WP_CLI::add_command( 'marta vat-self-test', array( $this, 'run' ) );
		}
	}

	/**
	 * Run smoke tests.
	 *
	 * @return void
	 */
	public function run(): void {
		$vies      = new Marta_VIES_Client();
		$validator = new Marta_VAT_Validator( $vies );
		$tax       = new Marta_VAT_Tax();
		$failures  = array();

		add_filter(
			'marta_vies_mock_result',
			static function ( $mock, $country, $number ) {
				if ( 'DE' === $country && '129273398' === $number ) {
					return array(
						'status'     => 'valid',
						'name'       => 'SAP',
						'address'    => null,
						'checked_at' => gmdate( 'c' ),
						'raw'        => array( 'mock' => true ),
					);
				}

				return array(
					'status'     => 'invalid',
					'name'       => null,
					'address'    => null,
					'checked_at' => gmdate( 'c' ),
					'raw'        => array( 'mock' => true ),
				);
			},
			10,
			3
		);

		$result_valid = $validator->validate( 'DE', 'DE129273398' );
		$this->assert( 'valid' === $result_valid['status'], 'DE valid VAT returns valid status', $failures );

		$result_bad_format = $validator->validate( 'DE', 'XYZ' );
		$this->assert(
			'invalid' === $result_bad_format['status'] && 'format' === $result_bad_format['reason'],
			'Format-invalid VAT returns invalid/format',
			$failures
		);

		$result_invalid_vies = $validator->validate( 'DE', 'DE000000000' );
		$this->assert(
			'invalid' === $result_invalid_vies['status'] && 'vies_invalid' === $result_invalid_vies['reason'],
			'VIES-invalid VAT returns invalid/vies_invalid',
			$failures
		);

		$result_not_eu = $validator->validate( 'US', 'US123' );
		$this->assert(
			'invalid' === $result_not_eu['status'] && 'not_eu' === $result_not_eu['reason'],
			'Non-EU country returns invalid/not_eu',
			$failures
		);

		$should_apply = $tax->should_apply_reverse_charge(
			'DE',
			'NL',
			array(
				'status'       => 'valid',
				'country_code' => 'DE',
			),
			true,
			true
		);
		$this->assert( true === $should_apply, 'Reverse charge applies DE->NL for valid VAT', $failures );

		$should_not_apply = $tax->should_apply_reverse_charge(
			'NL',
			'NL',
			array(
				'status'       => 'valid',
				'country_code' => 'NL',
			),
			true,
			true
		);
		$this->assert( false === $should_not_apply, 'Reverse charge not applied domestically', $failures );

		if ( empty( $failures ) ) {
			WP_CLI::success( 'All Marta VAT self-tests passed.' );
			return;
		}

		foreach ( $failures as $failure ) {
			WP_CLI::warning( $failure );
		}

		WP_CLI::error( 'Marta VAT self-test failed.' );
	}

	/**
	 * Push failure when assertion is false.
	 *
	 * @param bool          $condition Condition.
	 * @param string        $message Message.
	 * @param array<int,string> $failures Failure list.
	 * @return void
	 */
	private function assert( bool $condition, string $message, array &$failures ): void {
		if ( ! $condition ) {
			$failures[] = $message;
		}
	}
}
