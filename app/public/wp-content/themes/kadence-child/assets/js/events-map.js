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

	// Mini-carte d'événement : réutilise le système .pf-card global (variante
	// --static --compact), passée en disposition horizontale via events-map.css.
	function eventCard( e ) {
		var media = e.thumb
			? '<span class="pf-map-pop-card__media"><img src="' + encodeURI( e.thumb ) + '" alt="" loading="lazy"></span>'
			: '';
		return '<a class="pf-card pf-card--static pf-card--compact pf-map-pop-card" href="' + encodeURI( e.url ) + '">' +
			media +
			'<span class="pf-card-content">' +
				'<span class="pf-card-title">' + esc( e.title ) + '</span>' +
				( e.date ? '<span class="pf-card-text">' + esc( e.date ) + '</span>' : '' ) +
			'</span>' +
		'</a>';
	}

	// Bloc d'un lieu : en-tête (nom + ville) + ses cartes d'événement.
	// `areaLabel` (contexte cluster) : si la ville est identique au dénominateur
	// commun déjà affiché en en-tête, on masque la ville (évite « Dax » répété).
	function venueBlock( m, areaLabel ) {
		var showCity = m.city && ( ! areaLabel || m.city.toLowerCase() !== String( areaLabel ).toLowerCase() );
		var venue = '<div class="pf-map-pop__venue">' +
			'<span class="pf-map-pop__venue-name">' + esc( m.venue || '' ) + '</span>' +
			( showCity ? '<span class="pf-map-pop__venue-city">' + esc( m.city ) + '</span>' : '' ) +
		'</div>';
		var cards = ( m.events || [] ).map( eventCard ).join( '' );
		return '<div class="pf-map-pop__group">' + venue + cards + '</div>';
	}

	// Infobulle d'un lieu unique : titre (nom + ville) hors zone de scroll,
	// seule la liste d'événements défile (cf. .pf-map-pop__header / __scroll).
	function singlePopup( m ) {
		var header = '<div class="pf-map-pop__header">' +
			'<span class="pf-map-pop__venue-name">' + esc( m.venue || '' ) + '</span>' +
			( m.city ? '<span class="pf-map-pop__venue-city">' + esc( m.city ) + '</span>' : '' ) +
		'</div>';
		var cards = ( m.events || [] ).map( eventCard ).join( '' );
		return '<div class="pf-map-pop">' + header + '<div class="pf-map-pop__scroll">' + cards + '</div></div>';
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

		// Filet de sécurité : sur un conteneur carte bas (mobile notamment), une
		// infobulle de cluster à plusieurs événements peut dépasser du haut du
		// conteneur (rogné par son overflow:hidden) — l'auto-pan de Leaflet ne suffit
		// pas toujours (position du marqueur trop haute pour le dégagement requis).
		// On mesure le dépassement RÉEL une fois l'infobulle positionnée (source de
		// vérité unique, valable quels que soient le zoom/pan courants) et on réduit
		// la hauteur de la seule zone de scroll (.pf-map-pop__scroll) d'autant — le
		// titre (.pf-map-pop__header) reste hors de cet ajustement, donc toujours visible.
		map.on( 'popupopen', function ( e ) {
			var popupEl = e.popup._container;
			var content = popupEl && popupEl.querySelector( '.pf-map-pop__scroll' );
			if ( ! content ) { return; }

			var margin = 20;
			var overflow = el.getBoundingClientRect().top - popupEl.getBoundingClientRect().top + margin;
			if ( overflow <= 0 ) { return; }

			// height (et non max-height) + overflow-y explicites, SANS rappeler
			// _updateLayout() de Leaflet : cette méthode s'applique à .leaflet-popup-content
			// (pas à notre zone de scroll interne) et n'a donc plus lieu d'être invoquée ici.
			content.style.height = Math.max( 120, content.offsetHeight - overflow ) + 'px';
			e.popup._updatePosition();

			// _updatePosition() déplace le popup en fonction de sa nouvelle taille,
			// mais le rapport hauteur-retirée ↔ décalage obtenu n'est pas garanti
			// strictement 1:1 (arrondis, chrome du popup) : une seconde mesure/passe
			// referme tout résidu de dépassement.
			var residual = el.getBoundingClientRect().top - popupEl.getBoundingClientRect().top + margin;
			if ( residual > 0 ) {
				content.style.height = Math.max( 100, content.offsetHeight - residual ) + 'px';
				e.popup._updatePosition();
			}
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
					minWidth: 220, maxWidth: 300, closeButton: true,
					autoPan: false // l'auto-pan natif de Leaflet, exécuté après coup, entre en
					// conflit avec l'ajustement de hauteur du handler popupopen ci-dessous.
				} );
				layer.addLayer( mk );
				latlngs.push( [ m.lat, m.lng ] );
			} );

			if ( useCluster ) {
				layer.on( 'clusterclick', function ( a ) {
					var cluster = a.layer;
					cluster.bindPopup( clusterPopup( cluster.getAllChildMarkers() ), {
						minWidth: 240, maxWidth: 320, closeButton: true,
						autoPan: false
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
					+ '&nonce=' + encodeURIComponent( cfg.searchNonce || '' )
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
