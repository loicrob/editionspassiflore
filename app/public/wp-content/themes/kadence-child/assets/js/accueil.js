document.addEventListener( 'DOMContentLoaded', function () {
    var typingDone = initTypingAnimation();
    var carousel = initCarousel();
    initRelocateActualites( carousel );
    initScrollCarets();
    initStickyHeaderShadow();
    initBookshelfIntro();

    typingDone.then( function () {
        return new Promise( function ( resolve ) { setTimeout( resolve, 1000 ); } );
    } ).then( function () {
        var scrollBtn = document.querySelector( '.pf-hero-scroll' );
        if ( scrollBtn ) scrollBtn.classList.add( 'pf-hero-scroll--anim' );
        if ( carousel ) carousel.Components.Autoplay.play();
    } );
} );

// ── Typing animation ──────────────────────────────────────────────────────────

function initTypingAnimation() {
    var ligne3 = document.getElementById( 'pf-ligne-3' );
    var ligne4 = document.getElementById( 'pf-ligne-4' );
    if ( ! ligne3 ) return;

    var delay = function ( ms ) {
        return new Promise( function ( resolve ) { setTimeout( resolve, ms ); } );
    };

    var typeInto = function ( el, text, baseDelay ) {
        return Array.from( text ).reduce( function ( promise, char ) {
            return promise.then( function () {
                el.textContent += char;
                var jitter = Math.floor( ( Math.random() - 0.3 ) * baseDelay * 1.4 );
                return delay( Math.max( 20, baseDelay + jitter ) );
            } );
        }, Promise.resolve() );
    };

    return delay( 500 )
        .then( function () {
            ligne3.classList.add( 'pf-hero-ligne--active' );
            return typeInto( ligne3, 'En toute indépendance,', 55 );
        } )
        .then( function () {
            ligne3.classList.remove( 'pf-hero-ligne--active' );
            if ( ! ligne4 ) return delay( 1500 );
            return delay( 520 );
        } )
        .then( function () {
            if ( ! ligne4 ) return;
            ligne4.classList.add( 'pf-hero-ligne--active' );
            return typeInto( ligne4, 'depuis 2009.', 55 );
        } )
        .then( function () {
            // Curseur clignote encore 1500ms, puis disparaît — fire and forget.
            delay( 1500 ).then( function () {
                if ( ligne4 ) ligne4.classList.remove( 'pf-hero-ligne--active' );
            } );
            // La promise résout ici : dernier caractère tapé.
        } );
}

// ── Scroll carets ─────────────────────────────────────────────────────────────

function scrollToEl( el ) {
    var stickyOffset = parseFloat(
        getComputedStyle( document.documentElement ).getPropertyValue( '--pf-sticky-offset' )
    ) || 0;
    var top = el.getBoundingClientRect().top + window.scrollY - stickyOffset;
    window.scrollTo( { top: top, behavior: 'smooth' } );
}

function initScrollCarets() {
    document.querySelectorAll( '[data-scroll-to]' ).forEach( function ( btn ) {
        btn.addEventListener( 'click', function () {
            var target = document.getElementById( btn.dataset.scrollTo );
            if ( target ) scrollToEl( target );
            btn.classList.add( 'is-clicked' );
            btn.blur();
        } );
    } );

    // Caret hero : pause au prochain boundary d'itération (pas en plein milieu)
    var scrollBtn = document.querySelector( '.pf-hero-scroll' );
    if ( ! scrollBtn ) return;
    var svg = scrollBtn.querySelector( 'svg' );
    if ( ! svg ) return;

    var wantPause = false;

    svg.addEventListener( 'animationiteration', function () {
        if ( wantPause ) {
            scrollBtn.classList.add( 'is-paused' );
            wantPause = false;
        }
    } );

    function onEnter() {
        if ( scrollBtn.classList.contains( 'is-clicked' ) ) return;
        wantPause = true;
    }

    function onLeave() {
        wantPause = false;
        scrollBtn.classList.remove( 'is-paused' );
    }

    scrollBtn.addEventListener( 'mouseenter', onEnter );
    scrollBtn.addEventListener( 'focusin',    onEnter );
    scrollBtn.addEventListener( 'mouseleave', onLeave );
    scrollBtn.addEventListener( 'focusout',   onLeave );
}

// ── Carousel ─────────────────────────────────────────────────────────────────

function initCarousel() {
    var el = document.querySelector( '.pf-actualites-carousel' );
    if ( ! el ) return null;

    return new Splide( el, {
        type        : 'loop',
        autoplay    : 'pause',
        interval    : 6000,
        pauseOnHover: true,
        arrows      : true,
        pagination  : true,
        speed       : 600,
        gap         : 0,
        perPage     : 1,
    } ).mount();
}

// ── Actualités : relocalisation responsive ─────────────────────────────────────
// Rendu par défaut dans le hero (PHP), qui le garde à partir de 769px (grand
// écran). Sur mobile+tablette (< 769px) on le descend dans la section
// « En ce moment » au-dessus des événements. Une seule instance Splide ; on
// appelle refresh() après déplacement pour qu'elle recalcule sa largeur dans le
// nouveau conteneur.

function initRelocateActualites( splide ) {
    var node = document.querySelector( '.pf-en-ce-moment-actualites' );
    var slot = document.querySelector( '.pf-hero-actualites-slot' );
    var cols = document.querySelector( '.pf-en-ce-moment-cols' );
    if ( ! node || ! slot || ! cols ) return;

    var mql = window.matchMedia( '(min-width: 769px)' );

    var apply = function () {
        var target = mql.matches ? slot : cols;
        if ( node.parentNode === target ) return;

        if ( mql.matches ) {
            slot.appendChild( node );
        } else {
            cols.insertBefore( node, cols.firstChild );
        }
        if ( splide ) splide.refresh();
    };

    apply();
    mql.addEventListener( 'change', apply );
}

// ── Sticky header shadow ──────────────────────────────────────────────────────

function initStickyHeaderShadow() {
    var headers = [
        '.pf-en-ce-moment-section > .pf-section-header',
        '.pf-au-catalogue-section > .pf-section-header',
    ].map( function ( sel ) {
        return document.querySelector( sel );
    } ).filter( Boolean );

    if ( ! headers.length ) return;

    function update() {
        var stickyOffset = parseFloat(
            getComputedStyle( document.documentElement ).getPropertyValue( '--pf-sticky-offset' )
        ) || 0;
        headers.forEach( function ( header ) {
            var stuck = header.getBoundingClientRect().top <= stickyOffset + 1;
            header.classList.toggle( 'pf-header-stuck', stuck );
        } );
    }

    window.addEventListener( 'scroll', update, { passive: true } );
    window.addEventListener( 'resize', update, { passive: true } );
    update();
}

// ── Animation d'entrée des étagères ────────────────────────────────────────────

function initBookshelfIntro() {
    if ( window.matchMedia( '(prefers-reduced-motion: reduce)' ).matches ) return;

    var shelves = document.querySelectorAll( '.pf-accueil .pf-bookshelf' );
    if ( ! shelves.length ) return;

    var STEP_COVERS = 0.2; // s entre deux livres — mode covers
    var STEP_SPINES = 0.04; // s entre deux livres — mode spines

    // Pose de départ (couverture ouverte+agrandie / dos penché) posée dès le chargement,
    // visible avant le scroll de déclenchement.
    shelves.forEach( function ( shelf ) { shelf.classList.add( 'pf-bookshelf-armed' ); } );

    var observer = new IntersectionObserver( function ( entries, obs ) {
        entries.forEach( function ( entry ) {
            if ( ! entry.isIntersecting ) return;
            var shelf = entry.target;
            var step = shelf.classList.contains( 'pf-bookshelf--spines' ) ? STEP_SPINES : STEP_COVERS;
            shelf.querySelectorAll( '.pf-book' ).forEach( function ( book, i ) {
                book.style.setProperty( '--pf-intro-delay', ( i * step ).toFixed( 2 ) + 's' );
            } );
            // L'anim prend le relais de la pose de départ au même instant → pas d'à-coup.
            shelf.classList.remove( 'pf-bookshelf-armed' );
            shelf.classList.add( 'pf-bookshelf-intro' );
            obs.unobserve( shelf );
        } );
    }, { rootMargin: '0px 0px -25% 0px' } );

    shelves.forEach( function ( shelf ) { observer.observe( shelf ); } );
}
