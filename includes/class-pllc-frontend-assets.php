<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Encola el JS/CSS propio del frontend, y le pasa al JS (vía
 * wp_localize_script) el estado actual del carrito para esta página —
 * así, si el usuario ya había agregado algo antes, la página lo muestra
 * tildado/marcado como "Eliminar" en vez de vacío.
 */
class PLLC_Frontend_Assets {

	const PAGE_SLUGS = [ 'colegios', 'iteo-personal', 'iteo-pacientes', 'particulares' ];

	// Slug de página -> form_type, para saber qué parte del carrito mirar.
	const PAGE_FORM_TYPE = [
		'iteo-personal'  => 'iteo_personal',
		'iteo-pacientes' => 'iteo_pacientes',
		'particulares'   => 'particular',
	];

	public static function init() {
		add_action( 'wp_enqueue_scripts', [ __CLASS__, 'enqueue' ] );
		add_filter( 'body_class', [ __CLASS__, 'add_role_page_body_class' ] );
	}

	/**
	 * Agrega una clase estable para aislar los estilos de cada recorrido.
	 * No depende de las clases que WordPress o Elementor decidan imprimir.
	 */
	public static function add_role_page_body_class( $classes ) {
		if ( is_front_page() ) {
			$classes[] = 'pllc-page-home';
		}

		if ( function_exists( 'is_shop' ) && is_shop() ) {
			$classes[] = 'pllc-page-particulares';
			return $classes;
		}

		if ( self::is_particular_product_page() ) {
			$classes[] = 'pllc-page-particulares';
			$classes[] = 'pllc-page-particular-product';
			return $classes;
		}

		if ( is_page() ) {
			$slug = get_post_field( 'post_name', get_queried_object_id() );
			if ( in_array( $slug, self::PAGE_SLUGS, true ) ) {
				$classes[] = 'pllc-page-' . sanitize_html_class( $slug );
			}
		}

		return $classes;
	}

	public static function enqueue() {
		$is_role_page = is_front_page() || is_page( self::PAGE_SLUGS ) || ( function_exists( 'is_shop' ) && is_shop() ) || self::is_particular_product_page();
		$is_order_details = ( function_exists( 'is_order_received_page' ) && is_order_received_page() )
			|| ( function_exists( 'is_wc_endpoint_url' ) && is_wc_endpoint_url( 'view-order' ) );
		$user_roles   = (array) wp_get_current_user()->roles;
		$is_iteo_user = (bool) array_intersect( [ 'iteo_personal', 'iteo_paciente' ], $user_roles );

		if ( ! $is_role_page && ! is_cart() && ! is_checkout() && ! $is_order_details && ! $is_iteo_user ) {
			return;
		}

		wp_enqueue_script(
			'pllc-frontend',
			PLLC_URL . 'assets/js/pllc-frontend.js',
			[],
			PLLC_VERSION,
			true
		);

		$form_type      = self::get_current_page_form_type();
		$frontend_state = self::build_frontend_state( $form_type );

		wp_localize_script( 'pllc-frontend', 'PLLC_Data', [
			'ajax_url'   => admin_url( 'admin-ajax.php' ),
			'nonce'      => wp_create_nonce( 'pllc_add_order' ),
			'cart_state' => $frontend_state['cart_state'],
			'current_form' => $frontend_state['current_form'],
			'students'   => $frontend_state['students'],
			'has_active_order' => $frontend_state['has_active_order'],
			'day_titles' => self::build_day_titles(),
			'day_dates' => class_exists( 'PLLC_Order_Rules' ) ? PLLC_Order_Rules::get_day_dates() : [],
			'day_availability' => class_exists( 'PLLC_Order_Rules' ) ? PLLC_Order_Rules::get_day_availability() : [],
			'shipping_note' => self::build_shipping_note(),
		] );

		wp_enqueue_style(
			'pllc-frontend',
			PLLC_URL . 'assets/css/pllc-frontend.css',
			[],
			PLLC_VERSION
		);
	}

	/**
	 * Fuente canónica del estado editable. Se usa tanto al cargar la página
	 * como después de cada mutación AJAX para no reconstruir el carrito en JS.
	 */
	public static function build_frontend_state( $form_type ) {
		$form_type = sanitize_key( (string) $form_type );
		if ( ! in_array( $form_type, [ 'colegios', 'iteo_personal', 'iteo_pacientes', 'particular' ], true ) ) {
			$form_type = '';
		}

		$students   = self::build_college_students();
		$cart_state = 'colegios' === $form_type ? [] : self::build_cart_state( $form_type );
		return [
			'form_type'        => $form_type,
			'cart_state'       => $cart_state,
			'current_form'     => 'colegios' === $form_type ? [] : self::build_current_form( $form_type ),
			'students'         => $students,
			'has_active_order' => 'colegios' === $form_type ? ! empty( $students ) : ! empty( $cart_state ),
		];
	}

	/**
	 * Envía el aviso de carrito mixto al frontend para que pueda insertarse
	 * aunque el widget de checkout de Elementor no ejecute los hooks estándar.
	 */
	private static function build_shipping_note() {
		if ( ! class_exists( 'PLLC_Cart_Groups' ) ) {
			return '';
		}

		return PLLC_Cart_Groups::get_shipping_note();
	}

	/**
	 * Devuelve la fecha de la próxima aparición de cada día del menú.
	 * La fecha base puede reemplazarse externamente mediante el filtro
	 * `pllc_current_datetime` (por ejemplo, desde Code Snippets para pruebas).
	 */
	private static function build_day_titles() {
		if ( class_exists( 'PLLC_Order_Rules' ) ) {
			return PLLC_Order_Rules::get_day_titles();
		}

		$now = apply_filters( 'pllc_current_datetime', current_datetime() );

		if ( ! $now instanceof DateTimeInterface ) {
			$now = current_datetime();
		}

		$timezone = wp_timezone();
		$base     = ( new DateTimeImmutable( '@' . $now->getTimestamp() ) )->setTimezone( $timezone );
		$days     = [
			'lunes'    => [ 'label' => 'Lunes', 'number' => 1 ],
			'martes'   => [ 'label' => 'Martes', 'number' => 2 ],
			'miercoles' => [ 'label' => 'Miércoles', 'number' => 3 ],
			'jueves'   => [ 'label' => 'Jueves', 'number' => 4 ],
			'viernes'  => [ 'label' => 'Viernes', 'number' => 5 ],
			'sabado'   => [ 'label' => 'Sábado', 'number' => 6 ],
		];
		$titles = [];

		foreach ( $days as $slug => $day ) {
			$days_ahead = ( $day['number'] - (int) $base->format( 'N' ) + 7 ) % 7;
			if ( 0 === $days_ahead ) {
				$days_ahead = 7;
			}

			$date = $base->modify( '+' . $days_ahead . ' days' );
			$titles[ $slug ] = sprintf(
				'%s %s',
				$day['label'],
				wp_date( 'j \\d\\e F', $date->getTimestamp(), $timezone )
			);
		}

		return $titles;
	}

	private static function get_current_page_form_type() {
		if ( is_front_page() || ( function_exists( 'is_shop' ) && is_shop() ) || self::is_particular_product_page() ) {
			return 'particular';
		}
		if ( ! is_page() ) {
			return '';
		}
		$slug = get_post_field( 'post_name', get_queried_object_id() );
		return isset( self::PAGE_FORM_TYPE[ $slug ] ) ? self::PAGE_FORM_TYPE[ $slug ] : '';
	}

	private static function is_particular_product_page() {
		if ( ! function_exists( 'is_product' ) || ! is_product() || ! class_exists( 'PLLC_Access' ) ) {
			return false;
		}
		return PLLC_Access::is_particular_product( get_queried_object_id() );
	}

	/**
	 * Arma, para el tipo de pedido de la página actual, un mapa
	 * product_id -> { slot: cart_item_key }. "slot" es el valor de la
	 * comida (almuerzo/cena) para ITEO Personal, o "_default" para
	 * Colegios/ITEO Pacientes (un solo posible ítem en carrito por
	 * producto).
	 */
	private static function build_cart_state( $form_type ) {
		$state = [];

		if ( ! $form_type || ! function_exists( 'WC' ) || ! WC()->cart ) {
			return $state;
		}

		foreach ( WC()->cart->get_cart() as $key => $item ) {
			$item_type = ! empty( $item['pllc_form_type'] ) ? $item['pllc_form_type'] : 'particular';

			if ( $item_type !== $form_type ) {
				continue;
			}

			$product_id = (string) $item['product_id'];

			if ( ! isset( $state[ $product_id ] ) ) {
				$state[ $product_id ] = [];
			}

			$target =& $state[ $product_id ];
			if ( ! empty( $item['pllc_day'] ) ) {
				$day = sanitize_key( $item['pllc_day'] );
				if ( ! isset( $state[ $product_id ]['_days'][ $day ] ) ) {
					$state[ $product_id ]['_days'][ $day ] = [];
				}
				$target =& $state[ $product_id ]['_days'][ $day ];
			}

			if ( ! empty( $item['pllc_meals'] ) ) {
				foreach ( $item['pllc_meals'] as $meal ) {
					$target[ $meal ] = $key;
				}
			} else {
				$target['_default']     = $key;
				$target['_default_qty'] = $item['quantity'];
				$target['_default_variation_id'] = isset( $item['variation_id'] ) ? absint( $item['variation_id'] ) : 0;
			}
			unset( $target );
		}

		return $state;
	}

	private static function build_current_form( $form_type ) {
		if ( ! $form_type || ! function_exists( 'WC' ) || ! WC()->cart ) {
			return [];
		}

		if ( 'particular' === $form_type && WC()->session ) {
			$stored = WC()->session->get( 'pllc_particular_observations', null );
			if ( null !== $stored ) {
				return [ 'observaciones' => sanitize_textarea_field( (string) $stored ) ];
			}
		}

		foreach ( WC()->cart->get_cart() as $item ) {
			$item_type = isset( $item['pllc_form_type'] ) ? $item['pllc_form_type'] : '';
			if ( $item_type === $form_type && ! empty( $item['pllc_form'] ) && is_array( $item['pllc_form'] ) ) {
				return $item['pllc_form'];
			}
		}

		return [];
	}

	/**
	 * Devuelve los alumnos que ya tienen productos escolares en el carrito,
	 * junto con sus datos y el estado de sus productos. El frontend mantiene
	 * "Nuevo alumno" como opción inicial y solo aplica este estado cuando el
	 * padre selecciona expresamente uno de los nombres.
	 */
	private static function build_college_students() {
		$students = [];

		if ( ! function_exists( 'WC' ) || ! WC()->cart ) {
			return [];
		}

		foreach ( WC()->cart->get_cart() as $cart_key => $item ) {
			if ( empty( $item['pllc_form_type'] ) || 'colegios' !== $item['pllc_form_type'] ) {
				continue;
			}

			$form = ( ! empty( $item['pllc_form'] ) && is_array( $item['pllc_form'] ) ) ? $item['pllc_form'] : [];
			$name = isset( $form['nombre_alumno'] ) ? trim( $form['nombre_alumno'] ) : '';

			if ( '' === $name ) {
				continue;
			}

			$school = isset( $form['colegio'] ) ? trim( $form['colegio'] ) : '';
			$student_key = md5( strtolower( $name ) . '|' . strtolower( $school ) );

			if ( ! isset( $students[ $student_key ] ) ) {
				$students[ $student_key ] = [
					'key'        => $student_key,
					'name'       => $name,
					'form'       => $form,
					'cart_state' => [],
				];
			}

			$product_id = (string) $item['product_id'];
			$slot = [
				'_default'              => $cart_key,
				'_default_qty'          => isset( $item['quantity'] ) ? absint( $item['quantity'] ) : 1,
				'_default_variation_id' => isset( $item['variation_id'] ) ? absint( $item['variation_id'] ) : 0,
			];
			if ( ! empty( $item['pllc_day'] ) ) {
				$day = sanitize_key( $item['pllc_day'] );
				$students[ $student_key ]['cart_state'][ $product_id ]['_days'][ $day ] = $slot;
			} else {
				$students[ $student_key ]['cart_state'][ $product_id ] = $slot;
			}
		}

		return array_values( $students );
	}
}
