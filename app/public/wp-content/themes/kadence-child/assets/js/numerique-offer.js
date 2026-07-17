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
	if ( ! check || ! button ) {
		return;
	}

	function sync() {
		if ( check.checked ) {
			button.setAttribute( 'data-pf_add_numerique', '1' );
		} else {
			button.removeAttribute( 'data-pf_add_numerique' );
		}
	}

	check.addEventListener( 'change', sync );
	sync();
} )();
