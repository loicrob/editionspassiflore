<?php
/**
 * View: Month Calendar Event (override)
 *
 * Surcharge de
 * the-events-calendar/src/views/v2/month/calendar-body/day/calendar-events/calendar-event.php
 *
 * La pastille mono-jour ENTIÈRE est un lien vers l'événement : le bloc `-details`
 * (date + titre) devient une ancre <a> — au lieu d'un lien étiré (::after) sur le seul
 * titre. Cette ancre porte aussi le déclencheur d'infobulle (data-js /
 * data-tooltip-content), repris du titre ; le titre (override title.php) n'est donc
 * plus lui-même un lien (pas de <a> imbriqué).
 *
 * ⚠️ Le contenu de l'infobulle (notre card, override tooltip.php, qui contient
 * elle-même un <a>) est rendu EN DEHORS de l'ancre `-details` (frère dans l'<article>)
 * pour éviter un <a> imbriqué (HTML invalide → le navigateur fermerait l'ancre
 * prématurément et casserait le lien de la pastille). tooltipster retrouve ce contenu
 * par son id (`data-tooltip-content`), quelle que soit sa position dans le document.
 *
 * @link http://evnt.is/1aiy
 *
 * @version 5.0.0
 *
 * @var WP_Post $event The event post object with properties added by the `tribe_get_event` function.
 *
 * @see tribe_get_event() For the format of the event object.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$classes = tribe_get_post_class( [ 'tribe-events-calendar-month__calendar-event' ], $event->ID );

$classes['tribe-events-calendar-month__calendar-event--featured'] = ! empty( $event->featured );
$classes['tribe-events-calendar-month__calendar-event--sticky']   = ( -1 === $event->menu_order );

$pf_tooltip_id = 'tribe-events-tooltip-content-' . $event->ID;
?>

<article <?php tec_classes( $classes ); ?>>

	<?php $this->template( 'month/calendar-body/day/calendar-events/calendar-event/featured-image', [ 'event' => $event ] ); ?>

	<a
		href="<?php echo esc_url( $event->permalink ); ?>"
		class="tribe-events-calendar-month__calendar-event-details"
		aria-label="<?php echo esc_attr( $event->title ); ?>"
		rel="bookmark"
		data-js="tribe-events-tooltip"
		data-tooltip-content="#<?php echo esc_attr( $pf_tooltip_id ); ?>"
		aria-describedby="<?php echo esc_attr( $pf_tooltip_id ); ?>"
	>
		<?php $this->template( 'month/calendar-body/day/calendar-events/calendar-event/date', [ 'event' => $event ] ); ?>
		<?php $this->template( 'month/calendar-body/day/calendar-events/calendar-event/title', [ 'event' => $event ] ); ?>
	</a>

	<?php $this->template( 'month/calendar-body/day/calendar-events/calendar-event/tooltip', [ 'event' => $event ] ); ?>

</article>
