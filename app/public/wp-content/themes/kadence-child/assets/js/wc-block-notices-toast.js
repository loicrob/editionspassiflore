/**
 * Notices des blocs Panier / Commander (React) → toasts du site.
 *
 * Pendant du contrôleur des notices classiques (`wc-notices-toast.js`), pour
 * l'autre système de messages de WooCommerce : celui des blocs, alimenté par la
 * Store API et rendu par React dans des conteneurs `.wc-block-components-notices`
 * (bannières) et `.wc-block-components-notices__snackbar`. Les deux formes portent
 * la même classe `.wc-block-components-notice-banner` (+ `is-error` / `is-success`
 * / `is-warning` / `is-info`), d'où un seul sélecteur.
 *
 * **Deux capteurs, parce qu'il y a deux origines et qu'aucune ne couvre l'autre :**
 *
 * 1. **Le store `core/notices`** — coupon retiré, erreur de paiement, échec de
 *    soumission de commande, échec d'ajout au panier depuis un bouton de bloc…
 *    On y prend la notice et on l'en retire aussitôt, par l'API officielle
 *    (`removeNotice`, ce que fait déjà son bouton « rejeter »). Deux raisons : une
 *    notice à identifiant fixe (`add-to-cart`, `wc-payment-error`) resterait sinon
 *    en place et **une deuxième tentative identique n'afficherait plus rien** ; et
 *    surtout, certaines n'ont **aucun conteneur pour les rendre** — un échec
 *    d'ajout au panier est publié en contexte `wc/all-products`, dont le conteneur
 *    n'existe que sur le bloc « Tous les produits », absent du panier : vérifié,
 *    WooCommerce l'y perd purement et simplement. Les reprendre ici les rend
 *    visibles.
 * 2. **Le DOM** — les erreurs renvoyées par la Store API dans la réponse panier
 *    (article devenu indisponible, prix modifié) sont passées au conteneur en
 *    **props** (`additionalNotices`) : elles ne passent jamais par le store, et
 *    seul l'affichage les trahit.
 *
 * **On ne retire jamais un nœud du DOM de React** (il le supprimerait à son tour
 * plus tard → `removeChild` sur un nœud disparu) : les conteneurs sont masqués en
 * CSS (`html.pf-notices-js`, cf. style.css) et on se contente de recopier ce qui y
 * apparaît.
 *
 * Amélioration progressive : sans JavaScript il n'y a pas de blocs du tout, donc
 * rien à reprendre ; si ce script ne s'exécute pas, la classe `pf-notices-js` n'est
 * pas posée non plus et les notices s'affichent normalement.
 */
( function () {
	'use strict';

	var data = window.wp && window.wp.data;

	if ( ! window.pfToast || ! data || ! data.subscribe ) {
		return;
	}

	// Contextes de notices des blocs : un conteneur par section du tunnel, plus
	// `wc/all-products` (boutons « Ajouter au panier » des blocs de produits).
	var CONTEXTS = [
		'wc/cart',
		'wc/checkout',
		'wc/checkout/payments',
		'wc/checkout/express-payments',
		'wc/checkout/contact-information',
		'wc/checkout/shipping-address',
		'wc/checkout/billing-address',
		'wc/checkout/shipping-methods',
		'wc/checkout/checkout-actions',
		'wc/checkout/order-information',
		'wc/all-products'
	];

	var CLASS_STATUS = {
		'is-error':   'error',
		'is-success': 'success',
		'is-warning': 'warning',
		'is-info':    'info'
	};

	var ICON      = window.pfNoticeIcons || {};
	var DEDUPE_MS = 2000;

	var seen   = [];               // clés présentes au dernier balayage du DOM
	var recent = Object.create( null ); // clé → date du dernier toast

	/** Texte nu d'un fragment de markup (clé de déduplication). */
	function textOf( html ) {
		var tmp = document.createElement( 'div' );
		tmp.innerHTML = html;
		return ( tmp.textContent || '' ).trim();
	}

	/**
	 * Un même message peut être vu par les deux capteurs si React peint la notice
	 * avant qu'on ne l'ait retirée du store. Fenêtre courte : une vraie répétition
	 * (deuxième tentative de paiement, même code promo re-soumis) demande un
	 * aller-retour utilisateur, bien plus long que ces deux secondes.
	 */
	function emit( status, html ) {
		var key = status + '|' + textOf( html );
		var now = Date.now();

		if ( recent[ key ] && now - recent[ key ] < DEDUPE_MS ) {
			return;
		}
		recent[ key ] = now;

		window.pfToast.show( {
			// Markup déjà assaini par les blocs (`sanitizeHTML`) : le relire ici
			// n'ouvre aucune surface nouvelle.
			html:    html,
			icon:    ICON[ status ],
			status:  status,
			// Même règle que les notices classiques : une erreur reste jusqu'à
			// fermeture manuelle (carte refusée, champ manquant), le reste suit la
			// durée par défaut du composant.
			duration: 'error' === status ? 0 : undefined
		} );
	}

	/* ─── Capteur 1 : le store ──────────────────────────────────────── */

	function drainStore() {
		var select   = data.select( 'core/notices' );
		var dispatch = data.dispatch( 'core/notices' );

		if ( ! select || ! dispatch ) {
			return;
		}
		CONTEXTS.forEach( function ( context ) {
			( select.getNotices( context ) || [] ).forEach( function ( notice ) {
				// Retrait d'abord : le ré-appel de ce même écouteur qu'il provoque
				// ne retrouve alors plus rien à traiter.
				dispatch.removeNotice( notice.id, context );
				// `status` du store ('error', 'success'…) = classe de la bannière
				// sans son préfixe ; 'default' n'y figure pas et retombe sur info.
				emit( CLASS_STATUS[ 'is-' + notice.status ] || 'info', notice.content );
			} );
		} );
	}

	// Second argument : n'écouter que ce store (ignoré par les versions de
	// @wordpress/data qui ne le connaissent pas — on retombe alors sur un
	// abonnement global, plus bavard mais tout aussi correct).
	data.subscribe( drainStore, 'core/notices' );
	drainStore();

	/* ─── Capteur 2 : le DOM ────────────────────────────────────────── */

	// ⚠️ Uniquement les bannières PLACÉES DANS UN CONTENEUR de notices : la règle
	// est « on reprend exactement ce que le CSS masque ». Certaines vues du tunnel
	// composent un message à demeure avec la même classe de bannière, hors
	// conteneur — « Aucun moyen de paiement disponible » vit dans l'étape Paiement,
	// où il reste visible et où il a un sens précis. Le mettre aussi en toast en
	// ferait un doublon, et un toast pour un état permanent n'a pas de sens.
	var BANNERS = '.wc-block-components-notices .wc-block-components-notice-banner,'
		+ '.wc-block-components-notices__snackbar .wc-block-components-notice-banner';

	function statusOf( el ) {
		for ( var cls in CLASS_STATUS ) {
			if ( el.classList.contains( cls ) ) {
				return CLASS_STATUS[ cls ];
			}
		}
		return 'info';
	}

	/**
	 * Messages portés par une bannière. Quand plusieurs notices de même statut
	 * arrivent ensemble, le conteneur les groupe en UNE bannière : un résumé
	 * (« Veuillez corriger les erreurs suivantes ») puis un `<li>` par notice. On
	 * rend alors un toast par `<li>` — même règle que côté classique, où une notice
	 * d'erreur multiple est un `<ul>` de plusieurs `<li>`.
	 */
	function messagesOf( banner ) {
		var content = banner.querySelector( '.wc-block-components-notice-banner__content' ) || banner;
		var items   = content.querySelectorAll( 'li' );

		if ( items.length ) {
			return Array.prototype.map.call( items, function ( li ) {
				return li.innerHTML.trim();
			} );
		}
		return [ content.innerHTML.trim() ];
	}

	/**
	 * Diff entre deux passes plutôt que mémoire cumulative : une notice qui
	 * disparaît puis revient doit redonner un toast, alors qu'un simple re-rendu de
	 * React ne doit rien redonner.
	 */
	function scan() {
		var now = [];

		Array.prototype.forEach.call(
			document.querySelectorAll( BANNERS ),
			function ( banner ) {
				var status = statusOf( banner );
				messagesOf( banner ).forEach( function ( html ) {
					var key = status + '|' + html;
					now.push( key );
					if ( -1 === seen.indexOf( key ) ) {
						emit( status, html );
					}
				} );
			}
		);

		seen = now;
	}

	// Observation à la racine du document : les conteneurs de notices ne sont pas
	// tous dans le bloc Panier/Commande (un bloc de produits en amène le sien), et
	// React les monte et démonte à volonté. Le coût reste une seule passe de
	// `querySelectorAll` par frame — les enregistrements de mutation ne sont jamais
	// lus, seulement comptés comme « quelque chose a bougé ».
	var pending  = false;
	var observer = new MutationObserver( function () {
		if ( pending ) {
			return;
		}
		pending = true;
		requestAnimationFrame( function () {
			pending = false;
			scan();
		} );
	} );

	observer.observe( document.body, { childList: true, subtree: true, characterData: true } );
	scan();
} )();
