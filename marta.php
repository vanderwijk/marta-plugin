<?php
/*
Plugin Name: marta;
Plugin URI: https://martaonline.eu
Description: Kitchen sink for marta;
Author: Johan van der Wijk
Version: 1.2
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
	remove_menu_page( 'tools.php' );
}
add_action( 'admin_menu', 'marta_remove_menus' );