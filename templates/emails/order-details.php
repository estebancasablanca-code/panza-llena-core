<?php
/** Entrada del detalle de WooCommerce; los datos y el contexto pertenecen al renderer. */
defined( 'ABSPATH' ) || exit;
PLLC_Emails::render( $order, $sent_to_admin, $plain_text, $email );
