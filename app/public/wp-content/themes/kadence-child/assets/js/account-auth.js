/**
 * Bascule connexion ↔ création de compte (page « Mon compte », déconnecté).
 *
 * Amélioration progressive pure : sans ce script, les deux bascules sont des
 * liens normaux qui rechargent la page sur la bonne URL (le serveur lit
 * ?action=register). Ici, on évite simplement le rechargement.
 *
 * ⚠️ history.replaceState n'est PAS cosmétique : les formulaires postent vers
 * l'URL courante, et WooCommerce re-rend cette même URL en cas d'erreur
 * (email déjà utilisé, mot de passe refusé…). Sans réécriture, une inscription
 * ratée après bascule JS renverrait le visiteur sur le formulaire de connexion,
 * avec un message d'erreur sans objet visible.
 */
( function () {
	'use strict';

	var root = document.getElementById( 'customer_login' );
	if ( ! root || ! root.classList.contains( 'pf-auth' ) ) {
		return;
	}

	/*
	 * Page restaurée depuis le back/forward cache (bouton Précédent) : le
	 * navigateur ré-affiche le HTML mémorisé SANS consulter le serveur. Elle
	 * montre donc l'état d'authentification d'avant — formulaire de connexion et
	 * bouton « Connexion » alors que la session a pu s'ouvrir entre-temps. Aucun
	 * garde-fou PHP ne peut corriger ça, puisqu'aucune requête n'a lieu : le seul
	 * moyen de resynchroniser est de forcer un rechargement, qui repassera par
	 * pf_auth_guards() et enverra un visiteur connecté sur la page compte.
	 */
	window.addEventListener( 'pageshow', function ( event ) {
		if ( event.persisted ) {
			window.location.reload();
		}
	} );

	/*
	 * Hauteur « plein écran » de la zone (cf. account.css) : on mesure la
	 * distance réelle entre le haut du viewport et le début de la zone, que le
	 * CSS ne sait pas exprimer — --pf-sticky-offset ne compte que les barres
	 * collantes, alors que le bandeau bêta (non collant) et la marge de contenu
	 * décalent la zone plus bas. Mesure au repos : la position du haut de la
	 * zone ne dépend pas de sa propre hauteur (elle est sous le header), donc
	 * pas de boucle de rétroaction avec le min-height qu'elle alimente.
	 */
	var zone = root.parentNode;
	if ( zone && zone.style ) {
		var sizeZone = function () {
			var top = zone.getBoundingClientRect().top + ( window.scrollY || window.pageYOffset || 0 );
			zone.style.setProperty( '--pf-auth-top', Math.round( top ) + 'px' );
		};
		sizeZone();
		// Les polices web peuvent encore changer la hauteur du header après coup.
		window.addEventListener( 'load', sizeZone );
		window.addEventListener( 'resize', sizeZone );
	}

	var links = root.querySelectorAll( '[data-pf-auth-target]' );
	if ( ! links.length ) {
		return;
	}

	function show( target, href ) {
		var isRegister = 'register' === target;

		root.classList.toggle( 'is-register', isRegister );

		// On reprend l'URL du lien lui-même plutôt que de la reconstruire : elle
		// est déjà exactement celle que le serveur sait interpréter, quelle que
		// soit la forme retenue côté PHP.
		if ( href && window.history && window.history.replaceState ) {
			try {
				window.history.replaceState( null, '', href );
			} catch ( e ) {
				/* URL non réécrite : la bascule reste fonctionnelle, on n'interrompt rien. */
			}
		}

		// Le panneau vient d'apparaître : on y amène le focus, sinon il reste sur
		// un lien devenu invisible (et la tabulation repartirait du haut de page).
		var panel = root.querySelector( isRegister ? '.pf-auth__panel--register' : '.pf-auth__panel--login' );
		var field = panel && panel.querySelector( 'input:not([type="hidden"]):not([type="checkbox"])' );
		if ( field ) {
			field.focus();
		}
	}

	Array.prototype.forEach.call( links, function ( link ) {
		link.addEventListener( 'click', function ( event ) {
			event.preventDefault();
			show( link.getAttribute( 'data-pf-auth-target' ), link.href );
		} );
	} );
} )();
