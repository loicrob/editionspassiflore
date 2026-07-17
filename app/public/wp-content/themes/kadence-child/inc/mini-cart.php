<?php
/**
 * Mini-panier (dropdown header, template classique) — voir aussi
 * woocommerce/cart/mini-cart.php (override de template) et
 * inc/numerique-offer.php (prix barre sur cette meme liste).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Meme correctif que inc/checkout.php cote Store API : la vignette par
 * defaut ($_product->get_image(), taille woocommerce_thumbnail) est deja
 * recadree carre 300x300 cote fichier, avant meme le CSS. On la remplace par
 * la taille medium (non recadree, coeur WordPress, deja generee pour tout
 * media existant) pour que la couverture (generalement portrait) reste
 * entiere ; le CSS existant du mini-panier (Kadence, width:50px; height:auto)
 * l'affiche deja correctement une fois la bonne source fournie.
 */
add_filter( 'woocommerce_cart_item_thumbnail', function ( $image, $cart_item ) {
	$product = $cart_item['data'] ?? null;
	if ( $product instanceof WC_Product ) {
		return $product->get_image( 'medium' );
	}
	return $image;
}, 10, 2 );
