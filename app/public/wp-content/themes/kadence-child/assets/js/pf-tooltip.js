/**
 * Infobulle « offre numérique » (`.pf-numerique-tip`) — composant partagé :
 * fiche livre (numerique-offer.js) + encart panier (numerique-cart-nudge.js).
 *
 * `window.pfTooltip.wire( tip, opts )` câble un élément `.pf-numerique-tip` :
 * l'affichage au survol/focus reste géré en CSS ; ici on ajoute l'ouverture au
 * tap (tactile) et au clavier, la fermeture au clic extérieur / Échap, et le
 * **recalage horizontal** de la bulle pour qu'elle reste dans le `.site-container`
 * le plus proche (l'icône étant en bout de ligne, la bulle est ancrée à droite
 * et déborderait sinon sous le bord gauche du conteneur).
 *
 * opts.preventClick : annule l'action par défaut du clic — utilisé sur la fiche
 * livre où l'icône vit dans un `<label>` (sinon le clic cocherait la case).
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

	window.pfTooltip.wire = function ( tip, opts ) {
		if ( ! tip || tip._pfTipWired ) {
			return;
		}
		tip._pfTipWired = true;
		opts = opts || {};
		ensureGlobal();

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
			if ( e.key === 'Enter' || e.key === ' ' ) {
				e.preventDefault();
				toggle();
			} else if ( e.key === 'Escape' ) {
				tip.classList.remove( 'is-open' );
			}
		} );
	};
} )();
