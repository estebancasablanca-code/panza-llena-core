<?php
/** @var array $row Producto preparado; no se consulta ni modifica el pedido aquí. */
defined( 'ABSPATH' ) || exit;
?>
<tr class="pllc-email-item<?php echo $show_prices ? '' : ' pllc-email-item-unpriced'; ?>" style="border-bottom:1px solid #e9e1d5;">
	<td class="pllc-email-product" valign="top" style="padding:14px 8px 14px 0;word-wrap:break-word;">
		<table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="width:100%;border-collapse:collapse;table-layout:fixed;">
			<tr>
				<td width="60" valign="top" style="width:60px;padding:0 12px 0 0;box-sizing:border-box;"><?php echo wp_kses_post( $row['image'] ); ?></td>
				<td valign="top" style="padding:0;font-family:Arial,Helvetica,sans-serif;word-wrap:break-word;">
					<?php if ( $row['day'] ) : ?><p class="pllc-email-day" style="margin:0 0 4px;color:#737373;font-size:12px;line-height:17px;"><?php echo esc_html( $row['day'] ); ?></p><?php endif; ?>
					<p class="pllc-email-product-name" style="margin:0;color:#302a26;font-family:Arial,Helvetica,sans-serif;font-size:16px;font-weight:bold;line-height:21px;mso-line-height-rule:at-least;"><?php echo wp_kses_post( $row['name'] ); ?></p>
					<?php if ( $row['meal'] ) : ?><p style="margin:4px 0 0;font-size:13px;line-height:18px;"><?php echo esc_html( sprintf( __( 'Comida: %s', 'panza-llena-core' ), $row['meal'] ) ); ?></p><?php endif; ?>
					<?php if ( $row['meta'] ) : ?><div style="margin-top:4px;color:#6b625a;font-size:12px;line-height:18px;"><?php echo wp_kses_post( $row['meta'] ); ?></div><?php endif; ?>
					<?php if ( $row['purchase_note'] ) : ?><div style="margin-top:6px;font-size:13px;line-height:19px;"><?php echo wp_kses_post( $row['purchase_note'] ); ?></div><?php endif; ?>
				</td>
			</tr>
		</table>
	</td>
	<td class="pllc-email-quantity" align="center" valign="middle" style="padding:14px 0;font-size:14px;line-height:21px;">
		<span class="pllc-email-quantity-label" style="display:none;mso-hide:all;font-size:0;line-height:0;"><?php esc_html_e( 'Cantidad', 'panza-llena-core' ); ?>: </span><?php echo '' !== $row['quantity'] ? '&times;' . wp_kses_post( $row['quantity'] ) : ''; ?>
	</td>
	<?php if ( $show_prices ) : ?>
	<td class="pllc-email-amount" align="right" valign="middle" style="padding:14px 0;font-size:14px;line-height:21px;white-space:nowrap;"><?php echo wp_kses_post( $row['amount'] ); ?></td>
	<?php endif; ?>
</tr>
