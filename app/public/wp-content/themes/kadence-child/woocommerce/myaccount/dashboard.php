<?php
/**
 * Accueil du compte — surcharge Passiflore
 *
 * Salutation personnalisée selon l'heure, puis une grille de tuiles vers les
 * pages du compte, chacune portant une information vivante. La navigation
 * latérale est masquée ici (les tuiles en tiennent lieu) et réapparaît dès qu'on
 * entre dans une page — cf. inc/account-hub.php pour toute la logique et les
 * raisons.
 *
 * @see plugins/woocommerce/templates/myaccount/dashboard.php (v4.4.0)
 * @package kadence-child
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$pf_user = wp_get_current_user();

// Salutation selon l'heure locale (4h–18h = jour, sinon soir).
$pf_name    = $pf_user->first_name ? $pf_user->first_name : $pf_user->display_name;
$pf_hour    = (int) current_time( 'G' );
$pf_evening = ( $pf_hour >= 18 || $pf_hour < 4 );
$pf_salut   = $pf_evening ? 'Bonsoir,' : 'Bonjour,';
?>

<div class="pf-account-dashboard">

	<p class="pf-account-hello">
		<?php echo esc_html( $pf_salut . ' ' . $pf_name . '.' ); ?>
	</p>

	<?php
	if ( function_exists( 'pf_account_hub_tiles' ) ) {
		echo pf_account_hub_tiles();  // phpcs:ignore WordPress.Security.EscapeOutput
		echo pf_account_hub_logout(); // phpcs:ignore WordPress.Security.EscapeOutput
	}
	?>

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
