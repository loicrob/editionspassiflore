<?php
/**
 * Tunnel de commande (Cart/Checkout par blocs, Store API).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Les vignettes du recap (cart/checkout) sont fournies en woocommerce_thumbnail
 * (recadre carre 300x300), affichees ensuite dans une boite 48x48 en CSS
 * (checkout.css) : le recadrage a deja eu lieu cote fichier, aucun CSS ne peut
 * ensuite reafficher la couverture entiere. On substitue une taille non
 * recadree deja generee pour toute image (medium, coeur WordPress) afin que la
 * couverture (generalement portrait) reste intacte ; c'est le CSS qui la fait
 * tenir dans la boite 48x48 (object-fit: contain).
 */
add_filter( 'woocommerce_store_api_cart_item_images', function ( $images ) {
	foreach ( $images as $image ) {
		if ( empty( $image->id ) ) {
			continue;
		}
		$medium = wp_get_attachment_image_src( $image->id, 'medium' );
		if ( $medium ) {
			$image->thumbnail = current( $medium );
		}
	}
	return $images;
} );

/**
 * Panier et Commander sont des pages bloc pures (contenu = bloc Cart/Checkout
 * uniquement) : pas de contenu éditorial où poser un <h1> à la main, et la
 * barre de titre Kadence est désactivée sur les Pages. On le restitue en
 * code pour le SEO et la navigation lecteur d'écran.
 */
add_filter( 'the_content', function ( $content ) {
	if ( ! in_the_loop() || ! is_main_query() ) {
		return $content;
	}
	if ( is_cart() ) {
		return '<h1>Panier</h1>' . $content;
	}
	if ( is_checkout() ) {
		if ( is_wc_endpoint_url( 'order-received' ) ) {
			return '<h1>Commande confirmée</h1>' . $content;
		}
		if ( is_wc_endpoint_url( 'order-pay' ) ) {
			return '<h1>Payer la commande</h1>' . $content;
		}
		return '<h1>Commander</h1>' . $content;
	}
	return $content;
} );

/**
 * Texte sous le h1 de remerciement (page order-received).
 */
add_filter( 'woocommerce_thankyou_order_received_text', function ( $text, $order ) {
	return 'Nous vous remercions pour votre commande, elle sera traitée dans les plus brefs délais.';
}, 10, 2 );
