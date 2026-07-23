/**
 * Bascule d'affichage des étagères du compte : « Couvertures | Dos ».
 *
 * Le switch .pf-shelf-switch (à droite du titre) re-rend l'étagère côté serveur
 * dans l'affichage demandé (covers ⇄ spines diffèrent au rendu : épaisseur des
 * dos, packing, signets/prix/badges propres aux couvertures) et remplace le
 * contenu du .pf-shelf-slot voisin. Handler délégué sur document → résiste aux
 * swaps AJAX (l'étagère « Ma liste de lecture » est reconstruite par
 * shelf-bookmarks.js après un toggle de signet).
 *
 * Back-end : recommendations.php (endpoint pf_shelf_display + pf_shelf_display_specs).
 */
( function () {
	'use strict';

	if ( typeof pfShelfDisplay === 'undefined' ) {
		return;
	}

	document.addEventListener( 'click', function ( e ) {
		var btn = e.target.closest ? e.target.closest( '.pf-shelf-switch .pf-switch__btn' ) : null;
		if ( ! btn || btn.classList.contains( 'is-active' ) ) {
			return;
		}

		var sw = btn.closest( '.pf-shelf-switch' );
		if ( ! sw || sw.dataset.busy === '1' ) {
			return;
		}
		var shelf   = sw.getAttribute( 'data-pf-shelf' );
		var display = btn.getAttribute( 'data-display' );
		var slot    = document.querySelector( '.pf-shelf-slot[data-pf-shelf-slot="' + shelf + '"]' );
		if ( ! shelf || ! display || ! slot ) {
			return;
		}

		sw.dataset.busy = '1';
		slot.classList.add( 'is-loading' );

		var fd = new FormData();
		fd.append( 'action', 'pf_shelf_display' );
		fd.append( 'nonce', pfShelfDisplay.nonce );
		fd.append( 'shelf', shelf );
		fd.append( 'display', display );

		fetch( pfShelfDisplay.ajax_url, { method: 'POST', body: fd, credentials: 'same-origin' } )
			.then( function ( r ) { return r.json(); } )
			.then( function ( payload ) {
				if ( ! payload || ! payload.success || typeof payload.data.html !== 'string' ) {
					return;
				}
				slot.innerHTML = payload.data.html;

				// Bascule l'état actif des deux boutons.
				sw.querySelectorAll( '.pf-switch__btn' ).forEach( function ( b ) {
					var on = b.getAttribute( 'data-display' ) === display;
					b.classList.toggle( 'is-active', on );
					b.setAttribute( 'aria-pressed', on ? 'true' : 'false' );
				} );

				// Re-layout + réattache le hover 3D sur les nouveaux nœuds (idempotent).
				if ( window.PassifloreBookshelf && typeof window.PassifloreBookshelf.init === 'function' ) {
					window.PassifloreBookshelf.init();
				}
			} )
			.catch( function ( err ) { console.error( 'pf-shelf-display:', err ); } )
			.finally( function () {
				sw.dataset.busy = '0';
				slot.classList.remove( 'is-loading' );
			} );
	} );
} )();
