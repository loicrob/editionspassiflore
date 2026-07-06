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
 */
jQuery( document ).ready( function ( $ ) {
	if ( ! window.pfVenueAdmin ) return;

	// Repositionne Code postal/Département/Région entre Ville et Pays : le
	// hook TEC utilisé pour injecter Département/Région
	// (tribe_events_after_venue_metabox) ne s'exécute qu'en toute fin de
	// formulaire, sans point d'ancrage natif à cet endroit — on déplace donc
	// les <tr> déjà rendus plutôt que dupliquer le template natif pour
	// gagner un point d'insertion.
	var $cityRow = $( 'tr.tribe-linked-type-venue-city' );
	var $zipRow  = $( 'tr.tribe-linked-type-venue-zip' );
	var $deptRow = $( 'tr.tribe-linked-type-venue-departement' );
	var $regRow  = $( 'tr.tribe-linked-type-venue-region' );
	if ( $cityRow.length && $deptRow.length ) {
		$cityRow.after( $zipRow, $deptRow, $regRow );
	}

	// Miroir JS de pf_search_normalize() (inc/search.php), comme dans
	// book-picker.js : minuscules, sans accents ni ligatures, ponctuation
	// réduite à des espaces.
	function normalize( s ) {
		return ( s == null ? '' : '' + s ).toLowerCase()
			.replace( /œ/g, 'oe' ).replace( /æ/g, 'ae' )
			.normalize( 'NFD' ).replace( /[\u0300-\u036f]/g, '' )
			.replace( /[^a-z0-9]+/g, ' ' ).trim();
	}

	// Par field ('departements' | 'regions') → { confirm(value) }, pour que la
	// cascade code postal / département puisse fixer une valeur depuis
	// l'extérieur du combobox exactement comme le ferait l'utilisateur.
	var combos = {};

	$( '.pf-venue-combo' ).each( function () {
		var $wrap   = $( this );
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
	if ( $zip.length && combos.departements ) {
		$zip.on( 'input blur', function () {
			var digits = ( $zip.val() || '' ).replace( /\D/g, '' );
			if ( digits.length !== 5 ) return;
			var map  = window.pfVenueAdmin.postalPrefixToDept || {};
			var dept = map[ digits.slice( 0, 3 ) ] || map[ digits.slice( 0, 2 ) ] || '';
			if ( dept ) combos.departements.confirm( dept );
		} );
	}

	// Case « Ce lieu n'a pas de nom » (pf_render_venue_name_is_address_checkbox,
	// rendue sous le champ Titre natif via edit_form_after_title) : coché, le
	// Titre devient lecture seule et se remplit à la volée depuis l'Adresse.
	// readonly (pas disabled) pour que la valeur soit bien soumise au submit —
	// un champ disabled est exclu du POST par le navigateur.
	var $checkbox = $( '#pf-venue-name-is-address' );
	var $title    = $( '#title' );
	var $address  = $( '#venueAddress' );

	if ( $checkbox.length && $title.length ) {
		var applyState = function () {
			$title.prop( 'readonly', $checkbox.is( ':checked' ) )
				.toggleClass( 'pf-venue-title-readonly', $checkbox.is( ':checked' ) );
		};
		applyState();
		$checkbox.on( 'change', function () {
			applyState();
			if ( $checkbox.is( ':checked' ) ) $title.val( $address.val() );
		} );
		$address.on( 'input', function () {
			if ( $checkbox.is( ':checked' ) ) $title.val( $address.val() );
		} );
	}
} );
