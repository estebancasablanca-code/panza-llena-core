<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Adapta los datos de contacto del checkout al tipo de pedido y normaliza
 * teléfonos argentinos al formato internacional que utiliza WhatsApp.
 */
class PLLC_Checkout {

	public static function init() {
		add_filter( 'woocommerce_checkout_fields', [ __CLASS__, 'filter_checkout_fields' ], 9999 );
		add_filter( 'woocommerce_form_field_args', [ __CLASS__, 'enforce_rendered_labels' ], 9999, 3 );
		add_filter( 'woocommerce_checkout_posted_data', [ __CLASS__, 'normalize_posted_whatsapp' ], 20 );
		add_action( 'woocommerce_after_checkout_validation', [ __CLASS__, 'validate_whatsapp' ], 20, 2 );
		add_filter( 'woocommerce_admin_billing_fields', [ __CLASS__, 'rename_admin_phone' ] );
		add_filter( 'woocommerce_email_customer_details_fields', [ __CLASS__, 'rename_email_phone' ], 20, 3 );
	}

	public static function filter_checkout_fields( $fields ) {
		if ( empty( $fields['billing'] ) ) {
			return $fields;
		}

		$institutional = self::is_institutional_only_cart();
		$responsible   = self::cart_has_form_type( 'colegios' ) || self::cart_has_form_type( 'iteo_pacientes' );

		if ( $institutional ) {
			$allowed = [ 'billing_first_name', 'billing_phone', 'billing_email' ];
			foreach ( array_keys( $fields['billing'] ) as $key ) {
				if ( ! in_array( $key, $allowed, true ) ) {
					unset( $fields['billing'][ $key ] );
				}
			}

			$fields['billing']['billing_first_name']['label']       = $responsible ? 'Nombre y apellido del responsable' : 'Nombre y apellido';
			$fields['billing']['billing_first_name']['class']       = [ 'form-row-wide' ];
			$fields['billing']['billing_first_name']['required']    = true;
			$fields['billing']['billing_first_name']['priority']    = 10;
			$fields['billing']['billing_first_name']['autocomplete'] = 'name';
			$fields['billing']['billing_first_name']['placeholder'] = '';

		}

		if ( isset( $fields['billing']['billing_phone'] ) ) {
			$fields['billing']['billing_phone']['label']       = 'WhatsApp';
			$fields['billing']['billing_phone']['required']    = true;
			$fields['billing']['billing_phone']['class']       = [ 'form-row-wide' ];
			$fields['billing']['billing_phone']['priority']    = $institutional ? 20 : ( isset( $fields['billing']['billing_phone']['priority'] ) ? $fields['billing']['billing_phone']['priority'] : 100 );
			$fields['billing']['billing_phone']['placeholder'] = 'Ej.: 343 4567890';
			$fields['billing']['billing_phone']['description'] = '';
			$fields['billing']['billing_phone']['type']         = 'tel';
			$fields['billing']['billing_phone']['custom_attributes']['inputmode'] = 'tel';
			$fields['billing']['billing_phone']['autocomplete'] = 'tel';
		}

		if ( $institutional && isset( $fields['billing']['billing_email'] ) ) {
			$fields['billing']['billing_email']['class']    = [ 'form-row-wide' ];
			$fields['billing']['billing_email']['required'] = true;
			$fields['billing']['billing_email']['priority'] = 30;
		}

		return $fields;
	}

	/**
	 * Elementor puede volver a procesar los campos después del filtro general
	 * de WooCommerce. Esta segunda capa asegura el rótulo en el HTML final.
	 */
	public static function enforce_rendered_labels( $args, $key, $value ) {
		if ( ! is_checkout() ) {
			return $args;
		}

		if ( 'billing_phone' === $key ) {
			$args['label'] = 'WhatsApp';
		}

		if ( 'billing_first_name' === $key && self::is_institutional_only_cart() ) {
			$args['label'] = ( self::cart_has_form_type( 'colegios' ) || self::cart_has_form_type( 'iteo_pacientes' ) )
				? 'Nombre y apellido del responsable'
				: 'Nombre y apellido';
		}

		return $args;
	}

	public static function normalize_posted_whatsapp( $data ) {
		if ( ! empty( $data['billing_phone'] ) ) {
			$normalized = self::normalize_argentine_mobile( $data['billing_phone'] );
			if ( $normalized ) {
				$data['billing_phone'] = $normalized;
			}
		}
		return $data;
	}

	public static function validate_whatsapp( $data, $errors ) {
		$phone = isset( $data['billing_phone'] ) ? $data['billing_phone'] : '';
		if ( ! self::normalize_argentine_mobile( $phone ) ) {
			$errors->add(
				'pllc_invalid_whatsapp',
				'Ingresá un WhatsApp argentino válido con código de área. Ejemplo: 343 4567890.'
			);
		}
	}

	/**
	 * Acepta, entre otros: 3434567890, 03434567890, 0343154567890 y
	 * +5493434567890. Devuelve siempre +549 seguido de diez dígitos.
	 */
	private static function normalize_argentine_mobile( $phone ) {
		$digits = preg_replace( '/\D+/', '', (string) $phone );
		if ( 0 === strpos( $digits, '00' ) ) {
			$digits = substr( $digits, 2 );
		}
		if ( 0 === strpos( $digits, '549' ) ) {
			$digits = substr( $digits, 3 );
		} elseif ( 0 === strpos( $digits, '54' ) ) {
			$digits = substr( $digits, 2 );
			if ( 11 === strlen( $digits ) && '9' === $digits[0] ) {
				$digits = substr( $digits, 1 );
			}
		}
		if ( 0 === strpos( $digits, '0' ) ) {
			$digits = substr( $digits, 1 );
		}

		// Formato nacional antiguo: 0 + área + 15 + número local.
		if ( 12 === strlen( $digits ) ) {
			foreach ( [ 2, 3, 4 ] as $area_length ) {
				if ( '15' === substr( $digits, $area_length, 2 ) ) {
					$digits = substr( $digits, 0, $area_length ) . substr( $digits, $area_length + 2 );
					break;
				}
			}
		}

		return preg_match( '/^[1-9][0-9]{9}$/', $digits ) ? '+549' . $digits : '';
	}

	public static function rename_admin_phone( $fields ) {
		if ( isset( $fields['phone'] ) ) {
			$fields['phone']['label'] = 'WhatsApp';
		}
		return $fields;
	}

	public static function rename_email_phone( $fields, $sent_to_admin, $order ) {
		if ( isset( $fields['billing_phone'] ) ) {
			$fields['billing_phone']['label'] = 'WhatsApp';
		}
		return $fields;
	}

	private static function is_institutional_only_cart() {
		$has_institutional = self::cart_has_form_type( 'colegios' ) || self::cart_has_form_type( 'iteo_personal' ) || self::cart_has_form_type( 'iteo_pacientes' );
		return $has_institutional && ! self::cart_has_form_type( 'particular' );
	}

	private static function cart_has_form_type( $type ) {
		if ( ! function_exists( 'WC' ) || ! WC()->cart ) {
			return false;
		}
		foreach ( WC()->cart->get_cart() as $item ) {
			$item_type = ! empty( $item['pllc_form_type'] ) ? $item['pllc_form_type'] : 'particular';
			if ( $type === $item_type ) {
				return true;
			}
		}
		return false;
	}
}
