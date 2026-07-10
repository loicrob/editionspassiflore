<?php
/**
 * View: List Single Event Description (override)
 *
 * Override of /wp-content/plugins/the-events-calendar/src/views/v2/list/event/description.php
 *
 * @var WP_Post $event The event post object with properties added by the `tribe_get_event` function.
 */

$description = function_exists( 'passiflore_render_event_description_text' )
	? passiflore_render_event_description_text( $event )
	: '';
?>
<?php if ( '' !== $description ) : ?>
	<div class="tribe-events-calendar-list__event-description pf-card-text pf-card-text--clamp-2">
		<?php echo $description; ?>
	</div>
<?php endif; ?>

<?php
if ( function_exists( 'passiflore_render_event_card_meta' ) ) {
	echo passiflore_render_event_card_meta( $event );
}
