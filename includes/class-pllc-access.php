<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Restringe el acceso a las páginas de producto según el rol del usuario.
 *
 * IMPORTANTE: la restricción se identifica por el SLUG de la página
 * (la parte de la URL), no por su ID. Esto significa que en WordPress
 * tenés que crear las páginas con estos slugs exactos:
 *
 *   - /colegios/
 *   - /iteo-personal/
 *   - /iteo-pacientes/
	 *   - /tienda/ (archivo oficial de WooCommerce para Particulares)
 *
 * Si querés usar otros slugs, ajustá el array PAGE_ROLE_MAP más abajo.
 */
class PLLC_Access {

	const PAGE_ROLE_MAP = [
		'colegios'       => [ 'colegio' ],
		'iteo-personal'  => [ 'iteo_personal' ],
		'iteo-pacientes' => [ 'iteo_paciente' ],
	];

	public static function init() {
		add_action( 'template_redirect', [ __CLASS__, 'restrict_pages' ] );
		add_filter( 'woocommerce_cart_item_permalink', [ __CLASS__, 'filter_cart_item_permalink' ], 10, 3 );
		add_action( 'pre_get_posts', [ __CLASS__, 'limit_shop_to_particulars' ], 20 );
	}

	public static function restrict_pages() {
		// Los administradores siempre pueden entrar: necesitan editar estas
		// páginas con Elementor, y el editor carga la propia URL del front
		// dentro de un iframe, así que sin esta excepción el redirect de
		// abajo también bloquea al propio editor.
		if ( current_user_can( 'manage_options' ) ) {
			return;
		}

		// Cualquier vista previa o modo editor de Elementor (iframe del
		// editor, preview de un cambio sin publicar, etc.) tampoco debe
		// restringirse, por la misma razón.
		if ( self::is_elementor_edit_context() ) {
			return;
		}

		if ( is_product() ) {
			self::restrict_single_product();
			return;
		}

		if ( ! is_page() ) {
			return;
		}

		$slug = get_post_field( 'post_name', get_queried_object_id() );

		if ( ! isset( self::PAGE_ROLE_MAP[ $slug ] ) ) {
			return; // No es una página restringida.
		}

		$allowed_roles = self::PAGE_ROLE_MAP[ $slug ];
		$user_roles    = PLLC_Roles::get_current_user_roles();

		if ( ! array_intersect( $allowed_roles, $user_roles ) ) {
			wp_safe_redirect( home_url( '/' ) );
			exit;
		}
	}

	/**
	 * Los productos de Particulares pueden abrirse desde cualquier tipo de
	 * acceso. Los productos institucionales vuelven a su catálogo correspondiente.
	 */
	private static function restrict_single_product() {
		$product_id = get_queried_object_id();
		$user_roles = PLLC_Roles::get_current_user_roles();

		if ( self::is_particular_product( $product_id ) ) {
			return;
		}

		wp_safe_redirect( home_url( '/' . self::get_catalog_slug_for_roles( $user_roles ) . '/' ) );
		exit;
	}

	private static function get_catalog_slug_for_roles( $roles ) {
		if ( in_array( 'iteo_personal', $roles, true ) ) {
			return 'iteo-personal';
		}
		if ( in_array( 'iteo_paciente', $roles, true ) ) {
			return 'iteo-pacientes';
		}
		if ( in_array( 'colegio', $roles, true ) ) {
			return 'colegios';
		}
		return 'tienda';
	}

	/**
	 * Quita el enlace del thumbnail y del nombre para cualquier línea que no
	 * deba abrir una ficha individual. WooCommerce conserva la imagen y texto.
	 */
	public static function filter_cart_item_permalink( $permalink, $cart_item, $cart_item_key ) {
		$product_id = isset( $cart_item['product_id'] ) ? absint( $cart_item['product_id'] ) : 0;

		if ( ! $product_id || ! self::is_particular_product( $product_id ) ) {
			return '';
		}

		return $permalink;
	}

	/**
	 * La tienda pública utiliza la consulta nativa de WooCommerce, limitada a
	 * la categoría Particulares y a todas sus subcategorías.
	 */
	public static function limit_shop_to_particulars( $query ) {
		if ( is_admin() || ! $query->is_main_query() || ! $query->is_post_type_archive( 'product' ) ) {
			return;
		}

		$tax_query   = (array) $query->get( 'tax_query' );
		$tax_query[] = [
			'taxonomy'         => 'product_cat',
			'field'            => 'slug',
			'terms'            => [ 'particulares' ],
			'include_children' => true,
			'operator'         => 'IN',
		];
		$query->set( 'tax_query', $tax_query );
	}

	public static function is_particular_product( $product_id ) {
		$particular = get_term_by( 'slug', 'particulares', 'product_cat' );
		if ( ! $particular || is_wp_error( $particular ) ) {
			return false;
		}

		$allowed_ids = [ (int) $particular->term_id ];
		$children    = get_term_children( $particular->term_id, 'product_cat' );
		if ( ! is_wp_error( $children ) ) {
			$allowed_ids = array_merge( $allowed_ids, array_map( 'intval', $children ) );
		}

		$product_terms = wp_get_post_terms( $product_id, 'product_cat', [ 'fields' => 'ids' ] );
		if ( is_wp_error( $product_terms ) ) {
			return false;
		}

		return (bool) array_intersect( $allowed_ids, array_map( 'intval', $product_terms ) );
	}

	private static function is_elementor_edit_context() {
		if ( isset( $_GET['elementor-preview'] ) ) {
			return true;
		}

		if ( class_exists( '\Elementor\Plugin' )
			&& \Elementor\Plugin::$instance->editor->is_edit_mode() ) {
			return true;
		}

		return false;
	}
}
