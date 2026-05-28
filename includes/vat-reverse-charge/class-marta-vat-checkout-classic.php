<?php
/**
 * Classic checkout VAT integration.
 *
 * @package Marta
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Marta_VAT_Checkout_Classic {

	/**
	 * Validator instance.
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
		add_filter( 'woocommerce_checkout_fields', array( $this, 'add_checkout_field' ) );
		add_action( 'woocommerce_checkout_process', array( $this, 'validate_checkout_field' ) );
		add_action( 'woocommerce_checkout_update_order_review', array( $this, 'on_ajax_recalc' ), 10, 1 );
		add_action( 'woocommerce_checkout_update_order_meta', array( $this, 'update_order_meta' ) );
		add_filter( 'woocommerce_checkout_get_value', array( $this, 'restore_checkout_value' ), 10, 2 );
		add_action( 'wp_footer', array( $this, 'enqueue_checkout_recalc_script' ), 20 );
	}

	/**
	 * Add billing VAT field.
	 *
	 * @param array<string,mixed> $fields Checkout fields.
	 * @return array<string,mixed>
	 */
	public function add_checkout_field( array $fields ): array {
		$fields['billing']['billing_vat_number'] = array(
			'type'        => 'text',
			'label'       => __( 'VAT number (EU business)', 'marta' ),
			'placeholder' => __( 'Optional', 'marta' ),
			'required'    => false,
			'class'       => array( 'form-row-wide', 'update_totals_on_change' ),
			'priority'    => 35,
			'clear'       => true,
		);

		return $fields;
	}

	/**
	 * Validate VAT field during checkout process.
	 *
	 * @return void
	 */
	public function validate_checkout_field(): void {
		$raw_vat = isset( $_POST['billing_vat_number'] ) ? sanitize_text_field( wp_unslash( $_POST['billing_vat_number'] ) ) : '';

		if ( '' === $raw_vat ) {
			Marta_VAT_Tax::set_session_validation( array() );
			Marta_VAT_Tax::set_vies_unreachable( false );
			return;
		}

		$billing_country = isset( $_POST['billing_country'] ) ? wc_strtoupper( sanitize_text_field( wp_unslash( $_POST['billing_country'] ) ) ) : '';
		$result          = $this->validator->validate( $billing_country, $raw_vat );

		if ( 'valid' === $result['status'] ) {
			Marta_VAT_Tax::set_session_validation( $result );
			Marta_VAT_Tax::set_vies_unreachable( false );
			return;
		}

		if ( 'unreachable' === $result['status'] ) {
			Marta_VAT_Tax::set_session_validation( array() );
			Marta_VAT_Tax::set_vies_unreachable( true );
			return;
		}

		if ( isset( $result['reason'] ) && 'not_eu' === $result['reason'] ) {
			Marta_VAT_Tax::set_session_validation( array() );
			Marta_VAT_Tax::set_vies_unreachable( false );
			return;
		}

		if ( isset( $result['reason'] ) && 'format' === $result['reason'] ) {
			wc_add_notice( __( 'VAT number format is invalid for the selected country.', 'marta' ), 'error' );
		} elseif ( isset( $result['reason'] ) && 'country_mismatch' === $result['reason'] ) {
			wc_add_notice( __( 'VAT number country prefix does not match billing country.', 'marta' ), 'error' );
		} else {
			wc_add_notice( __( 'This VAT number is not valid in VIES. Please check or leave the field blank.', 'marta' ), 'error' );
		}
	}

	/**
	 * Validate VAT during AJAX order review recalculation.
	 *
	 * @param string $post_data Serialized checkout form data.
	 * @return void
	 */
	public function on_ajax_recalc( string $post_data ): void {
		$parsed = array();
		wp_parse_str( $post_data, $parsed );

		$country = isset( $parsed['billing_country'] ) ? wc_strtoupper( sanitize_text_field( wp_unslash( $parsed['billing_country'] ) ) ) : '';
		$raw_vat = isset( $parsed['billing_vat_number'] ) ? sanitize_text_field( wp_unslash( $parsed['billing_vat_number'] ) ) : '';

		if ( '' === trim( $raw_vat ) ) {
			Marta_VAT_Tax::set_session_validation( array() );
			Marta_VAT_Tax::set_vies_unreachable( false );
			return;
		}

		$result = $this->validator->validate( $country, $raw_vat );

		if ( 'valid' === $result['status'] ) {
			Marta_VAT_Tax::set_session_validation( $result );
			Marta_VAT_Tax::set_vies_unreachable( false );
			return;
		}

		if ( 'unreachable' === $result['status'] ) {
			Marta_VAT_Tax::set_session_validation( array() );
			Marta_VAT_Tax::set_vies_unreachable( true );
			return;
		}

		Marta_VAT_Tax::set_session_validation(
			array(
				'status'       => 'invalid',
				'country_code' => $country,
				'vat_number'   => strtoupper( preg_replace( '/[\s\.\-]/', '', $raw_vat ) ),
				'checked_at'   => gmdate( 'c' ),
			)
		);
		Marta_VAT_Tax::set_vies_unreachable( false );

		if ( isset( $result['reason'] ) && 'not_eu' === $result['reason'] ) {
			return;
		}

		if ( isset( $result['reason'] ) && 'format' === $result['reason'] ) {
			wc_add_notice( __( 'VAT number format is invalid for the selected country.', 'marta' ), 'error' );
		} elseif ( isset( $result['reason'] ) && 'country_mismatch' === $result['reason'] ) {
			wc_add_notice( __( 'VAT number country prefix does not match billing country.', 'marta' ), 'error' );
		} else {
			wc_add_notice( __( 'This VAT number is not valid in VIES. Please check or leave the field blank.', 'marta' ), 'error' );
		}
	}

	/**
	 * Persist classic checkout VAT meta.
	 *
	 * @param int $order_id Order ID.
	 * @return void
	 */
	public function update_order_meta( int $order_id ): void {
		$order = wc_get_order( $order_id );

		if ( ! $order ) {
			return;
		}

		$raw_vat = isset( $_POST['billing_vat_number'] ) ? sanitize_text_field( wp_unslash( $_POST['billing_vat_number'] ) ) : '';

		if ( '' === $raw_vat ) {
			return;
		}

		$order->update_meta_data( '_billing_vat_number', $raw_vat );
		$payload = WC()->session ? WC()->session->get( Marta_VAT_Tax::SESSION_KEY ) : null;

		if ( is_array( $payload ) && ! empty( $payload['status'] ) ) {
			$order->update_meta_data( '_marta_vies_status', $payload['status'] );
		}

		$order->save();
	}

	/**
	 * Keep VAT field value on checkout reload.
	 *
	 * @param mixed  $value Existing value.
	 * @param string $input Input key.
	 * @return mixed
	 */
	public function restore_checkout_value( $value, string $input ) {
		if ( 'billing_vat_number' !== $input ) {
			return $value;
		}

		if ( isset( $_POST['billing_vat_number'] ) ) {
			return sanitize_text_field( wp_unslash( $_POST['billing_vat_number'] ) );
		}

		return $value;
	}

	/**
	 * Trigger checkout recalc shortly after VAT field input.
	 *
	 * @return void
	 */
	public function enqueue_checkout_recalc_script(): void {
		if ( ! function_exists( 'is_checkout' ) || ! is_checkout() || is_wc_endpoint_url() ) {
			return;
		}
		?>
		<script>
			(function($){
				var timer = null;
				$(document.body).on('input change', '#billing_vat_number', function() {
					window.clearTimeout(timer);
					timer = window.setTimeout(function() {
						$(document.body).trigger('update_checkout');
					}, 450);
				});
			})(jQuery);
		</script>
		<?php
	}
}
