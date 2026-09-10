<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Agrupa visualmente el carrito por pedido: inserta un encabezado
 * "Pedido para {alumno}" (o "Pedido ITEO personal/pacientes" / "Pedido
 * para mí" cuando no hay nombre de persona) antes del primer producto de
 * cada pedido.
 *
 * IMPORTANTE sobre el agrupamiento: NO se agrupa por group_id (el UUID
 * que se genera en cada click de "Agregar al carrito"), sino por una
 * clave "de persona" — así, si el mismo alumno (o el mismo tipo ITEO,
 * que no tiene nombre) hace dos pedidos en momentos separados, el
 * carrito los sigue mostrando bajo un único título en vez de duplicarlo.
 *
 * Precio/Cantidad/Subtotal quedan visibles salvo excepciones puntuales
 * por tipo de pedido (ver constantes HIDE_PRICE_FOR / LOCK_QUANTITY_FOR).
 */
class PLLC_Cart_Groups {

	private static $last_group_key = null;
	private static $last_checkout_group_key = null;
	private static $cart_shipping_note_shown = false;

	const HIDE_PRICE_FOR    = [ 'iteo_pacientes', 'iteo_personal' ];
	const LOCK_QUANTITY_FOR = [ 'colegios', 'iteo_personal' ];

	// Prefijo de slug de categoría por día, según el tipo de pedido.
	const DAY_CATEGORY_PREFIX = [
		'colegios'       => 'colegios-',
		'iteo_personal'  => 'iteo-personal-',
		'iteo_pacientes' => 'iteo-pacientes-',
		'particular'     => 'particulares-',
	];

	public static function init() {
		add_action( 'woocommerce_cart_loaded_from_session', [ __CLASS__, 'sort_cart_items' ], 20 );
		add_action( 'woocommerce_before_calculate_totals', [ __CLASS__, 'sort_cart_items' ], 5 );
		add_action( 'woocommerce_before_calculate_totals', [ __CLASS__, 'exclude_iteo_prices_from_totals' ], 20 );
		add_filter( 'woocommerce_cart_item_class', [ __CLASS__, 'add_row_class' ], 10, 3 );
		add_filter( 'woocommerce_get_item_data', [ __CLASS__, 'inject_group_header' ], 5, 2 );
		add_filter( 'woocommerce_get_item_data', [ __CLASS__, 'inject_day_label' ], 6, 2 );
		add_filter( 'woocommerce_cart_item_name', [ __CLASS__, 'format_checkout_item_name' ], 20, 3 );
		add_filter( 'woocommerce_cart_item_name', [ __CLASS__, 'format_cart_item_name' ], 21, 3 );
		add_filter( 'woocommerce_cart_item_price', [ __CLASS__, 'maybe_hide_price' ], 10, 3 );
		add_filter( 'woocommerce_cart_item_subtotal', [ __CLASS__, 'maybe_hide_price' ], 10, 3 );
		add_filter( 'woocommerce_cart_item_quantity', [ __CLASS__, 'maybe_lock_quantity' ], 10, 3 );
		add_filter( 'woocommerce_cart_subtotal', [ __CLASS__, 'mask_iteo_cart_subtotal' ], 99, 3 );

		// Sin envío: pedidos 100% Colegios o 100% ITEO (ninguno de los
		// dos se factura/envía por acá).
		add_filter( 'woocommerce_cart_needs_shipping', [ __CLASS__, 'maybe_disable_shipping' ] );
		add_filter( 'woocommerce_cart_shipping_packages', [ __CLASS__, 'limit_shipping_packages_to_particulars' ], 20 );
		// Si hay Envío, el aviso va antes. La segunda acción es el respaldo
		// cuando WooCommerce todavía no muestra esa fila en el carrito.
		add_action( 'woocommerce_cart_totals_before_shipping', [ __CLASS__, 'maybe_show_cart_shipping_note' ] );
		add_action( 'woocommerce_cart_totals_before_order_total', [ __CLASS__, 'maybe_show_cart_shipping_note' ] );
		// Este punto siempre existe en el resumen, incluso cuando WooCommerce
		// todavía no puede mostrar o calcular la fila de envío.
		add_action( 'woocommerce_review_order_before_order_total', [ __CLASS__, 'maybe_show_checkout_shipping_note' ] );
		add_filter( 'body_class', [ __CLASS__, 'add_cart_body_class' ] );
		add_filter( 'woocommerce_coupons_enabled', [ __CLASS__, 'disable_checkout_coupons' ] );
	}

	/**
	 * Los pedidos ITEO no se cobran por WooCommerce. Poner sus líneas en cero
	 * hace que subtotal, impuestos y total contemplen únicamente Particulares
	 * cuando el carrito combina ambos tipos de pedido.
	 */
	public static function exclude_iteo_prices_from_totals( $cart ) {
		if ( ! $cart || empty( $cart->cart_contents ) || ! is_array( $cart->cart_contents ) ) {
			return;
		}

		foreach ( $cart->cart_contents as $cart_item ) {
			if ( ! in_array( self::get_form_type( $cart_item ), self::HIDE_PRICE_FOR, true ) ) {
				continue;
			}

			if ( ! empty( $cart_item['data'] ) && is_a( $cart_item['data'], 'WC_Product' ) ) {
				$cart_item['data']->set_price( 0 );
			}
		}
	}

	/**
	 * Mantiene cada pedido/persona agrupado y ordena sus productos de lunes
	 * a sábado. El orden de las personas conserva su primera aparición en
	 * el carrito, pero una edición nunca vuelve a colocar el día modificado
	 * al principio del grupo.
	 */
	public static function sort_cart_items( $cart ) {
		if ( ! $cart || empty( $cart->cart_contents ) || ! is_array( $cart->cart_contents ) ) {
			return;
		}

		$group_order = [];
		$decorated   = [];
		$position    = 0;

		foreach ( $cart->cart_contents as $cart_key => $cart_item ) {
			$group_key = self::get_display_group_key( $cart_item );

			if ( ! isset( $group_order[ $group_key ] ) ) {
				$group_order[ $group_key ] = count( $group_order );
			}

			$decorated[] = [
				'key'         => $cart_key,
				'item'        => $cart_item,
				'is_particular' => 'particular' === self::get_form_type( $cart_item ) ? 1 : 0,
				'group_order' => $group_order[ $group_key ],
				'day_order'   => self::get_day_sort_index( $cart_item ),
				'meal_order'  => self::get_meal_sort_index( $cart_item ),
				'position'    => $position++,
			];
		}

		usort( $decorated, function ( $a, $b ) {
			if ( $a['is_particular'] !== $b['is_particular'] ) {
				return $a['is_particular'] <=> $b['is_particular'];
			}
			if ( $a['group_order'] !== $b['group_order'] ) {
				return $a['group_order'] <=> $b['group_order'];
			}
			if ( $a['day_order'] !== $b['day_order'] ) {
				return $a['day_order'] <=> $b['day_order'];
			}
			if ( $a['meal_order'] !== $b['meal_order'] ) {
				return $a['meal_order'] <=> $b['meal_order'];
			}
			return $a['position'] <=> $b['position'];
		} );

		$sorted = [];
		foreach ( $decorated as $entry ) {
			$sorted[ $entry['key'] ] = $entry['item'];
		}

		$cart->cart_contents = $sorted;
	}

	private static function get_day_sort_index( $cart_item ) {
		$form_type = self::get_form_type( $cart_item );
		if ( ! isset( self::DAY_CATEGORY_PREFIX[ $form_type ] ) || empty( $cart_item['product_id'] ) ) {
			return 99;
		}

		$prefix = self::DAY_CATEGORY_PREFIX[ $form_type ];
		$terms  = wc_get_product_terms( $cart_item['product_id'], 'product_cat', [ 'fields' => 'slugs' ] );
		$days   = [
			'lunes'    => 1,
			'martes'   => 2,
			'miercoles' => 3,
			'jueves'   => 4,
			'viernes'  => 5,
			'sabado'   => 6,
		];
		if ( ! empty( $cart_item['pllc_day'] ) ) {
			$stored_day = sanitize_key( $cart_item['pllc_day'] );
			return isset( $days[ $stored_day ] ) ? $days[ $stored_day ] : 99;
		}

		if ( is_wp_error( $terms ) ) {
			return 99;
		}

		foreach ( $terms as $slug ) {
			if ( 0 !== strpos( $slug, $prefix ) ) {
				continue;
			}
			$day = sanitize_title( substr( $slug, strlen( $prefix ) ) );
			return isset( $days[ $day ] ) ? $days[ $day ] : 99;
		}

		return 99;
	}

	private static function get_meal_sort_index( $cart_item ) {
		if ( 'iteo_personal' !== self::get_form_type( $cart_item ) || empty( $cart_item['pllc_meals'][0] ) ) {
			return 99;
		}

		$meal_order = [
			'almuerzo' => 1,
			'cena'     => 2,
		];
		$meal = sanitize_key( $cart_item['pllc_meals'][0] );
		return isset( $meal_order[ $meal ] ) ? $meal_order[ $meal ] : 99;
	}

	private static function get_form_type( $cart_item ) {
		$type = isset( $cart_item['pllc_form_type'] ) ? $cart_item['pllc_form_type'] : '';
		return $type ? $type : 'particular';
	}

	private static function cart_has_form_type( $type ) {
		if ( ! function_exists( 'WC' ) || ! WC()->cart ) {
			return false;
		}
		foreach ( WC()->cart->get_cart() as $cart_item ) {
			if ( self::get_form_type( $cart_item ) === $type ) {
				return true;
			}
		}
		return false;
	}

	private static function is_colegios_only_cart() {
		return self::cart_has_form_type( 'colegios' ) && ! self::cart_has_form_type( 'particular' );
	}

	private static function is_iteo_only_cart() {
		$has_iteo  = self::cart_has_form_type( 'iteo_personal' ) || self::cart_has_form_type( 'iteo_pacientes' );
		$has_otros = self::cart_has_form_type( 'colegios' ) || self::cart_has_form_type( 'particular' );
		return $has_iteo && ! $has_otros;
	}

	private static function should_mask_iteo_total() {
		// El rol no define qué importes mostrar: un usuario ITEO también puede
		// sumar Particulares. Solo se enmascara el total si TODO el carrito es
		// ITEO; en carritos mixtos cada renglón conserva su tratamiento propio.
		return self::is_iteo_only_cart();
	}

	public static function mask_iteo_cart_subtotal( $subtotal, $compound = false, $cart = null ) {
		if ( self::should_mask_iteo_total() ) {
			return '<span class="woocommerce-Price-amount amount">---</span>';
		}
		return $subtotal;
	}

	public static function maybe_disable_shipping( $needs_shipping ) {
		if ( self::is_colegios_only_cart() || self::is_iteo_only_cart() ) {
			return false;
		}
		return $needs_shipping;
	}

	/**
	 * En pedidos mixtos, el envío a domicilio corresponde sólo a Particulares.
	 * Los productos institucionales conservan sus propias unidades de entrega,
	 * pero no intervienen en peso, cantidad, clase ni costo del paquete enviado.
	 */
	public static function limit_shipping_packages_to_particulars( $packages ) {
		if ( ! is_array( $packages ) ) {
			return $packages;
		}

		foreach ( $packages as $package_key => $package ) {
			if ( empty( $package['contents'] ) || ! is_array( $package['contents'] ) ) {
				continue;
			}

			$particular_contents = array_filter( $package['contents'], function ( $cart_item ) {
				return 'particular' === self::get_form_type( $cart_item );
			} );

			if ( empty( $particular_contents ) ) {
				unset( $packages[ $package_key ] );
				continue;
			}

			$contents_cost = 0;
			foreach ( $particular_contents as $cart_item ) {
				$contents_cost += isset( $cart_item['line_total'] ) ? (float) $cart_item['line_total'] : 0;
			}

			$packages[ $package_key ]['contents']      = $particular_contents;
			$packages[ $package_key ]['contents_cost'] = $contents_cost;
		}

		return $packages;
	}

	public static function get_shipping_note() {
		if ( self::cart_has_form_type( 'colegios' ) && self::cart_has_form_type( 'particular' ) ) {
			return __( 'Te mostramos los datos de envío porque además del pedido escolar agregaste productos para vos (Particulares).', 'panza-llena-core' );
		}
		if ( self::cart_has_form_type( 'iteo_personal' ) && self::cart_has_form_type( 'particular' ) ) {
			return __( 'Te mostramos los datos de envío porque además del pedido de "ITEO Personal" agregaste productos para vos (Particulares).', 'panza-llena-core' );
		}
		if ( self::cart_has_form_type( 'iteo_pacientes' ) && self::cart_has_form_type( 'particular' ) ) {
			return __( 'Te mostramos los datos de envío porque además del pedido de "ITEO Pacientes" agregaste productos para vos (Particulares).', 'panza-llena-core' );
		}
		return '';
	}

	public static function maybe_show_cart_shipping_note() {
		if ( self::$cart_shipping_note_shown ) {
			return;
		}
		$note = self::get_shipping_note();
		if ( $note ) {
			self::$cart_shipping_note_shown = true;
			echo '<tr class="pllc-shipping-note-row"><td colspan="2"><div class="pllc-shipping-note">'
				. esc_html( $note )
				. '</div></td></tr>';
		}
	}

	public static function maybe_show_checkout_shipping_note() {
		$note = self::get_shipping_note();
		if ( $note ) {
			echo '<tr class="pllc-shipping-note-row"><td colspan="2"><div class="pllc-shipping-note">'
				. esc_html( $note )
				. '</div></td></tr>';
		}
	}

	/**
	 * Clases en el <body>:
	 * - pllc-colegios-only-cart: oculta el renglón de Subtotal (es igual
	 *   al Total al no haber envío).
	 * - pllc-iteo-only-cart: simplifica el bloque de totales a solo el
	 *   botón de checkout, y enmascara el importe del mini-carrito.
	 *
	 * Se calcula en TODO el sitio (no solo carrito/checkout) porque el
	 * ícono del mini-carrito (widget Menu Cart de Elementor) vive en el
	 * header y aparece en cualquier página.
	 */
	public static function add_cart_body_class( $classes ) {
		if ( is_admin() ) {
			return $classes;
		}
		if ( self::is_colegios_only_cart() ) {
			$classes[] = 'pllc-colegios-only-cart';
		}
		if ( self::is_iteo_only_cart() ) {
			$classes[] = 'pllc-iteo-only-cart';
		}
		if ( function_exists( 'WC' ) && WC()->cart && ! WC()->cart->is_empty() ) {
			$classes[] = 'pllc-grouped-order-cart';
		}
		if ( self::should_mask_iteo_total() ) {
			$classes[] = 'pllc-iteo-price-masked';
		}
		return $classes;
	}

	public static function add_row_class( $class, $cart_item, $cart_item_key ) {
		return $class . ' pllc-form-type-' . sanitize_html_class( self::get_form_type( $cart_item ) );
	}

	/**
	 * Clave para decidir si dos líneas del carrito pertenecen al MISMO
	 * pedido visual: si hay nombre de alumno, se agrupa por nombre+colegio
	 * (así el mismo alumno no se duplica en dos títulos si pidió en dos
	 * tandas separadas); si no hay nombre (ITEO, Particular), se agrupa
	 * por tipo — un solo título para todo ese tipo en el carrito.
	 */
	private static function get_display_group_key( $cart_item ) {
		$form_type = self::get_form_type( $cart_item );
		$form      = ( isset( $cart_item['pllc_form'] ) && is_array( $cart_item['pllc_form'] ) ) ? $cart_item['pllc_form'] : [];

		if ( ! empty( $form['nombre_alumno'] ) ) {
			$colegio = isset( $form['colegio'] ) ? $form['colegio'] : '';
			return 'nombre:' . strtolower( trim( $form['nombre_alumno'] ) ) . '|' . strtolower( trim( $colegio ) );
		}

		return 'tipo:' . $form_type;
	}

	public static function inject_group_header( $item_data, $cart_item ) {
		if ( is_checkout() && ! is_cart() ) {
			return $item_data;
		}

		$display_key = self::get_display_group_key( $cart_item );

		if ( $display_key === self::$last_group_key ) {
			return $item_data; // Ya se mostró el título de este pedido.
		}

		self::$last_group_key = $display_key;

		$form_type = self::get_form_type( $cart_item );
		$form    = ( isset( $cart_item['pllc_form'] ) && is_array( $cart_item['pllc_form'] ) ) ? $cart_item['pllc_form'] : [];
		$header  = 'particular' === $form_type ? __( 'Pedido para mí', 'panza-llena-core' ) : self::build_group_label( $form, $form_type );
		$summary = self::build_summary_line( $form );

		$html = '<div class="pllc-cart-group-header">' . esc_html( $header ) . '</div>';

		if ( $summary ) {
			$html .= '<div class="pllc-cart-group-summary">' . $summary . '</div>';
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
	 * Agrega "Día X" arriba del nombre en cada producto organizado por día,
	 * incluidos los productos de Particulares.
	 */
	public static function inject_day_label( $item_data, $cart_item ) {
		if ( is_checkout() || is_cart() ) {
			return $item_data;
		}

		$form_type = self::get_form_type( $cart_item );

		if ( ! isset( self::DAY_CATEGORY_PREFIX[ $form_type ] ) ) {
			return $item_data;
		}

		$day_label = self::get_cart_item_day_label( $cart_item, self::DAY_CATEGORY_PREFIX[ $form_type ] );

		if ( ! $day_label ) {
			return $item_data;
		}

		$item_data[] = [
			'key'     => 'pllc-day-label',
			'name'    => '',
			'value'   => '',
			/* translators: %s: día de la semana */
			'display' => '<div class="pllc-cart-day-label">' . esc_html( sprintf( __( 'Día %s', 'panza-llena-core' ), $day_label ) ) . '</div>',
		];

		return $item_data;
	}

	/**
	 * En checkout inserta el encabezado antes del primer plato del pedido,
	 * sin generar los rótulos técnicos pllc-group-header/pllc-day-label.
	 */
	public static function format_checkout_item_name( $product_name, $cart_item, $cart_item_key ) {
		if ( ! is_checkout() || is_cart() ) {
			return $product_name;
		}

		$form_type   = self::get_form_type( $cart_item );
		$display_key = self::get_display_group_key( $cart_item );
		$prefix      = '';

		if ( $display_key !== self::$last_checkout_group_key ) {
			self::$last_checkout_group_key = $display_key;
			$form = ( isset( $cart_item['pllc_form'] ) && is_array( $cart_item['pllc_form'] ) ) ? $cart_item['pllc_form'] : [];

			$header  = 'particular' === $form_type ? __( 'Pedido para mí', 'panza-llena-core' ) : self::build_group_label( $form, $form_type );
			$summary = self::build_summary_line( $form );

			$prefix = '<div class="pllc-checkout-group-header"><div class="pllc-cart-group-header">' . esc_html( $header ) . '</div>';
			if ( $summary ) {
				$prefix .= '<div class="pllc-cart-group-summary">' . $summary . '</div>';
			}
			$prefix .= '</div>';
		}

		if ( isset( self::DAY_CATEGORY_PREFIX[ $form_type ] ) && ! empty( $cart_item['product_id'] ) ) {
			$day = self::get_cart_item_day_label( $cart_item, self::DAY_CATEGORY_PREFIX[ $form_type ] );
			if ( $day ) {
				$prefix .= '<div class="pllc-cart-day-label">' . esc_html( sprintf( __( 'Día %s', 'panza-llena-core' ), $day ) ) . '</div>';
			}
		}

		return $prefix . '<span class="pllc-checkout-product-name">' . $product_name . '</span>';
	}

	/** En el carrito muestra el día antes del nombre del producto. */
	public static function format_cart_item_name( $product_name, $cart_item, $cart_item_key ) {
		if ( ! is_cart() ) {
			return $product_name;
		}

		$form_type = self::get_form_type( $cart_item );
		if ( ! isset( self::DAY_CATEGORY_PREFIX[ $form_type ] ) || empty( $cart_item['product_id'] ) ) {
			return $product_name;
		}

		$day = self::get_cart_item_day_label( $cart_item, self::DAY_CATEGORY_PREFIX[ $form_type ] );
		if ( ! $day ) {
			return $product_name;
		}

		return '<div class="pllc-cart-day-label pllc-cart-day-label-before-product">'
			. esc_html( sprintf( __( 'Día %s', 'panza-llena-core' ), $day ) )
			. '</div>' . $product_name;
	}

	/** El cupón se desactiva únicamente en ITEO Personal/Pacientes. */
	public static function disable_checkout_coupons( $enabled ) {
		return ( is_checkout() && ! is_cart() && self::should_mask_iteo_total() ) ? false : $enabled;
	}

	private static function get_day_label( $product_id, $prefix ) {
		$terms = wc_get_product_terms( $product_id, 'product_cat', [ 'fields' => 'all' ] );

		if ( is_wp_error( $terms ) || empty( $terms ) ) {
			return '';
		}

		foreach ( $terms as $term ) {
			if ( 0 === strpos( $term->slug, $prefix ) ) {
				return $term->name;
			}
		}

		return '';
	}

	private static function get_cart_item_day_label( $cart_item, $prefix ) {
		$day  = ! empty( $cart_item['pllc_day'] ) ? sanitize_key( $cart_item['pllc_day'] ) : '';
		$date = isset( $cart_item['pllc_delivery_date'] ) ? $cart_item['pllc_delivery_date'] : '';
		if ( class_exists( 'PLLC_Order_Rules' ) && ( $day || $date ) ) {
			$formatted = PLLC_Order_Rules::format_delivery_label( $day, $date );
			if ( $formatted ) {
				return $formatted;
			}
		}

		if ( $day ) {
			$labels = [
				'lunes' => 'Lunes', 'martes' => 'Martes', 'miercoles' => 'Miércoles',
				'jueves' => 'Jueves', 'viernes' => 'Viernes', 'sabado' => 'Sábado',
			];
			if ( isset( $labels[ $day ] ) ) {
				return $labels[ $day ];
			}
		}
		return self::get_day_label( $cart_item['product_id'], $prefix );
	}

	private static function build_group_label( $form, $form_type ) {
		if ( ! empty( $form['nombre_alumno'] ) ) {
			/* translators: %s: nombre del alumno */
			return sprintf( __( 'Pedido para %s', 'panza-llena-core' ), $form['nombre_alumno'] );
		}

		$labels_por_tipo = [
			'iteo_personal'  => __( 'Pedido ITEO personal', 'panza-llena-core' ),
			'iteo_pacientes' => __( 'Pedido ITEO pacientes', 'panza-llena-core' ),
		];

		return isset( $labels_por_tipo[ $form_type ] ) ? $labels_por_tipo[ $form_type ] : __( 'Pedido', 'panza-llena-core' );
	}

	private static function build_summary_line( $form ) {
		$labels = [
			'colegio'       => __( 'Colegio', 'panza-llena-core' ),
			'nivel'         => __( 'Nivel', 'panza-llena-core' ),
			'curso'         => __( 'Curso', 'panza-llena-core' ),
			'cubiertos'     => __( 'Cubiertos descartables', 'panza-llena-core' ),
		];

		$parts = [];

		foreach ( $labels as $key => $label ) {
			if ( ! empty( $form[ $key ] ) ) {
				$parts[] = '<strong>' . esc_html( $label ) . ':</strong> ' . esc_html( $form[ $key ] );
			}
		}

		$html = implode( ' | ', $parts );
		if ( ! empty( $form['observaciones'] ) ) {
			$html .= '<span class="pllc-kitchen-observations"><strong>'
				. esc_html__( 'Observaciones para la cocina', 'panza-llena-core' )
				. ':</strong> ' . esc_html( $form['observaciones'] ) . '</span>';
		}

		return $html;
	}

	public static function maybe_hide_price( $price_html, $cart_item, $cart_item_key ) {
		if ( in_array( self::get_form_type( $cart_item ), self::HIDE_PRICE_FOR, true ) ) {
			return '<span class="pllc-hidden-price">---</span>';
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
