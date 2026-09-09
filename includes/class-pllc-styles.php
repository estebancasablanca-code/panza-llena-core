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
			'primary_bg'=>'#8a5800','primary_text'=>'#ffffff','primary_border'=>'#8a5800','primary_hover_bg'=>'#ffffff','primary_hover_text'=>'#8a5800','primary_hover_border'=>'#8a5800',
			'action_bg'=>'#f04a24','action_text'=>'#ffffff','action_border'=>'#f04a24','action_hover_bg'=>'#ffffff','action_hover_text'=>'#f04a24','action_hover_border'=>'#f04a24',
			'disabled_bg'=>'#dddddd','disabled_text'=>'#888888','disabled_border'=>'#dddddd','button_border_width'=>1,'button_radius'=>10,'button_height'=>44,'button_font_size'=>15,'button_weight'=>600,
			'radio_color'=>'#1683ff','notice_bg'=>'#f2f9fc','notice_accent'=>'#50bde3','access_color'=>'#1f6b45','access_exit'=>'#b42318',
			'field_border_color'=>'#cccccc','field_border_width'=>1,'field_radius'=>4,'field_padding'=>8,'form_error_color'=>'#c0392b','form_info_color'=>'#9a6700',
			'cart_group_title_color'=>'#5bc0de','cart_group_title_size'=>1.3,'cart_group_title_weight'=>700,'cart_group_title_gap'=>4,'cart_summary_color'=>'#555555','cart_summary_size'=>0.9,'cart_summary_gap'=>10,'cart_separator_color'=>'#dddddd','cart_group_padding_top'=>16,'cart_group_padding_bottom'=>8,
			'checkout_group_radius'=>6,'checkout_group_padding_y'=>14,'checkout_group_padding_x'=>16,'checkout_group_title_size'=>1.15,'checkout_group_title_gap'=>5,'checkout_summary_color'=>'#4f5d66','checkout_summary_size'=>0.9,'checkout_row_bg'=>'#ffffff','checkout_row_separator'=>'#e7edf0','checkout_row_padding_top'=>15,'checkout_row_padding_x'=>12,'checkout_row_padding_bottom'=>17,'checkout_day_bg'=>'#eef3f6','checkout_day_size'=>0.82,'checkout_day_radius'=>999,'checkout_day_padding_y'=>3,'checkout_day_padding_x'=>8,
			'shipping_notice_bg'=>'#fff8e8','shipping_notice_accent'=>'#f3a712','shipping_notice_text'=>'#59636b','shipping_notice_radius'=>5,'shipping_notice_padding_y'=>10,'shipping_notice_padding_x'=>12,'custom_css'=>'',
		];
	}

	private static function color_keys() {
		return ['primary_bg','primary_text','primary_border','primary_hover_bg','primary_hover_text','primary_hover_border','action_bg','action_text','action_border','action_hover_bg','action_hover_text','action_hover_border','disabled_bg','disabled_text','disabled_border','radio_color','notice_bg','notice_accent','access_color','access_exit','field_border_color','form_error_color','form_info_color','cart_group_title_color','cart_summary_color','cart_separator_color','checkout_summary_color','checkout_row_bg','checkout_row_separator','checkout_day_bg','shipping_notice_bg','shipping_notice_accent','shipping_notice_text'];
	}

	private static function numeric_rules() {
		return [
			'button_border_width'=>[0,10,1,'px'],'button_radius'=>[0,40,1,'px'],'button_height'=>[30,100,1,'px'],'button_font_size'=>[10,30,1,'px'],'button_weight'=>[100,900,100,''],
			'field_border_width'=>[0,10,1,'px'],'field_radius'=>[0,40,1,'px'],'field_padding'=>[0,40,1,'px'],
			'cart_group_title_size'=>[0.5,4,0.05,'em'],'cart_group_title_weight'=>[100,900,100,''],'cart_group_title_gap'=>[0,80,1,'px'],'cart_summary_size'=>[0.5,3,0.05,'em'],'cart_summary_gap'=>[0,80,1,'px'],'cart_group_padding_top'=>[0,100,1,'px'],'cart_group_padding_bottom'=>[0,100,1,'px'],
			'checkout_group_radius'=>[0,100,1,'px'],'checkout_group_padding_y'=>[0,100,1,'px'],'checkout_group_padding_x'=>[0,100,1,'px'],'checkout_group_title_size'=>[0.5,4,0.05,'em'],'checkout_group_title_gap'=>[0,80,1,'px'],'checkout_summary_size'=>[0.5,3,0.05,'em'],'checkout_row_padding_top'=>[0,100,1,'px'],'checkout_row_padding_x'=>[0,100,1,'px'],'checkout_row_padding_bottom'=>[0,100,1,'px'],'checkout_day_size'=>[0.5,3,0.01,'em'],'checkout_day_radius'=>[0,999,1,'px'],'checkout_day_padding_y'=>[0,50,1,'px'],'checkout_day_padding_x'=>[0,50,1,'px'],
			'shipping_notice_radius'=>[0,100,1,'px'],'shipping_notice_padding_y'=>[0,100,1,'px'],'shipping_notice_padding_x'=>[0,100,1,'px'],
		];
	}

	public static function get() { return wp_parse_args( (array) get_option( self::OPTION, [] ), self::defaults() ); }
	public static function admin_menu() { add_submenu_page( 'woocommerce', 'Estilos Panza Llena', 'Estilos Panza Llena', 'manage_options', 'pllc-styles', [ __CLASS__, 'page' ] ); }

	public static function admin_assets( $hook ) {
		if ( 'woocommerce_page_pllc-styles' !== $hook ) { return; }
		wp_enqueue_style( 'wp-color-picker' );
		wp_enqueue_style( 'pllc-admin-styles', PLLC_URL . 'assets/css/pllc-admin-styles.css', [ 'wp-color-picker' ], PLLC_VERSION );
		wp_enqueue_script( 'pllc-admin-styles', PLLC_URL . 'assets/js/pllc-admin-styles.js', [ 'jquery', 'wp-color-picker' ], PLLC_VERSION, true );
	}

	public static function elementor_colors() {
		$colors = [];
		if ( class_exists( '\\Elementor\\Plugin' ) && isset( \Elementor\Plugin::$instance->kits_manager ) ) {
			$kit = \Elementor\Plugin::$instance->kits_manager->get_active_kit();
			if ( $kit ) {
				foreach ( ['system_colors','custom_colors'] as $group ) {
					$items = $kit->get_settings( $group );
					if ( ! is_array( $items ) ) { continue; }
					foreach ( $items as $item ) {
						$id = isset($item['_id']) ? preg_replace('/[^a-zA-Z0-9_-]/','',(string)$item['_id']) : '';
						$color = isset($item['color']) ? sanitize_hex_color($item['color']) : '';
						if ( $id && $color ) { $colors[$id]=['id'=>$id,'label'=>sanitize_text_field($item['title']??$id),'color'=>strtolower($color),'global'=>true]; }
					}
				}
			}
		}
		if ( $colors ) { return $colors; }
		return [
			'primary'=>['id'=>'primary','label'=>'Rojo','color'=>'#f04a24','global'=>false],'secondary'=>['id'=>'secondary','label'=>'Verde','color'=>'#557b45','global'=>false],
			'text'=>['id'=>'text','label'=>'Marrón','color'=>'#8a5800','global'=>false],'accent'=>['id'=>'accent','label'=>'Beige','color'=>'#faf2e3','global'=>false],
			'white'=>['id'=>'white','label'=>'Blanco','color'=>'#ffffff','global'=>false],'black'=>['id'=>'black','label'=>'Negro','color'=>'#000000','global'=>false],
		];
	}

	public static function sanitize_color_value( $value, $fallback='', $allow_empty=false ) {
		$value = is_scalar($value) ? trim((string)$value) : '';
		if ( $allow_empty && ''===$value ) { return ''; }
		$hex=sanitize_hex_color($value); if($hex){return strtolower($hex);}
		if(preg_match('/^global:([a-zA-Z0-9_-]{1,80})$/D',$value,$m)){return 'global:'.$m[1];}
		return $allow_empty ? '' : strtolower((string)sanitize_hex_color($fallback));
	}

	public static function css_color( $value, $fallback='#000000' ) {
		$value=self::sanitize_color_value($value,$fallback,true);
		if(preg_match('/^global:([a-zA-Z0-9_-]{1,80})$/D',$value,$m)){
			$p=self::elementor_colors(); $resolved=$p[$m[1]]['color']??sanitize_hex_color($fallback);
			return 'var(--e-global-color-'.$m[1].', '.($resolved?:'#000000').')';
		}
		return $value ?: (sanitize_hex_color($fallback)?:'#000000');
	}

	public static function resolved_color_for_admin($value,$fallback=''){
		if(preg_match('/^global:([a-zA-Z0-9_-]{1,80})$/D',(string)$value,$m)){ $p=self::elementor_colors(); return $p[$m[1]]['color']??$fallback; }
		return sanitize_hex_color($value)?:$fallback;
	}

	public static function color_control($label,$name,$id,$value,$fallback='',$allow_inherit=false){
		$palette=self::elementor_colors(); $value=self::sanitize_color_value($value,$fallback,$allow_inherit); $selected='custom';
		if(''===$value&&$allow_inherit){$selected='inherit';} elseif(0===strpos($value,'global:')){$selected=$value;} else {
			foreach($palette as $c){if(strtolower($value)===strtolower($c['color'])){$selected=$c['global']?'global:'.$c['id']:$c['color'];break;}}
		}
		$resolved=self::resolved_color_for_admin($value,$fallback); $input=$resolved?:(sanitize_hex_color($fallback)?:'#ffffff');
		echo '<label for="'.esc_attr($id).'">'.esc_html($label).'</label><span class="pllc-color-control">';
		echo '<input class="pllc-color-value" type="hidden" name="'.esc_attr($name).'" value="'.esc_attr($value).'" data-key="'.esc_attr(preg_replace('/^.*\[([^\]]+)\]$/','$1',$name)).'" data-fallback="'.esc_attr($fallback).'" data-resolved="'.esc_attr($resolved).'">';
		echo '<select id="'.esc_attr($id).'" class="pllc-color-source">';
		if($allow_inherit){echo '<option value="inherit" data-color="'.esc_attr($fallback).'"'.selected($selected,'inherit',false).'>Heredar de Elementor o del tema</option>';}
		foreach($palette as $c){$v=$c['global']?'global:'.$c['id']:$c['color'];$prefix=$c['global']?'Elementor':'Paleta';echo '<option value="'.esc_attr($v).'" data-color="'.esc_attr($c['color']).'"'.selected($selected,$v,false).'>'.esc_html($prefix.' · '.$c['label'].' — '.strtoupper($c['color'])).'</option>';}
		echo '<option value="custom"'.selected($selected,'custom',false).'>Color personalizado…</option></select>';
		echo '<span class="pllc-color-status"><i class="pllc-color-swatch"></i><span class="pllc-color-status-text"></span></span><span class="pllc-color-custom-wrap"><input class="pllc-color" type="text" value="'.esc_attr($input).'" data-default-color="'.esc_attr($fallback?:$input).'"></span></span>';
	}

	private static function color_field($label,$key,$s){$d=self::defaults();self::color_control($label,'styles['.$key.']','pllc-'.$key,$s[$key],$d[$key]);}
	private static function number_field($label,$key,$s){$r=self::numeric_rules()[$key];printf('<label for="pllc-%1$s">%2$s</label><span><input id="pllc-%1$s" type="number" name="styles[%1$s]" value="%3$s" min="%4$s" max="%5$s" step="%6$s"> %7$s</span>',esc_attr($key),esc_html($label),esc_attr($s[$key]),esc_attr($r[0]),esc_attr($r[1]),esc_attr($r[2]),esc_html($r[3]));}
	private static function section_open($title,$description,$open=false){echo '<details class="pllc-style-section"'.($open?' open':'').'><summary><span><h2>'.esc_html($title).'</h2><p>'.esc_html($description).'</p></span></summary><div class="pllc-style-section-content">';}
	private static function section_close(){echo '</div></details>';}
	private static function group_open($title,$description=''){echo '<section class="pllc-style-group"><h3>'.esc_html($title).'</h3>';if($description){echo '<p>'.esc_html($description).'</p>';}echo '<div class="pllc-style-fields">';}
	private static function group_close(){echo '</div></section>';}
	private static function color_group($title,$description,$prefix,$s){self::group_open($title,$description);self::color_field('Fondo',$prefix.'_bg',$s);self::color_field('Texto',$prefix.'_text',$s);self::color_field('Borde',$prefix.'_border',$s);self::group_close();}

	public static function page(){
		if(!current_user_can('manage_options')){return;} $s=self::get(); ?>
		<div class="wrap"><h1>Estilos Panza Llena</h1><p class="pllc-style-intro">Estilos de los componentes generados o controlados por Panza Llena Core. Las estructuras funcionales permanecen protegidas. Los colores globales se leen del Kit activo de Elementor.</p>
		<?php if(isset($_GET['updated'])):?><div class="notice notice-success is-dismissible"><p>Los estilos se guardaron correctamente.</p></div><?php elseif(isset($_GET['reset'])):?><div class="notice notice-success is-dismissible"><p>Se restauraron los estilos originales.</p></div><?php endif;?>
		<form class="pllc-style-form" method="post" action="<?php echo esc_url(admin_url('admin-post.php'));?>"><input type="hidden" name="action" value="pllc_save_styles"><?php wp_nonce_field('pllc_save_styles');?><div class="pllc-style-sections">
		<?php self::section_open('Botones','Agregar, eliminar, actualizar y estados deshabilitados.',true);echo '<div class="pllc-style-grid">';self::color_group('Agregar y guardar','Estado normal de los botones principales.','primary',$s);self::color_group('Hover de Agregar','Al pasar sobre los botones principales.','primary_hover',$s);self::color_group('Eliminar y actualizar','Eliminar y cambios preparados.','action',$s);self::color_group('Hover de Eliminar','Al pasar sobre eliminar y actualizar.','action_hover',$s);self::color_group('Deshabilitados','Productos y acción principal bloqueados.','disabled',$s);self::group_open('Forma y tipografía');foreach(['button_border_width'=>'Grosor del borde','button_radius'=>'Radio','button_height'=>'Altura mínima','button_font_size'=>'Tamaño del texto','button_weight'=>'Peso tipográfico'] as $k=>$l){self::number_field($l,$k,$s);}self::group_close();echo '</div><div class="pllc-preview"><button type="button" class="pllc-preview-primary">Agregar</button><button type="button" class="pllc-preview-action">Eliminar / Actualizar</button><button type="button" class="pllc-preview-disabled" disabled>Deshabilitado</button></div>';self::section_close();

		self::section_open('Formularios','Campos, opciones y mensajes de los formularios propios.');echo '<div class="pllc-style-grid">';self::group_open('Campos','Inputs, selectores y observaciones.');self::color_field('Color del borde','field_border_color',$s);foreach(['field_border_width'=>'Grosor del borde','field_radius'=>'Radio','field_padding'=>'Espacio interior'] as $k=>$l){self::number_field($l,$k,$s);}self::group_close();self::group_open('Opciones y mensajes');self::color_field('Selección de opciones','radio_color',$s);self::color_field('Errores','form_error_color',$s);self::color_field('Información','form_info_color',$s);self::group_close();echo '</div>';self::section_close();

		self::section_open('Acceso por código','Colores de ingreso, salida y mensajes de error.');echo '<div class="pllc-style-grid">';self::group_open('Acciones');self::color_field('Botón de acceso','access_color',$s);self::color_field('Salir / error','access_exit',$s);self::group_close();echo '</div>';self::section_close();

		self::section_open('Carrito','Encabezados “Pedido…”, resúmenes y separadores.');echo '<div class="pllc-style-grid">';self::group_open('Título de cada pedido','Pedido ITEO, para cada alumno y para mí.');self::color_field('Color','cart_group_title_color',$s);foreach(['cart_group_title_size'=>'Tamaño','cart_group_title_weight'=>'Peso','cart_group_title_gap'=>'Separación inferior'] as $k=>$l){self::number_field($l,$k,$s);}self::group_close();self::group_open('Información del pedido');self::color_field('Color','cart_summary_color',$s);self::number_field('Tamaño','cart_summary_size',$s);self::number_field('Separación inferior','cart_summary_gap',$s);self::group_close();self::group_open('Separación entre pedidos');self::color_field('Color de línea','cart_separator_color',$s);self::number_field('Espacio superior','cart_group_padding_top',$s);self::number_field('Espacio inferior','cart_group_padding_bottom',$s);self::group_close();echo '</div>';self::section_close();

		self::section_open('Finalizar compra','Bloques agrupados, filas, días y avisos de envío.');echo '<div class="pllc-style-grid">';self::group_open('Bloques “Pedido…”');self::color_field('Fondo','notice_bg',$s);self::color_field('Acento lateral','notice_accent',$s);foreach(['checkout_group_radius'=>'Radio','checkout_group_padding_y'=>'Espacio vertical','checkout_group_padding_x'=>'Espacio horizontal'] as $k=>$l){self::number_field($l,$k,$s);}self::group_close();self::group_open('Título y resumen');self::number_field('Tamaño del título','checkout_group_title_size',$s);self::number_field('Separación del título','checkout_group_title_gap',$s);self::color_field('Color del resumen','checkout_summary_color',$s);self::number_field('Tamaño del resumen','checkout_summary_size',$s);self::group_close();self::group_open('Filas de productos');self::color_field('Fondo','checkout_row_bg',$s);self::color_field('Separador','checkout_row_separator',$s);foreach(['checkout_row_padding_top'=>'Espacio superior','checkout_row_padding_x'=>'Espacio lateral','checkout_row_padding_bottom'=>'Espacio inferior'] as $k=>$l){self::number_field($l,$k,$s);}self::group_close();self::group_open('Etiquetas de días');self::color_field('Fondo','checkout_day_bg',$s);foreach(['checkout_day_size'=>'Tamaño','checkout_day_radius'=>'Radio','checkout_day_padding_y'=>'Espacio vertical','checkout_day_padding_x'=>'Espacio horizontal'] as $k=>$l){self::number_field($l,$k,$s);}self::group_close();self::group_open('Aviso de envío');self::color_field('Fondo','shipping_notice_bg',$s);self::color_field('Acento lateral','shipping_notice_accent',$s);self::color_field('Texto','shipping_notice_text',$s);foreach(['shipping_notice_radius'=>'Radio','shipping_notice_padding_y'=>'Espacio vertical','shipping_notice_padding_x'=>'Espacio horizontal'] as $k=>$l){self::number_field($l,$k,$s);}self::group_close();echo '</div>';self::section_close();

		PLLC_Order_Received_Styles::render($s);
		self::section_open('Avanzado','CSS personalizado y herramientas de restauración.');?><p><label for="pllc-custom-css"><strong>CSS personalizado</strong></label><br>No incluyas la etiqueta <code>&lt;style&gt;</code>. Se carga después de los estilos configurables.</p><textarea id="pllc-custom-css" name="styles[custom_css]" class="large-text code" rows="9"><?php echo esc_textarea($s['custom_css']);?></textarea><?php self::section_close();?>
		</div><?php submit_button('Guardar estilos');?></form><form class="pllc-reset-form" method="post" action="<?php echo esc_url(admin_url('admin-post.php'));?>" onsubmit="return confirm('¿Restaurar todos los estilos originales del plugin?');"><input type="hidden" name="action" value="pllc_reset_styles"><?php wp_nonce_field('pllc_reset_styles');submit_button('Restablecer estilos originales','secondary','submit',false);?></form></div><?php
	}

	public static function save(){
		if(!current_user_can('manage_options')){wp_die('Sin permiso.');} check_admin_referer('pllc_save_styles');
		$p=isset($_POST['styles'])?(array)wp_unslash($_POST['styles']):[];$d=self::defaults();$c=[];
		foreach(self::color_keys() as $k){$c[$k]=self::sanitize_color_value($p[$k]??'',$d[$k]);}
		foreach(self::numeric_rules() as $k=>$r){$v=isset($p[$k])&&is_numeric($p[$k])?(float)$p[$k]:(float)$d[$k];$v=min($r[1],max($r[0],$v));$c[$k]=$r[2]>=1?(int)(round($v/$r[2])*$r[2]):round($v,2);}
		$c['custom_css']=isset($p['custom_css'])?wp_strip_all_tags($p['custom_css']):'';$c['order_received']=PLLC_Order_Received_Styles::sanitize($p['order_received']??[]);
		update_option(self::OPTION,$c);wp_safe_redirect(add_query_arg(['page'=>'pllc-styles','updated'=>1],admin_url('admin.php')));exit;
	}

	public static function reset(){if(!current_user_can('manage_options')){wp_die('Sin permiso.');}check_admin_referer('pllc_reset_styles');delete_option(self::OPTION);wp_safe_redirect(add_query_arg(['page'=>'pllc-styles','reset'=>1],admin_url('admin.php')));exit;}

	public static function frontend_css(){
		if(!wp_style_is('pllc-frontend','enqueued')){return;}$s=self::get();$d=self::defaults();$css=':root{';
		foreach(self::color_keys() as $k){$css.='--pllc-'.str_replace('_','-',$k).':'.self::css_color($s[$k],$d[$k]).';';}
		foreach(self::numeric_rules() as $k=>$r){$css.='--pllc-'.str_replace('_','-',$k).':'.$s[$k].$r[3].';';}
		$css.='--pllc-radio:'.self::css_color($s['radio_color'],$d['radio_color']).';--pllc-access:'.self::css_color($s['access_color'],$d['access_color']).';}';
		$css.='.pllc-access-box button{background:var(--pllc-access-color)!important;color:var(--pllc-primary-text)!important}.pllc-access-box .pllc-access-leave{background:var(--pllc-access-exit)!important}.pllc-access-error{color:var(--pllc-access-exit)!important}';
		if(''!==trim($s['custom_css'])){$css.="\n".$s['custom_css'];}wp_add_inline_style('pllc-frontend',$css);
	}
}
