<?php
/**
 * Plugin Name:       Panza Llena Core
 * Description:       Lógica custom del sitio Panza Llena: roles de cliente, restricción de páginas, y todo lo que Elementor Pro / WooCommerce / plugins gratuitos no resuelven de fábrica.
 * Version:            0.23.53
 * Requires at least:  6.9
 * Requires PHP:        7.4
 * Author:              Esteban
 * Text Domain:        panza-llena-core
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'PLLC_VERSION', '0.23.53' );
define( 'PLLC_PATH', plugin_dir_path( __FILE__ ) );
define( 'PLLC_URL', plugin_dir_url( __FILE__ ) );

add_action( 'before_woocommerce_init', function () {
	if ( class_exists( '\\Automattic\\WooCommerce\\Utilities\\FeaturesUtil' ) ) {
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
			'custom_order_tables',
			__FILE__,
			true
		);
	}
} );

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

function pllc_load_modules() {
	if ( ! pllc_check_dependencies() ) {
		return;
	}

	require_once PLLC_PATH . 'includes/class-pllc-roles.php';
	require_once PLLC_PATH . 'includes/class-pllc-code-access.php';
	require_once PLLC_PATH . 'includes/class-pllc-access.php';
	require_once PLLC_PATH . 'includes/class-pllc-order-rules.php';
	require_once PLLC_PATH . 'includes/class-pllc-frontend-assets.php';
	require_once PLLC_PATH . 'includes/class-pllc-order-received-styles.php';
	require_once PLLC_PATH . 'includes/class-pllc-styles.php';
	require_once PLLC_PATH . 'includes/class-pllc-tour.php';
	require_once PLLC_PATH . 'includes/class-pllc-shortcodes.php';
	require_once PLLC_PATH . 'includes/class-pllc-cart.php';
	require_once PLLC_PATH . 'includes/class-pllc-cart-groups.php';
	require_once PLLC_PATH . 'includes/class-pllc-order-details.php';
	require_once PLLC_PATH . 'includes/class-pllc-checkout.php';
	require_once PLLC_PATH . 'includes/class-pllc-deliveries.php';

	PLLC_Roles::init();
	PLLC_Code_Access::init();
	PLLC_Access::init();
	PLLC_Frontend_Assets::init();
	PLLC_Styles::init();
	PLLC_Order_Received_Styles::init();
	PLLC_Tour::init();
	PLLC_Shortcodes::init();
	PLLC_Cart::init();
	PLLC_Cart_Groups::init();
	PLLC_Order_Details::init();
	PLLC_Checkout::init();
	PLLC_Deliveries::init();
}
add_action( 'plugins_loaded', 'pllc_load_modules' );

function pllc_activate() {
	require_once PLLC_PATH . 'includes/class-pllc-roles.php';
	require_once PLLC_PATH . 'includes/class-pllc-code-access.php';
	require_once PLLC_PATH . 'includes/class-pllc-deliveries.php';
	PLLC_Roles::register_roles();
	PLLC_Code_Access::activate();
	PLLC_Deliveries::install();
}
register_activation_hook( __FILE__, 'pllc_activate' );
