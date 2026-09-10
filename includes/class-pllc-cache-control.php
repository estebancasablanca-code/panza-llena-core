<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Evita que una caché pública reutilice páginas que dependen del acceso,
 * del carrito o de la ventana horaria de pedidos.
 */
class PLLC_Cache_Control {
	const PRIVATE_COOKIES = [
		'pllc_access',
		'woocommerce_items_in_cart',
		'woocommerce_cart_hash',
	];

	const PRIVATE_COOKIE_PREFIXES = [
		'wp_woocommerce_session_',
		'wordpress_logged_in_',
	];

	const DYNAMIC_PATHS = [
		'colegios',
		'iteo-personal',
		'iteo-pacientes',
		'particulares',
		'tienda',
		'producto',
		'product',
		'carrito',
		'cart',
		'finalizar-compra',
		'checkout',
		'mi-cuenta',
		'my-account',
		'wp-json/pllc',
	];

	/** Registra la protección lo antes posible durante la carga del plugin. */
	public static function bootstrap() {
		if ( self::should_bypass_cache() ) {
			self::declare_no_page_cache();
		}

		add_filter( 'wp_headers', [ __CLASS__, 'filter_current_headers' ], PHP_INT_MAX );
		add_action( 'send_headers', [ __CLASS__, 'send_current_headers' ], PHP_INT_MAX );
	}

	/**
	 * Decisión temprana, independiente de las funciones condicionales de WP.
	 * Es pública para poder verificarla sin levantar WordPress completo.
	 */
	public static function should_bypass_cache( $request_uri = null, $cookies = null ) {
		$request_uri = null === $request_uri ? (string) ( $_SERVER['REQUEST_URI'] ?? '' ) : (string) $request_uri;
		$cookies     = null === $cookies ? $_COOKIE : (array) $cookies;

		foreach ( self::PRIVATE_COOKIES as $cookie ) {
			if ( array_key_exists( $cookie, $cookies ) ) {
				return true;
			}
		}

		foreach ( array_keys( $cookies ) as $cookie ) {
			foreach ( self::PRIVATE_COOKIE_PREFIXES as $prefix ) {
				if ( 0 === strpos( (string) $cookie, $prefix ) ) {
					return true;
				}
			}
		}

		$path = wp_parse_url( $request_uri, PHP_URL_PATH );
		$path = trim( strtolower( rawurldecode( is_string( $path ) ? $path : '' ) ), '/' );

		foreach ( self::DYNAMIC_PATHS as $dynamic_path ) {
			if ( $path === $dynamic_path || 0 === strpos( $path, $dynamic_path . '/' ) ) {
				return true;
			}
		}

		return false;
	}

	/** Completa la detección cuando WordPress ya conoce el tipo de página. */
	public static function is_dynamic_request() {
		if ( self::should_bypass_cache() ) {
			return true;
		}

		$conditionals = [ 'is_front_page', 'is_shop', 'is_product', 'is_cart', 'is_checkout', 'is_account_page' ];
		foreach ( $conditionals as $conditional ) {
			if ( function_exists( $conditional ) && $conditional() ) {
				return true;
			}
		}

		return false;
	}

	public static function filter_current_headers( $headers ) {
		if ( ! self::is_dynamic_request() ) {
			return $headers;
		}

		self::declare_no_page_cache();
		return self::private_headers( $headers );
	}

	public static function send_current_headers() {
		if ( ! self::is_dynamic_request() ) {
			return;
		}

		self::declare_no_page_cache();
		if ( function_exists( 'nocache_headers' ) ) {
			nocache_headers();
		}

		if ( ! headers_sent() ) {
			header( 'Cache-Control: no-store, no-cache, must-revalidate, max-age=0, private', true );
			header( 'Pragma: no-cache', true );
			header( 'Expires: Wed, 11 Jan 1984 05:00:00 GMT', true );
		}
	}

	/** Devuelve el conjunto canónico de cabeceras privadas. */
	public static function private_headers( $headers = [] ) {
		$headers['Cache-Control'] = 'no-store, no-cache, must-revalidate, max-age=0, private';
		$headers['Pragma']        = 'no-cache';
		$headers['Expires']       = 'Wed, 11 Jan 1984 05:00:00 GMT';
		return $headers;
	}

	private static function declare_no_page_cache() {
		if ( ! defined( 'DONOTCACHEPAGE' ) ) {
			define( 'DONOTCACHEPAGE', true );
		}
	}
}
