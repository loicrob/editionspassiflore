/**
 * Fiche livre — le livre s'envole vers le panier à l'ajout (~560 ms).
 *
 * Premier maillon d'un retour ENCHAÎNÉ : clic → vol → le serveur confirme, et
 * c'est alors seulement que l'icône du panier pulse et que le toast paraît
 * (`cart-toast.js`, site-wide). Enchaînés et non simultanés : c'est l'ordre, pas
 * le nombre, qui les empêche de se marcher dessus. Le relais se fait par
 * **`window.pfCartFlight.pending`** — une promesse d'atterrissage posée au clic,
 * que le contrôleur de toasts attend s'il la trouve.
 *
 * Ce fichier ne montre rien lui-même : sans lui (ou hors fiche livre), le toast
 * paraît simplement sans attendre.
 *
 * Amélioration progressive : sans JS, le bouton est un simple lien `?add-to-cart=`
 * qui recharge la page — pas d'ajout AJAX, donc pas de vol.
 */
( function () {
	'use strict';

	var btn = document.querySelector( '.bs-hero__cart' );

	if ( ! btn ) {
		return;
	}

	var REDUCED = window.matchMedia( '(prefers-reduced-motion: reduce)' );

	var FLY_MS       = 560; // durée du vol
	var FLY_LAND_PX  = 22;  // largeur du livre à l'arrivée (icône panier ≈ 19px)
	var FLY_SEED_PX  = 64;  // largeur du clone quand il jaillit du bouton (livre hors champ)
	var FLY_ARC_PX   = 24;  // dépassement au-dessus du panier avant d'y retomber
	var FLY_STAGGER  = 220; // retard de la liseuse : elle décolle avant que le livre n'atterrisse
	var FLY_MARGIN   = 8;   // marge conservée entre le vol et le bord de l'écran
	var FLY_STEPS    = 24;  // échantillonnage des trajectoires (nécessaire au bornage)

	// Courbes de la trajectoire. En tableaux plutôt qu'en chaînes : le bornage au
	// viewport doit les ÉVALUER en JS, et une seule source évite toute dérive
	// entre ce que le navigateur anime et ce que le calcul suppose.
	var EASE_X  = [ .35, 0, .6, .45 ];  // X : part doucement, file vers le panier
	var EASE_Y1 = [ .25, .75, .5, 1 ];  // Y montant : s'élève d'emblée
	var EASE_Y2 = [ .5, 0, .9, .6 ];    // Y retombant : plonge dans le panier
	var EASE_S  = [ .4, 0, .7, .6 ];    // échelle : reste lisible sur le 1er tiers

	/** Évalue une cubic-bezier CSS : t (0→1) → progression (0→1). */
	function bezier( e ) {
		function axis( a, b ) {
			return function ( u ) { var v = 1 - u; return 3 * v * v * u * a + 3 * v * u * u * b + u * u * u; };
		}
		var fx = axis( e[ 0 ], e[ 2 ] );
		var fy = axis( e[ 1 ], e[ 3 ] );
		return function ( t ) {
			var lo = 0, hi = 1, m = t;
			for ( var i = 0; i < 20; i++ ) {
				m = ( lo + hi ) / 2;
				if ( fx( m ) < t ) { lo = m; } else { hi = m; }
			}
			return fy( m );
		};
	}

	function cssEase( e ) {
		return 'cubic-bezier(' + e.join( ',' ) + ')';
	}

	/**
	 * Le bouton panier réellement affiché ET dans le champ — desktop et mobile ont
	 * chacun le leur, celui de l'autre largeur est rendu mais de taille nulle.
	 *
	 * ⚠️ La présence ne suffit pas : le header n'est pas collant à toutes les
	 * largeurs. À 1024 px, c'est le header MOBILE qui s'affiche et il défile avec
	 * la page — le panier se retrouve loin au-dessus de l'écran (mesuré : −797 px
	 * à 900 px de défilement). Un vol vers cette cible sortirait de l'écran pour
	 * atterrir là où personne ne regarde. Sans cible visible on ne vole pas : le
	 * toast confirme l'ajout, ce qui suffit.
	 */
	function cartTarget() {
		var els = document.querySelectorAll( '.header-cart-button' );
		for ( var i = 0; i < els.length; i++ ) {
			var r = els[ i ].getBoundingClientRect();
			if ( ! r.width || ! r.height ) {
				continue;
			}
			var cx = r.left + r.width / 2;
			var cy = r.top  + r.height / 2;
			if ( cx > 0 && cx < window.innerWidth && cy > 0 && cy < window.innerHeight ) {
				return els[ i ];
			}
		}
		return null;
	}

	/**
	 * « Réellement visible » ≠ « dans le viewport » : le header sticky recouvre le
	 * haut de la page. Sur mobile, quand le bouton d'achat est à l'écran, le livre
	 * du hero est entièrement caché derrière lui (mesuré : bas du livre à 41 px
	 * pour un header de 148 px) alors que son rect touche encore le viewport.
	 * On exige donc la moitié de sa hauteur sous la ligne du header.
	 */
	function visibleEnough( rect ) {
		var top = parseFloat( getComputedStyle( document.documentElement )
			.getPropertyValue( '--pf-sticky-offset' ) ) || 0;
		return Math.min( rect.bottom, window.innerHeight ) - Math.max( rect.top, top )
			>= rect.height * 0.5;
	}

	/**
	 * D'où part un vol : le livre du hero s'il est réellement visible, sinon le
	 * bouton qu'on vient de toucher. Commun à tous les objets envoyés au panier
	 * (le livre, puis la liseuse) — ils quittent le même endroit.
	 */
	function flightOrigin() {
		var book = document.querySelector( '.bs-hero__visual .pf-book' );
		if ( book ) {
			var r = book.getBoundingClientRect();
			if ( r.width && visibleEnough( r ) ) {
				return { cx: r.left + r.width / 2, cy: r.top + r.height / 2, onShelf: true };
			}
		}
		if ( btn ) {
			var b = btn.getBoundingClientRect();
			return { cx: b.left + b.width / 2, cy: b.top + b.height / 2, onShelf: false };
		}
		return null;
	}

	/**
	 * Envoie une COPIE d'un livre vers le panier — le volume entier (dos, bandeau
	 * de pages, couverture), pas seulement la couverture. L'original reste en
	 * place : on achète un exemplaire, on ne vide pas l'étagère.
	 *
	 * @param {Element} source Le `.pf-book` à cloner (celui du hero, ou celui que
	 *                         le <template> de l'offre numérique tient au frais).
	 * @param {number}  delay  Retard au départ, pour échelonner plusieurs vols.
	 * → Promise résolue à l'atterrissage, avec `true` s'il y a eu vol, `false`
	 *   sinon (résolue immédiatement dans ce cas).
	 */
	function flyBookToCart( source, delay ) {
		var cart   = cartTarget();
		var origin = flightOrigin();

		if ( REDUCED.matches || ! source || ! cart || ! origin ) {
			return Promise.resolve( false );
		}

		// ⚠️ L'arc ne peut PAS se faire sur un seul `translate()` à images-clés
		// intermédiaires : les deux courbes se composent alors en « élan, temps
		// mort, ruée » (mesuré — le livre parcourait 90 % de sa montée en 25 %
		// du temps, puis restait quasi immobile jusqu'à 75 %). Chaque axe a donc
		// son propre élément et sa propre courbe, ce que `transform` ne sait pas
		// exprimer sur un seul nœud. La composition des deux donne le lancer ;
		// chaque axe reste monotone, donc aucun temps mort possible.
		var wrapX = document.createElement( 'div' );
		wrapX.className = 'pf-fly-book';

		var wrapY = document.createElement( 'div' );
		wrapY.className = 'pf-fly-book__y';

		// ⚠️ TOUTES les règles du livre sont scopées sous `.pf-bookshelf--covers`
		// / `--hero`, et c'est sur `.pf-bookshelf` que sont déclarées les variables
		// du dessin (--pf-obl, l'angle de fuite, --pf-backcover…). Détaché de ce
		// contexte, le clone perdrait dos, bandeau de pages et projection. On
		// recrée donc l'ancêtre — un seul suffit, aucune de ces règles n'utilise
		// de combinateur enfant (vérifié sur les 83 règles concernées). Son
		// habillage de panneau (fond, cadre, ombre) est neutralisé en CSS.
		var stage = document.createElement( 'div' );
		stage.className = 'pf-fly-book__stage pf-bookshelf pf-bookshelf--covers pf-bookshelf--hero is-sized';

		var clone = source.cloneNode( true );
		clone.setAttribute( 'aria-hidden', 'true' );
		// Le livre du hero est un bouton (il ouvre la liseuse) : le clone ne doit
		// être ni focalisable, ni annoncé, ni cliquable.
		[ 'role', 'tabindex', 'aria-label', 'data-trigger-flipbook' ].forEach( function ( a ) {
			clone.removeAttribute( a );
		} );
		clone.querySelectorAll( '.pf-hero-flip-hint' ).forEach( function ( h ) {
			h.remove(); // affordance de survol (« EXTRAIT → ») : hors sujet en vol
		} );
		clone.querySelectorAll( 'img' ).forEach( function ( i ) {
			i.alt = '';
			i.removeAttribute( 'loading' );
			i.removeAttribute( 'fetchpriority' );
		} );

		stage.appendChild( clone );
		wrapY.appendChild( stage );
		wrapX.appendChild( wrapY );
		document.body.appendChild( wrapX );

		// Taille MESURÉE après insertion, jamais supposée : la source peut être le
		// livre du hero (redimensionné en JS selon la hauteur du texte) ou le
		// contenu inerte d'un <template>, qui n'a aucune boîte tant qu'il n'est pas
		// inséré. Tout est encore synchrone : aucune peinture entre-temps.
		var nat = clone.getBoundingClientRect();
		var W = nat.width;
		var H = nat.height;
		if ( ! W || ! H ) {
			wrapX.remove();
			return Promise.resolve( false );
		}

		// Le clone garde sa taille naturelle ; c'est l'échelle de départ qui
		// change. On raisonne donc en CENTRES, invariants sous `scale`.
		var scale0 = origin.onShelf ? 1 : FLY_SEED_PX / W;
		var scale1 = FLY_LAND_PX / W;
		var tRect  = cart.getBoundingClientRect();
		var x0 = origin.cx - W / 2;
		var y0 = origin.cy - H / 2;
		var x1 = tRect.left + tRect.width  / 2 - W / 2;
		var y1 = tRect.top  + tRect.height / 2 - H / 2;
		var yApex = y1 - FLY_ARC_PX;

		// ── Bornage au viewport ────────────────────────────────────────────
		// Sans lui le livre sort par le haut de l'écran (mesuré : 64 px à 1280,
		// au tiers du trajet) — il est encore grand quand il atteint la hauteur
		// du panier, donc son bord haut passe au-dessus. Les bornes sont
		// ÉLARGIES aux deux extrémités du vol : le livre du hero peut lui-même
		// être à moitié sous le header sticky, et le repousser au départ
		// provoquerait un saut. Autrement dit : le vol ne sort jamais de l'écran
		// plus que ses propres extrémités.
		var e0 = W * ( 1 - scale0 ) / 2, f0 = H * ( 1 - scale0 ) / 2;
		var e1 = W * ( 1 - scale1 ) / 2, f1 = H * ( 1 - scale1 ) / 2;
		var lim = {
			left:   Math.min( FLY_MARGIN, x0 + e0, x1 + e1 ),
			top:    Math.min( FLY_MARGIN, y0 + f0, y1 + f1 ),
			right:  Math.max( window.innerWidth  - FLY_MARGIN, x0 + W - e0, x1 + W - e1 ),
			bottom: Math.max( window.innerHeight - FLY_MARGIN, y0 + H - f0, y1 + H - f1 )
		};

		// Les deux axes sont échantillonnés plutôt que confiés à `easing` : c'est
		// la seule façon d'appliquer le bornage, qui dépend de l'échelle à
		// l'instant t (un livre réduit peut monter plus haut sans sortir).
		var eX = bezier( EASE_X ), eY1 = bezier( EASE_Y1 ), eY2 = bezier( EASE_Y2 ), eS = bezier( EASE_S );
		var keysX = [], keysY = [];

		for ( var i = 0; i <= FLY_STEPS; i++ ) {
			var t  = i / FLY_STEPS;
			var s  = scale0 + ( scale1 - scale0 ) * eS( t );
			var ex = W * ( 1 - s ) / 2, fy = H * ( 1 - s ) / 2;
			var x  = x0 + ( x1 - x0 ) * eX( t );
			var y  = t <= 0.7
				? y0 + ( yApex - y0 ) * eY1( t / 0.7 )
				: yApex + ( y1 - yApex ) * eY2( ( t - 0.7 ) / 0.3 );

			// Bornes croisées = objet plus grand que l'écran : on laisse la valeur
			// brute plutôt que de la faire sauter d'un bord à l'autre.
			var loX = lim.left - ex, hiX = lim.right  - W + ex;
			var loY = lim.top  - fy, hiY = lim.bottom - H + fy;
			if ( loX <= hiX ) { x = Math.min( Math.max( x, loX ), hiX ); }
			if ( loY <= hiY ) { y = Math.min( Math.max( y, loY ), hiY ); }

			keysX.push( { transform: 'translateX(' + x + 'px)' } );
			keysY.push( { transform: 'translateY(' + y + 'px)' } );
		}

		// ⚠️ `fill: 'both'` et non `'forwards'` : avec un retard au départ, la
		// première image-clé doit déjà s'appliquer PENDANT l'attente, sinon la
		// liseuse resterait visible en haut à gauche de l'écran, à sa taille
		// pleine, jusqu'à son décollage.
		var timing = { duration: FLY_MS, delay: delay || 0, fill: 'both' };
		var linear = { duration: FLY_MS, delay: delay || 0, fill: 'both', easing: 'linear' };

		var flight = wrapX.animate( keysX, linear );
		wrapY.animate( keysY, linear );

		// Rétrécissement et rotation suivent leur propre courbe : le livre doit
		// rester lisible sur le premier tiers du trajet, pas fondre d'emblée.
		stage.animate(
			[ { transform: 'scale(' + scale0 + ') rotate(0deg)' },
			  { transform: 'scale(' + scale1 + ') rotate(14deg)' } ],
			Object.assign( { easing: cssEase( EASE_S ) }, timing )
		);

		// L'opacité a encore un autre profil (rien pendant 78 %, puis extinction) :
		// sans elle, le livre disparaîtrait d'un coup à l'arrivée.
		stage.animate(
			[ { opacity: 1 }, { opacity: 1, offset: 0.78 }, { opacity: 0 } ],
			timing
		);

		return flight.finished
			.catch( function () {} )
			.then( function () { wrapX.remove(); return true; } );
	}

	// Offre « version numérique » : quand elle est retenue, deux produits partent
	// au panier — le livre puis la liseuse. Le markup de celle-ci vit dans un
	// <template> rendu par inc/numerique-offer.php (inerte : rien n'est chargé
	// tant qu'on n'y touche pas).
	var offerCheck = document.querySelector( '.pf-numerique-offer__check' );
	var offerTpl   = document.querySelector( '.pf-numerique-offer__book' );

	function numeriqueBook() {
		return ( offerCheck && offerCheck.checked && offerTpl )
			? offerTpl.content.querySelector( '.pf-book' )
			: null;
	}

	// Réchauffe l'image de la liseuse dès que la case est cochée : le contenu d'un
	// <template> n'est jamais téléchargé, et le vol part au clic suivant — sans
	// ça, l'écran de la liseuse serait blanc au décollage. Rien n'est chargé si
	// l'offre n'est pas retenue.
	if ( offerCheck && offerTpl ) {
		offerCheck.addEventListener( 'change', function () {
			if ( ! offerCheck.checked ) {
				return;
			}
			offerTpl.content.querySelectorAll( 'img' ).forEach( function ( i ) {
				var warm = new Image();
				warm.src = i.src;
			} );
		} );
	}

	// Le vol part au CLIC, pas à la réponse du serveur : il couvre la latence de
	// la requête d'ajout, pendant laquelle il ne se passait rien. Optimiste — si
	// l'ajout échoue, `added_to_cart` ne se déclenche pas et WooCommerce remonte
	// une notice, elle-même déportée en toast (inc/wc-notices-toast.php).
	window.pfCartFlight = window.pfCartFlight || { pending: null };

	btn.addEventListener( 'click', function () {
		var hero    = document.querySelector( '.bs-hero__visual .pf-book' );
		var ereader = numeriqueBook();
		var flights = [ flyBookToCart( hero, 0 ) ];

		// La liseuse décolle avant que le livre n'ait atterri : les deux sont
		// en l'air ensemble, on lit une suite et non deux animations séparées.
		if ( ereader ) {
			flights.push( flyBookToCart( ereader, FLY_STAGGER ) );
		}

		window.pfCartFlight.pending = Promise.all( flights );
	} );
} )();
