<?php
/**
 * View Component: Header (override Passiflore).
 *
 * Vues d'archive (liste + mois) : top-bar à gauche, « S'abonner » + events-bar
 * (sélecteur de vue) à droite, dans une barre sticky « smart-hide » full-bleed
 * (`.pf-sticky-bar`, mécanisme commun avec /auteurs et /catalogue). Le sticky est
 * posé sur le <header> lui-même (et non sur un enfant) car son parent
 * (`.tribe-events-l-container`) est haut → il colle sur toute la hauteur du contenu.
 *  - En vue LISTE, le top-bar est masqué (events-infinite.css) → seuls « S'abonner »
 *    + le switch s'affichent à droite ; les séparateurs de mois du scroll infini
 *    décalent leur `top` selon que le header est apparent ou masqué (cf. events-month.js
 *    / --pf-ev-header-offset).
 *  - Le bloc « S'abonner » est rendu ici, une seule fois ; l'override de
 *    components/ical-link.php supprime le rendu par défaut en bas de page.
 *
 * Autres vues (jour…) : markup d'origine inchangé.
 *
 * Override of /wp-content/plugins/the-events-calendar/src/views/v2/components/header.php
 *
 * @var \Tribe\Events\Views\V2\Template $this                 Template Engine instance rendering.
 * @var bool                            $disable_event_search Boolean on whether to disable the event search.
 */

$pf_slug    = $this->get_view_slug();
$pf_archive = in_array( $pf_slug, [ 'list', 'month', 'carte' ], true );

$header_classes = [ 'tribe-events-header' ];
if ( empty( $disable_event_search ) ) {
	$header_classes[] = 'tribe-events-header--has-event-search';
}
if ( $pf_archive ) {
	$header_classes[] = 'pf-events-header-bar';
	$header_classes[] = 'pf-sticky-bar'; // liste + mois : header sticky « smart-hide ».
}

$has_header_title = ! empty( $header_title );
$has_backlink     = ! empty( $backlink );
$has_breadcrumbs  = ! empty( $breadcrumbs );
?>

<header <?php tec_classes( $header_classes ); ?>>
	<?php
	// Primary title should always render first (H1).
	if ( $has_header_title ) {
		$this->template( 'components/header-title' );
	} else {
		$this->template( 'components/content-title' );
	}

	// Navigation should render directly after the primary title.
	if ( $has_backlink ) {
		$this->template( 'components/backlink' );
	} elseif ( $has_breadcrumbs ) {
		$this->template( 'components/breadcrumbs' );
	}

	// If header-title was the primary title, render content-title as a secondary title.
	if ( $has_header_title ) {
		$this->template( 'components/content-title' );
	}
	?>

	<?php $this->template( 'components/messages' ); ?>

	<?php $this->template( 'components/messages', [ 'classes' => [ 'tribe-events-header__messages--mobile' ] ] ); ?>

	<?php if ( $pf_archive ) : ?>

		<?php $GLOBALS['pf_events_ical_rendered'] = false; // « S'abonner » rendu ici ; le bas sera supprimé. ?>
		<div class="pf-sub-header pf-events-header-bar__inner">
			<div class="pf-events-header-bar__left">
				<?php if ( in_array( $pf_slug, [ 'list', 'month' ], true ) ) : // carte n'a pas de top-bar (préc./suiv./datepicker). ?>
					<?php $this->template( [ $pf_slug, 'top-bar' ] ); ?>
				<?php endif; ?>
				<?php if ( 'list' === $pf_slug && class_exists( 'Passiflore_Events_Search' ) ) : ?>
					<?php echo Passiflore_Events_Search::render_bar(); ?>
				<?php elseif ( 'carte' === $pf_slug && class_exists( 'Passiflore_Events_Map' ) ) : ?>
					<?php echo Passiflore_Events_Map::render_search_bar(); ?>
				<?php endif; ?>
			</div>
			<div class="pf-events-header-bar__right">
				<?php $this->template( 'components/ical-link' ); ?>
				<?php $this->template( 'components/events-bar' ); ?>
			</div>
		</div>

	<?php else : ?>

		<?php $this->template( 'components/events-bar' ); ?>

		<?php $this->template( [ $pf_slug, 'top-bar' ] ); ?>

	<?php endif; ?>
</header>
