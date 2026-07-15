<?php
/**
 * View Component: Filter Bar (override Passiflore).
 *
 * Override of /wp-content/plugins/the-events-calendar/src/views/v2/components/filter-bar.php
 *
 * Le template natif de ce composant est VIDE pour toutes les vues (pas de filtres/
 * catégories sur ce site) — détourné ici comme point d'ancrage pour une barre de nav
 * mobile propre à la vue mois, entre le header et la grille
 * (`<table class="tribe-events-calendar-month">`) : TEC n'expose aucun hook à cet
 * endroit précis, et ce composant est le seul rendu exactement entre les deux dans
 * `month.php`. Vue mois uniquement ($this->get_view_slug()) — ce composant est aussi
 * rendu par les vues liste/jour, qui doivent rester intactes.
 *
 * Réutilise le MÊME bouton flèche que le top-bar desktop/tablette (partiels natifs
 * `month/top-bar/nav/prev(-disabled)`/`next(-disabled)`, non surchargés — mêmes
 * classes/SVG/attributs ARIA/`data-js` de navigation AJAX que le header) plutôt que
 * `month/top-bar/nav.php` : ce dernier enveloppe les deux flèches dans un même
 * `<nav class="… tribe-common-a11y-hidden">` (masqué tant que le conteneur n'atteint
 * pas `--breakpoint-medium`, 768px — logique inverse de ce qu'on veut ici) et ne laisse
 * aucun point d'insertion pour le libellé entre les deux flèches. `$prev_url`/
 * `$next_url`/`$prev_rel`/`$next_rel`/`$the_date` sont déjà extraits par la vue
 * (View::setup_template_vars(), partagés par tous les templates de cette vue) — mêmes
 * variables que celles consommées nativement par ces partiels.
 *
 * Le libellé mois+année reprend le même calcul que la version desktop du datepicker
 * (month/top-bar/datepicker.php : `$pf_month_desktop`) pour un texte identique.
 *
 * Masquée à partir de 768px (events.css) : le nav natif du header prend alors le
 * relais (`.tribe-common--breakpoint-medium … .tribe-events-c-top-bar__nav`, même
 * seuil TEC) — les deux ne sont jamais visibles en même temps.
 *
 * @var \Tribe\Events\Views\V2\Template $this
 * @var \DateTime                       $the_date
 * @var string                          $prev_url
 * @var string                          $next_url
 */

if ( 'month' !== $this->get_view_slug() ) {
	return;
}

$pf_mobile_month_label = ucfirst( wp_date( 'F Y', $the_date->getTimestamp(), $the_date->getTimezone() ) );
$pf_nav_aria_label      = sprintf(
	// Translators: %s: Events (plural).
	__( '%s Pagination', 'the-events-calendar' ),
	tribe_get_event_label_plural()
);
?>
<nav class="pf-month-mobile-nav" aria-label="<?php echo esc_attr( $pf_nav_aria_label ); ?>">
	<?php
	if ( ! empty( $prev_url ) ) {
		$this->template( 'month/top-bar/nav/prev' );
	} else {
		$this->template( 'month/top-bar/nav/prev-disabled' );
	}
	?>
	<span class="pf-month-mobile-nav__label"><?php echo esc_html( $pf_mobile_month_label ); ?></span>
	<?php
	if ( ! empty( $next_url ) ) {
		$this->template( 'month/top-bar/nav/next' );
	} else {
		$this->template( 'month/top-bar/nav/next-disabled' );
	}
	?>
</nav>
