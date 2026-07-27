/**
 * Contrôleur unique de la « liste de lecture », pour ses DEUX affordances :
 *  - les signets posés sur les couvertures d'étagère (`.pf-book-bookmark`, rendus
 *    par inc/class-bookshelf.php) — pages du compte ;
 *  - le bouton du hero de la fiche livre (`.bs-hero__readlist`, rendu par
 *    Passiflore_Reading_List::render_button).
 * Les deux vues d'un même livre sont tenues en phase par setIcon() : même état,
 * mêmes toasts, une seule file d'écritures.
 *
 * Ce script (handlers délégués sur `document`, donc résilients aux swaps AJAX) :
 *  - bascule l'état instantanément sur TOUTES les instances d'un même livre
 *    (il peut figurer sur deux étagères, ou sur sa propre fiche) ;
 *  - AJOUT = commit immédiat (toast de confirmation) ;
 *  - RETRAIT = différé à la fin du toast (bouton « Annuler » l'annule) ;
 *  - INVITÉ = aucune écriture, une invitation à se connecter (toast sans fermeture
 *    automatique, une seule à la fois) ;
 *  - sur la page « Mon compte → Liste de lecture », reconstruit l'étagère « Ma liste
 *    de lecture » à partir du HTML renvoyé par le serveur (pf_reading_list_toggle + with_shelf) ;
 *  - infobulle flottante en espace écran, ancrée au signet, après 1 s de survol.
 *
 * Back-end réutilisé : Passiflore_Reading_List (endpoint pf_reading_list_toggle).
 */
( function () {
	'use strict';

	if ( typeof pfBookmarks === 'undefined' || ! window.pfToast ) {
		return;
	}

	var S     = pfBookmarks.strings || {};
	var ICONS = pfBookmarks.icons || {};
	var state = {};          // { [id]: { inFlight, pendingRemove } }

	// File d'attente SÉRIE des toggles serveur : une requête à la fois. Le back
	// (pf_reading_list_toggle) fait un read-modify-write non atomique sur la
	// user-meta ; deux requêtes concurrentes s'écraseraient (lost update). En les
	// sérialisant, chaque requête lit l'état laissé par la précédente.
	var chain   = Promise.resolve();
	var pending = 0;         // nb de commits en file → reconstruire l'étagère au dernier
	function enqueue( task ) {
		chain = chain.then( task, task ); // avance même si une tâche a échoué
		return chain;
	}

	function st( id ) {
		if ( ! state[ id ] ) {
			state[ id ] = { inFlight: false, pendingRemove: null };
		}
		return state[ id ];
	}

	/* ─── Utils ──────────────────────────────────────────────────── */

	function escapeHtml( s ) {
		return String( s )
			.replace( /&/g, '&amp;' ).replace( /</g, '&lt;' ).replace( />/g, '&gt;' )
			.replace( /"/g, '&quot;' ).replace( /'/g, '&#39;' );
	}

	function titleHTML( s ) {
		return '<strong><em>' + escapeHtml( s ) + '</em></strong>';
	}

	function fmt( tpl, s ) {
		return String( tpl || '' ).replace( '%s', s );
	}

	function setIcon( id, inList ) {
		var nodes = document.querySelectorAll( '.pf-book-bookmark[data-product-id="' + id + '"]' );
		for ( var i = 0; i < nodes.length; i++ ) {
			var n = nodes[ i ];
			n.classList.toggle( 'is-in-list', inList );
			n.setAttribute( 'aria-pressed', inList ? 'true' : 'false' );
			n.setAttribute( 'data-in-list', inList ? '1' : '0' );
			n.setAttribute( 'aria-label', inList ? S.tip_remove : S.tip_add );
		}
		// Autre vue du même état : le bouton du hero de la fiche livre. Libellé,
		// infobulle native et tracé d'icône sont portés en data-* par le PHP.
		var btns = document.querySelectorAll( '.bs-hero__readlist[data-product-id="' + id + '"]' );
		for ( var j = 0; j < btns.length; j++ ) {
			var b = btns[ j ];
			b.classList.toggle( 'is-in-list', inList );
			b.setAttribute( 'aria-pressed', inList ? 'true' : 'false' );
			b.setAttribute( 'data-in-list', inList ? '1' : '0' );
			b.title = inList ? b.dataset.titleIn : b.dataset.titleAdd;
			var label = b.querySelector( '.bs-hero__readlist-label' );
			if ( label ) {
				label.textContent = inList ? b.dataset.labelIn : b.dataset.labelAdd;
			}
			var path = b.querySelector( '.bs-hero__readlist-icon path' );
			if ( path ) {
				path.setAttribute( 'd', inList ? b.dataset.iconIn : b.dataset.iconAdd );
			}
		}
	}

	function toggleServer( id, withShelf ) {
		var fd = new FormData();
		fd.append( 'action', pfBookmarks.toggle_action );
		fd.append( 'nonce', pfBookmarks.nonce );
		fd.append( 'product_id', id );
		if ( withShelf ) {
			fd.append( 'with_shelf', '1' );
		}
		return fetch( pfBookmarks.ajax_url, { method: 'POST', body: fd, credentials: 'same-origin' } )
			.then( function ( r ) {
				// 403 = nonce périmé : on le marque pour que commit() lève le toast.
				if ( r.status === 403 ) {
					var e = new Error( 'session' );
					e.pfSession = true;
					throw e;
				}
				return r.json();
			} )
			.then( function ( payload ) {
				if ( ! payload || ! payload.success ) {
					throw new Error( 'toggle failed' );
				}
				return payload.data || {};
			} );
	}

	/* ─── Reconstruction « Ma liste de lecture » (page Liste de lecture) ─ */

	function applyShelf( html ) {
		var container = document.querySelector( '.pf-liste-lecture' );
		if ( ! container ) {
			return;
		}
		var existing = container.querySelector( '.pf-account-reco--readlist' );
		var fresh = null;
		if ( html && html.trim() ) {
			var tpl = document.createElement( 'template' );
			tpl.innerHTML = html.trim();
			fresh = tpl.content.querySelector( '.pf-account-reco--readlist' );
		}

		if ( ! fresh ) {
			// REMOVE : liste devenue vide.
			if ( existing ) {
				existing.classList.add( 'is-leaving' );
				var ex = existing;
				setTimeout( function () { if ( ex.parentNode ) { ex.parentNode.removeChild( ex ); } }, 300 );
			}
			return;
		}

		var imported = document.importNode( fresh, true );
		if ( existing ) {
			existing.parentNode.replaceChild( imported, existing ); // REPLACE
		} else {
			// INSERT : en tête, au-dessus de l'étagère « Catalogue ».
			var anchor = container.querySelector( '.pf-account-catalogue' );
			if ( anchor && anchor.parentNode ) {
				anchor.parentNode.insertBefore( imported, anchor );
			} else {
				container.appendChild( imported );
			}
		}

		// Recâble le hover 3D sur les nouveaux nœuds (idempotent → les tuiles
		// Suggestions intactes ne sont pas re-liées, cf. bookshelf.js).
		if ( window.PassifloreBookshelf && typeof window.PassifloreBookshelf.init === 'function' ) {
			window.PassifloreBookshelf.init();
		}
	}

	// Envoie le toggle au serveur (avec reconstruction d'étagère sur le dashboard).
	// SÉRIALISÉ via `enqueue` → jamais deux écritures concurrentes sur la meta.
	// `intended` = état voulu après commit → sert au revert en cas d'échec réseau.
	function commit( id, intended ) {
		var s = st( id );
		s.inFlight = true;
		pending++;
		enqueue( function () {
			var rebuild = !! pfBookmarks.rebuild_readlist;
			return toggleServer( id, rebuild ).then( function ( data ) {
				// Ne pas réconcilier l'icône si un retrait différé est déjà en
				// attente pour ce livre (add→remove rapide) : sa demande prime,
				// sinon la réponse tardive de l'ajout referait clignoter l'icône.
				if ( ! st( id ).pendingRemove ) {
					setIcon( id, !! data.in_list );
				}
				pending--;
				// Reconstruire l'étagère UNE seule fois, au dernier commit de la
				// rafale (requêtes sérialisées → le dernier reflète l'état cumulé).
				if ( rebuild && pending === 0 && typeof data.shelf_html === 'string' ) {
					applyShelf( data.shelf_html );
				}
			}, function ( err ) {
				if ( ! st( id ).pendingRemove ) {
					setIcon( id, ! intended ); // revert au dernier état connu
				}
				pending--;
				// Nonce périmé : toast de session (mode confirm : pas d'auto-reload,
				// on ne yanke pas une page d'étagère en cours de navigation).
				if ( err && err.pfSession && window.pfSessionExpired ) {
					window.pfSessionExpired( { mode: 'confirm' } );
				}
			} ).then( function () { s.inFlight = false; } );
		} );
	}

	/* ─── Activation d'un signet (clic / clavier) ────────────────── */

	function activate( bm ) {
		var id    = bm.getAttribute( 'data-product-id' );
		var title = bm.getAttribute( 'data-title' ) || '';
		var s     = st( id );
		hideTip();

		// Re-clic pendant un retrait en attente → ré-ajout (annule le différé).
		if ( s.pendingRemove ) {
			s.pendingRemove.cancelled = true;
			s.pendingRemove.handle.dismiss( 'programmatic' );
			s.pendingRemove = null;
			setIcon( id, true );
			return; // aucun réseau : le serveur l'a encore
		}

		var displayedIn = bm.getAttribute( 'data-in-list' ) === '1';

		if ( ! displayedIn ) {
			// AJOUT — commit immédiat.
			if ( s.inFlight ) { return; }
			setIcon( id, true );
			window.pfToast.show( {
				icon: ICONS.added,
				html: fmt( S.added, titleHTML( title ) ),
				duration: 5000,
				closeLabel: S.close
			} );
			commit( id, true );
		} else {
			// RETRAIT — différé à la fin du toast.
			setIcon( id, false );
			var rec = { handle: null, cancelled: false };
			s.pendingRemove = rec;
			rec.handle = window.pfToast.show( {
				icon: ICONS.removed,
				html: fmt( S.removed, titleHTML( title ) ),
				duration: 5000,
				closeLabel: S.close,
				actions: [ { label: S.undo, onClick: function () {
					rec.cancelled = true;
					s.pendingRemove = null;
					setIcon( id, true );
				} } ],
				onClose: function ( reason ) {
					if ( rec.cancelled ) { return; }
					// × et timeout confirment ; 'programmatic' = re-clic (annulé plus haut).
					if ( reason === 'timeout' || reason === 'close' ) {
						s.pendingRemove = null;
						commit( id, false );
					}
				}
			} );
		}
	}

	/* ─── Invité : invitation à se connecter ─────────────────────── */

	// Une seule invitation à la fois : re-cliquer le bouton pendant qu'elle est
	// affichée ne réempile pas de toast. 7 s plutôt que les 5 s des confirmations
	// d'ajout/retrait — il y a ici plus à lire ET un bouton à atteindre.
	var loginToast = null;
	function inviteToLogin( title ) {
		if ( loginToast ) { return; }
		loginToast = window.pfToast.show( {
			html: fmt( S.login, titleHTML( title ) ),
			duration: 7000,
			closeLabel: S.close,
			actions: [ { label: S.login_cta, href: pfBookmarks.login_url } ],
			onClose: function () { loginToast = null; }
		} );
	}

	/* ─── Bouton « Liste de lecture » du hero (fiche livre) ──────── */

	document.addEventListener( 'click', function ( e ) {
		var btn = e.target.closest ? e.target.closest( '.bs-hero__readlist' ) : null;
		if ( ! btn ) { return; }
		// Invité : le bouton est un <a> vers /connexion (repli sans JS) → on retient
		// la navigation au profit du toast.
		e.preventDefault();
		if ( btn.classList.contains( 'bs-hero__readlist--guest' ) ) {
			inviteToLogin( btn.getAttribute( 'data-title' ) || '' );
			return;
		}
		activate( btn );
	} );

	// Signet cliqué sous le pointeur (chemin normal). En mode « dos », le signet
	// est posé sur une couverture en 3D (transform-style: preserve-3d) : le
	// hit-testing du navigateur y est incohérent avec le rendu (le clic « traverse »
	// vers le bloc pages / le lien du livre → navigation). On rattrape ce cas par
	// GÉOMÉTRIE : si le point cliqué tombe dans le rectangle visible d'un signet du
	// livre visé, on l'active (le rendu, lui, est à la bonne place → clientX/Y y
	// correspond). Renvoie null si le clic ne vise aucun signet.
	function bookmarkAt( e ) {
		var direct = e.target.closest ? e.target.closest( '.pf-book-bookmark' ) : null;
		if ( direct ) { return direct; }
		var book = e.target.closest ? e.target.closest( '.pf-book' ) : null;
		if ( ! book ) { return null; }
		var marks = book.querySelectorAll( '.pf-book-bookmark' );
		for ( var i = 0; i < marks.length; i++ ) {
			var r = marks[ i ].getBoundingClientRect();
			if ( r.width && r.height
				&& e.clientX >= r.left && e.clientX <= r.right
				&& e.clientY >= r.top && e.clientY <= r.bottom ) {
				return marks[ i ];
			}
		}
		return null;
	}

	document.addEventListener( 'click', function ( e ) {
		var bm = bookmarkAt( e );
		if ( ! bm ) { return; }
		e.preventDefault();
		e.stopPropagation();
		activate( bm );
	} );

	// Aperçu des couleurs au survol (état inverse), comme le :hover du mode
	// couvertures. En mode dos, le signet est sur une couverture 3D (preserve-3d)
	// au hit-testing incohérent → le :hover natif ne s'y déclenche pas. On simule
	// donc le survol par GÉOMÉTRIE (même principe que le clic) en posant .is-hover.
	// Actif seulement quand une couverture « dos » est révélée (sinon inerte).
	var hoverPreview = null;
	function setHoverPreview( bm ) {
		if ( hoverPreview === bm ) { return; }
		if ( hoverPreview ) { hoverPreview.classList.remove( 'is-hover' ); }
		hoverPreview = bm;
		if ( bm ) { bm.classList.add( 'is-hover' ); }
	}
	document.addEventListener( 'mousemove', function ( e ) {
		var books = document.querySelectorAll( '.pf-bookshelf--spines .pf-book--cover-revealed' );
		if ( ! books.length ) { setHoverPreview( null ); return; }
		var found = null;
		for ( var i = 0; i < books.length && ! found; i++ ) {
			var marks = books[ i ].querySelectorAll( '.pf-book-bookmark' );
			for ( var j = 0; j < marks.length; j++ ) {
				var r = marks[ j ].getBoundingClientRect();
				if ( r.width && r.height
					&& e.clientX >= r.left && e.clientX <= r.right
					&& e.clientY >= r.top && e.clientY <= r.bottom ) {
					found = marks[ j ];
					break;
				}
			}
		}
		setHoverPreview( found );
	} );

	document.addEventListener( 'keydown', function ( e ) {
		if ( e.key !== 'Enter' && e.key !== ' ' ) { return; }
		var bm = e.target.closest ? e.target.closest( '.pf-book-bookmark' ) : null;
		if ( ! bm ) { return; }
		e.preventDefault();
		activate( bm );
	} );

	/* ─── Infobulle flottante (espace écran, délai 1 s) ──────────── */

	var tipEl   = null;
	var tipTimer = null;

	function ensureTip() {
		if ( tipEl && document.body.contains( tipEl ) ) {
			return tipEl;
		}
		tipEl = document.createElement( 'span' );
		tipEl.className = 'pf-book-bookmark-tip';
		tipEl.setAttribute( 'role', 'tooltip' );
		document.body.appendChild( tipEl );
		return tipEl;
	}

	function showTip( bm ) {
		var tip = ensureTip();
		var inList = bm.getAttribute( 'data-in-list' ) === '1';
		tip.textContent = inList ? S.tip_remove : S.tip_add;

		var r  = bm.getBoundingClientRect();
		var tr = tip.getBoundingClientRect();
		var pad = 8;
		var left = r.left + r.width / 2 - tr.width / 2;
		var top  = r.top - tr.height - 8;
		if ( top < pad ) { top = r.bottom + 8; } // bascule sous le signet
		if ( left < pad ) { left = pad; }
		if ( left + tr.width > window.innerWidth - pad ) {
			left = window.innerWidth - pad - tr.width;
		}
		tip.style.left = Math.round( left ) + 'px';
		tip.style.top  = Math.round( top ) + 'px';
		tip.classList.add( 'is-visible' );
	}

	function hideTip() {
		if ( tipEl ) { tipEl.classList.remove( 'is-visible' ); }
	}

	function cancelTip() {
		if ( tipTimer !== null ) { clearTimeout( tipTimer ); tipTimer = null; }
		hideTip();
	}

	function scheduleTip( bm ) {
		if ( tipTimer !== null ) { clearTimeout( tipTimer ); }
		tipTimer = setTimeout( function () { showTip( bm ); }, 1000 );
	}

	document.addEventListener( 'pointerover', function ( e ) {
		var bm = e.target.closest ? e.target.closest( '.pf-book-bookmark' ) : null;
		if ( ! bm ) { return; }
		if ( e.relatedTarget && bm.contains( e.relatedTarget ) ) { return; } // déplacement interne
		scheduleTip( bm );
	} );

	document.addEventListener( 'pointerout', function ( e ) {
		var bm = e.target.closest ? e.target.closest( '.pf-book-bookmark' ) : null;
		if ( ! bm ) { return; }
		if ( e.relatedTarget && bm.contains( e.relatedTarget ) ) { return; } // toujours dedans
		cancelTip();
	} );

	// Clavier : affichage immédiat (pas de délai a11y).
	document.addEventListener( 'focusin', function ( e ) {
		var bm = e.target.closest ? e.target.closest( '.pf-book-bookmark' ) : null;
		if ( bm ) { showTip( bm ); }
	} );
	document.addEventListener( 'focusout', function ( e ) {
		var bm = e.target.closest ? e.target.closest( '.pf-book-bookmark' ) : null;
		if ( bm ) { cancelTip(); }
	} );

	// L'infobulle est ancrée à un rect écran → la masquer dès qu'il bouge.
	window.addEventListener( 'scroll', cancelTip, true );
	window.addEventListener( 'resize', cancelTip );

	/* ─── Filet : flush des retraits en attente au départ de la page ─ */

	window.addEventListener( 'pagehide', function () {
		for ( var id in state ) {
			if ( ! state.hasOwnProperty( id ) ) { continue; }
			var pr = state[ id ].pendingRemove;
			if ( pr && ! pr.cancelled ) {
				var fd = new FormData();
				fd.append( 'action', pfBookmarks.toggle_action );
				fd.append( 'nonce', pfBookmarks.nonce );
				fd.append( 'product_id', id );
				try { navigator.sendBeacon( pfBookmarks.ajax_url, fd ); } catch ( e ) {}
			}
		}
	} );
} )();
