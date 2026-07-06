document.addEventListener( 'DOMContentLoaded', function () {
    document.querySelectorAll( '.pf-event-tiles-wrap' ).forEach( function ( wrap ) {
        var scroll = wrap.querySelector( '.pf-event-tiles-scroll' );
        if ( ! scroll ) return;

        function update() {
            var atStart     = scroll.scrollLeft <= 1;
            var atEnd       = scroll.scrollLeft + scroll.clientWidth >= scroll.scrollWidth - 1;
            var hasOverflow = scroll.scrollWidth > scroll.clientWidth;
            wrap.classList.toggle( 'pf-scroll-left',  ! atStart );
            wrap.classList.toggle( 'pf-scroll-right', hasOverflow && ! atEnd );
        }

        scroll.addEventListener( 'scroll', update, { passive: true } );
        new ResizeObserver( update ).observe( scroll );
        update();
    } );
} );
