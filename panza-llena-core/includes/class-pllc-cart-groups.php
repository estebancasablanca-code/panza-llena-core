<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Agrupa visualmente el carrito por pedido: inserta un encabezado
 * "Pedido para {alumno}" + resumen del formulario antes del primer
 * producto de cada group_id, y "Pedido para mí" para los que no tienen
 * group_id (compras de Particulares).
 *
 * SOLO para pedidos de Colegios además arma una vista enriquecida por
 * producto (Día + nombre + precio unitario, ocultando las celdas nativas
 * de Precio/Cantidad para no duplicar información) — Particulares
 * conserva el carrito estándar de WooCommerce sin tocar, e ITEO se
 * resuelve en una etapa posterior.
 *
 * Como el flujo de "Agregar al carrito" manda todos los productos de un
 * mismo pedido juntos en una sola llamada, ya quedan contiguos en el
 * carrito — no hace falta reordenar nada, solo detectar el cambio de
 * grupo mientras WooCommerce itera los items en su orden normal.
 */
class PLLC_Cart_Groups {

	private static $last_group_key = null;

	const HIDE_PRICE_FOR    = [ 'colegios', 'iteo_pacientes', 'iteo_personal' ];
	const LOCK_QUANTITY_FOR = [ 'colegios', 'iteo_personal' ];

	public static function init() {
		add_filter( 'woocommerce_cart_item_class', [ __CLASS__, 'add_row_class' ], 10, 3 );
		add_filter( 'woocommerce_get_item_data', [ __CLASS__, 'inject_group_header' ], 5, 2 );
		add_filter( 'woocommerce_get_item_data', [ __CLASS__, 'inject_colegios_row' ], 6, 2 );
		add_filter( 'woocommerce_cart_item_price', [ __CLASS__, 'maybe_hide_price' ], 10, 3 );
		add_filter( 'woocommerce_cart_item_subtotal', [ __CLASS__, 'maybe_hide_price' ], 10, 3 );
		add_filter( 'woocommerce_cart_item_quantity', [ __CLASS__, 'maybe_lock_quantity' ], 10, 3 );
	}

	private static function get_form_type( $cart_item ) {
		$type = isset( $cart_item['pllc_form_type'] ) ? $cart_item['pllc_form_type'] : '';
		return $type ? $type : 'particular';
	}

	/**
	 * Clase en el <tr> de cada producto (ej. "pllc-form-type-colegios"),
	 * para poder aplicar CSS distinto por tipo de pedido sin adivinar
	 * estructura — WooCommerce ya soporta esto de fábrica.
	 */
	public static function add_row_class( $class, $cart_item, $cart_item_key ) {
		return $class . ' pllc-form-type-' . sanitize_html_class( self::get_form_type( $cart_item ) );
	}

	/**
	 * Prioridad 5: encabezado del PEDIDO (una vez por grupo, no por
	 * producto). "Pedido para Juan" + el resumen Colegio/Nivel/Curso/...
	 */
	public static function inject_group_header( $item_data, $cart_item ) {
		$group_key = isset( $cart_item['pllc_group'] ) ? $cart_item['pllc_group'] : 'particular';

		if ( $group_key === self::$last_group_key ) {
			return $item_data; // Ya se mostró el resumen de este pedido.
		}

		self::$last_group_key = $group_key;

		if ( 'particular' === $group_key ) {
			$item_data[] = [
				'key'     => 'pllc-group-header',
				'name'    => '',
				'value'   => '',
				'display' => '<div class="pllc-cart-group-header">' . esc_html__( 'Pedido para mí', 'panza-llena-core' ) . '</div>',
			];
			return $item_data;
		}

		$form    = ( isset( $cart_item['pllc_form'] ) && is_array( $cart_item['pllc_form'] ) ) ? $cart_item['pllc_form'] : [];
		$header  = self::build_group_label( $form );
		$summary = self::build_summary_line( $form );

		$html = '<div class="pllc-cart-group-header">' . esc_html( $header ) . '</div>';

		if ( $summary ) {
			$html .= '<div class="pllc-cart-group-summary">' . $summary . '</div>';
		}

		if ( 'colegios' === self::get_form_type( $cart_item ) ) {
			$html .= '<div class="pllc-cart-columns-label">'
				. '<span>' . esc_html__( 'Producto', 'panza-llena-core' ) . '</span>'
				. '<span>' . esc_html__( 'Total', 'panza-llena-core' ) . '</span>'
				. '</div>';
		}

		$item_data[] = [
			'key'     => 'pllc-group-header',
			'name'    => '',
			'value'   => '',
			'display' => $html,
		];

		return $item_data;
	}

	/**
	 * Prioridad 6: fila enriquecida por PRODUCTO, solo para Colegios, en
	 * TODOS los productos (no solo el primero del grupo). Arma "Día: X" +
	 * el nombre propio del producto (con su variación, ej. "- Clásico") +
	 * el precio unitario — y por CSS se oculta el nombre nativo y las
	 * celdas de Precio/Cantidad para no duplicar.
	 */
	public static function inject_colegios_row( $item_data, $cart_item ) {
		if ( 'colegios' !== self::get_form_type( $cart_item ) ) {
			return $item_data;
		}

		$product = isset( $cart_item['data'] ) ? $cart_item['data'] : null;
		if ( ! $product ) {
			return $item_data;
		}

		$day_label = self::get_day_label( $cart_item['product_id'] );

		$html = '';
		if ( $day_label ) {
			/* translators: %s: día de la semana */
			$html .= '<div class="pllc-cart-day-label">' . esc_html( sprintf( __( 'Día: %s', 'panza-llena-core' ), $day_label ) ) . '</div>';
		}

		$html .= '<div class="pllc-cart-product-name"><a href="' . esc_url( $product->get_permalink( $cart_item ) ) . '">' . wp_kses_post( $product->get_name() ) . '</a></div>';
		$html .= '<div class="pllc-cart-unit-price">' . wp_kses_post( $product->get_price_html() ) . '</div>';

		$item_data[] = [
			'key'     => 'pllc-colegios-row',
			'name'    => '',
			'value'   => '',
			'display' => $html,
		];

		return $item_data;
	}

	/**
	 * Busca entre las categorías del producto (padre) la que empieza con
	 * "colegios-" (ej. "colegios-lunes") y devuelve su nombre ("Lunes").
	 */
	private static function get_day_label( $product_id ) {
		$terms = wc_get_product_terms( $product_id, 'product_cat', [ 'fields' => 'all' ] );

		if ( is_wp_error( $terms ) || empty( $terms ) ) {
			return '';
		}

		foreach ( $terms as $term ) {
			if ( 0 === strpos( $term->slug, 'colegios-' ) ) {
				return $term->name;
			}
		}

		return '';
	}

	private static function build_group_label( $form ) {
		if ( ! empty( $form['nombre_alumno'] ) ) {
			/* translators: %s: nombre del alumno */
			return sprintf( __( 'Pedido para %s', 'panza-llena-core' ), $form['nombre_alumno'] );
		}

		return __( 'Pedido', 'panza-llena-core' );
	}

	/**
	 * Línea única "Colegio: X | Nivel: Y | Curso: Z | ..." con todos los
	 * datos del formulario, salvo el nombre del alumno (ya está en el
	 * título) y campos vacíos.
	 */
	private static function build_summary_line( $form ) {
		$labels = [
			'colegio'       => __( 'Colegio', 'panza-llena-core' ),
			'nivel'         => __( 'Nivel', 'panza-llena-core' ),
			'curso'         => __( 'Curso', 'panza-llena-core' ),
			'cubiertos'     => __( 'Cubiertos descartables', 'panza-llena-core' ),
			'observaciones' => __( 'Observaciones', 'panza-llena-core' ),
		];

		$parts = [];

		foreach ( $labels as $key => $label ) {
			if ( ! empty( $form[ $key ] ) ) {
				$parts[] = '<strong>' . esc_html( $label ) . ':</strong> ' . esc_html( $form[ $key ] );
			}
		}

		return implode( ' | ', $parts );
	}

	public static function maybe_hide_price( $price_html, $cart_item, $cart_item_key ) {
		if ( in_array( self::get_form_type( $cart_item ), self::HIDE_PRICE_FOR, true ) ) {
			return '';
		}
		return $price_html;
	}

	public static function maybe_lock_quantity( $quantity_html, $cart_item_key, $cart_item ) {
		if ( in_array( self::get_form_type( $cart_item ), self::LOCK_QUANTITY_FOR, true ) ) {
			return '<span class="pllc-fixed-qty">' . absint( $cart_item['quantity'] ) . '</span>';
		}
		return $quantity_html;
	}
}
