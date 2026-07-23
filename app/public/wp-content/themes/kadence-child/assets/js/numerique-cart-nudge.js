/**
 * Panier (blocs) — encart de rappel « ajoutez la version numérique ».
 *
 * Le panier est rendu en blocs (Store API), sans hook PHP de rendu : on injecte
 * l'encart en JS au-dessus du bloc panier. Les offres « en attente » (livres
 * papier présents dont le numérique compagnon n'est pas encore au panier) sont
 * fournies par l'endpoint admin-ajax `pf_numerique_cart_offers`. Un clic sur
 * « Ajouter » appelle `pf_numerique_add_companion` puis recharge la page (le
 * bloc panier se re-rend proprement, l'encart disparaît, le compagnon apparaît).
 *
 * Ré-évaluation à chaque variation du store `wc/store/cart` (même idiome que
 * cart-count-sync.js).
 */
( function ( wp ) {
	'use strict';

	var cfg = window.pfNumerique;
	if ( ! cfg ) {
		return;
	}

	var BOX_ID = 'pf-numerique-nudge';

	function findAnchor() {
		return document.querySelector( '.wp-block-woocommerce-cart' ) ||
			document.querySelector( '.woocommerce-cart-form' ) ||
			document.querySelector( '.woocommerce' );
	}

	function ajax( action, params ) {
		var body = new URLSearchParams();
		body.set( 'action', action );
		body.set( 'nonce', cfg.nonce );
		Object.keys( params || {} ).forEach( function ( k ) {
			body.set( k, params[ k ] );
		} );
		return fetch( cfg.ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
			body: body.toString()
		} ).then( function ( r ) {
			// 403 = nonce périmé : on le remonte en rejet. Seule l'action utilisateur
			// (addCompanion) affiche un toast ; le sondage de fond l'ignore (.catch vide).
			if ( r.status === 403 ) {
				var e = new Error( 'session' );
				e.pfSession = true;
				throw e;
			}
			return r.json();
		} );
	}

	function escapeHtml( s ) {
		var d = document.createElement( 'div' );
		d.textContent = ( s == null ) ? '' : String( s );
		return d.innerHTML;
	}

	// Même infobulle que la fiche livre (composant .pf-numerique-tip / pf-tooltip.js).
	function tipMarkup( tipText, id ) {
		if ( ! tipText ) {
			return '';
		}
		return '<span class="pf-numerique-tip"><span class="pf-numerique-tip__trigger" tabindex="0" role="button" aria-label="Détails de l\'offre numérique" aria-describedby="' + id + '">' +
			'<svg viewBox="0 0 24 24" width="15" height="15" fill="currentColor" aria-hidden="true"><path d="M11 7h2v2h-2V7zm0 4h2v6h-2v-6zm1-9C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm0 18c-4.41 0-8-3.59-8-8s3.59-8 8-8 8 3.59 8 8-3.59 8-8 8z"/></svg>' +
			'</span><span class="pf-numerique-tip__bubble" id="' + id + '" role="tooltip">' + escapeHtml( tipText ) + '</span></span>';
	}

	function addCompanion( physicalId, btn ) {
		btn.disabled = true;
		ajax( 'pf_numerique_add_companion', { physical_id: physicalId } ).then( function ( res ) {
			if ( res && res.success ) {
				window.location.reload();
			} else {
				btn.disabled = false;
			}
		} ).catch( function ( err ) {
			btn.disabled = false;
			if ( err && err.pfSession && window.pfSessionExpired ) {
				window.pfSessionExpired(); // action panier → mode reload (défaut)
			}
		} );
	}

	function render( offers ) {
		var existing = document.getElementById( BOX_ID );

		if ( ! offers || ! offers.length ) {
			if ( existing ) {
				existing.remove();
			}
			return;
		}

		var anchor = findAnchor();
		if ( ! anchor || ! anchor.parentNode ) {
			return;
		}

		var box = document.createElement( 'div' );
		box.id = BOX_ID;
		box.className = 'pf-panel pf-panel--alt pf-numerique-nudge';

		var title = document.createElement( 'p' );
		title.className = 'pf-numerique-nudge__title';
		title.textContent = cfg.heading;
		box.appendChild( title );

		offers.forEach( function ( o ) {
			var row = document.createElement( 'div' );
			row.className = 'pf-numerique-nudge__row';

			var text = document.createElement( 'div' );
			text.className = 'pf-numerique-nudge__text';
			// price_html est généré serveur (wc_price) → sûr ; titre + infobulle échappés.
			var tipId = 'pf-numerique-tip-bubble-' + o.physical_id;
			text.innerHTML = 'Version numérique de <strong><em>' + escapeHtml( o.title ) +
				'</em></strong> : ' + o.price_html + tipMarkup( o.tip, tipId );

			var btn = document.createElement( 'button' );
			btn.type = 'button';
			btn.className = 'pf-btn pf-btn--primary pf-btn--sm pf-numerique-nudge__btn';
			btn.textContent = cfg.addLabel;
			btn.addEventListener( 'click', function () {
				addCompanion( o.physical_id, btn );
			} );

			row.appendChild( text );
			row.appendChild( btn );
			box.appendChild( row );
		} );

		if ( existing ) {
			existing.replaceWith( box );
		} else {
			anchor.parentNode.insertBefore( box, anchor );
		}

		// Câblage des infobulles (après insertion dans le DOM : closest('.site-container') OK).
		if ( window.pfTooltip ) {
			var tips = box.querySelectorAll( '.pf-numerique-tip' );
			for ( var i = 0; i < tips.length; i++ ) {
				window.pfTooltip.wire( tips[ i ] );
			}
		}
	}

	function refresh() {
		ajax( 'pf_numerique_cart_offers', {} ).then( function ( res ) {
			if ( res && res.success && res.data ) {
				render( res.data.offers );
			}
		} ).catch( function () {} );
	}

	refresh();

	// Re-évaluation quand le panier change (ajout/retrait/quantité via les blocs).
	if ( wp && wp.data ) {
		var lastCount = null;
		wp.data.subscribe( function () {
			var store = wp.data.select( 'wc/store/cart' );
			if ( ! store ) {
				return;
			}
			var data = store.getCartData();
			if ( ! data || typeof data.itemsCount !== 'number' ) {
				return;
			}
			if ( lastCount === null ) {
				lastCount = data.itemsCount;
				return;
			}
			if ( data.itemsCount === lastCount ) {
				return;
			}
			lastCount = data.itemsCount;
			refresh();
		} );
	}
} )( window.wp );
