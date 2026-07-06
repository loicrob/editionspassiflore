<?php
/**
 * View: List Single Event Description (override)
 *
 * Override of /wp-content/plugins/the-events-calendar/src/views/v2/list/event/description.php
 *
 * @var WP_Post $event The event post object with properties added by the `tribe_get_event` function.
 */

$excerpt = (string) $event->excerpt;
?>
<?php if ( ! empty( $excerpt ) ) : ?>
	<div class="tribe-events-calendar-list__event-description tribe-common-b2 tribe-common-a11y-hidden">
		<?php echo $excerpt; ?>
	</div>
<?php endif; ?>

<?php
if ( function_exists( 'passiflore_render_event_participants' ) ) {
	// 'text' : participants en texte simple (non cliquables) — la carte entière est le lien.
	echo passiflore_render_event_participants( $event->ID, 'text' );
}
