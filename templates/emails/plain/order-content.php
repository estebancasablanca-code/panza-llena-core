<?php
/** Alternativa de texto del mismo modelo de datos y el mismo orden que HTML. */
defined( 'ABSPATH' ) || exit;

echo PLLC_Emails::plain( $view['heading'] ) . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
if ( '' !== $view['number'] ) {
	echo PLLC_Emails::plain( sprintf( __( 'Pedido #%s', 'panza-llena-core' ), $view['number'] ) . ( $view['date'] ? ' · ' . $view['date'] : '' ) ) . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
}
if ( $view['mixed_title'] ) {
	echo "\n" . PLLC_Emails::plain( $view['mixed_title'] . "\n" . $view['mixed_explanation'] ) . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
}
foreach ( $view['groups'] as $group ) {
	echo "\n" . wc_strtoupper( PLLC_Emails::plain( $group['heading'] ) ) . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	foreach ( $group['fields'] as $field ) {
		echo PLLC_Emails::plain( $field ) . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}
	if ( $group['observations'] ) {
		echo PLLC_Emails::plain( __( 'Observaciones para la cocina', 'panza-llena-core' ) . ': ' . $group['observations'] ) . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}
	echo PLLC_Emails::plain( $group['context'] ) . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	foreach ( $group['items'] as $row ) {
		echo "\n" . PLLC_Emails::plain( $row['day'] . "\n" . $row['name'] ) . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		if ( $row['meal'] ) {
			echo PLLC_Emails::plain( sprintf( __( 'Comida: %s', 'panza-llena-core' ), $row['meal'] ) ) . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}
		echo PLLC_Emails::plain( __( 'Cantidad', 'panza-llena-core' ) . ': ' . $row['quantity_text'] . ( $group['show_prices'] ? ' · ' . $row['amount'] : '' ) ) . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		foreach ( [ $row['meta'], $row['purchase_note'] ] as $detail ) {
			if ( $detail ) {
				echo PLLC_Emails::plain( $detail ) . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			}
		}
	}
}
foreach ( $view['totals'] as $total ) {
	echo "\n" . PLLC_Emails::plain( $total['label'] . ' ' . ( isset( $total['meta'] ) ? $total['meta'] . ' ' : '' ) . $total['value'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
}
if ( $view['note'] ) {
	echo "\n\n" . PLLC_Emails::plain( __( 'Notas del pedido', 'panza-llena-core' ) . ":\n" . $view['note'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
}
if ( $view['admin_url'] ) {
	echo "\n\n" . PLLC_Emails::plain( __( 'Ver pedido', 'panza-llena-core' ) . ': ' . $view['admin_url'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
}
echo "\n\n";
