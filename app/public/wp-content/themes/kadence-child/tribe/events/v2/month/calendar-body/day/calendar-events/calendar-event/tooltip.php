<?php
/**
 * View: Month View - Calendar Event Tooltip (override)
 *
 * Surcharge de
 * the-events-calendar/src/views/v2/month/calendar-body/day/calendar-events/calendar-event/tooltip.php
 *
 * Remplace le contenu du popup par défaut de TEC (image / date / titre / description /
 * prix) par notre composant générique de card d'événement (Passiflore_Event_Tiles::
 * render_tile, avec la ligne « Lieu »), identique aux tuiles de l'accueil, de la fiche
 * auteur et de l'infobulle de la vue carte.
 *
 * Le conteneur #tribe-events-tooltip-content-{ID} est conservé tel quel : c'est la cible
 * du sélecteur `data-tooltip-content` porté par le déclencheur (title.php pour les
 * événements mono-jour, l'article multi-jours pour les autres), que tooltipster déplace
 * dans la bulle à l'ouverture. Seul son contenu change. Ce template est appelé pour les
 * deux types d'événements (mono-jour ET multi-jours).
 *
 * Le contenu est déplacé hors du conteneur `.tribe-common` par tooltipster (bulle
 * ajoutée au <body>) : la card se rend donc comme sur l'accueil, sans subir le reset
 * `.tribe-common *`. Le cadre par défaut de tooltipster est neutralisé côté CSS
 * (events.css, sélecteur `.tooltipster-base…:has(.pf-month-tooltip)`) pour laisser la
 * card fournir seule son fond / rayon / ombre.
 *
 * @link http://evnt.is/1aiy
 *
 * @version 4.9.13
 *
 * @var WP_Post $event The event post object with properties added by the `tribe_get_event` function.
 *
 * @see tribe_get_event() For the format of the event object.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<div class="tribe-events-calendar-month__calendar-event-tooltip-template tribe-common-a11y-hidden">
	<div
		class="tribe-events-calendar-month__calendar-event-tooltip pf-month-tooltip"
		id="tribe-events-tooltip-content-<?php echo esc_attr( $event->ID ); ?>"
		role="tooltip"
	>
		<?php
		if ( class_exists( 'Passiflore_Event_Tiles' ) ) {
			// HTML déjà échappé par render_tile().
			echo Passiflore_Event_Tiles::render_tile( // phpcs:ignore WordPress.Security.EscapeOutput
				Passiflore_Event_Tiles::normalize_event( (int) $event->ID )
			);
		}
		?>
	</div>
</div>
