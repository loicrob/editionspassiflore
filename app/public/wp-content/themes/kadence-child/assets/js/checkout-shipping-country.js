/**
 * Checkout (blocs) : force le recalcul de la livraison au changement de PAYS.
 *
 * Bug WooCommerce Blocks (constaté en 10.6) : changer le <select> « Pays/Région »
 * de l'adresse met bien à jour l'état interne (store `wc/store/cart`) mais ne
 * déclenche PAS le push vers la Store API qui recalcule les tarifs d'expédition —
 * contrairement aux champs texte (code postal, ville…). Résultat : le tarif reste
 * celui du pays précédent tant qu'aucun champ texte n'est ré-édité.
 *
 * Correctif minimal : à chaque changement d'un <select …-country>, on renvoie
 * l'adresse complète — déjà mise à jour avec le nouveau pays par le bloc lui-même —
 * via updateCustomerData(), ce qui provoque le push serveur et rafraîchit la
 * section « Options de livraison » et le total. Idempotent et sans effet de bord :
 * si le bloc finit par pousser tout seul (autre champ édité), c'est un recalcul de
 * plus, inoffensif.
 */
( function () {
	'use strict';

	var STORE = 'wc/store/cart';
	var timer = null;

	function cartStore() {
		return ( window.wp && wp.data && typeof wp.data.select === 'function' )
			? wp.data.select( STORE )
			: null;
	}

	function pushCustomerData() {
		var store = cartStore();
		if ( ! store || typeof store.getCustomerData !== 'function' ) {
			return;
		}
		try {
			var cd = store.getCustomerData();
			wp.data.dispatch( STORE ).updateCustomerData( {
				billing_address: cd.billingAddress,
				shipping_address: cd.shippingAddress,
			} );
		} catch ( e ) {
			// Ne jamais casser le tunnel de commande sur une erreur de sync.
		}
	}

	document.addEventListener( 'change', function ( e ) {
		var el = e.target;
		if ( ! el || 'SELECT' !== el.tagName || ! /(^|-)country$/.test( el.id || '' ) ) {
			return;
		}
		// Laisse le bloc traiter son propre onChange (mise à jour du store) avant de pousser.
		window.clearTimeout( timer );
		timer = window.setTimeout( pushCustomerData, 300 );
	} );
}() );
