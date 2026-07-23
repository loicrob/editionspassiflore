/**
 * « Session expirée » — toast de reprise après un 403 de nonce, construit
 * au-dessus de window.pfToast (assets/js/pf-toast.js).
 *
 * Sur les endpoints AJAX qui conservent un nonce (édition d'avis, panier,
 * newsletter…), un onglet resté ouvert au-delà de la durée de vie du nonce
 * (12–24 h) reçoit un 403 silencieux. Ce helper transforme ce silence en un
 * message clair, avec deux comportements selon qu'il y a une saisie à préserver :
 *
 *   mode 'reload' (défaut) — action pure (panier, suppression, désinscription) :
 *       compte à rebours puis rechargement de la page ; « Annuler » l'annule.
 *       Rien à perdre côté client → on rafraîchit pour repartir avec un nonce frais.
 *       Le × et la fin du compte à rebours confirment le rechargement.
 *
 *   mode 'confirm' — contexte avec saisie (édition d'avis, inscription newsletter) :
 *       reste affiché, JAMAIS de rechargement automatique ; bouton « Actualiser »
 *       explicite. On invite à copier la saisie d'abord (le reload la perdrait).
 *
 * window.pfSessionExpired( { mode } ) — idempotent : un seul toast à la fois.
 */
( function () {
	'use strict';

	if ( window.pfSessionExpired ) {
		return;
	}

	var active = false;

	function reload() {
		window.location.reload();
	}

	window.pfSessionExpired = function ( opts ) {
		// Sans pfToast (ne devrait pas arriver : dépendance de script déclarée),
		// on s'abstient plutôt que de recharger sans prévenir.
		if ( active || ! window.pfToast ) {
			return;
		}
		active = true;

		if ( opts && opts.mode === 'confirm' ) {
			window.pfToast.show( {
				html: '<strong>Votre session a expiré.</strong><br>Copiez votre saisie si besoin, puis actualisez la page.',
				duration: 0,
				closeLabel: 'Fermer',
				actions: [ { label: 'Actualiser', onClick: reload } ],
				onClose: function () { active = false; }
			} );
		} else {
			window.pfToast.show( {
				html: '<strong>Votre session a expiré.</strong><br>La page va être actualisée pour continuer.',
				duration: 5000,
				closeLabel: 'Fermer',
				actions: [ { label: 'Annuler', onClick: function () {} } ],
				onClose: function ( reason ) {
					active = false;
					// « Annuler » (reason 'action') annule ; le × ('close') et la fin
					// du compte à rebours ('timeout') confirment le rechargement.
					if ( reason === 'timeout' || reason === 'close' ) {
						reload();
					}
				}
			} );
		}
	};
} )();
