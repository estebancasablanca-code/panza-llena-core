<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Acceso institucional sin cuentas de WordPress, mediante códigos revocables. */
class PLLC_Code_Access {
	const OPTION = 'pllc_code_access';
	const COOKIE = 'pllc_access';
	const TTL    = 2592000; // 30 días.
	private static $internal_validated_add = false;

	const TYPES = [
		'colegio'       => [ 'label' => 'Colegios', 'page' => 'colegios' ],
		'iteo_personal' => [ 'label' => 'ITEO Personal', 'page' => 'iteo-personal' ],
		'iteo_paciente' => [ 'label' => 'ITEO Pacientes', 'page' => 'iteo-pacientes' ],
	];

	public static function init() {
		add_action( 'rest_api_init', [ __CLASS__, 'register_rest_routes' ] );
		add_filter( 'nav_menu_item_title', [ __CLASS__, 'filter_access_menu_title' ], 20, 4 );
		add_filter( 'nav_menu_item_title', [ __CLASS__, 'filter_institution_menu_title' ], 21, 4 );
		add_filter( 'wp_nav_menu_objects', [ __CLASS__, 'filter_institution_menu_items' ], 20, 2 );
		add_filter( 'nav_menu_link_attributes', [ __CLASS__, 'filter_institution_menu_link' ], 20, 4 );
		add_action( 'admin_menu', [ __CLASS__, 'admin_menu' ] );
		add_action( 'admin_post_pllc_save_access', [ __CLASS__, 'save_settings' ] );
		add_action( 'admin_post_nopriv_pllc_enter_code', [ __CLASS__, 'enter_code' ] );
		add_action( 'admin_post_pllc_enter_code', [ __CLASS__, 'enter_code' ] );
		add_action( 'admin_post_nopriv_pllc_leave_access', [ __CLASS__, 'leave_access' ] );
		add_action( 'admin_post_pllc_leave_access', [ __CLASS__, 'leave_access' ] );
		add_shortcode( 'pllc_access', [ __CLASS__, 'shortcode' ] );
		add_filter( 'woocommerce_add_to_cart_validation', [ __CLASS__, 'validate_native_add_to_cart' ], 20, 5 );
		add_action( 'wp_enqueue_scripts', [ __CLASS__, 'enqueue_styles' ], 30 );
		add_action( 'woocommerce_check_cart_items', [ __CLASS__, 'validate_cart_access' ] );
		add_action( 'wp_footer', [ __CLASS__, 'browser_cleanup_script' ], 99 );
		add_action( 'wp_loaded', [ __CLASS__, 'validate_existing_access' ], 20 );
	}

	/**
	 * Cambia el texto del ítem que abre el popup sin alterar el enlace ni el
	 * estilo del menú. El ítem debe tener la clase CSS pllc-acceso-menu.
	 */
	public static function filter_access_menu_title( $title, $item, $args, $depth ) {
		$classes = isset( $item->classes ) && is_array( $item->classes ) ? $item->classes : [];
		if ( ! in_array( 'pllc-acceso-menu', $classes, true ) ) {
			return $title;
		}

		return self::get_role() ? 'Mi acceso' : 'Ingresar';
	}

	/**
	 * Adapta el nombre del menú institucional al código activo.
	 * El ítem debe tener la clase CSS pllc-menu-institucion.
	 */
	public static function filter_institution_menu_title( $title, $item, $args, $depth ) {
		$classes = isset( $item->classes ) && is_array( $item->classes ) ? $item->classes : [];
		if ( ! in_array( 'pllc-menu-institucion', $classes, true ) ) {
			return $title;
		}

		$role = self::get_role();
		if ( ! $role || ! isset( self::TYPES[ $role ]['label'] ) ) {
			return $title;
		}

		return 'Menú ' . self::TYPES[ $role ]['label'];
	}

	/**
	 * Oculta "Menú institución" mientras no exista un acceso por código.
	 * El ítem debe tener la clase CSS pllc-menu-institucion.
	 */
	public static function filter_institution_menu_items( $items, $args ) {
		$role = self::get_role();
		if ( ! $role ) {
			return array_values( array_filter( $items, function ( $item ) {
				$classes = isset( $item->classes ) && is_array( $item->classes ) ? $item->classes : [];
				return ! in_array( 'pllc-menu-institucion', $classes, true );
			} ) );
		}

		$institution_page = self::TYPES[ $role ]['page'];
		foreach ( $items as $item ) {
			$classes = isset( $item->classes ) && is_array( $item->classes ) ? $item->classes : [];
			if ( in_array( 'pllc-menu-institucion', $classes, true ) && self::is_current_institution_page( $institution_page ) ) {
				$item->classes[] = 'current-menu-item';
				$item->classes[] = 'current_page_item';
				$item->current   = true;
			}
		}

		return $items;
	}

	/** Dirige el ítem institucional al catálogo correspondiente al código activo. */
	public static function filter_institution_menu_link( $atts, $item, $args, $depth ) {
		$classes = isset( $item->classes ) && is_array( $item->classes ) ? $item->classes : [];
		if ( ! in_array( 'pllc-menu-institucion', $classes, true ) ) {
			return $atts;
		}

		$role = self::get_role();
		if ( $role && isset( self::TYPES[ $role ]['page'] ) ) {
			$atts['href'] = home_url( '/' . self::TYPES[ $role ]['page'] . '/' );
			if ( self::is_current_institution_page( self::TYPES[ $role ]['page'] ) ) {
				$link_classes  = isset( $atts['class'] ) ? preg_split( '/\s+/', trim( $atts['class'] ) ) : [];
				$link_classes  = array_filter( $link_classes );
				$link_classes[] = 'elementor-item-active';
				$atts['class'] = implode( ' ', array_unique( $link_classes ) );
				$atts['aria-current'] = 'page';
			}
		}

		return $atts;
	}

	/** Comprueba el slug consultado sin depender sólo de las banderas de is_page(). */
	private static function is_current_institution_page( $slug ) {
		if ( is_page( $slug ) ) {
			return true;
		}

		$queried_id = get_queried_object_id();
		return $queried_id && $slug === get_post_field( 'post_name', $queried_id );
	}

	public static function validate_existing_access() {
		if ( ! empty( $_COOKIE[ self::COOKIE ] ) ) {
			self::get_role();
		}
	}

	public static function activate() {
		if ( false === get_option( self::OPTION, false ) ) {
			update_option( self::OPTION, [
				'colegio'       => [ 'hash' => '', 'version' => 1, 'active' => 1 ],
				'iteo_personal' => [ 'hash' => '', 'version' => 1, 'active' => 1 ],
				'iteo_paciente' => [ 'hash' => '', 'version' => 1, 'active' => 1 ],
			] );
		}
	}

	private static function settings() {
		$value = get_option( self::OPTION, [] );
		return is_array( $value ) ? $value : [];
	}

	public static function get_role() {
		if ( empty( $_COOKIE[ self::COOKIE ] ) ) {
			return '';
		}
		$raw   = base64_decode( sanitize_text_field( wp_unslash( $_COOKIE[ self::COOKIE ] ) ), true );
		$parts = $raw ? explode( '|', $raw ) : [];
		if ( 4 !== count( $parts ) ) {
			self::invalidate_access();
			return '';
		}
		list( $type, $version, $expires, $signature ) = $parts;
		if ( ! isset( self::TYPES[ $type ] ) || time() > (int) $expires ) {
			self::invalidate_access();
			return '';
		}
		$expected = hash_hmac( 'sha256', "$type|$version|$expires", wp_salt( 'auth' ) );
		$options  = self::settings();
		$current  = isset( $options[ $type ] ) ? $options[ $type ] : [];
		if ( ! hash_equals( $expected, $signature ) || empty( $current['active'] ) || (int) $version !== (int) ( $current['version'] ?? 0 ) ) {
			self::invalidate_access();
			return '';
		}
		return $type;
	}

	private static function set_cookie( $type, $version ) {
		$expires   = time() + self::TTL;
		$signature = hash_hmac( 'sha256', "$type|$version|$expires", wp_salt( 'auth' ) );
		$value     = base64_encode( "$type|$version|$expires|$signature" );
		$args = [ 'expires' => $expires, 'path' => '/', 'secure' => is_ssl(), 'httponly' => true, 'samesite' => 'Lax' ];
		if ( defined( 'COOKIE_DOMAIN' ) && is_string( COOKIE_DOMAIN ) && '' !== COOKIE_DOMAIN ) { $args['domain'] = COOKIE_DOMAIN; }
		setcookie( self::COOKIE, $value, $args );
		$_COOKIE[ self::COOKIE ] = $value;
	}

	private static function clear_cookie() {
		$args = [ 'expires' => time() - 3600, 'path' => '/', 'secure' => is_ssl(), 'httponly' => true, 'samesite' => 'Lax' ];
		if ( defined( 'COOKIE_DOMAIN' ) && is_string( COOKIE_DOMAIN ) && '' !== COOKIE_DOMAIN ) { $args['domain'] = COOKIE_DOMAIN; }
		setcookie( self::COOKIE, '', $args );
		unset( $_COOKIE[ self::COOKIE ] );
	}

	/** Elimina el carrito y los datos temporales cuando termina el acceso. */
	private static function invalidate_access() {
		self::clear_cookie();
		self::mark_browser_cleanup();
		if ( did_action( 'wp_loaded' ) ) {
			self::clear_cart();
		} else {
			add_action( 'wp_loaded', [ __CLASS__, 'clear_cart' ], 99 );
		}
	}

	public static function clear_cart() {
		if ( ! function_exists( 'WC' ) ) {
			return;
		}

		// admin-post.php no siempre carga el carrito aunque la sesión de
		// WooCommerce exista. Lo cargamos expresamente antes de vaciarlo.
		if ( ( ! WC()->session || ! WC()->cart ) && function_exists( 'wc_load_cart' ) ) {
			wc_load_cart();
		}

		if ( WC()->cart ) {
			WC()->cart->empty_cart( true );
			WC()->cart->set_session();
		}

		// Respaldo para cualquier contexto donde WooCommerce no haya podido
		// construir el objeto carrito, pero sí tenga disponible su sesión.
		if ( WC()->session ) {
			foreach ( [ 'cart', 'cart_totals', 'applied_coupons', 'coupon_discount_totals', 'coupon_discount_tax_totals', 'removed_cart_contents', 'pllc_particular_observations' ] as $key ) {
				WC()->session->__unset( $key );
			}
			WC()->session->save_data();
		}
	}

	private static function mark_browser_cleanup() {
		$args = [ 'expires' => time() + 300, 'path' => '/', 'secure' => is_ssl(), 'httponly' => false, 'samesite' => 'Lax' ];
		if ( defined( 'COOKIE_DOMAIN' ) && is_string( COOKIE_DOMAIN ) && '' !== COOKIE_DOMAIN ) { $args['domain'] = COOKIE_DOMAIN; }
		setcookie( 'pllc_clear_order', '1', $args );
		$_COOKIE['pllc_clear_order'] = '1';
	}

	public static function enter_code() {
		check_admin_referer( 'pllc_enter_code' );
		$rate_key = 'pllc_attempt_' . md5( isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : 'unknown' );
		$attempts = (int) get_transient( $rate_key );
		if ( $attempts >= 8 ) {
			wp_safe_redirect( add_query_arg( 'pllc_access_error', 'limit', wp_get_referer() ?: home_url( '/' ) ) );
			exit;
		}
		$code    = isset( $_POST['pllc_code'] ) ? trim( sanitize_text_field( wp_unslash( $_POST['pllc_code'] ) ) ) : '';
		$options = self::settings();
		foreach ( self::TYPES as $type => $data ) {
			$row = $options[ $type ] ?? [];
			if ( ! empty( $row['active'] ) && ! empty( $row['hash'] ) && wp_check_password( $code, $row['hash'] ) ) {
				delete_transient( $rate_key );
				$previous_type = self::get_cookie_type_unverified();
				if ( $previous_type && $previous_type !== $type ) {
					self::invalidate_access();
				}
				self::set_cookie( $type, (int) $row['version'] );
				$redirect_url = home_url( '/' . $data['page'] . '/' );
				wp_safe_redirect( $redirect_url );
				exit;
			}
		}
		set_transient( $rate_key, $attempts + 1, 15 * MINUTE_IN_SECONDS );
		$url = wp_get_referer() ?: home_url( '/' );
		wp_safe_redirect( add_query_arg( 'pllc_access_error', '1', $url ) );
		exit;
	}

	/** Registra la validación usada por el formulario dentro del popup. */
	public static function register_rest_routes() {
		register_rest_route( 'pllc/v1', '/access', [
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => [ __CLASS__, 'rest_enter_code' ],
			'permission_callback' => '__return_true',
			'args'                => [
				'code' => [
					'type'              => 'string',
					'required'          => true,
					'sanitize_callback' => 'sanitize_text_field',
				],
			],
		] );
	}

	/** Valida el código sin abandonar la página que contiene el popup. */
	public static function rest_enter_code( WP_REST_Request $request ) {
		$rate_key = 'pllc_attempt_' . md5( isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : 'unknown' );
		$attempts = (int) get_transient( $rate_key );
		if ( $attempts >= 8 ) {
			return new WP_Error( 'pllc_access_limit', 'Demasiados intentos. Esperá 15 minutos y probá nuevamente.', [ 'status' => 429 ] );
		}

		$code    = trim( (string) $request->get_param( 'code' ) );
		$options = self::settings();
		foreach ( self::TYPES as $type => $data ) {
			$row = $options[ $type ] ?? [];
			if ( ! empty( $row['active'] ) && ! empty( $row['hash'] ) && wp_check_password( $code, $row['hash'] ) ) {
				delete_transient( $rate_key );
				$previous_type = self::get_cookie_type_unverified();
				if ( $previous_type && $previous_type !== $type ) {
					self::invalidate_access();
				}
				self::set_cookie( $type, (int) $row['version'] );
				return rest_ensure_response( [
					'success'  => true,
					'redirect' => home_url( '/' . $data['page'] . '/' ),
				] );
			}
		}

		set_transient( $rate_key, $attempts + 1, 15 * MINUTE_IN_SECONDS );
		return new WP_Error( 'pllc_access_invalid', 'El código no es válido o está desactivado.', [ 'status' => 400 ] );
	}

	private static function get_cookie_type_unverified() {
		if ( empty( $_COOKIE[ self::COOKIE ] ) ) { return ''; }
		$raw = base64_decode( sanitize_text_field( wp_unslash( $_COOKIE[ self::COOKIE ] ) ), true );
		$parts = $raw ? explode( '|', $raw ) : [];
		return ! empty( $parts[0] ) && isset( self::TYPES[ $parts[0] ] ) ? $parts[0] : '';
	}

	public static function leave_access() {
		check_admin_referer( 'pllc_leave_access' );
		self::invalidate_access();
		wp_safe_redirect( home_url( '/' ) );
		exit;
	}

	public static function shortcode() {
		$role = self::get_role();
		if ( $role ) {
			$label = self::TYPES[ $role ]['label'];
			$change_form_id = wp_unique_id( 'pllc-change-code-' );
			return '<div class="pllc-access-box pllc-access-active">'
				. '<span>Acceso: <strong>' . esc_html( $label ) . '</strong></span>'
				. '<div class="pllc-access-actions">'
				. '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="pllc_leave_access">' . wp_nonce_field( 'pllc_leave_access', '_wpnonce', true, false ) . '<button type="submit" class="pllc-access-leave">Salir</button></form>'
				. '<button type="button" class="pllc-access-change" aria-expanded="false" aria-controls="' . esc_attr( $change_form_id ) . '">Cambiar código</button>'
				. '</div>'
				. '<form id="' . esc_attr( $change_form_id ) . '" class="pllc-access-form pllc-access-change-form" method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" data-pllc-endpoint="' . esc_url( rest_url( 'pllc/v1/access' ) ) . '" hidden>'
				. '<label for="' . esc_attr( $change_form_id ) . '-input">Ingresá el nuevo código proporcionado por tu institución</label>'
				. '<div><input id="' . esc_attr( $change_form_id ) . '-input" name="pllc_code" type="text" autocomplete="off" required><button type="submit">Ingresar con código</button></div>'
				. '<input type="hidden" name="action" value="pllc_enter_code">' . wp_nonce_field( 'pllc_enter_code', '_wpnonce', true, false )
				. '<p class="pllc-access-error" role="alert" hidden></p>'
				. '</form></div>';
		}
		$error = '';
		if ( isset( $_GET['pllc_access_error'] ) ) {
			$error = 'limit' === sanitize_key( wp_unslash( $_GET['pllc_access_error'] ) ) ? 'Demasiados intentos. Esperá 15 minutos y probá nuevamente.' : 'El código no es válido o está desactivado.';
			$error = '<p class="pllc-access-error">' . esc_html( $error ) . '</p>';
		}
		return '<div class="pllc-access-box"><form class="pllc-access-form" method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" data-pllc-endpoint="' . esc_url( rest_url( 'pllc/v1/access' ) ) . '"><label for="pllc-code">Ingresá el código proporcionado por tu institución</label><div><input id="pllc-code" name="pllc_code" type="text" autocomplete="off" required><button type="submit">Ingresar con código</button></div><input type="hidden" name="action" value="pllc_enter_code">' . wp_nonce_field( 'pllc_enter_code', '_wpnonce', true, false ) . $error . '<p class="pllc-access-error" role="alert" hidden></p></form></div>';
	}

	public static function enqueue_styles() {
		wp_add_inline_style( 'pllc-frontend', '.pllc-access-box{display:flex;align-items:center;gap:12px;flex-wrap:wrap}.pllc-access-box form{margin:0}.pllc-access-active{align-items:flex-start;flex-direction:column}.pllc-access-actions{display:flex;align-items:center;gap:8px;flex-wrap:wrap}.pllc-access-form label{display:block;margin-bottom:6px}.pllc-access-form>div{display:flex;gap:8px;flex-wrap:wrap}.pllc-access-form input{min-height:42px;padding:8px 12px}.pllc-access-box button{min-height:42px;border:0;border-radius:4px;padding:8px 16px;background:#1f6b45;color:#fff;cursor:pointer}.pllc-access-box .pllc-access-leave{background:#b42318}.pllc-access-box .pllc-access-change{background:#1f6b45}.pllc-access-change-form{width:100%;margin-top:4px!important}.pllc-access-error{color:#b42318;margin:8px 0 0}' );
		wp_add_inline_script( 'pllc-frontend', 'document.addEventListener("click",function(event){var button=event.target.closest(".pllc-access-change");if(!button){return;}var formId=button.getAttribute("aria-controls");var form=formId?document.getElementById(formId):null;if(!form){return;}var willOpen=form.hasAttribute("hidden");if(willOpen){form.removeAttribute("hidden");button.setAttribute("aria-expanded","true");var input=form.querySelector("input[name=pllc_code]");if(input){input.focus();}}else{form.setAttribute("hidden","");button.setAttribute("aria-expanded","false");}});document.addEventListener("submit",function(event){var form=event.target.closest(".pllc-access-form[data-pllc-endpoint]");if(!form){return;}event.preventDefault();var input=form.querySelector("input[name=pllc_code]");var button=form.querySelector("button[type=submit]");var error=form.querySelector(".pllc-access-error[role=alert]");if(!input){return;}if(button){button.disabled=true;}if(error){error.hidden=true;error.textContent="";}fetch(form.dataset.pllcEndpoint,{method:"POST",headers:{"Content-Type":"application/json","Accept":"application/json"},credentials:"same-origin",body:JSON.stringify({code:input.value})}).then(function(response){return response.json().then(function(data){return {ok:response.ok,data:data};});}).then(function(result){if(result.ok&&result.data&&result.data.success&&result.data.redirect){window.location.assign(result.data.redirect);return;}throw new Error(result.data&&result.data.message?result.data.message:"No se pudo validar el código.");}).catch(function(requestError){if(error){error.textContent=requestError.message||"No se pudo validar el código.";error.hidden=false;}}).finally(function(){if(button){button.disabled=false;}});});' );
	}

	public static function browser_cleanup_script() {
		if ( empty( $_COOKIE['pllc_clear_order'] ) ) { return; }
		echo '<script>(function(){try{sessionStorage.removeItem("pllc_restore_student_after_reload");sessionStorage.removeItem("pllc_reset_form_after_submit");}catch(e){}document.cookie="pllc_clear_order=; Max-Age=0; path=/; SameSite=Lax";}());</script>';
	}

	public static function admin_menu() {
		add_submenu_page( 'woocommerce', 'Accesos Panza Llena', 'Accesos Panza Llena', 'manage_options', 'pllc-access', [ __CLASS__, 'admin_page' ] );
	}

	public static function admin_page() {
		$options = self::settings();
		echo '<div class="wrap"><h1>Accesos Panza Llena</h1><p>Ingresá un código nuevo solamente cuando quieras crearlo o reemplazarlo. Al reemplazarlo se cierran inmediatamente todos los accesos anteriores.</p><form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="pllc_save_access">';
		wp_nonce_field( 'pllc_save_access' );
		echo '<table class="widefat striped"><thead><tr><th>Acceso</th><th>Estado</th><th>Código activo</th><th>Reemplazar código</th></tr></thead><tbody>';
		foreach ( self::TYPES as $type => $data ) {
			$row = $options[ $type ] ?? [];
			$visible_code = ! empty( $row['display_code'] ) ? '<code>' . esc_html( $row['display_code'] ) . '</code>' : ( ! empty( $row['hash'] ) ? '<em>No disponible: reemplazalo una vez para visualizarlo</em>' : '<em>Sin configurar</em>' );
			echo '<tr><td><strong>' . esc_html( $data['label'] ) . '</strong></td><td><label><input type="checkbox" name="active[' . esc_attr( $type ) . ']" value="1" ' . checked( ! empty( $row['active'] ), true, false ) . '> Activo</label></td><td>' . $visible_code . '</td><td><input type="text" class="regular-text" name="code[' . esc_attr( $type ) . ']" autocomplete="new-password" placeholder="Dejar vacío para conservar"></td></tr>';
		}
		echo '</tbody></table>'; submit_button( 'Guardar accesos' ); echo '</form><p><strong>Shortcode para el encabezado:</strong> <code>[pllc_access]</code></p></div>';
	}

	public static function save_settings() {
		if ( ! current_user_can( 'manage_options' ) ) { wp_die( 'Sin permiso.' ); }
		check_admin_referer( 'pllc_save_access' );
		$options = self::settings();
		foreach ( self::TYPES as $type => $data ) {
			$row = $options[ $type ] ?? [ 'hash' => '', 'version' => 1 ];
			$new_code = isset( $_POST['code'][ $type ] ) ? trim( sanitize_text_field( wp_unslash( $_POST['code'][ $type ] ) ) ) : '';
			if ( '' !== $new_code ) {
				$row['hash']    = wp_hash_password( $new_code );
				$row['display_code'] = $new_code;
				$row['version'] = (int) ( $row['version'] ?? 0 ) + 1;
			}
			$row['active'] = isset( $_POST['active'][ $type ] ) ? 1 : 0;
			$options[ $type ] = $row;
		}
		update_option( self::OPTION, $options );
		wp_safe_redirect( add_query_arg( [ 'page' => 'pllc-access', 'updated' => 'true' ], admin_url( 'admin.php' ) ) );
		exit;
	}

	public static function form_type_allowed( $form_type ) {
		// Los administradores deben poder probar todos los recorridos sin
		// ingresar ni conservar un código de acceso institucional.
		if ( current_user_can( 'manage_options' ) ) {
			return true;
		}

		$roles = PLLC_Roles::get_current_user_roles();
		$map = [ 'colegios' => 'colegio', 'iteo_personal' => 'iteo_personal', 'iteo_pacientes' => 'iteo_paciente', 'particular' => 'particular' ];
		$needed = $map[ $form_type ] ?? '';
		if ( 'particular' === $needed ) {
			return true;
		}
		return $needed && in_array( $needed, $roles, true );
	}

	public static function validate_native_add_to_cart( $passed, $product_id, $quantity, $variation_id = 0, $variations = [] ) {
		if ( ! $passed ) {
			return false;
		}

		// Los endpoints propios validan el lote completo antes de llamar a
		// WC_Cart::add_to_cart(). Este filtro protege las demás vías nativas.
		if ( self::$internal_validated_add ) {
			return true;
		}

		$form_type = isset( $_REQUEST['pllc_form_type'] ) ? sanitize_key( wp_unslash( $_REQUEST['pllc_form_type'] ) ) : '';
		if ( ! $form_type && class_exists( 'PLLC_Order_Rules' ) ) {
			$form_type = PLLC_Order_Rules::infer_native_form_type( $product_id );
		}
		if ( ! $form_type || ! self::form_type_allowed( $form_type ) ) {
			wc_add_notice( 'Este producto no está disponible para tu tipo de acceso.', 'error' );
			return false;
		}

		$selection = PLLC_Order_Rules::validate_selection( [
			'product_id' => $product_id,
			'variation_id' => $variation_id,
			'qty' => $quantity,
			'day' => isset( $_REQUEST['pllc_day'] ) ? wp_unslash( $_REQUEST['pllc_day'] ) : '',
			'delivery_date' => isset( $_REQUEST['pllc_delivery_date'] ) ? wp_unslash( $_REQUEST['pllc_delivery_date'] ) : '',
			'meals' => isset( $_REQUEST['pllc_meals'] ) ? (array) wp_unslash( $_REQUEST['pllc_meals'] ) : [],
		], $form_type );
		if ( is_wp_error( $selection ) ) {
			wc_add_notice( $selection->get_error_message(), 'error' );
			return false;
		}
		return true;
	}

	public static function begin_internal_validated_add() {
		self::$internal_validated_add = true;
	}

	public static function end_internal_validated_add() {
		self::$internal_validated_add = false;
	}

	public static function validate_cart_access() {
		if ( ! function_exists( 'WC' ) || ! WC()->cart ) { return; }
		foreach ( WC()->cart->get_cart() as $item ) {
			$type = ! empty( $item['pllc_form_type'] ) ? sanitize_key( $item['pllc_form_type'] ) : '';
			if ( ! self::form_type_allowed( $type ) ) {
				wc_add_notice( 'Tu acceso actual no permite finalizar uno o más productos del carrito. Ingresá el código correspondiente para continuar.', 'error' );
				return;
			}
			$validated = PLLC_Order_Rules::validate_cart_item( $item );
			if ( is_wp_error( $validated ) ) {
				wc_add_notice( $validated->get_error_message(), 'error' );
				return;
			}
		}
	}
}
