jQuery( document ).ready( function ( $ ) {
	if ( ! window.pfBookPicker ) return;
	pfBookPicker( $, {
		prefix:       'pf-eb',
		ajaxUrl:      pfEB.ajaxUrl,
		nonce:        pfEB.nonce,
		fieldName:    'pf_event_books[]',
		searchAction: 'pf_event_search_books',
		authorAction: 'pf_event_author_books',
		insertSort:   'date'
	} );
} );
