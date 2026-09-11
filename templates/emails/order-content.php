<?php
/** @var array $view Datos preparados por PLLC_Emails. */
defined( 'ABSPATH' ) || exit;
?>
<div class="pllc-email" style="font-family:Arial,Helvetica,sans-serif;color:#302a26;font-size:14px;line-height:21px;">
	<h2 style="margin:0 0 8px;color:#302a26;font-family:Arial,Helvetica,sans-serif;font-size:24px;line-height:30px;font-weight:bold;"><?php echo wp_kses_post( $view['heading'] ); ?></h2>
	<?php if ( '' !== $view['number'] ) : ?>
	<p style="margin:0 0 22px;color:#737373;font-size:13px;line-height:19px;">
		<?php if ( $view['admin_url'] ) : ?><a href="<?php echo esc_url( $view['admin_url'] ); ?>" style="color:#737373;text-decoration:none;"><?php endif; ?>
		<?php echo esc_html( sprintf( __( 'Pedido #%s', 'panza-llena-core' ), $view['number'] ) ); ?>
		<?php if ( $view['admin_url'] ) : ?></a><?php endif; ?>
		<?php if ( $view['date'] ) : ?> · <?php echo esc_html( $view['date'] ); ?><?php endif; ?>
	</p>
	<?php endif; ?>
	<?php if ( $view['mixed_title'] ) : ?>
	<table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="width:100%;border-collapse:collapse;margin:0 0 20px;">
		<tr><td bgcolor="#eef7f1" style="background-color:#eef7f1;border-left:3px solid #557b45;padding:14px 16px;color:#243d30;font-family:Arial,Helvetica,sans-serif;font-size:14px;line-height:21px;mso-line-height-rule:at-least;">
			<p style="margin:0 0 5px;font-size:16px;line-height:22px;font-weight:bold;"><?php echo esc_html( $view['mixed_title'] ); ?></p>
			<p style="margin:0;font-size:14px;line-height:21px;"><?php echo esc_html( $view['mixed_explanation'] ); ?></p>
		</td></tr>
	</table>
	<?php endif; ?>
	<?php foreach ( $view['groups'] as $group ) : ?>
		<?php PLLC_Emails::template( 'order-group.php', [ 'group' => $group ] ); ?>
	<?php endforeach; ?>
	<?php if ( $view['totals'] ) : ?>
	<table class="pllc-email-totals" width="100%" cellspacing="0" cellpadding="0" border="0" style="width:100%;border-collapse:collapse;border-top:1px solid #ded8d0;margin:0 0 20px;">
		<?php foreach ( $view['totals'] as $total ) : ?>
		<tr>
			<th scope="row" align="left" style="padding:9px 8px 9px 0;font-size:14px;line-height:21px;font-weight:normal;"><?php echo wp_kses_post( $total['label'] ); ?> <?php echo isset( $total['meta'] ) ? wp_kses_post( $total['meta'] ) : ''; ?></th>
			<td align="right" style="padding:9px 0;font-size:14px;line-height:21px;"><?php echo wp_kses_post( $total['value'] ); ?></td>
		</tr>
		<?php endforeach; ?>
	</table>
	<?php endif; ?>
	<?php if ( $view['note'] ) : ?>
	<p class="pllc-email-customer-note" style="margin:0 0 20px;padding-top:12px;border-top:1px solid #ded8d0;font-size:14px;line-height:21px;"><strong><?php esc_html_e( 'Notas del pedido', 'panza-llena-core' ); ?>:</strong><br><?php echo nl2br( esc_html( PLLC_Emails::plain( $view['note'] ) ) ); ?></p>
	<?php endif; ?>
</div>
