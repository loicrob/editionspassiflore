/**
 * Fiche livre — suppression de son propre avis.
 *
 * Le bouton n'est rendu par le serveur que sur les avis de l'utilisateur connecté
 * (publiés comme en attente) : rien à vérifier côté client, l'endpoint revalidant
 * de son côté la propriété du commentaire.
 *
 * Confirmation par toast (et non window.confirm()) : « Voulez-vous vraiment
 * supprimer votre avis ? » avec « Annuler »/« Confirmer », sans fermeture
 * automatique — la suppression n'a lieu qu'au clic sur « Confirmer ».
 *
 * Amélioration progressive : sans JS, l'avis reste simplement affiché — la
 * suppression est une action de confort, pas un chemin critique.
 */
( function () {
	'use strict';

	if ( typeof pfAvisDelete === 'undefined' ) {
		return;
	}

	// Écouteur posé sur le <section> pf-section entier (id dérivé du label par
	// sanitize_title() dans pf_sectionnav_sections(), toujours présent dès que
	// comments_open() — cf. inc/book-single-tabs.php), et non sur
	// .bs-avis-section[data-section="lecteurs"] : ce dernier n'existe pas encore
	// pour le tout premier avis d'un livre, que le dépôt AJAX (avis-submit.js)
	// peut désormais créer en cours de session, sans reload.
	var wrapper = document.getElementById( 'avis-des-lecteurs' );
	if ( ! wrapper ) {
		return;
	}

	// Icônes Material Symbols (même famille que le reste du site), colorées par
	// `.pf-toast__icon` — currentColor, pas le blanc d'origine (icônes pensées
	// pour un fond de couleur, ici c'est le fond crème du toast qui domine).
	var ICON_ASK     = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 -960 960 960" fill="currentColor" aria-hidden="true"><path d="m480-504 76 76q11 11 28 11t28-11q11-11 11-28t-11-28l-76-76 76-76q11-11 11-28t-11-28q-11-11-28-11t-28 11l-76 76-76-76q-11-11-28-11t-28 11q-11 11-11 28t11 28l76 76-76 76q-11 11-11 28t11 28q11 11 28 11t28-11l76-76ZM240-240l-92 92q-19 19-43.5 8.5T80-177v-623q0-33 23.5-56.5T160-880h640q33 0 56.5 23.5T880-800v480q0 33-23.5 56.5T800-240H240Zm-34-80h594v-480H160v525l46-45Zm-46 0v-480 480Z"/></svg>';
	var ICON_DELETED = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 -960 960 960" fill="currentColor" aria-hidden="true"><path d="M120-480q-17 0-28.5-11.5T80-520v-80q0-17 11.5-28.5T120-640q17 0 28.5 11.5T160-600v80q0 17-11.5 28.5T120-480Zm-15 340q-11-5-18-14t-7-23v-183q0-17 11.5-28.5T120-400q17 0 28.5 11.5T160-360v85l46-45h74q17 0 28.5 11.5T320-280q0 17-11.5 28.5T280-240h-40l-92 92q-10 10-21 11.5t-22-3.5Zm335-100q-17 0-28.5-11.5T400-280q0-17 11.5-28.5T440-320h80q17 0 28.5 11.5T560-280q0 17-11.5 28.5T520-240h-80Zm240 0q-17 0-28.5-11.5T640-280q0-17 11.5-28.5T680-320h120v-40q0-17 11.5-28.5T840-400q17 0 28.5 11.5T880-360v40q0 33-23.5 56.5T800-240H680Zm131.5-251.5Q800-503 800-520v-80q0-17 11.5-28.5T840-640q17 0 28.5 11.5T880-600v80q0 17-11.5 28.5T840-480q-17 0-28.5-11.5Zm0-239Q800-742 800-759v-41H680q-17 0-28.5-11.5T640-840q0-17 11.5-28.5T680-880h120q33 0 56.5 23.5T880-800v41q0 17-11.5 28.5T840-719q-17 0-28.5-11.5ZM440-800q-17 0-28.5-11.5T400-840q0-17 11.5-28.5T440-880h80q17 0 28.5 11.5T560-840q0 17-11.5 28.5T520-800h-80Zm-320 81q-17 0-28.5-11.5T80-759v-41q0-33 23.5-56.5T160-880h120q17 0 28.5 11.5T320-840q0 17-11.5 28.5T280-800H160v41q0 17-11.5 28.5T120-719Z"/></svg>';

	function performDelete( btn, item ) {
		// Désarmé pendant l'appel : un second clic renverrait un 404 (l'avis est déjà
		// en corbeille), que le client ne saurait distinguer d'un refus réel.
		btn.disabled = true;

		var fd = new FormData();
		fd.append( 'action', 'pf_avis_delete' );
		fd.append( 'nonce', pfAvisDelete.nonce );
		fd.append( 'comment_id', item.dataset.commentId );

		fetch( pfAvisDelete.ajax_url, { method: 'POST', body: fd, credentials: 'same-origin' } )
			.then( function ( r ) {
				// Nonce périmé (onglet ancien) → toast de session. Mode 'confirm' et non
				// 'reload' : la même section porte le formulaire de dépôt d'avis, qu'un
				// rechargement automatique viderait de la saisie en cours.
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
					btn.disabled = false;
					window.pfToast.show( { html: pfAvisDelete.strings.error, status: 'error' } );
					return;
				}

				// Le focus est sur un bouton qui disparaît : sans reprise il retombe sur
				// <body> et le lecteur d'écran perd le fil. Le titre de section est le
				// point de repère le plus proche.
				var heading = wrapper.querySelector( '.pf-section__title' );

				item.remove();

				if ( heading ) {
					heading.tabIndex = -1;
					heading.focus();
				}

				window.pfToast.show( { icon: ICON_DELETED, html: pfAvisDelete.strings.deleted, status: 'success' } );
			} )
			.catch( function ( err ) {
				// Réseau coupé, réponse illisible… : on le dit. Un échec muet laisserait
				// croire que le clic n'a pas été pris en compte.
				btn.disabled = false;
				window.pfToast.show( { html: pfAvisDelete.strings.error, status: 'error' } );
				console.error( 'pf-avis-delete:', err );
			} );
	}

	wrapper.addEventListener( 'click', function ( e ) {
		var btn = e.target.closest( '.bs-avis-supprimer' );
		if ( ! btn ) {
			return;
		}

		var item = btn.closest( '.bs-avis-item' );
		if ( ! item || ! item.dataset.commentId ) {
			return;
		}

		// Désarmé tant que le toast de confirmation est ouvert, sinon un second clic
		// empile un second toast par-dessus le premier. Réarmé à la fermeture SAUF si
		// « Confirmer » a été cliqué : performDelete() reprend alors la main sur
		// btn.disabled (désarmé le temps de l'appel, réarmé seulement en cas d'échec).
		btn.disabled = true;
		var confirmed = false;

		// Sans temps imparti : la suppression n'a lieu qu'au clic sur « Confirmer »
		// (ou pas du tout — fermer le toast équivaut à « Annuler »).
		window.pfToast.show( {
			icon: ICON_ASK,
			html: pfAvisDelete.strings.confirm,
			duration: 0,
			actions: [
				{ label: pfAvisDelete.strings.cancel_btn, outline: true },
				{ label: pfAvisDelete.strings.confirm_btn, onClick: function () {
					confirmed = true;
					performDelete( btn, item );
				} }
			],
			onClose: function () {
				if ( ! confirmed ) {
					btn.disabled = false;
				}
			}
		} );
	} );
} )();
