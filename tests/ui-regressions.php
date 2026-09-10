<?php
function check_ui_regression( $condition, $message ) {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

$root     = dirname( __DIR__ );
$frontend = file_get_contents( $root . '/assets/js/pllc-frontend.js' );
$frontend_css = file_get_contents( $root . '/assets/css/pllc-frontend.css' );
$tour     = file_get_contents( $root . '/includes/class-pllc-tour.php' );
$admin    = file_get_contents( $root . '/includes/class-pllc-order-details.php' );
$groups   = file_get_contents( $root . '/includes/class-pllc-cart-groups.php' );

check_ui_regression(
	false !== strpos( $frontend, 'input.disabled = closestCard( input ) !== card;' ),
	'Keep the selected Colegio card variants editable.'
);
check_ui_regression(
	false !== strpos( $frontend, "card.dataset.pllcQuantityUpdate = 'pending';" )
		&& false !== strpos( $frontend, "setProductButtonState( addBtn, 'Actualizar', 'pending' );" ),
	'Show Actualizar when a Particular or ITEO Pacientes quantity changes.'
);
check_ui_regression(
	false !== strpos( $frontend, "document.body.classList.contains( 'pllc-page-iteo-pacientes' )" )
		&& false !== strpos( $frontend, 'function quantityUsesUpdateButton()' ),
	'ITEO Pacientes must use the explicit quantity update flow.'
);
check_ui_regression(
	false !== strpos( $frontend, "return 'Seleccionar';" )
		&& false === strpos( $frontend, "'Agregar al pedido'" ),
	'Use Seleccionar as the initial product action label.'
);
check_ui_regression(
	false !== strpos( $frontend_css, '.pllc-particular-cart-button .elementor-button-icon' )
		&& false !== strpos( $frontend_css, 'display:none !important;' ),
	'Hide the Elementor icon from every Particular product button state.'
);
check_ui_regression(
	false !== strpos( $frontend, "card.dataset.pllcQuantityUpdate = 'prepared';" )
		&& false !== strpos( $frontend, "setProductButtonState( btn, 'Cambio preparado', 'pending' );" ),
	'Require the Particular product update to be prepared before the global save.'
);
check_ui_regression(
	false !== strpos( $tour, "'auto_start'  => false" ),
	'Do not open the guided tour automatically.'
);
check_ui_regression(
	false !== strpos( $admin, 'get_customer_note()' )
		&& false !== strpos( $admin, 'pllc-admin-customer-note' ),
	'Show the customer order note in the structured admin view.'
);
check_ui_regression(
	false !== strpos( $groups, "add_action( 'woocommerce_before_mini_cart', [ __CLASS__, 'prepare_mini_cart' ], 1 );" )
		&& false !== strpos( $groups, 'self::sort_cart_items( WC()->cart );' ),
	'Apply the canonical cart order before every mini cart render.'
);
check_ui_regression(
	false !== strpos( $frontend, 'function arrangeMiniCartContent()' )
		&& false !== strpos( $frontend, "group.className = 'pllc-mini-cart-group';" )
		&& false !== strpos( $frontend, "day.classList.add( 'pllc-mini-cart-day-label' );" ),
	'Keep order header, delivery day and product in the same visual order in the side cart.'
);

echo "UI regression checks passed.\n";
