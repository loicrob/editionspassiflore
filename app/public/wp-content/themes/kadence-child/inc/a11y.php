<?php
/**
 * Mentions d'accessibilité partagées.
 *
 * « Ouvre un nouvel onglet » : WCAG 3.2.5 (Change on Request) demande de prévenir
 * l'utilisateur avant un changement de contexte. La flèche ➜ posée en CSS sur
 * `[target="_blank"]` (style.css) joue ce rôle pour les voyants, mais du contenu
 * généré (::after) n'est pas restitué de façon fiable par les lecteurs d'écran →
 * il faut une mention dans le DOM, visuellement masquée.
 *
 * @package kadence-child
 */

if ( ! defined( 'ABSPATH' ) ) exit;

/** Formulation unique de la mention (un seul endroit à changer). */
const PF_NEW_WINDOW_NOTE = 'nouvelle fenêtre';

/**
 * Mention à placer DANS un lien `target="_blank"` dont le nom accessible vient de
 * son texte visible. `.screen-reader-text` est fourni par Kadence (global.min.css,
 * chargé site-wide) — pas de CSS à ajouter.
 *
 * ⚠️ L'espace séparateur vit À L'INTÉRIEUR du span, avant la parenthèse — jamais
 * avant lui. Placé dehors, il appartient au flux visible du lien : il rallonge le
 * soulignement et décale la flèche ➜ posée en `::after`. Dedans, il est emporté
 * par le clip de `.screen-reader-text` (aucun rendu visuel) tout en restant dans
 * le texte lu, ce qui évite le « confidentialiténouvelle fenêtre » à la
 * vocalisation. Même technique que la mention « opens in a new tab » du cœur de
 * WordPress.
 */
function pf_new_window_note() {
	return '<span class="screen-reader-text"> (' . PF_NEW_WINDOW_NOTE . ')</span>';
}

/**
 * Même mention pour un lien dont le nom accessible vient d'un `aria-label` et non
 * d'un texte visible (lien étiré `.pf-card-link`, vide) : retourne le libellé suffixé.
 */
function pf_new_window_label( $label ) {
	return trim( (string) $label ) . ' (' . PF_NEW_WINDOW_NOTE . ')';
}

/**
 * Icônes de réseaux sociaux du pied de page (Kadence) : ajoute la mention à leur
 * `aria-label`, seul porteur de leur nom accessible (ce sont des liens-icônes, sans
 * texte visible — ils sont d'ailleurs exclus de la flèche ➜ dans style.css, n'ayant
 * pas de libellé auquel l'accoler).
 *
 * Kadence construit ce `aria-label` en dur dans `Kadence\footer_social()` et n'expose
 * aucun filtre dessus (`kadence_social_link_target` ne pilote que la cible) → on
 * enveloppe le rendu de l'action `kadence_footer_social` (le callback natif y est
 * accroché en priorité 10) dans un tampon de sortie et on ne réécrit que les
 * `aria-label` des ancres réellement en `target="_blank"` (l'ouverture en nouvel
 * onglet est une option du customizer, elle peut être désactivée).
 *
 * Périmètre volontairement limité au pied de page : les réseaux sociaux d'en-tête
 * ne sont pas activés sur ce site (mêmes fonctions côté Kadence, action
 * `kadence_header_social` — à brancher ici de la même façon le jour où ils le seraient).
 */
add_action( 'kadence_footer_social', function () { ob_start(); }, 1 );
add_action( 'kadence_footer_social', function () {
	$html = ob_get_clean();
	echo preg_replace_callback(
		'#<a\b[^>]*>#i',
		static function ( $m ) {
			if ( false === stripos( $m[0], 'target="_blank"' ) ) return $m[0];
			// La valeur capturée sort déjà d'un esc_attr() côté Kadence : on lui accole la
			// mention telle quelle (pas de ré-échappement, qui doublerait les entités).
			return preg_replace_callback(
				'#aria-label="([^"]*)"#i',
				static function ( $a ) { return 'aria-label="' . pf_new_window_label( $a[1] ) . '"'; },
				$m[0],
				1
			);
		},
		$html
	);
}, 99 );
