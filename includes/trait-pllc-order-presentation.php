<?php
/** Datos de presentación compartidos por administración, vistas del pedido y correos. */
defined( 'ABSPATH' ) || exit;

trait PLLC_Order_Presentation {

	private static function get_item_form_type( $item ) {
		$type = sanitize_key( (string) $item->get_meta( '_pllc_form_type', true ) );
		if ( in_array( $type, [ 'colegios', 'iteo_personal', 'iteo_pacientes', 'particular' ], true )
			&& ( 'particular' !== $type || $item->meta_exists( '_pllc_form_type' ) ) ) {
			return $type;
		}

		$slugs = wc_get_product_terms( $item->get_product_id(), 'product_cat', [ 'fields' => 'slugs' ] );
		if ( ! is_wp_error( $slugs ) ) {
			foreach ( $slugs as $slug ) {
				if ( false !== strpos( $slug, 'iteo-personal' ) ) {
					return 'iteo_personal';
				}
				if ( false !== strpos( $slug, 'iteo-pacientes' ) ) {
					return 'iteo_pacientes';
				}
				if ( false !== strpos( $slug, 'colegios' ) ) {
					return 'colegios';
				}
			}
		}

		return 'particular';
	}

	private static function get_item_form( $item ) {
		$form = $item->get_meta( '_pllc_form', true );
		return is_array( $form ) ? $form : [];
	}

	private static function get_group_key( $form_type, $form ) {
		return PLLC_Item_Order::group_key( $form_type, $form );
	}

	private static function build_group_label( $form_type, $form ) {
		if ( ! empty( $form['nombre_alumno'] ) ) {
			return sprintf( __( 'Pedido para %s', 'panza-llena-core' ), $form['nombre_alumno'] );
		}
		$labels = [
			'iteo_personal'  => __( 'Pedido para ITEO Personal', 'panza-llena-core' ),
			'iteo_pacientes' => __( 'Pedido para ITEO Pacientes', 'panza-llena-core' ),
			'particular'     => __( 'Pedido particular', 'panza-llena-core' ),
		];
		return isset( $labels[ $form_type ] ) ? $labels[ $form_type ] : __( 'Pedido', 'panza-llena-core' );
	}

	private static function build_group_context( $form_type, $form ) {
		if ( 'iteo_personal' === $form_type ) {
			return __( 'Entrega en ITEO Personal · No facturado en WooCommerce', 'panza-llena-core' );
		}
		if ( 'iteo_pacientes' === $form_type ) {
			return __( 'Entrega en ITEO Pacientes · No facturado en WooCommerce', 'panza-llena-core' );
		}
		if ( 'particular' === $form_type ) {
			return __( 'Entrega a domicilio · Importe incluido en el total', 'panza-llena-core' );
		}
		if ( 'colegios' === $form_type ) {
			$destination = ! empty( $form['colegio'] ) ? $form['colegio'] : __( 'el colegio', 'panza-llena-core' );
			return sprintf( __( 'Entrega en %s · Importe incluido en el total', 'panza-llena-core' ), $destination );
		}
		return '';
	}

	private static function summary_labels() {
		return [
			'colegio'       => __( 'Colegio', 'panza-llena-core' ),
			'nivel'         => __( 'Nivel', 'panza-llena-core' ),
			'curso'         => __( 'Curso', 'panza-llena-core' ),
			'cubiertos'     => __( 'Cubiertos descartables', 'panza-llena-core' ),
		];
	}

	private static function get_kitchen_observations( $form ) {
		return ! empty( $form['observaciones'] ) ? sanitize_textarea_field( $form['observaciones'] ) : '';
	}

	private static function get_item_day_label( $item ) {
		$day = sanitize_key( (string) $item->get_meta( '_pllc_delivery_day', true ) );
		if ( class_exists( 'PLLC_Order_Rules' ) ) {
			$formatted = PLLC_Order_Rules::format_delivery_label(
				$day,
				$item->get_meta( '_pllc_delivery_date', true )
			);
			if ( $formatted ) {
				return $formatted;
			}
		}
		$labels = [
			'lunes' => 'Lunes', 'martes' => 'Martes', 'miercoles' => 'Miércoles',
			'jueves' => 'Jueves', 'viernes' => 'Viernes', 'sabado' => 'Sábado', 'domingo' => 'Domingo',
		];
		return isset( $labels[ $day ] ) ? $labels[ $day ] : '';
	}

	private static function get_item_meal_label( $item ) {
		$meals = $item->get_meta( '_pllc_meals', true );
		$meals = is_array( $meals ) ? $meals : [ $meals ];
		$labels = [];
		foreach ( $meals as $meal ) {
			$meal = sanitize_key( (string) $meal );
			if ( in_array( $meal, [ 'almuerzo', 'cena' ], true ) ) {
				$labels[] = ucfirst( $meal );
			}
		}
		return implode( ' / ', array_unique( $labels ) );
	}

	private static function get_order_form_types( $order ) {
		if ( ! is_a( $order, 'WC_Order' ) ) {
			return [];
		}

		$types = [];
		foreach ( $order->get_items( 'line_item' ) as $item ) {
			$type = self::get_item_form_type( $item );
			if ( ! in_array( $type, $types, true ) ) {
				$types[] = $type;
			}
		}

		$priority = [
			'colegios'       => 1,
			'iteo_personal'  => 2,
			'iteo_pacientes' => 3,
			'particular'     => 99,
		];
		usort( $types, function ( $a, $b ) use ( $priority ) {
			return ( isset( $priority[ $a ] ) ? $priority[ $a ] : 50 ) <=> ( isset( $priority[ $b ] ) ? $priority[ $b ] : 50 );
		} );

		return $types;
	}

	private static function is_mixed_order( $order ) {
		$types = self::get_order_form_types( $order );
		return in_array( 'particular', $types, true ) && count( $types ) > 1;
	}

	private static function is_iteo_mixed_order( $order ) {
		$types = self::get_order_form_types( $order );
		return in_array( 'particular', $types, true )
			&& ( in_array( 'iteo_personal', $types, true ) || in_array( 'iteo_pacientes', $types, true ) );
	}

	private static function build_order_type_label( $order ) {
		$labels = [
			'colegios'       => __( 'Colegios', 'panza-llena-core' ),
			'iteo_personal'  => __( 'ITEO Personal', 'panza-llena-core' ),
			'iteo_pacientes' => __( 'ITEO Pacientes', 'panza-llena-core' ),
			'particular'     => __( 'Particular', 'panza-llena-core' ),
		];
		$parts = [];
		foreach ( self::get_order_form_types( $order ) as $type ) {
			if ( isset( $labels[ $type ] ) ) {
				$parts[] = $labels[ $type ];
			}
		}
		return $parts ? implode( ' + ', $parts ) : __( 'Particular', 'panza-llena-core' );
	}

	private static function build_mixed_title( $order ) {
		return sprintf( __( 'Pedido mixto: %s', 'panza-llena-core' ), self::build_order_type_label( $order ) );
	}

	private static function build_delivery_explanation( $order ) {
		$types    = self::get_order_form_types( $order );
		$has_iteo = in_array( 'iteo_personal', $types, true ) || in_array( 'iteo_pacientes', $types, true );

		if ( $has_iteo ) {
			return __( 'La entrega institucional se realiza en ITEO y la entrega a domicilio corresponde únicamente al “Pedido particular”. Los importes del pedido contemplan solamente los productos Particulares.', 'panza-llena-core' );
		}

		return __( 'La entrega escolar se realiza en el colegio y la entrega a domicilio corresponde únicamente al “Pedido particular”. La dirección de envío no se aplica a los productos escolares.', 'panza-llena-core' );
	}

	private static function is_iteo_only_order( $order ) {
		$has_iteo = false;
		foreach ( $order->get_items( 'line_item' ) as $item ) {
			$type = self::get_item_form_type( $item );
			if ( ! in_array( $type, [ 'iteo_personal', 'iteo_pacientes' ], true ) ) {
				return false;
			}
			$has_iteo = true;
		}
		return $has_iteo;
	}
}
