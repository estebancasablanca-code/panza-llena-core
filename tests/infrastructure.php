<?php
define( 'ABSPATH', __DIR__ );

function add_filter( $hook, $callback, $priority = 10 ) {
	$GLOBALS['pllc_filters'][ $hook ][] = [ $callback, $priority ];
}

function add_action( $hook, $callback, $priority = 10 ) {
	$GLOBALS['pllc_actions'][ $hook ][] = [ $callback, $priority ];
}

function wp_parse_url( $url, $component = -1 ) {
	return parse_url( $url, $component );
}

function plugin_dir_path( $file ) {
	return dirname( $file ) . '/';
}

function plugin_dir_url( $file ) {
	return 'https://example.test/wp-content/plugins/panza-llena-core/';
}

function did_action( $hook ) {
	return 0;
}

function register_activation_hook( $file, $callback ) {
	$GLOBALS['pllc_activation_hook'] = $callback;
}

class WooCommerce {}

function check_infrastructure( $condition, $message ) {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

require dirname( __DIR__ ) . '/includes/class-pllc-cache-control.php';

check_infrastructure( PLLC_Cache_Control::should_bypass_cache( '/colegios/', [] ), 'School ordering pages must bypass public cache.' );
check_infrastructure( PLLC_Cache_Control::should_bypass_cache( '/iteo-personal/', [] ), 'ITEO ordering pages must bypass public cache.' );
check_infrastructure( PLLC_Cache_Control::should_bypass_cache( '/tienda/producto-1/', [] ), 'The public shop must bypass public cache.' );
check_infrastructure( PLLC_Cache_Control::should_bypass_cache( '/producto/menu-del-dia/', [] ), 'Product pages must bypass public cache.' );
check_infrastructure( PLLC_Cache_Control::should_bypass_cache( '/finalizar-compra/order-received/12/', [] ), 'Checkout pages must bypass public cache.' );
check_infrastructure( PLLC_Cache_Control::should_bypass_cache( '/contacto/', [ 'pllc_access' => 'token' ] ), 'Institutional sessions must bypass cache on every page.' );
check_infrastructure( PLLC_Cache_Control::should_bypass_cache( '/contacto/', [ 'wp_woocommerce_session_test' => 'token' ] ), 'WooCommerce sessions must bypass cache on every page.' );
check_infrastructure( ! PLLC_Cache_Control::should_bypass_cache( '/contacto/', [] ), 'Unrelated anonymous pages may remain cacheable.' );
check_infrastructure( ! PLLC_Cache_Control::should_bypass_cache( '/wp-content/uploads/producto.jpg', [] ), 'Static assets must not be classified as product pages.' );

$headers = PLLC_Cache_Control::private_headers( [ 'Content-Type' => 'text/html' ] );
check_infrastructure( false !== strpos( $headers['Cache-Control'], 'no-store' ), 'Private responses must use no-store.' );
check_infrastructure( false !== strpos( $headers['Cache-Control'], 'private' ), 'Private responses must not be shared.' );
check_infrastructure( 'text/html' === $headers['Content-Type'], 'Existing response headers must be preserved.' );

$_SERVER['REQUEST_URI'] = '/colegios/';
$_COOKIE                 = [];
PLLC_Cache_Control::bootstrap();
check_infrastructure( defined( 'DONOTCACHEPAGE' ) && DONOTCACHEPAGE, 'Cache plugins must receive the standard no-cache constant.' );
check_infrastructure( ! empty( $GLOBALS['pllc_filters']['wp_headers'] ), 'The response header filter must be registered.' );
check_infrastructure( ! empty( $GLOBALS['pllc_actions']['send_headers'] ), 'The response header action must be registered.' );

$main_source = file_get_contents( dirname( __DIR__ ) . '/panza-llena-core.php' );
check_infrastructure( false === strpos( $main_source, 'requiere que Elementor Pro esté activo' ), 'Elementor must not remain a hard dependency.' );
check_infrastructure( false !== strpos( $main_source, 'notice notice-warning' ), 'Missing Elementor must produce a non-blocking warning.' );
check_infrastructure( false !== strpos( $main_source, 'pllc_module_manifest' ), 'The module bootstrap must use one auditable manifest.' );
check_infrastructure( false !== strpos( $main_source, 'PLLC_Cache_Control::bootstrap();' ), 'Cache protection must start before normal modules.' );

require dirname( __DIR__ ) . '/panza-llena-core.php';
check_infrastructure( pllc_check_dependencies(), 'The core must load with WooCommerce even when Elementor is absent.' );
check_infrastructure( ! empty( $GLOBALS['pllc_actions']['admin_notices'] ), 'Missing Elementor must remain visible as an administrator warning.' );
foreach ( pllc_module_manifest() as $file => $class_name ) {
	check_infrastructure( is_file( dirname( __DIR__ ) . '/' . $file ), 'Every declared module must exist: ' . $file );
}
check_infrastructure( in_array( 'PLLC_Emails', pllc_module_manifest(), true ), 'The email renderer must be registered.' );

echo "Infrastructure checks passed.\n";
