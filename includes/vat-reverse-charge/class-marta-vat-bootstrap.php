<?php
/**
 * VAT reverse-charge bootstrap.
 *
 * @package Marta
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/class-marta-vies-client.php';
require_once __DIR__ . '/class-marta-vat-validator.php';
require_once __DIR__ . '/class-marta-vat-checkout-block.php';
require_once __DIR__ . '/class-marta-vat-checkout-classic.php';
require_once __DIR__ . '/class-marta-vat-tax.php';
require_once __DIR__ . '/class-marta-vat-order.php';

final class Marta_VAT_Bootstrap {

	/**
	 * Initialize VAT module.
	 *
	 * @return void
	 */
	public static function init(): void {
		$vies      = new Marta_VIES_Client();
		$validator = new Marta_VAT_Validator( $vies );

		( new Marta_VAT_Checkout_Block( $validator ) )->register();
		( new Marta_VAT_Checkout_Classic( $validator ) )->register();
		( new Marta_VAT_Tax() )->register();
		( new Marta_VAT_Order( $vies ) )->register();
	}
}

add_action( 'plugins_loaded', array( 'Marta_VAT_Bootstrap', 'init' ), 20 );
