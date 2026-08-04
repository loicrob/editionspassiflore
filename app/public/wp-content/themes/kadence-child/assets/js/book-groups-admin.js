jQuery( document ).ready( function ( $ ) {
	var BOOKS = Array.isArray( pfBG.books ) ? pfBG.books : [];

	// Filtre flou partagé (assets/js/book-filter.js), plafonné à 80 résultats.
	function localFilter( q, max ) {
		return window.pfBookFilter( BOOKS, q, max || 80 );
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
