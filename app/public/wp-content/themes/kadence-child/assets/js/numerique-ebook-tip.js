/**
 * Panier (blocs) — tooltip « téléchargement ePub » juste après le prix des
 * lignes au format numérique.
 *
 * Le panier étant rendu en React (Store API), impossible d'y injecter du HTML
 * via un hook PHP comme sur la fiche livre : on récupère les IDs des lignes
 * numérique via l'endpoint AJAX pf_numerique_ebook_ids (même nonce que
 * l'encart de rappel, cf. pfNumerique localisé par numerique-cart-nudge.js),
 * puis on associe chaque ID à sa ligne DOM par position (wp.data et le rendu
 * du panier partagent le même ordre). Réinjection idempotente à chaque
 * changement du store (React peut re-rendre une ligne sans notre nœud).
 */
( function ( wp ) {
	'use strict';

	var cfg = window.pfNumerique;
	if ( ! cfg || ! wp || ! wp.data ) {
		return;
	}

	var numeriqueIds = [];

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
		} ).then( function ( r ) { return r.json(); } );
	}

	// Même infobulle que la fiche livre / l'encart panier (composant .pf-tooltip / pf-tooltip.js).
	function tipMarkup( id ) {
		return '<span class="pf-tooltip"><span class="pf-tooltip__trigger" tabindex="0" role="button" aria-label="Précision sur le téléchargement" aria-describedby="' + id + '">' +
			'<svg viewBox="0 0 24 24" width="15" height="15" fill="currentColor" aria-hidden="true"><path d="M11 7h2v2h-2V7zm0 4h2v6h-2v-6zm1-9C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm0 18c-4.41 0-8-3.59-8-8s3.59-8 8-8 8 3.59 8 8-3.59 8-8 8z"/></svg>' +
			'</span><span class="pf-tooltip__bubble" id="' + id + '" role="tooltip">Vous pourrez le <strong>télécharger en ePub</strong> (sans DRM) une fois la commande effectuée. Si vous passez <strong>commande en étant connecté(e)</strong>, vous pourrez directement le <strong>lire depuis votre compte</strong>.</span></span>';
	}

	function apply() {
		if ( ! numeriqueIds.length ) {
			return;
		}
		var store = wp.data.select( 'wc/store/cart' );
		if ( ! store ) {
			return;
		}
		var items = ( store.getCartData() || {} ).items || [];
		var rows = document.querySelectorAll( '.wc-block-cart-items__row' );
		if ( ! rows.length || items.length !== rows.length ) {
			return;
		}

		items.forEach( function ( item, i ) {
			if ( numeriqueIds.indexOf( item.id ) === -1 ) {
				return;
			}
			var priceCell = rows[ i ].querySelector( '.wc-block-cart-item__prices' );
			if ( ! priceCell || priceCell.querySelector( '.pf-tooltip' ) ) {
				return;
			}
			priceCell.insertAdjacentHTML( 'beforeend', tipMarkup( 'pf-numerique-tip-bubble-ebook-' + item.id ) );
			if ( window.pfTooltip ) {
				window.pfTooltip.wire( priceCell.querySelector( '.pf-tooltip' ) );
			}
		} );
	}

	function refresh() {
		ajax( 'pf_numerique_ebook_ids', {} ).then( function ( res ) {
			if ( res && res.success && res.data ) {
				numeriqueIds = res.data.ids || [];
				apply();
			}
		} ).catch( function () {} );
	}

	refresh();

	// Ré-application à chaque changement du store (ajout/retrait/quantité) : le
	// re-rendu React peut avoir remplacé une ligne sans notre nœud. apply()
	// ressort tôt si rien à faire, donc coût négligeable.
	wp.data.subscribe( function () {
		if ( wp.data.select( 'wc/store/cart' ) ) {
			apply();
		}
	} );
} )( window.wp );
