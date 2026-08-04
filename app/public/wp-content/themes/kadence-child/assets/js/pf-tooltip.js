/**
 * Infobulle « offre numérique » (`.pf-numerique-tip`) — composant partagé :
 * fiche livre (numerique-offer.js) + encart panier (numerique-cart-nudge.js).
 *
 * `window.pfTooltip.wire( tip, opts )` câble un élément `.pf-numerique-tip` :
 * l'affichage au survol/focus reste géré en CSS ; ici on ajoute l'ouverture au
 * tap (tactile) et au clavier, la fermeture au clic extérieur / Échap, le
 * **recalage horizontal** de la bulle pour qu'elle reste dans le `.site-container`
 * le plus proche (l'icône étant en bout de ligne, la bulle est ancrée à droite
 * et déborderait sinon sous le bord gauche du conteneur), et une **croix de
 * fermeture** injectée dans la bulle (visible seulement épinglée, `.is-open` —
 * cf. style.css). Injectée ici plutôt que dans chaque markup appelant (fiche
 * livre en PHP ×2, encarts panier en JS ×2) pour rester un point d'entrée
 * unique du composant partagé.
 *
 * opts.preventClick : annule l'action par défaut du clic — utilisé sur la fiche
 * livre où l'icône vit dans un `<label>` (sinon le clic cocherait la case). La
 * croix, elle, est un vrai `<button>` : un descendant interactif natif dans un
 * `<label>` n'active pas la case au clic, pas besoin du même traitement.
 */
( function () {
	'use strict';

	window.pfTooltip = window.pfTooltip || {};
	if ( window.pfTooltip.wire ) {
		return;
	}

	var globalWired = false;

	function reposition( tip ) {
		var bubble = tip.querySelector( '.pf-numerique-tip__bubble' );
		if ( ! bubble ) {
			return;
		}
		var container = tip.closest( '.site-container' ) || document.documentElement;
		bubble.style.transform = 'none';
		var b = bubble.getBoundingClientRect();
		var c = container.getBoundingClientRect();
		var pad = 8;
		var dx = 0;
		if ( b.left < c.left + pad ) {
			dx = ( c.left + pad ) - b.left;
		} else if ( b.right > c.right - pad ) {
			dx = ( c.right - pad ) - b.right;
		}
		bubble.style.transform = dx ? 'translateX(' + Math.round( dx ) + 'px)' : 'none';
	}

	function ensureGlobal() {
		if ( globalWired ) {
			return;
		}
		globalWired = true;
		document.addEventListener( 'click', function ( e ) {
			var open = document.querySelectorAll( '.pf-numerique-tip.is-open' );
			for ( var i = 0; i < open.length; i++ ) {
				if ( ! open[ i ].contains( e.target ) ) {
					open[ i ].classList.remove( 'is-open' );
				}
			}
		} );
		window.addEventListener( 'resize', function () {
			var shown = document.querySelectorAll( '.pf-numerique-tip.is-open' );
			for ( var i = 0; i < shown.length; i++ ) {
				reposition( shown[ i ] );
			}
		} );
	}

	function addCloseButton( tip ) {
		var bubble = tip.querySelector( '.pf-numerique-tip__bubble' );
		if ( ! bubble || bubble.querySelector( '.pf-numerique-tip__close' ) ) {
			return;
		}
		var closeBtn = document.createElement( 'button' );
		closeBtn.type = 'button';
		closeBtn.className = 'pf-numerique-tip__close pf-roundbtn';
		closeBtn.setAttribute( 'aria-label', 'Fermer' );
		closeBtn.innerHTML = '<svg viewBox="0 0 24 24" width="24" height="24" fill="currentColor" aria-hidden="true"><path d="M19 6.41 17.59 5 12 10.59 6.41 5 5 6.41 10.59 12 5 17.59 6.41 19 12 13.41 17.59 19 19 17.59 13.41 12z"/></svg>';
		closeBtn.addEventListener( 'click', function ( e ) {
			e.stopPropagation();
			tip.classList.remove( 'is-open' );
			var trigger = tip.querySelector( '.pf-numerique-tip__trigger' );
			if ( trigger ) {
				trigger.focus();
			}
		} );
		bubble.appendChild( closeBtn );
	}

	window.pfTooltip.wire = function ( tip, opts ) {
		if ( ! tip || tip._pfTipWired ) {
			return;
		}
		tip._pfTipWired = true;
		opts = opts || {};
		ensureGlobal();
		addCloseButton( tip );

		var toggle = function () {
			tip.classList.toggle( 'is-open' );
			if ( tip.classList.contains( 'is-open' ) ) {
				reposition( tip );
			}
		};

		tip.addEventListener( 'mouseenter', function () { reposition( tip ); } );
		tip.addEventListener( 'focusin', function () { reposition( tip ); } );
		tip.addEventListener( 'click', function ( e ) {
			if ( opts.preventClick ) {
				e.preventDefault();
			}
			e.stopPropagation();
			toggle();
		} );
		tip.addEventListener( 'keydown', function ( e ) {
			if ( e.key === 'Escape' ) {
				tip.classList.remove( 'is-open' );
				return;
			}
			if ( e.key !== 'Enter' && e.key !== ' ' ) {
				return;
			}
			// La croix est un vrai <button> : elle gère sa propre activation
			// clavier (native click → son propre listener) — pas le toggle
			// générique, qui la ferait rouvrir juste après l'avoir fermée.
			if ( e.target.closest && e.target.closest( '.pf-numerique-tip__close' ) ) {
				return;
			}
			e.preventDefault();
			toggle();
		} );
	};
} )();
