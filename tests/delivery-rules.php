<?php
define( 'ABSPATH', __DIR__ );

function sanitize_key( $value ) {
	return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $value ) );
}
function sanitize_text_field( $value ) { return trim( (string) $value ); }
function absint( $value ) { return abs( (int) $value ); }
function current_time( $type ) { return '2026-09-10 12:00:00'; }

class PLLC_Test_WPDB {
	public $prefix = 'wp_';
	public $last_query = '';
	public function prepare( $query, ...$args ) {
		$this->last_query = $query;
		return $query;
	}
	public function query( $query ) {
		$this->last_query = $query;
		return 1;
	}
}

$GLOBALS['wpdb'] = new PLLC_Test_WPDB();

require dirname( __DIR__ ) . '/includes/class-pllc-deliveries.php';

function check_delivery_rule( $condition, $message ) {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

function call_private_delivery_rule( $method, ...$args ) {
	$reflection = new ReflectionMethod( 'PLLC_Deliveries', $method );
	$reflection->setAccessible( true );
	return $reflection->invokeArgs( null, $args );
}

check_delivery_rule( PLLC_Deliveries::DB_VERSION === '1.1.0', 'Delivery lifecycle columns require schema version 1.1.0.' );
check_delivery_rule( call_private_delivery_rule( 'is_inactive_order_status', 'refunded' ), 'Refunded orders must be inactive for logistics.' );
check_delivery_rule( call_private_delivery_rule( 'is_inactive_order_status', 'trash' ), 'Trashed orders must be inactive for logistics.' );
check_delivery_rule( ! call_private_delivery_rule( 'is_inactive_order_status', 'processing' ), 'Processing orders must remain active.' );
check_delivery_rule( ! call_private_delivery_rule( 'can_bulk_transition', 'cancelled', 'processing' ), 'Bulk actions must not reactivate cancelled deliveries.' );
check_delivery_rule( ! call_private_delivery_rule( 'can_bulk_transition', 'pending', 'refunded' ), 'Bulk actions must not update inactive orders.' );
check_delivery_rule( call_private_delivery_rule( 'can_bulk_transition', 'prepared', 'processing' ), 'Prepared deliveries in active orders must remain editable.' );
check_delivery_rule( call_private_delivery_rule( 'should_complete_order', 'delivered', 'processing' ), 'Delivered logistics may complete a processing order.' );
check_delivery_rule( ! call_private_delivery_rule( 'should_complete_order', 'delivered', 'on-hold' ), 'Delivered logistics must never complete an on-hold order.' );

call_private_delivery_rule( 'cancel_active_deliveries', 25, 'order_refunded' );
check_delivery_rule( false !== strpos( $GLOBALS['wpdb']->last_query, "previous_status = status" ), 'Automatic cancellation must remember the prior delivery state.' );
check_delivery_rule( false !== strpos( $GLOBALS['wpdb']->last_query, "status IN ('pending','prepared')" ), 'Automatic cancellation must preserve delivered rows.' );

call_private_delivery_rule( 'restore_cancelled_deliveries', 25, [ 'trash' ] );
check_delivery_rule( false !== strpos( $GLOBALS['wpdb']->last_query, "status = 'cancelled' AND cancel_reason IN" ), 'Restoration must be restricted to its recorded reason.' );

call_private_delivery_rule( 'cancel_obsolete_deliveries', 25, [ str_repeat( 'a', 64 ) ] );
check_delivery_rule( false !== strpos( $GLOBALS['wpdb']->last_query, 'delivery_key NOT IN' ), 'Reconciliation must preserve current delivery groups.' );

echo "Delivery lifecycle rules passed.\n";
