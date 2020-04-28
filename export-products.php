<?php
// Export products list to CSV

class marta_export_products {

	public function __construct() {
		add_action( 'admin_menu', array( $this, 'export_products_page' ) );
		add_action( 'init', array( $this, 'generate_products_csv' ) );
	}

	public function export_products_page() {
		add_submenu_page('edit.php?post_type=product', __( 'Export products to CSV', 'marta' ), __( 'Export products', 'marta' ), 'edit_others_posts', 'products-export', array( $this, 'products_export_page' ) );
	}

	public function generate_products_csv() {
		if ( isset( $_POST['_wpnonce-export-products'] ) ) {
			check_admin_referer( 'export-products', '_wpnonce-export-products' );

			$args = array (
				'post_type' => 'product',
				'orderby' => 'title',
				'posts_per_page' => -1
			);
		
			// Create the WP_User_Query object
			$products = get_posts( $args );

			if ( ! $products ) {
				$referer = add_query_arg( 'error', 'empty', wp_get_referer() );
				wp_redirect( $referer );
				exit;
			}

			$filename = 'products.' . date( 'Y-m-d-H-i-s' ) . '.csv';

			header( 'Content-Description: File Transfer' );
			header( 'Content-Disposition: attachment; filename=' . $filename );
			header( 'Content-Type: text/csv; charset=' . get_option( 'blog_charset' ), true );

			global $wpdb;

			$fields = array(
				'Item ID',
				'Title',
				'Product Description',
				'category',
				'_product_materials',
				'_product_designer',
				'_product_dimensions',
				'image'
			);

			$headers = array();
			foreach ( $fields as $key => $field ) {
				$headers[] = '"' . $field . '"';
			}
			echo implode( ',', $headers ) . "\n";

			foreach ( $products as $product ) {
				$data = array();
				$product_meta = get_post_meta( $product -> ID );

				foreach ( $fields as $field ) {

					if ( $field == 'Item ID') {
						echo '"';
						echo ( $product -> ID );
						echo '",';
					} else if ( $field == 'Title') {
						echo '"';
						echo ( $product -> post_title );
						echo '",';
					} else if ( $field == 'Product Description') {
						echo '"';
						echo ( $product -> post_content );
						echo '",';
					} else if ( $field == 'category') {
						echo '"';
						$terms = wp_get_post_terms( $product -> ID, 'category', array( 'fields' => 'names' ) );
						foreach ( $terms as $term ) {
							echo $term;
							if ( is_array($terms) && count($terms) > 1 ) {
								echo ', ';
							}
						}
						echo '",';
					} else if ( $field == 'image') {
						echo '"';
						$featured_image = wp_get_attachment_url( get_post_thumbnail_id( $product->ID ) );
						echo $featured_image;
						echo '",';
					} else if ( $field == 'date') {
						echo '"';
						echo ( $product -> post_date );
						echo '",';
					} else {	
						if ( ( $product_meta[$field][0] === '0' ) or ( $product_meta[$field][0] == '' ) ) {
							echo '"",';
						} else {
							echo '"' . $product_meta[$field][0] . '",';
						}
					}
				}
				echo "\n";
			}
			exit;
		}
	}

	public function products_export_page() {
		if ( ! current_user_can( 'edit_others_posts' ) )
			wp_die( __( 'You do not have sufficient permissions to access this page.', 'marta' ) ); ?>

	<div class="wrap">
		<h2><?php _e( 'Export products to a CSV file', 'marta' ); ?></h2>
		<?php
		if ( isset( $_GET['error'] ) ) {
			echo '<div class="updated"><p><strong>' . __( 'No products found.', 'marta' ) . '</strong></p></div>';
		}
		?>
		<form method="post" enctype="multipart/form-data">
			<?php wp_nonce_field( 'export-products', '_wpnonce-export-products' ); ?>
			<p class="submit">
				<input type="hidden" name="_wp_http_referer" value="<?php echo $_SERVER['REQUEST_URI'] ?>" />
				<input type="submit" class="button-primary" value="<?php _e( 'Export', 'marta' ); ?>" />
			</p>
		</form>
	</div>
<?php
	}
}

new marta_export_products;