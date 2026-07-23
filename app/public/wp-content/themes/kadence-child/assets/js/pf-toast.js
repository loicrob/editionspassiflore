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
 *   opts.duration  : ms avant fermeture auto (défaut 5000 ; 0 = illimité).
 *   opts.actions   : [ { label, onClick } ] → boutons .pf-btn.pf-btn--sm.
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
		toast.className = 'pf-toast';
		toast.setAttribute( 'role', 'status' );
		toast.tabIndex = -1;

		// Colonne texte + actions (les boutons se placent sous le texte).
		var body = document.createElement( 'div' );
		body.className = 'pf-toast__body';
		toast.appendChild( body );

		var msg = document.createElement( 'div' );
		msg.className = 'pf-toast__msg';
		msg.innerHTML = opts.html || '';
		body.appendChild( msg );

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
				var btn = document.createElement( 'button' );
				btn.type = 'button';
				btn.className = 'pf-btn pf-btn--sm';
				btn.textContent = a.label || '';
				btn.addEventListener( 'click', function () {
					if ( typeof a.onClick === 'function' ) {
						try { a.onClick(); } catch ( e ) {}
					}
					close( 'action' );
				} );
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
