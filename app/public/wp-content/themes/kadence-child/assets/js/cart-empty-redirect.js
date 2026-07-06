/**
 * Sur la page panier (bloc WooCommerce Cart), quand le client vide son panier
 * (retrait du dernier article), on le redirige vers le catalogue plutôt que de
 * lui montrer l'écran « panier vide ».
 *
 * On s'abonne au store `wc/store/cart` et on détecte la transition d'un nombre
 * d'articles strictement positif vers 0. L'URL du catalogue est fournie par PHP
 * (window.pfCartEmptyRedirect.catalogueUrl).
 */
( function ( wp ) {
	if ( ! wp || ! wp.data ) {
		return;
	}

	var target = ( window.pfCartEmptyRedirect || {} ).catalogueUrl;
	if ( ! target ) {
		return;
	}

	var last = null;
	var done = false;

	var unsubscribe = wp.data.subscribe( function () {
		if ( done ) {
			return;
		}

		var store = wp.data.select( 'wc/store/cart' );
		if ( ! store ) {
			return;
		}

		var cart = store.getCartData();
		if ( ! cart || typeof cart.itemsCount !== 'number' ) {
			return;
		}

		var count = cart.itemsCount;

		// Première valeur observée : sert de référence, pas d'action.
		if ( last === null ) {
			last = count;
			return;
		}

		if ( count === last ) {
			return;
		}

		// Transition vers un panier vide alors qu'il contenait des articles.
		if ( count === 0 && last > 0 ) {
			done = true;
			if ( typeof unsubscribe === 'function' ) {
				unsubscribe();
			}
			// Posée de façon synchrone (avant le repaint du bloc) : masque
			// l'écran « panier vide » par défaut le temps de la navigation.
			document.documentElement.classList.add( 'pf-cart-redirecting' );
			window.location.href = target;
			return;
		}

		last = count;
	} );
} )( window.wp );
