/**
 * « M'avertir si à nouveau disponible » — fiche d'un livre épuisé.
 *
 * Handlers délégués sur `document` (le formulaire peut être remplacé par l'état
 * « c'est fait »). Endpoint à état → nonce conservé : un 403 est traité AVANT
 * `.json()` par window.pfSessionExpired({ mode: 'confirm' }) — jamais 'reload',
 * qui perdrait l'adresse saisie (§ « Sécurité AJAX » de CLAUDE.md).
 *
 * Back-end : inc/stock-alert.php (action pf_stock_alert).
 */
( function () {
	'use strict';

	if ( typeof PfStockAlert === 'undefined' ) {
		return;
	}

	// Champ e-mail (invité) : le bouton, placé avant lui dans le DOM, naît désactivé
	// et suit la validité de la saisie. Entrée dans le champ soumet quand même (vrai <form>).
	document.addEventListener( 'input', function ( e ) {
		var input = e.target;
		if ( ! input.classList || ! input.classList.contains( 'pf-stockalert__email' ) ) {
			return;
		}
		var form = input.closest( '.pf-stockalert' );
		var btn  = form && form.querySelector( '.pf-stockalert__btn' );
		if ( btn ) {
			btn.disabled = ! input.checkValidity();
		}
	} );

	document.addEventListener( 'submit', function ( e ) {
		var form = e.target;
		if ( ! form.classList || ! form.classList.contains( 'pf-stockalert' ) ) {
			return;
		}
		e.preventDefault();

		if ( ! form.reportValidity() ) {
			return;
		}

		var btn        = form.querySelector( '.pf-stockalert__btn' );
		var msg        = form.querySelector( '.pf-stockalert__msg' );
		var emailField = form.querySelector( '.pf-stockalert__email' );

		function reEnable() {
			if ( btn ) {
				btn.disabled = emailField ? ! emailField.checkValidity() : false;
			}
		}

		var data = new FormData( form );
		data.append( 'action', 'pf_stock_alert' );
		data.append( 'nonce', PfStockAlert.nonce );
		data.append( 'product_id', form.getAttribute( 'data-product-id' ) || '' );

		form.classList.add( 'is-loading' );
		if ( btn ) {
			btn.disabled = true;
		}
		if ( msg ) {
			msg.className = 'pf-stockalert__msg';
			msg.textContent = '';
		}

		fetch( PfStockAlert.ajax_url, {
			method: 'POST',
			credentials: 'same-origin',
			body: data
		} )
			.then( function ( r ) {
				if ( r.status === 403 ) {
					if ( window.pfSessionExpired ) {
						window.pfSessionExpired( { mode: 'confirm' } );
					}
					return null;
				}
				return r.json();
			} )
			.then( function ( res ) {
				if ( ! res ) {
					return; // 403 : géré par le toast de session
				}

				if ( res.success && res.data && res.data.html ) {
					// L'état « c'est fait » rendu par le serveur remplace le formulaire
					// et porte à lui seul la confirmation (pas de toast).
					var wrap = document.createElement( 'div' );
					wrap.innerHTML = res.data.html;
					form.replaceWith( wrap.firstElementChild );
					return;
				}

				var text = ( res.data && res.data.message ) || 'Une erreur est survenue.';
				if ( msg ) {
					msg.className = 'pf-stockalert__msg is-error';
					msg.textContent = text;
				}
				reEnable();
			} )
			.catch( function () {
				if ( msg ) {
					msg.className = 'pf-stockalert__msg is-error';
					msg.textContent = 'Une erreur réseau est survenue. Merci de réessayer.';
				}
				reEnable();
			} )
			.finally( function () {
				form.classList.remove( 'is-loading' );
			} );
	} );
} )();
