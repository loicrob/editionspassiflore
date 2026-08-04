/**
 * Composant réutilisable de sélection de livres (admin).
 *
 * Recherche AJAX + ajout par auteur + drag-reorder, partagé par la méta-box
 * des événements (inc/event-admin.php) et l'outil « Groupes de livres »
 * (inc/book-groups-admin.php). Les éléments sont ciblés par préfixe d'ID, donc
 * une page ne porte qu'un seul picker à la fois.
 *
 * opts = {
 *   prefix:       string  // ex. 'pf-eb' → #pf-eb-list, #pf-eb-search, …
 *   ajaxUrl:      string
 *   nonce:        string
 *   fieldName:    string  // name des <input hidden> postés (ex. 'pf_event_books[]')
 *   searchAction: string  // action AJAX de recherche
 *   authorAction: string  // action AJAX « livres d'un auteur »
 *   insertSort:   'date' | 'append'  // ordre d'insertion d'un livre ajouté
 * }
 */
window.pfBookPicker = function ( $, opts ) {
	var p         = '#' + opts.prefix;
	var handleCls = opts.prefix + '-handle';
	var removeCls = opts.prefix + '-remove';
	var addedCls  = opts.prefix + '-added';
	var $list     = $( p + '-list' );
	var $empty    = $( p + '-empty' );
	var $feedback = $( p + '-feedback' );
	var $results  = $( p + '-results' );
	var searchTimer;

	if ( ! $list.length ) return;

	$list.sortable( { handle: '.' + handleCls, placeholder: 'ui-sortable-placeholder', axis: 'y' } );

	function selectedIds() {
		return $list.find( 'li' ).map( function () {
			return parseInt( $( this ).data( 'id' ), 10 );
		} ).get();
	}

	function addBook( book ) {
		if ( selectedIds().indexOf( book.id ) !== -1 ) return false;

		var date = book.date || '';
		var $li  = $( '<li>' ).attr( 'data-id', book.id ).attr( 'data-date', date );
		$li.append( $( '<span class="dashicons dashicons-menu" aria-hidden="true">' ).addClass( handleCls ) );
		$li.append( $( '<span>' ).text( book.title ) );
		$li.append( $( '<button type="button" class="dashicons dashicons-no-alt" aria-label="Supprimer">' ).addClass( removeCls ) );
		$li.append( $( '<input type="hidden">' ).attr( 'name', opts.fieldName ).val( book.id ) );

		// En mode 'date' : insertion à la position chronologique (plus récent en
		// premier), les livres sans date tombant en fin. En mode 'append' :
		// toujours à la fin, l'ordre étant ensuite fixé par le drag.
		var inserted = false;
		if ( opts.insertSort === 'date' && date ) {
			$list.find( 'li' ).each( function () {
				if ( date > ( $( this ).attr( 'data-date' ) || '' ) ) {
					$( this ).before( $li );
					inserted = true;
					return false;
				}
			} );
		}
		if ( ! inserted ) $list.append( $li );

		$empty.hide();
		return true;
	}

	// Retirer tout
	$( p + '-clear' ).on( 'click', function () {
		$list.empty();
		$empty.show();
		refreshResultsState();
	} );

	// Retirer un livre sélectionné
	$list.on( 'click', '.' + removeCls, function () {
		$( this ).closest( 'li' ).remove();
		if ( ! $list.children().length ) $empty.show();
		refreshResultsState();
	} );

	// Ajouter tous les livres d'un auteur
	$( p + '-author-btn' ).on( 'click', function () {
		var termId = $( p + '-author-select' ).val();
		if ( ! termId ) return;
		var $btn = $( this ).prop( 'disabled', true ).text( '…' );
		$feedback.text( '' );

		$.post( opts.ajaxUrl, { action: opts.authorAction, nonce: opts.nonce, term_id: termId }, function ( res ) {
			$btn.prop( 'disabled', false ).text( 'Ajouter' );
			if ( ! res.success ) { $feedback.text( 'Erreur.' ); return; }
			if ( ! res.data.length ) { $feedback.text( 'Aucun livre trouvé pour cet auteur.' ); return; }

			var added = 0, skipped = 0;
			$.each( res.data, function ( i, b ) { addBook( b ) ? added++ : skipped++; } );
			var msg = added + ' livre(s) ajouté(s)';
			if ( skipped ) msg += ', ' + skipped + ' déjà présent(s)';
			$feedback.text( msg );
			refreshResultsState();
		} ).fail( function () {
			$btn.prop( 'disabled', false ).text( 'Ajouter' );
			$feedback.text( 'Erreur réseau.' );
		} );
	} );

	var $search = $( p + '-search' );

	// Construit les <li> de résultats à partir d'une liste [{id,title,date}].
	function renderResults( data ) {
		$results.empty();
		if ( ! data.length ) {
			$results.append( $( '<li>' ).text( 'Aucun résultat.' ).css( 'color', '#888' ) ).show();
			return;
		}
		var ids = selectedIds();
		$.each( data, function ( i, book ) {
			var already = ids.indexOf( book.id ) !== -1;
			var $li = $( '<li>' ).attr( 'data-id', book.id ).attr( 'data-date', book.date || '' ).toggleClass( addedCls, already );
			$li.append( $( '<span>' ).text( book.title ) );
			if ( ! already ) {
				$li.append( $( '<button type="button" class="button button-small">+</button>' ) );
			} else {
				$li.append( $( '<span>' ).text( '✓' ).css( 'color', '#999' ) );
			}
			$results.append( $li );
		} );
		$results.show();
	}

	if ( Array.isArray( opts.books ) ) {
		// Mode local : liste préchargée → combobox flottant insensible à la casse
		// et aux accents, affichée dès le focus (filtrée à la frappe). Le filtre
		// flou est partagé (assets/js/book-filter.js), sans plafond de résultats.
		var BOOKS = opts.books;

		$search.on( 'focus input', function () {
			renderResults( window.pfBookFilter( BOOKS, $( this ).val() ) );
		} );
		$search.on( 'blur', function () { setTimeout( function () { $results.hide(); }, 200 ); } );
		// Garde le focus dans le champ pendant l'interaction avec la liste
		// (permet d'ajouter plusieurs livres sans refermer le menu).
		$results.on( 'mousedown', function ( e ) { e.preventDefault(); } );

	} else {
		// Mode AJAX (méta-box événements) : recherche serveur à partir de 2 car.
		$search.on( 'input', function () {
			clearTimeout( searchTimer );
			var q = $( this ).val().trim();
			if ( q.length < 2 ) { $results.empty().hide(); return; }

			searchTimer = setTimeout( function () {
				$.post( opts.ajaxUrl, { action: opts.searchAction, nonce: opts.nonce, q: q }, function ( res ) {
					if ( ! res.success ) { $results.empty(); return; }
					renderResults( res.data );
				} );
			}, 280 );
		} );
	}

	// Ajouter depuis les résultats de recherche
	$results.on( 'click', 'button', function () {
		var $li  = $( this ).closest( 'li' );
		var book = {
			id:    parseInt( $li.attr( 'data-id' ), 10 ),
			title: $li.find( 'span:first' ).text(),
			date:  $li.attr( 'data-date' ) || ''
		};
		if ( addBook( book ) ) {
			$li.addClass( addedCls ).find( 'button' ).replaceWith( $( '<span>' ).text( '✓' ).css( 'color', '#999' ) );
		}
	} );

	// Synchronise l'état des résultats après un retrait
	function refreshResultsState() {
		var ids = selectedIds();
		$results.find( 'li' ).each( function () {
			var id = parseInt( $( this ).attr( 'data-id' ), 10 );
			var already = ids.indexOf( id ) !== -1;
			if ( already && ! $( this ).hasClass( addedCls ) ) {
				$( this ).addClass( addedCls ).find( 'button' ).replaceWith( $( '<span>' ).text( '✓' ).css( 'color', '#999' ) );
			} else if ( ! already && $( this ).hasClass( addedCls ) ) {
				$( this ).removeClass( addedCls ).find( 'span:last' ).replaceWith(
					$( '<button type="button" class="button button-small">+</button>' )
				);
			}
		} );
	}
};
