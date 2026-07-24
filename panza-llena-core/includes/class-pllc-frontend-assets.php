<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Encola el JS propio del frontend, solo en las 4 páginas de producto por rol.
 * Este mismo archivo (pllc-frontend.js) es donde va a vivir toda la lógica
 * del punto 4 (selección, exclusión por día, Agregar al pedido) a medida
 * que la vayamos sumando — no hace falta un script nuevo por cada cosa.
 */
class PLLC_Frontend_Assets {

	const PAGE_SLUGS = [ 'colegios', 'iteo-personal', 'iteo-pacientes', 'particulares' ];

	public static function init() {
		add_action( 'wp_enqueue_scripts', [ __CLASS__, 'enqueue' ] );
	}

	public static function enqueue() {
		$is_role_page = is_page( self::PAGE_SLUGS );

		if ( ! $is_role_page && ! is_cart() && ! is_checkout() ) {
			return;
		}

		wp_enqueue_script(
			'pllc-frontend',
			PLLC_URL . 'assets/js/pllc-frontend.js',
			[],
			PLLC_VERSION,
			true
		);

		wp_localize_script( 'pllc-frontend', 'PLLC_Data', [
			'ajax_url' => admin_url( 'admin-ajax.php' ),
			'nonce'    => wp_create_nonce( 'pllc_add_order' ),
		] );

		wp_enqueue_style(
			'pllc-frontend',
			PLLC_URL . 'assets/css/pllc-frontend.css',
			[],
			PLLC_VERSION
		);
	}
}
