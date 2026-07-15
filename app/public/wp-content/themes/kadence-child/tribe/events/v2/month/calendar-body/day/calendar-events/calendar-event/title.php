<?php
/**
 * View: Month View - Calendar Event Title (override)
 *
 * Surcharge de
 * the-events-calendar/src/views/v2/month/calendar-body/day/calendar-events/calendar-event/title.php
 *
 * Le titre n'est plus un lien : toute la pastille `-details` est désormais l'ancre vers
 * l'événement (cf. override calendar-event.php). On garde le conteneur titré (classe
 * `tribe-common-h8` pour le style) mais sans <a> imbriqué. Couleur --pf-surface et
 * absence de soulignement gérées dans events.css.
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
?>
<div class="tribe-events-calendar-month__calendar-event-title tribe-common-h8 tribe-common-h--alt">
	<?php
	// phpcs:ignore
	echo $event->title;
	?>
</div>
