<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Gestión operativa de entregas.
 *
 * El pedido de WooCommerce continúa siendo la unidad financiera (un pago,
 * un total y una eventual devolución). Cada combinación destino + fecha se
 * guarda como una unidad de entrega independiente para poder filtrarla y
 * cambiar su estado en lote sin afectar las demás partes de un pedido mixto.
 */
class PLLC_Deliveries {

	const DB_VERSION        = '1.1.0';
	const DB_VERSION_OPTION = 'pllc_deliveries_db_version';
	const TABLE_SUFFIX      = 'pllc_deliveries';
	const ORDER_META_STATUS = '_pllc_delivery_status';
	const PER_PAGE          = 50;

	private static $page_hook = '';
	private static $order_item_status_cache = [];

	public static function init() {
		add_action( 'init', [ __CLASS__, 'maybe_install' ], 5 );
		add_action( 'woocommerce_checkout_create_order_line_item', [ __CLASS__, 'save_order_item_delivery_meta' ], 20, 4 );
		add_action( 'woocommerce_checkout_order_created', [ __CLASS__, 'create_order_deliveries' ], 20 );
		add_action( 'woocommerce_saved_order_items', [ __CLASS__, 'reconcile_order_deliveries' ], 20 );
		add_action( 'woocommerce_order_status_changed', [ __CLASS__, 'handle_order_status_change' ], 20, 4 );
		add_action( 'woocommerce_trash_order', [ __CLASS__, 'suspend_order_deliveries' ], 20 );
		add_action( 'woocommerce_before_delete_order', [ __CLASS__, 'delete_order_deliveries' ], 20 );
		add_action( 'woocommerce_untrash_order', [ __CLASS__, 'restore_order_deliveries' ], 20 );
		add_filter( 'woocommerce_hidden_order_itemmeta', [ __CLASS__, 'hide_internal_order_item_meta' ] );
		add_filter( 'woocommerce_order_item_get_formatted_meta_data', [ __CLASS__, 'remove_internal_formatted_meta' ], 20 );

		add_action( 'admin_menu', [ __CLASS__, 'register_admin_page' ], 30 );
		add_action( 'admin_enqueue_scripts', [ __CLASS__, 'enqueue_admin_assets' ] );
		add_action( 'admin_post_pllc_bulk_update_deliveries', [ __CLASS__, 'handle_bulk_update' ] );
		add_action( 'add_meta_boxes', [ __CLASS__, 'register_order_meta_box' ] );

		// Columna compatible tanto con la lista clásica como con HPOS.
		add_filter( 'manage_edit-shop_order_columns', [ __CLASS__, 'add_order_list_column' ], 30 );
		add_action( 'manage_shop_order_posts_custom_column', [ __CLASS__, 'render_legacy_order_list_column' ], 30, 2 );
		add_filter( 'manage_woocommerce_page_wc-orders_columns', [ __CLASS__, 'add_order_list_column' ], 30 );
		add_action( 'manage_woocommerce_page_wc-orders_custom_column', [ __CLASS__, 'render_hpos_order_list_column' ], 30, 2 );
	}

	/**
	 * Claves técnicas usadas para construir y administrar las entregas.
	 * Deben conservarse en el pedido, pero no mostrarse al operador o cliente.
	 */
	private static function internal_order_item_meta_keys() {
		return [
			'_pllc_form_type',
			'_pllc_form',
			'_pllc_group',
			'_pllc_meals',
			'_pllc_delivery_day',
			'_pllc_delivery_date',
			'_pllc_delivery_destination',
			'_pllc_delivery_destination_slug',
		];
	}

	/** Oculta las claves internas en el editor y la vista previa del pedido. */
	public static function hide_internal_order_item_meta( $hidden_keys ) {
		return array_values( array_unique( array_merge( (array) $hidden_keys, self::internal_order_item_meta_keys() ) ) );
	}

	/**
	 * Evita que los mismos datos técnicos se filtren a correos, Mi cuenta,
	 * páginas de agradecimiento u otras vistas que usan metadatos formateados.
	 */
	public static function remove_internal_formatted_meta( $formatted_meta ) {
		$internal_keys = self::internal_order_item_meta_keys();

		foreach ( (array) $formatted_meta as $meta_id => $meta ) {
			if ( isset( $meta->key ) && in_array( $meta->key, $internal_keys, true ) ) {
				unset( $formatted_meta[ $meta_id ] );
			}
		}

		return $formatted_meta;
	}

	/** Crea o actualiza la tabla operativa sin depender de HPOS. */
	public static function install() {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$table   = self::table_name();
		$charset = $wpdb->get_charset_collate();
		$sql     = "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			delivery_key char(64) NOT NULL,
			order_id bigint(20) unsigned NOT NULL,
			order_type varchar(32) NOT NULL,
			destination_slug varchar(100) NOT NULL,
			destination_label varchar(190) NOT NULL,
			delivery_date date DEFAULT NULL,
			delivery_day varchar(20) NOT NULL DEFAULT '',
			status varchar(20) NOT NULL DEFAULT 'pending',
			previous_status varchar(20) NOT NULL DEFAULT '',
			cancel_reason varchar(32) NOT NULL DEFAULT '',
			recipient_label text NOT NULL,
			item_count int(10) unsigned NOT NULL DEFAULT 0,
			item_ids longtext NOT NULL,
			item_summary longtext NOT NULL,
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			status_changed_at datetime DEFAULT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY delivery_key (delivery_key),
			KEY order_id (order_id),
			KEY delivery_date (delivery_date),
			KEY destination_status (destination_slug,status),
			KEY date_status (delivery_date,status),
			KEY order_type (order_type)
		) {$charset};";

		dbDelta( $sql );
		$table_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) );
		if ( $table_exists === $table ) {
			update_option( self::DB_VERSION_OPTION, self::DB_VERSION, false );
		}
	}

	public static function maybe_install() {
		if ( self::DB_VERSION !== get_option( self::DB_VERSION_OPTION ) ) {
			self::install();
		}
	}

	private static function table_name() {
		global $wpdb;
		return $wpdb->prefix . self::TABLE_SUFFIX;
	}

	/**
	 * Copia al renglón del pedido los datos que solo existían en el carrito.
	 * Las claves privadas no ensucian la visualización normal de WooCommerce.
	 */
	public static function save_order_item_delivery_meta( $item, $cart_item_key, $values, $order ) {
		$form_type = self::normalize_form_type( isset( $values['pllc_form_type'] ) ? $values['pllc_form_type'] : 'particular' );
		$form      = [];
		if ( ! empty( $values['pllc_form'] ) && is_array( $values['pllc_form'] ) ) {
			foreach ( $values['pllc_form'] as $key => $value ) {
				$value = is_scalar( $value ) ? (string) $value : '';
				$form[ sanitize_key( $key ) ] = 'observaciones' === $key
					? sanitize_textarea_field( $value )
					: sanitize_text_field( $value );
			}
		}
		$product_id = ! empty( $values['product_id'] ) ? absint( $values['product_id'] ) : $item->get_product_id();
		$day        = ! empty( $values['pllc_day'] )
			? sanitize_key( $values['pllc_day'] )
			: self::detect_product_day( $product_id, $form_type );
		$date       = ! empty( $values['pllc_delivery_date'] )
			? sanitize_text_field( $values['pllc_delivery_date'] )
			: self::calculate_delivery_date( $day );
		$destination = self::get_destination( $form_type, $form );

		$item->add_meta_data( '_pllc_form_type', $form_type, true );
		$item->add_meta_data( '_pllc_form', $form, true );
		$item->add_meta_data( '_pllc_group', isset( $values['pllc_group'] ) ? sanitize_text_field( $values['pllc_group'] ) : '', true );
		$item->add_meta_data( '_pllc_meals', ! empty( $values['pllc_meals'] ) ? array_map( 'sanitize_text_field', (array) $values['pllc_meals'] ) : [], true );
		$item->add_meta_data( '_pllc_delivery_day', $day, true );
		$item->add_meta_data( '_pllc_delivery_date', $date, true );
		$item->add_meta_data( '_pllc_delivery_destination', $destination['label'], true );
		$item->add_meta_data( '_pllc_delivery_destination_slug', $destination['slug'], true );
	}

	public static function create_order_deliveries( $order ) {
		self::sync_order_deliveries( $order );
	}

	/** Revisa las unidades después de editar productos desde WooCommerce. */
	public static function reconcile_order_deliveries( $order_id ) {
		$order = wc_get_order( absint( $order_id ) );
		if ( $order ) {
			self::sync_order_deliveries( $order );
		}
	}

	/** En papelera se conserva el historial y sólo se suspenden filas activas. */
	public static function suspend_order_deliveries( $order_id ) {
		self::cancel_active_deliveries( $order_id, 'trash' );
	}

	/** El borrado definitivo sí elimina las filas operativas. */
	public static function delete_order_deliveries( $order_id ) {
		global $wpdb;

		$order_id = absint( $order_id );
		if ( ! $order_id ) {
			return;
		}

		$wpdb->delete(
			self::table_name(),
			[ 'order_id' => $order_id ],
			[ '%d' ]
		);
	}

	/** Restaura sólo lo suspendido por la papelera y reconcilia los ítems. */
	public static function restore_order_deliveries( $order_id ) {
		$order = wc_get_order( absint( $order_id ) );
		if ( $order ) {
			self::restore_cancelled_deliveries( $order->get_id(), [ 'trash' ] );
			self::sync_order_deliveries( $order );
		}
	}

	/**
	 * Genera una fila por destino + fecha. Dos alumnos del mismo colegio y
	 * día quedan dentro de la misma entrega del pedido.
	 */
	private static function sync_order_deliveries( $order ) {
		global $wpdb;

		$order = is_a( $order, 'WC_Order' ) ? $order : wc_get_order( $order );
		if ( ! $order ) {
			return;
		}

		self::maybe_install();
		$groups         = [];
		$customer_label = self::get_order_customer_label( $order );

		foreach ( $order->get_items( 'line_item' ) as $item_id => $item ) {
			$form_type = self::get_item_form_type( $item );
			$form      = $item->get_meta( '_pllc_form', true );
			$form      = is_array( $form ) ? $form : [];
			$day       = sanitize_key( (string) $item->get_meta( '_pllc_delivery_day', true ) );
			$date      = sanitize_text_field( (string) $item->get_meta( '_pllc_delivery_date', true ) );

			if ( ! $day ) {
				$day = self::detect_product_day( $item->get_product_id(), $form_type );
			}
			if ( ! self::is_valid_date( $date ) ) {
				$date = self::calculate_delivery_date( $day );
			}

			$destination = self::get_destination_from_item( $item, $form_type, $form );
			$group_key   = implode( '|', [ $form_type, $destination['slug'], $date ? $date : 'sin-fecha', $date ? '' : $day ] );

			if ( ! isset( $groups[ $group_key ] ) ) {
				$groups[ $group_key ] = [
					'order_type'       => $form_type,
					'destination_slug' => $destination['slug'],
					'destination_label' => $destination['label'],
					'delivery_date'    => $date,
					'delivery_day'     => $day,
					'recipients'       => [],
					'item_count'       => 0,
					'item_ids'         => [],
					'items'            => [],
				];
			}

			$recipient = ! empty( $form['nombre_alumno'] ) ? $form['nombre_alumno'] : $customer_label;
			$meals     = $item->get_meta( '_pllc_meals', true );
			$meal      = is_array( $meals ) && $meals ? implode( ', ', array_map( 'ucfirst', $meals ) ) : '';
			$quantity  = max( 1, absint( $item->get_quantity() ) );
			$details   = [];
			foreach ( $item->get_formatted_meta_data() as $meta ) {
				$details[] = sanitize_text_field( wp_strip_all_tags( $meta->display_key . ': ' . $meta->display_value ) );
			}

			if ( $recipient ) {
				$groups[ $group_key ]['recipients'][] = sanitize_text_field( $recipient );
			}
			$groups[ $group_key ]['item_count'] += $quantity;
			$groups[ $group_key ]['item_ids'][]  = absint( $item_id );
			$groups[ $group_key ]['items'][]     = [
				'item_id'   => absint( $item_id ),
				'name'      => sanitize_text_field( $item->get_name() ),
				'quantity'  => $quantity,
				'meal'      => sanitize_text_field( $meal ),
				'details'   => implode( ' · ', array_filter( $details ) ),
				'recipient' => sanitize_text_field( $recipient ),
			];
		}

		$now           = current_time( 'mysql' );
		$delivery_keys = [];
		foreach ( $groups as $group ) {
			$delivery_key = hash( 'sha256', $order->get_id() . '|' . $group['order_type'] . '|' . $group['destination_slug'] . '|' . ( $group['delivery_date'] ? $group['delivery_date'] : 'sin-fecha|' . $group['delivery_day'] ) );
			$delivery_keys[] = $delivery_key;
			$existing     = $wpdb->get_row( $wpdb->prepare(
				"SELECT id, status, previous_status, cancel_reason FROM " . self::table_name() . ' WHERE delivery_key = %s',
				$delivery_key
			) );
			$existing_id  = $existing ? absint( $existing->id ) : 0;

			$data = [
				'order_id'          => $order->get_id(),
				'order_type'        => $group['order_type'],
				'destination_slug'  => $group['destination_slug'],
				'destination_label' => $group['destination_label'],
				'delivery_date'     => $group['delivery_date'] ? $group['delivery_date'] : null,
				'delivery_day'      => $group['delivery_day'],
				'recipient_label'   => implode( ', ', array_values( array_unique( array_filter( $group['recipients'] ) ) ) ),
				'item_count'        => $group['item_count'],
				'item_ids'          => wp_json_encode( array_values( array_unique( $group['item_ids'] ) ) ),
				'item_summary'      => wp_json_encode( $group['items'], JSON_UNESCAPED_UNICODE ),
				'updated_at'        => $now,
			];

			if ( $existing_id ) {
				// Sólo una fila retirada por una edición de ítems puede reaparecer
				// automáticamente. Otras cancelaciones requieren reactivar el pedido.
				if ( 'cancelled' === $existing->status && 'items_changed' === $existing->cancel_reason
					&& ! self::is_inactive_order_status( $order->get_status() ) ) {
					$data['status'] = in_array( $existing->previous_status, [ 'pending', 'prepared' ], true )
						? $existing->previous_status
						: 'pending';
					$data['previous_status']   = '';
					$data['cancel_reason']      = '';
					$data['status_changed_at'] = $now;
				}
				$wpdb->update( self::table_name(), $data, [ 'id' => $existing_id ] );
			} else {
				$data['delivery_key']     = $delivery_key;
				$data['status']           = self::is_inactive_order_status( $order->get_status() ) ? 'cancelled' : 'pending';
				$data['previous_status']  = self::is_inactive_order_status( $order->get_status() ) ? 'pending' : '';
				$data['cancel_reason']     = self::is_inactive_order_status( $order->get_status() ) ? 'order_' . $order->get_status() : '';
				$data['created_at']       = $now;
				$data['status_changed_at'] = $now;
				$wpdb->insert( self::table_name(), $data );
			}
		}

		self::cancel_obsolete_deliveries( $order->get_id(), $delivery_keys );

		self::refresh_order_delivery_status( $order );
	}

	private static function normalize_form_type( $type ) {
		$type    = sanitize_key( $type );
		$allowed = [ 'colegios', 'iteo_personal', 'iteo_pacientes', 'particular' ];
		return in_array( $type, $allowed, true ) ? $type : 'particular';
	}

	private static function get_item_form_type( $item ) {
		$type = self::normalize_form_type( $item->get_meta( '_pllc_form_type', true ) );
		if ( 'particular' !== $type || $item->meta_exists( '_pllc_form_type' ) ) {
			return $type;
		}

		$product_id = $item->get_product_id();
		$slugs      = wc_get_product_terms( $product_id, 'product_cat', [ 'fields' => 'slugs' ] );
		if ( is_wp_error( $slugs ) ) {
			return 'particular';
		}
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
		return 'particular';
	}

	private static function get_destination( $form_type, $form ) {
		$labels = [
			'iteo_personal'  => 'ITEO Personal',
			'iteo_pacientes' => 'ITEO Pacientes',
			'particular'     => 'Particular',
		];

		if ( 'colegios' === $form_type ) {
			$label = ! empty( $form['colegio'] ) ? sanitize_text_field( $form['colegio'] ) : 'Colegios';
		} else {
			$label = isset( $labels[ $form_type ] ) ? $labels[ $form_type ] : 'Particular';
		}

		return [
			'label' => $label,
			'slug'  => sanitize_title( $label ),
		];
	}

	private static function get_destination_from_item( $item, $form_type, $form ) {
		$label = sanitize_text_field( (string) $item->get_meta( '_pllc_delivery_destination', true ) );
		$slug  = sanitize_title( (string) $item->get_meta( '_pllc_delivery_destination_slug', true ) );
		if ( $label && $slug ) {
			return [ 'label' => $label, 'slug' => $slug ];
		}
		return self::get_destination( $form_type, $form );
	}

	/** Detecta el día aun si la categoría utiliza un prefijo distinto. */
	private static function detect_product_day( $product_id, $form_type ) {
		if ( ! $product_id ) {
			return '';
		}
		$slugs = wc_get_product_terms( $product_id, 'product_cat', [ 'fields' => 'slugs' ] );
		if ( is_wp_error( $slugs ) ) {
			return '';
		}
		$days = [ 'lunes', 'martes', 'miercoles', 'jueves', 'viernes', 'sabado', 'domingo' ];
		foreach ( $slugs as $slug ) {
			$slug = sanitize_title( $slug );
			foreach ( $days as $day ) {
				if ( $slug === $day || false !== strpos( $slug, '-' . $day ) ) {
					return $day;
				}
			}
		}
		return '';
	}

	/** Misma regla de próxima aparición que muestran los títulos del menú. */
	private static function calculate_delivery_date( $day ) {
		$numbers = [
			'lunes' => 1, 'martes' => 2, 'miercoles' => 3, 'jueves' => 4,
			'viernes' => 5, 'sabado' => 6, 'domingo' => 7,
		];
		if ( ! isset( $numbers[ $day ] ) ) {
			return '';
		}

		$now = apply_filters( 'pllc_current_datetime', current_datetime() );
		if ( ! $now instanceof DateTimeInterface ) {
			$now = current_datetime();
		}
		$timezone = wp_timezone();
		$base     = ( new DateTimeImmutable( '@' . $now->getTimestamp() ) )->setTimezone( $timezone );
		$ahead    = ( $numbers[ $day ] - (int) $base->format( 'N' ) + 7 ) % 7;
		if ( 0 === $ahead ) {
			$ahead = 7;
		}
		return $base->modify( '+' . $ahead . ' days' )->format( 'Y-m-d' );
	}

	private static function is_valid_date( $date ) {
		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', (string) $date ) ) {
			return false;
		}
		$parsed = DateTimeImmutable::createFromFormat( '!Y-m-d', $date, wp_timezone() );
		return $parsed && $parsed->format( 'Y-m-d' ) === $date;
	}

	private static function get_order_customer_label( $order ) {
		$name = trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() );
		return $name ? $name : sprintf( 'Pedido #%d', $order->get_id() );
	}

	private static function is_inactive_order_status( $status ) {
		return in_array( sanitize_key( (string) $status ), [ 'cancelled', 'refunded', 'failed', 'trash' ], true );
	}

	/** Suspende pendientes/preparadas sin alterar entregas ya realizadas. */
	private static function cancel_active_deliveries( $order_id, $reason ) {
		global $wpdb;
		$order_id = absint( $order_id );
		$reason   = sanitize_key( (string) $reason );
		if ( ! $order_id || ! $reason ) {
			return 0;
		}
		$now = current_time( 'mysql' );
		return $wpdb->query( $wpdb->prepare(
			"UPDATE " . self::table_name() . " SET previous_status = status, status = 'cancelled', cancel_reason = %s, status_changed_at = %s, updated_at = %s WHERE order_id = %d AND status IN ('pending','prepared')",
			$reason,
			$now,
			$now,
			$order_id
		) );
	}

	/** Recupera únicamente cancelaciones cuyo origen coincide. */
	private static function restore_cancelled_deliveries( $order_id, $reasons ) {
		global $wpdb;
		$order_id = absint( $order_id );
		$reasons  = array_values( array_unique( array_filter( array_map( 'sanitize_key', (array) $reasons ) ) ) );
		if ( ! $order_id || ! $reasons ) {
			return 0;
		}
		$now          = current_time( 'mysql' );
		$placeholders = implode( ',', array_fill( 0, count( $reasons ), '%s' ) );
		$args         = array_merge( [ $now, $now, $order_id ], $reasons );
		return $wpdb->query( $wpdb->prepare(
			"UPDATE " . self::table_name() . " SET status = CASE WHEN previous_status IN ('pending','prepared') THEN previous_status ELSE 'pending' END, previous_status = '', cancel_reason = '', status_changed_at = %s, updated_at = %s WHERE order_id = %d AND status = 'cancelled' AND cancel_reason IN ({$placeholders})",
			$args
		) );
	}

	/** Retira filas de ítems eliminados, conservándolas como historial. */
	private static function cancel_obsolete_deliveries( $order_id, $active_keys ) {
		global $wpdb;
		$order_id   = absint( $order_id );
		$active_keys = array_values( array_unique( array_filter( array_map( 'sanitize_text_field', (array) $active_keys ) ) ) );
		if ( ! $order_id ) {
			return 0;
		}

		$now   = current_time( 'mysql' );
		$sql   = "UPDATE " . self::table_name() . " SET previous_status = status, status = 'cancelled', cancel_reason = 'items_changed', status_changed_at = %s, updated_at = %s WHERE order_id = %d AND status IN ('pending','prepared')";
		$args  = [ $now, $now, $order_id ];
		if ( $active_keys ) {
			$sql  .= ' AND delivery_key NOT IN (' . implode( ',', array_fill( 0, count( $active_keys ), '%s' ) ) . ')';
			$args = array_merge( $args, $active_keys );
		}
		return $wpdb->query( $wpdb->prepare( $sql, $args ) );
	}

	private static function can_bulk_transition( $delivery_status, $order_status ) {
		return 'cancelled' !== sanitize_key( (string) $delivery_status )
			&& ! self::is_inactive_order_status( $order_status );
	}

	private static function should_complete_order( $delivery_status, $order_status ) {
		return 'delivered' === $delivery_status && 'processing' === $order_status;
	}

	/** Si se cancela el pedido financiero, sus entregas dejan la cola activa. */
	public static function handle_order_status_change( $order_id, $from, $to, $order ) {
		$active = [ 'pending', 'on-hold', 'processing' ];

		if ( self::is_inactive_order_status( $to ) ) {
			self::cancel_active_deliveries( $order_id, 'order_' . sanitize_key( $to ) );
		} elseif ( self::is_inactive_order_status( $from ) && in_array( $to, $active, true ) ) {
			self::restore_cancelled_deliveries( $order_id, [ 'order_' . sanitize_key( $from ) ] );
			self::sync_order_deliveries( $order );
			return;
		} else {
			return;
		}
		self::refresh_order_delivery_status( $order );
	}

	private static function refresh_order_delivery_status( $order ) {
		global $wpdb;
		$order = is_a( $order, 'WC_Order' ) ? $order : wc_get_order( $order );
		if ( ! $order ) {
			return;
		}

		$counts = $wpdb->get_results( $wpdb->prepare(
			'SELECT status, COUNT(*) AS amount FROM ' . self::table_name() . ' WHERE order_id = %d GROUP BY status',
			$order->get_id()
		), OBJECT_K );

		$status = self::aggregate_status_from_counts( $counts );

		$order->update_meta_data( self::ORDER_META_STATUS, $status );
		$order->save_meta_data();

		// La logística puede cerrar un pedido pagado en proceso, pero nunca
		// convertir un pedido en espera en pagado/completado.
		if ( self::should_complete_order( $status, $order->get_status() ) ) {
			$order->update_status( 'completed', 'Todas las unidades de entrega fueron marcadas como entregadas.' );
		}
	}

	/** Devuelve el estado agregado de una parte concreta de un pedido mixto. */
	public static function get_order_type_status_label( $order_id, $order_type ) {
		global $wpdb;

		$order_id   = absint( $order_id );
		$order_type = self::normalize_form_type( $order_type );
		if ( ! $order_id ) {
			return '';
		}

		$counts = $wpdb->get_results( $wpdb->prepare(
			'SELECT status, COUNT(*) AS amount FROM ' . self::table_name() . ' WHERE order_id = %d AND order_type = %s GROUP BY status',
			$order_id,
			$order_type
		), OBJECT_K );
		$status = self::aggregate_status_from_counts( $counts );
		$labels = self::aggregate_status_labels();

		return isset( $labels[ $status ] ) ? $labels[ $status ] : '';
	}

	/**
	 * Devuelve el indicador visual de la entrega que contiene un renglón.
	 * Si el renglón participa en más de una unidad, agrega sus estados.
	 */
	public static function get_order_item_status_badge( $order_id, $item_id ) {
		$order_id = absint( $order_id );
		$item_id  = absint( $item_id );
		if ( ! $order_id || ! $item_id ) {
			return '<span class="pllc-status-badge pllc-status-badge--none">Sin entrega</span>';
		}

		$status = self::get_order_item_delivery_status( $order_id, $item_id );
		if ( 'none' === $status ) {
			return '<span class="pllc-status-badge pllc-status-badge--none">Sin entrega</span>';
		}

		$url = add_query_arg(
			[
				'page'            => 'pllc-deliveries',
				'show_all'        => 1,
				's'               => $order_id,
				'delivery_status' => 'all',
			],
			admin_url( 'admin.php' )
		);

		return '<a class="pllc-order-item-delivery-link" href="' . esc_url( $url ) . '" title="Administrar entregas de este pedido">'
			. self::status_badge( $status, true )
			. '</a>';
	}

	private static function get_order_item_delivery_status( $order_id, $item_id ) {
		if ( ! isset( self::$order_item_status_cache[ $order_id ] ) ) {
			self::$order_item_status_cache[ $order_id ] = self::build_order_item_status_map( $order_id );
		}

		return isset( self::$order_item_status_cache[ $order_id ][ $item_id ] )
			? self::$order_item_status_cache[ $order_id ][ $item_id ]
			: 'none';
	}

	private static function build_order_item_status_map( $order_id ) {
		global $wpdb;

		$rows = $wpdb->get_results( $wpdb->prepare(
			'SELECT status, item_ids FROM ' . self::table_name() . ' WHERE order_id = %d',
			$order_id
		) );
		$item_counts = [];

		foreach ( (array) $rows as $row ) {
			$status   = sanitize_key( $row->status );
			$item_ids = json_decode( $row->item_ids, true );
			foreach ( (array) $item_ids as $item_id ) {
				$item_id = absint( $item_id );
				if ( ! $item_id ) {
					continue;
				}
				if ( ! isset( $item_counts[ $item_id ][ $status ] ) ) {
					$item_counts[ $item_id ][ $status ] = 0;
				}
				$item_counts[ $item_id ][ $status ]++;
			}
		}

		$map = [];
		foreach ( $item_counts as $item_id => $counts ) {
			$objects = [];
			foreach ( $counts as $status => $amount ) {
				$objects[ $status ] = (object) [ 'amount' => $amount ];
			}
			$map[ $item_id ] = self::aggregate_status_from_counts( $objects );
		}

		return $map;
	}

	private static function aggregate_status_from_counts( $counts ) {
		$counts    = (array) $counts;
		$total     = 0;
		$cancelled = isset( $counts['cancelled'] ) ? absint( $counts['cancelled']->amount ) : 0;
		$delivered = isset( $counts['delivered'] ) ? absint( $counts['delivered']->amount ) : 0;
		$prepared  = isset( $counts['prepared'] ) ? absint( $counts['prepared']->amount ) : 0;
		foreach ( $counts as $count ) {
			$total += absint( $count->amount );
		}
		$active = $total - $cancelled;

		if ( ! $total ) {
			return 'none';
		}
		if ( ! $active ) {
			return 'cancelled';
		}
		if ( $delivered === $active ) {
			return 'delivered';
		}
		if ( $prepared === $active ) {
			return 'prepared';
		}
		if ( $delivered || $prepared ) {
			return 'partial';
		}
		return 'pending';
	}

	public static function register_admin_page() {
		self::$page_hook = add_submenu_page(
			'woocommerce',
			'Entregas',
			'Entregas',
			'manage_woocommerce',
			'pllc-deliveries',
			[ __CLASS__, 'render_admin_page' ]
		);
	}

	public static function enqueue_admin_assets( $hook ) {
		$screen    = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		$screen_id = $screen ? $screen->id : '';
		$order_screens = [ 'edit-shop_order', 'shop_order', 'woocommerce_page_wc-orders' ];
		if ( $hook !== self::$page_hook && ! in_array( $screen_id, $order_screens, true ) ) {
			return;
		}
		wp_enqueue_style(
			'pllc-admin-deliveries',
			PLLC_URL . 'assets/css/pllc-admin-deliveries.css',
			[],
			PLLC_VERSION
		);
	}

	public static function render_admin_page() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'No tenés permisos para ver esta pantalla.', 'panza-llena-core' ) );
		}

		$filters      = self::read_filters();
		$page         = isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1;
		$result       = self::query_deliveries( $filters, $page, self::PER_PAGE );
		$destinations = self::get_destination_options();
		$summary      = self::get_status_summary( $filters );
		$total_pages  = max( 1, (int) ceil( $result['total'] / self::PER_PAGE ) );
		?>
		<div class="wrap pllc-deliveries-page">
			<h1>Entregas</h1>
			<p class="pllc-deliveries-intro">Cada fila es una entrega independiente. Un pedido mixto puede aparecer varias veces sin duplicar el pago.</p>

			<?php self::render_admin_notice(); ?>
			<?php self::render_summary_cards( $summary ); ?>

			<form method="get" class="pllc-delivery-filters">
				<input type="hidden" name="page" value="pllc-deliveries">
				<label>
					<span>Fecha de entrega</span>
					<input type="date" name="delivery_date" value="<?php echo esc_attr( $filters['delivery_date'] ); ?>">
				</label>
				<label>
					<span>Categoría</span>
					<select name="order_type">
						<option value="">Todas</option>
						<?php foreach ( self::type_labels() as $value => $label ) : ?>
							<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $filters['order_type'], $value ); ?>><?php echo esc_html( $label ); ?></option>
						<?php endforeach; ?>
					</select>
				</label>
				<label>
					<span>Destino</span>
					<select name="destination">
						<option value="">Todos</option>
						<?php foreach ( $destinations as $destination ) : ?>
							<option value="<?php echo esc_attr( $destination->destination_slug ); ?>" <?php selected( $filters['destination'], $destination->destination_slug ); ?>><?php echo esc_html( $destination->destination_label ); ?></option>
						<?php endforeach; ?>
					</select>
				</label>
				<label>
					<span>Estado</span>
					<select name="delivery_status">
						<option value="open" <?php selected( $filters['status'], 'open' ); ?>>Pendientes y preparados</option>
						<?php foreach ( self::status_labels() as $value => $label ) : ?>
							<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $filters['status'], $value ); ?>><?php echo esc_html( $label ); ?></option>
						<?php endforeach; ?>
						<option value="all" <?php selected( $filters['status'], 'all' ); ?>>Todos</option>
					</select>
				</label>
				<label class="pllc-delivery-search">
					<span>Buscar</span>
					<input type="search" name="s" value="<?php echo esc_attr( $filters['search'] ); ?>" placeholder="Pedido, persona o producto">
				</label>
				<button class="button button-primary" type="submit">Filtrar</button>
				<a class="button" href="<?php echo esc_url( add_query_arg( [ 'page' => 'pllc-deliveries', 'show_all' => 1 ], admin_url( 'admin.php' ) ) ); ?>">Todas las fechas</a>
			</form>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="pllc_bulk_update_deliveries">
				<?php wp_nonce_field( 'pllc_bulk_update_deliveries', 'pllc_delivery_nonce' ); ?>
				<div class="tablenav top">
					<div class="alignleft actions bulkactions">
						<label class="screen-reader-text" for="pllc-bulk-action">Acción en lote</label>
						<select id="pllc-bulk-action" name="bulk_action">
							<option value="">Acciones en lote</option>
							<option value="pending">Marcar como pendiente</option>
							<option value="prepared">Marcar como preparado</option>
							<option value="delivered">Marcar como entregado</option>
						</select>
						<button type="submit" class="button action">Aplicar</button>
					</div>
					<?php self::render_pagination( $page, $total_pages, $result['total'], $filters ); ?>
				</div>

				<table class="wp-list-table widefat fixed striped table-view-list pllc-deliveries-table">
					<thead><tr>
						<td class="manage-column column-cb check-column"><input id="pllc-select-all" type="checkbox"><label for="pllc-select-all"><span class="screen-reader-text">Seleccionar todo</span></label></td>
						<th>Fecha</th><th>Destino</th><th>Pedido</th><th>Destinatario</th><th>Productos</th><th>Estado</th>
					</tr></thead>
					<tbody>
					<?php if ( empty( $result['rows'] ) ) : ?>
						<tr><td colspan="7" class="pllc-no-deliveries">No hay entregas para estos filtros.</td></tr>
					<?php else : ?>
						<?php foreach ( $result['rows'] as $delivery ) : self::render_delivery_row( $delivery ); endforeach; ?>
					<?php endif; ?>
					</tbody>
				</table>
				<div class="tablenav bottom">
					<?php self::render_pagination( $page, $total_pages, $result['total'], $filters ); ?>
				</div>
			</form>
		</div>
		<script>
		(function () {
			var master = document.getElementById('pllc-select-all');
			if (!master) return;
			master.addEventListener('change', function () {
				document.querySelectorAll('input[name="delivery_ids[]"]').forEach(function (box) { box.checked = master.checked; });
			});
		}());
		</script>
		<?php
	}

	private static function read_filters() {
		$show_all = ! empty( $_GET['show_all'] );
		if ( $show_all ) {
			$date = '';
		} elseif ( array_key_exists( 'delivery_date', $_GET ) ) {
			$date = sanitize_text_field( wp_unslash( $_GET['delivery_date'] ) );
			$date = self::is_valid_date( $date ) ? $date : '';
		} else {
			$date = self::get_default_delivery_date();
		}

		$type = isset( $_GET['order_type'] ) ? sanitize_key( wp_unslash( $_GET['order_type'] ) ) : '';
		if ( $type && ! isset( self::type_labels()[ $type ] ) ) {
			$type = '';
		}
		$status  = isset( $_GET['delivery_status'] ) ? sanitize_key( wp_unslash( $_GET['delivery_status'] ) ) : 'open';
		$allowed = array_merge( [ 'open', 'all' ], array_keys( self::status_labels() ) );
		if ( ! in_array( $status, $allowed, true ) ) {
			$status = 'open';
		}

		return [
			'delivery_date' => $date,
			'order_type'    => $type,
			'destination'   => isset( $_GET['destination'] ) ? sanitize_title( wp_unslash( $_GET['destination'] ) ) : '',
			'status'        => $status,
			'search'        => isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '',
		];
	}

	private static function get_default_delivery_date() {
		global $wpdb;
		$today = current_datetime()->format( 'Y-m-d' );
		$date  = $wpdb->get_var( $wpdb->prepare(
			"SELECT MIN(delivery_date) FROM " . self::table_name() . " WHERE delivery_date >= %s AND status IN ('pending','prepared')",
			$today
		) );
		return self::is_valid_date( $date ) ? $date : $today;
	}

	private static function build_where( $filters, $include_status = true ) {
		global $wpdb;
		$where = [ '1=1' ];
		$args  = [];

		if ( ! empty( $filters['delivery_date'] ) ) {
			$where[] = 'delivery_date = %s';
			$args[]  = $filters['delivery_date'];
		}
		if ( ! empty( $filters['order_type'] ) ) {
			$where[] = 'order_type = %s';
			$args[]  = $filters['order_type'];
		}
		if ( ! empty( $filters['destination'] ) ) {
			$where[] = 'destination_slug = %s';
			$args[]  = $filters['destination'];
		}
		if ( $include_status && ! empty( $filters['status'] ) && 'all' !== $filters['status'] ) {
			if ( 'open' === $filters['status'] ) {
				$where[] = "status IN ('pending','prepared')";
			} else {
				$where[] = 'status = %s';
				$args[]  = $filters['status'];
			}
		}
		if ( ! empty( $filters['search'] ) ) {
			$like    = '%' . $wpdb->esc_like( $filters['search'] ) . '%';
			$clauses = [ 'destination_label LIKE %s', 'recipient_label LIKE %s', 'item_summary LIKE %s' ];
			$args[]  = $like;
			$args[]  = $like;
			$args[]  = $like;
			if ( ctype_digit( $filters['search'] ) ) {
				$clauses[] = 'order_id = %d';
				$args[]    = absint( $filters['search'] );
			}
			$where[] = '(' . implode( ' OR ', $clauses ) . ')';
		}

		return [ implode( ' AND ', $where ), $args ];
	}

	private static function query_deliveries( $filters, $page, $per_page ) {
		global $wpdb;
		list( $where, $args ) = self::build_where( $filters, true );
		$table  = self::table_name();
		$count_sql = "SELECT COUNT(*) FROM {$table} WHERE {$where}";
		$total  = $args ? absint( $wpdb->get_var( $wpdb->prepare( $count_sql, $args ) ) ) : absint( $wpdb->get_var( $count_sql ) );

		$offset    = ( max( 1, $page ) - 1 ) * $per_page;
		$rows_sql  = "SELECT * FROM {$table} WHERE {$where} ORDER BY (delivery_date IS NULL), delivery_date ASC, destination_label ASC, order_id DESC LIMIT %d OFFSET %d";
		$rows_args = array_merge( $args, [ $per_page, $offset ] );
		$rows      = $wpdb->get_results( $wpdb->prepare( $rows_sql, $rows_args ) );

		return [ 'rows' => $rows, 'total' => $total ];
	}

	private static function get_status_summary( $filters ) {
		global $wpdb;
		list( $where, $args ) = self::build_where( $filters, false );
		$sql  = 'SELECT status, COUNT(*) AS amount FROM ' . self::table_name() . " WHERE {$where} GROUP BY status";
		$rows = $args ? $wpdb->get_results( $wpdb->prepare( $sql, $args ), OBJECT_K ) : $wpdb->get_results( $sql, OBJECT_K );
		$out  = [ 'pending' => 0, 'prepared' => 0, 'delivered' => 0, 'cancelled' => 0, 'total' => 0 ];
		foreach ( $rows as $status => $row ) {
			if ( isset( $out[ $status ] ) ) {
				$out[ $status ] = absint( $row->amount );
			}
			$out['total'] += absint( $row->amount );
		}
		return $out;
	}

	private static function get_destination_options() {
		global $wpdb;
		return $wpdb->get_results( 'SELECT destination_slug, MAX(destination_label) AS destination_label FROM ' . self::table_name() . ' GROUP BY destination_slug ORDER BY destination_label ASC' );
	}

	private static function render_summary_cards( $summary ) {
		$cards = [
			'total'     => [ 'Total', 'all' ],
			'pending'   => [ 'Pendientes', 'pending' ],
			'prepared'  => [ 'Preparadas', 'prepared' ],
			'delivered' => [ 'Entregadas', 'delivered' ],
		];
		echo '<div class="pllc-delivery-cards">';
		foreach ( $cards as $key => $data ) {
			echo '<div class="pllc-delivery-card pllc-delivery-card--' . esc_attr( $data[1] ) . '"><span>' . esc_html( $data[0] ) . '</span><strong>' . absint( $summary[ $key ] ) . '</strong></div>';
		}
		echo '</div>';
	}

	private static function render_delivery_row( $delivery ) {
		$order      = wc_get_order( $delivery->order_id );
		$order_url  = $order && method_exists( $order, 'get_edit_order_url' ) ? $order->get_edit_order_url() : admin_url( 'post.php?post=' . absint( $delivery->order_id ) . '&action=edit' );
		$order_name = $order ? self::get_order_customer_label( $order ) : 'Pedido eliminado';
		$date       = self::format_delivery_date( $delivery->delivery_date, $delivery->delivery_day );
		$items      = json_decode( $delivery->item_summary, true );
		$items      = is_array( $items ) ? $items : [];
		$type_label = isset( self::type_labels()[ $delivery->order_type ] ) ? self::type_labels()[ $delivery->order_type ] : $delivery->order_type;
		?>
		<tr>
			<th scope="row" class="check-column"><input type="checkbox" name="delivery_ids[]" value="<?php echo absint( $delivery->id ); ?>"></th>
			<td data-colname="Fecha"><strong><?php echo esc_html( $date ); ?></strong></td>
			<td data-colname="Destino"><strong><?php echo esc_html( $delivery->destination_label ); ?></strong><span class="pllc-cell-subtitle"><?php echo esc_html( $type_label ); ?></span></td>
			<td data-colname="Pedido"><a href="<?php echo esc_url( $order_url ); ?>"><strong>#<?php echo absint( $delivery->order_id ); ?></strong></a><span class="pllc-cell-subtitle"><?php echo esc_html( $order_name ); ?></span></td>
			<td data-colname="Destinatario"><?php echo esc_html( $delivery->recipient_label ? $delivery->recipient_label : '—' ); ?></td>
			<td data-colname="Productos">
				<details class="pllc-delivery-products"><summary><?php echo esc_html( sprintf( '%d plato%s', absint( $delivery->item_count ), 1 === absint( $delivery->item_count ) ? '' : 's' ) ); ?></summary>
					<ul>
					<?php foreach ( $items as $item ) : ?>
						<li><strong><?php echo esc_html( isset( $item['quantity'] ) ? $item['quantity'] : 1 ); ?> ×</strong> <?php echo esc_html( isset( $item['name'] ) ? $item['name'] : '' ); ?><?php if ( ! empty( $item['details'] ) ) : ?><span><?php echo esc_html( $item['details'] ); ?></span><?php endif; ?><?php if ( ! empty( $item['meal'] ) ) : ?><span><?php echo esc_html( $item['meal'] ); ?></span><?php endif; ?></li>
					<?php endforeach; ?>
					</ul>
				</details>
			</td>
			<td data-colname="Estado"><?php echo self::status_badge( $delivery->status ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></td>
		</tr>
		<?php
	}

	private static function format_delivery_date( $date, $day ) {
		if ( class_exists( 'PLLC_Order_Rules' ) ) {
			$formatted = PLLC_Order_Rules::format_delivery_label( $day, $date );
			if ( $formatted ) {
				return $formatted;
			}
		}
		if ( self::is_valid_date( $date ) ) {
			$parsed = DateTimeImmutable::createFromFormat( '!Y-m-d', $date, wp_timezone() );
			return wp_date( 'D j/m/Y', $parsed->getTimestamp(), wp_timezone() );
		}
		return $day ? ucfirst( $day ) . ' (sin fecha)' : 'Sin fecha';
	}

	private static function render_pagination( $page, $total_pages, $total_items, $filters ) {
		if ( $total_pages <= 1 ) {
			echo '<div class="tablenav-pages one-page"><span class="displaying-num">' . esc_html( sprintf( '%d entrega%s', $total_items, 1 === $total_items ? '' : 's' ) ) . '</span></div>';
			return;
		}
		$args = [
			'page'            => 'pllc-deliveries',
			'delivery_date'   => $filters['delivery_date'],
			'order_type'      => $filters['order_type'],
			'destination'     => $filters['destination'],
			'delivery_status' => $filters['status'],
			's'               => $filters['search'],
			'paged'           => 999999999,
		];
		$base  = str_replace( '999999999', '%#%', add_query_arg( array_filter( $args, 'strlen' ), admin_url( 'admin.php' ) ) );
		$links = paginate_links( [ 'base' => $base, 'current' => $page, 'total' => $total_pages, 'type' => 'plain' ] );
		echo '<div class="tablenav-pages"><span class="displaying-num">' . esc_html( sprintf( '%d entregas', $total_items ) ) . '</span><span class="pagination-links">' . wp_kses_post( $links ) . '</span></div>';
	}

	public static function handle_bulk_update() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'No tenés permisos para realizar esta acción.', 'panza-llena-core' ) );
		}
		check_admin_referer( 'pllc_bulk_update_deliveries', 'pllc_delivery_nonce' );

		$status = isset( $_POST['bulk_action'] ) ? sanitize_key( wp_unslash( $_POST['bulk_action'] ) ) : '';
		$ids    = isset( $_POST['delivery_ids'] ) ? array_values( array_unique( array_filter( array_map( 'absint', (array) wp_unslash( $_POST['delivery_ids'] ) ) ) ) ) : [];
		if ( ! isset( self::status_labels()[ $status ] ) || 'cancelled' === $status || empty( $ids ) ) {
			self::redirect_after_bulk( 0, true );
		}

		global $wpdb;
		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		$rows         = $wpdb->get_results( $wpdb->prepare(
			'SELECT id, order_id, destination_label, delivery_date, status FROM ' . self::table_name() . " WHERE id IN ({$placeholders})",
			$ids
		) );
		$orders  = [];
		$updated = 0;
		$skipped = 0;
		$now     = current_time( 'mysql' );

		foreach ( $rows as $row ) {
			$order = wc_get_order( absint( $row->order_id ) );
			if ( ! $order || ! self::can_bulk_transition( $row->status, $order->get_status() ) ) {
				$skipped++;
				continue;
			}
			$result = $wpdb->update(
				self::table_name(),
				[ 'status' => $status, 'previous_status' => '', 'cancel_reason' => '', 'status_changed_at' => $now, 'updated_at' => $now ],
				[ 'id' => absint( $row->id ) ]
			);
			if ( false !== $result ) {
				$updated++;
				$order_id = absint( $row->order_id );
				if ( ! isset( $orders[ $order_id ] ) ) {
					$orders[ $order_id ] = [ 'order' => $order, 'deliveries' => [] ];
				}
				$orders[ $order_id ]['deliveries'][] = $row;
			}
		}

		foreach ( $orders as $order_id => $entry ) {
			$order      = $entry['order'];
			$deliveries = $entry['deliveries'];
			$order->add_order_note( sprintf(
				'%d unidad(es) de entrega marcada(s) como “%s” mediante acción en lote.',
				count( $deliveries ),
				self::status_labels()[ $status ]
			) );
			self::refresh_order_delivery_status( $order );
		}

		self::redirect_after_bulk( $updated, false, $skipped );
	}

	private static function redirect_after_bulk( $updated, $error, $skipped = 0 ) {
		$fallback = add_query_arg( 'page', 'pllc-deliveries', admin_url( 'admin.php' ) );
		$redirect = wp_validate_redirect( wp_get_referer(), $fallback );
		$redirect = remove_query_arg( [ 'pllc_updated', 'pllc_delivery_error', 'pllc_delivery_skipped' ], $redirect );
		$redirect = add_query_arg( $error ? 'pllc_delivery_error' : 'pllc_updated', $error ? 1 : absint( $updated ), $redirect );
		if ( $skipped ) {
			$redirect = add_query_arg( 'pllc_delivery_skipped', absint( $skipped ), $redirect );
		}
		wp_safe_redirect( $redirect );
		exit;
	}

	private static function render_admin_notice() {
		if ( ! empty( $_GET['pllc_delivery_error'] ) ) {
			echo '<div class="notice notice-error is-dismissible"><p>Seleccioná al menos una entrega y una acción válida.</p></div>';
		} elseif ( isset( $_GET['pllc_updated'] ) ) {
			$count = absint( $_GET['pllc_updated'] );
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( sprintf( 'Se actualizaron %d entregas.', $count ) ) . '</p></div>';
		}
		if ( ! empty( $_GET['pllc_delivery_skipped'] ) ) {
			$count = absint( $_GET['pllc_delivery_skipped'] );
			echo '<div class="notice notice-warning is-dismissible"><p>' . esc_html( sprintf( 'Se omitieron %d entregas canceladas o pertenecientes a pedidos inactivos.', $count ) ) . '</p></div>';
		}
	}

	private static function status_labels() {
		return [
			'pending'   => 'Pendiente',
			'prepared'  => 'Preparado',
			'delivered' => 'Entregado',
			'cancelled' => 'Cancelado',
		];
	}

	private static function type_labels() {
		return [
			'colegios'       => 'Colegios',
			'iteo_personal'  => 'ITEO Personal',
			'iteo_pacientes' => 'ITEO Pacientes',
			'particular'     => 'Particular',
		];
	}

	private static function aggregate_status_labels() {
		return [
			'none'      => 'Sin entregas',
			'pending'   => 'Pendiente',
			'prepared'  => 'Preparado',
			'partial'   => 'Parcial',
			'delivered' => 'Entregado',
			'cancelled' => 'Cancelado',
		];
	}

	private static function status_badge( $status, $aggregate = false ) {
		$labels = $aggregate ? self::aggregate_status_labels() : self::status_labels();
		$label  = isset( $labels[ $status ] ) ? $labels[ $status ] : ucfirst( $status );
		return '<span class="pllc-status-badge pllc-status-badge--' . esc_attr( sanitize_html_class( $status ) ) . '">' . esc_html( $label ) . '</span>';
	}

	public static function add_order_list_column( $columns ) {
		$new = [];
		foreach ( $columns as $key => $label ) {
			if ( 'order_total' === $key ) {
				$new['pllc_delivery_status'] = 'Entrega';
			}
			$new[ $key ] = $label;
		}
		if ( ! isset( $new['pllc_delivery_status'] ) ) {
			$new['pllc_delivery_status'] = 'Entrega';
		}
		return $new;
	}

	public static function render_legacy_order_list_column( $column, $post_id ) {
		if ( 'pllc_delivery_status' !== $column ) {
			return;
		}
		$order = wc_get_order( $post_id );
		self::render_order_delivery_badge( $order );
	}

	public static function render_hpos_order_list_column( $column, $order ) {
		if ( 'pllc_delivery_status' !== $column ) {
			return;
		}
		if ( ! is_a( $order, 'WC_Order' ) ) {
			$order = wc_get_order( $order );
		}
		self::render_order_delivery_badge( $order );
	}

	private static function render_order_delivery_badge( $order ) {
		if ( ! $order ) {
			echo '—';
			return;
		}
		$status = $order->get_meta( self::ORDER_META_STATUS, true );
		if ( ! $status || 'none' === $status ) {
			echo '—';
			return;
		}
		echo self::status_badge( $status, true ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}

	public static function register_order_meta_box() {
		$screens = [ 'shop_order' ];
		if ( function_exists( 'wc_get_page_screen_id' ) ) {
			$screens[] = wc_get_page_screen_id( 'shop-order' );
		}
		foreach ( array_unique( $screens ) as $screen ) {
			add_meta_box( 'pllc-order-deliveries', 'Entregas', [ __CLASS__, 'render_order_meta_box' ], $screen, 'side', 'default' );
		}
	}

	public static function render_order_meta_box( $object ) {
		$order = is_a( $object, 'WC_Order' ) ? $object : ( isset( $object->ID ) ? wc_get_order( $object->ID ) : false );
		if ( ! $order ) {
			return;
		}
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare(
			'SELECT * FROM ' . self::table_name() . ' WHERE order_id = %d ORDER BY delivery_date ASC, destination_label ASC',
			$order->get_id()
		) );

		if ( ! $rows ) {
			echo '<p>Este pedido no tiene unidades de entrega registradas.</p>';
			return;
		}
		echo '<ul class="pllc-order-deliveries-list">';
		foreach ( $rows as $row ) {
			echo '<li><div><strong>' . esc_html( $row->destination_label ) . '</strong><span>' . esc_html( self::format_delivery_date( $row->delivery_date, $row->delivery_day ) ) . '</span></div>' . self::status_badge( $row->status ) . '</li>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}
		echo '</ul>';
		$url = add_query_arg( [ 'page' => 'pllc-deliveries', 'show_all' => 1, 's' => $order->get_id(), 'delivery_status' => 'all' ], admin_url( 'admin.php' ) );
		echo '<p><a class="button" href="' . esc_url( $url ) . '">Administrar entregas</a></p>';
	}
}
