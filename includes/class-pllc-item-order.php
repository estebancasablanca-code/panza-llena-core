<?php
/** Orden estable compartido por carrito, menú lateral y comunicaciones. */
defined( 'ABSPATH' ) || exit;

class PLLC_Item_Order {

	public static function group_key( $type, $form ) {
		if ( ! empty( $form['nombre_alumno'] ) ) {
			$school = isset( $form['colegio'] ) ? $form['colegio'] : '';
			return 'nombre:' . strtolower( trim( $form['nombre_alumno'] ) ) . '|' . strtolower( trim( $school ) );
		}
		return 'tipo:' . $type;
	}

	public static function day_index( $day ) {
		$days = [ 'lunes' => 1, 'martes' => 2, 'miercoles' => 3, 'jueves' => 4, 'viernes' => 5, 'sabado' => 6 ];
		return isset( $days[ $day ] ) ? $days[ $day ] : 99;
	}

	/** El adaptador sólo describe cada ítem; se conservan claves y objetos originales. */
	public static function sort( array $items, callable $describe ) {
		$groups = [];
		$entries = [];
		foreach ( $items as $key => $item ) {
			$data = $describe( $item );
			$group = self::group_key( $data['type'], $data['form'] );
			if ( ! isset( $groups[ $group ] ) ) {
				$groups[ $group ] = count( $groups );
			}
			$meals = [ 'almuerzo' => 1, 'cena' => 2 ];
			$meal = isset( $data['meals'][0] ) ? sanitize_key( $data['meals'][0] ) : '';
			$entries[] = [
				'key' => $key,
				'item' => $item,
				'rank' => [
					'particular' === $data['type'] ? 1 : 0,
					$groups[ $group ],
					$data['day_order'],
					'iteo_personal' === $data['type'] && isset( $meals[ $meal ] ) ? $meals[ $meal ] : 99,
					count( $entries ),
				],
			];
		}
		usort( $entries, static function ( $a, $b ) { return $a['rank'] <=> $b['rank']; } );
		$sorted = [];
		foreach ( $entries as $entry ) {
			$sorted[ $entry['key'] ] = $entry['item'];
		}
		return $sorted;
	}
}
