<?php
define( 'ABSPATH', __DIR__ );

function sanitize_textarea_field( $value ) {
	return trim( strip_tags( (string) $value ) );
}

function is_checkout() {
	return true;
}

class PLLC_Test_Cart {
	public $items = [];

	public function get_cart() {
		return $this->items;
	}
}

class PLLC_Test_WC {
	public $cart;

	public function __construct() {
		$this->cart = new PLLC_Test_Cart();
	}
}

class PLLC_Test_Order {
	public $customer_note = null;

	public function set_customer_note( $note ) {
		$this->customer_note = $note;
	}
}

$GLOBALS['pllc_test_wc'] = new PLLC_Test_WC();
function WC() {
	return $GLOBALS['pllc_test_wc'];
}

function check_checkout_notes( $condition, $message ) {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

require dirname( __DIR__ ) . '/includes/class-pllc-checkout.php';

$types = [ 'colegios', 'iteo_personal', 'iteo_pacientes', 'particular' ];
foreach ( $types as $type ) {
	$GLOBALS['pllc_test_wc']->cart->items = [ [ 'pllc_form_type' => $type ] ];
	$fields = PLLC_Checkout::filter_checkout_fields( [
		'billing' => [
			'billing_first_name' => [],
			'billing_phone'      => [],
			'billing_email'      => [],
		],
	] );
	check_checkout_notes( isset( $fields['order']['order_comments'] ), 'Order notes must exist for ' . $type . '.' );
	check_checkout_notes( false === $fields['order']['order_comments']['required'], 'Order notes must stay optional for ' . $type . '.' );
}

$fields_without_billing = PLLC_Checkout::filter_checkout_fields( [] );
check_checkout_notes( isset( $fields_without_billing['order']['order_comments'] ), 'Order notes must not depend on billing fields.' );

$order = new PLLC_Test_Order();
PLLC_Checkout::save_customer_note( $order, [ 'order_comments' => "  Entregar por portería. <b>Gracias</b>  " ] );
check_checkout_notes( 'Entregar por portería. Gracias' === $order->customer_note, 'Save the native checkout note as the WooCommerce customer note.' );

$checkout_css = file_get_contents( dirname( __DIR__ ) . '/assets/css/pllc-frontend.css' );
check_checkout_notes(
	false !== strpos( $checkout_css, 'body.woocommerce-checkout.pllc-iteo-only-cart #payment .wc_payment_methods { display: none !important; }' ),
	'Hide payment methods for ITEO-only orders.'
);
check_checkout_notes(
	false === strpos( $checkout_css, 'body.woocommerce-checkout.pllc-iteo-only-cart #payment .woocommerce-terms-and-conditions-wrapper { display: none !important; }' )
		&& false === strpos( $checkout_css, 'body.woocommerce-checkout.pllc-iteo-only-cart #payment .woocommerce-privacy-policy-text,' ),
	'Keep WooCommerce terms and privacy information visible for ITEO-only orders.'
);

echo "Checkout note checks passed.\n";
