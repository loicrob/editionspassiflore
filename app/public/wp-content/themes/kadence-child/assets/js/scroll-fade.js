document.addEventListener( 'DOMContentLoaded', function () {
    document.querySelectorAll( '.pf-scroll-fade' ).forEach( function ( wrap ) {
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
} );
