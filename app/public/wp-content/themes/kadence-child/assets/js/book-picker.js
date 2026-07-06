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
		// et aux accents, affichée dès le focus (filtrée à la frappe).
		var BOOKS = opts.books;

		// Miroir JS de pf_search_normalize() (inc/search.php) : minuscules, sans
		// accents NI ligatures (\u0153\u2192oe, \u00e6\u2192ae), ponctuation \u2192 s\u00e9parateurs.
		var normalize = function ( s ) {
			return ( s == null ? '' : '' + s ).toLowerCase()
				.replace( /\u0153/g, 'oe' ).replace( /\u00e6/g, 'ae' )
				.normalize( 'NFD' ).replace( /[\u0300-\u036f]/g, '' )
				.replace( /[^a-z0-9]+/g, ' ' ).trim();
		};
		BOOKS.forEach( function ( b ) { if ( b._n === undefined ) b._n = normalize( b.title ); } );

		// Distance de Levenshtein (DP sur une ligne).
		var lev = function ( a, b ) {
			var al = a.length, bl = b.length;
			if ( ! al ) return bl;
			if ( ! bl ) return al;
			var prev = [], i, j;
			for ( j = 0; j <= bl; j++ ) prev[ j ] = j;
			for ( i = 1; i <= al; i++ ) {
				var cur = [ i ];
				for ( j = 1; j <= bl; j++ ) {
					var cost = a.charAt( i - 1 ) === b.charAt( j - 1 ) ? 0 : 1;
					cur[ j ] = Math.min( prev[ j ] + 1, cur[ j - 1 ] + 1, prev[ j - 1 ] + cost );
				}
				prev = cur;
			}
			return prev[ bl ];
		};

		var threshold = function ( tok ) {
			var n = tok.length;
			if ( n < 4 ) return 0;
			if ( n < 7 ) return 1;
			return 2;
		};

		// Un token de requ\u00eate matche si sous-cha\u00eene d'un token du texte, ou
		// Levenshtein <= seuil (tol\u00e9rance aux fautes de frappe).
		var tokenMatches = function ( qt, ttoks ) {
			var t, thr = threshold( qt );
			for ( t = 0; t < ttoks.length; t++ ) {
				if ( ttoks[ t ].indexOf( qt ) !== -1 ) return true;
			}
			if ( thr === 0 ) return false;
			for ( t = 0; t < ttoks.length; t++ ) {
				if ( Math.abs( ttoks[ t ].length - qt.length ) > thr ) continue;
				if ( lev( qt, ttoks[ t ] ) <= thr ) return true;
			}
			return false;
		};

		var localFilter = function ( q ) {
			var qn     = normalize( q );
			var tokens = qn ? qn.split( ' ' ) : [];
			var qjoin  = qn.replace( / /g, '' );
			var out = [];
			for ( var i = 0; i < BOOKS.length; i++ ) {
				var n = BOOKS[ i ]._n, ok = true;
				// Sous-cha\u00eene \u00ab coll\u00e9e \u00bb (g\u00e8re les caract\u00e8res sp\u00e9ciaux), sinon tous
				// les tokens doivent matcher (pr\u00e9fixe / sous-cha\u00eene / faute de frappe).
				if ( qjoin && n.replace( / /g, '' ).indexOf( qjoin ) !== -1 ) {
					out.push( BOOKS[ i ] );
					continue;
				}
				var ttoks = n ? n.split( ' ' ) : [];
				for ( var t = 0; t < tokens.length; t++ ) {
					if ( ! tokenMatches( tokens[ t ], ttoks ) ) { ok = false; break; }
				}
				if ( ok ) out.push( BOOKS[ i ] );
			}
			return out;
		};

		$search.on( 'focus input', function () { renderResults( localFilter( $( this ).val() ) ); } );
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
