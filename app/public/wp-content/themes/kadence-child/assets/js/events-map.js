/**
 * Vue « Carte » des événements — PROTOTYPE.
 *
 * Initialise une carte Leaflet dans #pf-events-map à partir des marqueurs
 * fournis par window.PassifloreMap (localisés côté PHP). Un point = un lieu ;
 * l'infobulle liste le ou les événements qui s'y déroulent.
 *
 * Regroupement (Leaflet.markercluster) : quand on dézoome, les lieux proches
 * fusionnent en un cluster. Le pin de cluster affiche le nombre de LIEUX
 * (un marqueur = un lieu, pas un événement) ; un clic zoome jusqu'à ce que le groupe se scinde. Quand il
 * est irréductible (lieux aux coordonnées identiques), le clic zoome quand même
 * au niveau ville puis les pins s'écartent en éventail (spiderfy) — une infobulle
 * n'affiche donc jamais qu'un seul lieu.
 *
 * Navigation vers/depuis la carte = rechargement complet (le switch pose un lien
 * simple pour cet onglet), donc une init sur DOMContentLoaded suffit ; l'idempotence
 * (el._pfMapInit) protège d'une double initialisation.
 */
( function () {
	'use strict';

	function esc( s ) {
		var d = document.createElement( 'div' );
		d.textContent = ( s == null ) ? '' : String( s );
		return d.innerHTML;
	}

	// Recentre l'infobulle entièrement dans le conteneur carte, avec une marge `pad`
	// tout autour. Remplace l'auto-pan natif de Leaflet (désactivé) : ce dernier
	// calcule le débordement depuis sa géométrie interne, faussée ici par la pointe
	// masquée + l'`offset` — il concluait « ça tient » et ne descendait pas (assez) la
	// carte, laissant le haut de l'infobulle rogné. On mesure plutôt les rectangles
	// réels (source de vérité) et on décale la carte de la juste quantité. Le haut est
	// prioritaire sur le bas (en-tête + 1res tuiles visibles) si l'infobulle est plus
	// haute que la carte. Signes des dérives calqués sur le _adjustPan de Leaflet :
	// une dérive négative révèle le haut/gauche, positive le bas/droite.
	function panPopupIntoView( map, mapEl, popup, pad ) {
		var wrap = popup && popup._container
			&& popup._container.querySelector( '.leaflet-popup-content-wrapper' );
		if ( ! wrap ) { return; }

		var mapRect = mapEl.getBoundingClientRect();
		var popRect = wrap.getBoundingClientRect();
		var dx = 0, dy = 0;

		if ( popRect.top < mapRect.top + pad ) {
			dy = popRect.top - ( mapRect.top + pad );            // haut rogné → révéler le haut
		} else if ( popRect.bottom > mapRect.bottom - pad ) {
			dy = popRect.bottom - ( mapRect.bottom - pad );      // bas rogné → révéler le bas
		}
		if ( popRect.left < mapRect.left + pad ) {
			dx = popRect.left - ( mapRect.left + pad );
		} else if ( popRect.right > mapRect.right - pad ) {
			dx = popRect.right - ( mapRect.right - pad );
		}

		if ( dx || dy ) {
			// animate:false : un panBy animé s'est révélé non fiable ici (parfois ignoré
			// selon le timing d'ouverture de l'infobulle). Le recentrage est donc instantané.
			map.panBy( [ dx, dy ], { animate: false } );
		}
	}

	// Résout un token CSS --pf-* (longueur) en pixels, via un élément-sonde jetable :
	// getComputedStyle sur une custom property renvoie la valeur brute (ex. "0.5rem"),
	// pas des px. Même idiome que pxVar() dans event-single-media.js. Sert à alimenter
	// l'`offset` Leaflet et le recentrage maison (panPopupIntoView), forcément en px,
	// tout en gardant les tokens comme source unique (--pf-space-2/6).
	function pxVar( token ) {
		var probe = document.createElement( 'div' );
		probe.style.cssText = 'position:absolute;visibility:hidden;height:var(' + token + ',0)';
		document.body.appendChild( probe );
		var px = parseFloat( getComputedStyle( probe ).height ) || 0;
		probe.remove();
		return px;
	}

	// Rangée horizontale des cards d'événement d'un lieu, enveloppée dans le
	// composant global .pf-scroll-fade (ombres de bord gauche/droite signalant
	// qu'il reste des tuiles hors champ). Chaque card est le composant global
	// .pf-event-tile pré-rendu côté serveur (e.html, déjà échappé par PHP) —
	// même tuile que l'accueil, sans la ligne « Lieu ». .pf-scroll-fade exige
	// que l'élément qui scrolle (.pf-hscroll) soit son unique enfant direct.
	function eventsRow( m ) {
		var cards = ( m.events || [] ).map( function ( e ) { return e.html || ''; } ).join( '' );
		return '<div class="pf-scroll-fade pf-map-pop__fade">' +
			'<div class="pf-map-pop__events pf-hscroll">' + cards + '</div>' +
		'</div>';
	}

	// Ombres de bord horizontales : rejoue la logique de scroll-fade.js (bascule
	// .is-scroll-left/.is-scroll-right selon la position de scroll de l'enfant
	// .pf-hscroll) sur les rangées d'une infobulle. Nécessaire ici car
	// scroll-fade.js ne scanne qu'au DOMContentLoaded → il ne voit pas les
	// infobulles que Leaflet injecte dynamiquement à l'ouverture.
	function wireScrollFade( popupEl ) {
		if ( ! popupEl ) { return; }
		popupEl.querySelectorAll( '.pf-scroll-fade' ).forEach( function ( wrap ) {
			var scroll = wrap.firstElementChild;
			if ( ! scroll ) { return; }
			function update() {
				var atStart     = scroll.scrollLeft <= 1;
				var atEnd       = scroll.scrollLeft + scroll.clientWidth >= scroll.scrollWidth - 1;
				var hasOverflow = scroll.scrollWidth > scroll.clientWidth;
				wrap.classList.toggle( 'is-scroll-left',  ! atStart );
				wrap.classList.toggle( 'is-scroll-right', hasOverflow && ! atEnd );
			}
			scroll.addEventListener( 'scroll', update, { passive: true } );
			new ResizeObserver( update ).observe( scroll );
			update();
		} );
	}

	// Infobulle d'un lieu unique : titre (nom + ville) hors zone de scroll,
	// seule la rangée d'événements défile (cf. .pf-map-pop__header / __scroll).
	function singlePopup( m ) {
		var header = '<div class="pf-map-pop__header">' +
			'<span class="pf-map-pop__venue-name">' + esc( m.venue || '' ) + '</span>' +
			( m.city ? '<span class="pf-map-pop__venue-city">' + esc( m.city ) + '</span>' : '' ) +
		'</div>';
		return '<div class="pf-map-pop">' + header + '<div class="pf-map-pop__scroll">' + eventsRow( m ) + '</div></div>';
	}

	// Infobulle d'un lieu à un seul événement (affiché) : pas d'en-tête commun,
	// la tuile pré-rendue EST l'infobulle — la ligne « Lieu » (lieuRow) y est
	// ré-injectée sous la date, comme les tuiles « Événements à venir » de l'accueil.
	function soloPopup( m ) {
		var tpl = document.createElement( 'template' );
		tpl.innerHTML = m.events[ 0 ].html;
		var tile = tpl.content.firstElementChild;
		var meta = tile && tile.querySelector( '.pf-event-card-meta' );
		if ( meta && m.lieuRow ) { meta.insertAdjacentHTML( 'beforeend', m.lieuRow ); }
		return tile || m.events[ 0 ].html;
	}

	// Pin d'un cluster : cercle rouge portant le nombre de LIEUX agrégés
	// (un marqueur = un lieu, cf. tête de fichier — pas le nombre d'événements
	// qu'ils portent chacun).
	function clusterIcon( cluster ) {
		var count = cluster.getAllChildMarkers().length;

		var tier = count < 10 ? 'sm' : ( count < 100 ? 'md' : 'lg' );

		return L.divIcon( {
			html:        '<span>' + count + '</span>',
			className:   'pf-map-cluster pf-map-cluster--' + tier,
			iconSize:    L.point( 40, 40 ),
			// Sans ancrage explicite, Leaflet retombe sur popupAnchor:[0,0] (aucun
			// décalage) : l'infobulle s'ouvrait alors quasiment sur le badge, sans
			// jamais s'en détacher. iconAnchor = centre du badge (cohérent quel que
			// soit le palier sm/md/lg, la taille visuelle du rond variant en CSS mais
			// pas ce iconSize) ; popupAnchor aligné sur celui du pin simple pour un
			// dégagement visuel comparable.
			iconAnchor:  [ 20, 20 ],
			popupAnchor: [ 0, -34 ]
		} );
	}

	// Vrai quand tous les lieux d'un cluster partagent EXACTEMENT les mêmes coordonnées
	// (précision de géocodage « ville » : plusieurs lieux sans adresse précise retombent
	// sur le même centroïde, cf. CLAUDE.md) — aucun zoom ne le scindera jamais.
	function isColocated( cluster ) {
		var kids  = cluster.getAllChildMarkers();
		var first = kids[ 0 ].getLatLng();
		return kids.every( function ( k ) {
			var ll = k.getLatLng();
			return ll.lat === first.lat && ll.lng === first.lng;
		} );
	}

	// Marge réservée aux bords lors du recadrage d'un cluster réductible (zoomToCluster) —
	// sans elle, Leaflet retient le zoom maximal où les bornes remplissent EXACTEMENT le
	// viewport, collant les repères extrêmes aux bords (pins rognés). Asymétrique : le pin
	// est dessiné 37px au-dessus de son point d'ancrage (iconAnchor, ligne 210) — une marge
	// symétrique laisserait optiquement plus de vide en bas qu'en haut.
	var EDGE_PAD_TL  = [ 44, 48 ]; // marge haut/gauche (px)
	var EDGE_PAD_BR  = [ 44, 16 ]; // marge bas/droite (px)
	var EDGE_PAD_SUM = [ 88, 64 ]; // padding TOTAL (TL+BR) attendu par getBoundsZoom()

	// Relais de cluster.zoomToBounds() réservant la marge ci-dessus. ⚠️ Le garde-fou
	// n'est pas décoratif : la lib n'atteint fitBounds() que si boundsZoom > mapZoom, donc
	// boundsZoom peut valoir mapZoom + 1 — sur un cluster large, retirer 88×64px du viewport
	// peut faire retomber le zoom paddé sur mapZoom, et fitBounds ne zoomerait alors plus du
	// tout (clic mort, le groupe ne se scinde jamais). Dans ce cas on repasse au cadrage
	// serré d'origine, qui garantit au moins un cran.
	function zoomToCluster( map, cluster ) {
		var padded = map.getBoundsZoom( cluster.getBounds(), false, EDGE_PAD_SUM );
		cluster.zoomToBounds( padded > map.getZoom()
			? { paddingTopLeft: EDGE_PAD_TL, paddingBottomRight: EDGE_PAD_BR }
			: undefined );
	}

	function init() {
		var el = document.getElementById( 'pf-events-map' );
		if ( ! el || el._pfMapInit || typeof L === 'undefined' || ! window.PassifloreMap ) {
			return;
		}
		el._pfMapInit = true;

		var data       = window.PassifloreMap;
		var allMarkers = data.markers || [];
		var fv         = data.franceView || [ 46.6, 2.4, 5 ];
		var emptyEl    = el.parentNode && el.parentNode.querySelector( '.pf-events-map-empty' );

		var map = L.map( el, { scrollWheelZoom: false } );
		el._pfMap = map; // exposé pour débogage / tests.

		L.tileLayer( data.tileUrl, {
			attribution: data.attribution,
			maxZoom: 19
		} ).addTo( map );

		// Dégagements verticaux de l'infobulle (en px, résolus depuis les tokens).
		// Portés par l'option `offset` de Leaflet (et non un `bottom` CSS) : ainsi
		// l'auto-pan, qui calcule le débordement à partir de sa propre géométrie
		// interne, connaît la vraie position de l'infobulle et la repositionne
		// correctement (un override CSS lui reste invisible → il sous-descendait).
		var GAP_PIN     = pxVar( '--pf-space-2' ); // infobulle ↔ pin simple
		var AUTOPAN_PAD = pxVar( '--pf-space-2' ); // marge conservée par l'auto-pan (haut de carte ↔ haut d'infobulle)

		// Molette : n'active le zoom qu'après un clic sur la carte (ne détourne
		// pas le scroll de la page tant que l'utilisateur n'a pas ciblé la carte).
		map.on( 'focus', function () { map.scrollWheelZoom.enable(); } );
		map.on( 'blur', function () { map.scrollWheelZoom.disable(); } );

		var icon = L.divIcon( {
			className:   'pf-map-pin',
			html:        '<svg class="pf-map-pin__svg" viewBox="0 0 28 38" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">'
				+ '<path d="M14 2C8 2 3 7 3 13c0 8 11 24 11 24s11-16 11-24C25 7 20 2 14 2Z"/>'
				+ '<circle cx="14" cy="13" r="4.6"/></svg>',
			iconSize:    [ 28, 38 ],
			iconAnchor:  [ 14, 37 ],
			popupAnchor: [ 0, -34 ]
		} );

		var currentLayer = null;

		// Débordement vertical : les cards d'événement sont des tuiles (.pf-event-tile)
		// en RANGÉE horizontale par lieu → hauteur d'infobulle bornée (une rangée +
		// max-height plafonnée de .pf-map-pop__scroll, cf. events-map.css). L'auto-pan
		// natif de Leaflet étant faussé ici (pointe masquée + offset → il croit que
		// l'infobulle tient et ne descend pas assez), il est désactivé (autoPan:false)
		// au profit d'un recentrage maison mesuré sur les rectangles réels.
		map.on( 'popupopen', function ( e ) {
			// Ombres de bord horizontales sur les rangées de tuiles.
			wireScrollFade( e.popup._container );
			// Recentrage de l'infobulle dans la carte (rAF : après mise en page).
			requestAnimationFrame( function () { panPopupIntoView( map, el, e.popup, AUTOPAN_PAD ); } );
		} );

		// (Re)construit la couche de marqueurs à partir d'une liste (jeu complet ou
		// sous-ensemble filtré par la recherche). `emptyText` : message affiché si la
		// liste est vide (repli initial ou « aucun résultat »).
		function renderMarkers( list, emptyText ) {
			if ( currentLayer ) {
				map.removeLayer( currentLayer );
				currentLayer = null;
			}

			if ( ! list.length ) {
				map.setView( [ fv[ 0 ], fv[ 1 ] ], fv[ 2 ] );
				if ( emptyEl ) {
					emptyEl.textContent = emptyText || data.emptyText || '';
					emptyEl.hidden = false;
				}
				return;
			}
			if ( emptyEl ) { emptyEl.hidden = true; }

			// Couche de regroupement : un clic sur cluster zoome jusqu'à ce qu'il se
			// scinde ; spiderfy si les lieux sont co-localisés. Repli sur des marqueurs
			// simples si le plugin n'est pas chargé.
			// zoomToBoundsOnClick/spiderfyOnMaxZoom désactivés : le clic est géré entièrement
			// à la main (cf. handler plus bas). Les deux tournaient sinon en parallèle du
			// handler maison — la lib zoomant de son côté vers son maxZoom pendant qu'on
			// zoomait nous-mêmes vers le niveau ville — et la double transition qui en
			// résultait laissait le spiderfy s'ouvrir puis se refermer aussitôt tout seul.
			var isCluster = typeof L.markerClusterGroup === 'function';
			var layer = isCluster
				? L.markerClusterGroup( {
					maxClusterRadius:    60,
					showCoverageOnHover: false,
					zoomToBoundsOnClick: false,
					spiderfyOnMaxZoom:   false,
					iconCreateFunction:  clusterIcon
				} )
				: L.layerGroup();

			var latlngs = [];
			list.forEach( function ( m ) {
				var mk = L.marker( [ m.lat, m.lng ], {
					icon:   icon,
					title:  m.venue || '',
					pfData: m
				} );
				var solo = ( m.events || [] ).length === 1;
				mk.bindPopup( solo ? soloPopup( m ) : singlePopup( m ), {
					// autoPan désactivé → recentrage maison au popupopen (panPopupIntoView).
					// minWidth:0 → Leaflet dimensionne à la largeur naturelle du contenu,
					// bornée par maxWidth : à ≥2 événements la rangée déborde et l'infobulle
					// est plafonnée à maxWidth ; à 1 événement la tuile solo impose sa propre
					// largeur (250px, cf. events-map.css) via pf-map-pop-solo.
					className: solo ? 'pf-map-pop-solo' : '',
					minWidth: 0, maxWidth: 300, closeButton: true, autoPan: false,
					offset: [ 0, -GAP_PIN ]
				} );
				layer.addLayer( mk );
				latlngs.push( [ m.lat, m.lng ] );
			} );

			// Clic sur un cluster : trois cas, gérés entièrement à la main (options
			// natives désactivées, cf. commentaire ci-dessus).
			// - Déjà spiderfié (reclic) : referme sans zoomer, cf. ⚠️ juste en dessous.
			// - Réductible (les lieux se sépareront à un zoom suffisant) : zoomToBounds(),
			//   exactement ce que la lib aurait fait nativement — simple relais.
			// - Co-localisé (isColocated, coordonnées identiques) : zoome au niveau ville
			//   (+2 crans, cf. discussion) puis spiderfie, plutôt que de spiderfier sur
			//   place à la vue courante (souvent la France entière — sans contexte utile).
			//   `getVisibleParent()` est relu APRÈS le zoom (le `cluster` capturé au clic
			//   est périmé dès que la lib reconstruit son arbre pour le nouveau zoom) —
			//   même mécanisme que celui qu'utilise en interne `zoomToShowLayer`.
			//   ⚠️ Ne pas repasser à `cluster.zoomToBounds()` sans options : la lib calcule
			//   son zoom sans marge et colle les repères scindés aux bords (cf. zoomToCluster).
			//   ⚠️ `moveend` seul ne suffit pas : il peut se déclencher AVANT la fin du
			//   décompte interne `_inZoomAnimation` de la lib, auquel cas `spiderfy()`
			//   s'auto-annule silencieusement (son garde-fou interne) — d'où le double
			//   abonnement `moveend`/`animationend` avec re-vérification du drapeau à
			//   chaque déclenchement (l'un des deux finit par tomber sur `_inZoomAnimation`
			//   réellement à 0).
			// ⚠️ Le cas « déjà spiderfié » doit être vérifié EN PREMIER, avant isColocated() :
			// spiderfy() déplace réellement les marqueurs enfants vers leurs positions en
			// éventail (`setLatLng`), donc un reclic sur un cluster spiderfié les verrait
			// comme non co-localisés et tomberait dans zoomToBounds() — fitBounds sur des
			// points désormais artificiellement écartés, zoomant jusqu'au maxZoom réel de la
			// carte (19) au lieu de refermer sans bouger.
			// Garde clavier alignée sur celle de la lib (_zoomOrSpiderfy) : une touche non-
			// Entrée sur `clusterkeypress` ne doit rien déclencher (un clic souris n'a pas
			// de keyCode, la garde ne le concerne donc pas).
			if ( isCluster ) {
				layer.on( 'clusterclick clusterkeypress', function ( e ) {
					if ( e.originalEvent && 'keyCode' in e.originalEvent && 13 !== e.originalEvent.keyCode ) {
						return;
					}
					var cluster = e.layer;
					if ( layer._spiderfied === cluster ) {
						cluster.unspiderfy();
					} else if ( ! isColocated( cluster ) ) {
						zoomToCluster( map, cluster );
					} else {
						var ready = function () {
							if ( layer._inZoomAnimation ) { return; }
							map.off( 'moveend', ready );
							layer.off( 'animationend', ready );
							var kid = cluster.getAllChildMarkers()[ 0 ];
							var visible = kid && layer.getVisibleParent( kid );
							if ( visible && visible !== kid ) {
								visible.spiderfy();
							}
						};
						map.on( 'moveend', ready );
						layer.on( 'animationend', ready );
						map.setView( cluster.getLatLng(), 15, { animate: true } );
					}
					if ( e.originalEvent && 13 === e.originalEvent.keyCode ) {
						map.getContainer().focus();
					}
				} );
			}

			map.addLayer( layer );
			currentLayer = layer;

			if ( latlngs.length === 1 ) {
				map.setView( latlngs[ 0 ], 13 );
			} else {
				// Même marge que zoomToCluster (EDGE_PAD_*) : une seule définition de
				// « marge de bord » dans ce fichier. Effet de bord assumé et minime : la
				// vue d'ouverture décale de 16px vers le bas (et est de toute façon
				// plafonnée par maxZoom:13 le plus souvent).
				map.fitBounds( latlngs, { paddingTopLeft: EDGE_PAD_TL, paddingBottomRight: EDGE_PAD_BR, maxZoom: 13 } );
			}
		}

		renderMarkers( allMarkers );

		// La carte est parfois montée dans un conteneur dont la taille se stabilise
		// après le layout (barre sticky, polices) : on recalcule une fois.
		setTimeout( function () { map.invalidateSize(); }, 200 );

		initSearch( allMarkers, renderMarkers );
	}

	// Contrôleur de la barre de recherche carte. Interroge le moteur serveur partagé
	// (endpoint pf_events_map_search → IDs classés, à venir uniquement), filtre les
	// marqueurs sur ces IDs (n'affichant que les événements correspondants) et recadre.
	function initSearch( allMarkers, renderMarkers ) {
		var cfg   = window.PassifloreMap || {};
		var input = document.querySelector( '.pf-map-search-input' );
		if ( ! input || ! cfg.ajaxUrl ) { return; }

		var bar      = input.closest( '.pf-sub-header' );
		var clearBtn = document.querySelector( '.pf-map-search-clear' );
		var timer    = null;
		var seq      = 0; // ignore les réponses périmées (dernière requête gagne).

		// Placeholder court sur mobile (≤768px), long au-dessus — même mécanisme
		// et mêmes textes que la recherche liste (events-search.js) : au repos, un
		// simple « Événement » (la barre resserrée, à côté de « S'abonner », n'a
		// pas la place pour le texte complet) ; le détail ne s'affiche qu'au focus.
		var phMq      = window.matchMedia( '(max-width: 768px)' );
		var phDefault = input.getAttribute( 'placeholder' );
		function applyPlaceholder() {
			if ( ! input.dataset.placeholderSm ) { return; }
			if ( ! phMq.matches ) { input.placeholder = phDefault; return; }
			input.placeholder = ( document.activeElement === input ) ? input.dataset.placeholderSm : cfg.placeholderMobile;
		}
		applyPlaceholder();
		phMq.addEventListener( 'change', applyPlaceholder );

		// « is-searching » (focus ou texte saisi) — même mécanisme que la recherche
		// liste (events-search.js) / .pf-catalogue-bar côté /catalogue : sur mobile,
		// réduit le bouton « S'abonner » pendant que la recherche grandit dans
		// l'espace libéré (cf. events.css, règle partagée par vue).
		function refreshSearchState() {
			applyPlaceholder();
			if ( ! bar ) { return; }
			var hasText = input.value !== '';
			var focused = document.activeElement === input;
			bar.classList.toggle( 'is-searching', hasText || focused );
		}

		// Attente d'une réponse : filet accent au bas du sous-header (composant
		// partagé avec la recherche globale et la recherche liste, cf. style.css).
		function setLoading( on ) {
			if ( bar ) { bar.classList.toggle( 'is-loading', on ); }
		}

		function applyIds( ids ) {
			var idSet = {};
			( ids || [] ).forEach( function ( id ) { idSet[ id ] = true; } );

			var filtered = [];
			allMarkers.forEach( function ( m ) {
				var evs = ( m.events || [] ).filter( function ( e ) { return idSet[ e.id ]; } );
				if ( evs.length ) {
					filtered.push( Object.assign( {}, m, { events: evs } ) );
				}
			} );

			renderMarkers( filtered, cfg.searchEmptyText );
		}

		function run( q ) {
			var mine = ++seq;
			setLoading( true );
			fetch( cfg.ajaxUrl, {
				method:      'POST',
				headers:     { 'Content-Type': 'application/x-www-form-urlencoded' },
				credentials: 'same-origin',
				body:        'action=pf_events_map_search'
					+ '&search=' + encodeURIComponent( q )
			} ).then( function ( r ) {
				return r.json();
			} ).then( function ( res ) {
				if ( mine !== seq ) { return; } // une frappe plus récente est partie
				setLoading( false );
				applyIds( res && res.success && res.data ? res.data.ids : [] );
			} ).catch( function () {
				if ( mine !== seq ) { return; }
				setLoading( false );
				renderMarkers( allMarkers );
			} );
		}

		input.addEventListener( 'focus', refreshSearchState );
		input.addEventListener( 'blur', refreshSearchState );
		input.addEventListener( 'input', function () {
			refreshSearchState();
			var q = input.value.trim();
			clearTimeout( timer );
			if ( q.length < 2 ) {
				seq++; // invalide toute requête en vol
				setLoading( false );
				renderMarkers( allMarkers );
				return;
			}
			timer = setTimeout( function () { run( q ); }, 250 );
		} );

		if ( clearBtn ) {
			clearBtn.addEventListener( 'click', function () {
				input.value = '';
				refreshSearchState();
				input.focus();
				clearTimeout( timer );
				seq++;
				setLoading( false );
				renderMarkers( allMarkers );
			} );
		}
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
} )();
