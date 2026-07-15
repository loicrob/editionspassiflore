/**
 * Composant global .pf-scroll-fade — ombres de bord d'un scroll horizontal.
 *
 * Expose window.pfScrollFade( root ) : câble tous les .pf-scroll-fade contenus dans
 * `root` (défaut = document) qui ne le sont pas déjà. Réutilisable pour du contenu
 * injecté après le chargement (ex. popup cloné de la vue mois) — le scan initial
 * DOMContentLoaded ne voit pas ces éléments-là.
 */
( function () {
	'use strict';

	function wire( root ) {
		( root || document ).querySelectorAll( '.pf-scroll-fade' ).forEach( function ( wrap ) {
			if ( wrap._pfFadeWired ) return;
			wrap._pfFadeWired = true;

			// .pf-scroll-fade ne doit JAMAIS scroller lui-même : un pseudo-élément
			// position:absolute dont le conteneur de positionnement est l'élément qui
			// scrolle fait partie de sa propre zone de scroll (il se déplacerait avec
			// le contenu au lieu de rester épinglé au bord). L'élément qui scrolle est
			// donc toujours un enfant distinct — son unique enfant direct.
			var scroll = wrap.firstElementChild;
			if ( ! scroll ) return;

			function update() {
				var atStart     = scroll.scrollLeft <= 1;
				var atEnd       = scroll.scrollLeft + scroll.clientWidth >= scroll.scrollWidth - 1;
				var hasOverflow = scroll.scrollWidth > scroll.clientWidth;
				wrap.classList.toggle( 'is-scroll-left',  ! atStart );
				wrap.classList.toggle( 'is-scroll-right', hasOverflow && ! atEnd );
			}

			scroll.addEventListener( 'scroll', update, { passive: true } );
			new ResizeObserver( update ).observe( scroll );
			update();
		} );
	}

	window.pfScrollFade = wire;

	document.addEventListener( 'DOMContentLoaded', function () { wire( document ); } );
} )();
