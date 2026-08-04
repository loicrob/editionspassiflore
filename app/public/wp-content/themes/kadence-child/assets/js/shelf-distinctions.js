/**
 * Infobulle « Distinctions » des étagères filtrées par distinctions
 * (`decouvrir="distinctions"`). Le bouton rond posé sur le chant de la
 * planche (Passiflore_Bookshelf::distinctions_html) ouvre une bulle listant les
 * distinctions du livre.
 *
 * ⚠️ Bulle UNIQUE posée en <body>, positionnée depuis le rect écran du bouton —
 * même idiome que l'infobulle des signets (shelf-bookmarks.js) et pour la même
 * raison : ancrée dans le DOM, elle serait rognée par l'`overflow` du rayon en
 * mode scroll, et embarquée dans la scène 3D du livre.
 *
 * Ouverture au CLIC, au clavier, ET au survol — mais deux régimes distincts,
 * comme les autres infobulles du site (cf. pf-tooltip.js) :
 *  - survol seul  → aperçu TRANSITOIRE, se referme (délai HOVER_CLOSE_DELAY)
 *    dès que le pointeur quitte le bouton ET la bulle (le délai laisse le
 *    temps de traverser le GAP entre les deux, la bulle étant scrollable).
 *  - clic/clavier → ÉPINGLÉE, ignore le survol/départ du pointeur, ne se
 *    referme qu'au clic ailleurs, à Échap, ou au scroll/resize. Un clic sur
 *    un aperçu déjà ouvert au survol l'épingle (n'importe où dans la bulle
 *    ferme aussi son propre bouton) plutôt que de le fermer.
 */
( function () {
	'use strict';

	var TRIGGER = '.pf-book-distinctions';
	var GAP     = 8;   // bouton ↔ bulle
	var PAD     = 8;   // bulle ↔ bord d'écran
	var HOVER_CLOSE_DELAY = 200;

	var tip        = null;
	var tipContent = null;
	var current    = null;
	var pinned     = false; // ouverte par clic/clavier : ignore le survol
	var closeTimer = null;

	function cancelHoverClose() {
		if ( closeTimer ) {
			clearTimeout( closeTimer );
			closeTimer = null;
		}
	}

	function ensureTip() {
		if ( tip && document.body.contains( tip ) ) {
			return tip;
		}
		tip = document.createElement( 'div' );
		tip.className = 'pf-distinctions-tip';
		tip.setAttribute( 'role', 'dialog' );
		tip.setAttribute( 'aria-label', 'Distinctions' );
		// Focalisable : le contenu de la bulle n'est PAS annoncé par la seule
		// ouverture (le bouton n'a pas d'aria-describedby, la liste changeant
		// d'un livre à l'autre) — c'est le déplacement du focus dedans qui la
		// fait lire, et Échap ramène ensuite au bouton.
		tip.setAttribute( 'tabindex', '-1' );

		// Croix de fermeture — seulement pertinente épinglée (un aperçu au
		// survol se referme tout seul) : visibilité pilotée par la classe
		// .is-pinned (style.css). Élément fixe du composant, jamais recréé ni
		// effacé par le innerHTML de tipContent à chaque ouverture.
		var closeBtn = document.createElement( 'button' );
		closeBtn.type = 'button';
		closeBtn.className = 'pf-distinctions-tip__close pf-roundbtn';
		closeBtn.setAttribute( 'aria-label', 'Fermer' );
		closeBtn.innerHTML = '<svg viewBox="0 0 24 24" width="24" height="24" fill="currentColor" aria-hidden="true"><path d="M19 6.41 17.59 5 12 10.59 6.41 5 5 6.41 10.59 12 5 17.59 6.41 19 12 13.41 17.59 19 19 17.59 13.41 12z"/></svg>';
		closeBtn.addEventListener( 'click', function ( e ) {
			e.stopPropagation();
			var trigger = current;
			close();
			if ( trigger ) {
				trigger.focus();
			}
		} );
		tip.appendChild( closeBtn );

		tipContent = document.createElement( 'div' );
		tipContent.className = 'pf-distinctions-tip__content';
		tip.appendChild( tipContent );

		document.body.appendChild( tip );
		return tip;
	}

	/**
	 * Bord bas du header collant. La bulle est au niveau des toasts (z-index),
	 * donc elle passerait PAR-DESSUS le header : sans ce plafond, une longue
	 * liste ouverte en haut d'écran le recouvrirait au lieu de basculer.
	 */
	function ceiling() {
		var v = parseFloat( getComputedStyle( document.documentElement )
			.getPropertyValue( '--pf-sticky-offset' ) );
		return ( isNaN( v ) ? 0 : v ) + PAD;
	}

	function position( trigger ) {
		var r  = trigger.getBoundingClientRect();
		// Mesure à la place naturelle : un `left`/`top` hérité de l'ouverture
		// précédente fausserait la largeur si la bulle butait sur un bord.
		tip.style.left = '0px';
		tip.style.top  = '0px';
		var t = tip.getBoundingClientRect();

		var top0 = ceiling();
		var left = r.left + r.width / 2 - t.width / 2;
		var top  = r.top - t.height - GAP;
		if ( top < top0 ) {
			top = r.bottom + GAP;               // bascule sous le bouton
			if ( top + t.height > window.innerHeight - PAD ) {
				top = Math.max( top0, window.innerHeight - PAD - t.height );
			}
		}
		if ( left < PAD ) {
			left = PAD;
		} else if ( left + t.width > window.innerWidth - PAD ) {
			left = window.innerWidth - PAD - t.width;
		}
		tip.style.left = Math.round( left ) + 'px';
		tip.style.top  = Math.round( top ) + 'px';
	}

	function close() {
		cancelHoverClose();
		pinned = false;
		if ( ! current ) {
			return;
		}
		current.setAttribute( 'aria-expanded', 'false' );
		current = null;
		if ( tip ) {
			tip.classList.remove( 'is-visible', 'is-pinned' );
		}
	}

	function scheduleHoverClose() {
		cancelHoverClose();
		closeTimer = setTimeout( function () {
			closeTimer = null;
			close();
		}, HOVER_CLOSE_DELAY );
	}

	// opts.focus: false lors d'une ouverture au survol — déplacer le focus
	// clavier au simple passage de la souris volerait le focus à ce que
	// l'utilisateur était en train de faire (ex. un champ de recherche survolé
	// au passage). opts.pin: false = aperçu transitoire (voir en tête de fichier).
	function open( trigger, opts ) {
		var tpl = trigger.querySelector( '.pf-book-distinctions__data' );
		if ( ! tpl ) {
			return;
		}
		opts = opts || {};
		close();
		var el = ensureTip();
		tipContent.innerHTML = '';
		tipContent.appendChild( tpl.content.cloneNode( true ) );
		tipContent.scrollTop = 0;
		pinned = opts.pin !== false;
		el.classList.add( 'is-visible' );
		el.classList.toggle( 'is-pinned', pinned );
		current = trigger;
		trigger.setAttribute( 'aria-expanded', 'true' );
		position( trigger );
		if ( opts.focus !== false ) {
			el.focus( { preventScroll: true } );
		}
	}

	function toggle( trigger ) {
		if ( current === trigger ) {
			if ( pinned ) {
				close();
			} else {
				// Déjà ouverte en aperçu survol : un clic l'épingle au lieu de
				// la fermer — cf. régime « clic/clavier » en tête de fichier.
				cancelHoverClose();
				pinned = true;
				if ( tip ) {
					tip.classList.add( 'is-pinned' );
					tip.focus( { preventScroll: true } );
				}
			}
		} else {
			open( trigger );
		}
	}

	// Phase de CAPTURE : le bouton vit dans le <a> du livre, il faut retenir la
	// navigation avant tout autre gestionnaire posé sur le livre lui-même.
	document.addEventListener( 'click', function ( e ) {
		var trigger = e.target.closest ? e.target.closest( TRIGGER ) : null;
		if ( trigger ) {
			e.preventDefault();
			e.stopPropagation();
			toggle( trigger );
			return;
		}
		// Clic dans la bulle (défilement, sélection de texte) : on la garde.
		if ( tip && tip.contains( e.target ) ) {
			return;
		}
		close();
	}, true );

	// mouseover/mouseout (délégués, contrairement à mouseenter/mouseleave) car
	// le bouton et la bulle sont deux sous-arbres disjoints (la bulle vit en
	// <body>) : impossible de câbler un survol composé sans délégation.
	document.addEventListener( 'mouseover', function ( e ) {
		var trigger = e.target.closest ? e.target.closest( TRIGGER ) : null;
		if ( trigger ) {
			cancelHoverClose();
			if ( current !== trigger ) {
				open( trigger, { focus: false, pin: false } );
			}
			return;
		}
		if ( tip && tip.contains( e.target ) ) {
			cancelHoverClose();
		}
	} );

	// Épinglée (ouverte par clic/clavier) : le survol/départ du pointeur ne la
	// concerne plus, seuls le clic ailleurs/Échap/scroll/resize la ferment.
	document.addEventListener( 'mouseout', function ( e ) {
		if ( pinned ) {
			return;
		}
		var trigger = e.target.closest ? e.target.closest( TRIGGER ) : null;
		if ( trigger && ( ! e.relatedTarget || ! trigger.contains( e.relatedTarget ) ) ) {
			scheduleHoverClose();
			return;
		}
		if ( tip && tip.contains( e.target ) && ( ! e.relatedTarget || ! tip.contains( e.relatedTarget ) ) ) {
			scheduleHoverClose();
		}
	} );

	document.addEventListener( 'keydown', function ( e ) {
		if ( e.key === 'Escape' ) {
			var focusBack = current;
			close();
			if ( focusBack ) {
				focusBack.focus();
			}
			return;
		}
		if ( e.key !== 'Enter' && e.key !== ' ' ) {
			return;
		}
		var trigger = e.target.closest ? e.target.closest( TRIGGER ) : null;
		if ( ! trigger ) {
			return;
		}
		e.preventDefault();
		toggle( trigger );
	} );

	// La bulle est ancrée à un rect écran → tout ce qui le déplace la ferme.
	// ⚠️ En capture, `scroll` remonte aussi depuis la bulle elle-même (elle est
	// scrollable) : on l'ignore, sinon la lire la fermerait.
	window.addEventListener( 'scroll', function ( e ) {
		if ( tip && e.target === tip ) {
			return;
		}
		close();
	}, true );
	window.addEventListener( 'resize', close );
} )();
