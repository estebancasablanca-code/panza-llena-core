<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Shortcodes para los controles de selección dentro de un Loop Item de
 * producto, y para el formulario lateral de cada rol.
 *
 * Los 3 primeros se usan SIN parámetros: toman el producto actual del loop
 * con get_the_ID() (Elementor configura el post global al renderizar cada
 * item de un Loop Grid, igual que cualquier loop de WordPress).
 *
 * - [pllc_variant_options]   → Colegios: radios Clásico/XL con precio real
 * - [pllc_meal_checkboxes]   → ITEO Personal: checkboxes Almuerzo/Cena
 * - [pllc_quantity_stepper]  → ITEO Pacientes: selector -/cantidad/+
 * - [pllc_order_form type="colegios|iteo_personal|iteo_pacientes"]
 *       → formulario lateral + botón real "Agregar al carrito"
 * - [pllc_particular_observations]
 *       → formulario general del pedido Particular: observaciones + envío al carrito
 *
 * Todos emiten los mismos data-attributes que usa pllc-frontend.js.
 */
class PLLC_Shortcodes {

	public static function init() {
		add_shortcode( 'pllc_variant_options', [ __CLASS__, 'render_variant_options' ] );
		add_shortcode( 'pllc_meal_checkboxes', [ __CLASS__, 'render_meal_checkboxes' ] );
		add_shortcode( 'pllc_quantity_stepper', [ __CLASS__, 'render_quantity_stepper' ] );
		add_shortcode( 'pllc_particular_day_selector', [ __CLASS__, 'render_particular_day_selector' ] );
		add_shortcode( 'pllc_order_form', [ __CLASS__, 'render_order_form' ] );
		add_shortcode( 'pllc_particular_observations', [ __CLASS__, 'render_particular_observations' ] );
	}

	public static function render_particular_day_selector() {
		$product_id = get_the_ID();
		if ( ! $product_id || ! class_exists( 'PLLC_Access' ) || ! PLLC_Access::is_particular_product( $product_id ) ) {
			return '';
		}

		$terms = wc_get_product_terms( $product_id, 'product_cat', [ 'fields' => 'slugs' ] );
		if ( is_wp_error( $terms ) ) {
			return '';
		}

		$labels = [
			'lunes' => 'Lunes', 'martes' => 'Martes', 'miercoles' => 'Miércoles',
			'jueves' => 'Jueves', 'viernes' => 'Viernes', 'sabado' => 'Sábado',
		];
		$days = [];
		foreach ( $labels as $slug => $label ) {
			if ( in_array( 'particulares-' . $slug, $terms, true ) ) {
				$days[ $slug ] = $label;
			}
		}
		if ( ! $days ) {
			return '';
		}

		$name = 'pllc-particular-day-' . $product_id;
		ob_start();
		?>
		<fieldset class="pllc-particular-day-selector" data-pllc-role="particular-day-selector" data-product-id="<?php echo esc_attr( $product_id ); ?>">
			<legend>Elegí el día</legend>
			<div class="pllc-particular-day-options">
				<?php foreach ( $days as $slug => $label ) : ?>
					<label class="pllc-particular-day-option">
						<input type="radio" name="<?php echo esc_attr( $name ); ?>" value="<?php echo esc_attr( $slug ); ?>" data-pllc-role="particular-day" <?php checked( $slug, array_key_first( $days ) ); ?>>
						<span data-pllc-day-label="<?php echo esc_attr( $slug ); ?>"><?php echo esc_html( $label ); ?></span>
					</label>
				<?php endforeach; ?>
			</div>
		</fieldset>
		<?php
		return ob_get_clean();
	}

	public static function render_variant_options() {
		$product_id = get_the_ID();
		$product    = $product_id ? wc_get_product( $product_id ) : null;

		if ( ! $product || ! $product->is_type( 'variable' ) ) {
			return '';
		}

		$group_name = 'pllc-variant-' . $product_id;

		ob_start();
		?>
		<div class="pllc-variant-options" data-pllc-role="variant-group" data-product-id="<?php echo esc_attr( $product_id ); ?>">
			<?php foreach ( $product->get_available_variations() as $variation_data ) :
				$variation = wc_get_product( $variation_data['variation_id'] );

				if ( ! $variation ) {
					continue;
				}

				$attribute_value = (string) reset( $variation_data['attributes'] );
				?>
				<label class="pllc-variant-option">
					<input
						type="radio"
						name="<?php echo esc_attr( $group_name ); ?>"
						data-pllc-role="variant"
						data-pllc-value="<?php echo esc_attr( $attribute_value ); ?>"
						data-pllc-variation-id="<?php echo esc_attr( $variation_data['variation_id'] ); ?>"
					/>
					<span class="pllc-variant-label"><?php echo esc_html( $attribute_value ); ?></span>
					<span class="pllc-variant-price"><?php echo wp_kses_post( $variation->get_price_html() ); ?></span>
				</label>
			<?php endforeach; ?>
		</div>
		<?php
		return ob_get_clean();
	}

	public static function render_meal_checkboxes() {
		$product_id = get_the_ID();
		$day        = self::get_iteo_personal_day( $product_id );

		ob_start();
		?>
		<div class="pllc-meal-checkboxes" data-pllc-role="check-group" data-product-id="<?php echo esc_attr( $product_id ); ?>" data-pllc-day="<?php echo esc_attr( $day ); ?>">
			<label class="pllc-meal-option">
				<input type="checkbox" data-pllc-role="check" data-pllc-value="almuerzo" />
				<span>Almuerzo</span>
			</label>
			<label class="pllc-meal-option">
				<input type="checkbox" data-pllc-role="check" data-pllc-value="cena" />
				<span>Cena</span>
			</label>
		</div>
		<?php
		return ob_get_clean();
	}

	private static function get_iteo_personal_day( $product_id ) {
		$terms = wc_get_product_terms( $product_id, 'product_cat', [ 'fields' => 'slugs' ] );
		if ( is_wp_error( $terms ) ) {
			return '';
		}
		$prefix = 'iteo-personal-';
		foreach ( $terms as $slug ) {
			if ( 0 === strpos( $slug, $prefix ) ) {
				return sanitize_title( substr( $slug, strlen( $prefix ) ) );
			}
		}
		return '';
	}

	public static function render_quantity_stepper() {
		$product_id = get_the_ID();

		ob_start();
		?>
		<div class="pllc-quantity-stepper" data-pllc-role="qty-group" data-product-id="<?php echo esc_attr( $product_id ); ?>">
			<button type="button" class="pllc-qty-btn" data-pllc-role="qty-minus">−</button>
			<input type="number" min="0" step="1" value="0" data-pllc-role="qty-value" />
			<button type="button" class="pllc-qty-btn" data-pllc-role="qty-plus">+</button>
		</div>
		<?php
		return ob_get_clean();
	}

	public static function render_order_form( $atts ) {
		$atts = shortcode_atts( [ 'type' => 'colegios' ], $atts, 'pllc_order_form' );
		$type = sanitize_key( $atts['type'] );
		$has_iteo_personal_order = 'iteo_personal' === $type && self::cart_has_form_type( 'iteo_personal' );
		$submit_label = $has_iteo_personal_order ? 'Actualizar carrito' : 'Agregar al carrito';

		ob_start();
		?>
		<form class="pllc-order-form" data-pllc-role="order-form" data-pllc-form-type="<?php echo esc_attr( $type ); ?>">

			<?php if ( 'colegios' === $type ) : ?>

				<div class="pllc-student-picker" data-pllc-role="student-picker" hidden>
					<span class="pllc-student-picker-title">¿Para quién es este pedido?</span>
					<div class="pllc-student-options" data-pllc-role="student-options"></div>
				</div>

				<label class="pllc-field" data-pllc-role="student-name-field">
					<span>Nombre del alumno</span>
					<input type="text" data-pllc-role="form-field" data-pllc-field="nombre_alumno" required>
				</label>

				<label class="pllc-field">
					<span>Colegio</span>
					<select data-pllc-role="form-field" data-pllc-field="colegio" data-pllc-cascade="colegio" required>
						<option value="">Seleccionar</option>
						<option value="Plaza Mayor">Plaza Mayor</option>
						<option value="Michelangelo">Michelangelo</option>
						<option value="CAE">CAE</option>
					</select>
				</label>

				<label class="pllc-field">
					<span>Nivel escolar</span>
					<select data-pllc-role="form-field" data-pllc-field="nivel" data-pllc-cascade="nivel" required disabled>
						<option value="">Elegí primero el colegio</option>
					</select>
				</label>

				<label class="pllc-field">
					<span>Curso</span>
					<select data-pllc-role="form-field" data-pllc-field="curso" data-pllc-cascade="curso" required disabled>
						<option value="">Elegí primero el nivel</option>
					</select>
				</label>

				<div class="pllc-field">
					<span>¿Te llevamos cubiertos descartables?</span>
					<label class="pllc-inline-option">
						<input type="radio" name="pllc-cubiertos" value="Sí" data-pllc-role="form-field" data-pllc-field="cubiertos">
						Sí
					</label>
					<label class="pllc-inline-option">
						<input type="radio" name="pllc-cubiertos" value="No" data-pllc-role="form-field" data-pllc-field="cubiertos">
						No
					</label>
				</div>

			<?php endif; ?>

			<label class="pllc-field">
				<span>Observaciones para la cocina</span>
				<textarea data-pllc-role="form-field" data-pllc-field="observaciones"></textarea>
			</label>

			<button type="button" class="pllc-submit-order" data-pllc-role="submit-order" <?php disabled( 'iteo_personal' === $type ); ?>><?php echo esc_html( $submit_label ); ?></button>

			<div class="pllc-form-message" data-pllc-role="form-message"></div>
		</form>
		<?php
		return ob_get_clean();
	}

	/** Formulario general para confirmar productos y observaciones de Particulares. */
	public static function render_particular_observations() {
		$value = '';
		if ( function_exists( 'WC' ) && WC()->session ) {
			$value = sanitize_textarea_field( (string) WC()->session->get( 'pllc_particular_observations', '' ) );
		}
		$has_particular_order = self::cart_has_form_type( 'particular' );
		$submit_label = $has_particular_order ? 'Actualizar carrito' : 'Agregar al carrito';

		ob_start();
		?>
		<form class="pllc-order-form pllc-particular-observations" data-pllc-role="order-form" data-pllc-form-type="particular">
			<label class="pllc-field">
				<span>Observaciones para la cocina</span>
				<textarea data-pllc-role="particular-observations-field" data-pllc-field="observaciones"><?php echo esc_textarea( $value ); ?></textarea>
			</label>
			<button type="button" class="pllc-submit-order" data-pllc-role="submit-order"><?php echo esc_html( $submit_label ); ?></button>
			<div class="pllc-form-message" data-pllc-role="form-message"></div>
		</form>
		<?php
		return ob_get_clean();
	}

	private static function cart_has_form_type( $form_type ) {
		if ( ! function_exists( 'WC' ) || ! WC()->cart ) {
			return false;
		}
		foreach ( WC()->cart->get_cart() as $cart_item ) {
			if ( ! empty( $cart_item['pllc_form_type'] ) && $form_type === $cart_item['pllc_form_type'] ) {
				return true;
			}
		}
		return false;
	}
}
