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

		add_action( 'wp_ajax_pllc_remove_order_item', [ __CLASS__, 'handle_remove_order_item' ] );
		add_action( 'wp_ajax_nopriv_pllc_remove_order_item', [ __CLASS__, 'handle_remove_order_item' ] );
		add_action( 'wp_ajax_pllc_update_particular_quantity', [ __CLASS__, 'handle_update_particular_quantity' ] );
		add_action( 'wp_ajax_nopriv_pllc_update_particular_quantity', [ __CLASS__, 'handle_update_particular_quantity' ] );
		add_action( 'wp_ajax_pllc_add_particular_quantity', [ __CLASS__, 'handle_add_particular_quantity' ] );
		add_action( 'wp_ajax_nopriv_pllc_add_particular_quantity', [ __CLASS__, 'handle_add_particular_quantity' ] );
		add_action( 'wp_ajax_pllc_save_particular_observations', [ __CLASS__, 'handle_save_particular_observations' ] );
		add_action( 'wp_ajax_nopriv_pllc_save_particular_observations', [ __CLASS__, 'handle_save_particular_observations' ] );
		add_action( 'woocommerce_checkout_order_created', [ __CLASS__, 'clear_particular_observations' ], 30 );

		// Muestra los datos del formulario en el carrito/checkout, para
		// poder confirmar visualmente que llegaron bien mientras probamos.
		add_filter( 'woocommerce_get_item_data', [ __CLASS__, 'display_item_data' ], 10, 2 );
		add_filter( 'woocommerce_add_cart_item_data', [ __CLASS__, 'add_particular_day_to_cart_item' ], 10, 4 );
	}

	/** Agrega un producto Particular sin enviar el formulario nativo por POST. */
	public static function handle_add_particular_quantity() {
		check_ajax_referer( 'pllc_add_order', 'nonce' );
		if ( ! function_exists( 'WC' ) || ! WC()->cart ) {
			wp_send_json_error( [ 'message' => 'WooCommerce no está disponible.' ] );
		}

		$product_id = isset( $_POST['product_id'] ) ? absint( $_POST['product_id'] ) : 0;
		$quantity   = isset( $_POST['quantity'] ) ? max( 1, absint( $_POST['quantity'] ) ) : 1;
		$day        = isset( $_POST['pllc_day'] ) ? sanitize_key( wp_unslash( $_POST['pllc_day'] ) ) : '';
		$product    = $product_id ? wc_get_product( $product_id ) : false;

		if ( ! $product || ! $product->is_type( 'simple' ) || ! $product->is_purchasable() || ! $product->is_in_stock() ) {
			wp_send_json_error( [ 'message' => 'Este producto no está disponible para comprar.' ] );
		}
		if ( ! class_exists( 'PLLC_Access' ) || ! PLLC_Access::is_particular_product( $product_id ) ) {
			wp_send_json_error( [ 'message' => 'El producto no pertenece a Particulares.' ] );
		}
		if ( class_exists( 'PLLC_Code_Access' ) && ! PLLC_Code_Access::form_type_allowed( 'particular' ) ) {
			wp_send_json_error( [ 'message' => 'Tu acceso no habilita productos de Particulares.' ] );
		}

		$observations = self::get_requested_particular_observations();
		self::store_particular_observations( $observations );
		$cart_item_data = [
			'pllc_form_type' => 'particular',
			'pllc_form'      => [ 'observaciones' => $observations ],
		];
		if ( in_array( $day, [ 'lunes', 'martes', 'miercoles', 'jueves', 'viernes', 'sabado' ], true ) ) {
			$terms = wc_get_product_terms( $product_id, 'product_cat', [ 'fields' => 'slugs' ] );
			if ( ! is_wp_error( $terms ) && in_array( 'particulares-' . $day, $terms, true ) ) {
				$cart_item_data['pllc_day'] = $day;
			}
		}

		$cart_key = WC()->cart->add_to_cart( $product_id, $quantity, 0, [], $cart_item_data );
		if ( ! $cart_key ) {
			wp_send_json_error( [ 'message' => 'No se pudo agregar el producto al carrito.' ] );
		}
		WC()->cart->calculate_totals();
		WC()->cart->set_session();
		wp_send_json_success( array_merge(
			[ 'cart_item_key' => $cart_key, 'quantity' => $quantity ],
			self::get_cart_event_data()
		) );
	}

	/**
	 * El mismo producto puede venderse en varios días. El día pasa a formar
	 * parte de los datos de la línea para que WooCommerce no mezcle cantidades.
	 */
	public static function add_particular_day_to_cart_item( $cart_item_data, $product_id, $variation_id, $quantity ) {
		if ( ! class_exists( 'PLLC_Access' ) || ! PLLC_Access::is_particular_product( $product_id ) ) {
			return $cart_item_data;
		}

		$cart_item_data['pllc_form_type'] = 'particular';
		$cart_item_data['pllc_form']      = [ 'observaciones' => self::get_particular_observations() ];

		$day     = isset( $_REQUEST['pllc_day'] ) ? sanitize_key( wp_unslash( $_REQUEST['pllc_day'] ) ) : '';
		$allowed = [ 'lunes', 'martes', 'miercoles', 'jueves', 'viernes', 'sabado' ];
		if ( ! in_array( $day, $allowed, true ) ) {
			return $cart_item_data;
		}

		$terms = wc_get_product_terms( $product_id, 'product_cat', [ 'fields' => 'slugs' ] );
		if ( is_wp_error( $terms ) || ! in_array( 'particulares-' . $day, $terms, true ) ) {
			return $cart_item_data;
		}

		$cart_item_data['pllc_day'] = $day;
		return $cart_item_data;
	}

	/** Actualiza una línea Particular existente sin volver a agregarla. */
	public static function handle_update_particular_quantity() {
		check_ajax_referer( 'pllc_add_order', 'nonce' );

		if ( ! function_exists( 'WC' ) || ! WC()->cart ) {
			wp_send_json_error( [ 'message' => 'WooCommerce no está disponible.' ] );
		}

		$cart_key = isset( $_POST['cart_item_key'] ) ? sanitize_text_field( wp_unslash( $_POST['cart_item_key'] ) ) : '';
		$quantity = isset( $_POST['quantity'] ) ? max( 1, absint( $_POST['quantity'] ) ) : 1;
		$item     = $cart_key ? WC()->cart->get_cart_item( $cart_key ) : [];

		if ( ! $item ) {
			wp_send_json_error( [ 'message' => 'El producto ya no está en el carrito.' ] );
		}

		$item_type = ! empty( $item['pllc_form_type'] ) ? $item['pllc_form_type'] : 'particular';
		if ( 'particular' !== $item_type ) {
			wp_send_json_error( [ 'message' => 'El producto no pertenece a Particulares.' ] );
		}

		if ( isset( $_POST['observaciones'] ) ) {
			self::store_particular_observations( sanitize_textarea_field( wp_unslash( $_POST['observaciones'] ) ) );
		}

		if ( false === WC()->cart->set_quantity( $cart_key, $quantity, true ) ) {
			wp_send_json_error( [ 'message' => 'No se pudo actualizar esa cantidad.' ] );
		}
		WC()->cart->calculate_totals();
		WC()->cart->set_session();

		wp_send_json_success( array_merge(
			[ 'quantity' => $quantity ],
			self::get_cart_event_data()
		) );
	}

	/** Guarda una única observación de cocina para todo el pedido Particular. */
	public static function handle_save_particular_observations() {
		check_ajax_referer( 'pllc_add_order', 'nonce' );
		if ( ! function_exists( 'WC' ) || ! WC()->session || ! WC()->cart ) {
			wp_send_json_error( [ 'message' => 'WooCommerce no está disponible.' ] );
		}

		$observations = isset( $_POST['observaciones'] )
			? sanitize_textarea_field( wp_unslash( $_POST['observaciones'] ) )
			: '';
		self::store_particular_observations( $observations );
		wp_send_json_success( [ 'observaciones' => $observations ] );
	}

	/** Evita reutilizar las observaciones en una compra posterior. */
	public static function clear_particular_observations( $order ) {
		if ( function_exists( 'WC' ) && WC()->session ) {
			WC()->session->__unset( 'pllc_particular_observations' );
		}
	}

	private static function get_requested_particular_observations() {
		return isset( $_POST['observaciones'] )
			? sanitize_textarea_field( wp_unslash( $_POST['observaciones'] ) )
			: self::get_particular_observations();
	}

	private static function get_particular_observations() {
		if ( function_exists( 'WC' ) && WC()->session ) {
			$stored = WC()->session->get( 'pllc_particular_observations', null );
			if ( null !== $stored ) {
				return sanitize_textarea_field( (string) $stored );
			}
		}

		if ( function_exists( 'WC' ) && WC()->cart ) {
			foreach ( WC()->cart->get_cart() as $item ) {
				if ( 'particular' !== ( isset( $item['pllc_form_type'] ) ? $item['pllc_form_type'] : 'particular' ) ) {
					continue;
				}
				$form = ( isset( $item['pllc_form'] ) && is_array( $item['pllc_form'] ) ) ? $item['pllc_form'] : [];
				if ( isset( $form['observaciones'] ) ) {
					return sanitize_textarea_field( (string) $form['observaciones'] );
				}
			}
		}

		return '';
	}

	private static function store_particular_observations( $observations ) {
		$observations = sanitize_textarea_field( (string) $observations );
		if ( function_exists( 'WC' ) && WC()->session ) {
			WC()->session->set( 'pllc_particular_observations', $observations );
		}
		if ( function_exists( 'WC' ) && WC()->cart ) {
			foreach ( WC()->cart->get_cart() as $key => $item ) {
				$item_type = isset( $item['pllc_form_type'] ) ? $item['pllc_form_type'] : 'particular';
				if ( 'particular' !== $item_type ) {
					continue;
				}
				$form = ( isset( $item['pllc_form'] ) && is_array( $item['pllc_form'] ) ) ? $item['pllc_form'] : [];
				$form['observaciones'] = $observations;
				WC()->cart->cart_contents[ $key ]['pllc_form'] = $form;
			}
			WC()->cart->set_session();
		}
	}

	public static function handle_add_order() {
		check_ajax_referer( 'pllc_add_order', 'nonce' );

		if ( ! function_exists( 'WC' ) || ! WC()->cart ) {
			wp_send_json_error( [ 'message' => 'WooCommerce no está disponible.' ] );
		}

		$form_type      = isset( $_POST['form_type'] ) ? sanitize_key( wp_unslash( $_POST['form_type'] ) ) : '';
		if ( class_exists( 'PLLC_Code_Access' ) && ! PLLC_Code_Access::form_type_allowed( $form_type ) ) {
			wp_send_json_error( [ 'message' => 'Tu código no habilita este tipo de pedido. Ingresá el código correspondiente para continuar.' ] );
		}
		$student_key    = isset( $_POST['student_key'] ) ? sanitize_key( wp_unslash( $_POST['student_key'] ) ) : '';
		$form_raw       = isset( $_POST['form'] ) ? wp_unslash( $_POST['form'] ) : '{}';
		$items_raw      = isset( $_POST['items'] ) ? wp_unslash( $_POST['items'] ) : '[]';
		$updates_raw    = isset( $_POST['updates'] ) ? wp_unslash( $_POST['updates'] ) : '[]';
		$quantity_updates_raw = isset( $_POST['quantity_updates'] ) ? wp_unslash( $_POST['quantity_updates'] ) : '[]';
		$meal_updates_raw = isset( $_POST['meal_updates'] ) ? wp_unslash( $_POST['meal_updates'] ) : '[]';

		$form_submitted = json_decode( $form_raw, true );
		$items          = json_decode( $items_raw, true );
		$updates        = json_decode( $updates_raw, true );
		$quantity_updates = json_decode( $quantity_updates_raw, true );
		$meal_updates   = json_decode( $meal_updates_raw, true );

		$items   = is_array( $items ) ? $items : [];
		$updates = is_array( $updates ) ? $updates : [];
		$quantity_updates = is_array( $quantity_updates ) ? $quantity_updates : [];
		$meal_updates = is_array( $meal_updates ) ? $meal_updates : [];
		$has_existing_order = self::cart_has_form_type( $form_type );

		if ( empty( $items ) && empty( $updates ) && empty( $quantity_updates ) && empty( $meal_updates ) && ! $student_key && ! $has_existing_order ) {
			wp_send_json_error( [ 'message' => 'No hay productos seleccionados.' ] );
		}

		$form_submitted_clean = [];
		if ( is_array( $form_submitted ) ) {
			foreach ( $form_submitted as $key => $value ) {
				$value = is_scalar( $value ) ? (string) $value : '';
				$form_submitted_clean[ sanitize_key( $key ) ] = 'observaciones' === $key
					? sanitize_textarea_field( $value )
					: sanitize_text_field( $value );
			}
		}

		if ( 'colegios' === $form_type && ! $student_key ) {
			$school_error = self::validate_school_fields( $form_submitted_clean );
			if ( $school_error ) {
				wp_send_json_error( [ 'message' => $school_error ] );
			}
		}

		// Al completar un pedido de un alumno existente, sus datos escolares
		// se recuperan del carrito y no se confía en valores manipulables del
		// navegador. Observaciones es el único campo que puede actualizarse.
		if ( 'colegios' === $form_type && $student_key ) {
			$existing_form = self::get_existing_student_form( $student_key );
			if ( empty( $existing_form ) ) {
				wp_send_json_error( [ 'message' => 'No se encontró el pedido del alumno seleccionado.' ] );
			}
			$existing_form['observaciones'] = isset( $form_submitted_clean['observaciones'] ) ? $form_submitted_clean['observaciones'] : '';
			$form_submitted_clean = $existing_form;
		}

		// Combina lo recién tipeado con lo que ya hubiera guardado de un
		// pedido anterior del mismo alumno/tipo — un campo vacío en este
		// envío NO borra lo que ya estaba escrito, solo lo pisa si trae
		// contenido nuevo.
		$form_clean = self::merge_with_existing_form( $form_type, $form_submitted_clean );

		$slot_error = self::validate_iteo_personal_slots( $form_type, $items, $meal_updates );
		if ( $slot_error ) {
			wp_send_json_error( [ 'message' => $slot_error ] );
		}

		$group_id = wp_generate_uuid4();
		$added    = 0;
		$updated  = 0;
		$quantity_updated = 0;
		$meal_updated = 0;

		foreach ( $updates as $update ) {
			if ( self::update_existing_variation( $update, $form_type, $student_key, $form_clean ) ) {
				$updated++;
			}
		}

		foreach ( $quantity_updates as $quantity_update ) {
			if ( self::update_existing_quantity( $quantity_update, $form_type ) ) {
				$quantity_updated++;
			}
		}

		foreach ( $meal_updates as $meal_update ) {
			if ( self::update_existing_meals( $meal_update, $form_type, $form_clean ) ) {
				$meal_updated++;
			}
		}

		foreach ( $items as $item ) {
			$product_id = isset( $item['product_id'] ) ? absint( $item['product_id'] ) : 0;

			if ( ! $product_id ) {
				continue;
			}
			if ( 'particular' === $form_type ) {
				$product = wc_get_product( $product_id );
				if ( ! $product || ! $product->is_type( 'simple' ) || ! $product->is_purchasable() || ! $product->is_in_stock() ) {
					continue;
				}
				if ( ! class_exists( 'PLLC_Access' ) || ! PLLC_Access::is_particular_product( $product_id ) ) {
					continue;
				}
			}

			$variation_id = ! empty( $item['variation_id'] ) ? absint( $item['variation_id'] ) : 0;
			$quantity     = ! empty( $item['qty'] ) ? max( 1, absint( $item['qty'] ) ) : 1;
			$meals        = ( ! empty( $item['meals'] ) && is_array( $item['meals'] ) ) ? array_map( 'sanitize_text_field', $item['meals'] ) : [];
			$day          = self::normalize_item_day( $item, $form_type, $product_id );
			if ( 'particular' === $form_type && ! $day ) {
				continue;
			}

			// Si eligió Almuerzo Y Cena, van como DOS líneas de carrito
			// separadas (mismo producto, una comida cada una) en vez de
			// una sola línea con las dos juntas.
			if ( $meals ) {
				foreach ( $meals as $meal ) {
					$cart_item_data = [
						'pllc_group'     => $group_id,
						'pllc_form_type' => $form_type,
						'pllc_form'      => $form_clean,
						'pllc_meals'     => [ $meal ],
						'pllc_day'       => $day,
					];

					if ( WC()->cart->add_to_cart( $product_id, $quantity, $variation_id, [], $cart_item_data ) ) {
						$added++;
					}
				}
				continue;
			}

			$cart_item_data = [
				'pllc_group'     => $group_id,
				'pllc_form_type' => $form_type,
				'pllc_form'      => $form_clean,
				'pllc_day'       => $day,
			];

			if ( WC()->cart->add_to_cart( $product_id, $quantity, $variation_id, [], $cart_item_data ) ) {
				$added++;
			}
		}

		if ( ! $added && ! $updated && ! $quantity_updated && ! $meal_updated && ! $student_key && ! $has_existing_order ) {
			wp_send_json_error( [ 'message' => 'No se pudo actualizar ningún producto del carrito.' ] );
		}

		if ( 'particular' === $form_type ) {
			self::store_particular_observations( isset( $form_clean['observaciones'] ) ? $form_clean['observaciones'] : '' );
		}
		self::sync_form_across_existing_items( $form_type, $form_clean );

		wp_send_json_success( array_merge( [
			'added'    => $added,
			'updated'  => $updated,
			'quantity_updated' => $quantity_updated,
			'meal_updated' => $meal_updated,
			'group_id' => $group_id,
		], self::get_cart_event_data() ) );
	}

	/** Datos estándar del evento WooCommerce `added_to_cart` que escucha Elementor. */
	private static function get_cart_event_data() {
		WC()->cart->calculate_totals();
		WC()->cart->set_session();

		ob_start();
		woocommerce_mini_cart();
		$mini_cart = ob_get_clean();
		$fragments = apply_filters(
			'woocommerce_add_to_cart_fragments',
			[
				'div.widget_shopping_cart_content' => '<div class="widget_shopping_cart_content">' . $mini_cart . '</div>',
			]
		);

		return [
			'fragments' => $fragments,
			'cart_hash' => WC()->cart->get_cart_hash(),
		];
	}

	private static function normalize_item_day( $item, $form_type, $product_id ) {
		$day = isset( $item['day'] ) ? sanitize_key( $item['day'] ) : '';
		if ( ! in_array( $day, [ 'lunes', 'martes', 'miercoles', 'jueves', 'viernes', 'sabado' ], true ) ) {
			return '';
		}
		$prefixes = [
			'colegios'       => 'colegios-',
			'iteo_personal'  => 'iteo-personal-',
			'iteo_pacientes' => 'iteo-pacientes-',
			'particular'     => 'particulares-',
		];
		if ( empty( $prefixes[ $form_type ] ) ) {
			return '';
		}
		$terms = wc_get_product_terms( $product_id, 'product_cat', [ 'fields' => 'slugs' ] );
		return ! is_wp_error( $terms ) && in_array( $prefixes[ $form_type ] . $day, $terms, true ) ? $day : '';
	}

	/**
	 * Comprueba el resultado final antes de modificar el carrito: por cada día
	 * solo puede quedar un producto para almuerzo y uno para cena.
	 */
	private static function validate_iteo_personal_slots( $form_type, $items, $meal_updates ) {
		if ( 'iteo_personal' !== $form_type ) {
			return '';
		}

		$selection = [];
		foreach ( WC()->cart->get_cart() as $cart_item ) {
			if ( empty( $cart_item['pllc_form_type'] ) || 'iteo_personal' !== $cart_item['pllc_form_type'] || empty( $cart_item['pllc_meals'][0] ) ) {
				continue;
			}
			$product_id = absint( $cart_item['product_id'] );
			$day = ! empty( $cart_item['pllc_day'] ) ? sanitize_key( $cart_item['pllc_day'] ) : self::get_iteo_personal_day( $product_id );
			$meal = sanitize_key( $cart_item['pllc_meals'][0] );
			if ( $product_id && $day && in_array( $meal, [ 'almuerzo', 'cena' ], true ) ) {
				$selection[ $day . '|' . $product_id ][ $meal ] = true;
			}
		}

		foreach ( $meal_updates as $update ) {
			if ( ! is_array( $update ) ) {
				continue;
			}
			$product_id = isset( $update['product_id'] ) ? absint( $update['product_id'] ) : 0;
			$day = isset( $update['day'] ) ? sanitize_key( $update['day'] ) : self::get_iteo_personal_day( $product_id );
			$selection_key = $day . '|' . $product_id;
			if ( ! $product_id || ! isset( $selection[ $selection_key ] ) ) {
				continue;
			}
			$selection[ $selection_key ] = [];
			$meals = ( isset( $update['meals'] ) && is_array( $update['meals'] ) ) ? $update['meals'] : [];
			foreach ( array_intersect( [ 'almuerzo', 'cena' ], array_map( 'sanitize_key', $meals ) ) as $meal ) {
				$selection[ $selection_key ][ $meal ] = true;
			}
		}

		foreach ( $items as $item ) {
			if ( ! is_array( $item ) || empty( $item['product_id'] ) || empty( $item['meals'] ) || ! is_array( $item['meals'] ) ) {
				continue;
			}
			$product_id = absint( $item['product_id'] );
			$day = isset( $item['day'] ) ? sanitize_key( $item['day'] ) : self::get_iteo_personal_day( $product_id );
			$selection_key = $day . '|' . $product_id;
			foreach ( array_intersect( [ 'almuerzo', 'cena' ], array_map( 'sanitize_key', $item['meals'] ) ) as $meal ) {
				$selection[ $selection_key ][ $meal ] = true;
			}
		}

		$slots = [];
		foreach ( $selection as $selection_key => $meals ) {
			list( $day, $product_id ) = array_pad( explode( '|', $selection_key, 2 ), 2, 0 );
			if ( ! $day ) {
				continue;
			}
			foreach ( array_keys( $meals ) as $meal ) {
				$slot = $day . '|' . $meal;
				if ( isset( $slots[ $slot ] ) && (string) $slots[ $slot ] !== (string) $product_id ) {
					return sprintf(
						'Para el %1$s solo podés seleccionar un plato para %2$s.',
						$day,
						$meal
					);
				}
				$slots[ $slot ] = $product_id;
			}
		}

		return '';
	}

	private static function get_iteo_personal_day( $product_id ) {
		$terms = wc_get_product_terms( $product_id, 'product_cat', [ 'fields' => 'slugs' ] );
		if ( is_wp_error( $terms ) ) {
			return '';
		}
		$prefix = 'iteo-personal-';
		foreach ( $terms as $slug ) {
			if ( 0 === strpos( $slug, $prefix ) ) {
				return sanitize_title( substr( $slug, strlen( $prefix ) ) );
			}
		}
		return '';
	}

	/**
	 * Actualiza Almuerzo/Cena de un producto ITEO Personal como una sola
	 * operación. Cada comida continúa siendo una línea independiente del
	 * carrito, pero la selección se edita desde una única card de producto.
	 */
	private static function update_existing_meals( $update, $form_type, $form_clean ) {
		if ( 'iteo_personal' !== $form_type || ! is_array( $update ) ) {
			return false;
		}

		$product_id = isset( $update['product_id'] ) ? absint( $update['product_id'] ) : 0;
		$day         = isset( $update['day'] ) ? sanitize_key( $update['day'] ) : '';
		$requested  = ( isset( $update['meals'] ) && is_array( $update['meals'] ) ) ? $update['meals'] : [];
		$requested  = array_values( array_unique( array_intersect( [ 'almuerzo', 'cena' ], array_map( 'sanitize_key', $requested ) ) ) );

		if ( ! $product_id ) {
			return false;
		}

		$existing = [];
		$template = [];
		foreach ( WC()->cart->get_cart() as $cart_key => $cart_item ) {
			if ( empty( $cart_item['pllc_form_type'] ) || 'iteo_personal' !== $cart_item['pllc_form_type'] ) {
				continue;
			}
			if ( absint( $cart_item['product_id'] ) !== $product_id || empty( $cart_item['pllc_meals'][0] ) ) {
				continue;
			}
			$cart_day = ! empty( $cart_item['pllc_day'] ) ? sanitize_key( $cart_item['pllc_day'] ) : self::get_iteo_personal_day( $product_id );
			if ( $day && $cart_day !== $day ) {
				continue;
			}
			$meal = sanitize_key( $cart_item['pllc_meals'][0] );
			if ( in_array( $meal, [ 'almuerzo', 'cena' ], true ) ) {
				$existing[ $meal ] = $cart_key;
				$template = $cart_item;
			}
		}

		if ( empty( $existing ) ) {
			return false;
		}

		$changed = false;
		foreach ( $existing as $meal => $cart_key ) {
			if ( ! in_array( $meal, $requested, true ) ) {
				WC()->cart->remove_cart_item( $cart_key );
				$changed = true;
			}
		}

		foreach ( $requested as $meal ) {
			if ( isset( $existing[ $meal ] ) ) {
				continue;
			}
			$cart_item_data = [
				'pllc_group'     => isset( $template['pllc_group'] ) ? $template['pllc_group'] : wp_generate_uuid4(),
				'pllc_form_type' => 'iteo_personal',
				'pllc_form'      => $form_clean,
				'pllc_meals'     => [ $meal ],
				'pllc_day'       => $day ? $day : ( isset( $template['pllc_day'] ) ? $template['pllc_day'] : '' ),
			];
			$variation_id = isset( $template['variation_id'] ) ? absint( $template['variation_id'] ) : 0;
			$variation    = ( $variation_id && ! empty( $template['variation'] ) && is_array( $template['variation'] ) ) ? $template['variation'] : [];

			if ( WC()->cart->add_to_cart( $product_id, 1, $variation_id, $variation, $cart_item_data ) ) {
				$changed = true;
			}
		}

		return $changed;
	}

	private static function cart_has_form_type( $form_type ) {
		if ( ! $form_type || ! function_exists( 'WC' ) || ! WC()->cart ) {
			return false;
		}
		foreach ( WC()->cart->get_cart() as $item ) {
			if ( isset( $item['pllc_form_type'] ) && $item['pllc_form_type'] === $form_type ) {
				return true;
			}
		}
		return false;
	}

	private static function update_existing_quantity( $update, $form_type ) {
		if ( ! in_array( $form_type, [ 'iteo_pacientes', 'particular' ], true ) || ! is_array( $update ) ) {
			return false;
		}
		$cart_key  = isset( $update['cart_item_key'] ) ? sanitize_text_field( $update['cart_item_key'] ) : '';
		$product_id = isset( $update['product_id'] ) ? absint( $update['product_id'] ) : 0;
		$quantity  = isset( $update['qty'] ) ? max( 0, absint( $update['qty'] ) ) : 0;
		$cart_item = $cart_key ? WC()->cart->get_cart_item( $cart_key ) : [];

		if ( ! $cart_item || empty( $cart_item['pllc_form_type'] ) || $form_type !== $cart_item['pllc_form_type'] ) {
			return false;
		}
		if ( ! $product_id || absint( $cart_item['product_id'] ) !== $product_id ) {
			return false;
		}
		if ( 0 === $quantity ) {
			return WC()->cart->remove_cart_item( $cart_key );
		}
		WC()->cart->set_quantity( $cart_key, $quantity, false );
		return true;
	}

	/**
	 * Sustituye la variación de una línea escolar existente conservando
	 * cantidad, alumno y metadatos del pedido. Solo acepta líneas que
	 * realmente pertenecen al alumno seleccionado en esta sesión.
	 */
	private static function update_existing_variation( $update, $form_type, $student_key, $form_clean ) {
		if ( 'colegios' !== $form_type || ! $student_key || ! is_array( $update ) ) {
			return false;
		}

		$cart_key     = isset( $update['cart_item_key'] ) ? sanitize_text_field( $update['cart_item_key'] ) : '';
		$variation_id = isset( $update['variation_id'] ) ? absint( $update['variation_id'] ) : 0;
		$cart_item    = $cart_key ? WC()->cart->get_cart_item( $cart_key ) : [];

		if ( ! $cart_item || ! $variation_id || empty( $cart_item['product_id'] ) ) {
			return false;
		}

		if ( empty( $cart_item['pllc_form_type'] ) || 'colegios' !== $cart_item['pllc_form_type'] ) {
			return false;
		}

		$existing_form = ( ! empty( $cart_item['pllc_form'] ) && is_array( $cart_item['pllc_form'] ) ) ? $cart_item['pllc_form'] : [];
		if ( ! hash_equals( self::build_student_hash( $existing_form ), $student_key ) ) {
			return false;
		}

		$variation = wc_get_product( $variation_id );
		if ( ! $variation || ! $variation->is_type( 'variation' ) || absint( $variation->get_parent_id() ) !== absint( $cart_item['product_id'] ) ) {
			return false;
		}

		if ( absint( $cart_item['variation_id'] ) === $variation_id ) {
			return false;
		}

		$cart_item_data = [];
		foreach ( $cart_item as $key => $value ) {
			if ( 0 === strpos( $key, 'pllc_' ) ) {
				$cart_item_data[ $key ] = $value;
			}
		}
		$cart_item_data['pllc_form'] = $form_clean;

		$new_key = WC()->cart->add_to_cart(
			absint( $cart_item['product_id'] ),
			max( 1, absint( $cart_item['quantity'] ) ),
			$variation_id,
			$variation->get_variation_attributes(),
			$cart_item_data
		);

		if ( ! $new_key ) {
			return false;
		}

		WC()->cart->remove_cart_item( $cart_key );
		return true;
	}

	/**
	 * Busca si ya hay un pedido del mismo alumno/tipo en el carrito y, si
	 * lo hay, combina su formulario guardado con lo recién tipeado — un
	 * campo que llega VACÍO en este envío no borra lo que ya había, solo
	 * se pisa si trae contenido nuevo. Si no hay nada previo, devuelve lo
	 * recién tipeado tal cual.
	 */
	private static function merge_with_existing_form( $form_type, $form_submitted_clean ) {
		if ( ! function_exists( 'WC' ) || ! WC()->cart ) {
			return $form_submitted_clean;
		}

		$new_key = self::build_display_key( $form_type, $form_submitted_clean );

		foreach ( WC()->cart->get_cart() as $existing_item ) {
			$existing_type = isset( $existing_item['pllc_form_type'] ) ? $existing_item['pllc_form_type'] : '';

			if ( $existing_type !== $form_type ) {
				continue;
			}

			$existing_form = ( isset( $existing_item['pllc_form'] ) && is_array( $existing_item['pllc_form'] ) ) ? $existing_item['pllc_form'] : [];
			$existing_key  = self::build_display_key( $existing_type, $existing_form );

			if ( $existing_key !== $new_key ) {
				continue;
			}

			$merged = $existing_form;
			foreach ( $form_submitted_clean as $field => $value ) {
				$merged[ $field ] = $value;
			}
			return $merged;
		}

		return $form_submitted_clean;
	}

	private static function get_existing_student_form( $student_key ) {
		if ( ! function_exists( 'WC' ) || ! WC()->cart ) {
			return [];
		}

		foreach ( WC()->cart->get_cart() as $item ) {
			if ( empty( $item['pllc_form_type'] ) || 'colegios' !== $item['pllc_form_type'] ) {
				continue;
			}

			$form = ( ! empty( $item['pllc_form'] ) && is_array( $item['pllc_form'] ) ) ? $item['pllc_form'] : [];
			$key = self::build_student_hash( $form );

			if ( hash_equals( $key, $student_key ) ) {
				return $form;
			}
		}

		return [];
	}

	/**
	 * Valida en el servidor la cascada Colegio -> Nivel -> Curso para evitar
	 * combinaciones inexistentes aunque se manipule el formulario del navegador.
	 */
	private static function validate_school_fields( $form ) {
		$schools = [
			'Plaza Mayor' => [
				'Jardín' => [ 'Sala de 1', 'Sala de 2', 'Sala de 3', 'Sala de 4', 'Sala de 5' ],
				'Primaria' => [
					'1er grado A', '1er grado B', '1er grado C',
					'2do grado A', '2do grado B', '2do grado C',
					'3er grado A', '3er grado B', '3er grado C',
					'4to grado A', '4to grado B', '4to grado C',
					'5to grado A', '5to grado B', '6to grado A', '6to grado B',
				],
				'Secundaria' => [
					'1er año A', '1er año B', '2do año A', '2do Año B',
					'3er año A', '3er año B', '4to año', '5to año', '6to año',
				],
			],
			'Michelangelo' => [
				'Secundaria' => [ '1er año', '2do año', '3er año' ],
			],
			'CAE' => [
				'Primaria' => [
					'1er grado', '2do grado', '3er grado', '4to grado',
					'5to grado', '6to grado', '7mo grado',
				],
				'Secundaria' => [ '1er año', '2do año', '3er año', '4to año', '5to año' ],
			],
		];

		$name   = isset( $form['nombre_alumno'] ) ? trim( $form['nombre_alumno'] ) : '';
		$school = isset( $form['colegio'] ) ? trim( $form['colegio'] ) : '';
		$level  = isset( $form['nivel'] ) ? trim( $form['nivel'] ) : '';
		$course = isset( $form['curso'] ) ? trim( $form['curso'] ) : '';

		if ( '' === $name || '' === $school || '' === $level || '' === $course ) {
			return 'Completá todos los campos obligatorios del alumno.';
		}
		if ( ! isset( $schools[ $school ][ $level ] ) || ! in_array( $course, $schools[ $school ][ $level ], true ) ) {
			return 'La combinación de colegio, nivel escolar y curso no es válida.';
		}

		return '';
	}

	private static function build_student_hash( $form ) {
		$name   = isset( $form['nombre_alumno'] ) ? trim( $form['nombre_alumno'] ) : '';
		$school = isset( $form['colegio'] ) ? trim( $form['colegio'] ) : '';
		return md5( strtolower( $name ) . '|' . strtolower( $school ) );
	}

	/**
	 * Aplica el formulario ya combinado (ver merge_with_existing_form) a
	 * TODOS los productos existentes del mismo pedido — así el resumen
	 * que se ve en el carrito queda consistente en todas las líneas, no
	 * solo en las que se acaban de agregar.
	 */
	private static function sync_form_across_existing_items( $form_type, $form_clean ) {
		$new_key = self::build_display_key( $form_type, $form_clean );

		foreach ( WC()->cart->get_cart() as $key => $existing_item ) {
			$existing_type = isset( $existing_item['pllc_form_type'] ) ? $existing_item['pllc_form_type'] : '';

			if ( $existing_type !== $form_type ) {
				continue;
			}

			$existing_form = ( isset( $existing_item['pllc_form'] ) && is_array( $existing_item['pllc_form'] ) ) ? $existing_item['pllc_form'] : [];
			$existing_key  = self::build_display_key( $existing_type, $existing_form );

			if ( $existing_key === $new_key ) {
				WC()->cart->cart_contents[ $key ]['pllc_form'] = $form_clean;
			}
		}

		WC()->cart->set_session();
	}

	/**
	 * Misma lógica de agrupamiento que PLLC_Cart_Groups::get_display_group_key()
	 * — si hay nombre de alumno, agrupa por nombre+colegio; si no, por tipo.
	 */
	private static function build_display_key( $form_type, $form ) {
		if ( ! empty( $form['nombre_alumno'] ) ) {
			$colegio = isset( $form['colegio'] ) ? $form['colegio'] : '';
			return 'nombre:' . strtolower( trim( $form['nombre_alumno'] ) ) . '|' . strtolower( trim( $colegio ) );
		}

		return 'tipo:' . $form_type;
	}

	/**
	 * Saca del carrito real uno o más productos que el usuario ya había
	 * agregado en una visita anterior — se usa cuando hace clic en
	 * "Eliminar" sobre una card que ya está reflejada en el carrito
	 * (identificada por PLLC_Frontend_Assets al cargar la página).
	 */
	public static function handle_remove_order_item() {
		check_ajax_referer( 'pllc_add_order', 'nonce' );

		if ( ! function_exists( 'WC' ) || ! WC()->cart ) {
			wp_send_json_error( [ 'message' => 'WooCommerce no está disponible.' ] );
		}

		$keys = isset( $_POST['cart_item_keys'] ) ? (array) wp_unslash( $_POST['cart_item_keys'] ) : [];
		$keys = array_map( 'sanitize_text_field', $keys );

		foreach ( $keys as $key ) {
			WC()->cart->remove_cart_item( $key );
		}

		$has_iteo_personal = false;
		foreach ( WC()->cart->get_cart() as $cart_item ) {
			if ( ! empty( $cart_item['pllc_form_type'] ) && 'iteo_personal' === $cart_item['pllc_form_type'] ) {
				$has_iteo_personal = true;
				break;
			}
		}

		wp_send_json_success( array_merge(
			[ 'has_iteo_personal' => $has_iteo_personal ],
			self::get_cart_event_data()
		) );
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
				'value' => ucfirst( implode( ', ', $cart_item['pllc_meals'] ) ),
			];
		}

		return $item_data;
	}
}
