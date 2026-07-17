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
