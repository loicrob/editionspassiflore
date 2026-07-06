<?php
/**
 * Override: Events Bar Views — switch (no dropdown).
 *
 * @var string $view_slug    Slug of the current view.
 * @var array  $public_views Array of public views keyed by slug.
 */
if ( ! defined( 'ABSPATH' ) ) exit;
?>
<div class="tribe-events-c-events-bar__views">
	<h3 class="tribe-common-a11y-visual-hide">
		<?php printf( esc_html__( '%s Views Navigation', 'the-events-calendar' ), tribe_get_event_label_singular() ); ?>
	</h3>
	<div class="pf-switch pf-switch--solid pf-view-switch" role="group" aria-label="<?php esc_attr_e( 'Select Calendar View', 'the-events-calendar' ); ?>">
		<?php foreach ( $public_views as $slug => $data ) : ?>
		<?php
		// La vue « carte » (Leaflet) ne passe pas par la bascule AJAX de TEC : tout
		// aller/retour impliquant la carte est un rechargement complet (init Leaflet
		// propre). On retire donc data-js quand la carte est source OU cible.
		$pf_plain = ( 'carte' === $slug || 'carte' === $view_slug );
		?>
		<a
			href="<?php echo esc_url( $data->view_url ); ?>"
			class="button pf-switch__btn pf-view-switch__btn<?php echo $view_slug === $slug ? ' is-active' : ''; ?>"
			<?php if ( ! $pf_plain ) : ?>data-js="tribe-events-view-link"<?php endif; ?>
			aria-label="<?php echo esc_attr( $data->aria_label ); ?>"
			<?php if ( $view_slug === $slug ) : ?>aria-current="true"<?php endif; ?>
		><?php echo esc_html( $data->view_label ); ?></a>
		<?php endforeach; ?>
	</div>
</div>
