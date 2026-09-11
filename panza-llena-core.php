<?php
/**
 * Plugin Name:       Panza Llena Core
 * Description:       Lógica de pedidos, accesos institucionales y entregas de Panza Llena sobre WooCommerce.
 * Version:            0.23.67
 * Requires at least:  6.9
 * Requires PHP:        7.4
 * Author:              Esteban
 * Text Domain:        panza-llena-core
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'PLLC_VERSION', '0.23.67' );
define( 'PLLC_PATH', plugin_dir_path( __FILE__ ) );
define( 'PLLC_URL', plugin_dir_url( __FILE__ ) );

require_once PLLC_PATH . 'includes/class-pllc-cache-control.php';
PLLC_Cache_Control::bootstrap();

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
			echo '<div class="notice notice-warning"><p><strong>Panza Llena Core:</strong> Elementor no está activo. Las funciones de pedidos siguen disponibles, pero las páginas diseñadas con Elementor pueden verse incompletas.</p></div>';
		} );
	}

	return true;
}

function pllc_module_manifest() {
	return [
		'includes/class-pllc-roles.php'                 => 'PLLC_Roles',
		'includes/class-pllc-code-access.php'           => 'PLLC_Code_Access',
		'includes/class-pllc-access.php'                => 'PLLC_Access',
		'includes/class-pllc-order-rules.php'           => null,
		'includes/class-pllc-item-order.php'            => null,
		'includes/trait-pllc-order-presentation.php'    => null,
		'includes/class-pllc-frontend-assets.php'       => 'PLLC_Frontend_Assets',
		'includes/class-pllc-order-received-styles.php' => 'PLLC_Order_Received_Styles',
		'includes/class-pllc-styles.php'                => 'PLLC_Styles',
		'includes/class-pllc-tour.php'                  => 'PLLC_Tour',
		'includes/class-pllc-shortcodes.php'            => 'PLLC_Shortcodes',
		'includes/class-pllc-cart.php'                  => 'PLLC_Cart',
		'includes/class-pllc-cart-groups.php'           => 'PLLC_Cart_Groups',
		'includes/class-pllc-order-details.php'         => 'PLLC_Order_Details',
		'includes/class-pllc-emails.php'                => 'PLLC_Emails',
		'includes/class-pllc-checkout.php'              => 'PLLC_Checkout',
		'includes/class-pllc-deliveries.php'            => 'PLLC_Deliveries',
	];
}

function pllc_load_modules() {
	if ( ! pllc_check_dependencies() ) {
		return;
	}

	foreach ( pllc_module_manifest() as $file => $class_name ) {
		require_once PLLC_PATH . $file;
	}

	foreach ( pllc_module_manifest() as $class_name ) {
		if ( $class_name && is_callable( [ $class_name, 'init' ] ) ) {
			call_user_func( [ $class_name, 'init' ] );
		}
	}
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
