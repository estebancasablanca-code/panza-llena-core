<?php
/** @var array $group Un pedido/persona, con datos y productos consecutivos. */
defined( 'ABSPATH' ) || exit;
?>
<table class="pllc-email-group" role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="width:100%;border-collapse:collapse;margin:0 0 26px;">
	<tr><td bgcolor="#faf2e3" style="background-color:#faf2e3;border-left:3px solid #8a5800;padding:14px 16px;font-family:Arial,Helvetica,sans-serif;font-size:14px;line-height:21px;mso-line-height-rule:at-least;">
		<h3 style="margin:0 0 8px;color:#8a5800;font-family:Arial,Helvetica,sans-serif;font-size:19px;line-height:25px;font-weight:bold;"><?php echo esc_html( $group['heading'] ); ?></h3>
		<?php foreach ( $group['fields'] as $field ) : ?>
		<p style="margin:0 0 2px;font-size:14px;line-height:21px;"><?php echo esc_html( $field ); ?></p>
		<?php endforeach; ?>
		<?php if ( $group['observations'] ) : ?>
		<p style="margin:6px 0 0;font-size:14px;line-height:21px;"><strong><?php esc_html_e( 'Observaciones para la cocina', 'panza-llena-core' ); ?>:</strong> <?php echo nl2br( esc_html( $group['observations'] ) ); ?></p>
		<?php endif; ?>
		<?php if ( $group['context'] ) : ?>
		<p style="margin:6px 0 0;color:#6b625a;font-size:12px;line-height:18px;"><?php echo esc_html( $group['context'] ); ?></p>
		<?php endif; ?>
	</td></tr>
	<tr><td style="padding:8px 0 0;">
		<table class="pllc-email-products" width="100%" cellspacing="0" cellpadding="0" border="0" style="width:100%;border-collapse:collapse;table-layout:fixed;">
			<thead class="pllc-email-columns"><tr>
				<th scope="col" width="<?php echo $group['show_prices'] ? '62%' : '82%'; ?>" align="left" style="padding:8px 8px 6px 0;color:#615c56;font-size:12px;line-height:18px;"><?php esc_html_e( 'Producto', 'panza-llena-core' ); ?></th>
				<th scope="col" width="14%" align="center" style="padding:8px 0 6px;color:#615c56;font-size:12px;line-height:18px;"><?php esc_html_e( 'Cantidad', 'panza-llena-core' ); ?></th>
				<?php if ( $group['show_prices'] ) : ?><th scope="col" width="24%" align="right" style="padding:8px 0 6px;color:#615c56;font-size:12px;line-height:18px;"><?php esc_html_e( 'Importe', 'panza-llena-core' ); ?></th><?php endif; ?>
			</tr></thead>
			<tbody>
			<?php foreach ( $group['items'] as $row ) : ?>
				<?php PLLC_Emails::template( 'order-item.php', [ 'row' => $row, 'show_prices' => $group['show_prices'] ] ); ?>
			<?php endforeach; ?>
			</tbody>
		</table>
	</td></tr>
</table>
