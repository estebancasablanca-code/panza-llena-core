<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Reglas canónicas de catálogo, selección y fechas de entrega. */
class PLLC_Order_Rules {

	const FORM_TYPES = [ 'colegios', 'iteo_personal', 'iteo_pacientes', 'particular' ];

	const DAY_NUMBERS = [
		'lunes' => 1, 'martes' => 2, 'miercoles' => 3,
		'jueves' => 4, 'viernes' => 5, 'sabado' => 6,
	];

	const DAY_LABELS = [
		'lunes' => 'Lunes', 'martes' => 'Martes', 'miercoles' => 'Miércoles',
		'jueves' => 'Jueves', 'viernes' => 'Viernes', 'sabado' => 'Sábado',
	];

	const CATEGORY_PREFIXES = [
		'colegios'       => 'colegios-',
		'iteo_personal'  => 'iteo-personal-',
		'iteo_pacientes' => 'iteo-pacientes-',
		'particular'     => 'particulares-',
	];

	/**
	 * Devuelve las fechas del ciclo visible y si cada día todavía admite pedidos.
	 * El ciclo abre el sábado a las 22:00; cada día cierra la víspera a las 22:00.
	 */
	public static function get_schedule( $now = null ) {
		$now = self::normalize_datetime( $now );
		$weekday = (int) $now->format( 'N' );

		if ( 6 === $weekday && (int) $now->format( 'H') >= 22 ) {
			$monday = $now->modify( 'next monday' )->setTime( 0, 0 );
		} elseif ( 7 === $weekday ) {
			$monday = $now->modify( 'next monday' )->setTime( 0, 0 );
		} else {
			$monday = $now->modify( 'monday this week' )->setTime( 0, 0 );
		}

		$schedule = [];
		foreach ( self::DAY_NUMBERS as $slug => $number ) {
			$date   = $monday->modify( '+' . ( $number - 1 ) . ' days' );
			$cutoff = $date->modify( '-1 day' )->setTime( 22, 0 );
			$schedule[ $slug ] = [
				'date'      => $date->format( 'Y-m-d' ),
				'timestamp' => $date->getTimestamp(),
				'available' => $now < $cutoff,
			];
		}

		return $schedule;
	}

	public static function get_day_titles( $now = null ) {
		$titles   = [];
		$schedule = self::get_schedule( $now );
		$timezone = wp_timezone();
		foreach ( $schedule as $slug => $entry ) {
			$titles[ $slug ] = sprintf(
				'%s %s',
				self::DAY_LABELS[ $slug ],
				wp_date( 'j \\d\\e F', $entry['timestamp'], $timezone )
			);
		}
		return $titles;
	}

	public static function get_day_dates( $now = null ) {
		return array_map( function ( $entry ) {
			return $entry['date'];
		}, self::get_schedule( $now ) );
	}

	public static function get_day_availability( $now = null ) {
		return array_map( function ( $entry ) {
			return (bool) $entry['available'];
		}, self::get_schedule( $now ) );
	}

	public static function product_catalog_types( $product_id ) {
		$terms = wc_get_product_terms( absint( $product_id ), 'product_cat', [ 'fields' => 'slugs' ] );
		if ( is_wp_error( $terms ) ) {
			return [];
		}

		$types = [];
		foreach ( self::CATEGORY_PREFIXES as $type => $prefix ) {
			foreach ( self::DAY_NUMBERS as $day => $number ) {
				if ( in_array( $prefix . $day, $terms, true ) ) {
					$types[] = $type;
					break;
				}
			}
		}
		return array_values( array_unique( $types ) );
	}

	public static function infer_native_form_type( $product_id ) {
		$types = self::product_catalog_types( $product_id );
		return 1 === count( $types ) ? $types[0] : '';
	}

	/** Valida y devuelve exactamente los valores que luego deben persistirse. */
	public static function validate_selection( $selection, $form_type, $now = null ) {
		$form_type = sanitize_key( (string) $form_type );
		if ( ! in_array( $form_type, self::FORM_TYPES, true ) ) {
			return new WP_Error( 'pllc_invalid_form_type', 'El tipo de pedido no es válido.' );
		}

		$product_id   = isset( $selection['product_id'] ) ? absint( $selection['product_id'] ) : 0;
		$variation_id = isset( $selection['variation_id'] ) ? absint( $selection['variation_id'] ) : 0;
		$day          = isset( $selection['day'] ) ? sanitize_key( (string) $selection['day'] ) : '';
		$date         = isset( $selection['delivery_date'] ) ? sanitize_text_field( (string) $selection['delivery_date'] ) : '';
		$quantity     = isset( $selection['qty'] ) ? absint( $selection['qty'] ) : 1;
		$meals        = isset( $selection['meals'] ) && is_array( $selection['meals'] )
			? array_values( array_unique( array_map( 'sanitize_key', $selection['meals'] ) ) )
			: [];

		if ( ! $product_id || ! isset( self::DAY_NUMBERS[ $day ] ) ) {
			return new WP_Error( 'pllc_invalid_product_day', 'El producto o el día seleccionado no es válido.' );
		}

		$product = wc_get_product( $product_id );
		if ( ! $product || ! $product->is_purchasable() || ! $product->is_in_stock() ) {
			return new WP_Error( 'pllc_product_unavailable', 'Este producto no está disponible para comprar.' );
		}

		$terms  = wc_get_product_terms( $product_id, 'product_cat', [ 'fields' => 'slugs' ] );
		$prefix = self::CATEGORY_PREFIXES[ $form_type ];
		if ( is_wp_error( $terms ) || ! in_array( $prefix . $day, $terms, true ) ) {
			return new WP_Error( 'pllc_catalog_mismatch', 'El producto no pertenece al catálogo y día seleccionados.' );
		}

		$schedule = self::get_schedule( $now );
		if ( ! preg_match( '/^\\d{4}-\\d{2}-\\d{2}$/', $date ) || $schedule[ $day ]['date'] !== $date ) {
			return new WP_Error( 'pllc_delivery_date_mismatch', 'La fecha del menú cambió. Recargá la página y revisá el pedido.' );
		}
		if ( ! $schedule[ $day ]['available'] ) {
			return new WP_Error( 'pllc_delivery_date_expired', 'Ya cerró el horario de pedidos para esa fecha. Recargá la página y revisá el pedido.' );
		}

		if ( 'colegios' === $form_type ) {
			if ( ! $product->is_type( 'variable' ) || ! $variation_id ) {
				return new WP_Error( 'pllc_variation_required', 'Elegí un tamaño válido para el plato.' );
			}
			if ( 1 !== $quantity || $meals ) {
				return new WP_Error( 'pllc_invalid_school_selection', 'La cantidad o comida del pedido escolar no es válida.' );
			}
			$quantity = 1;
			$meals    = [];
		} elseif ( 'iteo_personal' === $form_type ) {
			if ( ! $product->is_type( 'simple' ) || $variation_id || 1 !== $quantity
				|| ! $meals || array_diff( $meals, [ 'almuerzo', 'cena' ] ) ) {
				return new WP_Error( 'pllc_invalid_meal', 'Elegí Almuerzo y/o Cena.' );
			}
			$quantity     = 1;
			$variation_id = 0;
		} elseif ( 'iteo_pacientes' === $form_type ) {
			if ( ! $product->is_type( 'simple' ) || $variation_id || $meals || $quantity < 1 ) {
				return new WP_Error( 'pllc_invalid_quantity', 'La cantidad debe ser mayor que cero.' );
			}
			$variation_id = 0;
			$meals        = [];
		} else {
			if ( ! $product->is_type( 'simple' ) || $variation_id || $meals || $quantity < 1 ) {
				return new WP_Error( 'pllc_invalid_particular_product', 'El producto Particular no es válido.' );
			}
			$variation_id = 0;
			$meals        = [];
		}

		if ( $variation_id ) {
			$variation = wc_get_product( $variation_id );
			if ( ! $variation || ! $variation->is_type( 'variation' )
				|| absint( $variation->get_parent_id() ) !== $product_id
				|| ! $variation->is_purchasable() || ! $variation->is_in_stock() ) {
				return new WP_Error( 'pllc_invalid_variation', 'El tamaño seleccionado no está disponible para este plato.' );
			}
		}

		return [
			'product_id'   => $product_id,
			'variation_id' => $variation_id,
			'qty'          => $quantity,
			'meals'        => $meals,
			'day'          => $day,
			'delivery_date' => $date,
		];
	}

	public static function validate_cart_item( $item, $now = null ) {
		$form_type = ! empty( $item['pllc_form_type'] ) ? sanitize_key( $item['pllc_form_type'] ) : '';
		return self::validate_selection( [
			'product_id'    => isset( $item['product_id'] ) ? $item['product_id'] : 0,
			'variation_id'  => isset( $item['variation_id'] ) ? $item['variation_id'] : 0,
			'qty'           => isset( $item['quantity'] ) ? $item['quantity'] : 1,
			'meals'         => isset( $item['pllc_meals'] ) ? $item['pllc_meals'] : [],
			'day'           => isset( $item['pllc_day'] ) ? $item['pllc_day'] : '',
			'delivery_date' => isset( $item['pllc_delivery_date'] ) ? $item['pllc_delivery_date'] : '',
		], $form_type, $now );
	}

	private static function normalize_datetime( $now ) {
		if ( null === $now ) {
			$now = apply_filters( 'pllc_current_datetime', current_datetime() );
		}
		if ( ! $now instanceof DateTimeInterface ) {
			$now = current_datetime();
		}
		return ( new DateTimeImmutable( '@' . $now->getTimestamp() ) )->setTimezone( wp_timezone() );
	}
}
