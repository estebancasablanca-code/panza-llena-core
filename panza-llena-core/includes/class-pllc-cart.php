<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Recibe por AJAX el pedido armado en el frontend (items marcados como
 * "agregados" + datos del formulario) y lo agrega al carrito real de
 * WooCommerce, con un group_id compartido para poder agruparlo después en
 * el carrito/checkout (eso es el próximo punto de la arquitectura).
 */
class PLLC_Cart {

	public static function init() {
		add_action( 'wp_ajax_pllc_add_order', [ __CLASS__, 'handle_add_order' ] );
		add_action( 'wp_ajax_nopriv_pllc_add_order', [ __CLASS__, 'handle_add_order' ] );

		// Muestra los datos del formulario en el carrito/checkout, para
		// poder confirmar visualmente que llegaron bien mientras probamos.
		add_filter( 'woocommerce_get_item_data', [ __CLASS__, 'display_item_data' ], 10, 2 );
	}

	public static function handle_add_order() {
		check_ajax_referer( 'pllc_add_order', 'nonce' );

		if ( ! function_exists( 'WC' ) || ! WC()->cart ) {
			wp_send_json_error( [ 'message' => 'WooCommerce no está disponible.' ] );
		}

		$form_type = isset( $_POST['form_type'] ) ? sanitize_key( wp_unslash( $_POST['form_type'] ) ) : '';
		$form_raw  = isset( $_POST['form'] ) ? wp_unslash( $_POST['form'] ) : '{}';
		$items_raw = isset( $_POST['items'] ) ? wp_unslash( $_POST['items'] ) : '[]';

		$form  = json_decode( $form_raw, true );
		$items = json_decode( $items_raw, true );

		if ( ! is_array( $items ) || empty( $items ) ) {
			wp_send_json_error( [ 'message' => 'No hay productos seleccionados.' ] );
		}

		$group_id = wp_generate_uuid4();
		$added    = 0;

		foreach ( $items as $item ) {
			$product_id = isset( $item['product_id'] ) ? absint( $item['product_id'] ) : 0;

			if ( ! $product_id ) {
				continue;
			}

			$cart_item_data = [
				'pllc_group'     => $group_id,
				'pllc_form_type' => $form_type,
				'pllc_form'      => is_array( $form ) ? array_map( 'sanitize_text_field', $form ) : [],
			];

			$variation_id = 0;
			$quantity     = 1;

			if ( ! empty( $item['variation_id'] ) ) {
				$variation_id = absint( $item['variation_id'] );
			}

			if ( ! empty( $item['meals'] ) && is_array( $item['meals'] ) ) {
				$cart_item_data['pllc_meals'] = array_map( 'sanitize_text_field', $item['meals'] );
			}

			if ( ! empty( $item['qty'] ) ) {
				$quantity = max( 1, absint( $item['qty'] ) );
			}

			$added_key = WC()->cart->add_to_cart( $product_id, $quantity, $variation_id, [], $cart_item_data );

			if ( $added_key ) {
				$added++;
			}
		}

		if ( ! $added ) {
			wp_send_json_error( [ 'message' => 'No se pudo agregar ningún producto al carrito.' ] );
		}

		wp_send_json_success( [
			'added'    => $added,
			'group_id' => $group_id,
		] );
	}

	/**
	 * Solo muestra "Comida" (Almuerzo/Cena de ITEO Personal), que es un
	 * dato específico de CADA producto — a diferencia de Alumno, Colegio,
	 * Nivel, etc., que son del pedido completo y se muestran una sola vez
	 * en el encabezado de grupo (ver PLLC_Cart_Groups).
	 */
	public static function display_item_data( $item_data, $cart_item ) {
		if ( ! empty( $cart_item['pllc_meals'] ) ) {
			$item_data[] = [
				'name'  => 'Comida',
				'value' => implode( ', ', $cart_item['pllc_meals'] ),
			];
		}

		return $item_data;
	}
}
