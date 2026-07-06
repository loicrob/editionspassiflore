<?php
/**
 * View: Carte (override Passiflore) — PROTOTYPE.
 *
 * Template de premier niveau de la vue « carte ». Calqué sur le core list.php :
 * même conteneur `.tribe-events-view` (pour le switch/JS de TEC) + `components/header`
 * (barre sticky réutilisée), mais le corps est un conteneur Leaflet au lieu de la liste.
 *
 * Les marqueurs sont injectés en JS (window.PassifloreMap) ; ce template ne rend que
 * le conteneur, un message de repli JS, et une liste `<noscript>` pour les navigateurs
 * sans JavaScript.
 *
 * @var string   $rest_url          The REST URL.
 * @var string   $rest_method       The HTTP method.
 * @var int      $should_manage_url int containing if it should manage the URL.
 * @var string[] $container_classes Classes used for the container of the view.
 * @var array    $container_data    An additional set of container `data` attributes.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

$pf_events = class_exists( 'Passiflore_Events_Map' ) ? Passiflore_Events_Map::get_mappable_events() : [];
?>
<div
	<?php tec_classes( $container_classes ); ?>
	data-js="tribe-events-view"
	data-view-rest-url="<?php echo esc_url( $rest_url ); ?>"
	data-view-rest-method="<?php echo esc_attr( $rest_method ); ?>"
	data-view-manage-url="<?php echo esc_attr( $should_manage_url ); ?>"
	<?php foreach ( (array) $container_data as $key => $value ) : ?>
		data-view-<?php echo esc_attr( $key ); ?>="<?php echo esc_attr( $value ); ?>"
	<?php endforeach; ?>
	<?php if ( ! empty( $breakpoint_pointer ) ) : ?>
		data-view-breakpoint-pointer="<?php echo esc_attr( $breakpoint_pointer ); ?>"
	<?php endif; ?>
>
	<section class="tribe-common-l-container tribe-events-l-container">

		<?php $this->template( 'components/loader', [ 'text' => __( 'Loading...', 'the-events-calendar' ) ] ); ?>

		<?php $this->template( 'components/data' ); ?>

		<?php $this->template( 'components/before' ); ?>

		<?php $this->template( 'components/header' ); ?>

		<div class="pf-events-map-wrap">
			<div
				id="pf-events-map"
				class="pf-events-map"
				role="application"
				aria-label="<?php esc_attr_e( 'Carte des événements', 'passiflore' ); ?>"
			></div>

			<p class="pf-events-map-empty" hidden><?php esc_html_e( 'Aucun événement géolocalisé à afficher pour le moment.', 'passiflore' ); ?></p>

			<noscript>
				<ul class="pf-events-map-fallback">
					<?php foreach ( $pf_events as $pf_event ) : ?>
						<li>
							<a href="<?php echo esc_url( get_permalink( $pf_event->ID ) ); ?>"><?php echo esc_html( get_the_title( $pf_event->ID ) ); ?></a>
							<?php
							$pf_venue = tribe_get_venue( $pf_event->ID );
							if ( $pf_venue ) {
								echo ' — ' . esc_html( $pf_venue );
							}
							?>
						</li>
					<?php endforeach; ?>
				</ul>
			</noscript>
		</div>

		<?php $this->template( 'components/after' ); ?>

	</section>
</div>

<?php $this->template( 'components/breakpoints' ); ?>
