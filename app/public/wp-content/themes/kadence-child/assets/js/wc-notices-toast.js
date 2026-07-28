/**
 * Notices WooCommerce → toasts du site (`window.pfToast`).
 *
 * WooCommerce imprime ses messages (succès / erreur / info) dans un
 * `.woocommerce-notices-wrapper` posé en tête de la zone de contenu : un bandeau
 * pleine largeur, loin du geste qui l'a déclenché, qui pousse la page vers le bas
 * et qu'un visiteur ayant scrollé ne voit pas. On les redirige vers le composant
 * toast du site, déjà utilisé par la liste de lecture, la newsletter et les avis.
 *
 * **Périmètre : le contenu du wrapper, rien d'autre.** Les `.woocommerce-info`
 * rendus *hors* wrapper restent en place, car ancrés à un endroit précis de la
 * page et non à une action : « Vous avez déjà un compte ? » du tunnel, bloc
 * coupon, panier vide. Les notices React des blocs Panier/Commande
 * (`.wc-block-components-notice-banner`, alimentées par la Store API) sont un
 * autre système, également intact.
 *
 * **Amélioration progressive** : le wrapper n'est masqué que par la classe
 * `html.pf-notices-js`, posée avant le premier paint par `inc/wc-notices-toast.php`.
 * Sans JavaScript elle n'existe pas et les notices s'affichent normalement à leur
 * place d'origine.
 *
 * **1 notice = 1 toast** : une notice d'erreur multiple est un `<ul>` de plusieurs
 * `<li>`, chacun étant une notice distincte côté WooCommerce (un appel
 * `wc_add_notice()`), donc un toast distinct — fermable indépendamment.
 */
( function () {
	'use strict';

	if ( ! window.pfToast ) {
		return;
	}

	// Icônes Material Symbols (même famille et même viewBox que le reste du site) :
	// check_circle / error / warning / info. Teintées par la variante
	// `pf-toast--<statut>`. Exposées en `window.pfNoticeIcons` : le contrôleur des
	// notices React (`wc-block-notices-toast.js`, panier et commande) les réutilise
	// telles quelles plutôt que d'en recopier les tracés.
	var OPEN  = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 -960 960 960" fill="currentColor" aria-hidden="true"><path d="';
	var CLOSE = '"/></svg>';
	var RING  = 'M480-80q-83 0-156-31.5T197-197q-54-54-85.5-127T80-480q0-83 31.5-156T197-763q54-54 127-85.5T480-880q83 0 156 31.5T763-763q54 54 85.5 127T880-480q0 83-31.5 156T763-197q-54 54-127 85.5T480-80Zm0-80q134 0 227-93t93-227q0-134-93-227t-227-93q-134 0-227 93t-93 227q0 134 93 227t227 93Z';
	var ICON  = {
		success: OPEN + 'm424-296 282-282-56-56-226 226-114-114-56 56 170 170Z' + RING + CLOSE,
		error:   OPEN + 'M480-280q17 0 28.5-11.5T520-320q0-17-11.5-28.5T480-360q-17 0-28.5 11.5T440-320q0 17 11.5 28.5T480-280Zm-40-160h80v-240h-80v240Z' + RING + CLOSE,
		warning: OPEN + 'M109-120q-11 0-20-5.5T75-140q-5-9-5.5-19.5T75-180l371-640q6-10 15.5-15t19.5-5q10 0 19.5 5t15.5 15l371 640q6 10 5.5 20.5T887-140q-5 9-14 14.5t-20 5.5H109Zm69-80h604L480-720 178-200Zm302-40q17 0 28.5-11.5T520-280q0-17-11.5-28.5T480-320q-17 0-28.5 11.5T440-280q0 17 11.5 28.5T480-240Zm0-120q17 0 28.5-11.5T520-400v-120q0-17-11.5-28.5T480-560q-17 0-28.5 11.5T440-520v120q0 17 11.5 28.5T480-360Z' + CLOSE,
		info:    OPEN + 'M440-280h80v-240h-80v240Zm40-320q17 0 28.5-11.5T520-640q0-17-11.5-28.5T480-680q-17 0-28.5 11.5T440-640q0 17 11.5 28.5T480-600Z' + RING + CLOSE
	};

	window.pfNoticeIcons = ICON;

	/**
	 * Type de notice d'après la classe posée par les templates WooCommerce
	 * (`notices/error.php`, `success.php`, `notice.php`). Tout autre contenu du
	 * wrapper — un type de notice ajouté par un plugin via le filtre
	 * `woocommerce_notice_types` — est traité comme une info plutôt qu'ignoré :
	 * le wrapper étant masqué, un contenu non reconnu serait perdu.
	 */
	function typeOf( el ) {
		if ( el.classList.contains( 'woocommerce-error' ) ) {
			return 'error';
		}
		if ( el.classList.contains( 'woocommerce-message' ) ) {
			return 'success';
		}
		return 'info';
	}

	/**
	 * WooCommerce place l'appel à l'action d'une notice en tête de message, sous
	 * forme de `<a class="button">` (« Voir le panier », « Passer la commande »…).
	 * On le sort du texte pour en faire un bouton d'action du toast.
	 */
	function extractAction( node ) {
		var link = node.querySelector( 'a.button[href]' );
		if ( ! link ) {
			return null;
		}
		link.parentNode.removeChild( link );

		// Un CTA qui pointe vers la page courante n'est pas une action : « Voir le
		// panier » sur la page panier (ajout depuis l'encart « version numérique »,
		// ou `?add-to-cart=` sur cette URL) rechargerait simplement la page. Le
		// lien est retiré du texte dans tous les cas — un bouton au fil du message
		// n'a pas sa place dans un toast.
		if ( link.pathname === window.location.pathname ) {
			return null;
		}

		var label = ( link.textContent || '' ).trim();
		return label ? { label: label, href: link.getAttribute( 'href' ) } : null;
	}

	function emit( node, type ) {
		var action = extractAction( node );
		var html   = node.innerHTML.trim();
		if ( ! html && ! action ) {
			return;
		}
		window.pfToast.show( {
			// Markup de confiance : il vient du serveur, déjà filtré par
			// wc_kses_notice() et déjà parsé par le navigateur — le réinjecter
			// n'ouvre aucune surface nouvelle.
			html:    html,
			icon:    ICON[ type ],
			status:  type,
			// Une erreur est bloquante (identifiants refusés, champ manquant) :
			// fermeture manuelle uniquement. Les autres gardent la durée par
			// défaut du composant (minuteur en pause au survol/focus).
			duration: 'error' === type ? 0 : undefined,
			actions:  action ? [ action ] : null
		} );
	}

	function run() {
		var wrappers = document.querySelectorAll( '.woocommerce-notices-wrapper' );
		Array.prototype.forEach.call( wrappers, function ( wrapper ) {
			Array.prototype.slice.call( wrapper.children ).forEach( function ( child ) {
				var type  = typeOf( child );
				// Les erreurs arrivent groupées dans un seul <ul> : un <li> = une notice.
				var nodes = ( 'error' === type ) ? child.querySelectorAll( ':scope > li' ) : [ child ];
				Array.prototype.forEach.call( nodes, function ( node ) {
					emit( node, type );
				} );
				wrapper.removeChild( child );
			} );
		} );
	}

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', run );
	} else {
		run();
	}
} )();
