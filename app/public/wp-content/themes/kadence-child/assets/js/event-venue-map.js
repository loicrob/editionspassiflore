/**
 * Mini-carte mono-lieu de la fiche événement (section « Lieu »). Leaflet
 * auto-hébergé + tuiles OSM ; un seul marqueur (le lieu de l'événement).
 * Coordonnées lues sur le conteneur (#pf-event-venue-map[data-lat/lng]),
 * déjà géocodées côté serveur (cache post meta) — aucun appel réseau ajouté
 * ici hormis les tuiles OSM (comme la vue carte). Pin identique à events-map.js.
 */
(function () {
	var el = document.getElementById('pf-event-venue-map');
	if (!el || typeof L === 'undefined' || typeof PassifloreVenueMap === 'undefined') return;

	var lat = parseFloat(el.getAttribute('data-lat'));
	var lng = parseFloat(el.getAttribute('data-lng'));
	if (isNaN(lat) || isNaN(lng)) return;

	var map = L.map(el, {
		scrollWheelZoom: false,       // activé seulement au focus (voir plus bas)
		zoomControl: true
	}).setView([lat, lng], 14);

	L.tileLayer(PassifloreVenueMap.tileUrl, {
		attribution: PassifloreVenueMap.attribution,
		maxZoom: 19
	}).addTo(map);

	// Même pin que la vue carte (events-map.js) → cohérence visuelle.
	var icon = L.divIcon({
		className:   'pf-map-pin',
		html:        '<svg class="pf-map-pin__svg" viewBox="0 0 28 38" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">'
			+ '<path d="M14 2C8 2 3 7 3 13c0 8 11 24 11 24s11-16 11-24C25 7 20 2 14 2Z"/>'
			+ '<circle cx="14" cy="13" r="4.6"/></svg>',
		iconSize:    [ 28, 38 ],
		iconAnchor:  [ 14, 37 ]
	});
	L.marker([lat, lng], { icon: icon, keyboard: false }).addTo(map);

	// Molette : zoom seulement quand la carte a le focus (évite de capturer le
	// scroll de la page au survol) — même comportement que la vue carte.
	map.on('focus', function () { map.scrollWheelZoom.enable(); });
	map.on('blur',  function () { map.scrollWheelZoom.disable(); });
})();
