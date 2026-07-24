<?php
/**
 * Plugin Name:       Panza Llena Core
 * Description:       Lógica custom del sitio Panza Llena: roles de cliente, restricción de páginas, y todo lo que Elementor Pro / WooCommerce / plugins gratuitos no resuelven de fábrica.
 * Version:            0.10.1
 * Requires at least:  6.9
 * Requires PHP:        7.4
 * Author:              Esteban
 * Text Domain:        panza-llena-core
 */

// Evita el acceso directo al archivo.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'PLLC_VERSION', '0.10.1' );
define( 'PLLC_PATH', plugin_dir_path( __FILE__ ) );
define( 'PLLC_URL', plugin_dir_url( __FILE__ ) );

/**
 * Chequeo de dependencias: si WooCommerce no está activo, avisamos y no
 * cargamos nada más (evita errores fatales en un sitio mal configurado).
 */
function pllc_check_dependencies() {
	if ( ! class_exists( 'WooCommerce' ) ) {
		add_action( 'admin_notices', function () {
			echo '<div class="notice notice-error"><p><strong>Panza Llena Core</strong> requiere que WooCommerce esté activo.</p></div>';
		} );
		return false;
	}

	if ( ! did_action( 'elementor/loaded' ) ) {
		add_action( 'admin_notices', function () {
			echo '<div class="notice notice-error"><p><strong>Panza Llena Core</strong> requiere que Elementor Pro esté activo.</p></div>';
		} );
		return false;
	}

	return true;
}

/**
 * Carga de módulos. Cada punto de la arquitectura vive en su propio archivo
 * dentro de /includes, así el plugin crece sin volverse un solo archivo gigante.
 */
function pllc_load_modules() {
	if ( ! pllc_check_dependencies() ) {
		return;
	}

	require_once PLLC_PATH . 'includes/class-pllc-roles.php';
	require_once PLLC_PATH . 'includes/class-pllc-access.php';
	require_once PLLC_PATH . 'includes/class-pllc-frontend-assets.php';
	require_once PLLC_PATH . 'includes/class-pllc-shortcodes.php';
	require_once PLLC_PATH . 'includes/class-pllc-cart.php';
	require_once PLLC_PATH . 'includes/class-pllc-cart-groups.php';

	// Próximos módulos (se van sumando a medida que avanzamos):
	// require_once PLLC_PATH . 'includes/class-pllc-checkout.php';
	// require_once PLLC_PATH . 'includes/class-pllc-schedule.php';

	PLLC_Roles::init();
	PLLC_Access::init();
	PLLC_Frontend_Assets::init();
	PLLC_Shortcodes::init();
	PLLC_Cart::init();
	PLLC_Cart_Groups::init();
}
add_action( 'plugins_loaded', 'pllc_load_modules' );

/**
 * Alta de roles al activar el plugin.
 */
function pllc_activate() {
	require_once PLLC_PATH . 'includes/class-pllc-roles.php';
	PLLC_Roles::register_roles();
}
register_activation_hook( __FILE__, 'pllc_activate' );
