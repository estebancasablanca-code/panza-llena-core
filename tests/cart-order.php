<?php
define( 'ABSPATH', dirname( __DIR__ ) );

function sanitize_key( $value ) {
	return strtolower( preg_replace( '/[^a-z0-9_-]/', '', remove_accents_for_test( $value ) ) );
}

function remove_accents_for_test( $value ) {
	return strtr( $value, [ 'á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u' ] );
}

function wc_get_product_terms() { return []; }
function is_wp_error() { return false; }
function is_checkout() { return false; }
function is_cart() { return false; }
function esc_html( $value ) { return htmlspecialchars( $value, ENT_QUOTES, 'UTF-8' ); }
function __( $value ) { return $value; }

class PLLC_Test_Cart {
	public $cart_contents;

	public function __construct( $contents ) {
		$this->cart_contents = $contents;
	}

	public function get_cart() {
		return $this->cart_contents;
	}
}

function WC() {
	return $GLOBALS['pllc_test_wc'];
}

function cart_item_for_test( $type, $day, $person = '', $meal = '' ) {
	$item = [
		'product_id'    => 1,
		'pllc_form_type' => $type,
		'pllc_day'       => $day,
		'pllc_form'      => $person ? [ 'nombre_alumno' => $person, 'colegio' => 'Colegio' ] : [],
	];

	if ( $meal ) {
		$item['pllc_meals'] = [ $meal ];
	}

	return $item;
}

function check_cart_order( $condition, $message ) {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

function sorted_keys_for_test( $contents ) {
	$cart = new PLLC_Test_Cart( $contents );
	PLLC_Cart_Groups::sort_cart_items( $cart );
	return array_keys( $cart->cart_contents );
}

require dirname( __DIR__ ) . '/includes/class-pllc-item-order.php';
require dirname( __DIR__ ) . '/includes/class-pllc-cart-groups.php';

check_cart_order(
	sorted_keys_for_test( [
		'particular-sabado' => cart_item_for_test( 'particular', 'sabado' ),
		'particular-lunes'  => cart_item_for_test( 'particular', 'lunes' ),
	] ) === [ 'particular-lunes', 'particular-sabado' ],
	'Particular must be ordered Monday through Saturday.'
);

check_cart_order(
	sorted_keys_for_test( [
		'particular-jueves' => cart_item_for_test( 'particular', 'jueves' ),
		'test-viernes'      => cart_item_for_test( 'colegios', 'viernes', 'Test' ),
		'test-miercoles'    => cart_item_for_test( 'colegios', 'miercoles', 'Test' ),
		'ana-martes'        => cart_item_for_test( 'colegios', 'martes', 'Ana' ),
	] ) === [ 'test-miercoles', 'test-viernes', 'ana-martes', 'particular-jueves' ],
	'Colegios must keep each student together, sort their days and leave Particular last.'
);

check_cart_order(
	sorted_keys_for_test( [
		'personal-cena'      => cart_item_for_test( 'iteo_personal', 'miercoles', '', 'cena' ),
		'particular-martes'  => cart_item_for_test( 'particular', 'martes' ),
		'personal-jueves'    => cart_item_for_test( 'iteo_personal', 'jueves', '', 'almuerzo' ),
		'personal-almuerzo'  => cart_item_for_test( 'iteo_personal', 'miercoles', '', 'almuerzo' ),
	] ) === [ 'personal-almuerzo', 'personal-cena', 'personal-jueves', 'particular-martes' ],
	'ITEO Personal must sort by day and meal, before Particular in a mixed cart.'
);

check_cart_order(
	sorted_keys_for_test( [
		'particular-viernes' => cart_item_for_test( 'particular', 'viernes' ),
		'pacientes-sabado'   => cart_item_for_test( 'iteo_pacientes', 'sabado' ),
		'pacientes-jueves'   => cart_item_for_test( 'iteo_pacientes', 'jueves' ),
	] ) === [ 'pacientes-jueves', 'pacientes-sabado', 'particular-viernes' ],
	'ITEO Pacientes must sort by day and leave Particular last in a mixed cart.'
);

$mini_cart = new PLLC_Test_Cart( [
	'particular' => cart_item_for_test( 'particular', 'jueves' ),
	'colegio'    => cart_item_for_test( 'colegios', 'miercoles', 'Test' ),
] );
$GLOBALS['pllc_test_wc'] = (object) [ 'cart' => $mini_cart ];
PLLC_Cart_Groups::prepare_mini_cart();
check_cart_order(
	array_keys( $mini_cart->cart_contents ) === [ 'colegio', 'particular' ],
	'The side cart must apply the same canonical order immediately before rendering.'
);

echo "Cart ordering checks passed.\n";
