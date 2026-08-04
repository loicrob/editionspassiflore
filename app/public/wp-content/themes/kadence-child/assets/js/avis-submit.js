/**
 * Fiche livre — dépôt d'un avis sans rechargement de page.
 *
 * Le formulaire (comment_form(), inc/book-single-tabs.php) se poste en AJAX ;
 * le serveur rend le nouvel avis (en attente de validation) en HTML, inséré en
 * tête de la liste — cohérent avec le tri « du plus récent au plus ancien » et
 * avec la règle « son propre avis est toujours déplié ».
 *
 * Amélioration progressive : sans JS, le formulaire poste normalement vers
 * wp-comments-post.php (chemin classique inchangé, cf. passiflore_avis_is_ajax_submit
 * dans inc/book-single-tabs.php) et la page se recharge avec l'avis affiché.
 */
( function () {
	'use strict';

	if ( typeof pfAvisSubmit === 'undefined' ) {
		return;
	}

	var formWrap = document.querySelector( '.bs-avis-form' );
	var form     = formWrap ? formWrap.querySelector( '#commentform' ) : null;
	if ( ! form ) {
		return;
	}

	// Icône Material Symbols (même famille que le reste du site), colorée par
	// `.pf-toast__icon` — currentColor, pas le blanc d'origine (icône pensée pour
	// un fond de couleur, ici c'est le fond crème du toast qui domine).
	var ICON_SUBMITTED = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 -960 960 960" fill="currentColor" aria-hidden="true"><path d="m694-273 142-142q12-12 28-11.5t28 12.5q11 12 11.5 28T892-358L722-188q-12 12-28 12t-28-12l-85-86q-11-11-11.5-27.5T581-330q11-11 28-11t28 11l57 57Zm-454 33-92 92q-19 19-43.5 8.5T80-177v-623q0-33 23.5-56.5T160-880h640q33 0 56.5 23.5T880-800v240q0 17-11.5 28.5T840-520q-17 0-28.5-11.5T800-560v-240H160v525l46-45h234q17 0 28.5 11.5T480-280q0 17-11.5 28.5T440-240H240Zm-80-80v-480 480Z"/></svg>';

	// La section peut ne pas encore exister (premier avis du livre) : le PHP ne
	// rend le conteneur que s'il y a déjà des entrées (cf. passiflore_render_avis_lecteurs).
	function ensureSection() {
		var section = document.querySelector( '.bs-avis-section[data-section="lecteurs"]' );
		if ( section ) {
			return section;
		}
		var tab = document.createElement( 'div' );
		tab.className = 'bs-tab-avis';
		section = document.createElement( 'div' );
		section.className = 'bs-avis-section';
		section.setAttribute( 'data-section', 'lecteurs' );
		tab.appendChild( section );
		formWrap.parentNode.insertBefore( tab, formWrap );
		return section;
	}

	form.addEventListener( 'submit', function ( e ) {
		e.preventDefault();

		var button = form.querySelector( '#submit' );
		var data   = new FormData( form );
		data.append( 'action', 'pf_avis_submit' );
		data.append( 'nonce', pfAvisSubmit.nonce );

		if ( button ) {
			button.disabled = true;
		}

		fetch( pfAvisSubmit.ajax_url, { method: 'POST', body: data, credentials: 'same-origin' } )
			.then( function ( r ) {
				// Nonce périmé (onglet ancien) → toast de session. Mode 'confirm' et non
				// 'reload' : un rechargement automatique viderait la saisie en cours.
				if ( r.status === 403 ) {
					if ( window.pfSessionExpired ) {
						window.pfSessionExpired( { mode: 'confirm' } );
					}
					return null;
				}
				return r.json();
			} )
			.then( function ( payload ) {
				if ( ! payload ) {
					return; // 403 déjà traité
				}
				if ( ! payload.success ) {
					var msg = ( payload.data && payload.data.message ) || pfAvisSubmit.strings.error;
					window.pfToast.show( { html: msg, status: 'error' } );
					return;
				}

				// Résidu d'un échec précédent affiché sans JS (page rechargée avec
				// ?avis_erreur=…) : sans reload ici, il ne serait sinon jamais retiré.
				var errNotice = document.querySelector( '.bs-avis-erreur' );
				if ( errNotice ) {
					errNotice.remove();
				}

				ensureSection().insertAdjacentHTML( 'afterbegin', payload.data.html );

				var textarea = form.querySelector( '#comment' );
				if ( textarea ) {
					textarea.value = '';
				}

				window.pfToast.show( { icon: ICON_SUBMITTED, html: pfAvisSubmit.strings.success, status: 'success' } );
			} )
			.catch( function ( err ) {
				// Réseau coupé, réponse illisible… : on le dit. Un échec muet laisserait
				// croire que le clic n'a pas été pris en compte.
				window.pfToast.show( { html: pfAvisSubmit.strings.error, status: 'error' } );
				console.error( 'pf-avis-submit:', err );
			} )
			.finally( function () {
				if ( button ) {
					button.disabled = false;
				}
			} );
	} );
} )();
