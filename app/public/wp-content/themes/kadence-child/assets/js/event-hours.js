jQuery( document ).ready( function ( $ ) {
	var $wrap = $( '#pf-eh-wrap' );
	if ( ! $wrap.length ) return;

	var $rows     = $( '#pf-eh-rows' );
	var $feedback = $( '#pf-eh-feedback' );

	// --- Etats par ligne (ferme / journee entiere / sans heure de fin) ----------
	function syncRow( $row ) {
		var closed = $row.find( '.pf-eh-closed' ).is( ':checked' );
		var allday = $row.find( '.pf-eh-allday' ).is( ':checked' );
		var noend  = $row.find( '.pf-eh-noend' ).is( ':checked' );

		$row.toggleClass( 'is-closed', closed );

		var disableTimes = closed || allday;
		$row.find( '.pf-eh-start' ).prop( 'disabled', disableTimes );
		$row.find( '.pf-eh-noend' ).prop( 'disabled', disableTimes );
		$row.find( '.pf-eh-allday' ).prop( 'disabled', closed );
		$row.find( '.pf-eh-end' ).prop( 'disabled', disableTimes || noend );
	}

	function syncAll() {
		$rows.find( '.pf-eh-row' ).each( function () { syncRow( $( this ) ); } );
	}

	$rows.on( 'change', '.pf-eh-closed, .pf-eh-allday, .pf-eh-noend', function () {
		syncRow( $( this ).closest( '.pf-eh-row' ) );
	} );

	syncAll();

	// --- Regenerer d'apres les dates natives de TEC -----------------------------
	function readDate( id ) {
		var $el = $( '#' + id );
		if ( ! $el.length ) return '';
		// jQuery UI datepicker : getDate renvoie un objet Date quel que soit le format affiche.
		try {
			if ( $el.data( 'datepicker' ) || $el.hasClass( 'hasDatepicker' ) ) {
				var d = $el.datepicker( 'getDate' );
				if ( d ) {
					return d.getFullYear()
						+ ( '0' + ( d.getMonth() + 1 ) ).slice( -2 )
						+ ( '0' + d.getDate() ).slice( -2 );
				}
			}
		} catch ( e ) {}
		// Fallback : valeur brute au format Y-m-d.
		var m = ( $el.val() || '' ).match( /(\d{4})\D?(\d{2})\D?(\d{2})/ );
		return m ? m[ 1 ] + m[ 2 ] + m[ 3 ] : '';
	}

	// Valeurs en cours de saisie (pour ne pas les perdre lors d'une regeneration auto).
	function collectCurrent() {
		var current = {};
		$rows.find( '.pf-eh-row' ).each( function () {
			var $r = $( this );
			var day = String( $r.data( 'day' ) || '' );
			if ( ! day ) return;
			current[ day ] = {
				start:  $r.find( '.pf-eh-start' ).val() || '',
				end:    $r.find( '.pf-eh-end' ).val() || '',
				closed: $r.find( '.pf-eh-closed' ).is( ':checked' ) ? 1 : '',
				allday: $r.find( '.pf-eh-allday' ).is( ':checked' ) ? 1 : '',
				noend:  $r.find( '.pf-eh-noend' ).is( ':checked' ) ? 1 : ''
			};
		} );
		return current;
	}

	// Reconstruit les lignes d'apres les dates natives, en preservant la saisie en cours.
	function rebuild() {
		var start = readDate( 'EventStartDate' );
		var end   = readDate( 'EventEndDate' );
		if ( ! start ) {
			$rows.html( '<p id="pf-eh-empty">Definissez d\'abord une date de debut et de fin.</p>' );
			return;
		}

		$feedback.text( 'Mise a jour...' );

		$.post( pfEH.ajaxUrl, {
			action:  'pf_event_rebuild_days',
			nonce:   pfEH.nonce,
			post_id: $( '#post_ID' ).val(),
			start:   start,
			end:     end,
			current: collectCurrent()
		}, function ( res ) {
			if ( ! res.success ) { $feedback.text( 'Erreur.' ); return; }
			$rows.html( res.data.html );
			syncAll();
			$feedback.text( '' );
		} ).fail( function () {
			$feedback.text( 'Erreur reseau.' );
		} );
	}

	// Auto-sync : quand une date native de TEC change, on regenere les lignes (debounce).
	var rebuildTimer;
	$( document ).on( 'change', '#EventStartDate, #EventEndDate', function () {
		clearTimeout( rebuildTimer );
		rebuildTimer = setTimeout( rebuild, 250 );
	} );
} );
