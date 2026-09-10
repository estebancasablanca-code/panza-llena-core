<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Presentación agrupada del pedido en Gracias y Mi cuenta > Ver pedido. */
class PLLC_Order_Details {

	private static $last_group_key = null;
	private static $last_email_group_key = null;
	private static $email_order_id = 0;
	private static $email_plain_text = false;
	private static $admin_group_state = [];

	public static function init() {
		add_action( 'woocommerce_order_details_before_order_table', [ __CLASS__, 'reset_group_state' ], 5 );
		add_action( 'woocommerce_order_details_before_order_table', [ __CLASS__, 'render_customer_mixed_notice' ], 6 );
		add_action( 'woocommerce_email_before_order_table', [ __CLASS__, 'begin_email_context' ], 5, 4 );
		add_action( 'woocommerce_email_after_order_table', [ __CLASS__, 'end_email_context' ], 999, 4 );
		add_filter( 'woocommerce_email_order_details_heading', [ __CLASS__, 'filter_email_order_heading' ], 20, 3 );
		add_action( 'woocommerce_before_order_item_line_item_html', [ __CLASS__, 'render_admin_group_header' ], 5, 3 );
		add_filter( 'woocommerce_admin_html_order_item_class', [ __CLASS__, 'add_admin_item_class' ], 20, 3 );
		add_action( 'woocommerce_admin_order_item_headers', [ __CLASS__, 'render_admin_delivery_status_header' ], 30, 1 );
		add_action( 'woocommerce_admin_order_item_values', [ __CLASS__, 'render_admin_delivery_status_value' ], 30, 3 );
		add_action( 'woocommerce_admin_order_data_after_order_details', [ __CLASS__, 'render_admin_mixed_summary' ], 20 );
		add_action( 'add_meta_boxes', [ __CLASS__, 'register_admin_scope_meta_box' ], 30, 2 );
		add_action( 'admin_enqueue_scripts', [ __CLASS__, 'enqueue_admin_assets' ] );
		add_filter( 'woocommerce_admin_billing_fields', [ __CLASS__, 'rename_admin_phone_field' ], 20, 3 );
		add_filter( 'woocommerce_admin_shipping_fields', [ __CLASS__, 'rename_admin_phone_field' ], 20, 3 );
		add_filter( 'manage_edit-shop_order_columns', [ __CLASS__, 'add_order_type_column' ], 31 );
		add_action( 'manage_shop_order_posts_custom_column', [ __CLASS__, 'render_legacy_order_type_column' ], 31, 2 );
		add_filter( 'manage_woocommerce_page_wc-orders_columns', [ __CLASS__, 'add_order_type_column' ], 31 );
		add_action( 'manage_woocommerce_page_wc-orders_custom_column', [ __CLASS__, 'render_hpos_order_type_column' ], 31, 2 );
		add_filter( 'woocommerce_order_item_name', [ __CLASS__, 'format_item_name' ], 20, 3 );
		add_filter( 'woocommerce_order_item_permalink', [ __CLASS__, 'filter_item_permalink' ], 20, 3 );
		add_filter( 'woocommerce_order_item_class', [ __CLASS__, 'add_item_class' ], 10, 3 );
		add_filter( 'woocommerce_order_formatted_line_subtotal', [ __CLASS__, 'maybe_hide_line_subtotal' ], 20, 3 );
		add_filter( 'woocommerce_get_order_item_totals', [ __CLASS__, 'maybe_hide_order_totals' ], 20, 3 );
		add_filter( 'body_class', [ __CLASS__, 'add_body_classes' ] );
	}

	public static function reset_group_state( $order ) {
		self::$last_group_key = null;
	}

	/** Prepara el contexto que usa el filtro de nombres dentro del correo. */
	public static function begin_email_context( $order, $sent_to_admin, $plain_text, $email ) {
		self::$last_email_group_key = null;
		self::$email_order_id        = is_a( $order, 'WC_Order' ) ? $order->get_id() : 0;
		self::$email_plain_text      = (bool) $plain_text;

		if ( ! is_a( $order, 'WC_Order' ) || ! self::is_mixed_order( $order ) ) {
			return;
		}

		$title       = self::build_mixed_title( $order );
		$explanation = self::build_delivery_explanation( $order );

		if ( $plain_text ) {
			echo "\n" . strtoupper( $title ) . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			echo $explanation . "\n\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			return;
		}

		echo '<div class="pllc-email-mixed-notice" style="margin:0 0 20px;padding:14px 16px;background:#f4f8f5;border-left:4px solid #1f6b45;">';
		echo '<strong style="display:block;margin-bottom:5px;">' . esc_html( $title ) . '</strong>';
		echo '<span>' . esc_html( $explanation ) . '</span>';
		echo '</div>';
	}

	/** Limpia el contexto para no afectar otras tablas renderizadas después. */
	public static function end_email_context( $order, $sent_to_admin, $plain_text, $email ) {
		self::$last_email_group_key = null;
		self::$email_order_id        = 0;
		self::$email_plain_text      = false;
	}

	public static function filter_email_order_heading( $heading, $order, $email ) {
		return is_a( $order, 'WC_Order' ) && self::is_mixed_order( $order )
			? __( 'Resumen del pedido mixto', 'panza-llena-core' )
			: $heading;
	}

	/** Aviso principal en Gracias y Mi cuenta > Ver pedido. */
	public static function render_customer_mixed_notice( $order ) {
		if ( ! is_a( $order, 'WC_Order' ) || ! self::is_mixed_order( $order ) ) {
			return;
		}

		echo '<div class="pllc-mixed-order-notice">';
		echo '<strong>' . esc_html( self::build_mixed_title( $order ) ) . '</strong>';
		echo '<p>' . esc_html( self::build_delivery_explanation( $order ) ) . '</p>';
		echo '</div>';
	}

	/** Inserta una fila separadora real antes de cada grupo en el editor. */
	public static function render_admin_group_header( $item_id, $item, $order ) {
		if ( ! is_a( $item, 'WC_Order_Item_Product' ) || ! is_a( $order, 'WC_Order' ) ) {
			return;
		}

		$order_id  = $order->get_id();
		$form_type = self::get_item_form_type( $item );
		$form      = self::get_item_form( $item );
		$group_key = self::get_group_key( $form_type, $form );
		$last_key  = isset( self::$admin_group_state[ $order_id ] ) ? self::$admin_group_state[ $order_id ] : null;

		if ( $last_key === $group_key ) {
			return;
		}

		self::$admin_group_state[ $order_id ] = $group_key;
		$header  = self::build_admin_group_label( $form_type, $form );
		$summary = self::build_summary_line( $form );
		$observations = self::get_kitchen_observations( $form );
		$context = self::build_group_context( $form_type, $form );

		echo '<tr class="pllc-admin-order-group-header"><td colspan="100">';
		echo '<strong>' . esc_html( $header ) . '</strong>';
		if ( $summary ) {
			echo '<span class="pllc-admin-order-group-summary">' . wp_kses_post( $summary ) . '</span>';
		}
		if ( $observations ) {
			echo '<span class="pllc-admin-order-group-observations"><strong>' . esc_html__( 'Observaciones para la cocina', 'panza-llena-core' ) . ':</strong> ' . esc_html( $observations ) . '</span>';
		}
		if ( $context ) {
			echo '<span class="pllc-admin-order-group-context">' . esc_html( $context ) . '</span>';
		}
		echo '</td></tr>';
	}

	/** Marca las líneas ITEO para reemplazar visualmente $0 por No facturado. */
	public static function add_admin_item_class( $class, $item, $order ) {
		if ( is_a( $item, 'WC_Order_Item_Product' ) ) {
			$form_type = self::get_item_form_type( $item );
			$class    .= ' pllc-admin-form-type-' . sanitize_html_class( $form_type );
			if ( in_array( $form_type, [ 'iteo_personal', 'iteo_pacientes' ], true ) ) {
				$class .= ' pllc-admin-item-unbilled';
			}
		}
		return $class;
	}

	/** Columna informativa vinculada a las unidades operativas de Entregas. */
	public static function render_admin_delivery_status_header( $order ) {
		echo '<th class="pllc-admin-delivery-status-column">' . esc_html__( 'Estado de entrega', 'panza-llena-core' ) . '</th>';
	}

	public static function render_admin_delivery_status_value( $product, $item, $item_id ) {
		echo '<td class="pllc-admin-delivery-status-column">';
		if ( is_a( $item, 'WC_Order_Item_Product' ) && class_exists( 'PLLC_Deliveries' ) ) {
			echo PLLC_Deliveries::get_order_item_status_badge( $item->get_order_id(), $item_id ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		} else {
			echo '<span aria-hidden="true">—</span>';
		}
		echo '</td>';
	}

	/**
	 * Construye un resumen estructurado para todas las modalidades del pedido.
	 * El bloque se imprime dentro de la columna General y el script administrativo
	 * lo reubica junto a ella, conservando disponibles los editores nativos.
	 */
	public static function render_admin_mixed_summary( $order ) {
		if ( ! is_a( $order, 'WC_Order' ) || ! self::uses_structured_admin_layout( $order ) ) {
			return;
		}

		$types       = self::get_order_form_types( $order );
		$is_mixed    = self::is_mixed_order( $order );
		$has_particular  = in_array( 'particular', $types, true );
		$has_institution = count( array_diff( $types, [ 'particular' ] ) ) > 0;
		$buyer_name = trim( $order->get_formatted_billing_full_name() );
		$email      = sanitize_email( $order->get_billing_email() );
		$whatsapp   = sanitize_text_field( $order->get_billing_phone() );
		$address    = $order->get_formatted_shipping_address();
		if ( ! $address ) {
			$address = $order->get_formatted_billing_address();
		}

		echo '<section id="pllc-admin-mixed-order-panel" class="pllc-admin-mixed-order-panel">';
		echo '<div class="pllc-admin-mixed-order-heading">';
		if ( $is_mixed ) {
			$heading = __( 'Datos del pedido mixto', 'panza-llena-core' );
		} elseif ( $has_institution ) {
			$heading = __( 'Datos del pedido institucional', 'panza-llena-core' );
		} else {
			$heading = __( 'Datos del pedido particular', 'panza-llena-core' );
		}
		echo '<h3>' . esc_html( $heading ) . '</h3>';
		echo '<span class="pllc-order-type-badge">' . esc_html( self::build_order_type_label( $order ) ) . '</span>';
		echo '</div>';
		echo '<div class="pllc-admin-mixed-order-cards' . ( $is_mixed ? '' : ' pllc-admin-mixed-order-cards--single' ) . '">';

		if ( $is_mixed ) {
			echo '<div class="pllc-admin-order-data-card pllc-admin-order-data-card--buyer">';
			echo '<h4>' . esc_html__( 'Responsable de la compra', 'panza-llena-core' ) . '</h4>';
			self::render_admin_data_value( __( 'Nombre', 'panza-llena-core' ), $buyer_name );
			self::render_admin_data_value( __( 'Correo electrónico', 'panza-llena-core' ), $email, 'email' );
			self::render_admin_data_value( __( 'WhatsApp', 'panza-llena-core' ), $whatsapp, 'phone' );
			echo '<p class="pllc-admin-card-note">' . esc_html__( 'Recibe los correos del pedido completo.', 'panza-llena-core' ) . '</p>';
			echo '</div>';
		}

		if ( $has_institution ) {
			self::render_admin_institution_card( $order, $buyer_name, ! $is_mixed );
		}

		if ( $has_particular ) {
			self::render_admin_particular_card( $order, $buyer_name, $email, $whatsapp, $address, ! $is_mixed );
		}

		echo '</div>';
		if ( $is_mixed ) {
			$scope_text = __( 'Las entregas se gestionan por separado. El estado, la cancelación y los correos afectan al pedido completo.', 'panza-llena-core' );
			$open_label = __( 'Editar facturación y envío', 'panza-llena-core' );
			$close_label = __( 'Ocultar edición de facturación y envío', 'panza-llena-core' );
		} elseif ( $has_institution && self::is_iteo_only_order( $order ) ) {
			$scope_text = __( 'La preparación y la entrega se administran desde Entregas. Este pedido no se factura en WooCommerce.', 'panza-llena-core' );
			$open_label = __( 'Editar datos de contacto', 'panza-llena-core' );
			$close_label = __( 'Ocultar edición de datos de contacto', 'panza-llena-core' );
		} elseif ( $has_institution ) {
			$scope_text = __( 'La preparación y la entrega se administran desde Entregas. El importe del pedido se factura en WooCommerce.', 'panza-llena-core' );
			$open_label = __( 'Editar facturación y envío', 'panza-llena-core' );
			$close_label = __( 'Ocultar edición de facturación y envío', 'panza-llena-core' );
		} else {
			$scope_text = __( 'La preparación y la entrega a domicilio se administran desde Entregas. El importe del pedido se factura en WooCommerce.', 'panza-llena-core' );
			$open_label = __( 'Editar facturación y envío', 'panza-llena-core' );
			$close_label = __( 'Ocultar edición de facturación y envío', 'panza-llena-core' );
		}
		echo '<p class="pllc-admin-mixed-order-scope">' . esc_html( $scope_text ) . '</p>';
		echo '<button type="button" class="button-link pllc-toggle-native-order-data" aria-expanded="false" data-open-label="' . esc_attr( $open_label ) . '" data-close-label="' . esc_attr( $close_label ) . '">' . esc_html( $open_label ) . '</button>';
		echo '</section>';
	}

	public static function enqueue_admin_assets( $hook ) {
		$screen    = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		$screen_id = $screen ? $screen->id : '';
		if ( ! in_array( $screen_id, [ 'shop_order', 'woocommerce_page_wc-orders' ], true ) ) {
			return;
		}

		$order = self::resolve_admin_order();
		if ( ! $order || ! self::uses_structured_admin_layout( $order ) ) {
			return;
		}

		wp_enqueue_script(
			'pllc-admin-order-details',
			PLLC_URL . 'assets/js/pllc-admin-order-details.js',
			[],
			PLLC_VERSION,
			true
		);
	}

	/** Mantiene una única denominación para el dato capturado en checkout. */
	public static function rename_admin_phone_field( $fields, $order = false, $context = 'edit' ) {
		if ( isset( $fields['phone'] ) ) {
			$fields['phone']['label'] = __( 'WhatsApp', 'panza-llena-core' );
		}
		return $fields;
	}

	/** Agrega la modalidad sin reemplazar el nombre del comprador. */
	public static function add_order_type_column( $columns ) {
		if ( isset( $columns['pllc_order_type'] ) ) {
			return $columns;
		}

		$new      = [];
		$inserted = false;
		foreach ( $columns as $key => $label ) {
			$new[ $key ] = $label;
			if ( ! $inserted && in_array( $key, [ 'order_number', 'order_title' ], true ) ) {
				$new['pllc_order_type'] = __( 'Tipo de pedido', 'panza-llena-core' );
				$inserted = true;
			}
		}

		if ( ! $inserted ) {
			$new = [];
			foreach ( $columns as $key => $label ) {
				if ( 'order_date' === $key ) {
					$new['pllc_order_type'] = __( 'Tipo de pedido', 'panza-llena-core' );
					$inserted = true;
				}
				$new[ $key ] = $label;
			}
		}

		if ( ! $inserted ) {
			$new['pllc_order_type'] = __( 'Tipo de pedido', 'panza-llena-core' );
		}

		return $new;
	}

	public static function render_legacy_order_type_column( $column, $post_id ) {
		if ( 'pllc_order_type' === $column ) {
			self::render_order_type_column( wc_get_order( $post_id ) );
		}
	}

	public static function render_hpos_order_type_column( $column, $order ) {
		if ( 'pllc_order_type' !== $column ) {
			return;
		}
		self::render_order_type_column( is_a( $order, 'WC_Order' ) ? $order : wc_get_order( $order ) );
	}

	private static function render_order_type_column( $order ) {
		if ( ! is_a( $order, 'WC_Order' ) ) {
			echo '—';
			return;
		}

		echo '<span class="pllc-order-type-badge">' . esc_html( self::build_order_type_label( $order ) ) . '</span>';
		if ( self::is_iteo_mixed_order( $order ) ) {
			echo '<small class="pllc-order-type-total-scope">' . esc_html__( 'Total: sólo Particular', 'panza-llena-core' ) . '</small>';
		}
	}

	/** Agrega un recordatorio junto a las acciones globales del pedido. */
	public static function register_admin_scope_meta_box( $post_type, $post_or_order ) {
		$order = self::resolve_admin_order( $post_or_order );
		if ( ! $order || ! self::is_mixed_order( $order ) ) {
			return;
		}

		$screens = [ 'shop_order' ];
		if ( function_exists( 'wc_get_page_screen_id' ) ) {
			$screens[] = wc_get_page_screen_id( 'shop-order' );
		}

		foreach ( array_unique( $screens ) as $screen ) {
			add_meta_box( 'pllc-order-action-scope', 'Alcance de las acciones', [ __CLASS__, 'render_admin_scope_meta_box' ], $screen, 'side', 'high' );
		}
	}

	public static function render_admin_scope_meta_box( $object ) {
		$order = self::resolve_admin_order( $object );
		if ( ! $order ) {
			return;
		}

		$email = sanitize_email( $order->get_billing_email() );
		echo '<div class="pllc-admin-action-scope">';
		echo '<p><strong>Enviar correo:</strong> incluye el pedido completo y se envía a ' . ( $email ? '<code>' . esc_html( $email ) . '</code>' : 'la dirección del comprador' ) . '.</p>';
		echo '<p><strong>Estado y cancelación:</strong> afectan al pedido completo.</p>';
		echo '<p><strong>Reembolso:</strong> es una acción financiera y sólo contempla los importes facturados; no reemplaza la gestión individual de las entregas.</p>';
		echo '<p><strong>Preparación y entrega:</strong> se administran por separado desde el bloque <em>Entregas</em>.</p>';
		echo '</div>';
	}

	/** Mantiene el mismo criterio de enlaces que el carrito, solo en el detalle público. */
	public static function filter_item_permalink( $permalink, $item, $order ) {
		if ( is_admin() || self::$email_order_id || ! self::is_customer_order_details_screen()
			|| ! is_a( $item, 'WC_Order_Item_Product' ) ) {
			return $permalink;
		}

		return PLLC_Access::is_particular_product( $item->get_product_id() ) ? $permalink : '';
	}

	public static function format_item_name( $product_name, $item, $is_visible ) {
		if ( ! is_a( $item, 'WC_Order_Item_Product' ) ) {
			return $product_name;
		}

		$form_type = self::get_item_form_type( $item );
		$form      = self::get_item_form( $item );
		$day       = self::get_item_day_label( $item );

		if ( self::$email_order_id && absint( $item->get_order_id() ) === self::$email_order_id ) {
			$order = wc_get_order( self::$email_order_id );
			if ( ! $order || ! self::is_mixed_order( $order ) ) {
				if ( ! $day ) {
					return $product_name;
				}
				if ( self::$email_plain_text ) {
					return sprintf( __( 'Día %s', 'panza-llena-core' ), $day ) . "\n" . $product_name;
				}
				return '<span style="display:block;font-size:12px;font-weight:600;margin-bottom:3px;">'
					. esc_html( sprintf( __( 'Día %s', 'panza-llena-core' ), $day ) ) . '</span>' . $product_name;
			}

			$group_key = self::get_group_key( $form_type, $form );
			$prefix    = '';
			if ( self::$last_email_group_key !== $group_key ) {
				self::$last_email_group_key = $group_key;
				$header  = self::build_group_label( $form_type, $form );
				$summary = self::build_summary_text( $form );
				$observations = self::get_kitchen_observations( $form );
				$context = self::build_group_context( $form_type, $form );

				if ( self::$email_plain_text ) {
					$prefix = "\n" . strtoupper( $header ) . "\n";
					if ( $summary ) {
						$prefix .= $summary . "\n";
					}
					if ( $observations ) {
						$prefix .= __( 'Observaciones para la cocina', 'panza-llena-core' ) . ': ' . $observations . "\n";
					}
					if ( $context ) {
						$prefix .= $context . "\n";
					}
				} else {
					$prefix = '<span class="pllc-email-order-group" style="display:block;margin:14px 0 8px;padding:9px 11px;background:#f7f4ed;border-left:3px solid #d97706;">';
					$prefix .= '<strong style="display:block;">' . esc_html( $header ) . '</strong>';
					if ( $summary ) {
						$prefix .= '<small style="display:block;margin-top:3px;">' . esc_html( $summary ) . '</small>';
					}
					if ( $observations ) {
						$prefix .= '<small style="display:block;margin-top:3px;"><strong>' . esc_html__( 'Observaciones para la cocina', 'panza-llena-core' ) . ':</strong> ' . esc_html( $observations ) . '</small>';
					}
					if ( $context ) {
						$prefix .= '<small style="display:block;margin-top:3px;">' . esc_html( $context ) . '</small>';
					}
					$prefix .= '</span>';
				}
			}

			if ( $day ) {
				if ( self::$email_plain_text ) {
					$prefix .= sprintf( __( 'Día %s', 'panza-llena-core' ), $day ) . "\n";
				} else {
					$prefix .= '<span style="display:block;font-size:12px;font-weight:600;margin-bottom:3px;">' . esc_html( sprintf( __( 'Día %s', 'panza-llena-core' ), $day ) ) . '</span>';
				}
			}

			return $prefix . $product_name;
		}

		if ( is_admin() ) {
			if ( $day ) {
				return '<span class="pllc-admin-order-day">' . esc_html( sprintf( __( 'Día %s', 'panza-llena-core' ), $day ) ) . '</span>' . $product_name;
			}
			return $product_name;
		}

		if ( ! self::is_customer_order_details_screen() ) {
			return $product_name;
		}

		// También cubre nombres o imágenes enlazados por plantillas personalizadas.
		if ( ! PLLC_Access::is_particular_product( $item->get_product_id() ) ) {
			$allowed_html = wp_kses_allowed_html( 'post' );
			unset( $allowed_html['a'] );
			$product_name = wp_kses( $product_name, $allowed_html );
		}

		$group_key = self::get_group_key( $form_type, $form );
		$prefix    = '';

		if ( self::$last_group_key !== $group_key ) {
			self::$last_group_key = $group_key;
			$header  = self::build_group_label( $form_type, $form );
			$summary = self::build_summary_line( $form );
			$observations = self::get_kitchen_observations( $form );
			$context = self::build_group_context( $form_type, $form );
			$prefix  = '<div class="pllc-order-detail-group-header"><div class="pllc-cart-group-header">' . esc_html( $header ) . '</div>';
			if ( $summary ) {
				$prefix .= '<div class="pllc-cart-group-summary">' . $summary . '</div>';
			}
			if ( $observations ) {
				$prefix .= '<div class="pllc-kitchen-observations"><strong>' . esc_html__( 'Observaciones para la cocina', 'panza-llena-core' ) . ':</strong> ' . esc_html( $observations ) . '</div>';
			}
			if ( $context ) {
				$prefix .= '<div class="pllc-order-group-context">' . esc_html( $context ) . '</div>';
			}
			$prefix .= '</div>';
		}

		if ( $day ) {
			$prefix .= '<div class="pllc-cart-day-label">' . esc_html( sprintf( __( 'Día %s', 'panza-llena-core' ), $day ) ) . '</div>';
		}

		return $prefix . $product_name;
	}

	public static function add_item_class( $class, $item, $order ) {
		$is_email_item = self::$email_order_id && is_a( $item, 'WC_Order_Item_Product' ) && absint( $item->get_order_id() ) === self::$email_order_id;
		if ( ( self::is_customer_order_details_screen() || $is_email_item ) && is_a( $item, 'WC_Order_Item_Product' ) ) {
			$class .= ' pllc-form-type-' . sanitize_html_class( self::get_item_form_type( $item ) );
		}
		return $class;
	}

	public static function maybe_hide_line_subtotal( $subtotal, $item, $order ) {
		$is_email_item = self::$email_order_id && is_a( $item, 'WC_Order_Item_Product' ) && absint( $item->get_order_id() ) === self::$email_order_id;
		if ( ( ! self::is_customer_order_details_screen() && ! $is_email_item ) || ! is_a( $item, 'WC_Order_Item_Product' ) ) {
			return $subtotal;
		}

		return in_array( self::get_item_form_type( $item ), [ 'iteo_personal', 'iteo_pacientes' ], true )
			? esc_html__( 'No facturado', 'panza-llena-core' )
			: $subtotal;
	}

	public static function maybe_hide_order_totals( $rows, $order, $tax_display ) {
		$is_email_order = self::$email_order_id && is_a( $order, 'WC_Order' ) && $order->get_id() === self::$email_order_id;
		return ( ( self::is_customer_order_details_screen() || $is_email_order ) && self::is_iteo_only_order( $order ) ) ? [] : $rows;
	}

	public static function add_body_classes( $classes ) {
		if ( ! self::is_customer_order_details_screen() ) {
			return $classes;
		}

		$order = self::get_current_order();
		if ( ! $order ) {
			return $classes;
		}

		if ( $order->get_items( 'line_item' ) ) {
			$classes[] = 'pllc-grouped-order-details';
		}
		if ( self::is_iteo_only_order( $order ) ) {
			$classes[] = 'pllc-iteo-only-order-details';
		}
		if ( self::is_mixed_order( $order ) ) {
			$classes[] = 'pllc-mixed-order-details';
		}
		return $classes;
	}

	private static function is_customer_order_details_screen() {
		return ( function_exists( 'is_order_received_page' ) && is_order_received_page() )
			|| ( function_exists( 'is_wc_endpoint_url' ) && is_wc_endpoint_url( 'view-order' ) );
	}

	private static function get_current_order() {
		$order_id = absint( get_query_var( 'order-received' ) );
		if ( ! $order_id ) {
			$order_id = absint( get_query_var( 'view-order' ) );
		}
		return $order_id ? wc_get_order( $order_id ) : false;
	}

	private static function get_item_form_type( $item ) {
		$type = sanitize_key( (string) $item->get_meta( '_pllc_form_type', true ) );
		if ( in_array( $type, [ 'colegios', 'iteo_personal', 'iteo_pacientes', 'particular' ], true )
			&& ( 'particular' !== $type || $item->meta_exists( '_pllc_form_type' ) ) ) {
			return $type;
		}

		$slugs = wc_get_product_terms( $item->get_product_id(), 'product_cat', [ 'fields' => 'slugs' ] );
		if ( ! is_wp_error( $slugs ) ) {
			foreach ( $slugs as $slug ) {
				if ( false !== strpos( $slug, 'iteo-personal' ) ) {
					return 'iteo_personal';
				}
				if ( false !== strpos( $slug, 'iteo-pacientes' ) ) {
					return 'iteo_pacientes';
				}
				if ( false !== strpos( $slug, 'colegios' ) ) {
					return 'colegios';
				}
			}
		}

		return 'particular';
	}

	private static function get_item_form( $item ) {
		$form = $item->get_meta( '_pllc_form', true );
		return is_array( $form ) ? $form : [];
	}

	private static function get_group_key( $form_type, $form ) {
		if ( ! empty( $form['nombre_alumno'] ) ) {
			$colegio = isset( $form['colegio'] ) ? $form['colegio'] : '';
			return 'nombre:' . strtolower( trim( $form['nombre_alumno'] ) ) . '|' . strtolower( trim( $colegio ) );
		}
		return 'tipo:' . $form_type;
	}

	private static function build_admin_group_label( $form_type, $form ) {
		if ( 'particular' === $form_type ) {
			return __( 'Pedido particular', 'panza-llena-core' );
		}
		return self::build_group_label( $form_type, $form );
	}

	private static function build_group_label( $form_type, $form ) {
		if ( ! empty( $form['nombre_alumno'] ) ) {
			return sprintf( __( 'Pedido para %s', 'panza-llena-core' ), $form['nombre_alumno'] );
		}
		$labels = [
			'iteo_personal'  => __( 'Pedido para ITEO Personal', 'panza-llena-core' ),
			'iteo_pacientes' => __( 'Pedido para ITEO Pacientes', 'panza-llena-core' ),
			'particular'     => __( 'Pedido para mí', 'panza-llena-core' ),
		];
		return isset( $labels[ $form_type ] ) ? $labels[ $form_type ] : __( 'Pedido', 'panza-llena-core' );
	}

	private static function render_admin_data_value( $label, $value, $type = 'text' ) {
		$value = trim( (string) $value );
		echo '<div class="pllc-admin-data-value">';
		echo '<strong>' . esc_html( $label ) . '</strong>';
		if ( ! $value ) {
			echo '<span>—</span>';
		} elseif ( 'email' === $type ) {
			echo '<a href="mailto:' . esc_attr( $value ) . '">' . esc_html( $value ) . '</a>';
		} elseif ( 'phone' === $type ) {
			$phone_link = preg_replace( '/[^0-9+]/', '', $value );
			echo '<a href="https://wa.me/' . esc_attr( ltrim( $phone_link, '+' ) ) . '" target="_blank" rel="noopener noreferrer">' . esc_html( $value ) . '</a>';
		} else {
			echo '<span>' . esc_html( $value ) . '</span>';
		}
		echo '</div>';
	}

	private static function render_admin_institution_card( $order, $buyer_name, $include_contact = false ) {
		$types = array_values( array_diff( self::get_order_form_types( $order ), [ 'particular' ] ) );
		$type  = isset( $types[0] ) ? $types[0] : '';
		$labels = [
			'colegios'       => __( 'Pedido para Colegios', 'panza-llena-core' ),
			'iteo_personal'  => __( 'Pedido para ITEO Personal', 'panza-llena-core' ),
			'iteo_pacientes' => __( 'Pedido para ITEO Pacientes', 'panza-llena-core' ),
		];
		$title = isset( $labels[ $type ] ) ? $labels[ $type ] : __( 'Pedido institucional', 'panza-llena-core' );

		echo '<div class="pllc-admin-order-data-card pllc-admin-order-data-card--institution">';
		echo '<h4>' . esc_html( $title ) . '</h4>';
		echo '<span class="pllc-admin-delivery-kind pllc-admin-delivery-kind--institution">' . esc_html__( 'Entrega institucional', 'panza-llena-core' ) . '</span>';

		if ( 'colegios' === $type ) {
			self::render_admin_school_groups( $order );
		} else {
			$destinations = [
				'iteo_personal'  => 'ITEO Personal',
				'iteo_pacientes' => 'ITEO Pacientes',
			];
			self::render_admin_data_value( __( 'Destino', 'panza-llena-core' ), isset( $destinations[ $type ] ) ? $destinations[ $type ] : '' );
			if ( ! $include_contact ) {
				self::render_admin_data_value( __( 'Responsable', 'panza-llena-core' ), $buyer_name );
			}
		}

		if ( $include_contact ) {
			self::render_admin_data_value( __( 'Responsable', 'panza-llena-core' ), $buyer_name );
			self::render_admin_data_value( __( 'Correo electrónico', 'panza-llena-core' ), sanitize_email( $order->get_billing_email() ), 'email' );
			self::render_admin_data_value( __( 'WhatsApp', 'panza-llena-core' ), sanitize_text_field( $order->get_billing_phone() ), 'phone' );
		}

		$status = class_exists( 'PLLC_Deliveries' ) ? PLLC_Deliveries::get_order_type_status_label( $order->get_id(), $type ) : '';
		self::render_admin_data_value( __( 'Estado de entrega', 'panza-llena-core' ), $status );
		if ( in_array( $type, [ 'iteo_personal', 'iteo_pacientes' ], true ) ) {
			echo '<p class="pllc-admin-card-note">' . esc_html__( 'No facturado en WooCommerce.', 'panza-llena-core' ) . '</p>';
		}
		echo '</div>';
	}

	private static function render_admin_particular_card( $order, $buyer_name, $email, $whatsapp, $address, $include_contact = false ) {
		echo '<div class="pllc-admin-order-data-card pllc-admin-order-data-card--particular">';
		echo '<h4>' . esc_html__( 'Pedido particular', 'panza-llena-core' ) . '</h4>';
		echo '<span class="pllc-admin-delivery-kind pllc-admin-delivery-kind--home">' . esc_html__( 'Entrega a domicilio', 'panza-llena-core' ) . '</span>';
		if ( $include_contact ) {
			self::render_admin_data_value( __( 'Nombre', 'panza-llena-core' ), $buyer_name );
			self::render_admin_data_value( __( 'Correo electrónico', 'panza-llena-core' ), $email, 'email' );
		}
		echo '<div class="pllc-admin-address">' . ( $address ? wp_kses_post( $address ) : '<span>—</span>' ) . '</div>';
		self::render_admin_data_value( __( 'WhatsApp', 'panza-llena-core' ), $whatsapp, 'phone' );
		$particular_status = class_exists( 'PLLC_Deliveries' ) ? PLLC_Deliveries::get_order_type_status_label( $order->get_id(), 'particular' ) : '';
		self::render_admin_data_value( __( 'Estado de entrega', 'panza-llena-core' ), $particular_status );
		self::render_admin_particular_billing_scope( $order );
		echo '</div>';
	}

	private static function render_admin_school_groups( $order ) {
		$groups = [];
		foreach ( $order->get_items( 'line_item' ) as $item ) {
			if ( 'colegios' !== self::get_item_form_type( $item ) ) {
				continue;
			}
			$form = self::get_item_form( $item );
			$key  = self::get_group_key( 'colegios', $form );
			$groups[ $key ] = $form;
		}

		foreach ( $groups as $form ) {
			echo '<div class="pllc-admin-school-group">';
			self::render_admin_data_value( __( 'Alumno', 'panza-llena-core' ), isset( $form['nombre_alumno'] ) ? $form['nombre_alumno'] : '' );
			self::render_admin_data_value( __( 'Colegio', 'panza-llena-core' ), isset( $form['colegio'] ) ? $form['colegio'] : '' );
			self::render_admin_data_value( __( 'Nivel', 'panza-llena-core' ), isset( $form['nivel'] ) ? $form['nivel'] : '' );
			self::render_admin_data_value( __( 'Curso', 'panza-llena-core' ), isset( $form['curso'] ) ? $form['curso'] : '' );
			echo '</div>';
		}
	}

	private static function render_admin_particular_billing_scope( $order ) {
		echo '<div class="pllc-admin-billing-scope">';
		if ( self::is_iteo_mixed_order( $order ) ) {
			echo '<strong>' . esc_html__( 'Importe facturado', 'panza-llena-core' ) . '</strong>';
			echo '<span>' . wp_kses_post( $order->get_formatted_order_total() ) . '</span>';
			echo '<small>' . esc_html__( 'Corresponde solamente a los productos Particulares.', 'panza-llena-core' ) . '</small>';
		} elseif ( self::is_mixed_order( $order ) ) {
			echo '<strong>' . esc_html__( 'Facturación', 'panza-llena-core' ) . '</strong>';
			echo '<span>' . wp_kses_post( $order->get_formatted_order_total() ) . '</span>';
			echo '<small>' . esc_html__( 'El total incluye los productos de Colegio y Particulares.', 'panza-llena-core' ) . '</small>';
		} else {
			echo '<strong>' . esc_html__( 'Importe facturado', 'panza-llena-core' ) . '</strong>';
			echo '<span>' . wp_kses_post( $order->get_formatted_order_total() ) . '</span>';
			echo '<small>' . esc_html__( 'Corresponde a los productos Particulares.', 'panza-llena-core' ) . '</small>';
		}
		echo '</div>';
	}

	private static function build_group_context( $form_type, $form ) {
		if ( 'iteo_personal' === $form_type ) {
			return __( 'Entrega en ITEO Personal · No facturado en WooCommerce', 'panza-llena-core' );
		}
		if ( 'iteo_pacientes' === $form_type ) {
			return __( 'Entrega en ITEO Pacientes · No facturado en WooCommerce', 'panza-llena-core' );
		}
		if ( 'particular' === $form_type ) {
			return __( 'Entrega a domicilio · Importe incluido en el total', 'panza-llena-core' );
		}
		if ( 'colegios' === $form_type ) {
			$destination = ! empty( $form['colegio'] ) ? $form['colegio'] : __( 'el colegio', 'panza-llena-core' );
			return sprintf( __( 'Entrega en %s · Importe incluido en el total', 'panza-llena-core' ), $destination );
		}
		return '';
	}

	private static function build_summary_line( $form ) {
		$labels = self::summary_labels();
		$parts = [];
		foreach ( $labels as $key => $label ) {
			if ( ! empty( $form[ $key ] ) ) {
				$parts[] = '<strong>' . esc_html( $label ) . ':</strong> ' . esc_html( $form[ $key ] );
			}
		}
		return implode( ' | ', $parts );
	}

	private static function build_summary_text( $form ) {
		$parts = [];
		foreach ( self::summary_labels() as $key => $label ) {
			if ( ! empty( $form[ $key ] ) ) {
				$parts[] = $label . ': ' . sanitize_text_field( $form[ $key ] );
			}
		}
		return implode( ' | ', $parts );
	}

	private static function summary_labels() {
		return [
			'colegio'       => __( 'Colegio', 'panza-llena-core' ),
			'nivel'         => __( 'Nivel', 'panza-llena-core' ),
			'curso'         => __( 'Curso', 'panza-llena-core' ),
			'cubiertos'     => __( 'Cubiertos descartables', 'panza-llena-core' ),
		];
	}

	private static function get_kitchen_observations( $form ) {
		return ! empty( $form['observaciones'] ) ? sanitize_textarea_field( $form['observaciones'] ) : '';
	}

	private static function get_item_day_label( $item ) {
		$day = sanitize_key( (string) $item->get_meta( '_pllc_delivery_day', true ) );
		if ( class_exists( 'PLLC_Order_Rules' ) ) {
			$formatted = PLLC_Order_Rules::format_delivery_label(
				$day,
				$item->get_meta( '_pllc_delivery_date', true )
			);
			if ( $formatted ) {
				return $formatted;
			}
		}
		$labels = [
			'lunes' => 'Lunes', 'martes' => 'Martes', 'miercoles' => 'Miércoles',
			'jueves' => 'Jueves', 'viernes' => 'Viernes', 'sabado' => 'Sábado', 'domingo' => 'Domingo',
		];
		return isset( $labels[ $day ] ) ? $labels[ $day ] : '';
	}

	private static function get_order_form_types( $order ) {
		if ( ! is_a( $order, 'WC_Order' ) ) {
			return [];
		}

		$types = [];
		foreach ( $order->get_items( 'line_item' ) as $item ) {
			$type = self::get_item_form_type( $item );
			if ( ! in_array( $type, $types, true ) ) {
				$types[] = $type;
			}
		}

		$priority = [
			'colegios'       => 1,
			'iteo_personal'  => 2,
			'iteo_pacientes' => 3,
			'particular'     => 99,
		];
		usort( $types, function ( $a, $b ) use ( $priority ) {
			return ( isset( $priority[ $a ] ) ? $priority[ $a ] : 50 ) <=> ( isset( $priority[ $b ] ) ? $priority[ $b ] : 50 );
		} );

		return $types;
	}

	private static function is_mixed_order( $order ) {
		$types = self::get_order_form_types( $order );
		return in_array( 'particular', $types, true ) && count( $types ) > 1;
	}

	private static function is_iteo_mixed_order( $order ) {
		$types = self::get_order_form_types( $order );
		return in_array( 'particular', $types, true )
			&& ( in_array( 'iteo_personal', $types, true ) || in_array( 'iteo_pacientes', $types, true ) );
	}

	private static function uses_structured_admin_layout( $order ) {
		return ! empty( self::get_order_form_types( $order ) );
	}

	private static function build_order_type_label( $order ) {
		$labels = [
			'colegios'       => __( 'Colegios', 'panza-llena-core' ),
			'iteo_personal'  => __( 'ITEO Personal', 'panza-llena-core' ),
			'iteo_pacientes' => __( 'ITEO Pacientes', 'panza-llena-core' ),
			'particular'     => __( 'Particular', 'panza-llena-core' ),
		];
		$parts = [];
		foreach ( self::get_order_form_types( $order ) as $type ) {
			if ( isset( $labels[ $type ] ) ) {
				$parts[] = $labels[ $type ];
			}
		}
		return $parts ? implode( ' + ', $parts ) : __( 'Particular', 'panza-llena-core' );
	}

	private static function build_mixed_title( $order ) {
		return sprintf( __( 'Pedido mixto: %s', 'panza-llena-core' ), self::build_order_type_label( $order ) );
	}

	private static function build_delivery_explanation( $order ) {
		$types    = self::get_order_form_types( $order );
		$has_iteo = in_array( 'iteo_personal', $types, true ) || in_array( 'iteo_pacientes', $types, true );

		if ( $has_iteo ) {
			return __( 'La entrega institucional se realiza en ITEO y la entrega a domicilio corresponde únicamente a “Pedido para mí”. Los importes del pedido contemplan solamente los productos Particulares.', 'panza-llena-core' );
		}

		return __( 'La entrega escolar se realiza en el colegio y la entrega a domicilio corresponde únicamente a “Pedido para mí”. La dirección de envío no se aplica a los productos escolares.', 'panza-llena-core' );
	}

	private static function resolve_admin_order( $object = null ) {
		if ( is_a( $object, 'WC_Order' ) ) {
			return $object;
		}
		if ( is_object( $object ) && ! empty( $object->ID ) ) {
			return wc_get_order( absint( $object->ID ) );
		}
		if ( is_numeric( $object ) ) {
			return wc_get_order( absint( $object ) );
		}

		$order_id = isset( $_GET['id'] ) ? absint( wp_unslash( $_GET['id'] ) ) : 0;
		if ( ! $order_id && isset( $_GET['post'] ) ) {
			$order_id = absint( wp_unslash( $_GET['post'] ) );
		}

		return $order_id ? wc_get_order( $order_id ) : false;
	}

	private static function is_iteo_only_order( $order ) {
		$has_iteo = false;
		foreach ( $order->get_items( 'line_item' ) as $item ) {
			$type = self::get_item_form_type( $item );
			if ( ! in_array( $type, [ 'iteo_personal', 'iteo_pacientes' ], true ) ) {
				return false;
			}
			$has_iteo = true;
		}
		return $has_iteo;
	}
}
