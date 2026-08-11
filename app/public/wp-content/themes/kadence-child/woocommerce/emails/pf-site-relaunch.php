<?php
/**
 * Gabarit HTML de l'email d'annonce de la nouvelle version du site — envoyé
 * une fois aux comptes migrés depuis PrestaShop
 * (inc/site-relaunch-email.php).
 *
 * Variables : $user, $lost_password_url, $email_heading, $additional_content, $email
 */

if ( ! defined( 'ABSPATH' ) ) exit;

do_action( 'woocommerce_email_header', $email_heading, $email );
?>

<p>
<?php
if ( ! empty( $user->first_name ) ) {
	/* translators: %s: prénom du client */
	printf( esc_html__( 'Bonjour %s,', 'kadence-child' ), esc_html( $user->first_name ) );
} else {
	esc_html_e( 'Bonjour,', 'kadence-child' );
}
?>
</p>

<p><?php esc_html_e( "Le site des Éditions Passiflore fait peau neuve ! Votre compte vous attend sur cette nouvelle version, avec vos informations et l'historique de vos commandes conservés.", 'kadence-child' ); ?></p>

<p>
<?php esc_html_e( "Comme c'est un nouveau site, vous devrez définir un nouveau mot de passe avant de vous connecter :", 'kadence-child' ); ?>
<br />
<a href="<?php echo esc_url( $lost_password_url ); ?>"><?php esc_html_e( 'Cliquez ici pour définir votre mot de passe', 'kadence-child' ); ?></a>
</p>

<p><?php esc_html_e( 'Quelques nouveautés à découvrir :', 'kadence-child' ); ?></p>

<ul>
	<li><?php esc_html_e( "Une nouvelle interface, plus proche de l'esprit de la maison", 'kadence-child' ); ?></li>
	<li><?php esc_html_e( 'Une recherche unique pour retrouver un livre, un auteur ou un événement', 'kadence-child' ); ?></li>
	<li><?php esc_html_e( 'Les versions numériques, à acheter et lire directement en ligne depuis votre compte', 'kadence-child' ); ?></li>
	<li><?php esc_html_e( "L'agenda des événements et rencontres, avec une carte pour trouver les plus proches de chez vous", 'kadence-child' ); ?></li>
</ul>

<p><?php esc_html_e( 'Le site continue d’être peaufiné ces prochains temps — si vous tombez sur un bug ou avez un retour à nous faire, on est preneurs !', 'kadence-child' ); ?></p>

<?php
if ( $additional_content ) {
	echo '<table border="0" cellpadding="0" cellspacing="0" width="100%" role="presentation"><tr><td class="email-additional-content">';
	echo wp_kses_post( wpautop( wptexturize( $additional_content ) ) );
	echo '</td></tr></table>';
}
?>

<p style="font-size: smaller; color: #999;"><?php esc_html_e( 'PS : Site conçu par [Prénom Nom] — disponible pour d’autres projets.', 'kadence-child' ); ?></p>

<?php
do_action( 'woocommerce_email_footer', $email );
