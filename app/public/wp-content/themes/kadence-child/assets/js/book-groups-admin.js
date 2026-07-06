jQuery( document ).ready( function ( $ ) {
	var BOOKS = Array.isArray( pfBG.books ) ? pfBG.books : [];

	// Miroir JS de pf_search_normalize() (inc/search.php) : minuscules, sans
	// accents NI ligatures (\u0153\u2192oe, \u00e6\u2192ae), ponctuation \u2192 s\u00e9parateurs.
	function normalize( s ) {
		return ( s == null ? '' : '' + s ).toLowerCase()
			.replace( /\u0153/g, 'oe' ).replace( /\u00e6/g, 'ae' )
			.normalize( 'NFD' ).replace( /[\u0300-\u036f]/g, '' )
			.replace( /[^a-z0-9]+/g, ' ' ).trim();
	}
	BOOKS.forEach( function ( b ) { b._n = normalize( b.title ); } );

	function lev( a, b ) {
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
	}

	function threshold( tok ) {
		var n = tok.length;
		if ( n < 4 ) return 0;
		if ( n < 7 ) return 1;
		return 2;
	}

	function tokenMatches( qt, ttoks ) {
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
	}

	function localFilter( q, max ) {
		var qn     = normalize( q );
		var tokens = qn ? qn.split( ' ' ) : [];
		var qjoin  = qn.replace( / /g, '' );
		var out = [];
		for ( var i = 0; i < BOOKS.length && out.length < ( max || 80 ); i++ ) {
			var n = BOOKS[ i ]._n, ok = true;
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
	}

	// Picker de composition (séries / traductions / cibles « vous aimerez »).
	if ( window.pfBookPicker && $( '#pf-bg-list' ).length ) {
		pfBookPicker( $, {
			prefix:       'pf-bg',
			ajaxUrl:      pfBG.ajaxUrl,
			nonce:        pfBG.nonce,
			fieldName:    'pf_bg_books[]',
			authorAction: 'pf_bg_author_books',
			insertSort:   'append',
			books:        BOOKS
		} );
	}

	// Sélecteur de livre source (onglet « Vous aimerez aussi ») : combobox
	// flottant local ; la sélection recharge l'éditeur pour ce livre.
	var $sourceSearch  = $( '#pf-bg-source-search' );
	var $sourceResults = $( '#pf-bg-source-results' );
	if ( $sourceSearch.length ) {
		function renderSource( data ) {
			$sourceResults.empty();
			if ( ! data.length ) {
				$sourceResults.append( $( '<li>' ).text( 'Aucun résultat.' ).css( 'color', '#888' ) ).show();
				return;
			}
			$.each( data, function ( i, book ) {
				$sourceResults.append( $( '<li>' ).attr( 'data-id', book.id ).text( book.title ) );
			} );
			$sourceResults.show();
		}

		$sourceSearch.on( 'focus input', function () { renderSource( localFilter( $( this ).val() ) ); } );
		$sourceSearch.on( 'blur', function () { setTimeout( function () { $sourceResults.hide(); }, 200 ); } );
		$sourceResults.on( 'mousedown', 'li[data-id]', function ( e ) {
			e.preventDefault();
			var id = parseInt( $( this ).attr( 'data-id' ), 10 );
			if ( id ) window.location.href = pfBG.baseUrl + '&tab=aimerez&source=' + id;
		} );
	}
} );
