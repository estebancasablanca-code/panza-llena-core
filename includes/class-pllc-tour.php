<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Tour guiado de las páginas de platos.
 *
 * Cada recorrido usa selectores estables del plugin y omite automáticamente
 * los pasos cuyos elementos no estén presentes en la página de Elementor.
 */
class PLLC_Tour {

	const PAGE_SLUGS = [ 'colegios', 'iteo-personal', 'iteo-pacientes', 'particulares' ];

	public static function init() {
		add_action( 'wp_enqueue_scripts', [ __CLASS__, 'enqueue' ], 30 );
		add_action( 'wp_footer', [ __CLASS__, 'render_launcher' ] );
	}

	private static function current_page_slug() {
		// La tienda oficial de WooCommerce es el catálogo de Particulares.
		// is_page() devuelve false en esta vista porque WooCommerce la procesa
		// como un archivo de productos.
		if ( function_exists( 'is_shop' ) && is_shop() ) {
			return 'particulares';
		}

		if ( ! is_page() ) {
			return '';
		}

		$slug = get_post_field( 'post_name', get_queried_object_id() );
		return in_array( $slug, self::PAGE_SLUGS, true ) ? $slug : '';
	}

	public static function enqueue() {
		$slug = self::current_page_slug();
		if ( ! $slug ) {
			return;
		}

		wp_enqueue_style(
			'pllc-tour',
			PLLC_URL . 'assets/css/pllc-tour.css',
			[],
			PLLC_VERSION
		);

		wp_enqueue_script(
			'pllc-tour',
			PLLC_URL . 'assets/js/pllc-tour.js',
			[],
			PLLC_VERSION,
			true
		);

		wp_localize_script( 'pllc-tour', 'PLLC_Tour_Data', [
			'page'        => $slug,
			'storage_key' => 'pllc_tour_seen_' . str_replace( '-', '_', $slug ) . '_v1',
			// La ayuda queda disponible desde el lanzador, pero nunca interrumpe
			// automáticamente la navegación del usuario.
			'auto_start'  => false,
			'steps'       => self::steps_for( $slug ),
			'labels'      => [
				'previous' => 'Anterior',
				'next'     => 'Siguiente',
				'finish'   => 'Finalizar',
				'skip'     => 'Omitir tour',
				'close'    => 'Cerrar',
			],
		] );
	}

	public static function render_launcher() {
		if ( ! self::current_page_slug() ) {
			return;
		}
		?>
		<button type="button" class="pllc-tour-launcher" data-pllc-tour-start aria-label="Abrir ayuda para hacer el pedido">
			<span class="pllc-tour-launcher-icon" aria-hidden="true">?</span>
			<span>¿Cómo hacer tu pedido?</span>
		</button>
		<?php
	}

	private static function steps_for( $slug ) {
		$common_finish = [
			'selector' => '[data-pllc-role="submit-order"]',
			'title'    => 'Guardá el pedido',
			'text'     => 'Cuando termines de elegir los platos, usá este botón para guardar todo en el carrito.',
		];

		switch ( $slug ) {
			case 'colegios':
				return [
					[
						'selector' => '[data-pllc-role="student-picker"], [data-pllc-role="student-name-field"]',
						'title'    => 'Elegí para quién es el pedido',
						'text'     => 'Podés crear un alumno nuevo o seleccionar uno que ya tenga productos en el carrito.',
					],
					[
						'selector' => '[data-pllc-cascade="colegio"]',
						'title'    => 'Completá los datos escolares',
						'text'     => 'Elegí el colegio. Luego se habilitarán el nivel y el curso correspondientes.',
					],
					[
						'selector' => '.pllc-day-wrapper',
						'title'    => 'Recorré los días disponibles',
						'text'     => 'Los platos están separados por día. La fecha aparece junto al nombre de cada día.',
					],
					[
						'selector' => '[data-pllc-role="variant-group"]',
						'title'    => 'Elegí el tamaño',
						'text'     => 'Seleccioná Clásico o XL. Solo podés elegir un plato por día para cada alumno.',
					],
					[
						'selector' => '[data-pllc-role="add-btn"]',
						'title'    => 'Confirmá el plato',
						'text'     => 'Presioná “Seleccionar”. El botón cambiará de estado para mostrarte qué quedó seleccionado.',
					],
					$common_finish,
				];

			case 'iteo-personal':
				return [
					[
						'selector' => '.pllc-day-wrapper',
						'title'    => 'Elegí el día',
						'text'     => 'Los platos están organizados por día. Podés pedir un almuerzo y una cena para cada día.',
					],
					[
						'selector' => '[data-pllc-role="check-group"]',
						'title'    => 'Marcá Almuerzo o Cena',
						'text'     => 'Indicá en qué comida querés este plato. Al elegir otro para la misma comida y el mismo día, podrás confirmar el reemplazo.',
					],
					[
						'selector' => '[data-pllc-role="add-btn"]',
						'title'    => 'Confirmá cada elección',
						'text'     => 'Después de marcar Almuerzo o Cena, presioná “Seleccionar”. El botón te mostrará si el plato quedó confirmado o necesita un cambio.',
					],
					[
						'selector' => '[data-pllc-field="observaciones"]',
						'title'    => 'Observaciones para la cocina',
						'text'     => 'Este campo es opcional. Usalo para indicar cualquier detalle que deban tener en cuenta al preparar el pedido.',
					],
					$common_finish,
				];

			case 'iteo-pacientes':
				return [
					[
						'selector' => '.pllc-day-wrapper',
						'title'    => 'Elegí el día',
						'text'     => 'Los platos disponibles están separados por día y muestran la fecha correspondiente.',
					],
					[
						'selector' => '[data-pllc-role="qty-group"]',
						'title'    => 'Indicá la cantidad',
						'text'     => 'Usá los botones menos y más para elegir cuántas porciones necesitás de cada plato.',
					],
					[
						'selector' => '[data-pllc-role="add-btn"]',
						'title'    => 'Confirmá el plato',
						'text'     => 'Presioná “Seleccionar” para confirmar la cantidad seleccionada.',
					],
					[
						'selector' => '[data-pllc-field="observaciones"]',
						'title'    => 'Observaciones para la cocina',
						'text'     => 'Podés dejar una indicación general para la preparación del pedido.',
					],
					$common_finish,
				];

			case 'particulares':
				return [
					[
						'selector' => '.pllc-day-wrapper',
						'title'    => 'Recorré el menú por día',
						'text'     => 'Buscá el día que quieras y elegí entre los platos disponibles.',
					],
					[
						'selector' => '.add_to_cart_button, .single_add_to_cart_button',
						'title'    => 'Seleccioná el producto',
						'text'     => 'Elegí una cantidad y presioná “Seleccionar”. Después podrás guardar todas tus elecciones juntas.',
					],
					[
						'selector' => '[data-pllc-role="particular-observations-field"]',
						'title'    => 'Observaciones para la cocina',
						'text'     => 'Este campo es opcional y se aplica a todos los productos Particulares del pedido.',
					],
					[
						'selector' => '[data-pllc-role="order-form"][data-pllc-form-type="particular"] [data-pllc-role="submit-order"]',
						'title'    => 'Confirmá el pedido',
						'text'     => 'Cuando termines de elegir, presioná “Agregar al carrito” para guardar juntos los productos y las observaciones.',
					],
				];
		}

		return [];
	}
}
