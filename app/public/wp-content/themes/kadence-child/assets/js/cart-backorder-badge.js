/**
 * Panier + validation de commande (blocs) — badge « En précommande » à la
 * place du badge de promo.
 *
 * Les deux pages sont rendues en React (Store API) sur la même donnée
 * `wc/store/cart`, sans point d'accroche PHP pour injecter du HTML au bon
 * endroit : on recopie le badge dans le DOM après chaque re-rendu (même
 * idiome que wc-block-notices-toast.js). Le point d'insertion
 * (`.wc-block-cart-item__total-price-and-sale-badge-wrapper`) est identique
 * dans les deux contextes, mais pas la ligne qui le contient ni un moyen
 * commun de la rapprocher de son item de store : le panier a un vrai lien
 * `<a>` sur la vignette (permalien) ; le récapitulatif de commande n'en a
 * aucun (juste une `<img>`) — et son URL ne suffit pas non plus : une édition
 * papier et sa version numérique compagnon (offerte au même achat, cf.
 * numerique-offer.php) partagent le même `_thumbnail_id`, donc la même image,
 * et peuvent toutes deux se retrouver dans la même commande. On retombe donc
 * sur la **position** de la ligne, le DOM et le store itérant tous deux
 * `cart.items` dans le même ordre (même idiome que numerique-ebook-tip.js).
 * La Store API expose le statut réel sous `extensions.passiflore.is_backorder`
 * (inc/cart-backorder-badge.php).
 *
 * Le badge porte une infobulle « i » (composant partagé .pf-tooltip /
 * pf-tooltip.js, cf. style.css). Livre papier : texte accordé au
 * singulier/pluriel sur la quantité de l'article, reconstruit seulement
 * quand elle change (pour ne pas fermer une bulle déjà ouverte à chaque
 * re-rendu du panier). Livre numérique (`extensions.passiflore.is_numerique`) :
 * texte fixe, sans rapport avec l'expédition.
 */
( function () {
	'use strict';

	var data = window.wp && window.wp.data;
	if ( ! data || ! data.subscribe || ! data.select ) {
		return;
	}

	var BADGE_CLASS    = 'pf-cart-backorder-badge';
	var TIP_ID_PREFIX  = 'pf-cart-backorder-tip-';
	var ROW_SELECTOR   = '.wc-block-cart-items__row, .wc-block-components-order-summary-item';

	function findByPermalink( items, href ) {
		for ( var i = 0; i < items.length; i++ ) {
			if ( items[ i ].permalink === href ) {
				return items[ i ];
			}
		}
		return null;
	}

	// Panier : lien de la vignette (permalien), fiable et sans ambiguïté même
	// si deux lignes partagent la même couverture. Récapitulatif de commande :
	// pas de lien → repli sur la position (voir commentaire de fichier) ;
	// `rows`/`items` doivent avoir la même longueur, sans quoi la
	// correspondance ne veut rien dire (rendu React transitoire).
	function findItemForRow( items, row, index, rowCount ) {
		var link = row.querySelector( '.wc-block-cart-item__image a[href]' );
		if ( link ) {
			return findByPermalink( items, link.getAttribute( 'href' ) );
		}
		if ( rowCount === items.length ) {
			return items[ index ] || null;
		}
		return null;
	}

	// Numérique : jamais expédié (toujours sold_individually, donc qty === 1),
	// le message n'a rien à voir avec celui d'un livre papier — pas d'accord
	// singulier/pluriel à y faire.
	function tipText( qty, isNumerique ) {
		if ( isNumerique ) {
			return 'La version numérique est en cours de préparation. Nous vous notifierons lorsqu’elle sera disponible, vous pourrez alors la lire et la télécharger depuis votre compte. Si vous passez commande sans être connecté(e), nous vous l’enverrons par mail.';
		}
		if ( qty > 1 ) {
			return 'Ces livres nous parviendront bientôt, nous vous les enverrons dès qu’ils seront disponibles.';
		}
		return 'Ce livre nous parviendra bientôt, nous vous l’enverrons dès qu’il sera disponible.';
	}

	function tooltipMarkup( text, id ) {
		return '<span class="pf-tooltip"><span class="pf-tooltip__trigger" tabindex="0" role="button" aria-label="Précisions sur la précommande" aria-describedby="' + id + '">' +
			'<svg viewBox="0 0 24 24" width="15" height="15" fill="currentColor" aria-hidden="true"><path d="M11 7h2v2h-2V7zm0 4h2v6h-2v-6zm1-9C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm0 18c-4.41 0-8-3.59-8-8s3.59-8 8-8 8 3.59 8 8-3.59 8-8 8z"/></svg>' +
			'</span><span class="pf-tooltip__bubble" id="' + id + '" role="tooltip">' + text + '</span></span>';
	}

	function apply() {
		var store = data.select( 'wc/store/cart' );
		var cart  = store && store.getCartData ? store.getCartData() : null;
		var items = cart && cart.items ? cart.items : null;
		if ( ! items ) {
			return;
		}

		var rows = document.querySelectorAll( ROW_SELECTOR );
		Array.prototype.forEach.call( rows, function ( row, index ) {
			var wrapper = row.querySelector( '.wc-block-cart-item__total-price-and-sale-badge-wrapper' );
			if ( ! wrapper ) {
				return;
			}

			var item = findItemForRow( items, row, index, rows.length );
			var ext  = item && item.extensions && item.extensions.passiflore;
			var isBackorder = !! ( ext && ext.is_backorder );
			var isNumerique = !! ( ext && ext.is_numerique );

			var existing = wrapper.querySelector( '.' + BADGE_CLASS );
			if ( ! isBackorder ) {
				if ( existing ) {
					existing.remove();
				}
				return;
			}

			var qty = ( item && item.quantity ) || 1;
			if ( existing ) {
				if ( existing.getAttribute( 'data-pf-qty' ) !== String( qty ) ) {
					existing.setAttribute( 'data-pf-qty', String( qty ) );
					var bubble = existing.querySelector( '.pf-tooltip__bubble' );
					if ( bubble ) {
						bubble.textContent = tipText( qty, isNumerique );
					}
				}
				return;
			}

			var badge = document.createElement( 'span' );
			badge.className = 'pf-badge pf-badge--sm pf-badge--accent ' + BADGE_CLASS;
			badge.setAttribute( 'data-pf-qty', String( qty ) );
			badge.appendChild( document.createTextNode( 'En précommande' ) );
			badge.insertAdjacentHTML( 'beforeend', tooltipMarkup( tipText( qty, isNumerique ), TIP_ID_PREFIX + ( item.key || item.id ) ) );
			wrapper.appendChild( badge );
			if ( window.pfTooltip ) {
				window.pfTooltip.wire( badge.querySelector( '.pf-tooltip' ) );
			}
		} );
	}

	// Un seul balayage par frame : React remplace les lignes à volonté, les
	// enregistrements de mutation eux-mêmes ne sont jamais lus.
	var pending  = false;
	var observer = new MutationObserver( function () {
		if ( pending ) {
			return;
		}
		pending = true;
		requestAnimationFrame( function () {
			pending = false;
			apply();
		} );
	} );

	observer.observe( document.body, { childList: true, subtree: true } );
	data.subscribe( apply, 'wc/store/cart' );
	apply();
} )();
