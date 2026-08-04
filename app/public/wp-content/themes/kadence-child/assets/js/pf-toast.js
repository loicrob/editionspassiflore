/**
 * Composant Toast global réutilisable — `window.pfToast`.
 *
 * IIFE auto-enregistrante (modèle pf-tooltip.js) : idempotente, un seul
 * conteneur global paresseux. Les toasts s'affichent en bas à droite ; le plus
 * récent prend la place du bas, les précédents remontent (conteneur ancré en bas,
 * colonne flex → un `append` suffit).
 *
 * window.pfToast.show( opts ) → handle
 *   opts.html      : markup du message (de CONFIANCE — l'appelant échappe).
 *   opts.icon      : markup d'une icône de tête (SVG, de CONFIANCE) → gouttière
 *                    à gauche du message. Décorative (aria-hidden).
 *   opts.status    : 'success' | 'error' | 'info' → classe `.pf-toast--<statut>`
 *                    (teinte l'icône). 'error' passe aussi le toast en
 *                    `role="alert"` : un message bloquant ne doit pas attendre
 *                    la fin de la lecture en cours pour être annoncé.
 *   opts.duration  : ms avant fermeture auto (défaut 5000 ; 0 = illimité).
 *   opts.actions   : [ { label, onClick, outline } | { label, href, outline } ] →
 *                    .pf-btn--primary.--sm (ou .pf-btn--outline.--sm si `outline: true`,
 *                    pour une action secondaire — ex. « Annuler » à côté d'une action
 *                    primaire dans le même toast).
 *                    `href` produit un vrai lien (clic milieu / nouvel onglet
 *                    possibles) et ne ferme pas le toast : la navigation s'en charge,
 *                    et un ctrl-clic doit laisser le toast en place.
 *   opts.onClose   : fn(reason) appelée une seule fois au départ du toast.
 *                    reason ∈ 'timeout' | 'close' | 'action' | 'programmatic'.
 *   opts.closeLabel: aria-label du bouton de fermeture (défaut « Fermer »).
 *   → handle = { el, dismiss(reason='programmatic') }.
 *
 * Le survol/focus met le minuteur en pause (WCAG « assez de temps »). Non-modal :
 * ne vole jamais le focus. Échap sur un toast focalisé le ferme.
 */
( function () {
	'use strict';

	window.pfToast = window.pfToast || {};
	if ( window.pfToast.show ) {
		return;
	}

	var region = null;

	function ensureRegion() {
		if ( region && document.body.contains( region ) ) {
			return region;
		}
		region = document.createElement( 'div' );
		region.className = 'pf-toast-region';
		region.setAttribute( 'role', 'region' );
		region.setAttribute( 'aria-label', 'Notifications' );
		region.setAttribute( 'aria-live', 'polite' );
		document.body.appendChild( region );
		return region;
	}

	window.pfToast.show = function ( opts ) {
		opts = opts || {};
		var duration = ( typeof opts.duration === 'number' ) ? opts.duration : 5000;
		var reg      = ensureRegion();

		var toast = document.createElement( 'div' );
		toast.className = 'pf-toast' + ( opts.status ? ' pf-toast--' + opts.status : '' );
		toast.setAttribute( 'role', 'error' === opts.status ? 'alert' : 'status' );
		toast.tabIndex = -1;

		// Colonne texte + actions (les boutons se placent sous le texte).
		var body = document.createElement( 'div' );
		body.className = 'pf-toast__body';
		toast.appendChild( body );

		// Ligne icône + message. Ce niveau existe pour que l'icône se centre sur le
		// SEUL bloc de texte : sœur de .pf-toast__body, elle se centrerait sur
		// texte + boutons et descendrait dès qu'une action est présente.
		var main = document.createElement( 'div' );
		main.className = 'pf-toast__main';
		body.appendChild( main );

		if ( opts.icon ) {
			var icon = document.createElement( 'span' );
			icon.className = 'pf-toast__icon';
			icon.setAttribute( 'aria-hidden', 'true' );
			icon.innerHTML = opts.icon;
			main.appendChild( icon );
		}

		var msg = document.createElement( 'div' );
		msg.className = 'pf-toast__msg';
		msg.innerHTML = opts.html || '';
		main.appendChild( msg );

		var closed  = false;
		var timer   = null;
		var started = 0;
		var left    = duration;

		function clearTimer() {
			if ( timer !== null ) {
				clearTimeout( timer );
				timer = null;
			}
		}

		function startTimer() {
			if ( duration <= 0 ) {
				return;
			}
			started = Date.now();
			timer = setTimeout( function () { close( 'timeout' ); }, left );
		}

		function pause() {
			if ( duration <= 0 || timer === null ) {
				return;
			}
			left -= Date.now() - started;
			clearTimer();
		}

		function resume() {
			if ( duration <= 0 || closed || timer !== null ) {
				return;
			}
			if ( left < 0 ) { left = 0; }
			startTimer();
		}

		function close( reason ) {
			if ( closed ) {
				return;
			}
			closed = true;
			clearTimer();
			toast.classList.add( 'is-leaving' );

			var removed = false;
			var remove = function () {
				if ( removed ) { return; }
				removed = true;
				if ( toast.parentNode ) {
					toast.parentNode.removeChild( toast );
				}
			};
			toast.addEventListener( 'transitionend', remove );
			setTimeout( remove, 400 ); // filet si aucune transition ne se déclenche

			if ( typeof opts.onClose === 'function' ) {
				try { opts.onClose( reason ); } catch ( e ) {}
			}
		}

		// Boutons d'action (ex. « Annuler »).
		if ( opts.actions && opts.actions.length ) {
			var actions = document.createElement( 'div' );
			actions.className = 'pf-toast__actions';
			opts.actions.forEach( function ( a ) {
				var btn = document.createElement( a.href ? 'a' : 'button' );
				// --primary/--outline explicite : `.pf-btn` seul ne porte AUCUNE apparence
				// (fond/couleur viennent du modificateur). Un <button> nu héritait
				// du bouton Kadence — de mêmes fond et couleur, par coïncidence —
				// mais un <a> nu retombait en simple lien rouge sans fond.
				btn.className = 'pf-btn pf-btn--' + ( a.outline ? 'outline' : 'primary' ) + ' pf-btn--sm';
				btn.textContent = a.label || '';
				if ( a.href ) {
					btn.href = a.href;
				} else {
					btn.type = 'button';
					btn.addEventListener( 'click', function () {
						if ( typeof a.onClick === 'function' ) {
							try { a.onClick(); } catch ( e ) {}
						}
						close( 'action' );
					} );
				}
				actions.appendChild( btn );
			} );
			body.appendChild( actions );
		}

		// Bouton de fermeture (×).
		var closeBtn = document.createElement( 'button' );
		closeBtn.type = 'button';
		closeBtn.className = 'pf-toast__close pf-roundbtn';
		closeBtn.setAttribute( 'aria-label', opts.closeLabel || 'Fermer' );
		closeBtn.innerHTML = '<svg viewBox="0 0 24 24" width="16" height="16" fill="currentColor" aria-hidden="true"><path d="M19 6.41 17.59 5 12 10.59 6.41 5 5 6.41 10.59 12 5 17.59 6.41 19 12 13.41 17.59 19 19 17.59 13.41 12z"/></svg>';
		closeBtn.addEventListener( 'click', function () { close( 'close' ); } );
		toast.appendChild( closeBtn );

		toast.addEventListener( 'mouseenter', pause );
		toast.addEventListener( 'mouseleave', resume );
		toast.addEventListener( 'focusin', pause );
		toast.addEventListener( 'focusout', resume );
		toast.addEventListener( 'keydown', function ( e ) {
			if ( e.key === 'Escape' ) {
				close( 'close' );
			}
		} );

		// Indicateur de temps avant fermeture auto (barre qui se vide). Purement
		// visuel : le décompte réel reste piloté par startTimer/pause/resume ;
		// la barre se met en pause au survol/focus via CSS (mêmes conditions).
		if ( duration > 0 ) {
			var progress = document.createElement( 'div' );
			progress.className = 'pf-toast__progress';
			progress.style.animationDuration = duration + 'ms';
			toast.appendChild( progress );
		}

		reg.appendChild( toast );
		startTimer();

		return {
			el: toast,
			dismiss: function ( reason ) { close( reason || 'programmatic' ); }
		};
	};
} )();
