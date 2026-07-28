/**
 * Fiche livre — offre « version numérique en promo ».
 *
 * La case à cocher pilote un attribut data-* sur le bouton d'ajout au panier.
 * Le JS cœur de WooCommerce (add-to-cart.js) recopie tous les data-* du bouton
 * dans la requête AJAX d'ajout → le serveur (inc/numerique-offer.php) y lit
 * `pf_add_numerique` et ajoute le numérique compagnon au tarif configuré.
 *
 * Amélioration progressive : sans JS, le bouton ajoute normalement le livre
 * papier, l'offre n'est simplement pas appliquée.
 */
( function () {
	'use strict';

	var check = document.querySelector( '.pf-numerique-offer__check' );
	var button = document.querySelector( '.bs-hero__cart' );

	if ( check && button ) {
		var sync = function () {
			if ( check.checked ) {
				button.setAttribute( 'data-pf_add_numerique', '1' );
			} else {
				button.removeAttribute( 'data-pf_add_numerique' );
			}
		};
		check.addEventListener( 'change', sync );
		sync();
	}

	/* ── L'offre disparaît quand le numérique est déjà au panier ───────────
	   Il y est vendu à l'unité (`sold_individually`) : proposer de l'ajouter
	   une seconde fois n'a pas d'objet. Le serveur le sait déjà et s'abstient
	   (`pf_numerique_maybe_add_companion`) — ce masquage est donc purement une
	   affaire d'affichage, ce qui autorise à le faire ici plutôt qu'au rendu.

	   ⚠️ ET IL DOIT SE FAIRE ICI. La fiche livre est une page cacheable ; un
	   rendu conditionné au panier ferait servir l'état d'un visiteur à tous les
	   autres — le premier à arriver avec le numérique au panier ferait
	   disparaître l'offre pour tout le monde. Le HTML reste donc identique pour
	   tous, seule sa présentation varie côté client.

	   Source de vérité : le mini-panier du header. C'est un *fragment*
	   WooCommerce — restauré depuis sessionStorage puis rafraîchi en AJAX même
	   sur une page servie par un cache —, donc il reflète toujours le panier
	   réel, contrairement au reste de la page. Chaque article y porte un lien de
	   suppression avec son `data-product_id`.

	   Repli volontairement OUVERT : sans mini-panier (autre style de panier
	   configuré dans Kadence), on laisse l'offre visible. La montrer à tort est
	   sans conséquence (le serveur ignore le doublon) ; la cacher à tort ferait
	   perdre une vente. */
	var offer = document.querySelector( '.pf-numerique-offer[data-pf-numerique-id]' );
	var miniCart = document.querySelector( '.kadence-mini-cart-refresh' );

	if ( offer && miniCart && check ) {
		var numeriqueId = offer.getAttribute( 'data-pf-numerique-id' );

		var syncOffer = function () {
			var inCart = !! document.querySelector(
				'.kadence-mini-cart-refresh a.remove[data-product_id="' + numeriqueId + '"]'
			);
			offer.classList.toggle( 'is-in-cart', inCart );
			if ( inCart && check.checked ) {
				// Décochée pour de bon : la case pilote l'attribut d'ajout du
				// bouton, elle ne doit pas rester armée sous une offre masquée.
				check.checked = false;
				check.dispatchEvent( new Event( 'change', { bubbles: true } ) );
			}
		};

		// Le fragment REMPLACE `.kadence-mini-cart-refresh` à chaque changement
		// de panier : on observe donc son parent, qui lui reste en place.
		new MutationObserver( syncOffer ).observe( miniCart.parentNode, {
			childList: true,
			subtree: true
		} );
		syncOffer();
	}

	// Infobulle(s) — composant partagé pf-tooltip.js. Deux contextes mutuellement
	// exclusifs sur cette page : l'offre (icône dans le <label> de la case à
	// cocher, preventClick pour ne pas la cocher au clic) et le tooltip
	// « téléchargement » d'un produit numérique (icône hors label).
	if ( window.pfTooltip ) {
		document.querySelectorAll( '.pf-numerique-tip' ).forEach( function ( tip ) {
			var inLabel = !! tip.closest( '.pf-numerique-offer' );
			window.pfTooltip.wire( tip, inLabel ? { preventClick: true } : undefined );
		} );
	}
} )();
