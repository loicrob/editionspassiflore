document.addEventListener( 'DOMContentLoaded', function () {
    var typingDone = initTypingAnimation();
    var carousel = initCarousel();
    initRelocateActualites( carousel );
    initActualiteFit( carousel );
    initScrollCarets();
    initBookshelfIntro();
    initEventTilesIntro();
    initWordmarkFit();

    var hero = document.querySelector( '.pf-hero' );

    typingDone.then( function () {
        // Révèle présentation + cartes catégories (cf. .pf-hero-reveal, accueil.css)
        // seulement une fois le typing réellement terminé — pas de délai fixe estimé.
        if ( hero ) hero.classList.add( 'pf-hero-reveal' );
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

// Mobile + tablette : flèches masquées, le glissement tactile suffit à naviguer.
// La pagination (points), elle, reste affichée — simple indicateur de position,
// pas un contrôle redondant avec le geste. Critère = capacité de pointage, PAS la
// largeur : les breakpoints de Splide sont des seuils en px, or les tablettes
// vont de 744px (iPad mini) à 1366px (iPad Pro 13" en paysage) — aucun seuil ne
// sépare proprement tablette et ordinateur.
// `hover: none` + `pointer: coarse` = doigt sans survol possible, donc tactile.
var PF_TACTILE = window.matchMedia( '(hover: none) and (pointer: coarse)' );

// Classe posée sur le carousel quand les flèches sont masquées (tactile). C'est
// la seule source de vérité : le CSS y accroche la réserve sous le slide
// (padding-bottom, réduite à la seule pagination), et initActualiteFit() lit ce
// padding pour dimensionner la carte et la boîte du carousel — le CSS n'a pas à
// redupliquer la media query tactile (il suivrait sinon sa propre logique,
// dérivable de celle-ci).
var PF_SANS_FLECHES = 'pf-actualites-carousel--sans-fleches';

function initCarousel() {
    var el = document.querySelector( '.pf-actualites-carousel' );
    if ( ! el ) return null;

    el.classList.toggle( PF_SANS_FLECHES, PF_TACTILE.matches );

    var splide = new Splide( el, {
        type        : 'loop',
        autoplay    : 'pause',
        interval    : 6000,
        pauseOnHover: true,
        arrows      : ! PF_TACTILE.matches,
        pagination  : true,
        speed       : 800,
        gap         : 0,
        perPage     : 1,
    } ).mount();

    // Cas rare (souris branchée sur une tablette, mode bureau) : les flèches
    // apparaissent/disparaissent, donc la réserve sous le slide change → refresh()
    // pour que la boîte du carousel soit recalculée (initActualiteFit écoute
    // 'refresh'), sinon elle resterait dimensionnée pour l'état précédent.
    PF_TACTILE.addEventListener( 'change', function ( e ) {
        el.classList.toggle( PF_SANS_FLECHES, e.matches );
        splide.options = { arrows: ! e.matches };
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

// ── Actualités (hero + « En ce moment ») : ajuste la carte du carousel à la
// hauteur disponible ────────────────────────────────────────────────────────
// Sur grand écran (≥769px), l'encart actualités occupe la colonne droite du hero,
// de hauteur fixe (100svh − header). Sur mobile+tablette (<769px), relogé dans
// « En ce moment » (hauteur libre par ailleurs), même traitement pour qu'une
// diapo ne dépasse jamais l'écran : budget = 100lvh − header collant − une marge.
// lvh (pas vh) : évite que le budget change quand la barre d'URL mobile se
// replie/réapparaît (window.innerHeight suit la barre, contrairement à lvh).
// Dans les deux cas, deux problèmes, insolubles en CSS pur (circularité de
// hauteur + effondrement des hauteurs en % dès qu'on retire les flex:1 de la
// chaîne .pf-actualites-carousel/.splide__track, bug WebKit déjà documenté plus
// bas dans accueil.css) :
//
//   1. Carte trop HAUTE pour son budget : image + légende dépassent → le
//      polaroïd (max-height:100% + overflow:hidden) rognait le bas (le bouton).
//      On pose une largeur/hauteur explicites sur l'image (fit-content de la
//      carte + min-width:100% de la légende suivent), l'image rétrécit sur ses
//      deux axes. La largeur ne peut pas dériver en CSS car fit-content se cale
//      sur la largeur INTRINSÈQUE de l'image, pas sur sa largeur contrainte-en-
//      hauteur.
//
//   2. Budget trop GRAND pour la carte : le carousel (flex:1) remplissait tout
//      l'espace dispo, carte centrée dedans mais flèches/pagination collées en
//      bas (bottom:0) → grand vide sous la carte. On réduit la boîte du
//      carousel à la plus haute carte + la réserve des contrôles ;
//      justify-content:center (CSS) la recentre. On prend la plus haute carte
//      (pas la courante) → boîte stable entre diapos.

function initActualiteFit( splide ) {
    var carousel = document.querySelector( '.pf-actualites-carousel' );
    if ( ! carousel ) return;

    var slot = document.querySelector( '.pf-hero-actualites-slot' );
    var mql  = window.matchMedia( '(min-width: 769px)' );
    var MIN_IMG_H   = 60; // px — plancher si la légende est démesurée (évite l'effondrement)
    var MIN_CORPS_H = 60; // px — plancher de .pf-actualite-corps rendu scrollable (garde un peu de texte visible)

    // Résout une longueur --pf-* (rem) en pixels : getComputedStyle sur une custom
    // property renvoie la valeur BRUTE ("1.5rem"), pas la valeur résolue — un
    // élément jetable la fait résoudre (même idiome que event-single-media.js).
    function pxVar( name ) {
        var probe = document.createElement( 'div' );
        probe.style.cssText = 'position:absolute;visibility:hidden;height:var(' + name + ');width:0;';
        document.body.appendChild( probe );
        var px = parseFloat( getComputedStyle( probe ).height ) || 0;
        document.body.removeChild( probe );
        return px;
    }

    // 100lvh en px, même idiome que pxVar. Repli sur innerHeight si lvh n'est
    // pas supporté (le budget redevient alors sensible à la barre d'URL, mais
    // reste fonctionnel).
    function pxLvh() {
        var probe = document.createElement( 'div' );
        probe.style.cssText = 'position:absolute;visibility:hidden;height:100lvh;width:0;';
        document.body.appendChild( probe );
        var px = parseFloat( getComputedStyle( probe ).height ) || window.innerHeight;
        document.body.removeChild( probe );
        return px;
    }

    // Hauteur dispo pour l'encart :
    //   - grand écran : hauteur intérieure du slot (colonne droite du hero),
    //     STABLE (ne dépend que du viewport, pas de la taille de la carte ni de
    //     la boîte réduite du carousel) → base de mesure fiable, sans boucle de
    //     feedback ;
    //   - mobile/tablette : le slot est masqué (encart relogé dans « En ce
    //     moment », hauteur libre par ailleurs) → 100lvh − header collant − une
    //     marge, pour la même raison de stabilité (insensible à la barre d'URL).
    function colHeight() {
        if ( mql.matches ) {
            if ( ! slot ) return 0;
            var cs = getComputedStyle( slot );
            return slot.clientHeight
                - parseFloat( cs.paddingTop ) - parseFloat( cs.paddingBottom );
        }
        var offset = parseFloat(
            getComputedStyle( document.documentElement ).getPropertyValue( '--pf-sticky-offset' )
        ) || 0; // posé en JS (recherche-globale.js) → déjà en px
        return pxLvh() - offset - pxVar( '--pf-space-12' );
    }

    // Ajuste UNE carte pour qu'image + légende tiennent dans cardMaxH ; si le
    // texte à lui seul dépasse encore (légende très longue, image déjà au
    // plancher, ou slide sans image), .pf-actualite-corps (titre+texte, jamais
    // le bouton) devient scrollable plutôt que de laisser la carte — et avec
    // elle toute la boîte du carrousel, cf. fitAll — grandir au-delà du budget.
    function fitOne( polaroid, cardMaxH ) {
        var imgBox  = polaroid.querySelector( '.pf-actualite-image' );
        var img     = imgBox && imgBox.querySelector( 'img' );
        var caption = polaroid.querySelector( '.pf-actualite-contenu' );
        var corps   = polaroid.querySelector( '.pf-actualite-corps' );

        polaroid.style.width = '';
        if ( imgBox ) { imgBox.style.width = ''; imgBox.style.height = ''; }
        if ( corps ) corps.style.maxHeight = '';

        var imgH = 0; // hauteur finalement retenue pour l'image (0 si absente/pas encore chargée)

        if ( imgBox && img && img.naturalWidth && img.naturalHeight ) {
            var ratio = img.naturalWidth / img.naturalHeight; // largeur / hauteur

            // Largeur naturelle de l'image (déjà bornée par max-width du polaroïd/slot).
            var maxImgW = imgBox.getBoundingClientRect().width;

            if ( maxImgW > 0 ) {
                var naturalImgH = maxImgW / ratio;
                imgH = naturalImgH;

                // Mesuré à la largeur NATURELLE (pré-réduction) de la légende, en UNE
                // seule passe — jamais re-mesuré à la largeur réduite de l'image. Une
                // 2e passe re-mesurait la légende dans la colonne déjà rétrécie : sur
                // une légende très longue, la rétrécir l'allonge encore (plus de retours
                // à la ligne), ce qui réduisait l'image plus encore au tour suivant —
                // une spirale qui pouvait écraser l'image à sa largeur plancher et la
                // colonne de texte à quelques px, explosant la hauteur de la légende
                // (constaté : colonne de 4px, légende de 2089px). Le résidu que cette
                // seule passe laisse passer est maintenant absorbé par le plafonnement
                // scrollable de .pf-actualite-corps ci-dessous, qui n'a pas ce défaut.
                var capH    = caption ? caption.getBoundingClientRect().height : 0;
                var budgetH = cardMaxH - capH;

                if ( budgetH < naturalImgH ) {            // ne tient pas à taille naturelle → réduire
                    imgH = Math.max( MIN_IMG_H, budgetH );
                    var imgW = imgH * ratio;
                    if ( imgW > maxImgW ) { imgW = maxImgW; imgH = imgW / ratio; }

                    imgBox.style.width   = imgW + 'px';
                    imgBox.style.height  = imgH + 'px';
                    polaroid.style.width = imgW + 'px';
                }
            }
        }

        // La légende peut encore dépasser cardMaxH une fois l'image posée à sa
        // taille finale (légende très longue, ou slide sans image) : on plafonne
        // alors SEULEMENT .pf-actualite-corps (titre+texte, jamais le bouton) à
        // la place qui reste, et il devient scrollable — plutôt que de laisser
        // la carte dépasser son budget (et avec elle toute la boîte du
        // carrousel, cf. fitAll). ⚠️ Le dépassement ne peut PAS se lire sur
        // polaroid.getBoundingClientRect() : .pf-polaroid a lui-même
        // max-height:100% (= cardMaxH) + overflow:hidden, donc sa boîte est
        // déjà ramenée à cardMaxH pile — elle ne révèle jamais de combien ses
        // enfants (flex:0 0 auto, jamais rétrécis) le dépassent réellement. On
        // recalcule donc le total depuis les enfants : l'image (taille retenue
        // ci-dessus) + la légende, dont la propre rect NATURELLE (rien sur
        // .pf-actualite-contenu ne la plafonne) reste fiable.
        if ( corps && caption ) {
            var contenuH  = caption.getBoundingClientRect().height;
            var overflowH = ( imgH + contenuH ) - cardMaxH;
            if ( overflowH > 0 ) {
                var corpsH = corps.getBoundingClientRect().height;
                corps.style.maxHeight = Math.max( MIN_CORPS_H, corpsH - overflowH ) + 'px';
            }
        }
    }

    function fitAll() {
        // Reset la boîte du carousel AVANT de mesurer : sinon max-height:100% la
        // plafonnerait à la boîte réduite précédente et on ne « verrait » jamais la
        // hauteur naturelle plus grande quand le viewport s'agrandit (boîte figée).
        carousel.style.flex = '';
        carousel.style.height = '';

        var polaroids = carousel.querySelectorAll( '.pf-polaroid' );
        if ( ! polaroids.length ) return;

        var colH = colHeight();
        if ( colH <= 0 ) return;

        var firstSlide = carousel.querySelector( '.pf-actualite-slide' );
        if ( ! firstSlide ) return;
        var scs = getComputedStyle( firstSlide );
        var padTop = parseFloat( scs.paddingTop );
        var padBot = parseFloat( scs.paddingBottom ); // réserve pagination (+ flèches sur pointeur fin)

        // 1) Ajuste chaque carte à la hauteur max (place des contrôles réservée).
        var cardMaxH = colH - padTop - padBot;
        polaroids.forEach( function ( p ) { fitOne( p, cardMaxH ); } );

        // 2) Réduit la boîte du carousel à la plus haute carte (+ réserve) pour que
        //    les contrôles suivent la carte au lieu de rester collés au bas du
        //    budget disponible. Fallback flex:1 (plein budget) tant qu'aucune image
        //    n'est chargée : on recalculera à leur chargement.
        //    ⚠️ PAS de Math.min avec colH ici : à ce stade (avant que carousel.style.height
        //    soit posé) max-height:100% de la chaîne slide/polaroid résout à "none" (aucun
        //    ancêtre à hauteur définie) — offsetHeight reflète donc la hauteur NATURELLE de
        //    la carte, jamais rognée. Si on plafonnait quand même boxH à colH ici, la chaîne
        //    de max-height:100% deviendrait active APRÈS coup (carousel.style.height posé
        //    plus bas) et rognerait la légende via overflow:hidden — cas extrême où même
        //    l'image au plancher (MIN_IMG_H) ne suffit pas à faire tenir la légende dans le
        //    budget. Mieux vaut dépasser le budget que tronquer le texte/bouton.
        //    Depuis le plafonnement scrollable de .pf-actualite-corps (fitOne), ce cas est
        //    rarissime : p.offsetHeight tient déjà dans cardMaxH sauf si MIN_CORPS_H +
        //    MIN_IMG_H + le reste de la légende (padding, bouton) dépasse encore le budget.
        var maxCard = 0, loaded = false;
        polaroids.forEach( function ( p ) {
            var img = p.querySelector( '.pf-actualite-image img' );
            if ( img && img.naturalWidth ) loaded = true;
            maxCard = Math.max( maxCard, p.offsetHeight );
        } );
        if ( ! loaded || maxCard <= 0 ) return;

        var boxH = maxCard + padTop + padBot;
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

    var STEP_COVERS = 0.08; // s entre deux livres — mode covers
    var STEP_SPINES = 0.02; // s entre deux livres — mode spines
    // Pas FIXE, sans plafond de durée totale : les étagères de l'accueil sont des
    // scrollers horizontaux, seuls les premiers livres sont dans le champ — les
    // suivants apparaissent hors écran, à droite du conteneur. La longueur du rayon
    // (le « numérique » compte 64 livres) n'a donc aucune incidence sur ce qu'on
    // voit, et resserrer le pas pour tenir un budget global ne ferait qu'accélérer
    // la seule partie visible de la cascade.

    // Pose de départ posée dès le chargement, avant le scroll de déclenchement :
    // covers = livre absent (visibility) et agrandi, couverture fermée ; spines =
    // dos penché (visible, lui).
    shelves.forEach( function ( shelf ) { shelf.classList.add( 'pf-bookshelf-armed' ); } );

    var observer = new IntersectionObserver( function ( entries, obs ) {
        entries.forEach( function ( entry ) {
            if ( ! entry.isIntersecting ) return;
            var shelf = entry.target;
            var books = shelf.querySelectorAll( '.pf-book' );
            var step = shelf.classList.contains( 'pf-bookshelf--spines' ) ? STEP_SPINES : STEP_COVERS;
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

// ── Animation d'entrée des cartes événement (« En ce moment ») ─────────────────
// Même idiome que initBookshelfIntro() ci-dessus (armé→intro au scroll, pas
// de délai fixe) mais version simplifiée : pas de pose de départ scale/rotate
// à tenir, juste un fondu+montée. .pf-event-tile est aussi rendu ailleurs
// (fiche livre, popup carte) — scopé à .pf-en-ce-moment-events, propre à
// cette page, pour ne pas les affecter.
function initEventTilesIntro() {
    if ( window.matchMedia( '(prefers-reduced-motion: reduce)' ).matches ) return;

    var row = document.querySelector( '.pf-en-ce-moment-events .pf-event-tiles-scroll' );
    if ( ! row ) return;
    var tiles = row.querySelectorAll( '.pf-event-tile' );
    if ( ! tiles.length ) return;

    var STEP = 0.08; // s entre deux cartes

    row.classList.add( 'pf-event-tiles-armed' );

    var observer = new IntersectionObserver( function ( entries, obs ) {
        entries.forEach( function ( entry ) {
            if ( ! entry.isIntersecting ) return;
            tiles.forEach( function ( tile, i ) {
                tile.style.setProperty( '--pf-intro-delay', ( i * STEP ).toFixed( 2 ) + 's' );
            } );
            row.classList.remove( 'pf-event-tiles-armed' );
            row.classList.add( 'pf-event-tiles-intro' );
            obs.unobserve( row );
        } );
    }, { rootMargin: '0px 0px -25% 0px' } );

    observer.observe( row );
}
