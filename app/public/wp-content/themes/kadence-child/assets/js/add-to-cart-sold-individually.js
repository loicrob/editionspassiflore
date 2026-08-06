/**
 * Fiche livre — produit vendu à l'unité (`sold_individually`) déjà au panier.
 *
 * Sans ce script, un 2e clic sur "Ajouter au panier" part en AJAX, échoue
 * côté serveur (WooCommerce refuse un doublon sold_individually), et
 * provoque un rechargement complet de page pour afficher la notice d'erreur
 * en toast. On désactive plutôt le bouton dès que le produit est détecté au
 * panier — même source de vérité et même pattern MutationObserver que
 * numerique-offer.js (mini-panier du header, fragment WooCommerce).
 *
 * Repli volontairement ouvert : sans mini-panier, le bouton reste actif et
 * le toast d'erreur WooCommerce natif reprend la main.
 */
( function () {
	'use strict';

	var button = document.querySelector( '.bs-hero__cart[data-pf-sold-individually]' );
	var miniCart = document.querySelector( '.kadence-mini-cart-refresh' );

	if ( ! button || ! miniCart ) {
		return;
	}

	var productId = button.getAttribute( 'data-product_id' );

	var sync = function () {
		var inCart = !! document.querySelector(
			'.kadence-mini-cart-refresh a.remove[data-product_id="' + productId + '"]'
		);
		button.classList.toggle( 'is-in-cart', inCart );
		button.setAttribute( 'aria-disabled', inCart ? 'true' : 'false' );
	};

	// Écouteur de CAPTURE sur document, pas sur le bouton : la phase de capture
	// se joue avant la phase "at target", donc avant TOUT écouteur posé
	// directement sur le bouton — notamment add-to-cart-flight.js, qui lance
	// son animation de vol au clic sans jamais vérifier l'état désactivé. Un
	// écouteur sur le bouton lui-même arriverait après (ordre d'attachement),
	// trop tard pour empêcher le vol. stopPropagation() ici coupe aussi le
	// handler cœur WooCommerce délégué sur document.body (qui ignore
	// `e.defaultPrevented`), donc l'AJAX ne part pas non plus.
	document.addEventListener( 'click', function ( e ) {
		if ( e.target.closest( '.bs-hero__cart.is-in-cart' ) ) {
			e.preventDefault();
			e.stopPropagation();
		}
	}, true );

	// Le fragment REMPLACE `.kadence-mini-cart-refresh` à chaque changement de
	// panier : on observe donc son parent, qui lui reste en place.
	new MutationObserver( sync ).observe( miniCart.parentNode, {
		childList: true,
		subtree: true
	} );
	sync();
} )();
