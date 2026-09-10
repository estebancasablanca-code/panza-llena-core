<?php
define( 'ABSPATH', __DIR__ );

function sanitize_key( $value ) { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $value ) ); }
function sanitize_textarea_field( $value ) { return trim( (string) $value ); }
function absint( $value ) { return abs( (int) $value ); }

class PLLC_Test_Session {
	public function get( $key, $default = null ) {
		return 'pllc_particular_observations' === $key ? 'Sin sal' : $default;
	}
}

class PLLC_Test_Cart {
	public function get_cart() {
		return [
			'particular-key' => [
				'product_id' => 10, 'quantity' => 3, 'variation_id' => 0,
				'pllc_form_type' => 'particular', 'pllc_day' => 'jueves',
			],
			'college-key' => [
				'product_id' => 20, 'quantity' => 1, 'variation_id' => 201,
				'pllc_form_type' => 'colegios', 'pllc_day' => 'viernes',
				'pllc_form' => [ 'nombre_alumno' => 'Ana', 'colegio' => 'CAE' ],
			],
		];
	}
}

class PLLC_Test_WC {
	public $cart;
	public $session;
	public function __construct() {
		$this->cart = new PLLC_Test_Cart();
		$this->session = new PLLC_Test_Session();
	}
}

function WC() { return $GLOBALS['pllc_test_wc']; }
$GLOBALS['pllc_test_wc'] = new PLLC_Test_WC();

require dirname( __DIR__ ) . '/includes/class-pllc-frontend-assets.php';

function check_frontend_state( $condition, $message ) {
	if ( ! $condition ) { throw new RuntimeException( $message ); }
}

$state = PLLC_Frontend_Assets::build_frontend_state( 'particular' );
check_frontend_state( isset( $state['cart_state']['10']['_days']['jueves']['_default'] ), 'Expose the current form type cart state.' );
check_frontend_state( ! isset( $state['cart_state']['20'] ), 'Never mix catalog states.' );
check_frontend_state( $state['current_form']['observaciones'] === 'Sin sal', 'Expose the canonical saved form.' );
check_frontend_state( $state['has_active_order'] === true, 'Expose whether this flow has an active order.' );
check_frontend_state( count( $state['students'] ) === 1, 'Rebuild the student picker from the cart.' );
check_frontend_state( isset( $state['students'][0]['cart_state']['20']['_days']['viernes']['_default'] ), 'Expose each student cart state.' );

$college = PLLC_Frontend_Assets::build_frontend_state( 'colegios' );
check_frontend_state( $college['cart_state'] === [] && $college['has_active_order'] === true, 'College state must live per student, never in an aggregated product map.' );

$invalid = PLLC_Frontend_Assets::build_frontend_state( 'manipulated' );
check_frontend_state( $invalid['form_type'] === '' && $invalid['cart_state'] === [], 'Reject unknown form types.' );

$cart_source = file_get_contents( dirname( __DIR__ ) . '/includes/class-pllc-cart.php' );
$js_source   = file_get_contents( dirname( __DIR__ ) . '/assets/js/pllc-frontend.js' );
$css_source  = file_get_contents( dirname( __DIR__ ) . '/assets/css/pllc-frontend.css' );
check_frontend_state( false !== strpos( $cart_source, "data['frontend_state']" ), 'Every cart mutation must include canonical frontend state.' );
check_frontend_state( false !== strpos( $cart_source, "'removed' => \$removed" ), 'Removal must report actual successful mutations.' );
check_frontend_state( false === strpos( $js_source, 'function resetRemovedCard' ), 'Do not maintain a second partial removal state in JavaScript.' );
check_frontend_state( false !== strpos( $js_source, "rememberCartEventAfterReload( res.data, 'removed_from_cart' )" ), 'Removal must preserve the correct WooCommerce event.' );
check_frontend_state( false !== strpos( $css_source, 'body.pllc-cart-has-editable-quantities button[name="update_cart"]' ), 'CSS and JavaScript must share one update-cart visibility contract.' );

echo "Canonical frontend state checks passed.\n";
