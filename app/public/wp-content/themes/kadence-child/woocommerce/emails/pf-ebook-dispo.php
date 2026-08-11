<?php
/**
 * « Votre livre numérique est disponible » — HTML. Ne rend PAS le tableau de
 * commande standard (`woocommerce_email_order_details`) : ce n'est pas une
 * confirmation de commande mais une notification ciblée sur les fichiers
 * nouvellement disponibles ($downloads, sous-ensemble filtré par la classe).
 *
 * Chaque entrée de $downloads vient de WC_Order::get_downloadable_items() —
 * le lien ('download_url') fonctionne pour un·e client·e sans compte
 * (cf. docblock de la classe).
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
<p><?php echo esc_html( $intro ); ?></p>

<ul>
<?php foreach ( $downloads as $download ) : ?>
	<li>
		<a href="<?php echo esc_url( $download['download_url'] ); ?>">
			<?php echo esc_html( $download['product_name'] ?? $download['download_name'] ); ?>
		</a>
	</li>
<?php endforeach; ?>
</ul>

<p>
<?php
if ( function_exists( 'pf_auth_url' ) ) {
	printf(
		wp_kses_post( __( 'Vous pouvez aussi <a href="%s">créer un compte</a> pour le lire directement dans votre navigateur et retrouver votre bibliothèque numérique.', 'kadence-child' ) ),
		esc_url( pf_auth_url( 'register' ) )
	);
} else {
	esc_html_e( 'Créez un compte sur notre site pour le lire directement dans votre navigateur et retrouver votre bibliothèque numérique.', 'kadence-child' );
}
?>
</p>

<?php
if ( $additional_content ) {
	echo '<table border="0" cellpadding="0" cellspacing="0" width="100%" role="presentation"><tr><td class="email-additional-content">';
	echo wp_kses_post( wpautop( wptexturize( $additional_content ) ) );
	echo '</td></tr></table>';
}

do_action( 'woocommerce_email_footer', $email );
