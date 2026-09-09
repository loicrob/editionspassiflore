/**
 * Écran « Intérêts pour les épuisés » — dépliage du détail + copie des adresses.
 * Volumétrie faible : le détail est rendu au chargement (aucun AJAX), simplement
 * masqué via l'attribut `hidden`.
 */
( function () {
	'use strict';

	// Confirmation avant la suppression en masse (form[data-confirm]).
	document.addEventListener( 'submit', function ( e ) {
		var form = e.target;
		if ( form && form.hasAttribute && form.hasAttribute( 'data-confirm' ) ) {
			if ( ! window.confirm( form.getAttribute( 'data-confirm' ) ) ) {
				e.preventDefault();
			}
		}
	} );

	document.addEventListener( 'click', function ( e ) {
		var toggle = e.target.closest( '.pf-sa-toggle' );
		if ( toggle ) {
			var id  = toggle.getAttribute( 'aria-controls' );
			var row = id && document.getElementById( id );
			if ( ! row ) {
				return;
			}
			var open = row.hidden;
			row.hidden = ! open;
			toggle.setAttribute( 'aria-expanded', open ? 'true' : 'false' );
			toggle.textContent = open ? 'Masquer' : 'Plus d’infos';
			return;
		}

		var copyBtn = e.target.closest( '.pf-sa-copy' );
		if ( copyBtn ) {
			var src = copyBtn.parentNode.querySelector( '.pf-sa-copy-src' );
			if ( ! src ) {
				return;
			}
			var text = src.value;
			var done = function () {
				var label = copyBtn.getAttribute( 'data-copied' ) || 'Copié';
				var prev  = copyBtn.textContent;
				copyBtn.textContent = label;
				setTimeout( function () { copyBtn.textContent = prev; }, 2000 );
			};

			if ( navigator.clipboard && navigator.clipboard.writeText ) {
				navigator.clipboard.writeText( text ).then( done, function () {
					legacyCopy( src );
					done();
				} );
			} else {
				legacyCopy( src );
				done();
			}
		}
	} );

	// Repli pour les contextes non sécurisés (http) : sélection + execCommand sur
	// le <textarea> (toujours rendu, simplement positionné hors écran en CSS).
	function legacyCopy( src ) {
		src.select();
		try { document.execCommand( 'copy' ); } catch ( err ) {}
		if ( window.getSelection ) {
			window.getSelection().removeAllRanges();
		}
	}
} )();
