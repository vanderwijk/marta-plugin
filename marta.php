<?php
/*
Plugin Name: marta;
Plugin URI: https://martaonline.eu
Description: Kitchen sink for marta;
Author: Johan van der Wijk
Version: 2.0.1
Author URI: https://vanderwijk.nl
*/

// Load plugin translations textdomain
function translations_init() {
	load_plugin_textdomain( 'marta', false, dirname( plugin_basename( __FILE__ ) ) . '/languages/' );
}
add_action( 'plugins_loaded', 'translations_init' );

require 'cpt-products.php';
require 'cpt-projects.php';
require 'export-products.php';

function marta_remove_menus() {
	remove_menu_page( 'edit.php' );
	remove_menu_page( 'edit-comments.php' );
	//remove_menu_page( 'tools.php' );
}
add_action( 'admin_menu', 'marta_remove_menus' );


function marta_after_cart_button() {
	echo '<p style="margin-top: 20px;"><a href="/buy/">Where to buy ?</a></p>';
	echo '<p><a href="/professionals/">Are you a professional ?</a></p>';
}
add_action( 'woocommerce_after_add_to_cart_button', 'marta_after_cart_button' );


add_filter('gettext', function ($translated_text, $text, $domain) {

	switch ($translated_text) {
		case 'Cart':
			$translated_text = __('Basket', 'woocommerce');
			break;
		case 'Update cart':
			$translated_text = __('Update basket', 'woocommerce');
			break;
		case 'Add to cart':
			$translated_text = __('Add to basket', 'woocommerce');
			break;
		case 'View cart':
			$translated_text = __('View basket', 'woocommerce');
			break;
		case 'Proceed to checkout':
			$translated_text = __('Submit quote', 'woocommerce');
			break;
		case 'Billing details':
			$translated_text = __('Your details', 'woocommerce');
			break;
		case 'Your order':
			$translated_text = __('Items', 'woocommerce');
			break;
		case 'Return to shop':
			$translated_text = __('Return to the collection', 'woocommerce');
			break;
		case 'Additional information':
			$translated_text = __('Product information', 'woocommerce');
			break;
		case 'Basket totals':
			$translated_text = __('Products', 'woocommerce');
			break;
		case 'Thank you. Your order has been received.':
			$translated_text = __('Thank you. Your quote request has been received.', 'woocommerce');
			break;
		case 'Order details':
			$translated_text = __('Quote details', 'woocommerce');
			break;
		case 'We have received your request for a quote. You will be notified via email soon.':
			$translated_text = __('You will be notified via email soon.', 'woocommerce');
			break;
	}

	return $translated_text;

}, 20, 3);
