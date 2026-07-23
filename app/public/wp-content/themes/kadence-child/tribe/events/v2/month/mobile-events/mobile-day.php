<?php
/**
 * View: Month View Mobile Day (override)
 *
 * Surcharge de
 * the-events-calendar/src/views/v2/month/mobile-events/mobile-day.php
 *
 * Sur mobile, TEC affiche les événements du jour tapé dans ce panneau, EN BAS de la
 * grille (bascule `--show` par month-mobile-events.js). Ce thème remplace ce
 * comportement : le panneau devient une SOURCE DE DONNÉES cachée (cf. events.css,
 * règle `.pf-month-pop-active …--show { display:none }`) que `events-month-mobile-pop.js`
 * clone dans un popup flottant AU-DESSUS du jour tapé (même composant `.pf-map-pop` que
 * l'infobulle de la vue carte). Différences avec la carte : en-tête = le jour, et la
 * ligne « Lieu » est conservée en bas de chaque tuile (render_tile par défaut).
 *
 * Le contenu n'est rendu QUE pour un jour avec événements : un jour vide produit un
 * panneau vide → aucun popup ne s'ouvre (le JS ne trouve pas de `.pf-map-pop`).
 *
 * Sans JS (repli) : le panneau reste piloté nativement (bascule `--show` au tap) et
 * affiche ce même contenu en bas de la grille — dégradation acceptable.
 *
 * ⚠️ L'`id` du conteneur DOIT rester identique au natif : c'est la cible de
 * `aria-controls` porté par le bouton-jour (date.php).
 *
 * @link http://evnt.is/1aiy
 *
 * @version 4.9.10
 *
 * @var string $today_date Today's date in the `Y-m-d` format.
 * @var string $day_date The current day date, in the `Y-m-d` format.
 * @var array  $day The current day data.
 * @var array  $mobile_messages A set of mobile messages that will be used to handle the user interaction in mobile.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Événements du jour : multi-jours (empilés, avec spacers falsy à filtrer) + mono-jour,
// même fusion que le natif — l'ordre reflète ce que montrait le panneau mobile.
$events = ! empty( $day['events'] ) ? $day['events'] : [];
if ( ! empty( $day['multiday_events'] ) ) {
	$events = array_filter( array_merge( $day['multiday_events'], $events ) );
}

$mobile_day_id = 'tribe-events-calendar-mobile-day-' . $day['year_number'] . '-' . $day['month_number'] . '-' . $day['day_number'];

$classes = [ 'tribe-events-calendar-month-mobile-events__mobile-day' ];
if ( $today_date === $day_date ) {
	$classes[] = 'tribe-events-calendar-month-mobile-events__mobile-day--show';
}

$has_events = ! empty( $events ) && class_exists( 'Passiflore_Event_Tiles' );

// En-tête = le jour tapé. Jour de semaine capitalisé (initiale ASCII en français) ;
// année ajoutée seulement si différente de l'année en cours (comme les dates de tuile).
$pf_day_ts    = strtotime( $day['date'] );
$pf_day_label = ucfirst( pf_date_i18n_ordinal( 'l j F', $pf_day_ts ) ); // déjà échappé (avec <sup> brut pour "1er")
if ( (int) date_i18n( 'Y', $pf_day_ts ) !== (int) date_i18n( 'Y' ) ) {
	$pf_day_label .= ' ' . date_i18n( 'Y', $pf_day_ts );
}
$pf_count = count( $events );
?>

<div <?php tec_classes( $classes ); ?> id="<?php echo sanitize_html_class( $mobile_day_id ); ?>">

	<?php if ( $has_events ) : ?>
		<div class="pf-map-pop pf-month-pop">
			<div class="pf-map-pop__header">
				<span class="pf-map-pop__area-label"><?php echo $pf_day_label; // phpcs:ignore WordPress.Security.EscapeOutput — pré-échappé (pf_date_i18n_ordinal) ?></span>
				<span class="pf-map-pop__area-count">
					<?php echo esc_html( $pf_count . ' ' . ( $pf_count > 1 ? 'événements' : 'événement' ) ); ?>
				</span>
			</div>
			<div class="pf-map-pop__scroll">
				<div class="pf-scroll-fade pf-map-pop__fade">
					<div class="pf-map-pop__events pf-hscroll">
						<?php
						foreach ( $events as $event ) {
							// HTML déjà échappé par render_tile() ; show_lieu par défaut (true).
							echo Passiflore_Event_Tiles::render_tile( // phpcs:ignore WordPress.Security.EscapeOutput
								Passiflore_Event_Tiles::normalize_event( (int) $event->ID )
							);
						}
						?>
					</div>
				</div>
			</div>
		</div>
	<?php endif; ?>

</div>
