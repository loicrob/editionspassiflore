<?php
/** « Votre livre numérique est disponible » — texte brut. Cf. variante HTML pour le détail des variables. */

defined( 'ABSPATH' ) || exit;

echo "=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=\n";
echo esc_html( wp_strip_all_tags( $email_heading ) );
echo "\n=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=\n\n";

if ( ! empty( $order->get_billing_first_name() ) ) {
	/* translators: %s: prénom du client */
	echo sprintf( esc_html__( 'Bonjour %s,', 'kadence-child' ), esc_html( $order->get_billing_first_name() ) ) . "\n\n";
} else {
	echo esc_html__( 'Bonjour,', 'kadence-child' ) . "\n\n";
}

echo esc_html( $intro ) . "\n\n";

foreach ( $downloads as $download ) {
	$name = $download['product_name'] ?? $download['download_name'];
	echo '- ' . esc_html( $name ) . ' : ' . esc_url( $download['download_url'] ) . "\n";
}

echo "\n";

if ( function_exists( 'pf_auth_url' ) ) {
	echo esc_html__( 'Vous pouvez aussi créer un compte pour le lire directement dans votre navigateur et retrouver votre bibliothèque numérique :', 'kadence-child' ) . "\n";
	echo esc_url( pf_auth_url( 'register' ) ) . "\n\n";
} else {
	echo esc_html__( 'Créez un compte sur notre site pour le lire directement dans votre navigateur et retrouver votre bibliothèque numérique.', 'kadence-child' ) . "\n\n";
}

if ( $additional_content ) {
	echo esc_html( wp_strip_all_tags( wptexturize( $additional_content ) ) );
	echo "\n\n----------------------------------------\n\n";
}

echo wp_kses_post( apply_filters( 'woocommerce_email_footer_text', get_option( 'woocommerce_email_footer_text' ) ) );
