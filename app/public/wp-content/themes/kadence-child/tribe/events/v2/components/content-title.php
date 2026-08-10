<?php
/**
 * View Component: Title (override Passiflore).
 *
 * TEC masque ce titre par défaut (screen-reader-text) dès que $show_content_title
 * est vide, en misant sur le thème pour afficher un vrai titre de page — mais la
 * barre de titre Kadence est désactivée ici, comme sur Catalogue/Auteurs.
 *
 * - Liste/Mois/Carte : gardé invisible à dessein (pas de place prise dans un
 *   header déjà chargé) — coût nul, garde un vrai <h1> pour le SEO/lecteur
 *   d'écran plutôt que rien du tout. TOUJOURS invisible, y compris quand TEC
 *   bascule la vue en AJAX (sélecteur liste/mois/carte, sans rechargement de
 *   page) : dans ce contexte, TEC calcule parfois $show_content_title=true
 *   côté serveur pour compenser l'absence de rechargement — Day_View s'en
 *   protège déjà en le forçant à false ; on fait pareil ici plutôt que de
 *   dépendre de cette variable.
 * - Vue jour : inchangée (comportement TEC d'origine).
 *
 * Override of /wp-content/plugins/the-events-calendar/src/views/v2/components/content-title.php
 *
 * @var \Tribe\Events\Views\V2\Template $this Template Engine instance rendering.
 * @var string                          $content_title The title to display.
 */

$pf_slug    = $this->get_view_slug();
$pf_archive = in_array( $pf_slug, [ 'list', 'month', 'carte' ], true );

// Get heading tag from View helper method.
if ( $this->get( 'view' ) instanceof Tribe\Events\Views\V2\View ) {
	$heading_tag = $this->get( 'view' )->get_content_title_heading_tag( 'h1' );
} else {
	$heading_tag = 'h1';
}

// If header_title exists, this title should be h2 (header-title is the primary h1).
if ( ! empty( $header_title ) && 'h1' === $heading_tag ) {
	$heading_tag = 'h2';
}

$pf_archive_labels = [
	'list'  => 'Événements',
	'month' => 'Événements sur un calendrier',
	'carte' => 'Événements sur une carte',
];
$heading_text = $pf_archive ? $pf_archive_labels[ $pf_slug ] : ( $content_title ?: tribe_get_event_label_plural() );

// Liste/Mois/Carte restent volontairement invisibles, sans exception (cf.
// note en tête de fichier) ; seul le TEC natif ($show_content_title) peut
// encore rendre ceci visible ailleurs (vue jour, etc.).
$visual_class = ( ! $pf_archive && ! empty( $show_content_title ) )
	? 'tribe-events-header__content-title-text tribe-common-h7 tribe-common-h3--min-medium tribe-common-h--alt'
	: 'screen-reader-text tec-a11y-title-hidden';
?>
<div class="tribe-events-header__content-title">
	<?php

	printf(
		'<%1$s class="%2$s">%3$s</%1$s>',
		$heading_tag, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped,StellarWP.XSS.EscapeOutput.OutputNotEscaped
		esc_attr( $visual_class ),
		esc_html( $heading_text )
	);
	?>
</div>
