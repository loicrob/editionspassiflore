/**
 * Saisie de l'œuvre et du format sur l'écran d'édition produit.
 *
 * Le champ natif « Nom de produit » porte la RACINE du titre (le serveur y a
 * déjà retiré le suffixe de format via le filtre edit_post_title) ; le nom réel
 * est recomposé à l'enregistrement. Ce script ne fait qu'assister la saisie :
 * tout est revalidé et réécrit côté serveur (inc/product-format-admin.php).
 *
 * Données injectées : window.pfFmt — { works, formats, postId, work, format,
 * coherent, editUrl }. Recherche floue partagée : window.pfBookFilter (book-filter.js).
 */
jQuery( document ).ready( function ( $ ) {
	if ( typeof pfFmt === 'undefined' ) return;

	var $title    = $( '#title' );
	var $search   = $( '#pf-fmt-search' );
	var $results  = $( '#pf-fmt-results' );
	var $work     = $( '#pf-fmt-work' );
	var $current  = $( '#pf-fmt-current' );
	var $taken    = $( '#pf-fmt-taken' );
	var $warning  = $( '#pf-fmt-warning' );
	var $format   = $( '#pf-fmt-format' );
	var $preview  = $( '#pf-fmt-preview' );
	var $rename   = $( '#pf-fmt-rename' );
	var $renameFlag = $( '#pf-fmt-rename-flag' );
	var $repPanel = $( '#pf-fmt-rep-panel' );

	if ( ! $title.length || ! $search.length ) return;

	// L'œuvre sélectionnée, ou null si le livre est autonome.
	var selected = null;
	for ( var i = 0; i < pfFmt.works.length; i++ ) {
		if ( workValue( pfFmt.works[ i ] ) === pfFmt.work ) { selected = pfFmt.works[ i ]; break; }
	}

	function workValue( w ) {
		return w.group ? 'g:' + w.group : 'p:' + w.id;
	}

	function suffixFor( slug ) {
		for ( var i = 0; i < pfFmt.formats.length; i++ ) {
			if ( pfFmt.formats[ i ].slug === slug ) return pfFmt.formats[ i ].suffix;
		}
		return '';
	}

	function labelFor( slug ) {
		for ( var i = 0; i < pfFmt.formats.length; i++ ) {
			if ( pfFmt.formats[ i ].slug === slug ) return pfFmt.formats[ i ].name;
		}
		return slug;
	}

	/* ── Aperçu du nom composé ─────────────────────────────────── */

	function refreshPreview() {
		var root = $title.val().trim();
		$preview.text( root ? root + suffixFor( $format.val() ) : '—' );
	}

	/* ── Autres formats du livre ─────────────────────────────── */

	function refreshTaken() {
		var taken = selected ? ( selected.taken || {} ) : {};
		var links = [];

		$format.find( 'option' ).each( function () {
			var owner = taken[ this.value ];
			// Le format que porte DÉJÀ le livre édité reste sélectionnable.
			var isSelf = owner === pfFmt.postId;
			this.disabled = !! owner && ! isSelf;
			if ( owner && ! isSelf ) {
				links.push( $( '<a>', {
					href: pfFmt.editUrl + owner,
					target: '_blank',
					rel: 'noopener',
					text: labelFor( this.value )
				} ) );
			}
		} );

		// Si le format courant vient d'être verrouillé, retomber sur un format libre.
		if ( $format.find( 'option:selected' ).prop( 'disabled' ) ) {
			var free = $format.find( 'option' ).not( ':disabled' ).first();
			$format.val( free.length ? free.val() : '' );
		}

		if ( links.length ) {
			$taken.empty().append( 'Autres formats de ce livre : ' );
			links.forEach( function ( $a, i ) {
				if ( i > 0 ) $taken.append( ', ' );
				$taken.append( $a );
			} );
			$taken.append( '.' ).prop( 'hidden', false );
		} else {
			$taken.prop( 'hidden', true );
		}
	}

	/* ── État global de l'écran ────────────────────────────────── */

	function refreshState() {
		if ( selected ) {
			$current.find( '.pf-fmt-chip' ).text( selected.title );
			$current.prop( 'hidden', false );
			$search.prop( 'hidden', true );
			$repPanel.prop( 'hidden', false );
			// Racine verrouillée : elle appartient à l'œuvre, pas à cette édition.
			$title.prop( 'readonly', true );
			$rename.prop( 'hidden', false );
		} else {
			$current.prop( 'hidden', true );
			$search.prop( 'hidden', false );
			$repPanel.prop( 'hidden', true );
			$title.prop( 'readonly', false );
			$rename.prop( 'hidden', true );
			$renameFlag.val( '' );
		}

		refreshTaken();
		refreshPreview();
		refreshWarning();
	}

	function refreshWarning() {
		var messages = [];

		if ( ! pfFmt.coherent ) {
			messages.push( 'Le nom enregistré ne suit pas la convention « racine + format ». ' +
				'Il sera normalisé à l’enregistrement.' );
		}
		if ( selected && $title.val().trim() && $title.val().trim() !== selected.title ) {
			messages.push( 'La racine diffère de celle de l’œuvre (« ' + selected.title + ' »). ' +
				'Utilisez « Changer le titre du livre et de tous ses formats » pour aligner les éditions.' );
		}

		if ( messages.length ) {
			$warning.html( messages.join( '<br>' ) ).prop( 'hidden', false );
		} else {
			$warning.prop( 'hidden', true );
		}
	}

	/* ── Combobox de sélection de l'œuvre ──────────────────────── */

	function renderResults( list ) {
		$results.empty();
		if ( ! list.length ) {
			$results.append( $( '<li>' ).text( 'Aucun résultat.' ).css( 'color', '#787c82' ) );
		} else {
			$.each( list, function ( i, w ) {
				var $li = $( '<li>' ).attr( 'data-value', workValue( w ) ).text( w.title );
				var meta = w.group ? 'groupe de formats' : 'livre seul';
				$li.append( $( '<span class="pf-fmt-meta">' ).text( '· ' + meta ) );
				$results.append( $li );
			} );
		}
		$results.prop( 'hidden', false );
	}

	$search.on( 'focus input', function () {
		renderResults( window.pfBookFilter( pfFmt.works, $( this ).val(), 60 ) );
	} );
	$search.on( 'blur', function () {
		setTimeout( function () { $results.prop( 'hidden', true ); }, 200 );
	} );

	// mousedown plutôt que click : le blur du champ ne doit pas fermer la liste avant la sélection.
	$results.on( 'mousedown', 'li[data-value]', function ( e ) {
		e.preventDefault();
		var value = $( this ).attr( 'data-value' );
		for ( var i = 0; i < pfFmt.works.length; i++ ) {
			if ( workValue( pfFmt.works[ i ] ) === value ) { selected = pfFmt.works[ i ]; break; }
		}
		$work.val( value );
		$results.prop( 'hidden', true );
		$search.val( '' );
		// Nouvelle œuvre : la racine est celle de l'œuvre.
		$title.val( selected.title );
		refreshState();
	} );

	$current.on( 'click', '.pf-fmt-detach', function () {
		selected = null;
		$work.val( '' );
		refreshState();
		$search.trigger( 'focus' );
	} );

	/* ── Renommage de toute l'œuvre ────────────────────────────── */

	$rename.on( 'click', function () {
		$title.prop( 'readonly', false ).trigger( 'focus' ).select();
		$renameFlag.val( '1' );
		$( this ).prop( 'disabled', true );
	} );

	$title.on( 'input', function () { refreshPreview(); refreshWarning(); } );
	$format.on( 'change', refreshPreview );

	refreshState();
} );
