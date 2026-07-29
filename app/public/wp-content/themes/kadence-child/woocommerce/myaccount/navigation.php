<?php
/**
 * My Account navigation — surcharge Passiflore
 *
 * Reprend le composant partagé « section-nav » (fiche livre / fiche événement)
 * via sa variante `.pf-sectionnav--static` : rail à pastilles sur desktop,
 * pilules horizontales sticky sur mobile. Contrairement à la sectionnav des
 * fiches (scrollspy sur des ancres d'UNE page), ce menu pointe vers des PAGES
 * distinctes → l'item actif est la page courante, posée côté serveur par
 * WooCommerce (`is-active` dans les classes du <li>, recopiée sur le <a> pour
 * que `.pf-sectionnav a.is-active` du composant s'applique). Voir style.css
 * (bloc `.pf-sectionnav--static`) et inc/section-nav.php.
 *
 * Structure conservée en <ul>/<li> (menu sémantique) ; le wrapper .pf-scroll-fade
 * fournit les ombres de bord du défilement horizontal mobile (scroll-fade.js).
 *
 * @see     plugins/woocommerce/templates/myaccount/navigation.php
 * @package WooCommerce\Templates
 * @version 9.3.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

do_action( 'woocommerce_before_account_navigation' );
?>

<nav class="woocommerce-MyAccount-navigation pf-sectionnav pf-sectionnav--static" aria-label="<?php esc_html_e( 'Account pages', 'woocommerce' ); ?>">
	<div class="pf-scroll-fade">
		<ul class="pf-sectionnav__track">
			<?php foreach ( wc_get_account_menu_items() as $endpoint => $label ) : ?>
				<li class="<?php echo wc_get_account_menu_item_classes( $endpoint ); ?>">
					<?php // data-label : libellé recopié pour le double invisible en gras (`a::after`, style.css) qui réserve la largeur de l'état actif. ?>
					<a class="menu-item-link<?php echo wc_is_current_account_menu_item( $endpoint ) ? ' is-active' : ''; ?>" data-label="<?php echo esc_attr( $label ); ?>" href="<?php echo esc_url( wc_get_account_endpoint_url( $endpoint ) ); ?>" <?php echo wc_is_current_account_menu_item( $endpoint ) ? 'aria-current="page"' : ''; ?>>
						<?php echo esc_html( $label ); ?>
					</a>
				</li>
			<?php endforeach; ?>
		</ul>
	</div>
</nav>

<?php do_action( 'woocommerce_after_account_navigation' ); ?>
