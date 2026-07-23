<?php
/**
 * View: List View - Single Event Featured Image  (override Passiflore)
 *
 * Copie fidèle du core TEC (@version 6.14.2) — SEUL AJOUT : l'attribut `sizes`.
 * Sans lui, le défaut navigateur (100vw) fait choisir dans le srcset la plus
 * grande variante (jusqu'au plein format ~2560px) pour une image affichée
 * à ≤360px (desktop) / pleine largeur (mobile). Le `sizes` décrit la largeur
 * réelle → le srcset (déjà émis par TEC) sert `medium_large`/`large`.
 * Layout : cf. events.css (.pf-card .…__event-featured-image-wrapper :
 * flex 0 0 34% / max-width 360px en ≥ medium, pleine largeur 16/9 en mobile).
 *
 * ⚠️ À la MAJ de TEC, comparer le @version du core et reporter tout changement.
 *
 * @version 6.14.2
 *
 * @var WP_Post $event The event post object with properties added by the `tribe_get_event` function.
 */

if ( ! $event->thumbnail->exists ) {
	return;
}

?>
<div class="tribe-events-calendar-list__event-featured-image-wrapper tribe-common-g-col">
	<img
		class="tribe-events-calendar-list__event-featured-image"
		src="<?php echo esc_url( $event->thumbnail->full->url ); ?>"
		<?php if ( ! empty( $event->thumbnail->srcset ) ) : ?>
			srcset="<?php echo esc_attr( $event->thumbnail->srcset ); ?>"
			sizes="(max-width: 781px) 93vw, 360px"
		<?php endif; ?>
		<?php if ( ! empty( $event->thumbnail->alt ) ) : ?>
			alt="<?php echo esc_attr( $event->thumbnail->alt ); ?>"
		<?php else : ?>
			alt=""
		<?php endif; ?>
		<?php if ( ! empty( $event->thumbnail->title ) ) : ?>
			title="<?php echo esc_attr( $event->thumbnail->title ); ?>"
		<?php endif; ?>
		<?php if ( ! empty( $event->thumbnail->full->width ) && ! empty( $event->thumbnail->full->height ) ) : ?>
			width="<?php echo esc_attr( $event->thumbnail->full->width ); ?>"
			height="<?php echo esc_attr( $event->thumbnail->full->height ); ?>"
		<?php endif; ?>
	/>
</div>
