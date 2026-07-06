/**
 * Synchronise le compteur du panier dans le header avec les blocs WooCommerce
 * Cart / Checkout.
 *
 * Les blocs Panier/Commander (Store API, React) modifient le panier sans
 * déclencher le mécanisme legacy `woocommerce_add_to_cart_fragments`. Or le
 * compteur du header de Kadence (`span.header-cart-total` /
 * `span.header-cart-empty-check`) repose entièrement sur ce mécanisme → il
 * reste figé tant qu'on ne recharge pas la page après avoir changé une quantité.
 *
 * On s'abonne au store `wc/store/cart` ; à chaque variation du nombre d'articles
 * on déclenche `wc_fragment_refresh`, ce qui force `wc-cart-fragments` à
 * refetcher les fragments → Kadence réécrit le compteur (et la règle CSS
 * `:has(.header-cart-is-empty-true)` masque/affiche l'icône en conséquence).
 */
( function ( wp, $ ) {
	if ( ! wp || ! wp.data || ! $ ) {
		return;
	}

	var last = null;

	wp.data.subscribe( function () {
		var store = wp.data.select( 'wc/store/cart' );
		if ( ! store ) {
			// Store pas encore enregistré : la souscription refire à chaque MAJ.
			return;
		}

		var data = store.getCartData();
		if ( ! data || typeof data.itemsCount !== 'number' ) {
			return;
		}

		var count = data.itemsCount;

		// Première valeur observée : on cale la référence (le header est déjà
		// juste via le rendu serveur) sans déclencher de rafraîchissement.
		if ( last === null ) {
			last = count;
			return;
		}

		if ( count === last ) {
			return;
		}

		last = count;
		$( document.body ).trigger( 'wc_fragment_refresh' );
	} );
} )( window.wp, window.jQuery );
