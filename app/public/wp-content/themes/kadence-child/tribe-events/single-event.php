<?php
/**
 * Override du template single-event de The Events Calendar (fiche événement).
 *
 * Hero (image + titre + dates + description + site + agenda) puis sections via
 * le composant partagé « section-nav » (inc/section-nav.php + assets/js/section-nav.js) :
 * Horaires, Lieu (+ carte OSM), Présence, Livres associés, Organisateur.
 *
 * Écarts avec l'original du plugin :
 *  - navigation « événement précédent / suivant » (header + footer) retirée ;
 *  - bloc meta natif (modules/meta : Détails / Lieu / Organisateur / carte Google)
 *    remplacé par nos propres sections (pas de catégorie / mots-clés / prix sur ce site) ;
 *  - hooks tribe_events_single_event_before/after_the_content NON déclenchés
 *    (le layout à sections remplace ce modèle d'extension).
 *
 * @see the-events-calendar/src/views/single-event.php (original, @version 4.6.19)
 * @package kadence-child
 */

if ( ! defined( 'ABSPATH' ) ) {
	die( '-1' );
}

$event_id = Tribe__Events__Main::postIdHelper( get_the_ID() );
?>

<div id="tribe-events-content" class="tribe-events-single">

	<?php while ( have_posts() ) : the_post(); ?>
		<div id="post-<?php the_ID(); ?>" <?php post_class( 'pf-event-single' ); ?>>

			<?php $pf_sections = passiflore_get_event_sections_parts( $event_id ); ?>

			<!-- Layout événement, grille `.pf-event-hero` [nav | contenu | image]. La NAV (points)
			     est une colonne de gauche qui longe TOUTE la page (topsec + botsec) → reste sticky
			     partout. L'IMAGE (colonne droite, collée pleine hauteur) ne couvre que la ZONE HAUTE
			     (head + body + topsec = Horaires/Lieu/Organisateur/Participants) → elle reprend son
			     scroll dès la ZONE BASSE (botsec = Livres associés), qui s'étend alors sur contenu+image
			     (pleine largeur à droite de la nav). Mobile+tablette : tout empilé. Placement via
			     grid-template-areas (event-single.css). -->
			<div class="pf-event-hero">
				<?php echo $pf_sections['nav']; // phpcs:ignore WordPress.Security.EscapeOutput — <nav class="pf-sectionnav"> (grid-area nav) + primer ?>

				<div class="pf-event-hero__head">
					<h1 class="pf-event-hero__title"><?php the_title(); ?></h1>

					<div class="pf-event-hero__schedule">
						<?php echo tribe_events_event_schedule_details( $event_id ); // phpcs:ignore WordPress.Security.EscapeOutput — filtré (sentinelle / planning par jour) ?>
					</div>
				</div>

				<?php $thumb = get_the_post_thumbnail( $event_id, 'large', [ 'class' => 'pf-event-hero__img', 'alt' => '', 'sizes' => '(max-width: 1023px) 100vw, 40vw' ] ); ?>
				<?php if ( $thumb ) : ?>
					<div class="pf-event-hero__media"><div class="pf-event-hero__media-inner"><?php echo $thumb; // phpcs:ignore WordPress.Security.EscapeOutput ?></div></div>
				<?php endif; ?>

				<div class="pf-event-hero__body">
					<?php if ( '' !== trim( (string) get_the_content() ) ) : ?>
						<div class="pf-event-hero__desc tribe-events-content"><?php the_content(); ?></div>
					<?php endif; ?>

					<?php echo passiflore_render_event_hero_meta( $event_id ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
				</div>

				<?php if ( '' !== $pf_sections['top'] ) : ?>
				<div class="pf-event-hero__topsec"><?php echo $pf_sections['top']; // phpcs:ignore WordPress.Security.EscapeOutput ?></div>
				<?php endif; ?>

				<?php if ( '' !== $pf_sections['bot'] ) : ?>
				<div class="pf-event-hero__botsec"><?php echo $pf_sections['bot']; // phpcs:ignore WordPress.Security.EscapeOutput ?></div>
				<?php endif; ?>
			</div>

		</div>
	<?php endwhile; ?>

</div><!-- #tribe-events-content -->
