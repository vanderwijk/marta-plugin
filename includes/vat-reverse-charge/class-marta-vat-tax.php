<?php
/**
 * VAT tax logic.
 *
 * @package Marta
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Marta_VAT_Tax {

	/**
	 * Session key for validation payload.
	 */
	const SESSION_KEY = 'marta_vat_validation';

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function register(): void {
		/*
		 * Hook BEFORE the cart computes line taxes — set_is_vat_exempt() only takes
		 * effect on subsequent calculations, so calling it from
		 * woocommerce_after_calculate_totals (the previous wiring) was too late and
		 * the current AJAX recalculation still rendered full VAT. Hooking
		 * woocommerce_before_calculate_totals at priority 1 makes the exemption
		 * apply to this same recalc. Also keep the after_calculate_totals hook as a
		 * defensive second pass so subsequent reads of the customer state are
		 * consistent.
		 */
		add_action( 'woocommerce_before_calculate_totals', array( $this, 'maybe_apply_reverse_charge' ), 1 );
		add_action( 'woocommerce_after_calculate_totals', array( $this, 'maybe_apply_reverse_charge' ), 1 );
		add_action( 'woocommerce_checkout_create_order', array( $this, 'persist_checkout_meta' ) );
		add_action( 'woocommerce_store_api_checkout_update_order_from_request', array( $this, 'store_api_before_totals' ), 10, 2 );
		add_filter( 'woocommerce_order_get_formatted_billing_address', array( $this, 'append_vat_to_billing_address' ), 10, 3 );
	}

	/**
	 * Save VAT validation payload into customer session.
	 *
	 * @param array<string,mixed> $payload Validation payload.
	 * @return void
	 */
	public static function set_session_validation( array $payload ): void {
		if ( WC()->session ) {
			WC()->session->set( self::SESSION_KEY, $payload );
		}
	}

	/**
	 * Set VIES unreachable flag in customer session.
	 *
	 * @param bool $is_unreachable Unreachable state.
	 * @return void
	 */
	public static function set_vies_unreachable( bool $is_unreachable ): void {
		if ( WC()->session ) {
			WC()->session->set( 'marta_vat_vies_unreachable', $is_unreachable ? '1' : '' );
		}
	}

	/**
	 * Apply reverse charge where applicable.
	 *
	 * @param WC_Cart $cart Cart object.
	 * @return void
	 */
	public function maybe_apply_reverse_charge( $cart ): void {
		if ( ! $cart || ! WC()->customer ) {
			return;
		}

		$billing_country = strtoupper( (string) WC()->customer->get_billing_country() );
		$base_location   = wc_get_base_location();
		$shop_country    = isset( $base_location['country'] ) ? strtoupper( $base_location['country'] ) : '';
		$payload         = WC()->session ? WC()->session->get( self::SESSION_KEY ) : null;
		$is_valid_vat    = is_array( $payload ) && isset( $payload['status'] ) && 'valid' === $payload['status'];
		$is_eu           = is_array( $payload ) && ! empty( $payload['country_code'] );

		$should_exempt = $this->should_apply_reverse_charge( $billing_country, $shop_country, $payload, $is_eu, $is_valid_vat );

		WC()->customer->set_is_vat_exempt( $should_exempt );
	}

	/**
	 * Determine whether reverse charge should be applied.
	 *
	 * @param string                   $billing_country Billing country.
	 * @param string                   $shop_country Shop country.
	 * @param array<string,mixed>|null $payload Validation payload.
	 * @param bool                     $is_eu Is EU billing country.
	 * @param bool                     $is_valid_vat Is VAT valid.
	 * @return bool
	 */
	public function should_apply_reverse_charge( string $billing_country, string $shop_country, ?array $payload, bool $is_eu, bool $is_valid_vat ): bool {
		return $is_valid_vat
			&& $is_eu
			&& ! empty( $payload['country_code'] )
			&& $billing_country
			&& $shop_country
			&& $billing_country !== $shop_country;
	}

	/**
	 * Store API route support for block checkout.
	 *
	 * @param WC_Order         $order Order.
	 * @param WP_REST_Request  $request Request.
	 * @return void
	 */
	public function store_api_before_totals( $order, $request ): void {
		if ( ! $order || ! $request ) {
			return;
		}

		$extensions = $request->get_param( 'extensions' );

		if ( ! is_array( $extensions ) || ! isset( $extensions['marta/vat-number'] ) ) {
			return;
		}

		$vat_value = (string) $extensions['marta/vat-number'];
		$vat_value = strtoupper( preg_replace( '/[^A-Za-z0-9]/', '', $vat_value ) );

		if ( WC()->session && $vat_value ) {
			$payload = WC()->session->get( self::SESSION_KEY );
			if ( is_array( $payload ) ) {
				$payload['raw_input'] = $vat_value;
				WC()->session->set( self::SESSION_KEY, $payload );
			}
		}
	}

	/**
	 * Persist VAT fields to order.
	 *
	 * @param WC_Order $order Order.
	 * @return void
	 */
	public function persist_checkout_meta( $order ): void {
		if ( ! $order ) {
			return;
		}

		$payload = WC()->session ? WC()->session->get( self::SESSION_KEY ) : null;

		if ( is_array( $payload ) && ! empty( $payload['vat_number'] ) ) {
			$order->update_meta_data( '_marta_vat_number', (string) $payload['vat_number'] );
			$order->update_meta_data( '_marta_vat_country', (string) $payload['country_code'] );
			$order->update_meta_data( '_marta_vies_status', (string) $payload['status'] );
			$order->update_meta_data( '_marta_vies_checked_at', isset( $payload['checked_at'] ) ? (string) $payload['checked_at'] : gmdate( 'c' ) );

			if ( ! empty( $payload['name'] ) ) {
				$order->update_meta_data( '_marta_vat_trader_name', (string) $payload['name'] );
			}
		}

		$unreachable = WC()->session ? WC()->session->get( 'marta_vat_vies_unreachable' ) : '';
		if ( $unreachable ) {
			$order->update_meta_data( '_marta_vies_unreachable', '1' );
			if ( ! $order->get_meta( '_marta_vies_status' ) ) {
				$order->update_meta_data( '_marta_vies_status', 'unreachable' );
			}
		}
	}

	/**
	 * Append VAT number to formatted billing address.
	 *
	 * @param string   $address Billing address.
	 * @param array    $address_raw Raw address array.
	 * @param WC_Order $order Order.
	 * @return string
	 */
	public function append_vat_to_billing_address( $address, $address_raw, $order ): string {
		$country = (string) $order->get_meta( '_marta_vat_country' );
		$number  = (string) $order->get_meta( '_marta_vat_number' );

		if ( ! $country || ! $number ) {
			return $address;
		}

		return $address . '<br/>' . esc_html( sprintf( 'VAT: %s%s', $country, $number ) );
	}
}
