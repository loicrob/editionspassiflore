<?php
/**
 * Gabarit HTML partagé par les 5 emails de statut sur mesure (precommande,
 * retrait-att, expediee, livree, retiree) — cf.
 * inc/emails/class-email-order-status-base.php pour le pourquoi du partage.
 *
 * Variables : $order, $email_heading, $additional_content, $email, $intro
 * (paragraphe HTML propre à chaque statut, fourni par la sous-classe).
 */

if ( ! defined( 'ABSPATH' ) ) exit;

do_action( 'woocommerce_email_header', $email_heading, $email );
?>

<p>
<?php
if ( ! empty( $order->get_billing_first_name() ) ) {
	/* translators: %s: prénom du client */
	printf( esc_html__( 'Bonjour %s,', 'kadence-child' ), esc_html( $order->get_billing_first_name() ) );
} else {
	esc_html_e( 'Bonjour,', 'kadence-child' );
}
?>
</p>
<?php echo wp_kses_post( $intro ); /* déjà passé par wpautop() côté classe — pas de <p> englobant ici */ ?>

<?php
do_action( 'woocommerce_email_order_details', $order, $sent_to_admin, $plain_text, $email );
do_action( 'woocommerce_email_order_meta', $order, $sent_to_admin, $plain_text, $email );
do_action( 'woocommerce_email_customer_details', $order, $sent_to_admin, $plain_text, $email );

if ( $additional_content ) {
	echo '<table border="0" cellpadding="0" cellspacing="0" width="100%" role="presentation"><tr><td class="email-additional-content">';
	echo wp_kses_post( wpautop( wptexturize( $additional_content ) ) );
	echo '</td></tr></table>';
}

do_action( 'woocommerce_email_footer', $email );
