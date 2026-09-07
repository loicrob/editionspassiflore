/**
 * Combobox à choix contraint pour les champs Département / Région de la
 * fiche lieu (tribe_venue) — cf. inc/venue-admin.php.
 *
 * Liste préchargée (pfVenueAdmin.departements / .regions), filtrée à la
 * frappe, affichée dès le focus. La valeur n'est retenue que si elle
 * correspond exactement à une entrée de la liste (ou est vide) ; sinon elle
 * est ré-écrasée par la dernière valeur valide au blur.
 *
 * Cascade d'auto-remplissage : Code postal → Département (table de
 * correspondance locale, Corse exclue) → Région (relation exacte à 100%,
 * appliquée aussi quand Département est choisi/tapé manuellement).
 *
 * Deux contextes rendent ces champs : la fiche lieu autonome (statique, tout
 * est déjà dans le DOM au chargement) et le mini-formulaire « Créer un
 * nouveau lieu » de la fiche événement (cloné par le JS natif de TEC,
 * events-admin.js, à l'intérieur de SON PROPRE document.ready — sans
 * dépendance de script explicite, l'ordre d'exécution entre les deux
 * document.ready n'est pas garanti). D'où pfVenueAdminRun(), rejouée une
 * seconde fois au window load (après tous les document.ready) avec des
 * garde-fous d'idempotence, pour couvrir ce second contexte de façon fiable.
 */
( function ( $ ) {

	// Miroir JS de pf_search_normalize() (inc/search.php), comme dans
	// book-picker.js : minuscules, sans accents ni ligatures, ponctuation
	// réduite à des espaces.
	function normalize( s ) {
		return ( s == null ? '' : '' + s ).toLowerCase()
			.replace( /œ/g, 'oe' ).replace( /æ/g, 'ae' )
			.normalize( 'NFD' ).replace( /[\u0300-\u036f]/g, '' )
			.replace( /[^a-z0-9]+/g, ' ' ).trim();
	}

	function initCombos() {
		// Par field ('departements' | 'regions') → { confirm(value) }, pour que
		// la cascade code postal / département puisse fixer une valeur depuis
		// l'extérieur du combobox exactement comme le ferait l'utilisateur.
		// Objet local à chaque passe : au 2e passage (window load), seuls les
		// combos pas encore initialisés (garde pfInited) y sont ajoutés, ce qui
		// reste cohérent puisque la cascade code postal ci-dessous est câblée
		// dans la même passe.
		var combos = {};

		$( '.pf-venue-combo' ).each( function () {
			var $wrap = $( this );
			if ( $wrap.data( 'pfInited' ) ) return;
			$wrap.data( 'pfInited', true );

			var field   = $wrap.data( 'field' );
			var options = window.pfVenueAdmin[ field ] || [];
			options.forEach( function ( o ) { o._n = normalize( o.label ); } );

			var $input    = $wrap.find( 'input' );
			var $menu     = $wrap.find( '.pf-venue-combo-menu' );
			var lastValid = $input.val();

			function filtered( q ) {
				var qjoin = normalize( q ).replace( / /g, '' );
				if ( ! qjoin ) return options;
				return options.filter( function ( o ) {
					return o._n.replace( / /g, '' ).indexOf( qjoin ) !== -1;
				} );
			}

			function render( list ) {
				$menu.empty();
				if ( ! list.length ) {
					$menu.append( $( '<li>' ).addClass( 'pf-venue-combo-empty' ).text( 'Aucun résultat.' ) );
				} else {
					list.forEach( function ( o ) {
						$( '<li>' ).text( o.label ).attr( 'data-value', o.value ).appendTo( $menu );
					} );
				}
				$menu.show();
			}

			// Fixe une valeur validée (clic, blur valide, ou cascade externe) et
			// répercute sur Région quand c'est Département qui vient de changer.
			function confirm( value ) {
				lastValid = value;
				$input.val( value );
				$menu.hide();
				if ( field === 'departements' ) syncRegionFromDept( value );
			}

			combos[ field ] = { confirm: confirm };

			// Poignée stable inter-fichiers (venue-geo-picker.js) : `combos` est local à
			// chaque passe de pfVenueAdminRun(), cette référence-ci leur survit.
			window.pfVenueCombos = window.pfVenueCombos || {};
			window.pfVenueCombos[ field ] = combos[ field ];

			$input.on( 'focus input', function () { render( filtered( $input.val() ) ); } );

			// mousedown + preventDefault : évite que le blur de l'input ne ferme
			// le menu avant que le clic sur l'option n'ait été traité.
			$menu.on( 'mousedown', 'li[data-value]', function ( e ) {
				e.preventDefault();
				confirm( $( this ).attr( 'data-value' ) );
			} );

			$input.on( 'blur', function () {
				setTimeout( function () {
					var v = $input.val();
					if ( v === '' || options.some( function ( o ) { return o.value === v; } ) ) {
						confirm( v );
					} else {
						$input.val( lastValid );
					}
					$menu.hide();
				}, 150 );
			} );

			$input.on( 'keydown', function ( e ) {
				if ( e.key === 'Escape' ) {
					$input.val( lastValid );
					$menu.hide();
					$input.trigger( 'blur' );
				}
			} );
		} );

		// Région ← Département : relation exacte (chaque département n'appartient
		// qu'à une seule région) → toujours synchronisée, y compris quand
		// Département vient lui-même d'être déduit du code postal ci-dessous.
		function syncRegionFromDept( deptValue ) {
			if ( ! combos.regions ) return;
			var region = ( window.pfVenueAdmin.deptToRegion || {} )[ deptValue ] || '';
			combos.regions.confirm( region );
		}

		// Département ← Code postal : préfixe à 3 chiffres pour l'outre-mer
		// (971-976), sinon 2 chiffres (= code département dans l'immense
		// majorité des cas). Corse (20xxx) volontairement absente de la table
		// PHP (2A/2B non déductibles du préfixe) → aucun remplissage, laissé à
		// la sélection manuelle plutôt que de deviner faux.
		var $zip = $( '#EventZip' );
		if ( $zip.length && ! $zip.data( 'pfInited' ) && combos.departements ) {
			$zip.data( 'pfInited', true );
			$zip.on( 'input blur', function () {
				var digits = ( $zip.val() || '' ).replace( /\D/g, '' );
				if ( digits.length !== 5 ) return;
				var map  = window.pfVenueAdmin.postalPrefixToDept || {};
				var dept = map[ digits.slice( 0, 3 ) ] || map[ digits.slice( 0, 2 ) ] || '';
				if ( dept ) combos.departements.confirm( dept );
			} );
		}
	}

	// Repositionne Code postal/Département/Région entre Ville et Pays, et la
	// case « nom = adresse » juste après le dropdown de sélection/création du
	// lieu (avant Adresse) : les hooks TEC utilisés pour injecter ces champs
	// (tribe_events_after_venue_metabox sur la fiche lieu, ou
	// tribe_events_linked_post_new_form sur la fiche événement) ne s'exécutent
	// qu'en toute fin de formulaire, sans point d'ancrage natif à ces endroits —
	// on déplace donc les <tr> déjà rendus plutôt que dupliquer les templates
	// natifs pour gagner un point d'insertion. Idempotent (ré-appliquer un
	// positionnement déjà correct est un no-op).
	function reposition() {
		var $cityRow    = $( 'tr.tribe-linked-type-venue-city' );
		var $zipRow     = $( 'tr.tribe-linked-type-venue-zip' );
		var $deptRow    = $( 'tr.tribe-linked-type-venue-departement' );
		var $regRow     = $( 'tr.tribe-linked-type-venue-region' );
		var $nameRow    = $( 'tr.tribe-linked-type-venue-name-is-address' );
		var $addressRow = $( 'tr.tribe-linked-type-venue-address' );
		var $countryRow = $( 'tr.tribe-linked-type-venue-country' );
		var $geoRow     = $( 'tr.tribe-linked-type-venue-geo' );

		if ( $cityRow.length && $deptRow.length ) {
			$cityRow.after( $zipRow, $deptRow, $regRow );
		}
		if ( $addressRow.length && $nameRow.length ) {
			$addressRow.before( $nameRow );
		}
		// Adresse → Ville → CP → Département → Région → Pays → Carte → Téléphone.
		if ( $countryRow.length && $geoRow.length ) {
			$countryRow.after( $geoRow );
		}
	}

	// Case « Ce lieu n'a pas de nom, remplacer par l'adresse » : coché, le
	// champ nom devient lecture seule et se remplit à la volée depuis
	// Adresse (Rue sinon Ville, cf. pf_venue_composed_name() dans
	// inc/venue-admin.php qui fait foi côté serveur — ceci n'en est qu'un
	// aperçu live). readonly (pas disabled) pour que la valeur soit bien
	// soumise au submit — un champ disabled est exclu du POST par le
	// navigateur. Partagée entre les deux contextes (fiche lieu autonome :
	// #title ; mini-formulaire événement : champ « Nom du lieu »), qui
	// passent chacun leurs propres éléments.
	function wireNameIsAddress( $checkbox, $title, $street, $city ) {
		if ( ! $checkbox.length || ! $title.length || $checkbox.data( 'pfInited' ) ) return;
		$checkbox.data( 'pfInited', true );

		function composed() {
			var street = $street.val() || '';
			return street !== '' ? street : ( $city.val() || '' );
		}

		var applyState = function () {
			$title.prop( 'readonly', $checkbox.is( ':checked' ) )
				.toggleClass( 'pf-venue-title-readonly', $checkbox.is( ':checked' ) );
		};
		applyState();
		$checkbox.on( 'change', function () {
			applyState();
			if ( $checkbox.is( ':checked' ) ) $title.val( composed() );
		} );
		$street.on( 'input', function () {
			if ( $checkbox.is( ':checked' ) ) $title.val( composed() );
		} );
		$city.on( 'input', function () {
			if ( $checkbox.is( ':checked' ) ) $title.val( composed() );
		} );

		// Rempart, fiche événement uniquement : le JS natif TEC (events-admin.js,
		// fonction déclenchée sur le change du sélecteur de lieu — y compris une
		// fois automatiquement au chargement via .trigger('change')) réinitialise
		// value="" sur TOUS les <input>/<select> des lignes .linked-post — y
		// compris le champ Nom lui-même une fois déjà composé, d'où la
		// réimposition de composed() ici en plus de la case. Cocher la case plus
		// tard ne restaure PAS cet attribut (cocher/décocher ne change que
		// `checked`, jamais `value`) : elle reste visuellement cochée et remplit
		// bien le Nom depuis l'Adresse, mais se soumet vide — d'où
		// _VenueNameIsAddress enregistré à 0 malgré une case cochée à l'écran. On
		// réimpose value="1" juste avant l'envoi du formulaire, dernier moment
		// possible, sans dépendre de savoir quand la corruption a eu lieu.
		var $form = $checkbox.closest( 'form' );
		if ( $form.length ) {
			$form.on( 'submit', function () {
				$checkbox.val( '1' );
				if ( $checkbox.is( ':checked' ) ) $title.val( composed() );
			} );
		}
	}

	// Sur la fiche événement uniquement : le mini-formulaire de création de
	// lieu pré-remplit parfois le Pays depuis le lieu actuellement lié à
	// l'événement (comportement natif TEC) plutôt que depuis le défaut
	// France — on ne comble que si le champ est resté vide, sans écraser une
	// valeur héritée légitime.
	function fillDefaultCountry() {
		if ( ! $( 'body' ).hasClass( 'post-type-tribe_events' ) ) return;
		var $country = $( '#EventCountry' );
		if ( $country.length && ! $country.val() ) {
			$country.val( 'France' ).trigger( 'change' );
		}
	}

	// Fiche événement uniquement : le dropdown natif de sélection de lieu ne
	// fait plus que chercher (mode « tape pour créer » désactivé côté PHP,
	// cf. pf_disable_venue_dropdown_creation) — un bouton dédié révèle le
	// mini-formulaire de création. On reproduit à la main la mécanique
	// interne de TEC (events-admin.js, fonction liée au `change` du
	// dropdown : classe `.tribe-is-creating-linked-post`, affichage des
	// lignes `.linked-post`, valeur sentinelle `-1`) faute de hook officiel
	// pour ça — donc plus fragile qu'un simple filtre PHP, à surveiller aux
	// mises à jour de TEC.
	//
	// Décision volontaire : déclencher un `change` natif sur le dropdown
	// (au clic comme à l'annulation) plutôt que d'essayer de l'éviter —
	// tenter de contourner le handler natif de TEC pour ne pas le
	// déclencher casserait la synchronisation visuelle de Select2 (qui
	// écoute ce même événement). On laisse donc le handler natif de TEC
	// s'exécuter (il vide/masque les champs), puis on réaffiche par-dessus
	// au clic sur « Créer un lieu » — la remise à zéro des champs est de
	// toute façon le comportement voulu pour démarrer une création.
	// Étiquettes du bouton par type de contenu lié — un lieu (allow_multiple:
	// false, une seule ligne dropdown possible) ou un organisateur
	// (allow_multiple: true par défaut TEC, donc plusieurs lignes dropdown —
	// une par organisateur déjà lié — et de nouvelles lignes peuvent être
	// ajoutées dynamiquement via « + Ajouter un autre organisateur »).
	var CREATE_BUTTON_LABELS = {
		tribe_venue: 'Créer un lieu',
		tribe_organizer: 'Créer un organisateur'
	};

	// Câble un bouton « Créer » + lien « Annuler » sur UN dropdown donné.
	// Appelée pour chaque ligne existante au chargement, et pour chaque
	// nouvelle ligne ajoutée dynamiquement (organisateurs, cf. plus bas) —
	// idempotente par élément ($select.data('pfInited')), donc rien ne se
	// double si les deux passes (dropdown existant + hook d'ajout) se
	// recoupaient jamais.
	function wireCreateButton( $select, label ) {
		if ( ! $select.length || $select.data( 'pfInited' ) ) return;
		$select.data( 'pfInited', true );

		var $row     = $select.closest( 'tr.saved-linked-post' );
		var $cell    = $row.find( 'td' ).last();
		var $tbody   = $row.closest( 'tbody' );
		var $section = $tbody.parents( '.tribe-section' );

		var $createBtn = $( '<button type="button" class="button pf-linked-post-create-btn"></button>' )
			.text( label )
			.appendTo( $cell );

		var $cancelLink = $( '<a href="#" class="pf-linked-post-create-cancel"></a>' )
			.text( 'Annuler, revenir à la recherche' )
			.hide()
			.appendTo( $cell );

		var previousValue = null;

		$createBtn.on( 'click', function ( e ) {
			e.preventDefault();
			previousValue = $select.val();
			$select.val( '-1' ).trigger( 'change' );
			$tbody.find( '.linked-post' ).removeAttr( 'data-hidden' ).show();
			$section.addClass( 'tribe-is-creating-linked-post' );
			$createBtn.hide();
			$cancelLink.show();
		} );

		$cancelLink.on( 'click', function ( e ) {
			e.preventDefault();
			$select.val( previousValue || '-1' ).trigger( 'change' );
			$section.removeClass( 'tribe-is-creating-linked-post' );
			$cancelLink.hide();
			$createBtn.show();
		} );
	}

	// Câble tous les dropdowns déjà présents dans le DOM pour un type donné
	// (lieu : au plus un ; organisateur : un par organisateur déjà lié).
	function wireCreateButtonsFor( postType ) {
		var label = CREATE_BUTTON_LABELS[ postType ];
		$( 'select.linked-post-dropdown[data-post-type="' + postType + '"]' ).each( function () {
			wireCreateButton( $( this ), label );
		} );
	}

	// Organisateurs uniquement en pratique (lieu : pas de bouton « + Ajouter »,
	// cf. allow_multiple ci-dessus) : chaque clic sur « + Ajouter un autre
	// organisateur » clone une NOUVELLE ligne dropdown, en dehors de tout
	// document.ready — wireCreateButtonsFor() au chargement ne peut pas la
	// voir. TEC expose un hook JS natif dédié (wp.hooks, contrairement à la
	// mécanique interne du dropdown lui-même) pour ce cas précis.
	function hookDynamicRows() {
		if ( ! window.wp || ! window.wp.hooks || window.pfLinkedPostsHooked ) return;
		window.pfLinkedPostsHooked = true;

		window.wp.hooks.addAction( 'tec.events.admin.linked_posts.add_post', 'pf-venue-admin', function ( postType, outerTable, newTbody ) {
			var label = CREATE_BUTTON_LABELS[ postType ];
			if ( ! label ) return;
			$( newTbody ).find( 'select.linked-post-dropdown[data-post-type="' + postType + '"]' ).each( function () {
				wireCreateButton( $( this ), label );
			} );
		} );
	}

	function pfVenueAdminRun() {
		if ( ! window.pfVenueAdmin ) return;

		reposition();
		initCombos();
		fillDefaultCountry();
		wireCreateButtonsFor( 'tribe_venue' );
		wireCreateButtonsFor( 'tribe_organizer' );
		hookDynamicRows();

		// Fiche lieu autonome.
		wireNameIsAddress( $( '#pf-venue-name-is-address' ), $( '#title' ), $( '#venueAddress' ), $( '#venueCity' ) );

		// Mini-formulaire « Créer un nouveau lieu » de la fiche événement —
		// pas de champ Titre séparé ici, juste un champ « Nom du lieu ».
		wireNameIsAddress(
			$( '#pf-venue-name-is-address-inline' ),
			$( 'input[name="venue[Venue][]"]' ),
			$( 'input[name="venue[Address][]"]' ),
			$( 'input[name="venue[City][]"]' )
		);
	}

	jQuery( document ).ready( function () {
		pfVenueAdminRun();
	} );

	// Sur la fiche événement, TEC clone le mini-formulaire de création de lieu
	// (avec nos champs Département/Région) depuis SON PROPRE document.ready
	// (events-admin.js) — sans dépendance de script explicite entre nos deux
	// fichiers, l'ordre d'exécution des deux handlers document.ready n'est pas
	// garanti. On rejoue donc l'initialisation au chargement complet de la
	// page (après tous les document.ready) ; les garde-fous pfInited rendent
	// ce second passage sans effet là où le premier a déjà tout initialisé.
	jQuery( window ).on( 'load', function () {
		pfVenueAdminRun();
	} );

} )( jQuery );
