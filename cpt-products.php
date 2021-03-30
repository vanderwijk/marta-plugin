<?php
function register_cpt_product() {
	$labels = array( 
		'name' => __( 'Products', 'marta' ),
		'singular_name' => __( 'Product', 'marta' ),
		'add_new' => __( 'Add New', 'marta' ),
		'add_new_item' => __( 'Add New Product', 'marta' ),
		'edit_item' => __( 'Edit Product', 'marta' ),
		'new_item' => __( 'New Product', 'marta' ),
		'view_item' => __( 'View Product', 'marta' ),
		'search_items' => __( 'Search Products', 'marta' ),
		'not_found' => __( 'No products found', 'marta' ),
		'not_found_in_trash' => __( 'No products found in Trash', 'marta' ),
		'parent_item_colon' => __( 'Parent Product:', 'marta' ),
		'menu_name' => __( 'Products', 'marta' ),
	);
	$args = array( 
		'labels' => $labels,
		'hierarchical' => true,
		'supports' => array( 'title', 'editor', 'thumbnail', 'category' ),
		'public' => true,
		'show_ui' => true,
		'show_in_menu' => true,
		'menu_position' => 5,
		'show_in_nav_menus' => true,
		'publicly_queryable' => true,
		'exclude_from_search' => false,
		'taxonomies' => array('category'),
		'has_archive' => true,
		'query_var' => true,
		'can_export' => true,
		'rewrite' => array( 'slug' => __( 'collection', 'marta' ) ),
		'capability_type' => 'page'
	);
	register_post_type( 'product', $args );
}
//add_action( 'init', 'register_cpt_product' );