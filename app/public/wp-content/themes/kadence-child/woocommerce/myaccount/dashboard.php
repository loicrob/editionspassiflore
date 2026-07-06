<?php
/**
 * My Account Dashboard — surcharge Passiflore
 *
 * Remplace le texte d'accueil par défaut par un salut personnalisé (selon
 * l'heure), un résumé compact (dernière commande + liste de lecture) et des
 * suggestions de livres. La navigation se fait via le menu du compte.
 *
 * @see plugins/woocommerce/templates/myaccount/dashboard.php (v4.4.0)
 * @package kadence-child
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$pf_user = wp_get_current_user();

// Résumé compact : dernière commande + nombre de livres en liste de lecture.
$pf_last_order = null;
if ( function_exists( 'wc_get_orders' ) ) {
	$pf_recent = wc_get_orders( [
		'customer_id' => $pf_user->ID,
		'limit'       => 1,
		'orderby'     => 'date',
		'order'       => 'DESC',
		'return'      => 'ids',
	] );
	if ( ! empty( $pf_recent ) ) {
		$pf_last_order = wc_get_order( $pf_recent[0] );
	}
}
$pf_rl_count = class_exists( 'Passiflore_Reading_List' ) ? count( Passiflore_Reading_List::get_ids( $pf_user->ID ) ) : null;

// Salutation selon l'heure locale (4h–18h = jour, sinon soir).
$pf_name     = $pf_user->first_name ? $pf_user->first_name : $pf_user->display_name;
$pf_hour     = (int) current_time( 'G' );
$pf_evening  = ( $pf_hour >= 18 || $pf_hour < 4 );
$pf_salut    = $pf_evening ? 'Bonsoir' : 'Bonjour';
$pf_question = $pf_evening ? 'quelle sera votre lecture pour ce soir ?' : 'quelle sera votre lecture du jour ?';
?>

<div class="pf-account-dashboard">

	<p class="pf-account-hello">
		<?php echo esc_html( $pf_salut . ' ' . $pf_name . ', ' . $pf_question ); ?>
	</p>

	<?php if ( $pf_last_order || null !== $pf_rl_count ) : ?>
		<div class="pf-account-summary">
			<?php if ( $pf_last_order ) : ?>
				<a class="pf-card pf-account-summary__chip" href="<?php echo esc_url( $pf_last_order->get_view_order_url() ); ?>">
					<span class="pf-account-summary__label">Dernière commande</span>
					<span class="pf-card-title pf-account-summary__value"><?php echo esc_html( wc_get_order_status_name( $pf_last_order->get_status() ) ); ?></span>
					<span class="pf-account-summary__meta"><?php echo esc_html( wc_format_datetime( $pf_last_order->get_date_created() ) ); ?></span>
				</a>
			<?php endif; ?>
			<?php if ( null !== $pf_rl_count ) : ?>
				<a class="pf-card pf-account-summary__chip" href="<?php echo esc_url( wc_get_account_endpoint_url( 'liste-lecture' ) ); ?>">
					<span class="pf-account-summary__label">Liste de lecture</span>
					<span class="pf-card-title pf-account-summary__value"><?php echo esc_html( $pf_rl_count . ' ' . _n( 'livre', 'livres', $pf_rl_count, 'kadence-child' ) ); ?></span>
					<span class="pf-account-summary__meta">à découvrir</span>
				</a>
			<?php endif; ?>
		</div>
	<?php endif; ?>

	<?php if ( function_exists( 'pf_reco_render' ) ) : ?>
		<section class="pf-account-reco pf-reco">
			<h2 class="pf-titre-3">Suggestions pour vous</h2>
			<?php echo pf_reco_render( $pf_user->ID ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
		</section>
	<?php endif; ?>

</div>

<?php
/**
 * My Account dashboard.
 *
 * @since 2.6.0
 */
do_action( 'woocommerce_account_dashboard' );

// Hooks dépréciés conservés pour compatibilité (cf. template d'origine).
do_action( 'woocommerce_before_my_account' );
do_action( 'woocommerce_after_my_account' );
