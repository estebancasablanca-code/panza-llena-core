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
 *   - /particulares/
 *
 * Si querés usar otros slugs, ajustá el array PAGE_ROLE_MAP más abajo.
 */
class PLLC_Access {

	const PAGE_ROLE_MAP = [
		'colegios'       => [ 'colegio' ],
		'iteo-personal'  => [ 'iteo_personal' ],
		'iteo-pacientes' => [ 'iteo_paciente' ],
		'particulares'   => [ 'colegio', 'customer', 'particular' ],
	];

	public static function init() {
		add_action( 'template_redirect', [ __CLASS__, 'restrict_pages' ] );
	}

	public static function restrict_pages() {
		if ( ! is_page() ) {
			return;
		}

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
