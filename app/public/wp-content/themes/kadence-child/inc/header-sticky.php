<?php
/**
 * Header collant — débranche le sticky JS de Kadence.
 *
 * Kadence ne pose jamais `position: sticky`. Il rend DEUX headers (#main-header
 * pour l'ordinateur, #mobile-header), n'en affiche qu'un par media query, et
 * bascule celui qui est visible en `position: fixed` au scroll (navigation.min.js,
 * `initStickyHeader`). Deux défauts mesurés le 2026-07-28 :
 *
 * 1. Le JS bascule à `1024 <= innerWidth`, le CSS à `min-width: 1025px`. À 1024 px
 *    PILE (iPad 9,7"/mini en paysage, largeur de fenêtre courante sur ordinateur),
 *    le JS rend collant le header DESKTOP — qui est masqué — pendant que le header
 *    mobile, seul visible, reste dans le flux : plus rien ne colle.
 * 2. Au franchissement du point de bascule (zoom navigateur, redimensionnement de
 *    fenêtre, devtools ancrés, rotation de tablette), Kadence recalcule son seuil
 *    de collage avec `getOffset()` = `rect.top + scrollY`, sur un élément qui peut
 *    DÉJÀ être en `position: fixed` → il lit `top + scrollY` au lieu de la position
 *    réelle. Le seuil devient la position de scroll du moment : le header ne
 *    recolle plus qu'en dessous, et remonter le laisse défiler avec la page
 *    jusqu'au rechargement. Rien ne se voit au moment du redimensionnement, la
 *    casse n'apparaît qu'en remontant plus tard — d'où l'impression d'aléatoire.
 *
 * Les deux disparaissent en posant un vrai `position: sticky` sur #masthead
 * (`style.css`) : un seul élément collant, qui CONTIENT les deux headers, donc
 * plus aucun point de bascule dans la logique de collage. C'est bien #masthead
 * qu'il faut viser et pas `.site-header-inner-wrap` : le parent de celle-ci
 * (#main-header) est aussi haut qu'elle, un sticky y serait libéré aussitôt.
 *
 * Vérifié avant migration : chaîne d'ancêtres saine (#masthead → #wrapper → body,
 * #wrapper en `overflow: clip`, qui ne casse PAS sticky contrairement à `hidden`),
 * et #masthead était DÉJÀ `position: relative` → aucun changement de bloc conteneur
 * pour les descendants absolus (mini-panier, sous-menus), et aucun descendant en
 * `position: fixed` (les panneaux de la recherche globale sont déplacés dans
 * <body> par leur JS).
 *
 * Ici on ne fait que débrancher le mécanisme JS. Les deux options sont forcées par
 * filtre plutôt que réglées dans le customizer : versionné avec le thème, donc
 * identique en local et en production, sans manipulation à la mise en ligne.
 * ⚠ Conséquence assumée : les réglages « En-tête collant » du customizer n'ont
 * plus d'effet — le comportement est désormais celui de `style.css`.
 *
 * Effets de bord vérifiés :
 * - la classe `kadence-sticky-header` n'est plus émise → tout le bloc CSS que
 *   Kadence génère pour `.item-is-fixed` disparaît (styles/component.php, gardé
 *   par ces deux mêmes options). Le fond de #masthead lui-même est posé plus haut
 *   dans le même fichier, HORS de ce bloc : il reste en place ;
 * - `navigation.min.js` reste chargé (la version « lite » n'est substituée que si
 *   `enable_scroll_to_id` est faux, or il vaut `true` par défaut) → tiroir mobile,
 *   sous-menus et scroll d'ancre intacts. `initStickyHeader` ne trouve plus
 *   d'élément et n'enregistre aucun écouteur ;
 * - le scroll d'ancre de Kadence (`getTopOffset`) retomberait à 0, mais les deux
 *   seuls jeux d'ancres du site (fiche livre, fiche événement) court-circuitent
 *   déjà ce mécanisme via `body.no-anchor-scroll` (inc/section-nav.php), et aucune
 *   entrée de menu n'est une ancre (vérifié en base) → sans effet.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Force les deux options « header collant » de Kadence à « non ».
 *
 * `theme_mod_{$name}` est le filtre du cœur WordPress appliqué par `get_theme_mod()`,
 * que `kadence()->option()` utilise pour ce thème (stockage `theme_mod`). Il
 * s'applique aussi bien quand la valeur existe en base que quand elle est absente.
 *
 * @return string
 */
function pf_header_sticky_disable_kadence() {
	return 'no';
}
add_filter( 'theme_mod_header_sticky', 'pf_header_sticky_disable_kadence' );
add_filter( 'theme_mod_mobile_header_sticky', 'pf_header_sticky_disable_kadence' );
