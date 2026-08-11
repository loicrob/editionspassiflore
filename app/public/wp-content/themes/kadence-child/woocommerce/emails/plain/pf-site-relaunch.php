<?php
/**
 * Gabarit texte brut — mêmes paramètres que la variante HTML
 * (pf-site-relaunch.php).
 */

if ( ! defined( 'ABSPATH' ) ) exit;

echo "=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=\n";
echo esc_html( wp_strip_all_tags( $email_heading ) );
echo "\n=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=\n\n";

if ( ! empty( $user->first_name ) ) {
	/* translators: %s: prénom du client */
	echo sprintf( esc_html__( 'Bonjour %s,', 'kadence-child' ), esc_html( $user->first_name ) ) . "\n\n";
} else {
	echo esc_html__( 'Bonjour,', 'kadence-child' ) . "\n\n";
}

echo esc_html__( "Le site des Éditions Passiflore fait peau neuve ! Votre compte vous attend sur cette nouvelle version, avec vos informations et l'historique de vos commandes conservés.", 'kadence-child' ) . "\n\n";

echo esc_html__( "Comme c'est un nouveau site, vous devrez définir un nouveau mot de passe avant de vous connecter :", 'kadence-child' ) . "\n";
echo esc_html( $lost_password_url ) . "\n\n";

echo esc_html__( 'Quelques nouveautés à découvrir :', 'kadence-child' ) . "\n";
echo '- ' . esc_html__( "Une nouvelle interface, plus proche de l'esprit de la maison", 'kadence-child' ) . "\n";
echo '- ' . esc_html__( 'Une recherche unique pour retrouver un livre, un auteur ou un événement', 'kadence-child' ) . "\n";
echo '- ' . esc_html__( 'Les versions numériques, à acheter et lire directement en ligne depuis votre compte', 'kadence-child' ) . "\n";
echo '- ' . esc_html__( "L'agenda des événements et rencontres, avec une carte pour trouver les plus proches de chez vous", 'kadence-child' ) . "\n\n";

echo esc_html__( 'Le site continue d’être peaufiné ces prochains temps — si vous tombez sur un bug ou avez un retour à nous faire, on est preneurs !', 'kadence-child' ) . "\n\n";

echo "\n----------------------------------------\n\n";

if ( $additional_content ) {
	echo esc_html( wp_strip_all_tags( wptexturize( $additional_content ) ) );
	echo "\n\n----------------------------------------\n\n";
}

echo esc_html__( 'PS : Site conçu par [Prénom Nom] — disponible pour d’autres projets.', 'kadence-child' ) . "\n\n";

echo wp_kses_post( apply_filters( 'woocommerce_email_footer_text', get_option( 'woocommerce_email_footer_text' ) ) );
