/**
 * Sur la page panier ou la page de validation de la commande (blocs WooCommerce
 * Cart/Checkout), quand le client vide son panier (retrait du dernier article),
 * on le redirige vers le catalogue plutôt que de lui montrer l'écran « panier
 * vide » ou un tunnel de commande sans article.
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

	function redirectToCatalogue() {
		// Posée de façon synchrone (avant le repaint du bloc) : masque
		// l'écran « panier vide » par défaut le temps de la navigation.
		document.documentElement.classList.add( 'pf-cart-redirecting' );
		window.location.href = target;
	}

	// Sur la page de validation de la commande, une commande qui vient d'être
	// validée vide aussi le panier : WooCommerce redirige alors lui-même vers
	// sa page de remerciement. Le store `wc/store/checkout` n'existe que là
	// (jamais sur la page panier) — s'il est présent, on laisse un court délai
	// à cette redirection légitime pour s'engager avant la nôtre, pour ne pas
	// lui couper l'herbe sous le pied et renvoyer le client au catalogue au
	// lieu de sa confirmation de commande.
	function isCheckoutRedirectPending( checkoutStore ) {
		if ( typeof checkoutStore.getRedirectUrl === 'function' && checkoutStore.getRedirectUrl() ) {
			return true;
		}
		if ( typeof checkoutStore.isComplete === 'function' && checkoutStore.isComplete() ) {
			return true;
		}
		return false;
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

			var checkoutStore = wp.data.select( 'wc/store/checkout' );
			if ( checkoutStore ) {
				setTimeout( function () {
					if ( isCheckoutRedirectPending( checkoutStore ) ) {
						return;
					}
					redirectToCatalogue();
				}, 400 );
				return;
			}

			redirectToCatalogue();
			return;
		}

		last = count;
	} );
} )( window.wp );
