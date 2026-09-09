<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Optional visual overrides for WooCommerce page titles and the order-received endpoint. */
class PLLC_Order_Received_Styles {
	public static function init() {
		// Load before the existing custom CSS so advanced overrides keep their priority.
		add_action( 'wp_enqueue_scripts', [ __CLASS__, 'frontend_css' ], 29 );
	}

	private static function schema() {
		return [
[ 'title' => 'Títulos de WooCommerce', 'description' => 'Carrito, Finalizar compra, Pedido recibido, Mi cuenta y títulos internos de la confirmación.', 'selector' => 'body.woocommerce-order-received .woocommerce-order .woocommerce-thankyou-order-received, body.woocommerce-order-received .woocommerce-order h2, body.woocommerce-cart .entry-title, body.woocommerce-checkout .entry-title, body.woocommerce-account .entry-title, body.post-type-archive-product .woocommerce-products-header__title.page-title, body.woocommerce-cart .elementor-widget-theme-post-title .elementor-heading-title, body.woocommerce-checkout .elementor-widget-theme-post-title .elementor-heading-title, body.woocommerce-account .elementor-widget-theme-post-title .elementor-heading-title', 'fields' => [
[ 'key' => 'title_color', 'label' => 'Color', 'property' => 'color', 'type' => 'color' ],
[ 'key' => 'title_size', 'label' => 'Tamaño (px)', 'property' => 'font-size', 'type' => 'number', 'max' => 100 ],
[ 'key' => 'title_font', 'label' => 'Familia tipográfica', 'property' => 'font-family', 'type' => 'font' ]
] ],
[ 'title' => 'Margen del título principal', 'description' => 'Separación exterior del título de cada página; no modifica los subtítulos del pedido.', 'selector' => 'body.woocommerce-cart .entry-title, body.woocommerce-checkout .entry-title, body.woocommerce-account .entry-title, body.post-type-archive-product .woocommerce-products-header__title.page-title, body.woocommerce-cart .elementor-widget-theme-post-title .elementor-heading-title, body.woocommerce-checkout .elementor-widget-theme-post-title .elementor-heading-title, body.woocommerce-account .elementor-widget-theme-post-title .elementor-heading-title', 'fields' => [
[ 'key' => 'page_title_margin_top', 'label' => 'Margen superior (px)', 'property' => 'margin-top', 'type' => 'number', 'max' => 300 ],
[ 'key' => 'page_title_margin_bottom', 'label' => 'Margen inferior (px)', 'property' => 'margin-bottom', 'type' => 'number', 'max' => 300 ]
] ],
[ 'title' => 'Textos y enlaces', 'description' => 'Texto general de la confirmación.', 'selector' => 'body.woocommerce-order-received .woocommerce-order', 'fields' => [
[ 'key' => 'text_color', 'label' => 'Color del texto', 'property' => 'color', 'type' => 'color' ],
[ 'key' => 'text_size', 'label' => 'Tamaño (px)', 'property' => 'font-size', 'type' => 'number', 'max' => 100 ],
[ 'key' => 'text_font', 'label' => 'Familia tipográfica', 'property' => 'font-family', 'type' => 'font' ]
] ],
[ 'title' => 'Enlaces de productos', 'description' => 'Nombres enlazados dentro del detalle.', 'selector' => 'body.woocommerce-order-received .woocommerce-order .woocommerce-table--order-details .product-name > a', 'fields' => [
[ 'key' => 'product_link_color', 'label' => 'Color del enlace', 'property' => 'color', 'type' => 'color' ],
[ 'key' => 'product_link_size', 'label' => 'Tamaño (px)', 'property' => 'font-size', 'type' => 'number', 'max' => 100 ]
] ],
[ 'title' => 'Enlaces al pasar el cursor', 'description' => 'Hover y foco de teclado.', 'selector' => 'body.woocommerce-order-received .woocommerce-order .woocommerce-table--order-details .product-name > a:hover, body.woocommerce-order-received .woocommerce-order .woocommerce-table--order-details .product-name > a:focus-visible', 'fields' => [
[ 'key' => 'product_link_hover', 'label' => 'Color', 'property' => 'color', 'type' => 'color' ]
] ],
[ 'title' => 'Resumen del pedido', 'description' => 'Número, fecha, correo, importe y pago, cuando corresponda.', 'selector' => 'body.woocommerce-order-received .woocommerce-order .woocommerce-order-overview', 'fields' => [
[ 'key' => 'overview_bg', 'label' => 'Fondo', 'property' => 'background-color', 'type' => 'color' ],
[ 'key' => 'overview_text', 'label' => 'Texto', 'property' => 'color', 'type' => 'color' ],
[ 'key' => 'overview_size', 'label' => 'Tamaño (px)', 'property' => 'font-size', 'type' => 'number', 'max' => 100 ],
[ 'key' => 'overview_padding', 'label' => 'Espacio interior (px)', 'property' => 'padding', 'type' => 'number', 'max' => 100 ],
[ 'key' => 'overview_radius', 'label' => 'Radio (px)', 'property' => 'border-radius', 'type' => 'number', 'max' => 100 ]
] ],
[ 'title' => 'Bloques «Pedido para…»', 'description' => 'Fondo y forma de los encabezados de grupo.', 'selector' => 'body.woocommerce-order-received.pllc-grouped-order-details .pllc-order-detail-group-header-row > td', 'fields' => [
[ 'key' => 'group_bg', 'label' => 'Fondo', 'property' => 'background-color', 'type' => 'color' ],
[ 'key' => 'group_border', 'label' => 'Borde lateral', 'property' => 'border-left-color', 'type' => 'color' ],
[ 'key' => 'group_border_width', 'label' => 'Grosor lateral (px)', 'property' => 'border-left-width', 'type' => 'number', 'max' => 12 ],
[ 'key' => 'group_radius', 'label' => 'Radio (px)', 'property' => 'border-radius', 'type' => 'number', 'max' => 100 ],
[ 'key' => 'group_padding', 'label' => 'Espacio interior (px)', 'property' => 'padding', 'type' => 'number', 'max' => 100 ]
] ],
[ 'title' => 'Títulos de cada grupo', 'description' => 'Pedido para ITEO, para cada alumno y para mí.', 'selector' => 'body.woocommerce-order-received.pllc-grouped-order-details .pllc-order-detail-group-header .pllc-cart-group-header', 'fields' => [
[ 'key' => 'group_title_color', 'label' => 'Color', 'property' => 'color', 'type' => 'color' ],
[ 'key' => 'group_title_size', 'label' => 'Tamaño (px)', 'property' => 'font-size', 'type' => 'number', 'max' => 100 ],
[ 'key' => 'group_title_weight', 'label' => 'Peso (100–900)', 'property' => 'font-weight', 'type' => 'weight', 'max' => 900 ],
[ 'key' => 'group_title_font', 'label' => 'Familia tipográfica', 'property' => 'font-family', 'type' => 'font' ]
] ],
[ 'title' => 'Observaciones para la cocina', 'description' => 'Texto y etiqueta de las observaciones.', 'selector' => 'body.woocommerce-order-received .woocommerce-order .pllc-kitchen-observations', 'fields' => [
[ 'key' => 'observations_color', 'label' => 'Color', 'property' => 'color', 'type' => 'color' ],
[ 'key' => 'observations_size', 'label' => 'Tamaño (px)', 'property' => 'font-size', 'type' => 'number', 'max' => 100 ],
[ 'key' => 'observations_gap', 'label' => 'Separación superior (px)', 'property' => 'margin-top', 'type' => 'number', 'max' => 100 ]
] ],
[ 'title' => 'Información del grupo y entrega', 'description' => 'Datos del alumno, lugar de entrega y aclaración de facturación.', 'selector' => 'body.woocommerce-order-received.pllc-grouped-order-details .pllc-order-detail-group-header .pllc-cart-group-summary, body.woocommerce-order-received.pllc-grouped-order-details .pllc-order-detail-group-header .pllc-order-group-context', 'fields' => [
[ 'key' => 'context_color', 'label' => 'Color', 'property' => 'color', 'type' => 'color' ],
[ 'key' => 'context_size', 'label' => 'Tamaño (px)', 'property' => 'font-size', 'type' => 'number', 'max' => 100 ],
[ 'key' => 'context_gap', 'label' => 'Separación superior (px)', 'property' => 'margin-top', 'type' => 'number', 'max' => 100 ]
] ],
[ 'title' => 'Etiquetas de días', 'description' => 'Lunes, martes y demás días.', 'selector' => 'body.woocommerce-order-received.pllc-grouped-order-details .woocommerce-table--order-details .pllc-cart-day-label', 'fields' => [
[ 'key' => 'day_bg', 'label' => 'Fondo', 'property' => 'background-color', 'type' => 'color' ],
[ 'key' => 'day_text', 'label' => 'Texto', 'property' => 'color', 'type' => 'color' ],
[ 'key' => 'day_size', 'label' => 'Tamaño (px)', 'property' => 'font-size', 'type' => 'number', 'max' => 100 ],
[ 'key' => 'day_radius', 'label' => 'Radio (px)', 'property' => 'border-radius', 'type' => 'number', 'max' => 999 ],
[ 'key' => 'day_padding', 'label' => 'Espacio interior (px)', 'property' => 'padding', 'type' => 'number', 'max' => 100 ],
[ 'key' => 'day_gap', 'label' => 'Separación inferior (px)', 'property' => 'margin-bottom', 'type' => 'number', 'max' => 100 ]
] ],
[ 'title' => 'Productos y cantidades', 'description' => 'Filas de productos; no modifica los encabezados de grupo.', 'selector' => 'body.woocommerce-order-received .woocommerce-order .woocommerce-table--order-details tbody tr:not(.pllc-order-detail-group-header-row) > td', 'fields' => [
[ 'key' => 'row_color', 'label' => 'Color del texto', 'property' => 'color', 'type' => 'color' ],
[ 'key' => 'row_size', 'label' => 'Tamaño (px)', 'property' => 'font-size', 'type' => 'number', 'max' => 100 ],
[ 'key' => 'row_padding', 'label' => 'Espacio interior (px)', 'property' => 'padding', 'type' => 'number', 'max' => 100 ],
[ 'key' => 'row_border', 'label' => 'Separadores', 'property' => 'border-bottom-color', 'type' => 'color' ],
[ 'key' => 'row_border_width', 'label' => 'Grosor del separador (px)', 'property' => 'border-bottom-width', 'type' => 'number', 'max' => 10 ]
] ],
[ 'title' => 'Encabezados de tabla', 'description' => 'Producto y Total, cuando son visibles.', 'selector' => 'body.woocommerce-order-received .woocommerce-order .woocommerce-table--order-details thead th', 'fields' => [
[ 'key' => 'table_head_bg', 'label' => 'Fondo', 'property' => 'background-color', 'type' => 'color' ],
[ 'key' => 'table_head_text', 'label' => 'Texto', 'property' => 'color', 'type' => 'color' ],
[ 'key' => 'table_head_size', 'label' => 'Tamaño (px)', 'property' => 'font-size', 'type' => 'number', 'max' => 100 ],
[ 'key' => 'table_head_padding', 'label' => 'Espacio interior (px)', 'property' => 'padding', 'type' => 'number', 'max' => 100 ]
] ],
[ 'title' => 'Totales', 'description' => 'Importes de Colegios y Particulares.', 'selector' => 'body.woocommerce-order-received .woocommerce-order .woocommerce-table--order-details tfoot th, body.woocommerce-order-received .woocommerce-order .woocommerce-table--order-details tfoot td', 'fields' => [
[ 'key' => 'total_bg', 'label' => 'Fondo', 'property' => 'background-color', 'type' => 'color' ],
[ 'key' => 'total_text', 'label' => 'Texto', 'property' => 'color', 'type' => 'color' ],
[ 'key' => 'total_size', 'label' => 'Tamaño (px)', 'property' => 'font-size', 'type' => 'number', 'max' => 100 ],
[ 'key' => 'total_padding', 'label' => 'Espacio interior (px)', 'property' => 'padding', 'type' => 'number', 'max' => 100 ]
] ],
[ 'title' => 'Aviso de pedido mixto', 'description' => 'Aclaración sobre entregas e importes.', 'selector' => 'body.woocommerce-order-received .woocommerce-order .pllc-mixed-order-notice', 'fields' => [
[ 'key' => 'mixed_bg', 'label' => 'Fondo', 'property' => 'background-color', 'type' => 'color' ],
[ 'key' => 'mixed_text', 'label' => 'Texto', 'property' => 'color', 'type' => 'color' ],
[ 'key' => 'mixed_border', 'label' => 'Borde lateral', 'property' => 'border-left-color', 'type' => 'color' ],
[ 'key' => 'mixed_size', 'label' => 'Tamaño (px)', 'property' => 'font-size', 'type' => 'number', 'max' => 100 ],
[ 'key' => 'mixed_padding', 'label' => 'Espacio interior (px)', 'property' => 'padding', 'type' => 'number', 'max' => 100 ],
[ 'key' => 'mixed_radius', 'label' => 'Radio (px)', 'property' => 'border-radius', 'type' => 'number', 'max' => 100 ]
] ],
[ 'title' => 'Direcciones', 'description' => 'Datos de facturación y envío.', 'selector' => 'body.woocommerce-order-received .woocommerce-order .woocommerce-customer-details address', 'fields' => [
[ 'key' => 'address_bg', 'label' => 'Fondo', 'property' => 'background-color', 'type' => 'color' ],
[ 'key' => 'address_text', 'label' => 'Texto', 'property' => 'color', 'type' => 'color' ],
[ 'key' => 'address_border', 'label' => 'Borde', 'property' => 'border-color', 'type' => 'color' ],
[ 'key' => 'address_size', 'label' => 'Tamaño (px)', 'property' => 'font-size', 'type' => 'number', 'max' => 100 ],
[ 'key' => 'address_padding', 'label' => 'Espacio interior (px)', 'property' => 'padding', 'type' => 'number', 'max' => 100 ],
[ 'key' => 'address_radius', 'label' => 'Radio (px)', 'property' => 'border-radius', 'type' => 'number', 'max' => 100 ]
] ]
];
	}

	public static function render( $settings ) {
		$values = self::sanitize( $settings['order_received'] ?? [] );
		echo '<section class="pllc-style-card pllc-style-wide pllc-advanced"><h2>Títulos de WooCommerce y Pedido recibido</h2>';
		echo '<p>El primer bloque unifica los títulos principales de Carrito, Finalizar compra, Pedido recibido, Mi cuenta y Tienda. Los demás estilos son exclusivos de la confirmación de compra. Un campo vacío conserva el estilo actual del sitio; para volver al estilo heredado, vaciá el campo y guardá.</p>';
		echo '<p>Las familias tipográficas deben estar cargadas en el sitio (por ejemplo: Roboto, sans-serif). Estas opciones no descargan fuentes.</p>';
		foreach ( self::schema() as $group ) {
			echo '<details class="pllc-received-style-group"><summary><strong>' . esc_html( $group['title'] ) . '</strong></summary>';
			echo '<p class="description">' . esc_html( $group['description'] ) . '</p><div class="pllc-style-fields">';
			foreach ( $group['fields'] as $field ) {
				$key = $field['key'];
				$value = $values[$key] ?? '';
				$id = 'pllc-received-' . $key;
				echo '<label for="' . esc_attr( $id ) . '">' . esc_html( $field['label'] ) . '</label>';
				$common = ' id="' . esc_attr( $id ) . '" name="styles[order_received][' . esc_attr( $key ) . ']" value="' . esc_attr( $value ) . '"';
				if ( 'color' === $field['type'] ) {
					echo '<input type="text" class="pllc-color"' . $common . ' placeholder="Heredado">';
				} elseif ( 'font' === $field['type'] ) {
					echo '<input type="text"' . $common . ' placeholder="Fuente del sitio" maxlength="160">';
				} else {
					$min = 'weight' === $field['type'] ? 100 : ( 'font-size' === $field['property'] ? 1 : 0 );
					$step = 'weight' === $field['type'] ? 100 : 1;
					echo '<input type="number"' . $common . ' min="' . (int) $min . '" max="' . (int) $field['max'] . '" step="' . (int) $step . '" placeholder="Actual">';
				}
			}
			echo '</div></details>';
		}
		echo '</section>';
	}

	/** Ignore unknown keys and invalid input; blank values mean no CSS override. */
	public static function sanitize( $input ) {
		$input = is_array( $input ) ? $input : [];
		$clean = [];
		foreach ( self::schema() as $group ) {
			foreach ( $group['fields'] as $field ) {
				$key = $field['key'];
				if ( ! isset( $input[$key] ) || ! is_scalar( $input[$key] ) ) { continue; }
				$value = trim( (string) $input[$key] );
				if ( '' === $value ) { continue; }
				if ( 'color' === $field['type'] ) {
					$value = sanitize_hex_color( $value );
					if ( ! $value ) { continue; }
				} elseif ( 'font' === $field['type'] ) {
					// Restrict CSS syntax, allowing simple names and comma-separated fallbacks.
					if ( strlen( $value ) > 160 || ! preg_match( '/^[a-zA-Z][a-zA-Z0-9 ,_-]*$/D', $value ) ) { continue; }
				} else {
					if ( ! is_numeric( $value ) ) { continue; }
					$min = 'weight' === $field['type'] ? 100 : ( 'font-size' === $field['property'] ? 1 : 0 );
					$value = min( $field['max'], max( $min, (int) $value ) );
					if ( 'weight' === $field['type'] ) { $value = (int) ( round( $value / 100 ) * 100 ); }
				}
				$clean[$key] = $value;
			}
		}
		return $clean;
	}

	public static function frontend_css() {
		$is_supported_page = ( function_exists( 'is_cart' ) && is_cart() )
			|| ( function_exists( 'is_checkout' ) && is_checkout() )
			|| ( function_exists( 'is_account_page' ) && is_account_page() )
			|| ( function_exists( 'is_shop' ) && is_shop() )
			|| ( function_exists( 'is_order_received_page' ) && is_order_received_page() );
		if ( ! $is_supported_page ) { return; }
		if ( ! wp_style_is( 'pllc-frontend', 'enqueued' ) ) {
			wp_enqueue_style( 'pllc-frontend', PLLC_URL . 'assets/css/pllc-frontend.css', [], PLLC_VERSION );
		}
		$settings = PLLC_Styles::get();
		$values = self::sanitize( $settings['order_received'] ?? [] );
		$css = '';
		foreach ( self::schema() as $group ) {
			$declarations = '';
			foreach ( $group['fields'] as $field ) {
				if ( ! array_key_exists( $field['key'], $values ) ) { continue; }
				$value = $values[$field['key']];
				$unit = 'number' === $field['type'] ? 'px' : '';
				$declarations .= $field['property'] . ':' . $value . $unit . '!important;';
				if ( 'border-bottom-width' === $field['property'] ) {
					$declarations .= 'border-bottom-style:solid!important;';
				}
			}
			if ( '' !== $declarations ) { $css .= $group['selector'] . '{' . $declarations . '}'; }
		}
		if ( '' !== $css ) { wp_add_inline_style( 'pllc-frontend', $css ); }
	}
}
