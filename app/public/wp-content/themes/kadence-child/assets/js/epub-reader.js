/**
 * Lecteur ePub — page /mon-compte/livres-numeriques
 *
 * Un clic sur une liseuse de l'étagère ouvre le livre dans un overlay plein
 * écran (epub.js, auto-hébergé). Amélioration progressive : sans JavaScript, le
 * lien de la liseuse reste le permalien de la fiche livre.
 *
 * Rendu de la coque : Passiflore_Ebooks::render_overlay() (inc/class-ebooks.php).
 * Fichier servi par l'endpoint ?pf_epub=<id>, qui vérifie le droit d'accès.
 */
( function () {
	'use strict';

	var CFG = window.PassifloreEpub || {};
	var I18N = CFG.i18n || {};

	var overlay = document.getElementById( 'pf-epub-overlay' );
	var viewport = document.getElementById( 'pf-epub-viewport' );
	if ( ! overlay || ! viewport || typeof window.ePub !== 'function' ) {
		return;
	}

	var titleEl = overlay.querySelector( '.pf-epub-title' );
	var progressEl = overlay.querySelector( '.pf-epub-progress' );
	var stateEl = overlay.querySelector( '.pf-epub-state' );
	var closeBtn = overlay.querySelector( '.pf-epub-close' );
	var prevBtn = overlay.querySelector( '[data-pf-epub-action="prev"]' );
	var nextBtn = overlay.querySelector( '[data-pf-epub-action="next"]' );

	var book = null;
	var rendition = null;
	var currentId = 0;
	var lastFocus = null;
	var saveTimer = null;
	var pending = null;
	var positions = CFG.positions || {};

	/* ─── État affiché ─────────────────────────────────────────────── */

	function showState( message ) {
		if ( ! stateEl ) {
			return;
		}
		if ( ! message ) {
			stateEl.hidden = true;
			stateEl.textContent = '';
			return;
		}
		stateEl.hidden = false;
		stateEl.textContent = message;
	}

	/* ─── Sauvegarde de la position ────────────────────────────────── */

	function queueSave( cfi, pct ) {
		pending = { cfi: cfi, pct: pct };
		if ( saveTimer ) {
			clearTimeout( saveTimer );
		}
		saveTimer = setTimeout( flushSave, 1500 );
	}

	function flushSave( useBeacon ) {
		if ( ! pending || ! currentId ) {
			return;
		}
		var body = new URLSearchParams();
		body.set( 'action', 'pf_epub_position' );
		body.set( 'nonce', CFG.nonce || '' );
		body.set( 'product_id', String( currentId ) );
		body.set( 'cfi', pending.cfi );
		body.set( 'pct', String( pending.pct ) );
		pending = null;

		// Au départ de la page, seul sendBeacon a une chance d'aboutir. Il ne peut
		// pas lire la réponse : un 403 y est donc avalé en silence — même
		// compromis que le sondage de fond du panier (cf. CLAUDE.md).
		if ( useBeacon && navigator.sendBeacon ) {
			navigator.sendBeacon( CFG.ajaxUrl, body );
			return;
		}

		fetch( CFG.ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
			body: body.toString()
		} ).then( function ( res ) {
			// Nonce périmé : on prévient sans jamais recharger d'office — un
			// rechargement jetterait la position qu'on tentait justement de sauver.
			if ( 403 === res.status && window.pfSessionExpired ) {
				window.pfSessionExpired( { mode: 'confirm' } );
			}
		} ).catch( function () {} );
	}

	/* ─── Ouverture / fermeture ────────────────────────────────────── */

	function destroy() {
		if ( rendition ) {
			try {
				rendition.destroy();
			} catch ( e ) {}
			rendition = null;
		}
		if ( book ) {
			try {
				book.destroy();
			} catch ( e ) {}
			book = null;
		}
		// epub.js laisse son conteneur : on repart d'un viewport propre, en
		// préservant le nœud d'état (enfant déclaré côté serveur).
		Array.prototype.slice.call( viewport.children ).forEach( function ( node ) {
			if ( node !== stateEl ) {
				viewport.removeChild( node );
			}
		} );
	}

	function open( productId ) {
		if ( ! productId ) {
			return;
		}
		currentId = productId;
		lastFocus = document.activeElement;

		destroy();
		overlay.classList.add( 'is-open' );
		overlay.removeAttribute( 'aria-hidden' );
		// Sur <html> et non <body> : plus fiable avec le header sticky du site.
		document.documentElement.style.overflow = 'hidden';

		if ( titleEl ) {
			titleEl.textContent = ( CFG.titles && CFG.titles[ productId ] ) || '';
		}
		if ( progressEl ) {
			progressEl.textContent = '';
		}
		showState( I18N.loading || '' );
		if ( closeBtn ) {
			closeBtn.focus();
		}

		// L'URL porte le livre ouvert → le bouton Retour ferme le lecteur, au lieu
		// de quitter la page. Même motif que la liseuse PDF de la fiche livre.
		if ( CFG.baseUrl ) {
			try {
				history.pushState( { pfEpub: productId }, '', CFG.baseUrl.replace( /\/?$/, '/' ) + productId + '/' );
			} catch ( e ) {}
		}

		load( productId );
	}

	function close( skipHistory ) {
		flushSave();
		destroy();
		currentId = 0;
		overlay.classList.remove( 'is-open' );
		overlay.setAttribute( 'aria-hidden', 'true' );
		document.documentElement.style.overflow = '';
		showState( '' );

		if ( ! skipHistory && CFG.baseUrl ) {
			try {
				history.pushState( {}, '', CFG.baseUrl );
			} catch ( e ) {}
		}
		if ( lastFocus && lastFocus.focus ) {
			lastFocus.focus();
		}
	}

	/* ─── Chargement du livre ──────────────────────────────────────── */

	function load( productId ) {
		var url = CFG.fileUrl + ( CFG.fileUrl.indexOf( '?' ) === -1 ? '?' : '&' ) + 'pf_epub=' + productId;

		// On passe un ArrayBuffer à epub.js plutôt que l'URL, pour quatre raisons :
		// notre URL n'a pas d'extension .epub (epub.js la prendrait pour un
		// répertoire déplié) ; un 403/404 devient un message français explicite au
		// lieu d'un rejet opaque ; et le cache HTTP du navigateur s'applique de
		// toute façon au fetch (l'endpoint envoie ETag + Cache-Control: private).
		fetch( url, { credentials: 'same-origin' } )
			.then( function ( res ) {
				if ( 403 === res.status || 401 === res.status ) {
					throw new Error( 'forbidden' );
				}
				if ( ! res.ok ) {
					throw new Error( 'http' );
				}
				return res.arrayBuffer();
			} )
			.then( function ( buffer ) {
				if ( currentId !== productId ) {
					return; // Le lecteur a été fermé ou un autre livre demandé.
				}
				render( buffer, productId );
			} )
			.catch( function ( err ) {
				if ( currentId !== productId ) {
					return;
				}
				showState( 'forbidden' === err.message ? ( I18N.forbidden || '' ) : ( I18N.error || '' ) );
			} );
	}

	function render( buffer, productId ) {
		book = window.ePub( buffer );

		rendition = book.renderTo( viewport, {
			width: '100%',
			height: '100%',
			flow: 'paginated',
			// « auto » : une page sur écran étroit, deux sur écran large — pas de
			// media query maison à tenir en phase.
			spread: 'auto'
		} );

		// epub.js rend le texte dans une <iframe> : un écouteur posé sur `document`
		// ne verrait JAMAIS les touches ni les gestes au-dessus du texte. Il faut
		// s'abonner à l'intérieur de chaque vue, à chaque rendu.
		rendition.on( 'rendered', function ( section, view ) {
			var doc = view && view.document;
			if ( ! doc ) {
				return;
			}
			doc.addEventListener( 'keydown', onKey );
			doc.addEventListener( 'touchstart', onTouchStart, { passive: true } );
			doc.addEventListener( 'touchend', onTouchEnd );
		} );

		// Repère de lecture affiché dans la barre.
		//
		// ⚠️ `location.start.percentage` d'epub.js vaut TOUJOURS 0 tant que
		// `book.locations` n'a pas été généré (vérifié : la barre affichait « 0 % »
		// sur toutes les pages). Générer coûte une passe sur tout le livre, donc on
		// la lance en tâche de fond, sans bloquer la lecture, et le pourcentage
		// n'apparaît qu'une fois prêt. En attendant — et pour les livres sans
		// pagination utile — on affiche le CHAPITRE, disponible immédiatement et
		// souvent plus parlant qu'un pourcentage.
		var locationsReady = false;
		book.ready
			.then( function () {
				return book.locations.generate( 1024 );
			} )
			.then( function () {
				locationsReady = true;
				updateProgress( rendition.currentLocation() );
			} )
			.catch( function () {} );

		function chapterLabel( location ) {
			var href = location && location.start && location.start.href;
			if ( ! href || ! book.navigation ) {
				return '';
			}
			var item = book.navigation.get( href );

			return item && item.label ? item.label.trim() : '';
		}

		function updateProgress( location ) {
			if ( ! progressEl || ! location ) {
				return;
			}
			var parts = [];
			var chapter = chapterLabel( location );
			if ( chapter ) {
				parts.push( chapter );
			}
			if ( locationsReady && location.start && location.start.cfi ) {
				var pct = book.locations.percentageFromCfi( location.start.cfi );
				if ( 'number' === typeof pct && I18N.percent ) {
					parts.push( I18N.percent.replace( '%s', Math.round( pct * 100 ) ) );
				}
			}
			progressEl.textContent = parts.join( ' · ' );
		}

		rendition.on( 'relocated', function ( location ) {
			showState( '' );
			updateProgress( location );

			if ( prevBtn ) {
				prevBtn.disabled = !! ( location && location.atStart );
			}
			if ( nextBtn ) {
				nextBtn.disabled = !! ( location && location.atEnd );
			}
			if ( location && location.start && location.start.cfi ) {
				var pct = locationsReady ? book.locations.percentageFromCfi( location.start.cfi ) : 0;
				queueSave( location.start.cfi, pct || 0 );
			}
		} );

		var saved = positions[ productId ] || positions[ String( productId ) ];
		var startAt = saved && saved.cfi ? saved.cfi : undefined;

		// Un CFI devenu invalide (fichier mis à jour depuis) fait rejeter
		// display() : on retombe alors au début plutôt que sur un écran vide.
		rendition.display( startAt ).catch( function () {
			return rendition.display();
		} ).catch( function () {
			showState( I18N.error || '' );
		} );
	}

	/* ─── Interactions ─────────────────────────────────────────────── */

	function turn( dir ) {
		if ( ! rendition ) {
			return;
		}
		if ( 'prev' === dir ) {
			rendition.prev();
		} else {
			rendition.next();
		}
	}

	function onKey( e ) {
		if ( 'Escape' === e.key ) {
			close();
			return;
		}
		if ( 'ArrowLeft' === e.key || 'PageUp' === e.key ) {
			e.preventDefault();
			turn( 'prev' );
		} else if ( 'ArrowRight' === e.key || 'PageDown' === e.key ) {
			e.preventDefault();
			turn( 'next' );
		}
	}

	var touchX = null;
	var touchY = null;

	function onTouchStart( e ) {
		var t = e.changedTouches && e.changedTouches[ 0 ];
		touchX = t ? t.clientX : null;
		touchY = t ? t.clientY : null;
	}

	function onTouchEnd( e ) {
		if ( null === touchX ) {
			return;
		}
		var t = e.changedTouches && e.changedTouches[ 0 ];
		if ( ! t ) {
			return;
		}
		var dx = t.clientX - touchX;
		var dy = t.clientY - touchY;
		touchX = null;
		// Balayage franc et horizontal seulement : sinon on volerait le scroll
		// vertical du texte.
		if ( Math.abs( dx ) > 50 && Math.abs( dx ) > Math.abs( dy ) ) {
			turn( dx > 0 ? 'prev' : 'next' );
		}
	}

	/* ─── Câblage ──────────────────────────────────────────────────── */

	document.addEventListener( 'click', function ( e ) {
		var link = e.target.closest ? e.target.closest( '.pf-book[data-pf-epub]' ) : null;
		if ( ! link ) {
			return;
		}
		// Ctrl/cmd-clic, clic du milieu, nouvel onglet : on laisse le lien faire
		// son travail (il pointe sur la fiche livre).
		if ( e.metaKey || e.ctrlKey || e.shiftKey || 1 === e.button ) {
			return;
		}
		e.preventDefault();
		open( parseInt( link.getAttribute( 'data-pf-epub' ), 10 ) );
	} );

	if ( closeBtn ) {
		closeBtn.addEventListener( 'click', function () {
			close();
		} );
	}
	if ( prevBtn ) {
		prevBtn.addEventListener( 'click', function () {
			turn( 'prev' );
		} );
	}
	if ( nextBtn ) {
		nextBtn.addEventListener( 'click', function () {
			turn( 'next' );
		} );
	}

	// Clavier hors iframe (focus sur la barre ou les boutons).
	document.addEventListener( 'keydown', function ( e ) {
		if ( overlay.classList.contains( 'is-open' ) ) {
			onKey( e );
		}
	} );

	// Bouton Retour : ferme le lecteur sans réécrire l'historique.
	window.addEventListener( 'popstate', function ( e ) {
		var id = e.state && e.state.pfEpub;
		if ( id ) {
			open( id );
		} else if ( overlay.classList.contains( 'is-open' ) ) {
			close( true );
		}
	} );

	window.addEventListener( 'pagehide', function () {
		flushSave( true );
	} );

	// Arrivée par lien profond /mon-compte/livres-numeriques/<id>/.
	( function deepLink() {
		var m = window.location.pathname.match( /\/(\d+)\/?$/ );
		if ( ! m ) {
			return;
		}
		var id = parseInt( m[ 1 ], 10 );
		if ( document.querySelector( '.pf-book[data-pf-epub="' + id + '"]' ) ) {
			open( id );
		}
	} )();
}() );
