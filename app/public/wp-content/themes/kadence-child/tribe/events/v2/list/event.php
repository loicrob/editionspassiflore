<?php
/**
 * View: List Event (override)
 *
 * Surcharge de /wp-content/plugins/the-events-calendar/src/views/v2/list/event.php
 * Seule différence avec le cœur : la classe `pf-card` ajoutée au wrapper, qui devient
 * ainsi une carte cliquable (cf. assets/css/events.css — fond, ombre, lien étiré sur
 * toute la carte, image à droite sur desktop/tablette et en haut sur mobile).
 *
 * @link http://evnt.is/1aiy
 *
 * @version 5.0.0
 *
 * @var WP_Post $event The event post object with properties added by the `tribe_get_event` function.
 *
 * @see tribe_get_event() For the format of the event object.
 */

$container_classes = [ 'tribe-common-g-row', 'tribe-events-calendar-list__event-row' ];
$container_classes['tribe-events-calendar-list__event-row--featured'] = $event->featured;

$event_classes = tribe_get_post_class( [ 'tribe-events-calendar-list__event', 'tribe-common-g-row', 'tribe-common-g-row--gutters' ], $event->ID );
?>
<li <?php tec_classes( $container_classes ); ?>>

	<?php $this->template( 'list/event/date-tag', [ 'event' => $event ] ); ?>

	<div class="tribe-events-calendar-list__event-wrapper tribe-common-g-col pf-card">
		<?php // Lien étiré : rend toute la carte cliquable. Le titre n'est plus un lien. ?>
		<a class="pf-card-link" href="<?php echo esc_url( $event->permalink ); ?>" aria-label="<?php echo esc_attr( wp_strip_all_tags( $event->title ) ); ?>"></a>
		<article <?php tec_classes( $event_classes ); ?>>
			<?php $this->template( 'list/event/featured-image', [ 'event' => $event ] ); ?>

			<div class="tribe-events-calendar-list__event-details tribe-common-g-col">

				<header class="tribe-events-calendar-list__event-header">
					<?php $this->template( 'list/event/title', [ 'event' => $event ] ); ?>
					<?php $this->template( 'list/event/date', [ 'event' => $event ] ); ?>
				</header>

				<?php // Lieu (nom+ville)/organisateur/participants : rendus par description.php (pf-event-card-meta),
				// pas par TEC — adresse complète, catégories et coût natifs volontairement supprimés. ?>
				<?php $this->template( 'list/event/description', [ 'event' => $event ] ); ?>

			</div>
		</article>
	</div>

</li>
