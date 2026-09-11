<?php
/** Presentación de correos; WooCommerce conserva el envío, la envoltura y los cálculos. */
defined( 'ABSPATH' ) || exit;

class PLLC_Emails {

	use PLLC_Order_Presentation;

	private static $order_stack = [];

	public static function init() {
		add_filter( 'wc_get_template', [ __CLASS__, 'locate_order_details' ], 20, 5 );
		add_filter( 'woocommerce_email_styles', [ __CLASS__, 'email_styles' ], 20, 2 );
	}

	/** Sustituye únicamente el detalle de pedido, en sus dos formatos. */
	public static function locate_order_details( $located, $name, $args, $template_path = '', $default_path = '' ) {
		if ( in_array( $name, [ 'emails/email-order-details.php', 'emails/plain/email-order-details.php' ], true )
			&& isset( $args['order'] ) && is_a( $args['order'], 'WC_Order' ) ) {
			return PLLC_PATH . 'templates/emails/order-details.php';
		}
		return $located;
	}

	public static function email_styles( $css, $email = null ) {
		return $css . "\n" . file_get_contents( PLLC_PATH . 'assets/css/pllc-emails.css' );
	}

	/** La pila admite renderizados anidados y se restaura incluso ante una excepción. */
	public static function is_rendering( $order_id = null ) {
		if ( ! self::$order_stack ) {
			return false;
		}
		return null === $order_id || (int) end( self::$order_stack ) === (int) $order_id;
	}

	public static function render( $order, $sent_to_admin = false, $plain_text = false, $email = null ) {
		if ( ! is_a( $order, 'WC_Order' ) ) {
			return;
		}
		self::$order_stack[] = $order->get_id();
		try {
			do_action( 'woocommerce_email_before_order_table', $order, $sent_to_admin, $plain_text, $email );
			$view = self::prepare( $order, $sent_to_admin, $plain_text, $email );
			self::template( $plain_text ? 'plain/order-content.php' : 'order-content.php', [ 'view' => $view ] );
			do_action( 'woocommerce_email_after_order_table', $order, $sent_to_admin, $plain_text, $email );
		} finally {
			array_pop( self::$order_stack );
		}
	}

	public static function template( $name, array $args ) {
		wc_get_template( 'emails/' . $name, $args, 'panza-llena-core/', PLLC_PATH . 'templates/' );
	}

	private static function prepare( $order, $sent_to_admin, $plain_text, $email ) {
		$args = apply_filters( 'woocommerce_email_order_items_args', [
			'order' => $order,
			'items' => $order->get_items( 'line_item' ),
			'show_download_links' => $order->is_download_permitted() && ! $sent_to_admin,
			'show_sku' => false,
			'show_purchase_note' => $order->is_paid() && ! $sent_to_admin,
			'show_image' => ! $plain_text,
			'image_size' => [ 48, 48 ],
			'plain_text' => $plain_text,
			'sent_to_admin' => $sent_to_admin,
		] );
		$items = array_filter( $args['items'], static function ( $item ) {
			return is_a( $item, 'WC_Order_Item_Product' ) && apply_filters( 'woocommerce_order_item_visible', true, $item );
		} );
		$items = PLLC_Item_Order::sort( $items, static function ( $item ) {
			$day = sanitize_key( (string) $item->get_meta( '_pllc_delivery_day', true ) );
			$index = PLLC_Item_Order::day_index( $day );
			$date = (string) $item->get_meta( '_pllc_delivery_date', true );
			if ( 99 === $index && preg_match( '/^\d{4}-\d{2}-\d{2}$/D', $date ) && strtotime( $date ) ) {
				$index = (int) gmdate( 'N', strtotime( $date . ' UTC' ) );
			}
			$meals = $item->get_meta( '_pllc_meals', true );
			return [ 'type' => self::get_item_form_type( $item ), 'form' => self::get_item_form( $item ), 'day_order' => $index, 'meals' => is_array( $meals ) ? $meals : [ $meals ] ];
		} );
		$groups = [];
		foreach ( $items as $item_id => $item ) {
			$type = self::get_item_form_type( $item );
			$form = self::get_item_form( $item );
			$key = self::get_group_key( $type, $form );
			if ( ! isset( $groups[ $key ] ) ) {
				$fields = [];
				foreach ( self::summary_labels() as $field => $label ) {
					if ( ! empty( $form[ $field ] ) ) {
						$fields[] = $label . ': ' . sanitize_text_field( $form[ $field ] );
					}
				}
				$groups[ $key ] = [
					'heading' => self::build_group_label( $type, $form ),
					'fields' => $fields,
					'observations' => self::get_kitchen_observations( $form ),
					'context' => self::build_group_context( $type, $form ),
					'show_prices' => ! in_array( $type, [ 'iteo_personal', 'iteo_pacientes' ], true ),
					'items' => [],
				];
			}
			$groups[ $key ]['items'][] = self::prepare_item( $item_id, $item, $order, $args, $groups[ $key ]['show_prices'], $plain_text );
		}
		$date_created = $order->get_date_created();
		$is_mixed = self::is_mixed_order( $order );
		return [
			'heading' => apply_filters( 'woocommerce_email_order_details_heading', $is_mixed ? __( 'Detalle del pedido mixto', 'panza-llena-core' ) : __( 'Detalle del pedido', 'panza-llena-core' ), $order, $email ),
			'number' => apply_filters( 'woocommerce_email_display_order_number', true, $order, $email ) ? $order->get_order_number() : '',
			'date' => $date_created ? wc_format_datetime( $date_created ) : '',
			'admin_url' => $sent_to_admin ? $order->get_edit_order_url() : '',
			'mixed_title' => $is_mixed ? self::build_mixed_title( $order ) : '',
			'mixed_explanation' => $is_mixed ? self::build_delivery_explanation( $order ) : '',
			'groups' => $groups,
			'totals' => self::is_iteo_only_order( $order ) ? [] : $order->get_order_item_totals(),
			'note' => $order->get_customer_note() ? wc_wptexturize_order_note( $order->get_customer_note() ) : '',
		];
	}

	private static function prepare_item( $item_id, $item, $order, $args, $show_prices, $plain_text ) {
		$product = $item->get_product();
		$image = '';
		if ( ! empty( $args['show_image'] ) && $product ) {
			$image = $product->get_image( [ 48, 48 ], [ 'style' => 'display:block;width:48px;height:48px;border:0;', 'alt' => $item->get_name() ] );
			$image = apply_filters( 'woocommerce_order_item_thumbnail', $image, $item );
		}
		$name = apply_filters( 'woocommerce_order_item_name', $item->get_name(), $item, false );
		$quantity = $item->get_quantity();
		$refunded = $order->get_qty_refunded_for_item( $item_id );
		$quantity_html = $refunded
			? '<del>' . esc_html( $quantity ) . '</del> <ins>' . esc_html( $quantity + $refunded ) . '</ins>'
			: esc_html( $quantity );
		$quantity_html = apply_filters( 'woocommerce_email_order_item_quantity', $quantity_html, $item );
		$quantity_text = $refunded ? sprintf( __( '%1$s → %2$s (reembolso)', 'panza-llena-core' ), $quantity, $quantity + $refunded ) : self::plain( $quantity_html );
		$level = ob_get_level();
		ob_start();
		try {
			do_action( 'woocommerce_order_item_meta_start', $item_id, $item, $order, $plain_text );
			wc_display_item_meta( $item, [ 'before' => '', 'after' => '', 'separator' => '<br>', 'label_before' => '<strong>', 'label_after' => ':</strong> ' ] );
			do_action( 'woocommerce_order_item_meta_end', $item_id, $item, $order, $plain_text );
			$meta = ob_get_contents();
		} finally {
			while ( ob_get_level() > $level ) {
				ob_end_clean();
			}
		}
		$purchase_note = ! empty( $args['show_purchase_note'] ) && $product ? $product->get_purchase_note() : '';
		return [
			'name' => $name,
			'day' => self::get_item_day_label( $item ),
			'meal' => self::get_item_meal_label( $item ),
			'image' => $image,
			'quantity' => $quantity_html,
			'quantity_text' => $quantity_text,
			'amount' => $show_prices ? $order->get_formatted_line_subtotal( $item ) : '',
			'meta' => $meta,
			'purchase_note' => $purchase_note ? wpautop( do_shortcode( $purchase_note ) ) : '',
		];
	}

	/** Conserva saltos legibles y decodifica entidades en la alternativa de texto. */
	public static function plain( $value ) {
		$value = preg_replace( '/<br\s*\/?\s*>|<\/(?:p|div|li|tr)>/i', "\n", (string) $value );
		return trim( html_entity_decode( wp_strip_all_tags( $value ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
	}
}
