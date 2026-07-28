document.addEventListener( 'DOMContentLoaded', function () {
    var typingDone = initTypingAnimation();
    var carousel = initCarousel();
    initRelocateActualites( carousel );
    initActualiteFit( carousel );
    initScrollCarets();
    initBookshelfIntro();
    initWordmarkFit();

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

// ── Wordmark : largeur fit-content une fois "Editions"/"Passiflore" empilés ────

/* Une fois empilées (flex-wrap sur .pf-hero-wordmark, cf. accueil.css), la
   largeur de la boîte reste par défaut celle de la paire côte à côte (le CSS
   shrink-to-fit ne retombe pas sur la largeur du mot le plus large une fois
   wrappé) — d'où l'espace vide à droite de "Passiflore" sans ce correctif, et
   .pf-hero-marque (icône + cette boîte) qui paraît décalé à gauche une fois
   centré par .pf-hero-contenu. Mesure directement en px plutôt que de poser
   width:min-content : ce mot-clé, sur ce flex-wrap d'images, fait s'effondrer
   sa largeur à 0 dans Chrome quel que soit le mécanisme qui le pose (classe
   fixe ou container query, testé) — bug de layout confirmé, pas une histoire
   de spécificité. width:auto/max-content/une valeur px fonctionnent, donc on
   mesure la largeur réelle du mot le plus large et on la pose en style
   inline. */
function initWordmarkFit() {
    var wordmark = document.querySelector( '.pf-hero-wordmark' );
    if ( ! wordmark ) return;
    var imgs = wordmark.querySelectorAll( 'img' );
    if ( imgs.length < 2 ) return;
    var first = imgs[ 0 ];
    var last = imgs[ imgs.length - 1 ];

    function apply() {
        // Retire d'abord toute largeur posée précédemment : sinon, une fois
        // posée, les mots resteraient toujours "empilés" au sens de cette
        // mesure (plus assez de place pour les remettre côte à côte).
        wordmark.style.width = '';
        var wrapped = first.getBoundingClientRect().top !== last.getBoundingClientRect().top;
        if ( ! wrapped ) return;

        var widest = 0;
        imgs.forEach( function ( img ) {
            widest = Math.max( widest, img.getBoundingClientRect().width );
        } );
        wordmark.style.width = widest + 'px';
    }

    apply();
    window.addEventListener( 'load', apply );

    var resizeTimer = null;
    window.addEventListener( 'resize', function () {
        clearTimeout( resizeTimer );
        resizeTimer = setTimeout( apply, 120 );
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

// Mobile + tablette : ni flèches ni pagination, le glissement tactile suffit.
// Critère = capacité de pointage, PAS la largeur : les breakpoints de Splide sont
// des seuils en px, or les tablettes vont de 744px (iPad mini) à 1366px (iPad Pro
// 13" en paysage) — aucun seuil ne sépare proprement tablette et ordinateur.
// `hover: none` + `pointer: coarse` = doigt sans survol possible, donc tactile.
var PF_TACTILE = window.matchMedia( '(hover: none) and (pointer: coarse)' );

// Classe posée sur le carousel quand il n'a NI flèches NI pagination. C'est la
// seule source de vérité : le CSS y accroche la bande réservée sous le slide
// (padding-bottom), et initActualiteFit() lit ce padding pour dimensionner la
// carte et la boîte du carousel. Sans contrôles → réserve nulle → rien n'est
// décompté pour des boutons absents, et le CSS n'a pas à redupliquer la media
// query tactile (il suivrait sinon sa propre logique, dérivable de celle-ci).
var PF_SANS_CONTROLES = 'pf-actualites-carousel--sans-controles';

function initCarousel() {
    var el = document.querySelector( '.pf-actualites-carousel' );
    if ( ! el ) return null;

    el.classList.toggle( PF_SANS_CONTROLES, PF_TACTILE.matches );

    var splide = new Splide( el, {
        type        : 'loop',
        autoplay    : 'pause',
        interval    : 6000,
        pauseOnHover: true,
        arrows      : ! PF_TACTILE.matches,
        pagination  : ! PF_TACTILE.matches,
        speed       : 800,
        gap         : 0,
        perPage     : 1,
    } ).mount();

    // Cas rare (souris branchée sur une tablette, mode bureau) : les contrôles
    // apparaissent/disparaissent, donc la réserve sous le slide change → refresh()
    // pour que la boîte du carousel soit recalculée (initActualiteFit écoute
    // 'refresh'), sinon elle resterait dimensionnée pour l'état précédent.
    PF_TACTILE.addEventListener( 'change', function ( e ) {
        el.classList.toggle( PF_SANS_CONTROLES, e.matches );
        splide.options = { arrows: ! e.matches, pagination: ! e.matches };
        splide.refresh();
    } );

    return splide;
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

// ── Actualités (hero) : ajuste la carte du carousel à la hauteur disponible ────
// Sur grand écran (≥769px), l'encart actualités occupe la colonne droite du hero,
// de hauteur fixe (100svh − header). Deux problèmes, tous deux réglés ici car
// insolubles en CSS pur (circularité de hauteur + effondrement des hauteurs en %
// dès qu'on retire les flex:1 de la chaîne .pf-actualites-carousel/.splide__track,
// bug WebKit déjà documenté plus bas dans accueil.css) :
//
//   1. Colonne trop COURTE : image + légende dépassent → le polaroïd
//      (max-height:100% + overflow:hidden) rognait le bas (le bouton). On pose
//      une largeur/hauteur explicites sur l'image (fit-content de la carte +
//      min-width:100% de la légende suivent), l'image rétrécit sur ses deux axes.
//      La largeur ne peut pas dériver en CSS car fit-content se cale sur la
//      largeur INTRINSÈQUE de l'image, pas sur sa largeur contrainte-en-hauteur.
//
//   2. Colonne trop HAUTE : le carousel (flex:1) remplissait toute la colonne,
//      carte centrée dedans mais flèches/pagination collées en bas (bottom:0)
//      → grand vide sous la carte. On réduit la boîte du carousel à la plus haute
//      carte + la réserve des contrôles ; justify-content:center (CSS) la recentre.
//      On prend la plus haute carte (pas la courante) → boîte stable entre diapos.
//
// Sur petit écran l'encart est relocalisé dans une colonne à hauteur libre
// (image plafonnée en CSS, pas de rognage) → on efface tous les overrides et sort.

function initActualiteFit( splide ) {
    var carousel = document.querySelector( '.pf-actualites-carousel' );
    if ( ! carousel ) return;

    var slot = document.querySelector( '.pf-hero-actualites-slot' );
    var mql  = window.matchMedia( '(min-width: 769px)' );
    var MIN_IMG_H = 60; // px — plancher si la légende est démesurée (évite l'effondrement)

    // Hauteur intérieure du slot (colonne droite) : hauteur dispo pour l'encart,
    // STABLE (ne dépend que du viewport, pas de la taille de la carte ni de la
    // boîte réduite du carousel) → base de mesure fiable, sans boucle de feedback.
    function colHeight() {
        if ( ! slot ) return 0;
        var cs = getComputedStyle( slot );
        return slot.clientHeight
            - parseFloat( cs.paddingTop ) - parseFloat( cs.paddingBottom );
    }

    function clearAll() {
        carousel.style.flex = '';
        carousel.style.height = '';
        carousel.querySelectorAll( '.pf-polaroid' ).forEach( function ( p ) {
            p.style.width = '';
            var ib = p.querySelector( '.pf-actualite-image' );
            if ( ib ) { ib.style.width = ''; ib.style.height = ''; }
        } );
    }

    // Ajuste UNE carte pour qu'image + légende tiennent dans cardMaxH.
    function fitOne( polaroid, cardMaxH ) {
        var imgBox  = polaroid.querySelector( '.pf-actualite-image' );
        var img     = imgBox && imgBox.querySelector( 'img' );
        var caption = polaroid.querySelector( '.pf-actualite-contenu' );

        polaroid.style.width = '';
        if ( imgBox ) { imgBox.style.width = ''; imgBox.style.height = ''; }
        if ( ! imgBox || ! img ) return;

        var nw = img.naturalWidth, nh = img.naturalHeight;
        if ( ! nw || ! nh ) return;                  // image pas encore chargée
        var ratio = nw / nh;                         // largeur / hauteur

        // Largeur naturelle de l'image (déjà bornée par max-width du polaroïd/slot).
        var maxImgW = imgBox.getBoundingClientRect().width;
        if ( maxImgW <= 0 ) return;
        var naturalImgH = maxImgW / ratio;

        for ( var pass = 0; pass < 2; pass++ ) {
            var capH    = caption ? caption.getBoundingClientRect().height : 0;
            var budgetH = cardMaxH - capH;

            if ( budgetH >= naturalImgH ) {          // tient à taille naturelle → pas d'override
                polaroid.style.width = '';
                imgBox.style.width   = '';
                imgBox.style.height  = '';
                return;
            }

            var imgH = Math.max( MIN_IMG_H, budgetH );
            var imgW = imgH * ratio;
            if ( imgW > maxImgW ) { imgW = maxImgW; imgH = imgW / ratio; }

            imgBox.style.width   = imgW + 'px';
            imgBox.style.height  = imgH + 'px';
            polaroid.style.width = imgW + 'px';
        }
    }

    function fitAll() {
        // Reset la boîte du carousel AVANT de mesurer : sinon max-height:100% la
        // plafonnerait à la boîte réduite précédente et on ne « verrait » jamais la
        // hauteur naturelle plus grande quand le viewport s'agrandit (boîte figée).
        carousel.style.flex = '';
        carousel.style.height = '';

        if ( ! mql.matches ) { clearAll(); return; }  // petit écran : hauteur libre

        var polaroids = carousel.querySelectorAll( '.pf-polaroid' );
        if ( ! polaroids.length ) return;

        var colH = colHeight();
        if ( colH <= 0 ) return;

        var firstSlide = carousel.querySelector( '.pf-actualite-slide' );
        if ( ! firstSlide ) return;
        var scs = getComputedStyle( firstSlide );
        var padTop = parseFloat( scs.paddingTop );
        var padBot = parseFloat( scs.paddingBottom ); // réserve flèches/pagination (nulle si absentes)

        // 1) Ajuste chaque carte à la hauteur max (place des contrôles réservée).
        var cardMaxH = colH - padTop - padBot;
        polaroids.forEach( function ( p ) { fitOne( p, cardMaxH ); } );

        // 2) Réduit la boîte du carousel à la plus haute carte (+ réserve) pour que
        //    les flèches suivent la carte au lieu de rester collées au bas de la
        //    colonne. Fallback flex:1 (pleine colonne) tant qu'aucune image n'est
        //    chargée : on recalculera à leur chargement.
        var maxCard = 0, loaded = false;
        polaroids.forEach( function ( p ) {
            var img = p.querySelector( '.pf-actualite-image img' );
            if ( img && img.naturalWidth ) loaded = true;
            maxCard = Math.max( maxCard, p.offsetHeight );
        } );
        if ( ! loaded || maxCard <= 0 ) return;

        var boxH = Math.min( colH, maxCard + padTop + padBot );
        carousel.style.flex   = '0 0 auto';   // sinon flex:1 (basis 0%) ignore la hauteur
        carousel.style.height = boxH + 'px';
    }

    fitAll();
    window.addEventListener( 'load', fitAll );
    carousel.querySelectorAll( '.pf-actualite-image img' ).forEach( function ( img ) {
        if ( ! img.complete ) img.addEventListener( 'load', fitAll );
    } );
    mql.addEventListener( 'change', fitAll );

    if ( splide ) {
        splide.on( 'resized', fitAll );
        splide.on( 'refresh', fitAll );
    }

    var resizeTimer = null;
    window.addEventListener( 'resize', function () {
        clearTimeout( resizeTimer );
        resizeTimer = setTimeout( fitAll, 120 );
    } );
}

// ── Animation d'entrée des étagères ────────────────────────────────────────────

function initBookshelfIntro() {
    if ( window.matchMedia( '(prefers-reduced-motion: reduce)' ).matches ) return;

    var shelves = document.querySelectorAll( '.pf-accueil .pf-bookshelf' );
    if ( ! shelves.length ) return;

    var STEP_COVERS = 0.2; // s entre deux livres — mode covers
    var STEP_SPINES = 0.04; // s entre deux livres — mode spines
    // Un livre en covers reste invisible tant que son tour n'est pas venu : sur un
    // rayon long (le rayon « numérique » en compte 64), 0,2 s/livre laisserait le
    // dernier apparaître 13 s après le déclenchement. Le pas est donc resserré pour
    // que la cascade tienne dans ce budget (2,4 s = 12 livres au pas nominal, soit
    // le nb_books_first_displayed par défaut). Spines non concerné : ses dos sont
    // visibles dès le départ, seule leur inclinaison se redresse.
    var CASCADE_MAX_COVERS = 2.4; // s — durée totale max de la cascade, mode covers

    // Pose de départ posée dès le chargement, avant le scroll de déclenchement :
    // covers = livre absent (visibility) en couverture ouverte + agrandi, spines =
    // dos penché (visible, lui).
    shelves.forEach( function ( shelf ) { shelf.classList.add( 'pf-bookshelf-armed' ); } );

    var observer = new IntersectionObserver( function ( entries, obs ) {
        entries.forEach( function ( entry ) {
            if ( ! entry.isIntersecting ) return;
            var shelf = entry.target;
            var books = shelf.querySelectorAll( '.pf-book' );
            var step;
            if ( shelf.classList.contains( 'pf-bookshelf--spines' ) ) {
                step = STEP_SPINES;
            } else {
                step = Math.min( STEP_COVERS, CASCADE_MAX_COVERS / Math.max( 1, books.length - 1 ) );
            }
            books.forEach( function ( book, i ) {
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
