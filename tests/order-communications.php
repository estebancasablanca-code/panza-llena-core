<?php
define( 'ABSPATH', __DIR__ );

function __( $text, $domain = null ) { return $text; }
function esc_html__( $text, $domain = null ) { return $text; }
function esc_html( $text ) { return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' ); }
function esc_attr( $text ) { return esc_html( $text ); }
function sanitize_key( $value ) { return strtolower( preg_replace( '/[^a-z0-9_\-]/', '', (string) $value ) ); }
function sanitize_text_field( $value ) { return trim( strip_tags( (string) $value ) ); }
function sanitize_textarea_field( $value ) { return trim( strip_tags( (string) $value ) ); }
function sanitize_email( $value ) { return (string) $value; }
function wp_kses_post( $value ) { return (string) $value; }
function wp_kses_allowed_html( $context ) { return [ 'a' => [], 'span' => [], 'strong' => [] ]; }
function wp_kses( $value, $allowed ) { return (string) $value; }
function wc_get_product_terms() { return []; }
function is_wp_error() { return false; }
function is_admin() { return false; }
function is_order_received_page() { return ! empty( $GLOBALS['pllc_received'] ); }
function is_wc_endpoint_url() { return false; }

class PLLC_Order_Rules {
	public static function format_delivery_label( $day, $date ) {
		return $date ? 'Miércoles 9 de septiembre' : ucfirst( $day );
	}
}

class PLLC_Access {
	public static function is_particular_product( $product_id ) {
		return 99 === (int) $product_id;
	}
}

class WC_Order_Item_Product {
	private $order_id;
	private $product_id;
	private $meta;

	public function __construct( $order_id, $product_id, $meta ) {
		$this->order_id   = $order_id;
		$this->product_id = $product_id;
		$this->meta       = $meta;
	}

	public function get_order_id() { return $this->order_id; }
	public function get_product_id() { return $this->product_id; }
	public function get_meta( $key, $single = true ) { return isset( $this->meta[ $key ] ) ? $this->meta[ $key ] : ''; }
	public function meta_exists( $key ) { return array_key_exists( $key, $this->meta ); }
}

class WC_Order {
	private $id;
	private $items;

	public function __construct( $id, $items ) {
		$this->id    = $id;
		$this->items = $items;
	}

	public function get_id() { return $this->id; }
	public function get_items( $type = 'line_item' ) { return $this->items; }
}

$GLOBALS['pllc_orders'] = [];
function wc_get_order( $order_id ) {
	return isset( $GLOBALS['pllc_orders'][ $order_id ] ) ? $GLOBALS['pllc_orders'][ $order_id ] : false;
}

function check_order_communication( $condition, $message ) {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

function make_item( $order_id, $type, $form = [], $meals = [], $product_id = 10 ) {
	return new WC_Order_Item_Product( $order_id, $product_id, [
		'_pllc_form_type'     => $type,
		'_pllc_form'          => $form,
		'_pllc_delivery_day'  => 'miercoles',
		'_pllc_delivery_date' => '2026-09-09',
		'_pllc_meals'         => $meals,
	] );
}

function register_order( $id, $items ) {
	$order = new WC_Order( $id, $items );
	$GLOBALS['pllc_orders'][ $id ] = $order;
	return $order;
}

function render_email_items( $order, $plain_text = false ) {
	ob_start();
	PLLC_Order_Details::begin_email_context( $order, false, $plain_text, null );
	$output = ob_get_clean();
	foreach ( $order->get_items() as $item ) {
		$output .= PLLC_Order_Details::format_item_name( 'Plato del día', $item, true ) . "\n";
	}
	PLLC_Order_Details::end_email_context( $order, false, $plain_text, null );
	return $output;
}

require dirname( __DIR__ ) . '/includes/class-pllc-order-details.php';

$school_form = [
	'nombre_alumno' => 'Ana',
	'colegio'       => 'Plaza Mayor',
	'nivel'         => 'Primaria',
	'curso'         => '3er grado',
	'cubiertos'     => 'Sí',
	'observaciones' => 'Sin salsa',
];
$school_order = register_order( 1, [ make_item( 1, 'colegios', $school_form ) ] );
$school_email = render_email_items( $school_order );
check_order_communication( false !== strpos( $school_email, 'Pedido para Ana' ), 'A non-mixed school email must identify the student order.' );
check_order_communication( false !== strpos( $school_email, 'Colegio: Plaza Mayor' ), 'A non-mixed school email must include school data.' );
check_order_communication( false !== strpos( $school_email, 'Observaciones para la cocina' ) && false !== strpos( $school_email, 'Sin salsa' ), 'A non-mixed school email must include kitchen observations.' );
check_order_communication( false !== strpos( $school_email, 'Día Miércoles 9 de septiembre' ), 'Emails must show the concrete delivery date.' );

$iteo_order = register_order( 2, [ make_item( 2, 'iteo_personal', [], [ 'almuerzo' ] ) ] );
$iteo_email = render_email_items( $iteo_order );
check_order_communication( false !== strpos( $iteo_email, 'Pedido para ITEO Personal' ), 'A non-mixed ITEO email must include its group heading.' );
check_order_communication( false !== strpos( $iteo_email, 'No facturado en WooCommerce' ), 'An ITEO email must explain its billing scope.' );
check_order_communication( false !== strpos( $iteo_email, 'Comida: Almuerzo' ), 'An ITEO email must distinguish lunch from dinner.' );

$iteo_plain = render_email_items( $iteo_order, true );
check_order_communication( false !== strpos( $iteo_plain, 'PEDIDO PARA ITEO PERSONAL' ), 'Plain-text emails must include the group heading.' );
check_order_communication( false !== strpos( $iteo_plain, 'Comida: Almuerzo' ), 'Plain-text emails must include the meal.' );
PLLC_Order_Details::begin_email_context( $iteo_order, false, false, null );
check_order_communication( [] === PLLC_Order_Details::maybe_hide_order_totals( [ 'total' => '$0' ], $iteo_order, 'excl' ), 'ITEO-only emails must not display totals.' );
PLLC_Order_Details::end_email_context( $iteo_order, false, false, null );

$particular_order = register_order( 3, [ make_item( 3, 'particular', [], [], 99 ) ] );
$particular_email = render_email_items( $particular_order );
check_order_communication( false !== strpos( $particular_email, 'Pedido particular' ), 'Particular communications must use the canonical heading.' );

$mixed_order = register_order( 4, [
	make_item( 4, 'iteo_personal', [], [ 'cena' ] ),
	make_item( 4, 'particular', [], [], 99 ),
] );
$mixed_email = render_email_items( $mixed_order );
check_order_communication( false !== strpos( $mixed_email, 'Pedido mixto: ITEO Personal + Particular' ), 'Mixed emails must keep their overall explanation.' );
check_order_communication( false !== strpos( $mixed_email, 'Pedido para ITEO Personal' ) && false !== strpos( $mixed_email, 'Pedido particular' ), 'Mixed emails must identify every group.' );
check_order_communication( false !== strpos( $mixed_email, 'Comida: Cena' ), 'Mixed emails must include the ITEO meal.' );

$GLOBALS['pllc_received'] = true;
PLLC_Order_Details::reset_group_state( $iteo_order );
$received_item = PLLC_Order_Details::format_item_name( 'Plato del día', $iteo_order->get_items()[0], true );
check_order_communication( false !== strpos( $received_item, 'Pedido para ITEO Personal' ), 'Order received must include the group heading for a non-mixed order.' );
check_order_communication( false !== strpos( $received_item, 'Día Miércoles 9 de septiembre' ), 'Order received must include the concrete delivery date.' );
check_order_communication( false !== strpos( $received_item, 'Comida: Almuerzo' ), 'Order received must include the meal.' );

echo "Order communication checks passed.\n";
