<?php
/**
 * VAT order admin and email integration.
 *
 * @package Marta
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Marta_VAT_Order {

	/**
	 * VIES client.
	 *
	 * @var Marta_VIES_Client
	 */
	private $vies;

	/**
	 * Constructor.
	 *
	 * @param Marta_VIES_Client $vies VIES client.
	 */
	public function __construct( Marta_VIES_Client $vies ) {
		$this->vies = $vies;
	}

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'add_meta_boxes', array( $this, 'add_order_metabox' ) );
		add_action( 'add_meta_boxes_woocommerce_page_wc-orders', array( $this, 'add_order_metabox_hpos' ) );
		add_action( 'wp_ajax_marta_vat_recheck', array( $this, 'ajax_recheck' ) );
		add_action( 'admin_notices', array( $this, 'maybe_show_unreachable_notice' ) );
		add_filter( 'manage_edit-shop_order_columns', array( $this, 'add_order_column' ) );
		add_action( 'manage_shop_order_posts_custom_column', array( $this, 'render_order_column' ) );
		add_filter( 'manage_woocommerce_page_wc-orders_columns', array( $this, 'add_order_column' ) );
		add_action( 'manage_woocommerce_page_wc-orders_custom_column', array( $this, 'render_hpos_order_column' ), 10, 2 );
		add_action( 'woocommerce_email_customer_details', array( $this, 'render_email_vat_details' ), 20, 4 );
	}

	/**
	 * Add metabox for legacy order screen.
	 *
	 * @return void
	 */
	public function add_order_metabox(): void {
		add_meta_box(
			'marta-vat-reverse-charge',
			__( 'VAT / Reverse charge', 'marta' ),
			array( $this, 'render_order_metabox' ),
			'shop_order',
			'side'
		);
	}

	/**
	 * Add metabox for HPOS order screen.
	 *
	 * @param string $screen_id Screen ID.
	 * @return void
	 */
	public function add_order_metabox_hpos( string $screen_id ): void {
		add_meta_box(
			'marta-vat-reverse-charge',
			__( 'VAT / Reverse charge', 'marta' ),
			array( $this, 'render_order_metabox' ),
			$screen_id,
			'side'
		);
	}

	/**
	 * Render metabox content.
	 *
	 * @param WP_Post|WC_Order $object Object.
	 * @return void
	 */
	public function render_order_metabox( $object ): void {
		$order = $object instanceof WC_Order ? $object : wc_get_order( $object->ID );

		if ( ! $order ) {
			return;
		}

		$vat_number = (string) $order->get_meta( '_marta_vat_number' );
		$country    = (string) $order->get_meta( '_marta_vat_country' );
		$status     = (string) $order->get_meta( '_marta_vies_status' );
		$checked_at = (string) $order->get_meta( '_marta_vies_checked_at' );

		wp_nonce_field( 'marta_vat_recheck', 'marta_vat_recheck_nonce' );

		echo '<p><strong>' . esc_html__( 'VAT number:', 'marta' ) . '</strong><br/>' . esc_html( $country . $vat_number ) . '</p>';
		echo '<p><strong>' . esc_html__( 'VIES status:', 'marta' ) . '</strong><br/>' . wp_kses_post( $this->status_badge( $status ) ) . '</p>';
		echo '<p><strong>' . esc_html__( 'Checked at:', 'marta' ) . '</strong><br/>' . esc_html( $checked_at ? $checked_at : '-' ) . '</p>';

		if ( $vat_number && $country ) {
			printf(
				'<p><button type="button" class="button" data-order-id="%1$d" id="marta-vat-recheck-button">%2$s</button></p>',
				(int) $order->get_id(),
				esc_html__( 'Re-check now', 'marta' )
			);
			echo '<script>document.addEventListener("click",function(e){if(e.target&&e.target.id==="marta-vat-recheck-button"){e.preventDefault();var b=e.target;b.disabled=true;var f=new FormData();f.append("action","marta_vat_recheck");f.append("order_id",b.getAttribute("data-order-id"));f.append("nonce","' . esc_js( wp_create_nonce( 'marta_vat_recheck' ) ) . '");fetch(ajaxurl,{method:"POST",body:f}).then(function(r){return r.json();}).then(function(){location.reload();});}});</script>';
		}
	}

	/**
	 * Recheck VAT via AJAX.
	 *
	 * @return void
	 */
	public function ajax_recheck(): void {
		check_ajax_referer( 'marta_vat_recheck', 'nonce' );

		$order_id = isset( $_POST['order_id'] ) ? absint( wp_unslash( $_POST['order_id'] ) ) : 0;
		$order    = wc_get_order( $order_id );

		if ( ! $order ) {
			wp_send_json_error();
		}

		$country = (string) $order->get_meta( '_marta_vat_country' );
		$number  = (string) $order->get_meta( '_marta_vat_number' );

		if ( ! $country || ! $number ) {
			wp_send_json_error();
		}

		$this->vies->delete_cache( $country, $number );
		$result = $this->vies->check( $country, $number );

		$order->update_meta_data( '_marta_vies_status', $result['status'] );
		$order->update_meta_data( '_marta_vies_checked_at', $result['checked_at'] );
		if ( ! empty( $result['name'] ) ) {
			$order->update_meta_data( '_marta_vat_trader_name', $result['name'] );
		}
		$order->save();

		wp_send_json_success( $result );
	}

	/**
	 * Show admin notice when VIES was unreachable at checkout.
	 *
	 * @return void
	 */
	public function maybe_show_unreachable_notice(): void {
		if ( ! is_admin() || ! isset( $_GET['post'] ) ) {
			return;
		}

		$order = wc_get_order( absint( $_GET['post'] ) );

		if ( ! $order || ! $order->get_meta( '_marta_vies_unreachable' ) ) {
			return;
		}

		echo '<div class="notice notice-warning"><p>' .
			esc_html__( 'VIES could not be reached during checkout. VAT was not reverse-charged automatically; review this order manually.', 'marta' ) .
		'</p></div>';
	}

	/**
	 * Add order list VAT column.
	 *
	 * @param array<string,string> $columns Existing columns.
	 * @return array<string,string>
	 */
	public function add_order_column( array $columns ): array {
		$columns['marta_vat_status'] = __( 'VAT', 'marta' );
		return $columns;
	}

	/**
	 * Render VAT column for legacy list table.
	 *
	 * @param string $column Column key.
	 * @return void
	 */
	public function render_order_column( string $column ): void {
		if ( 'marta_vat_status' !== $column ) {
			return;
		}

		global $post;
		$order = wc_get_order( $post ? $post->ID : 0 );
		echo wp_kses_post( $this->status_badge( $order ? (string) $order->get_meta( '_marta_vies_status' ) : '' ) );
	}

	/**
	 * Render VAT column for HPOS list table.
	 *
	 * @param string $column Column key.
	 * @param WC_Order $order_or_id Order or order ID.
	 * @return void
	 */
	public function render_hpos_order_column( string $column, $order_or_id ): void {
		if ( 'marta_vat_status' !== $column ) {
			return;
		}

		$order = $order_or_id instanceof WC_Order ? $order_or_id : wc_get_order( $order_or_id );
		echo wp_kses_post( $this->status_badge( $order ? (string) $order->get_meta( '_marta_vies_status' ) : '' ) );
	}

	/**
	 * Show VAT details and reverse-charge legal phrase in emails.
	 *
	 * @param WC_Order $order Order.
	 * @param bool     $sent_to_admin Sent to admin.
	 * @param bool     $plain_text Plain text mode.
	 * @param WC_Email $email Email.
	 * @return void
	 */
	public function render_email_vat_details( $order, $sent_to_admin, $plain_text, $email ): void {
		if ( ! $order instanceof WC_Order ) {
			return;
		}

		$country = (string) $order->get_meta( '_marta_vat_country' );
		$number  = (string) $order->get_meta( '_marta_vat_number' );
		$status  = (string) $order->get_meta( '_marta_vies_status' );

		if ( ! $country || ! $number ) {
			return;
		}

		$vat_line = sprintf( 'VAT: %s%s', $country, $number );

		if ( $plain_text ) {
			echo "\n" . $vat_line . "\n";
		} else {
			echo '<p>' . esc_html( $vat_line ) . '</p>';
		}

		if ( 'valid' === $status ) {
			$legal = __( 'Reverse charge - VAT to be accounted for by the recipient under Article 196 of Council Directive 2006/112/EC.', 'marta' );
			if ( $plain_text ) {
				echo $legal . "\n";
			} else {
				echo '<p>' . esc_html( $legal ) . '</p>';
			}
		}
	}

	/**
	 * Build status badge HTML.
	 *
	 * @param string $status Status.
	 * @return string
	 */
	private function status_badge( string $status ): string {
		$status = $status ? $status : 'not_provided';

		$map = array(
			'valid'        => array( '#1e8e3e', __( 'Valid', 'marta' ) ),
			'unreachable'  => array( '#c77700', __( 'Unreachable', 'marta' ) ),
			'invalid'      => array( '#b91c1c', __( 'Invalid', 'marta' ) ),
			'not_provided' => array( '#6b7280', __( 'Not provided', 'marta' ) ),
		);

		$entry = isset( $map[ $status ] ) ? $map[ $status ] : $map['not_provided'];

		return sprintf(
			'<span style="display:inline-block;padding:2px 8px;border-radius:999px;color:#fff;background:%1$s;font-size:11px;">%2$s</span>',
			esc_attr( $entry[0] ),
			esc_html( $entry[1] )
		);
	}
}
