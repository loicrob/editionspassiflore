<?php
/**
 * Template for the home page — /
 * WordPress uses this file automatically when a static front page is set.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

get_header();

$page_id = get_queried_object_id();

// Hero background image (SCF field hero_image on the Accueil page)
$hero_image_id  = get_field( 'hero_image', $page_id );
$hero_image_url = $hero_image_id ? wp_get_attachment_image_url( $hero_image_id, 'full' ) : '';

// Hero presentation paragraph (SCF field paragraphe_de_presentation on the Accueil page)
$hero_presentation = get_field( 'paragraphe_de_presentation', $page_id );

// Actualités carousel
$slides     = get_field( 'actualites', $page_id );
$has_slides = ! empty( $slides );

// Upcoming events
$events = function_exists( 'passiflore_get_upcoming_events' ) ? passiflore_get_upcoming_events() : [];

// Section title links
$catalogue_url  = function_exists( 'wc_get_page_permalink' ) ? wc_get_page_permalink( 'shop' ) : get_post_type_archive_link( 'product' );
$events_url     = function_exists( 'tribe_get_events_link' ) ? tribe_get_events_link() : get_post_type_archive_link( 'tribe_events' );

// Hero category links (product_cat archives → filtered catalogue)
$litterature_link = get_term_link( 'litterature', 'product_cat' );
$litterature_url  = is_wp_error( $litterature_link ) ? '' : $litterature_link;
$culture_link     = get_term_link( 'culture-sud-ouest', 'product_cat' );
$culture_url      = is_wp_error( $culture_link ) ? '' : $culture_link;
?>

<?php /*
 Primer --pf-sticky-offset : posé AVANT le premier paint du hero.
 Le hero fait `calc(100svh - var(--pf-sticky-offset))`. Sans ce primer, la variable
 tombe sur son défaut (:root = 80px, cf. style.css) au premier rendu — recherche-globale.js
 est un script externe en footer, exécuté trop tard → le hero démarrerait à la mauvaise
 hauteur puis se recalerait quand la vraie valeur arrive → .pf-hero-categories sauterait.
 Ce script inline, placé entre le header (déjà dans le DOM) et le hero (pas encore parsé),
 mesure le header et fixe la variable juste à temps.
 Ensuite recherche-globale.js met à jour --pf-sticky-offset au resize / orientationchange /
 load — PAS au scroll (le header ne change pas de hauteur ; s'abstenir au scroll évite le
 jiggle iOS où getBoundingClientRect suit la barre d'outils Safari). svh (≠ dvh) rend par
 ailleurs la hauteur du hero stable face au repli de cette barre.
 ⚠ Garder la liste de sélecteurs synchronisée avec recherche-globale.js. */ ?>
<script>
(function () {
	var SEL = [
		'.site-header-inner-wrap.kadence-sticky-header',
		'.site-header-wrap.kadence-sticky-header',
		'.kadence-sticky-header',
		'#masthead'
	];
	var best = 0;
	SEL.forEach( function ( sel ) {
		document.querySelectorAll( sel ).forEach( function ( el ) {
			if ( ! el.offsetHeight ) return;
			var b = el.getBoundingClientRect().bottom;
			if ( b > best ) best = b;
		} );
	} );
	var ab = document.getElementById( 'wpadminbar' );
	if ( ab && best === 0 ) {
		var r = ab.getBoundingClientRect();
		if ( r.bottom > 0 ) best = r.bottom;
	}
	document.documentElement.style.setProperty( '--pf-sticky-offset', Math.max( 0, best ) + 'px' );
})();
</script>

<div id="primary" class="content-area">
    <main id="main" class="site-main pf-accueil" role="main">

        <!-- ══ HERO ══════════════════════════════════════════════════════════ -->
        <section
            class="pf-hero"
            <?php if ( $hero_image_url ) : ?>
            style="--pf-hero-bg: url('<?php echo esc_url( $hero_image_url ); ?>')"
            <?php endif; ?>
        >
            <div class="pf-hero-inner site-container">
            <div class="pf-hero-haut">
            <div class="pf-hero-contenu">
                <div class="pf-hero-marque">
                    <div class="pf-hero-logo-wrap">
                        <?php
                        $icon_url = get_site_icon_url( 256 )
                            ?: content_url( 'uploads/2026/04/cropped-icone.png' );
                        ?>
                        <a href="<?php echo esc_url( home_url( '/' ) ); ?>"
                           aria-label="<?php echo esc_attr( get_bloginfo( 'name' ) ); ?>">
                            <img src="<?php echo esc_url( $icon_url ); ?>"
                                 alt=""
                                 width="90"
                                 height="90" />
                        </a>
                    </div>
                    <div class="pf-hero-wordmark">
                        <img src="<?php echo esc_url( get_stylesheet_directory_uri() . '/assets/img/Editions_Passiflore_logo-simple_text_Editions.png' ); ?>"
                             alt="Editions"
                             width="265"
                             height="76" />
                        <img src="<?php echo esc_url( get_stylesheet_directory_uri() . '/assets/img/Editions_Passiflore_logo-simple_text_Passiflore.png' ); ?>"
                             alt="Passiflore"
                             width="430"
                             height="76" />
                    </div>
                </div>
                <div class="pf-hero-sous-titre-wrap">
                    <div class="pf-hero-ligne pf-hero-sous-titre" id="pf-ligne-3"></div>
                    <div class="pf-hero-ligne pf-hero-sous-titre" id="pf-ligne-4"></div>
                </div>
                <?php if ( $hero_presentation ) : ?>
                <p id="pf-hero-presentation" class="pf-hero-presentation"><?php
                    // <br> ne respecte ni display:block ni margin dans les moteurs de rendu
                    // (Chrome/Blink traite <br> comme un saut de ligne forcé, indépendant de
                    // son style calculé) : chaque ligne est donc un vrai bloc pour espacer.
                    foreach ( preg_split( '/\r\n|\r|\n/', $hero_presentation ) as $pf_hero_line ) {
                        if ( '' === trim( $pf_hero_line ) ) {
                            continue;
                        }
                        echo '<span class="pf-hero-presentation__line">' . esc_html( $pf_hero_line ) . '</span>';
                    }
                ?></p>
                <?php endif; ?>

                <div class="pf-hero-categories">
                    <a class="pf-card pf-hero-cat" href="<?php echo esc_url( $litterature_url ); ?>">
                        <span class="pf-badge pf-badge--accent pf-hero-cat-badge">Littérature</span>
                        <div class="pf-card-content">
                            <span class="pf-card-text">Des romans exigeants et accessibles, générateurs d’émotions</span>
                        </div>
                    </a>
                    <a class="pf-card pf-hero-cat" href="<?php echo esc_url( $culture_url ); ?>">
                        <span class="pf-badge pf-badge--accent pf-hero-cat-badge">Culture Sud-Ouest</span>
                        <div class="pf-card-content">
                            <span class="pf-card-text">Des beaux livres et des ouvrages sur nos sports et notre patrimoine</span>
                        </div>
                    </a>
                </div>
            </div><!-- .pf-hero-contenu -->

                <div class="pf-hero-actualites-slot">
                    <?php if ( $has_slides ) : ?>
                    <div class="pf-en-ce-moment-actualites">
                        <header class="pf-section-header">
                            <h3 class="pf-section-titre pf-titre-3">Actualités</h3>
                        </header>
                        <div class="splide pf-actualites-carousel" aria-label="<?php esc_attr_e( 'Actualités', 'kadence-child' ); ?>">
                            <div class="splide__track">
                                <ul class="splide__list">
                                    <?php foreach ( $slides as $slide ) :
                                        $image_id   = $slide['image'] ?? null;
                                        $titre      = $slide['titre'] ?? '';
                                        $contenu    = $slide['contenu'] ?? '';
                                        $lien       = $slide['lien'] ?? '';
                                        $label_lien = $slide['label_lien'] ?? '';

                                        // Options de mise en forme par diapo (sous-champs SCF lus
                                        // par nom → indépendants des IDs de champ, cf. recréation en ligne).
                                        $titre_classes = 'pf-actualite-titre';
                                        if ( 'grande' === ( $slide['taille_de_titre'] ?? '' ) )  $titre_classes .= ' pf-actualite-titre--grande';
                                        if ( 'centre' === ( $slide['aligner_le_titre'] ?? '' ) )  $titre_classes .= ' pf-actualite-titre--centre';

                                        $texte_classes = 'pf-actualite-texte';
                                        if ( 'grande' === ( $slide['taille_de_description'] ?? '' ) )  $texte_classes .= ' pf-actualite-texte--grande';
                                        if ( 'centre' === ( $slide['aligner_la_description'] ?? '' ) )  $texte_classes .= ' pf-actualite-texte--centre';

                                        $lien_attrs = ! empty( $slide['ouvrir_dans_un_nouvel_onglet'] )
                                            ? ' target="_blank" rel="noopener noreferrer"'
                                            : '';
                                    ?>
                                    <li class="splide__slide pf-actualite-slide">
                                        <div class="pf-polaroid">

                                            <?php if ( $image_id ) :
                                                // Ratio inline → permet à .pf-actualite-image d'avoir une
                                                // largeur définie (hauteur × ratio) pour que le polaroïd
                                                // épouse la largeur réelle de l'image contrainte en hauteur.
                                                $img_meta  = wp_get_attachment_image_src( $image_id, 'large' );
                                                $img_ratio = ( $img_meta && $img_meta[1] && $img_meta[2] )
                                                    ? $img_meta[1] . ' / ' . $img_meta[2]
                                                    : '';
                                            ?>
                                            <div class="pf-actualite-image"<?php echo $img_ratio ? ' style="aspect-ratio: ' . esc_attr( $img_ratio ) . ';"' : ''; ?>>
                                                <?php echo wp_get_attachment_image( $image_id, 'large', false, [
                                                    'alt'     => esc_attr( $titre ),
                                                    'loading' => 'eager',
                                                ] ); ?>
                                            </div>
                                            <?php endif; ?>

                                            <?php if ( $titre || $contenu || $lien ) : ?>
                                            <div class="pf-actualite-contenu">
                                                <div class="pf-actualite-corps">
                                                    <?php if ( $titre ) : ?>
                                                    <h3 class="<?php echo esc_attr( $titre_classes ); ?>"><?php echo esc_html( $titre ); ?></h3>
                                                    <?php endif; ?>

                                                    <?php if ( $contenu ) : ?>
                                                    <div class="<?php echo esc_attr( $texte_classes ); ?>">
                                                        <?php echo wp_kses_post( $contenu ); ?>
                                                    </div>
                                                    <?php endif; ?>
                                                </div>

                                                <?php if ( $lien ) : ?>
                                                <a class="pf-actualite-lien pf-btn pf-btn--primary" href="<?php echo esc_url( $lien ); ?>"<?php echo $lien_attrs; ?>>
                                                    <?php echo esc_html( $label_lien ?: __( 'En savoir plus', 'kadence-child' ) ); ?>
                                                </a>
                                                <?php endif; ?>
                                            </div>
                                            <?php endif; ?>

                                        </div>
                                    </li>
                                    <?php endforeach; ?>
                                </ul>
                            </div>
                        </div>
                    </div>
                    <?php endif; ?>
                </div><!-- .pf-hero-actualites-slot -->
            </div><!-- .pf-hero-haut -->
            </div><!-- .pf-hero-inner -->

            <button type="button" class="pf-hero-scroll" data-scroll-to="pf-en-ce-moment" aria-label="Découvrir la suite">
                <svg width="32" height="18" viewBox="0 0 22 12" fill="none" aria-hidden="true"><path d="M1 1 11 11 21 1" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
            </button>
            <!--
            <div class="pf-scroll-caret-wrap">
                <button class="pf-scroll-caret" aria-label="Voir la suite">
                    <svg width="20" height="11" viewBox="0 0 20 11" fill="none" aria-hidden="true"><path d="M1 1 10 10 19 1" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
                </button>
            </div>
            -->
        </section>

        <!-- ══ EN CE MOMENT ══════════════════════════════════════════════════ -->
        <section id="pf-en-ce-moment" class="pf-en-ce-moment-section site-container">

            <header class="pf-section-header">
                <h2 class="pf-section-titre pf-titre-2">En ce moment chez Passiflore…</h2>
            </header>

            <?php if ( $has_slides || ! empty( $events ) ) : ?>
            <div class="pf-en-ce-moment-cols">

            <?php if ( ! empty( $events ) ) : ?>
            <div class="pf-en-ce-moment-events">

                <header class="pf-section-header">
                    <h3 class="pf-section-titre pf-titre-3"><?php if ( $events_url ) : ?><a href="<?php echo esc_url( $events_url ); ?>">Événements à venir</a><?php else : ?>Événements à venir<?php endif; ?></h3>
                </header>

                <?php echo Passiflore_Event_Tiles::render_row( $events ); ?>

            </div>
            <?php endif; ?>

            </div><!-- .pf-en-ce-moment-cols -->
            <?php endif; ?>

            <div class="pf-etagere-bloc">
                <h3 class="pf-section-titre pf-titre-3"><a href="<?php echo esc_url( add_query_arg( 'decouvrir', 'nouveautes', get_term_link( 'litterature', 'product_cat' ) ) ); ?>">Nouveautés littérature</a></h3>
                <?php echo do_shortcode( '[passiflore_etagere mode="scroll" display="covers" decouvrir="nouveautes" category="litterature"]' ); ?>
            </div>

            <div class="pf-etagere-bloc">
                <h3 class="pf-section-titre pf-titre-3"><a href="<?php echo esc_url( add_query_arg( 'decouvrir', 'nouveautes', get_term_link( 'culture-sud-ouest', 'product_cat' ) ) ); ?>">Nouveautés Culture Sud-Ouest</a></h3>
                <?php echo do_shortcode( '[passiflore_etagere mode="scroll" display="covers" decouvrir="nouveautes" category="culture-sud-ouest"]' ); ?>
            </div>

        </section>

        <!-- ══ AU CATALOGUE ══════════════════════════════════════════════════ -->
        <section class="pf-au-catalogue-section site-container">

            <header class="pf-section-header">
                <h2 class="pf-section-titre pf-titre-2">Au catalogue</h2>
            </header>

            <div class="pf-etagere-bloc">
                <h3 class="pf-section-titre pf-titre-3"><a href="<?php echo esc_url( get_term_link( 'litterature-generale', 'product_cat' ) ); ?>">Littérature générale</a></h3>
                <?php echo do_shortcode( '[passiflore_etagere mode="scroll" display="spines" category="litterature-generale" format="classique"]' ); ?>
            </div>

            <div class="pf-etagere-bloc">
                <h3 class="pf-section-titre pf-titre-3"><a href="<?php echo esc_url( get_term_link( 'culture-sud-ouest', 'product_cat' ) ); ?>">Culture Sud-Ouest</a></h3>
                <?php echo do_shortcode( '[passiflore_etagere mode="scroll" display="spines" category="culture-sud-ouest" orderby="hauteur"]' ); ?>
            </div>

            <div class="pf-etagere-bloc">
                <h3 class="pf-section-titre pf-titre-3"><a href="<?php echo esc_url( add_query_arg( 'format', 'grands-caracteres', $catalogue_url ) ); ?>">Grands caractères</a></h3>
                <?php echo do_shortcode( '[passiflore_etagere mode="scroll" display="covers" format="grands-caracteres"]' ); ?>
            </div>

            <div class="pf-etagere-bloc">
                <h3 class="pf-section-titre pf-titre-3"><a href="<?php echo esc_url( add_query_arg( 'format', 'numerique', $catalogue_url ) ); ?>">Formats numériques</a></h3>
                <?php echo do_shortcode( '[passiflore_etagere mode="scroll" display="covers" format="numerique"]' ); ?>
            </div>

        </section>


    </main>
</div>

<?php get_footer(); ?>
