<?php
/**
 * Panier + validation de commande (blocs) — expose le statut « précommande »
 * de chaque article.
 *
 * Le badge natif « Available on backorder » de WooCommerce Blocks ne s'affiche
 * jamais sur ce site : son champ Store API `show_backorder_badge` exige
 * `backorders_require_notification()`, lui-même conditionné à
 * `_manage_stock = 'yes'` — or aucun produit du catalogue ne gère son stock
 * (cf. inc/stock-sync.php, qui pilote `_stock_status` depuis `disponibilite`).
 * On expose donc `_stock_status === 'onbackorder'` nous-mêmes, par article de
 * panier, sous `extensions.passiflore.is_backorder` — même idiome que
 * `cart.extensions.passiflore.has_numerique` (inc/checkout-consent.php), à
 * l'échelle de l'article plutôt que du panier entier. Le récapitulatif de
 * commande (/commander) lit la même donnée `wc/store/cart` que le panier,
 * donc rien à dupliquer ici pour cette page.
 *
 * assets/js/cart-backorder-badge.js lit ce champ pour poser un badge « En
 * précommande » à la place du badge de promo (masqué en CSS, cart.css +
 * checkout.css) : aucun hook PHP classique ne peut atteindre cet emplacement
 * dans un panier/récapitulatif rendu en React.
 *
 * `is_numerique` (même terme `pa_format_particulier` que numerique-offer.php)
 * accompagne `is_backorder` pour que le JS choisisse le bon texte d'infobulle
 * — la version numérique n'est jamais expédiée, son message n'a donc rien à
 * voir avec celui d'un livre papier.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'woocommerce_init', function () {
	if ( ! function_exists( 'woocommerce_store_api_register_endpoint_data' ) ) {
		return;
	}

	woocommerce_store_api_register_endpoint_data( [
		'endpoint'        => Automattic\WooCommerce\StoreApi\Schemas\V1\CartItemSchema::IDENTIFIER,
		'namespace'       => 'passiflore',
		'data_callback'   => static function ( $cart_item ) {
			$product = $cart_item['data'] ?? null;
			return [
				'is_backorder'  => $product instanceof WC_Product && 'onbackorder' === $product->get_stock_status(),
				'is_numerique'  => $product instanceof WC_Product && has_term( 'numerique', 'pa_format_particulier', $product->get_id() ),
			];
		},
		'schema_callback' => static function () {
			return [
				'is_backorder' => [
					'description' => __( 'Cet article est en précommande (disponibilité à paraître / bientôt disponible).', 'kadence-child' ),
					'type'        => 'boolean',
					'readonly'    => true,
				],
				'is_numerique' => [
					'description' => __( 'Cet article est un livre numérique.', 'kadence-child' ),
					'type'        => 'boolean',
					'readonly'    => true,
				],
			];
		},
	] );
} );
