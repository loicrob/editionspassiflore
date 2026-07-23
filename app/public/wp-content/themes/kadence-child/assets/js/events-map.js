/**
 * Vue « Carte » des événements — PROTOTYPE.
 *
 * Initialise une carte Leaflet dans #pf-events-map à partir des marqueurs
 * fournis par window.PassifloreMap (localisés côté PHP). Un point = un lieu ;
 * l'infobulle liste le ou les événements qui s'y déroulent.
 *
 * Regroupement (Leaflet.markercluster) : quand on dézoome, les lieux proches
 * fusionnent en un cluster. Le pin de cluster affiche le nombre d'ÉVÉNEMENTS
 * (pas de lieux) ; un clic ouvre une infobulle combinée dont l'en-tête est le
 * dénominateur commun des lieux (ville, sinon département, sinon région).
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

	function uniq( arr ) {
		return arr.filter( function ( v, i ) { return v && arr.indexOf( v ) === i; } );
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

	// Bloc d'un lieu : en-tête (nom + ville) + sa rangée de cards d'événement.
	// `areaLabel` (contexte cluster) : si la ville est identique au dénominateur
	// commun déjà affiché en en-tête, on masque la ville (évite « Dax » répété).
	function venueBlock( m, areaLabel ) {
		var showCity = m.city && ( ! areaLabel || m.city.toLowerCase() !== String( areaLabel ).toLowerCase() );
		var venue = '<div class="pf-map-pop__venue">' +
			'<span class="pf-map-pop__venue-name">' + esc( m.venue || '' ) + '</span>' +
			( showCity ? '<span class="pf-map-pop__venue-city">' + esc( m.city ) + '</span>' : '' ) +
		'</div>';
		return '<div class="pf-map-pop__group">' + venue + eventsRow( m ) + '</div>';
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

	// Dénominateur commun d'un ensemble de lieux : ville si toutes identiques,
	// sinon département, sinon région, sinon null (lieux disparates).
	function commonLabel( ms ) {
		var cities = uniq( ms.map( function ( m ) { return m.city; } ) );
		if ( cities.length === 1 ) { return cities[ 0 ]; }
		var depts = uniq( ms.map( function ( m ) { return m.dept; } ) );
		if ( depts.length === 1 ) { return depts[ 0 ]; }
		var regions = uniq( ms.map( function ( m ) { return m.region; } ) );
		if ( regions.length === 1 ) { return regions[ 0 ]; }
		return null;
	}

	// Infobulle combinée d'un cluster : en-tête = dénominateur commun + nombre
	// total d'événements, puis un bloc par lieu.
	function clusterPopup( childMarkers ) {
		var ms = childMarkers.map( function ( c ) { return c.options.pfData; } );

		var total = ms.reduce( function ( s, m ) {
			return s + ( ( m.events || [] ).length || 0 );
		}, 0 );

		var label = commonLabel( ms ) || 'Plusieurs lieux';
		var noun  = total > 1 ? 'événements' : 'événement';

		var header = '<div class="pf-map-pop__header">' +
			'<span class="pf-map-pop__area-label">' + esc( label ) + '</span>' +
			'<span class="pf-map-pop__area-count">' + total + ' ' + noun + '</span>' +
		'</div>';

		var groups = ms.map( function ( m ) { return venueBlock( m, label ); } ).join( '' );

		return '<div class="pf-map-pop pf-map-pop--cluster">' + header + '<div class="pf-map-pop__scroll">' + groups + '</div></div>';
	}

	// Pin d'un cluster : cercle rouge portant le nombre d'ÉVÉNEMENTS agrégés.
	function clusterIcon( cluster ) {
		var count = cluster.getAllChildMarkers().reduce( function ( s, m ) {
			return s + ( m.options.pfEventCount || 1 );
		}, 0 );

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
		var GAP_CLUSTER = pxVar( '--pf-space-6' ); // infobulle ↔ rond de cluster (plus haut)
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

			// Couche de regroupement : un clic sur cluster ouvre une infobulle combinée
			// (pas de zoom auto ni de spiderfy). Repli sur des marqueurs simples si le
			// plugin n'est pas chargé.
			var useCluster = typeof L.markerClusterGroup === 'function';
			var layer = useCluster
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
					icon:         icon,
					title:        m.venue || '',
					pfData:       m,
					pfEventCount: ( m.events || [] ).length || 1
				} );
				mk.bindPopup( singlePopup( m ), {
					// autoPan désactivé → recentrage maison au popupopen (panPopupIntoView).
					// minWidth:0 → Leaflet dimensionne à la largeur naturelle du contenu,
					// bornée par maxWidth : un lieu à 1 seul événement (rangée sans scroll
					// horizontal) rétrécit à la largeur d'une tuile + ses marges ; à ≥2
					// événements la rangée déborde et l'infobulle est plafonnée à maxWidth.
					minWidth: 0, maxWidth: 300, closeButton: true, autoPan: false,
					offset: [ 0, -GAP_PIN ]
				} );
				layer.addLayer( mk );
				latlngs.push( [ m.lat, m.lng ] );
			} );

			if ( useCluster ) {
				layer.on( 'clusterclick', function ( a ) {
					var cluster = a.layer;
					// Lier + ouvrir seulement au 1er clic. Aux clics suivants, le handler
					// de clic natif posé par bindPopup bascule l'infobulle (ouverte↔fermée)
					// tout seul — on ne rappelle donc PAS openPopup, sinon la fermeture
					// native (re-clic) serait aussitôt ré-ouverte par nous (fade-out/-in au
					// lieu d'une fermeture, comme pour un marqueur simple).
					if ( cluster.getPopup() ) {
						return;
					}
					cluster.bindPopup( clusterPopup( cluster.getAllChildMarkers() ), {
						// autoPan désactivé → recentrage maison au popupopen (panPopupIntoView).
						// minWidth:0 : rétrécit à la largeur d'une tuile si AUCUN lieu du cluster
						// n'a d'événements côte à côte ; sinon la rangée la plus large déborde et
						// plafonne l'infobulle à maxWidth (même mécanisme que le marqueur simple).
						minWidth: 0, maxWidth: 320, closeButton: true, autoPan: false,
						// Dégagement accru : le repère de cluster est un rond, plus haut qu'un pin.
						offset: [ 0, -GAP_CLUSTER ]
					} ).openPopup();
				} );
			}

			map.addLayer( layer );
			currentLayer = layer;

			if ( latlngs.length === 1 ) {
				map.setView( latlngs[ 0 ], 13 );
			} else {
				map.fitBounds( latlngs, { padding: [ 44, 44 ], maxZoom: 13 } );
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
				applyIds( res && res.success && res.data ? res.data.ids : [] );
			} ).catch( function () {
				if ( mine === seq ) { renderMarkers( allMarkers ); }
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
