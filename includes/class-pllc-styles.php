<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

class PLLC_Styles {
	const OPTION = 'pllc_style_settings';

	public static function init() {
		add_action( 'admin_menu', [ __CLASS__, 'admin_menu' ], 40 );
		add_action( 'admin_enqueue_scripts', [ __CLASS__, 'admin_assets' ] );
		add_action( 'admin_post_pllc_save_styles', [ __CLASS__, 'save' ] );
		add_action( 'admin_post_pllc_reset_styles', [ __CLASS__, 'reset' ] );
		add_action( 'wp_enqueue_scripts', [ __CLASS__, 'frontend_css' ], 30 );
	}

	public static function defaults() {
		return [
			'primary_bg'=>'#8a5800','primary_text'=>'#ffffff','primary_border'=>'#8a5800',
			'primary_hover_bg'=>'#ffffff','primary_hover_text'=>'#8a5800','primary_hover_border'=>'#8a5800',
			'action_bg'=>'#f04a24','action_text'=>'#ffffff','action_border'=>'#f04a24',
			'action_hover_bg'=>'#ffffff','action_hover_text'=>'#f04a24','action_hover_border'=>'#f04a24',
			'disabled_bg'=>'#dddddd','disabled_text'=>'#888888','disabled_border'=>'#dddddd',
			'button_border_width'=>1,'button_radius'=>10,'button_height'=>44,'button_font_size'=>15,'button_weight'=>600,
			'radio_color'=>'#1683ff','notice_bg'=>'#f2f9fc','notice_accent'=>'#50bde3',
			'access_color'=>'#1f6b45','access_exit'=>'#b42318','custom_css'=>'',
		];
	}

	public static function get() {
		return wp_parse_args( (array) get_option( self::OPTION, [] ), self::defaults() );
	}

	public static function admin_menu() {
		add_submenu_page( 'woocommerce', 'Estilos Panza Llena', 'Estilos Panza Llena', 'manage_options', 'pllc-styles', [ __CLASS__, 'page' ] );
	}

	public static function admin_assets( $hook ) {
		if ( 'woocommerce_page_pllc-styles' !== $hook ) { return; }
		wp_enqueue_style( 'wp-color-picker' );
		wp_enqueue_script( 'wp-color-picker' );
		wp_add_inline_style( 'wp-color-picker', '.pllc-style-intro{max-width:900px}.pllc-style-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(310px,1fr));gap:18px;max-width:1100px;margin:20px 0}.pllc-style-card{background:#fff;border:1px solid #dcdcde;border-radius:8px;padding:20px;box-shadow:0 1px 2px rgba(0,0,0,.04)}.pllc-style-card h2{margin:0 0 4px}.pllc-style-card>p{margin:0 0 16px;color:#646970}.pllc-style-fields{display:grid;grid-template-columns:minmax(145px,1fr) auto;gap:13px 16px;align-items:center}.pllc-style-fields label{font-weight:600}.pllc-style-fields input[type=number]{width:84px}.pllc-style-wide{max-width:1060px}.pllc-preview{display:flex;flex-wrap:wrap;gap:10px;margin-top:18px;padding-top:18px;border-top:1px solid #eee}.pllc-preview button{min-width:145px;padding:9px 16px}.pllc-advanced{margin-top:20px}.pllc-reset-form{margin-top:14px}.pllc-received-style-group{padding:14px 0;border-top:1px solid #dcdcde}.pllc-received-style-group summary{cursor:pointer}.pllc-received-style-group .pllc-style-fields{max-width:700px;margin-top:14px}.pllc-received-style-group input[type=text]:not(.pllc-color){max-width:100%;width:240px}@media(max-width:782px){.pllc-style-fields{grid-template-columns:1fr}}' );
		wp_add_inline_script( 'wp-color-picker', 'jQuery(function($){function v(k){return $("[name=\\"styles["+k+"]\\"]").val()}function p(){var c={borderWidth:v("button_border_width")+"px",borderStyle:"solid",borderRadius:v("button_radius")+"px",minHeight:v("button_height")+"px",fontSize:v("button_font_size")+"px",fontWeight:v("button_weight")};$(".pllc-preview button").css(c);$(".pllc-preview-primary").css({backgroundColor:v("primary_bg"),color:v("primary_text"),borderColor:v("primary_border")});$(".pllc-preview-action").css({backgroundColor:v("action_bg"),color:v("action_text"),borderColor:v("action_border")});$(".pllc-preview-disabled").css({backgroundColor:v("disabled_bg"),color:v("disabled_text"),borderColor:v("disabled_border")})}$(".pllc-color").wpColorPicker({change:function(){setTimeout(p,0)},clear:function(){setTimeout(p,0)}});$(document).on("input change",".pllc-style-form input",p);p()});' );
	}

	public static function page() {
		if ( ! current_user_can( 'manage_options' ) ) { return; }
		$s = self::get(); ?>
		<div class="wrap">
			<h1>Estilos Panza Llena</h1>
			<p class="pllc-style-intro">Configuración común para Colegios, Tienda, ITEO Personal e ITEO Pacientes. “Actualizar” usa el estilo de “Eliminar”; “Agregar al carrito” y “Actualizar carrito” usan el estilo principal.</p>
			<?php if ( isset( $_GET['updated'] ) ) : ?><div class="notice notice-success is-dismissible"><p>Los estilos se guardaron correctamente.</p></div><?php elseif ( isset( $_GET['reset'] ) ) : ?><div class="notice notice-success is-dismissible"><p>Se restauraron los estilos originales.</p></div><?php endif; ?>
			<form class="pllc-style-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="pllc_save_styles"><?php wp_nonce_field( 'pllc_save_styles' ); ?>
				<div class="pllc-style-grid">
					<?php self::color_card( 'Agregar y guardar', 'Agregar al pedido, Agregar al carrito y Actualizar carrito.', 'primary', $s ); ?>
					<?php self::color_card( 'Eliminar y actualizar', 'Eliminar y Actualizar en los botones de productos.', 'action', $s ); ?>
					<section class="pllc-style-card"><h2>Botones deshabilitados</h2><p>Productos y botón principal.</p><div class="pllc-style-fields"><?php self::color_field( 'Color de fondo', 'disabled_bg', $s ); self::color_field( 'Color del texto', 'disabled_text', $s ); self::color_field( 'Color del borde', 'disabled_border', $s ); ?></div></section>
					<section class="pllc-style-card"><h2>Forma y tipografía</h2><p>Medidas compartidas por todos los estados.</p><div class="pllc-style-fields"><?php self::number_field( 'Grosor del borde', 'button_border_width', $s, 0, 10, 'px' ); self::number_field( 'Radio de bordes', 'button_radius', $s, 0, 40, 'px' ); self::number_field( 'Altura mínima', 'button_height', $s, 30, 100, 'px' ); self::number_field( 'Tamaño del texto', 'button_font_size', $s, 10, 30, 'px' ); self::number_field( 'Peso tipográfico', 'button_weight', $s, 100, 900, '', 100 ); ?></div></section>
				</div>
				<section class="pllc-style-card pllc-style-wide"><h2>Vista previa</h2><p>Vista de los estados normales; los campos “Al pasar el cursor” controlan el hover del sitio.</p><div class="pllc-preview"><button type="button" class="pllc-preview-primary">Agregar al pedido</button><button type="button" class="pllc-preview-action">Eliminar / Actualizar</button><button type="button" class="pllc-preview-disabled" disabled>Deshabilitado</button></div></section>
				<?php PLLC_Order_Received_Styles::render( $s ); ?>
				<details class="pllc-style-card pllc-style-wide pllc-advanced"><summary><strong>Otros componentes y ajustes avanzados</strong></summary><div class="pllc-style-grid"><section><h3>Formularios y avisos</h3><div class="pllc-style-fields"><?php self::color_field( 'Selección de opciones', 'radio_color', $s ); self::color_field( 'Fondo de avisos', 'notice_bg', $s ); self::color_field( 'Acento de avisos', 'notice_accent', $s ); ?></div></section><section><h3>Acceso por código</h3><div class="pllc-style-fields"><?php self::color_field( 'Botón de acceso', 'access_color', $s ); self::color_field( 'Salir / error', 'access_exit', $s ); ?></div></section></div><p><label for="pllc-custom-css"><strong>CSS personalizado</strong></label><br>No incluyas la etiqueta <code>&lt;style&gt;</code>.</p><textarea id="pllc-custom-css" name="styles[custom_css]" class="large-text code" rows="8"><?php echo esc_textarea( $s['custom_css'] ); ?></textarea></details>
				<?php submit_button( 'Guardar estilos' ); ?>
			</form>
			<form class="pllc-reset-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" onsubmit="return confirm('¿Restaurar todos los estilos originales del plugin?');"><input type="hidden" name="action" value="pllc_reset_styles"><?php wp_nonce_field( 'pllc_reset_styles' ); submit_button( 'Restablecer estilos originales', 'secondary', 'submit', false ); ?></form>
		</div><?php
	}

	private static function color_card( $title, $description, $prefix, $s ) {
		echo '<section class="pllc-style-card"><h2>'.esc_html($title).'</h2><p>'.esc_html($description).'</p><div class="pllc-style-fields">';
		self::color_field( 'Fondo', $prefix.'_bg', $s ); self::color_field( 'Texto', $prefix.'_text', $s ); self::color_field( 'Borde', $prefix.'_border', $s );
		echo '</div><h3>Al pasar el cursor</h3><div class="pllc-style-fields">';
		self::color_field( 'Fondo', $prefix.'_hover_bg', $s ); self::color_field( 'Texto', $prefix.'_hover_text', $s ); self::color_field( 'Borde', $prefix.'_hover_border', $s );
		echo '</div></section>';
	}

	private static function color_field( $label, $key, $s ) {
		printf( '<label for="pllc-%1$s">%2$s</label><input id="pllc-%1$s" class="pllc-color" type="text" name="styles[%1$s]" value="%3$s" data-default-color="%4$s">', esc_attr($key), esc_html($label), esc_attr($s[$key]), esc_attr(self::defaults()[$key]) );
	}

	private static function number_field( $label, $key, $s, $min, $max, $suffix, $step=1 ) {
		printf( '<label for="pllc-%1$s">%2$s</label><span><input id="pllc-%1$s" type="number" name="styles[%1$s]" value="%3$s" min="%4$d" max="%5$d" step="%6$d"> %7$s</span>', esc_attr($key), esc_html($label), esc_attr($s[$key]), (int)$min, (int)$max, (int)$step, esc_html($suffix) );
	}

	public static function save() {
		if ( ! current_user_can( 'manage_options' ) ) { wp_die( 'Sin permiso.' ); }
		check_admin_referer( 'pllc_save_styles' );
		$p = isset($_POST['styles']) ? (array)wp_unslash($_POST['styles']) : []; $d=self::defaults(); $c=[];
		$colors=['primary_bg','primary_text','primary_border','primary_hover_bg','primary_hover_text','primary_hover_border','action_bg','action_text','action_border','action_hover_bg','action_hover_text','action_hover_border','disabled_bg','disabled_text','disabled_border','radio_color','notice_bg','notice_accent','access_color','access_exit'];
		foreach($colors as $k){ $c[$k]=sanitize_hex_color($p[$k]??'')?:$d[$k]; }
		$limits=['button_border_width'=>[0,10],'button_radius'=>[0,40],'button_height'=>[30,100],'button_font_size'=>[10,30],'button_weight'=>[100,900]];
		foreach($limits as $k=>$r){$v=isset($p[$k])?absint($p[$k]):$d[$k];$c[$k]=min($r[1],max($r[0],$v));}
		$c['custom_css']=isset($p['custom_css'])?wp_strip_all_tags($p['custom_css']):'';
		$c['order_received'] = PLLC_Order_Received_Styles::sanitize( $p['order_received'] ?? [] );
		update_option(self::OPTION,$c); wp_safe_redirect(add_query_arg(['page'=>'pllc-styles','updated'=>1],admin_url('admin.php'))); exit;
	}

	public static function reset() {
		if ( ! current_user_can( 'manage_options' ) ) { wp_die( 'Sin permiso.' ); }
		check_admin_referer('pllc_reset_styles'); delete_option(self::OPTION); wp_safe_redirect(add_query_arg(['page'=>'pllc-styles','reset'=>1],admin_url('admin.php'))); exit;
	}

	public static function frontend_css() {
		if ( ! wp_style_is( 'pllc-frontend', 'enqueued' ) ) { return; }
		$s=self::get(); $css=':root{';
		foreach(['primary_bg','primary_text','primary_border','primary_hover_bg','primary_hover_text','primary_hover_border','action_bg','action_text','action_border','action_hover_bg','action_hover_text','action_hover_border','disabled_bg','disabled_text','disabled_border'] as $k){$css.='--pllc-'.str_replace('_','-',$k).':'.$s[$k].';';}
		$css.='--pllc-button-border-width:'.absint($s['button_border_width']).'px;--pllc-button-radius:'.absint($s['button_radius']).'px;--pllc-button-height:'.absint($s['button_height']).'px;--pllc-button-font-size:'.absint($s['button_font_size']).'px;--pllc-button-weight:'.absint($s['button_weight']).';--pllc-radio:'.$s['radio_color'].';--pllc-notice-bg:'.$s['notice_bg'].';--pllc-notice-accent:'.$s['notice_accent'].';--pllc-access:'.$s['access_color'].';--pllc-access-exit:'.$s['access_exit'].';}';
		$css.='.pllc-access-box button{background:var(--pllc-access)!important;color:var(--pllc-primary-text)!important}.pllc-access-box .pllc-access-leave{background:var(--pllc-access-exit)!important}.pllc-access-error{color:var(--pllc-access-exit)!important}';
		if(''!==trim($s['custom_css'])){$css.="\n".$s['custom_css'];} wp_add_inline_style('pllc-frontend',$css);
	}
}
