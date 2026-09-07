/**
 * Carte de repositionnement du repère du lieu (champ « Position sur la
 * carte », cf. inc/venue-admin.php) — deux contextes : fiche lieu autonome
 * et mini-formulaire « Créer un nouveau lieu » de la fiche événement, même
 * distinction .linked-post qu'ailleurs dans ce module (venue-admin.js).
 *
 * Aperçu de géocodage à la frappe (endpoint pf_venue_geocode_preview) :
 * statut affiché (rue localisée / centre de commune / introuvable) + repère
 * repositionné automatiquement. Déplacer le repère (drag ou clic sur la
 * carte) bascule en mode manuel : coordonnées figées, l'adresse ne sert plus
 * qu'au texte affiché (cf. Passiflore_Events_Map::geocode_venue()).
 */
( function ( $ ) {

	// Même pin que la vue carte publique (events-map.js), en classes propres
	// (style.css n'est pas chargé en admin, cf. inline style de
	// pf_enqueue_venue_admin_assets).
	var PIN_HTML = '<svg class="pf-venue-geo-pin__svg" viewBox="0 0 28 38" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">'
		+ '<path d="M14 2C8 2 3 7 3 13c0 8 11 24 11 24s11-16 11-24C25 7 20 2 14 2Z"/>'
		+ '<circle cx="14" cy="13" r="4.6"/></svg>';

	// Champs source de l'adresse : #EventZip / #EventCountry sont les MÊMES ids
	// dans les deux contextes (TEC réutilise son template venue-meta-box.php),
	// donc pas de scoping nécessaire ; Adresse/Ville en revanche ont un id
	// seulement en fiche autonome, un name="venue[Champ][]" en inline (même
	// distinction que wireNameIsAddress() dans venue-admin.js).
	function fieldsFor( inline ) {
		return {
			$street:  inline ? $( 'input[name="venue[Address][]"]' ) : $( '#venueAddress' ),
			$city:    inline ? $( 'input[name="venue[City][]"]' )    : $( '#venueCity' ),
			$zip:     $( '#EventZip' ),
			$country: $( '#EventCountry' )
		};
	}

	function setStatus( $status, state, text ) {
		$status.attr( 'data-status', state ).text( text );
	}

	function initPicker( $map ) {
		if ( $map.data( 'pfInited' ) ) return;
		$map.data( 'pfInited', true );

		var $row     = $map.closest( 'tr' );
		var inline   = $row.hasClass( 'linked-post' );
		var $status  = $row.find( '.pf-venue-geo-status' );
		var $resetBtn       = $row.find( '.pf-venue-geo-reset' );
		var $latInput       = $row.find( '.pf-venue-geo-lat' );
		var $lngInput       = $row.find( '.pf-venue-geo-lng' );
		var $manualInput    = $row.find( '.pf-venue-geo-manual' );
		var $keyInput       = $row.find( '.pf-venue-geo-key' );
		var $precisionInput = $row.find( '.pf-venue-geo-precision' );

		var f = fieldsFor( inline );

		var lat       = parseFloat( $map.attr( 'data-lat' ) );
		var lng       = parseFloat( $map.attr( 'data-lng' ) );
		var precision = $map.attr( 'data-precision' ) || '';
		var hasCoords = ! isNaN( lat ) && ! isNaN( lng );

		// Clé d'adresse associée à (lat, lng, precision) ci-dessus — mise à jour
		// UNIQUEMENT en même temps qu'eux (jamais recalculée seule au submit,
		// cf. plus bas) : sans cette règle, un envoi pendant qu'un aperçu est en
		// vol pourrait réimposer une clé fraîche appariée à des coordonnées
		// périmées, ce qui ferait *matcher* pf_venue_geo_key() côté serveur et
		// persisterait la mauvaise position — exactement la dérive que ce
		// mécanisme sert à éliminer.
		var geoKey = '';

		var icon = L.divIcon( {
			className: 'pf-venue-geo-pin',
			html:      PIN_HTML,
			iconSize:  [ 28, 38 ],
			iconAnchor: [ 14, 37 ]
		} );

		var map = L.map( $map[ 0 ], { scrollWheelZoom: false, zoomControl: true } );
		L.tileLayer( window.pfVenueGeo.tileUrl, { attribution: window.pfVenueGeo.attribution, maxZoom: 19 } ).addTo( map );
		map.on( 'focus', function () { map.scrollWheelZoom.enable(); } );
		map.on( 'blur',  function () { map.scrollWheelZoom.disable(); } );

		var marker = null;

		function showMarker( la, ln, zoom ) {
			if ( marker ) {
				marker.setLatLng( [ la, ln ] );
			} else {
				marker = L.marker( [ la, ln ], { icon: icon, draggable: true, keyboard: false } ).addTo( map );
				marker.on( 'dragend', function () {
					var pos = marker.getLatLng();
					enterManual( pos.lat, pos.lng );
				} );
			}
			map.setView( [ la, ln ], zoom );
		}

		map.on( 'click', function ( e ) {
			enterManual( e.latlng.lat, e.latlng.lng );
		} );

		function enterManual( la, ln ) {
			lat = la; lng = ln; precision = 'manual';
			$latInput.val( la );
			$lngInput.val( ln );
			$manualInput.val( '1' );
			showMarker( la, ln, 15 );
			$resetBtn.prop( 'hidden', false );
			setStatus( $status, 'manual', window.pfVenueGeo.i18n.manual );
		}

		$resetBtn.on( 'click', function () {
			$manualInput.val( '' );
			$resetBtn.prop( 'hidden', true );
			precision = ''; // sinon le garde-fou de soumission inline réarme le mode manuel
			lastQuery = null; // force une nouvelle requête même adresse inchangée
			geoKey = ''; // sinon une clé encore valide persisterait les coordonnées manuelles abandonnées si l'aperçu qui suit échoue
			$keyInput.val( '' );
			runPreview();
		} );

		// Vue initiale : coordonnées déjà en cache (fiche existante) ou vue
		// France en attendant une adresse (nouveau lieu).
		if ( hasCoords ) {
			showMarker( lat, lng, 14 );
			if ( 'manual' === precision ) {
				$resetBtn.prop( 'hidden', false );
				setStatus( $status, 'manual', window.pfVenueGeo.i18n.manual );
			} else if ( 'street' === precision ) {
				setStatus( $status, 'street', window.pfVenueGeo.i18n.street.replace( '%s', '' ) );
			} else if ( 'city' === precision ) {
				setStatus( $status, 'city', window.pfVenueGeo.i18n.city.replace( '%s', '' ) );
			}
		} else {
			map.setView( window.pfVenueGeo.franceView.slice( 0, 2 ), window.pfVenueGeo.franceView[ 2 ] );
		}

		// Un simple ré-enregistrement sans rien toucher doit conserver les
		// coordonnées existantes : la clé soumise doit donc être calculée par le
		// même algorithme que côté serveur (pf_venue_geo_key()) dès l'ouverture
		// du formulaire, pas seulement après un nouvel aperçu.
		geoKey = currentKey();
		$keyInput.val( geoKey );
		$precisionInput.val( precision );

		// Aperçu à la frappe : debounce 900 ms sur input, immédiat au blur, une
		// seule requête par adresse distincte (mémo). Tourne aussi en mode
		// manuel (pour renseigner Ville/CP/Département/Région), sans jamais y
		// retoucher au repère ni au statut (cf. runPreview()).
		var lastQuery      = null;
		var previewTimer   = null;
		var settingCountry = false;

		function currentKey() {
			return [ f.$street.val() || '', f.$city.val() || '', f.$zip.val() || '', f.$country.val() || '' ].join( '|' );
		}

		// Pays : ne s'applique que si une <option> porte exactement cette valeur
		// (comparaison en .filter() sur la value, pas un sélecteur d'attribut —
		// des noms de pays contiennent parenthèses/apostrophes). .trigger('change')
		// est indispensable pour que Select2 (tribe-dropdown) redessine son
		// libellé ; le drapeau settingCountry borne le runPreview() qu'il
		// redéclenche autrement en cascade (jQuery exécute les handlers de
		// .trigger() de façon synchrone).
		function setCountry( value ) {
			var exists = f.$country.find( 'option' ).filter( function () { return this.value === value; } ).length > 0;
			if ( ! exists ) return;
			settingCountry = true;
			f.$country.val( value ).trigger( 'change' );
			settingCountry = false;
		}

		// Applique les champs déduits du géocodage (cf. pf_venue_admin_fields_from_geocode()) :
		// Ville/CP/Pays seulement si vides (alimentent la requête, ne pas
		// écraser une saisie en cours) ; Département/Région toujours rafraîchis
		// (comme la cascade CP→Département existante). .val() nu, sans
		// .trigger('input'), pour Ville/CP : ça relancerait cette même cascade,
		// moins précise que le département qu'on vient de résoudre.
		function applyFields( fields ) {
			if ( ! fields ) return;

			if ( fields.city && ! f.$city.val() ) f.$city.val( fields.city );
			if ( fields.zip && ! f.$zip.val() ) f.$zip.val( fields.zip );
			if ( fields.country && ! f.$country.val() ) setCountry( fields.country );

			if ( fields.departement && window.pfVenueCombos && window.pfVenueCombos.departements ) {
				window.pfVenueCombos.departements.confirm( fields.departement );
			} else if ( fields.region && window.pfVenueCombos && window.pfVenueCombos.regions ) {
				window.pfVenueCombos.regions.confirm( fields.region );
			}

			// Recalcule le mémo depuis les valeurs de champs courantes : la ville
			// et le CP qu'on vient d'écrire changeraient sinon la clé et
			// provoqueraient un aller-retour redondant au prochain blur — un vrai
			// garde-fou ici (Ville est un champ écrit ET lu par la requête), pas
			// une optimisation : sans lui on boucle.
			lastQuery = currentKey();
		}

		function runPreview() {
			if ( settingCountry ) return;

			var street  = f.$street.val()  || '';
			var city    = f.$city.val()    || '';
			var zip     = f.$zip.val()     || '';
			var country = f.$country.val() || '';

			if ( ! city && ! zip ) return;

			var key = currentKey();
			if ( key === lastQuery ) return;
			lastQuery = key;

			var manual = '1' === $manualInput.val();
			if ( ! manual ) {
				setStatus( $status, 'loading', window.pfVenueGeo.i18n.loading );
			}

			$.post( window.pfVenueGeo.ajaxUrl, {
				action:  'pf_venue_geocode_preview',
				nonce:   window.pfVenueGeo.nonce,
				street:  street,
				city:    city,
				zip:     zip,
				country: country
			} ).done( function ( res ) {
				// Relu au moment de la réponse, pas de la requête : couvre un
				// passage en/hors mode manuel pendant le vol de la requête.
				var stillManual = '1' === $manualInput.val();

				if ( ! res || ! res.success ) {
					if ( ! stillManual ) setStatus( $status, 'error', window.pfVenueGeo.i18n.error );
					return;
				}
				var data = res.data;
				if ( ! data.found ) {
					if ( ! stillManual ) setStatus( $status, 'notfound', window.pfVenueGeo.i18n.notfound );
					return;
				}

				if ( ! stillManual ) {
					precision = data.precision;
					lat = data.lat; lng = data.lng;
					$latInput.val( lat );
					$lngInput.val( lng );
					if ( 'street' === precision ) {
						showMarker( lat, lng, 16 );
						setStatus( $status, 'street', window.pfVenueGeo.i18n.street.replace( '%s', data.label || '' ) );
					} else {
						showMarker( lat, lng, 13 );
						setStatus( $status, 'city', window.pfVenueGeo.i18n.city.replace( '%s', data.label ? ' : ' + data.label : '' ) );
					}
				}

				applyFields( data.fields );

				// Après applyFields() : elle peut écrire Ville/Pays, et une clé
				// calculée avant ne correspondrait plus aux metas enregistrées
				// (mismatch → repli sur le géocodage serveur, la dérive qu'on
				// supprime ici). Seulement hors mode manuel : en mode manuel, le
				// repère affiché n'est pas celui que renvoie cet aperçu. geoKey
				// capturée ici même (pas recalculée plus tard) pour rester
				// appariée à ce lat/lng précis, y compris au submit (cf. plus bas).
				if ( ! stillManual ) {
					geoKey = currentKey();
					$keyInput.val( geoKey );
					$precisionInput.val( precision );
				}
			} ).fail( function () {
				if ( '1' !== $manualInput.val() ) {
					setStatus( $status, 'error', window.pfVenueGeo.i18n.error );
				}
			} );
		}

		f.$street.on( 'input', debounced ).on( 'blur', runPreview );
		f.$city.on( 'input', debounced ).on( 'blur', runPreview );
		f.$zip.on( 'input', debounced ).on( 'blur', runPreview );
		f.$country.on( 'change', runPreview );

		function debounced() {
			clearTimeout( previewTimer );
			previewTimer = setTimeout( runPreview, 900 );
		}

		// Fiche événement uniquement : le JS natif TEC remet value="" sur tous
		// les <input> des lignes .linked-post au change du sélecteur de lieu
		// (même gotcha que la case « nom = adresse », cf. venue-admin.js) —
		// réimposé juste avant l'envoi, depuis l'état JS courant (le repère est
		// la source de vérité, pas les hidden inputs qui ont pu être vidés).
		if ( inline ) {
			var $form = $map.closest( 'form' );
			if ( $form.length ) {
				$form.on( 'submit', function () {
					if ( isNaN( lat ) || isNaN( lng ) ) return;
					$latInput.val( lat );
					$lngInput.val( lng );
					$manualInput.val( 'manual' === precision ? '1' : '' );
					// geoKey réimposée telle quelle (jamais recalculée ici) : elle
					// doit rester appariée à `lat`/`lng` ci-dessus, capturés au même
					// instant qu'elle (cf. sa déclaration en tête de fonction) — la
					// recalculer depuis les champs à cet instant referait courir le
					// risque qu'un envoi pendant un aperçu en vol associe une clé
					// fraîche à des coordonnées périmées.
					$keyInput.val( geoKey );
					$precisionInput.val( precision );
				} );
			}
		}
	}

	// Lignes .linked-post : rendues dans le DOM mais masquées jusqu'au clic sur
	// « Créer un lieu » (wireCreateButton(), venue-admin.js). Un conteneur
	// display:none donne à Leaflet une taille nulle au moment de la création de
	// la carte — on diffère donc entièrement l'init jusqu'à la première
	// visibilité réelle plutôt que de tenter un invalidateSize() a posteriori.
	function observe( $map ) {
		if ( $map.data( 'pfObserved' ) ) return;
		$map.data( 'pfObserved', true );

		var inline = $map.closest( 'tr' ).hasClass( 'linked-post' );

		if ( ! inline || ! ( 'IntersectionObserver' in window ) ) {
			initPicker( $map );
			return;
		}

		var io = new IntersectionObserver( function ( entries ) {
			entries.forEach( function ( entry ) {
				if ( entry.isIntersecting ) {
					initPicker( $map );
					io.disconnect();
				}
			} );
		} );
		io.observe( $map[ 0 ] );
	}

	function run() {
		if ( ! window.pfVenueGeo || typeof L === 'undefined' ) return;
		$( '.pf-venue-geo-map' ).each( function () { observe( $( this ) ); } );
	}

	// Même motif que pfVenueAdminRun() (venue-admin.js) : le clonage du
	// mini-formulaire côté TEC tourne dans son propre document.ready, ordre non
	// garanti avec le nôtre — on rejoue donc aussi au window load.
	jQuery( document ).ready( run );
	jQuery( window ).on( 'load', run );

} )( jQuery );
