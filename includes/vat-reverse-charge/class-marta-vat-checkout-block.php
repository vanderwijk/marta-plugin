<?php
/**
 * Block checkout VAT integration.
 *
 * @package Marta
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Marta_VAT_Checkout_Block {

	/**
	 * Validator.
	 *
	 * @var Marta_VAT_Validator
	 */
	private $validator;

	/**
	 * Constructor.
	 *
	 * @param Marta_VAT_Validator $validator Validator.
	 */
	public function __construct( Marta_VAT_Validator $validator ) {
		$this->validator = $validator;
	}

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'woocommerce_init', array( $this, 'register_checkout_field' ) );
	}

	/**
	 * Register additional checkout field if available.
	 *
	 * @return void
	 */
	public function register_checkout_field(): void {
		if ( ! function_exists( 'woocommerce_register_additional_checkout_field' ) ) {
			return;
		}

		woocommerce_register_additional_checkout_field(
			array(
				'id'                => 'marta/vat-number',
				'label'             => __( 'VAT number (EU business)', 'marta' ),
				'location'          => 'address',
				'type'              => 'text',
				'required'          => false,
				'attributes'        => array(
					'autocomplete' => 'off',
					'pattern'      => '[A-Za-z0-9 .-]{4,20}',
				),
				'sanitize_callback' => function ( $value ) {
					return strtoupper( preg_replace( '/[^A-Za-z0-9]/', '', (string) $value ) );
				},
				'validate_callback' => array( $this, 'validate_field' ),
			)
		);
	}

	/**
	 * Validate checkout field.
	 *
	 * @param string                  $value Field value.
	 * @param string                  $group Group.
	 * @param WC_Customer|WC_Order    $wc_object Object.
	 * @return true|WP_Error
	 */
	public function validate_field( $value, $group, $wc_object ) {
		$value = (string) $value;

		if ( '' === trim( $value ) ) {
			Marta_VAT_Tax::set_session_validation( array() );
			Marta_VAT_Tax::set_vies_unreachable( false );
			return true;
		}

		$country = '';
		if ( $wc_object instanceof WC_Customer ) {
			$country = (string) $wc_object->get_billing_country();
		} elseif ( $wc_object instanceof WC_Order ) {
			$country = (string) $wc_object->get_billing_country();
		}

		$result = $this->validator->validate( strtoupper( $country ), $value );

		if ( 'valid' === $result['status'] ) {
			$result['raw_input'] = strtoupper( preg_replace( '/[^A-Za-z0-9]/', '', $value ) );
			Marta_VAT_Tax::set_session_validation( $result );
			Marta_VAT_Tax::set_vies_unreachable( false );
			return true;
		}

		if ( 'unreachable' === $result['status'] ) {
			Marta_VAT_Tax::set_session_validation( array() );
			Marta_VAT_Tax::set_vies_unreachable( true );
			return true;
		}

		if ( isset( $result['reason'] ) && 'not_eu' === $result['reason'] ) {
			Marta_VAT_Tax::set_session_validation( array() );
			Marta_VAT_Tax::set_vies_unreachable( false );
			return true;
		}

		return new WP_Error(
			'marta_vat_invalid',
			__( 'This VAT number is not valid in VIES. Please check or leave the field blank.', 'marta' )
		);
	}
}
