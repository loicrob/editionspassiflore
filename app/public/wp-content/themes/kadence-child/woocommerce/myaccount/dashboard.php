<?php
/**
 * My Account Dashboard — surcharge Passiflore
 *
 * Remplace le texte d'accueil par défaut par un salut personnalisé (selon
 * l'heure) et des suggestions de livres. La navigation se fait via le menu
 * du compte.
 *
 * @see plugins/woocommerce/templates/myaccount/dashboard.php (v4.4.0)
 * @package kadence-child
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$pf_user = wp_get_current_user();

// Salutation selon l'heure locale (4h–18h = jour, sinon soir).
$pf_name     = $pf_user->first_name ? $pf_user->first_name : $pf_user->display_name;
$pf_hour     = (int) current_time( 'G' );
$pf_evening  = ( $pf_hour >= 18 || $pf_hour < 4 );
$pf_salut    = $pf_evening ? 'Bonsoir' : 'Bonjour';
?>

<div class="pf-account-dashboard">

	<p class="pf-account-hello">
		<?php echo esc_html( $pf_salut . ' ' . $pf_name . ', quelle sera votre prochaine lecture ?' ); ?>
	</p>

	<?php if ( function_exists( 'pf_reco_render' ) ) :
		$pf_reco_display = function_exists( 'pf_shelf_display_pref' ) ? pf_shelf_display_pref( 'reco' ) : 'covers';
	?>
		<section class="pf-account-reco pf-reco">
			<div class="pf-shelf-head">
				<h2 class="pf-titre-3">Suggestions de Passiflore<span class="pf-numerique-tip"><span class="pf-numerique-tip__trigger" tabindex="0" role="button" aria-label="Comment sont établies ces suggestions ?" aria-describedby="pf-reco-tip-bubble"><svg viewBox="0 0 24 24" width="15" height="15" fill="currentColor" aria-hidden="true"><path d="M11 7h2v2h-2V7zm0 4h2v6h-2v-6zm1-9C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm0 18c-4.41 0-8-3.59-8-8s3.59-8 8-8 8 3.59 8 8-3.59 8-8 8z"/></svg></span><span class="pf-numerique-tip__bubble" id="pf-reco-tip-bubble" role="tooltip">Suggestions établies à partir de vos commandes passées et de votre liste de lecture. À défaut, présentation des nouveautés.</span></span></h2>
				<?php if ( function_exists( 'pf_shelf_display_switch' ) ) { echo pf_shelf_display_switch( 'reco', $pf_reco_display ); } // phpcs:ignore WordPress.Security.EscapeOutput ?>
			</div>
			<div class="pf-shelf-slot" data-pf-shelf-slot="reco"><?php echo pf_reco_render( $pf_user->ID, 12, $pf_reco_display ); // phpcs:ignore WordPress.Security.EscapeOutput ?></div>
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
