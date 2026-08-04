/**
 * Masque le séparateur « · » entre deux liens du footer (inc/shortcodes.php →
 * [pf_footer_legal]) quand il tombe pile sur un retour à la ligne : le repli
 * visuel suffit alors, un point resterait sinon accroché en tête ou en fin de
 * ligne. `visibility` (jamais `display`) pour ne pas faire bouger le `gap` au
 * ré-examen suivant.
 */
( function () {
	function updateSeparators() {
		document.querySelectorAll( '.pf-footer-legal' ).forEach( function ( row ) {
			row.querySelectorAll( '.pf-footer-legal__sep' ).forEach( function ( sep ) {
				var prev = sep.previousElementSibling;
				var next = sep.nextElementSibling;
				var sameLine = prev && next && prev.offsetTop === next.offsetTop;
				sep.style.visibility = sameLine ? '' : 'hidden';
			} );
		} );
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', updateSeparators );
	} else {
		updateSeparators();
	}

	var resizeTimer;
	window.addEventListener( 'resize', function () {
		clearTimeout( resizeTimer );
		resizeTimer = setTimeout( updateSeparators, 150 );
	} );
} )();
