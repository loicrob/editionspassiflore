<?php
/**
 * View: List View - Single Event Title (override)
 *
 * Surcharge de /wp-content/plugins/the-events-calendar/src/views/v2/list/event/title.php
 *
 * Le titre n'est plus un lien : toute la carte est cliquable via `.pf-card-link`
 * (cf. override list/event.php + assets/css/events.css). Le titre reçoit la classe
 * `pf-card-title` (style de titre de carte du site : rougit au survol de la carte
 * via la règle globale `.pf-card:hover .pf-card-title` de style.css).
 *
 * @link http://evnt.is/1aiy
 *
 * @version 6.15.12
 *
 * @var WP_Post $event The event post object with properties added by the `tribe_get_event` function.
 *
 * @see tribe_get_event() For the format of the event object.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<h4 class="tribe-events-calendar-list__event-title pf-card-title">
	<?php
	// phpcs:ignore
	echo $event->title;
	?>
</h4>
