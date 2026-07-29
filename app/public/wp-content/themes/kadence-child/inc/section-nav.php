<?php
/**
 * Composant partagé « section-nav » — nav sticky (verticale desktop / horizontale
 * mobile) + scrollspy, pour les pages à sections (fiche livre, fiche événement).
 *
 * CSS : .pf-sectionnav / .pf-body / .pf-section dans style.css.
 * JS  : assets/js/section-nav.js (contrôleur, enqueué en footer par les pages).
 *
 * @package kadence-child
 */

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Filtre les sections sans contenu (pas d'ancre morte). Chaque section :
 * [ 'label' => string, 'html' => string ] ; ancre/id = sanitize_title($label).
 */
function pf_sectionnav_filter( array $sections ) {
	return array_values( array_filter( $sections, static function ( $s ) {
		return ! empty( $s['label'] ) && isset( $s['html'] ) && '' !== trim( (string) $s['html'] );
	} ) );
}

/**
 * Barre de nav (`<nav class="pf-sectionnav">`) + primer inline. Rendue seulement
 * à partir de 3 sections (en dessous un index n'apporte rien) → '' sinon. Les
 * liens listent TOUTES les sections passées, même si elles sont ensuite rendues
 * dans plusieurs conteneurs (fiche événement : zone haute + zone pleine largeur).
 */
function pf_sectionnav_bar( array $sections ) {
	$sections = pf_sectionnav_filter( $sections );
	if ( count( $sections ) < 3 ) return '';

	ob_start();
	echo '<nav class="pf-sectionnav" aria-label="Navigation dans la page">';
	echo '<div class="pf-scroll-fade">';
	echo '<div class="pf-sectionnav__track">';
	foreach ( $sections as $s ) {
		$slug = sanitize_title( $s['label'] );
		// .menu-item-link : exclut ces liens de la règle globale `.entry-content a:not(.menu-item-link)…`
		// (style.css) — nécessaire quand la nav est rendue à l'intérieur de `.entry-content`
		// (fiche événement) pour ne pas hériter du style « lien de contenu ». Inerte ailleurs.
		// data-label : libellé recopié pour le double invisible en gras (`a::after` dans
		// style.css) qui réserve la largeur de l'état actif — cf. le commentaire là-bas.
		echo '<a class="menu-item-link" data-label="' . esc_attr( $s['label'] ) . '" href="#' . esc_attr( $slug ) . '">' . esc_html( $s['label'] ) . '</a>';
	}
	echo '</div>';
	echo '</div>';
	echo '</nav>';

	/* Primer --pf-sticky-offset / --pf-sectionnav-h : posé AVANT que le navigateur ne
	 * traite l'ancre de l'URL (#slug), qui peut faire défiler la page vers une section
	 * dès le chargement, avant que recherche-globale.js (footer) n'ait rien calculé.
	 * ⚠ Garder la mesure synchronisée avec recherche-globale.js. */
	echo '<script>(function(){var best=0;var h=document.getElementById("masthead");if(h&&h.offsetHeight)best=h.getBoundingClientRect().bottom;var ab=document.getElementById("wpadminbar");if(ab&&best===0){var r=ab.getBoundingClientRect();if(r.bottom>0)best=r.bottom;}document.documentElement.style.setProperty("--pf-sticky-offset",Math.max(0,best)+"px");var nav=document.querySelector(".pf-sectionnav");if(nav)document.documentElement.style.setProperty("--pf-sectionnav-h",nav.offsetHeight+"px");})();</script>';
	return ob_get_clean();
}

/**
 * Blocs de sections (`<div class="pf-sections"><section class="pf-section">…`).
 */
function pf_sectionnav_sections( array $sections ) {
	$sections = pf_sectionnav_filter( $sections );
	if ( empty( $sections ) ) return '';

	ob_start();
	echo '<div class="pf-sections">';
	foreach ( $sections as $s ) {
		$slug = sanitize_title( $s['label'] );
		echo '<section id="' . esc_attr( $slug ) . '" class="pf-section">';
		echo '<h2 class="pf-section__title">' . esc_html( $s['label'] ) . '</h2>';
		echo $s['html'];
		echo '</section>';
	}
	echo '</div>';
	return ob_get_clean();
}

/**
 * Layout à sections « tout-en-un » (fiche livre) : nav + sections dans la grille
 * `.pf-body` si ≥ 3 sections, sinon les sections seules pleine largeur. (La fiche
 * événement compose elle-même bar/sections en 2 zones — cf. inc/event-single.php.)
 */
function pf_render_sectionnav( array $sections ) {
	$sections = pf_sectionnav_filter( $sections );
	if ( empty( $sections ) ) return '';

	$bar  = pf_sectionnav_bar( $sections );
	$secs = pf_sectionnav_sections( $sections );

	if ( '' !== $bar ) {
		return '<div class="pf-body">' . $bar . $secs . '</div>';
	}
	return $secs;
}

/**
 * Neutralise le scroll d'ancre de Kadence sur les pages à section-nav : son JS
 * (navigation.min.js → scrollToElement) cale les ancres avec le seul offset du
 * header, ignorant scroll-margin-top et la nav sticky → titre masqué sous la nav.
 * La classe `no-anchor-scroll` court-circuite ce comportement ; le scroll d'ancre
 * est géré à la main dans assets/js/section-nav.js.
 */
add_filter( 'body_class', function ( $classes ) {
	if ( is_product() || is_singular( 'tribe_events' ) ) {
		$classes[] = 'no-anchor-scroll';
	}
	return $classes;
} );
