/**
 * Toasts d'ajout et de retrait du panier — site-wide.
 *
 * Le retour ne vit plus sur la fiche livre mais **au niveau du panier** : le
 * mini-panier du header est présent sur toutes les pages, et un livre retiré
 * depuis son bouton × mérite la même confirmation qu'un livre ajouté. Un seul
 * point d'écoute (`added_to_cart` / `removed_from_cart` sur `document.body`)
 * couvre donc la fiche livre, le mini-panier, et tout futur bouton d'ajout AJAX.
 *
 * jQuery est indispensable : ce sont des événements jQuery custom du cœur
 * WooCommerce (`$( document.body ).trigger( … )` dans son `add-to-cart.js`),
 * invisibles pour un `addEventListener` natif.
 *
 * **Ne couvre pas les pages Panier / Commander en blocs** : elles passent par la
 * Store API, sans ces événements — et un toast y ferait doublon, la ligne
 * disparaissant sous les yeux du client.
 *
 * Amélioration progressive : sans JS, les boutons sont de vrais liens qui
 * rechargent la page ; WooCommerce y met alors sa notice, elle-même déportée en
 * toast (`inc/wc-notices-toast.php`).
 */
( function ( $ ) {
	'use strict';

	if ( ! $ || ! window.pfToast || typeof PassifloreCartToast === 'undefined' ) {
		return;
	}

	var S = PassifloreCartToast;

	// Icônes de tête (Material Symbols, même famille que celle du bouton d'achat),
	// colorées par `.pf-toast__icon`. L'ajout garde le caddie garni du bouton
	// d'achat ; le retrait prend le cabas, barré d'une croix tracée en `stroke`
	// (80 unités, centrée dans le cabas) plutôt qu'en contour rempli — même idiome
	// que le toast « retiré de la liste de lecture » (Passiflore_Reading_List::toast_icons()).
	var CART  = 'm742-160 4.5-35q4.5-35 10.5-85 2-11 2.5-21t2.5-19l4.5-35q4.5-35 10.5-85 6-51 10.5-85.5L792-560l10-80v1l-60 479ZM240-80q-33 0-56.5-23.5T160-160h583l59-479H692l-11 85q-2 17-15 26.5t-30 7.5q-17-2-26.5-14.5T602-564l9-75H452l-11 84q-2 17-15 27t-30 8q-17-2-27-15t-8-30l9-74H220q4-34 26-57.5t54-23.5h80q8-75 51.5-117.5T550-880q64 0 106.5 47.5T698-720h102q36 1 60 28t19 63l-60 480q-4 30-26.5 49.5T740-80H240Zm220-640h159q1-33-22.5-56.5T540-800q-35 0-55.5 21.5T460-720ZM230-240H90q-17 0-28.5-11.5T50-280q0-17 11.5-28.5T90-320h140q17 0 28.5 11.5T270-280q0 17-11.5 28.5T230-240Zm120-160H170q-17 0-28.5-11.5T130-440q0-17 11.5-28.5T170-480h180q17 0 28.5 11.5T390-440q0 17-11.5 28.5T350-400Z';
	var BAG   = 'M240-80q-33 0-56.5-23.5T160-160v-480q0-33 23.5-56.5T240-720h80q0-66 47-113t113-47q66 0 113 47t47 113h80q33 0 56.5 23.5T800-640v480q0 33-23.5 56.5T720-80H240Zm0-80h480v-480h-80v80q0 17-11.5 28.5T600-520q-17 0-28.5-11.5T560-560v-80H400v80q0 17-11.5 28.5T360-520q-17 0-28.5-11.5T320-560v-80h-80v480Zm160-560h160q0-33-23.5-56.5T480-800q-33 0-56.5 23.5T400-720ZM240-160v-480 480Z';
	// Croix centrée dans le VIDE du cabas, pas dans sa boîte : les deux ergots de
	// l'anse occupent le haut de la panse (jusqu'à −520). Centrée géométriquement
	// (−400) elle les frôlait et laissait tout l'espace en bas ; à −340, il reste
	// 100 unités de part et d'autre.
	var CROSS = '<path d="M400-420L560-260M560-420L400-260" fill="none" stroke="currentColor" stroke-width="80" stroke-linecap="round"/>';
	var OPEN  = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 -960 960 960" fill="currentColor" aria-hidden="true"><path d="';

	var ICON_ADD    = OPEN + CART + '"/></svg>';
	var ICON_REMOVE = OPEN + BAG + '"/>' + CROSS + '</svg>';

	function escapeHtml( s ) {
		return String( s )
			.replace( /&/g, '&amp;' ).replace( /</g, '&lt;' ).replace( />/g, '&gt;' )
			.replace( /"/g, '&quot;' ).replace( /'/g, '&#39;' );
	}

	/** Titre d'ouvrage : semibold + italique, comme les toasts de la liste de lecture. */
	function titleHtml( title ) {
		return '<strong><em>' + escapeHtml( title ) + '</em></strong>';
	}

	function toast( html, icon, actions ) {
		window.pfToast.show( {
			icon:       icon,
			html:       html,
			duration:   5000,
			closeLabel: S.close,
			actions:    actions || null
		} );

		// WooCommerce écrit le même message dans sa propre région live
		// (`AddToCartHandler.alertCartUpdated`, alimentée par `data-success_message`).
		// Le toast l'annonce déjà : on vide la sienne pour ne pas le faire lire deux
		// fois. Reporté d'un tour de boucle plutôt que de dépendre de `wc-add-to-cart`
		// (qui n'est pas chargé sur toutes les pages) : ainsi l'ordre de liaison des
		// gestionnaires n'a aucune importance, on passe après tous les autres.
		setTimeout( function () {
			$( '.widget_shopping_cart_live_region' ).text( '' );
		}, 0 );
	}

	/**
	 * Repli quand le titre n'est pas connu : la phrase toute faite que WooCommerce
	 * pose sur le bouton (traduite, exacte), en texte simple.
	 */
	function fallbackOf( $button ) {
		return ( $button && $button.data ) ? ( $button.data( 'success_message' ) || '' ) : '';
	}

	/**
	 * Action « Voir le panier » — sauf sur la page panier, où elle ne ferait que
	 * recharger (même règle que pour les CTA des notices, cf. wc-notices-toast.js).
	 */
	function cartAction() {
		var a = document.createElement( 'a' );
		a.href = S.cart_url;

		return a.pathname === window.location.pathname
			? null
			: [ { label: S.cart_cta, href: S.cart_url } ];
	}

	function toastAdded( title, fallback, withNumerique ) {
		if ( ! title && ! fallback ) {
			return;
		}
		var tpl = withNumerique ? S.added_num : S.added;

		toast(
			title ? String( tpl ).replace( '%s', titleHtml( title ) ) : escapeHtml( fallback ),
			ICON_ADD,
			cartAction()
		);
	}

	/* ─── Ajout ─────────────────────────────────────────────────────── */

	/**
	 * Fait pulser l'icône du panier (desktop + mobile — celle du header masqué à
	 * cette largeur n'anime rien, `playOnce` la nettoie aussitôt).
	 *
	 * ⚠️ `animationend` ne se déclenche PAS si aucune animation ne tourne : élément
	 * masqué ou `prefers-reduced-motion` (qui pose `animation: none`). Sans le
	 * garde-fou `getAnimations()`, la classe resterait posée indéfiniment — inerte,
	 * mais trompeuse pour qui inspecterait le DOM plus tard.
	 */
	function playOnce( el, cls ) {
		el.classList.remove( cls );
		void el.offsetWidth; // reflow → redémarre l'animation si elle tournait encore
		el.classList.add( cls );

		if ( ! el.getAnimations().length ) {
			el.classList.remove( cls );
			return;
		}
		el.addEventListener( 'animationend', function () {
			el.classList.remove( cls );
		}, { once: true } );
	}

	function bumpCartIcon() {
		document.querySelectorAll( '.header-cart-button .kadence-svg-iconset' )
			.forEach( function ( el ) {
				playOnce( el, 'is-bumping' );
			} );
	}

	$( document.body ).on( 'added_to_cart', function ( e, fragments, hash, $button ) {
		// L'offre « version numérique » a-t-elle été retenue ? C'est l'attribut que
		// la case à cocher pose sur le bouton (numerique-offer.js) et que le JS cœur
		// de WooCommerce a transmis au serveur : le signal le plus fidèle à ce qui a
		// réellement été demandé.
		//
		// ⚠️ Lu SYNCHRONEMENT, jamais dans le `.then()` plus bas : le mini-panier
		// rafraîchi par cet même événement révèle le numérique, et le
		// MutationObserver de numerique-offer.js décoche alors la case — donc retire
		// l'attribut — dès la microtâche suivante, bien avant l'atterrissage du vol.
		var withNumerique = !! ( $button && $button.attr && $button.attr( 'data-pf_add_numerique' ) );

		// Sur la fiche livre, un exemplaire s'envole vers le panier (add-to-cart-flight.js) :
		// le toast attend l'atterrissage, sinon il paraîtrait pendant le vol. Ailleurs,
		// il n'y a pas de vol et la promesse est déjà tenue.
		var flight = window.pfCartFlight || {};
		var landed = flight.pending || Promise.resolve();
		flight.pending = null;

		// Ce `.then()` sert aussi de report d'un tour de boucle, indispensable même
		// sans vol : le fragment `span.header-cart-total` est remplacé pendant ce
		// même événement et l'ordre des gestionnaires n'est pas garanti. Une
		// microtâche s'exécute après TOUS les gestionnaires synchrones du
		// `trigger()`, donc après le remplacement — c'est bien le nouveau nœud
		// qu'on anime.
		landed.then( function () {
			bumpCartIcon();
			toastAdded( S.title, fallbackOf( $button ), withNumerique );
		} );
	} );

	/**
	 * Ajout confirmé par une page qui recharge dans la foulée (encart « version
	 * numérique » du panier) : elle ne peut pas afficher le toast elle-même et
	 * nous passe le relais par sessionStorage. Lu **et effacé** au chargement, pour
	 * qu'un aller-retour dans l'historique ne le rejoue pas.
	 */
	try {
		var handoff = sessionStorage.getItem( 'pfCartToast' );

		if ( handoff ) {
			sessionStorage.removeItem( 'pfCartToast' );
			var added = ( JSON.parse( handoff ) || {} ).added;
			if ( added ) {
				bumpCartIcon();
				toastAdded( added, '' );
			}
		}
	} catch ( e ) {}

	/* ─── Retrait ───────────────────────────────────────────────────── */

	// Le titre doit être lu AU CLIC : quand `removed_from_cart` se déclenche, les
	// fragments ont déjà remplacé le mini-panier et la ligne n'existe plus.
	var removed = '';

	$( document.body ).on( 'click', '.remove_from_cart_button', function () {
		var item = $( this ).closest( '.woocommerce-mini-cart-item' );
		removed = $.trim( item.find( '.pf-card-title' ).first().text() );
	} );

	$( document.body ).on( 'removed_from_cart', function ( e, fragments, hash, $button ) {
		var title    = removed;
		var fallback = fallbackOf( $button );
		removed = '';

		if ( ! title && ! fallback ) {
			return;
		}
		toast(
			title ? String( S.removed ).replace( '%s', titleHtml( title ) ) : escapeHtml( fallback ),
			ICON_REMOVE
		);
	} );
} )( window.jQuery );
