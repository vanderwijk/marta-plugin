<?php
// Register Custom Post Type
function register_cpt_project() {

	$labels = array(
		'name'                  => _x( 'Projects', 'Post Type General Name', 'marta' ),
		'singular_name'         => _x( 'Project', 'Post Type Singular Name', 'marta' ),
		'menu_name'             => __( 'Projects', 'marta' ),
		'name_admin_bar'        => __( 'Project', 'marta' ),
		'archives'              => __( 'Project Archives', 'marta' ),
		'attributes'            => __( 'Project Attributes', 'marta' ),
		'parent_item_colon'     => __( 'Parent Project:', 'marta' ),
		'all_items'             => __( 'All Projects', 'marta' ),
		'add_new_item'          => __( 'Add New Project', 'marta' ),
		'add_new'               => __( 'Add New', 'marta' ),
		'new_item'              => __( 'New Project', 'marta' ),
		'edit_item'             => __( 'Edit Project', 'marta' ),
		'update_item'           => __( 'Update Project', 'marta' ),
		'view_item'             => __( 'View Project', 'marta' ),
		'view_items'            => __( 'View Projects', 'marta' ),
		'search_items'          => __( 'Search Project', 'marta' ),
		'not_found'             => __( 'Not found', 'marta' ),
		'not_found_in_trash'    => __( 'Not found in Trash', 'marta' ),
		'featured_image'        => __( 'Featured Image', 'marta' ),
		'set_featured_image'    => __( 'Set featured image', 'marta' ),
		'remove_featured_image' => __( 'Remove featured image', 'marta' ),
		'use_featured_image'    => __( 'Use as featured image', 'marta' ),
		'insert_into_item'      => __( 'Insert into project', 'marta' ),
		'uploaded_to_this_item' => __( 'Uploaded to this project', 'marta' ),
		'items_list'            => __( 'Project list', 'marta' ),
		'items_list_navigation' => __( 'Project list navigation', 'marta' ),
		'filter_items_list'     => __( 'Filter project list', 'marta' ),
	);
	$args = array(
		'label'                 => __( 'Project', 'marta' ),
		'description'           => __( 'Projects made with marta; products', 'marta' ),
		'labels'                => $labels,
		'supports'              => array( 'title', 'editor', 'revisions', 'thumbnail'),
		'taxonomies'            => array( ),
		'hierarchical'          => true,
		'public'                => true,
		'show_ui'               => true,
		'show_in_menu'          => true,
		'menu_position'         => 7,
		'menu_icon'             => 'dashicons-format-gallery',
		'show_in_admin_bar'     => true,
		'show_in_nav_menus'     => true,
		'can_export'            => true,
		'has_archive'           => true,		
		'exclude_from_search'   => false,
		'publicly_queryable'    => true,
		'rewrite' => array( 'slug' => __( 'projects', 'marta' ) ),
		'capability_type'       => 'page',
		'show_in_rest' => true,
	);
	register_post_type( 'project', $args );

}
add_action( 'init', 'register_cpt_project', 0 );