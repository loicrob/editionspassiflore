/**
 * Vue mois (mobile) — popup d'événements AU-DESSUS du jour tapé.
 *
 * Remplace le comportement natif de TEC (les événements du jour tapé s'affichent en
 * bas de la grille, panneau `…__mobile-day--show`) par un popup flottant ancré au jour,
 * réutilisant le composant d'infobulle de la vue carte (.pf-map-pop) : en-tête = le
 * jour, tuiles avec ligne « Lieu ». Un jour SANS événement n'ouvre rien.
 *
 * Fonctionnement :
 *   - Le contenu du popup est pré-rendu côté serveur dans le panneau mobile natif
 *     (override mobile-day.php), gardé caché (règle CSS `.pf-month-pop-active`) et servant
 *     de source de données : on le CLONE dans un carton flottant .pf-month-pop-layer.
 *   - Un écouteur de CAPTURE sur `document` intercepte le tap sur un bouton-jour, bloque
 *     le handler natif (bubble, posé par month-mobile-events.js) via stopPropagation, et
 *     ouvre/ferme notre popup. Délégué → survit aux bascules de vue AJAX de TEC (aucun
 *     écouteur par élément à ré-attacher).
 *   - Ne s'active que sur mobile : les boutons-jour sont `display:none` au-dessus du
 *     point de rupture « medium » de TEC → aucun tap à intercepter sur desktop.
 *
 * Repli sans JS : la classe `.pf-month-pop-active` n'étant jamais posée, le panneau natif
 * reste visible et piloté par TEC (événements en bas de grille).
 */
( function () {
	'use strict';

	var DAY_BTN   = '[data-js="tribe-events-calendar-month-day-cell-mobile"]';
	var LAYER_CLS = 'pf-month-pop-layer';
	var GAP       = 8;   // px entre le popup et le bord du jour
	var MARGIN    = 8;   // px de marge minimale au bord du viewport

	var layer   = null;  // carton flottant courant
	var openBtn = null;  // bouton-jour actuellement ouvert

	// Résout un token longueur --pf-* en px (custom property → valeur brute sinon).
	function stickyOffset() {
		var v = getComputedStyle( document.documentElement ).getPropertyValue( '--pf-sticky-offset' );
		return parseFloat( v ) || 0;
	}

	function panelFor( btn ) {
		var id = btn.getAttribute( 'aria-controls' );
		return id ? document.getElementById( id ) : null;
	}

	function close() {
		if ( layer ) { layer.remove(); layer = null; }
		if ( openBtn ) { openBtn.setAttribute( 'aria-expanded', 'false' ); openBtn = null; }
	}

	// Rétrécit le carton à la largeur NATURELLE de son contenu, plafonnée au cap CSS
	// (max-width) — même comportement que l'infobulle Leaflet de la vue carte
	// (minWidth:0/maxWidth) : un seul événement → largeur d'une tuile + ses espacements ;
	// plusieurs → cap atteint et la rangée défile horizontalement.
	// Les overflow scrollants (.pf-hscroll en X, .pf-map-pop__scroll en Y) ne contribuent
	// AUCUNE largeur intrinsèque → un `width:max-content` s'effondrerait à la largeur de
	// l'en-tête seul. On les neutralise le temps de la mesure, on lit, on plafonne, on
	// restaure. offsetWidth = largeur border-box (padding du carton inclus).
	function fitWidth( el ) {
		var events = el.querySelector( '.pf-map-pop__events' );
		var scroll = el.querySelector( '.pf-map-pop__scroll' );
		var cap    = parseFloat( getComputedStyle( el ).maxWidth ) || 320;

		if ( events ) events.style.overflowX = 'visible';
		if ( scroll ) scroll.style.overflowY = 'visible';
		el.style.width = 'max-content';

		var natural = el.offsetWidth;

		if ( events ) events.style.overflowX = '';
		if ( scroll ) scroll.style.overflowY = '';
		el.style.width = Math.min( natural, cap ) + 'px';
	}

	function position( el, btn ) {
		var r  = btn.getBoundingClientRect();
		var lw = el.offsetWidth;
		var lh = el.offsetHeight;
		var vw = document.documentElement.clientWidth;

		// Centré horizontalement sur le jour, borné au viewport.
		var left = r.left + r.width / 2 - lw / 2;
		left = Math.max( MARGIN, Math.min( left, vw - lw - MARGIN ) );

		// Au-dessus du jour si la place le permet (sous le header sticky), sinon dessous.
		var top;
		if ( r.top - stickyOffset() >= lh + GAP ) {
			top = r.top - lh - GAP;
		} else {
			top = r.bottom + GAP;
		}

		// Coordonnées document (position:absolute sur <body>) → suit le jour au scroll.
		el.style.left = ( left + window.scrollX ) + 'px';
		el.style.top  = ( top + window.scrollY ) + 'px';
	}

	function open( btn ) {
		var panel   = panelFor( btn );
		var content = panel ? panel.querySelector( '.pf-map-pop' ) : null;
		if ( ! content ) return;   // jour sans événement → rien

		close();

		layer = document.createElement( 'div' );
		layer.className = LAYER_CLS;

		var close_btn = document.createElement( 'button' );
		close_btn.type = 'button';
		close_btn.className = 'pf-month-pop__close';
		close_btn.setAttribute( 'aria-label', 'Fermer' );
		close_btn.innerHTML = '&times;';
		close_btn.addEventListener( 'click', function ( e ) {
			e.stopPropagation();
			close();
		} );

		layer.appendChild( close_btn );
		layer.appendChild( content.cloneNode( true ) );
		document.body.appendChild( layer );

		if ( typeof window.pfScrollFade === 'function' ) {
			window.pfScrollFade( layer );
		}

		fitWidth( layer );
		position( layer, btn );
		openBtn = btn;
		btn.setAttribute( 'aria-expanded', 'true' );
	}

	// Capture sur document : intercepte AVANT le handler natif (bubble) et le neutralise.
	document.addEventListener( 'click', function ( e ) {
		var btn = e.target.closest ? e.target.closest( DAY_BTN ) : null;
		if ( btn ) {
			// Boutons-jour cachés (desktop) : offsetParent null → laisser filer (inerte).
			if ( btn.offsetParent === null ) return;
			e.preventDefault();
			e.stopPropagation();   // bloque toggleMobileEvents (month-mobile-events.js)
			if ( openBtn === btn ) { close(); }
			else { open( btn ); }
			return;
		}
		// Tap hors popup → fermeture (les taps DANS le popup — tuiles, × — n'y passent pas).
		if ( layer && ! ( e.target.closest && e.target.closest( '.' + LAYER_CLS ) ) ) {
			close();
		}
	}, true );

	// Reflow (rotation, redimensionnement, passage desktop) → position périmée : on ferme.
	window.addEventListener( 'resize', close, { passive: true } );

	// Active le remplacement du panneau natif (source de données cachée). Posé une fois
	// sur <html> (non remplacé par l'AJAX de TEC) → survit aux bascules de vue.
	document.documentElement.classList.add( 'pf-month-pop-active' );
} )();
