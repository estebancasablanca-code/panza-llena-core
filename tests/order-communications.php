<?php
/** Ejecuta las plantillas reales con pedidos sintéticos, sin enviar correos. */
define( 'ABSPATH', __DIR__ );
define( 'PLLC_PATH', dirname( __DIR__ ) . '/' );
set_error_handler( static function ( $severity, $message, $file, $line ) { throw new RuntimeException( $message . ' at ' . $file . ':' . $line ); } );

function __( $text, $domain = null ) { return $text; }
function esc_html__( $text, $domain = null ) { return esc_html( $text ); }
function esc_html_e( $text, $domain = null ) { echo esc_html( $text ); }
function esc_html( $text ) { return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' ); }
function esc_attr( $text ) { return esc_html( $text ); }
function esc_url( $text ) { return esc_html( $text ); }
function absint( $value ) { return abs( (int) $value ); }
function sanitize_key( $value ) { return strtolower( preg_replace( '/[^a-z0-9_\-]/i', '', (string) $value ) ); }
function sanitize_text_field( $value ) { return trim( strip_tags( (string) $value ) ); }
function sanitize_textarea_field( $value ) { return trim( strip_tags( (string) $value ) ); }
function sanitize_html_class( $value ) { return sanitize_key( $value ); }
function sanitize_email( $value ) { return (string) $value; }
function wp_kses_post( $value ) { return (string) $value; }
function wp_strip_all_tags( $value ) { return strip_tags( $value ); }
function wp_kses_allowed_html( $context ) { return [ 'a' => [], 'span' => [], 'strong' => [] ]; }
function wp_kses( $value, $allowed ) { return (string) $value; }
function wc_get_product_terms() { return []; }
function is_wp_error() { return false; }
function is_admin() { return ! empty( $GLOBALS['pllc_admin'] ); }
function is_order_received_page() { return ! empty( $GLOBALS['pllc_received'] ); }
function is_wc_endpoint_url() { return ! empty( $GLOBALS['pllc_account'] ); }
function wc_format_datetime( $date ) { return '10 de septiembre de 2026'; }
function wc_strtoupper( $value ) { return strtoupper( $value ); }
function wc_wptexturize_order_note( $value ) { return $value; }
function wpautop( $value ) { return '<p>' . $value . '</p>'; }
function do_shortcode( $value ) { return $value; }

$GLOBALS['pllc_hooks'] = [];
function add_filter( $name, $callback, $priority = 10, $accepted = 1 ) { $GLOBALS['pllc_hooks'][ $name ][ $priority ][] = [ $callback, $accepted ]; }
function add_action( $name, $callback, $priority = 10, $accepted = 1 ) { add_filter( $name, $callback, $priority, $accepted ); }
function apply_filters( $name, $value, ...$args ) {
	$priorities = isset( $GLOBALS['pllc_hooks'][ $name ] ) ? $GLOBALS['pllc_hooks'][ $name ] : [];
	ksort( $priorities );
	foreach ( $priorities as $callbacks ) {
		foreach ( $callbacks as $callback ) {
			$value = call_user_func_array( $callback[0], array_slice( array_merge( [ $value ], $args ), 0, $callback[1] ) );
		}
	}
	return $value;
}
function do_action( $name, ...$args ) {
	$priorities = isset( $GLOBALS['pllc_hooks'][ $name ] ) ? $GLOBALS['pllc_hooks'][ $name ] : [];
	ksort( $priorities );
	foreach ( $priorities as $callbacks ) {
		foreach ( $callbacks as $callback ) {
			call_user_func_array( $callback[0], array_slice( $args, 0, $callback[1] ) );
		}
	}
}
function wc_get_template( $name, $args = [], $template_path = '', $default_path = '' ) {
	$located = apply_filters( 'wc_get_template', $default_path . $name, $name, $args, $template_path, $default_path );
	extract( $args, EXTR_SKIP );
	include $located;
}
function wc_display_item_meta( $item, $args ) { echo esc_html( $item->public_meta ); }

class PLLC_Order_Rules {
	public static function format_delivery_label( $day, $date ) {
		$labels = [ '2026-09-07' => 'Lunes 7 de septiembre', '2026-09-08' => 'Martes 8 de septiembre', '2026-09-09' => 'Miércoles 9 de septiembre', '2026-09-10' => 'Jueves 10 de septiembre', '2026-09-11' => 'Viernes 11 de septiembre', '2026-09-12' => 'Sábado 12 de septiembre' ];
		return isset( $labels[ $date ] ) ? $labels[ $date ] : ucfirst( $day );
	}
}
class PLLC_Access { public static function is_particular_product( $id ) { return 99 === $id; } }
class PLLC_Test_Product {
	public function get_image( $size, $attributes = [] ) { return '<img src="https://example.test/food.jpg" width="48" height="48" alt="Plato" style="display:block;background:#faf2e3;">'; }
	public function get_purchase_note() { return 'Conservar refrigerado'; }
}
class WC_Order_Item_Product {
	public $id;
	public $order_id;
	public $name;
	public $meta;
	public $quantity = 1;
	public $amount = '$ 8.000,00';
	public $product;
	public $public_meta = '';
	public function __construct( $order_id, $type, $name, $day = 'miercoles', $form = [], $meals = [] ) {
		static $next_id = 1;
		$this->id = $next_id++;
		$this->order_id = $order_id;
		$this->name = $name;
		$dates = [ 'lunes' => '2026-09-07', 'martes' => '2026-09-08', 'miercoles' => '2026-09-09', 'jueves' => '2026-09-10', 'viernes' => '2026-09-11', 'sabado' => '2026-09-12' ];
		$this->meta = [ '_pllc_form_type' => $type, '_pllc_form' => $form, '_pllc_delivery_day' => $day, '_pllc_delivery_date' => isset( $dates[ $day ] ) ? $dates[ $day ] : '', '_pllc_meals' => $meals ];
		$this->product = new PLLC_Test_Product();
	}
	public function get_id() { return $this->id; }
	public function get_name() { return $this->name; }
	public function get_order_id() { return $this->order_id; }
	public function get_product_id() { return 'particular' === $this->get_meta( '_pllc_form_type' ) ? 99 : 10; }
	public function get_meta( $key, $single = true ) { return isset( $this->meta[ $key ] ) ? $this->meta[ $key ] : ''; }
	public function meta_exists( $key ) { return array_key_exists( $key, $this->meta ); }
	public function get_product() { return $this->product; }
	public function get_quantity() { return $this->quantity; }
}
class WC_Order {
	public $id;
	public $items;
	public $refunds = [];
	public $note = "Avisar al llegar\nPortón lateral";
	public function __construct( $id, $items ) {
		$this->id = $id;
		$this->items = [];
		foreach ( $items as $item ) { $this->items[ $item->id ] = $item; }
	}
	public function get_id() { return $this->id; }
	public function get_items( $type = 'line_item' ) { return $this->items; }
	public function get_order_number() { return 2761; }
	public function get_date_created() { return new DateTime( '2026-09-10' ); }
	public function get_edit_order_url() { return 'https://example.test/wp-admin/order/2761'; }
	public function is_paid() { return true; }
	public function is_download_permitted() { return false; }
	public function get_customer_note() { return $this->note; }
	public function get_qty_refunded_for_item( $id ) { return isset( $this->refunds[ $id ] ) ? $this->refunds[ $id ] : 0; }
	public function get_formatted_line_subtotal( $item ) { return apply_filters( 'woocommerce_order_formatted_line_subtotal', $item->amount, $item, $this ); }
	public function get_order_item_totals() {
		return apply_filters( 'woocommerce_get_order_item_totals', [
			[ 'label' => 'Subtotal:', 'value' => '$ 96.000,00' ],
			[ 'label' => 'Descuento:', 'value' => '-$ 1.000,00' ],
			[ 'label' => 'Envío:', 'value' => '$ 1.200,00' ],
			[ 'label' => 'Total:', 'value' => '$ 96.200,00' ],
		], $this, 'excl' );
	}
}
function wc_get_order( $id ) { return $GLOBALS['pllc_orders'][ $id ]; }
function check_order_communication( $condition, $message ) { if ( ! $condition ) { throw new RuntimeException( $message ); } }
function render_email_items( $order, $plain_text = false, $admin = false ) {
	ob_start();
	try {
		wc_get_template( $plain_text ? 'emails/plain/email-order-details.php' : 'emails/email-order-details.php', [ 'order' => $order, 'sent_to_admin' => $admin, 'plain_text' => $plain_text, 'email' => null ] );
		return ob_get_contents();
	} finally { ob_end_clean(); }
}
function expect_sequence( $output, $needles ) {
	$offset = 0;
	foreach ( $needles as $needle ) {
		$pos = strpos( $output, $needle, $offset );
		check_order_communication( false !== $pos, 'Missing or misplaced output: ' . $needle );
		$offset = $pos + strlen( $needle );
	}
}

require PLLC_PATH . 'includes/class-pllc-item-order.php';
require PLLC_PATH . 'includes/trait-pllc-order-presentation.php';
require PLLC_PATH . 'includes/class-pllc-order-details.php';
require PLLC_PATH . 'includes/class-pllc-emails.php';
require PLLC_PATH . 'includes/class-pllc-cart-groups.php';
PLLC_Order_Details::init();
PLLC_Emails::init();

$school_form = [ 'nombre_alumno' => 'Mariano', 'colegio' => 'Plaza Mayor', 'nivel' => 'Primaria', 'curso' => '2do grado C', 'cubiertos' => 'Sí', 'observaciones' => 'Cocina rico!!!' ];
$school_first = new WC_Order_Item_Product( 1, 'colegios', 'Bastoncitos de pescado con arroz – Clásico', 'miercoles', $school_form );
$school_last = new WC_Order_Item_Product( 1, 'colegios', 'Hamburguesas con queso en pan – XL', 'jueves', $school_form );
$school_last->amount = '$ 9.000,00';
$part_first = new WC_Order_Item_Product( 1, 'particular', 'Particular 3', 'miercoles', [ 'observaciones' => 'Sin sal' ] );
$part_first->quantity = 7; $part_first->amount = '$ 55.300,00';
$part_last = new WC_Order_Item_Product( 1, 'particular', 'Particular 4', 'jueves', [ 'observaciones' => 'Sin sal' ] );
$part_last->quantity = 3; $part_last->amount = '$ 23.700,00';
$mixed = new WC_Order( 1, [ $part_last, $school_last, $part_first, $school_first ] );
$personal_lunch = new WC_Order_Item_Product( 2, 'iteo_personal', 'Pollo al horno', 'miercoles', [ 'observaciones' => 'Sin salsa' ], [ 'almuerzo' ] );
$personal_dinner = new WC_Order_Item_Product( 2, 'iteo_personal', 'Tarta de verdura', 'miercoles', [], [ 'cena' ] );
$patients_item = new WC_Order_Item_Product( 3, 'iteo_pacientes', 'Menú de pacientes', 'jueves', [ 'observaciones' => 'Sin condimentos' ] );
$patients_item->quantity = 12;
$fixtures = [
	'mixto-colegios' => $mixed,
	'colegios' => new WC_Order( 1, [ $school_last, $school_first ] ),
	'particular' => new WC_Order( 1, [ $part_last, $part_first ] ),
	'iteo-personal' => new WC_Order( 2, [ $personal_dinner, $personal_lunch ] ),
	'iteo-pacientes' => new WC_Order( 3, [ $patients_item ] ),
];
$mixed_personal = clone $part_first; $mixed_personal->order_id = 2;
$fixtures['mixto-iteo'] = new WC_Order( 2, [ $mixed_personal, $personal_dinner, $personal_lunch ] );
$mixed_patients = clone $part_first; $mixed_patients->order_id = 3;
$fixtures['mixto-pacientes'] = new WC_Order( 3, [ $mixed_patients, $patients_item ] );
$GLOBALS['pllc_orders'] = [ 1 => $mixed, 2 => $fixtures['iteo-personal'], 3 => $fixtures['iteo-pacientes'] ];

foreach ( $fixtures as $key => $order ) {
	foreach ( [ false, true ] as $plain ) {
		foreach ( [ false, true ] as $admin ) {
			$GLOBALS['pllc_admin'] = $admin;
			$output = render_email_items( $order, $plain, $admin );
			check_order_communication( ! PLLC_Emails::is_rendering(), 'Rendering must clean up email context.' );
			check_order_communication( false === strpos( $output, '_pllc_' ) && false === strpos( $output, 'Resumen de entrega' ), 'Do not expose internal metadata or the obsolete detached summary.' );
			check_order_communication( 1 === substr_count( $output, 'Avisar al llegar' ), 'Native order notes must occur exactly once.' );
			if ( $plain ) {
				check_order_communication( ! preg_match( '/<\/?(?:table|tr|td|span|p|strong|img|del|ins)\b/i', $output ), 'Plain output must not contain HTML.' );
			} else {
				check_order_communication( false !== strpos( $output, 'role="presentation"' ) && false !== strpos( $output, 'bgcolor="#faf2e3"' ), 'Use explicit table and color fallbacks.' );
			}
			if ( in_array( $key, [ 'iteo-personal', 'iteo-pacientes' ], true ) ) {
				check_order_communication( false === strpos( $output, '$' ) && false === strpos( $output, 'Subtotal:' ), 'ITEO-only emails must have no money or totals, including admin/plain variants.' );
			} else {
				check_order_communication( false !== strpos( $output, '$ 96.200,00' ) && false !== strpos( $output, '-$ 1.000,00' ), 'Keep WooCommerce totals, discounts and shipping values.' );
			}
			if ( false !== strpos( $key, 'iteo' ) && false === strpos( $key, 'pacientes' ) ) {
				expect_sequence( $output, [ 'Pollo al horno', 'Comida: Almuerzo', 'Tarta de verdura', 'Comida: Cena' ] );
			}
		}
	}
}
$GLOBALS['pllc_admin'] = false;
$html = render_email_items( $mixed );
expect_sequence( $html, [ 'Pedido para Mariano', 'Colegio: Plaza Mayor', 'Miércoles 9 de septiembre', 'Bastoncitos de pescado', 'Jueves 10 de septiembre', 'Hamburguesas con queso', 'Pedido particular', 'Sin sal', 'Particular 3', 'Particular 4', 'Total:' ] );
$plain = render_email_items( $mixed, true );
expect_sequence( $plain, [ 'PEDIDO PARA MARIANO', 'Bastoncitos de pescado', 'Hamburguesas con queso', 'PEDIDO PARTICULAR', 'Particular 3', 'Particular 4' ] );
check_order_communication( 1 === substr_count( $html, 'Pedido para Mariano' ) && 1 === substr_count( $html, '>Pedido particular<' ), 'Each group heading must occur once.' );

$ana = clone $school_first;
$ana->id = 900; $ana->name = 'Plato de Ana'; $ana->meta['_pllc_form']['nombre_alumno'] = 'Ana';
$multi = new WC_Order( 1, [ $part_first, $school_last, $ana, $school_first ] );
expect_sequence( render_email_items( $multi ), [ 'Pedido para Mariano', 'Bastoncitos', 'Hamburguesas', 'Pedido para Ana', 'Plato de Ana', 'Pedido particular', 'Particular 3' ] );
$fixtures['varios-alumnos'] = $multi;

// The cart and the email adapter must use identical stable ordering.
$cart = (object) [ 'cart_contents' => [] ];
foreach ( $mixed->items as $id => $item ) {
	$cart->cart_contents[ $id ] = [ 'product_id' => $item->get_product_id(), 'pllc_form_type' => $item->get_meta( '_pllc_form_type' ), 'pllc_form' => $item->get_meta( '_pllc_form' ), 'pllc_day' => $item->get_meta( '_pllc_delivery_day' ), 'pllc_meals' => $item->get_meta( '_pllc_meals' ) ];
}
PLLC_Cart_Groups::sort_cart_items( $cart );
expect_sequence( $html, array_map( static function ( $id ) use ( $mixed ) { return $mixed->items[ $id ]->name; }, array_keys( $cart->cart_contents ) ) );
$before = serialize( $mixed->items );
render_email_items( $mixed );
check_order_communication( $before === serialize( $mixed->items ), 'Rendering must never mutate or reorder stored order items.' );

// Deleted products, refunds and public item metadata must remain readable.
$refund_item = clone $part_first; $refund_item->quantity = 4; $refund_item->product = false;
$refund_item->public_meta = 'Tamaño: XL'; $refund_item->amount = '<del>$ 8.000,00</del> <ins>$ 6.000,00</ins>';
$refund = new WC_Order( 1, [ $refund_item ] ); $refund->refunds[ $refund_item->id ] = -1;
$refund_html = render_email_items( $refund );
check_order_communication( false !== strpos( $refund_html, '<del>4</del> <ins>3</ins>' ) && false !== strpos( $refund_html, '$ 6.000,00' ) && false !== strpos( $refund_html, 'Tamaño: XL' ), 'Preserve refund quantities, native amounts and metadata when the product no longer exists.' );
check_order_communication( false !== strpos( render_email_items( $refund, true ), '4 → 3 (reembolso)' ), 'Plain refunds must remain unambiguous.' );
$fixtures['reembolso'] = $refund;

// A rendering exception must not leak context or output buffers into later emails/pages.
add_action( 'woocommerce_order_item_meta_start', static function () { if ( ! empty( $GLOBALS['pllc_throw'] ) ) { throw new RuntimeException( 'Intentional render failure' ); } } );
$level = ob_get_level(); $GLOBALS['pllc_throw'] = true;
try { render_email_items( $mixed ); check_order_communication( false, 'Expected render failure.' ); }
catch ( RuntimeException $error ) { check_order_communication( 'Intentional render failure' === $error->getMessage(), $error->getMessage() ); }
$GLOBALS['pllc_throw'] = false;
check_order_communication( ! PLLC_Emails::is_rendering() && $level === ob_get_level(), 'Exceptions must restore context and buffers.' );

add_action( 'woocommerce_email_before_order_table', static function ( $order ) use ( $fixtures ) {
	if ( ! empty( $GLOBALS['pllc_nested'] ) && 1 === $order->get_id() ) {
		render_email_items( $fixtures['iteo-personal'], true );
		check_order_communication( PLLC_Emails::is_rendering( 1 ), 'Nested rendering must restore the outer order context.' );
	}
} );
$GLOBALS['pllc_nested'] = true;
render_email_items( $mixed );
$GLOBALS['pllc_nested'] = false;
check_order_communication( ! PLLC_Emails::is_rendering(), 'Nested emails must leave no residual context.' );

// Date/type fallbacks are independent of a live product.
$legacy = clone $school_first; $legacy->meta['_pllc_delivery_day'] = '';
expect_sequence( render_email_items( new WC_Order( 1, [ $school_last, $legacy ] ) ), [ 'Bastoncitos', 'Hamburguesas' ] );
$escaped = clone $school_first; $escaped->meta['_pllc_form']['nombre_alumno'] = '<b>Ana & Tomás</b>';
check_order_communication( false !== strpos( render_email_items( new WC_Order( 1, [ $escaped ] ) ), '&lt;b&gt;Ana &amp; Tomás&lt;/b&gt;' ), 'Group labels must escape user data.' );

foreach ( [ 'emails/email-header.php', 'emails/email-footer.php', 'order/order-details.php' ] as $unrelated ) {
	check_order_communication( 'original.php' === PLLC_Emails::locate_order_details( 'original.php', $unrelated, [ 'order' => $mixed ] ), 'Only order email detail templates may be replaced.' );
}
foreach ( [ 'pllc_received', 'pllc_account' ] as $screen ) {
	$GLOBALS[ $screen ] = true;
	PLLC_Order_Details::reset_group_state( $fixtures['iteo-personal'] );
	$received = PLLC_Order_Details::format_item_name( 'Plato del día', $personal_lunch, true );
	check_order_communication( false !== strpos( $received, 'Pedido para ITEO Personal' ) && false !== strpos( $received, 'Comida: Almuerzo' ), 'Keep grouped content and meal labels in order received/My account.' );
	$GLOBALS[ $screen ] = false;
}
$css = PLLC_Emails::email_styles( '' );
check_order_communication( false !== strpos( $css, '@media only screen and (max-width: 480px)' ) && false === strpos( $css, 'display:flex' ) && false === strpos( $css, 'display:grid' ), 'Responsive email must retain the table fallback.' );

if ( isset( $argv[1], $argv[2] ) && '--preview-dir' === $argv[1] ) {
	if ( ! is_dir( $argv[2] ) ) { mkdir( $argv[2], 0777, true ); }
	foreach ( $fixtures as $key => $order ) {
		$content = render_email_items( $order );
		$preview = '<!doctype html><html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><style>body{margin:0;background:#f1f2f3}.email-shell{max-width:640px;margin:20px auto;background:white;padding:24px;box-sizing:border-box}td,th{text-align:inherit}td[align=right],th[align=right]{text-align:right}td[align=center],th[align=center]{text-align:center}' . $css . '</style></head><body><div class="email-shell">' . $content . '</div></body></html>';
		file_put_contents( $argv[2] . '/' . $key . '.html', $preview );
		file_put_contents( $argv[2] . '/' . $key . '.txt', render_email_items( $order, true ) );
	}
}
echo "Order communication checks passed: grouped HTML/plain output, all modalities, cart order, refunds, notes, scope and cleanup.\n";
