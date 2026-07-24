/**
 * Fiche de commande (checkout, blocs) : remplace le placeholder par défaut du
 * champ « note de commande » par un texte orienté cadeau.
 *
 * Le textarea (composant React @woocommerce/blocks-components) n'existe dans
 * le DOM que lorsque la case « Ajouter une note à votre commande » est cochée
 * — il est démonté/remonté à chaque bascule, avec le placeholder d'origine
 * repassé à chaque fois. D'où l'observer, qui réapplique notre texte à chaque
 * réapparition plutôt qu'un simple réglage au chargement.
 */
( function () {
	var PLACEHOLDER = 'Paquet-cadeau, masquage du prix, ajout d’un mot personnalisé…';

	function applyPlaceholder() {
		var textareas = document.querySelectorAll(
			'.wc-block-checkout__add-note .wc-block-components-textarea'
		);
		for ( var i = 0; i < textareas.length; i++ ) {
			if ( textareas[ i ].placeholder !== PLACEHOLDER ) {
				textareas[ i ].placeholder = PLACEHOLDER;
			}
		}
	}

	document.addEventListener( 'DOMContentLoaded', function () {
		applyPlaceholder();

		new MutationObserver( applyPlaceholder ).observe( document.body, {
			childList: true,
			subtree: true,
		} );
	} );
} )();
