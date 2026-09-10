<?php
define( 'ABSPATH', __DIR__ );

function wp_timezone() { return new DateTimeZone( 'America/Argentina/Buenos_Aires' ); }
function current_datetime() { return new DateTimeImmutable( 'now', wp_timezone() ); }
function apply_filters( $name, $value ) { return $value; }
function wp_date( $format, $timestamp, $timezone ) { return ( new DateTimeImmutable( '@' . $timestamp ) )->setTimezone( $timezone )->format( $format ); }
function sanitize_key( $value ) { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $value ) ); }
function sanitize_text_field( $value ) { return trim( (string) $value ); }
function absint( $value ) { return abs( (int) $value ); }
function is_wp_error( $value ) { return $value instanceof WP_Error; }

class WP_Error {
	private $code;
	private $message;
	public function __construct( $code, $message ) { $this->code = $code; $this->message = $message; }
	public function get_error_code() { return $this->code; }
	public function get_error_message() { return $this->message; }
}

class PLLC_Test_Product {
	private $type;
	private $parent;
	public function __construct( $type, $parent = 0 ) { $this->type = $type; $this->parent = $parent; }
	public function is_type( $type ) { return $this->type === $type; }
	public function is_purchasable() { return true; }
	public function is_in_stock() { return true; }
	public function get_parent_id() { return $this->parent; }
}

$GLOBALS['pllc_test_products'] = [
	10 => new PLLC_Test_Product( 'simple' ),
	20 => new PLLC_Test_Product( 'simple' ),
	30 => new PLLC_Test_Product( 'variable' ),
	31 => new PLLC_Test_Product( 'variation', 30 ),
];
$GLOBALS['pllc_test_terms'] = [
	10 => [ 'iteo-personal-lunes' ],
	20 => [ 'iteo-pacientes-lunes' ],
	30 => [ 'colegios-lunes' ],
];
function wc_get_product( $id ) { return isset( $GLOBALS['pllc_test_products'][ $id ] ) ? $GLOBALS['pllc_test_products'][ $id ] : false; }
function wc_get_product_terms( $id, $taxonomy, $args ) { return isset( $GLOBALS['pllc_test_terms'][ $id ] ) ? $GLOBALS['pllc_test_terms'][ $id ] : []; }

require dirname( __DIR__ ) . '/includes/class-pllc-order-rules.php';

function check( $condition, $message ) {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}
function at_time( $value ) { return new DateTimeImmutable( $value, wp_timezone() ); }

$schedule = PLLC_Order_Rules::get_schedule( at_time( '2026-09-13 21:59:00' ) );
check( $schedule['lunes']['date'] === '2026-09-14' && $schedule['lunes']['available'], 'Sunday before 22:00 must keep Monday open.' );
check( PLLC_Order_Rules::format_delivery_label( 'lunes', '2026-09-14' ) === 'Lunes 14 de September', 'Delivery labels must include the exact stored date.' );
check( PLLC_Order_Rules::format_delivery_label( 'martes', '' ) === 'Martes', 'Orders without an exact date must keep the weekday fallback.' );

$schedule = PLLC_Order_Rules::get_schedule( at_time( '2026-09-09 21:00:00' ) );
check(
	array_keys( array_filter( array_column( $schedule, 'available', 'date' ) ) ) === [ '2026-09-10', '2026-09-11', '2026-09-12' ],
	'Wednesday before 22:00 must show Thursday, Friday and Saturday.'
);

$schedule = PLLC_Order_Rules::get_schedule( at_time( '2026-09-09 22:00:00' ) );
check(
	array_keys( array_filter( array_column( $schedule, 'available', 'date' ) ) ) === [ '2026-09-11', '2026-09-12' ],
	'Wednesday at 22:00 must show only Friday and Saturday.'
);

$schedule = PLLC_Order_Rules::get_schedule( at_time( '2026-09-13 22:00:00' ) );
check( ! $schedule['lunes']['available'] && $schedule['martes']['available'], 'Sunday at 22:00 must close Monday.' );

$schedule = PLLC_Order_Rules::get_schedule( at_time( '2026-09-18 21:59:00' ) );
check( $schedule['sabado']['available'], 'Friday before 22:00 must keep Saturday open.' );

$schedule = PLLC_Order_Rules::get_schedule( at_time( '2026-09-18 22:00:00' ) );
check( ! array_filter( array_column( $schedule, 'available' ) ), 'Friday at 22:00 must close the weekly window.' );

$schedule = PLLC_Order_Rules::get_schedule( at_time( '2026-09-19 21:59:00' ) );
check( ! array_filter( array_column( $schedule, 'available' ) ), 'Saturday before 22:00 must remain closed.' );

$schedule = PLLC_Order_Rules::get_schedule( at_time( '2026-09-19 22:00:00' ) );
check( $schedule['lunes']['date'] === '2026-09-21' && count( array_filter( array_column( $schedule, 'available' ) ) ) === 6, 'Saturday at 22:00 must open the next week.' );

$now = at_time( '2026-09-13 21:00:00' );
$valid = PLLC_Order_Rules::validate_selection( [
	'product_id' => 10, 'qty' => 1, 'day' => 'lunes', 'delivery_date' => '2026-09-14', 'meals' => [ 'almuerzo' ],
], 'iteo_personal', $now );
check( ! is_wp_error( $valid ) && $valid['delivery_date'] === '2026-09-14', 'A valid ITEO Personal selection must pass.' );

$wrong_catalog = PLLC_Order_Rules::validate_selection( [
	'product_id' => 30, 'variation_id' => 31, 'qty' => 1, 'day' => 'lunes', 'delivery_date' => '2026-09-14',
], 'iteo_pacientes', $now );
check( is_wp_error( $wrong_catalog ) && $wrong_catalog->get_error_code() === 'pllc_catalog_mismatch', 'Cross-catalog products must be rejected.' );

$wrong_quantity = PLLC_Order_Rules::validate_selection( [
	'product_id' => 10, 'qty' => 99, 'day' => 'lunes', 'delivery_date' => '2026-09-14', 'meals' => [ 'almuerzo' ],
], 'iteo_personal', $now );
check( is_wp_error( $wrong_quantity ), 'ITEO Personal quantity manipulation must be rejected.' );

$wrong_meal = PLLC_Order_Rules::validate_selection( [
	'product_id' => 10, 'qty' => 1, 'day' => 'lunes', 'delivery_date' => '2026-09-14', 'meals' => [ 'desayuno' ],
], 'iteo_personal', $now );
check( is_wp_error( $wrong_meal ), 'Unknown meals must be rejected.' );

$expired = PLLC_Order_Rules::validate_selection( [
	'product_id' => 20, 'qty' => 2, 'day' => 'lunes', 'delivery_date' => '2026-09-14',
], 'iteo_pacientes', at_time( '2026-09-13 22:00:00' ) );
check( is_wp_error( $expired ) && $expired->get_error_code() === 'pllc_delivery_date_expired', 'Expired delivery dates must be rejected.' );

$school = PLLC_Order_Rules::validate_selection( [
	'product_id' => 30, 'variation_id' => 31, 'qty' => 1, 'day' => 'lunes', 'delivery_date' => '2026-09-14',
], 'colegios', $now );
check( ! is_wp_error( $school ), 'A valid school variation must pass.' );

echo "Order rule checks passed.\n";
