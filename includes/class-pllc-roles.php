<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Maneja los roles custom del proyecto.
 *
 * "Particular" NO es un rol nuevo: es el rol `customer` estándar de
 * WooCommerce, o directamente un visitante sin sesión. Solo se crean
 * los 3 roles que necesitan comportamiento propio.
 */
class PLLC_Roles {

	const ROLES = [
		'iteo_paciente' => 'ITEO Paciente',
		'iteo_personal' => 'ITEO Personal',
		'colegio'       => 'Colegio',
	];

	public static function init() {
		// Por si el plugin se activó antes de que WooCommerce creara el rol
		// `customer` (orden de activación), verificamos también en `init`.
		add_action( 'init', [ __CLASS__, 'maybe_register_roles' ] );
	}

	public static function maybe_register_roles() {
		foreach ( self::ROLES as $slug => $label ) {
			if ( ! get_role( $slug ) ) {
				self::register_roles();
				return;
			}
		}
	}

	public static function register_roles() {
		$customer = get_role( 'customer' );
		$caps     = $customer ? $customer->capabilities : [ 'read' => true ];

		foreach ( self::ROLES as $slug => $label ) {
			if ( ! get_role( $slug ) ) {
				add_role( $slug, $label, $caps );
			}
		}
	}

	/**
	 * Devuelve los roles del usuario actual. Si no tiene sesión o no tiene
	 * ninguno de los 3 roles especiales, se lo trata como "particular".
	 */
	public static function get_current_user_roles() {
		if ( class_exists( 'PLLC_Code_Access' ) ) {
			$access_role = PLLC_Code_Access::get_role();
			if ( $access_role ) {
				return [ $access_role ];
			}
		}
		$user  = wp_get_current_user();
		$roles = (array) $user->roles;

		if ( empty( $roles ) ) {
			return [ 'particular' ];
		}

		return $roles;
	}
}
