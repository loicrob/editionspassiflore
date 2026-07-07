<?php
/**
 * Livraison : tarif réduit au-delà d'un montant de commande.
 */

defined( 'ABSPATH' ) || exit;

/**
 * Nouvelle section dans Réglages > Livraison (WooCommerce > Réglages > Livraison > Tarif réduit)
 * pour rendre le seuil et les coûts réduits modifiables en admin.
 */
add_filter( 'woocommerce_get_sections_shipping', 'passiflore_shipping_seuil_section' );
function passiflore_shipping_seuil_section( $sections ) {
	$sections['passiflore_seuil'] = __( 'Tarif réduit', 'kadence-child' );
	return $sections;
}

add_filter( 'woocommerce_get_settings_shipping', 'passiflore_shipping_seuil_settings', 10, 2 );
function passiflore_shipping_seuil_settings( $settings, $current_section ) {
	if ( 'passiflore_seuil' !== $current_section ) {
		return $settings;
	}

	return array(
		array(
			'title' => __( 'Tarif de livraison réduit au-delà d’un montant de commande', 'kadence-child' ),
			'type'  => 'title',
			'desc'  => __( 'S’applique aux méthodes « Forfait » Point relais et Livraison à domicile de la zone National.', 'kadence-child' ),
			'id'    => 'pf_shipping_seuil_options',
		),
		array(
			'title'    => __( 'Seuil (€ TTC)', 'kadence-child' ),
			'desc'     => __( 'Sous-total du panier (TTC, tel qu’affiché au client) à partir duquel le tarif réduit s’applique.', 'kadence-child' ),
			'id'       => 'pf_shipping_seuil',
			'default'  => '35',
			'type'     => 'text',
			'desc_tip' => true,
			'css'      => 'width:80px;',
		),
		array(
			'title'    => __( 'Coût réduit — Point relais (€)', 'kadence-child' ),
			'id'       => 'pf_shipping_cout_relais',
			'default'  => '0.01',
			'type'     => 'text',
			'desc_tip' => true,
			'css'      => 'width:80px;',
		),
		array(
			'title'    => __( 'Coût réduit — Livraison à domicile (€)', 'kadence-child' ),
			'id'       => 'pf_shipping_cout_domicile',
			'default'  => '2.00',
			'type'     => 'text',
			'desc_tip' => true,
			'css'      => 'width:80px;',
		),
		array(
			'type' => 'sectionend',
			'id'   => 'pf_shipping_seuil_options',
		),
	);
}

/**
 * Normalise une valeur numérique saisie en admin (accepte la virgule décimale française).
 */
function passiflore_shipping_option_float( $option_name, $default ) {
	return (float) str_replace( ',', '.', get_option( $option_name, $default ) );
}

/**
 * Seuil et coûts appliqués par méthode (Forfait) une fois le sous-total TTC du panier
 * (ce que le client voit) atteint ou dépassé. Les instance_id ci-dessous correspondent
 * aux méthodes "Forfait" (Point relais, 3 €) et "Livraison à domicile" (4 €) de la zone
 * de livraison "National" — à ajuster si ces méthodes sont supprimées/recréées.
 */
add_filter( 'woocommerce_package_rates', 'passiflore_shipping_rates_seuil', 100, 2 );
function passiflore_shipping_rates_seuil( $rates, $package ) {
	$seuil         = passiflore_shipping_option_float( 'pf_shipping_seuil', '35' );
	$couts_reduits = array(
		1 => passiflore_shipping_option_float( 'pf_shipping_cout_relais', '0.01' ),   // Point relais.
		2 => passiflore_shipping_option_float( 'pf_shipping_cout_domicile', '2.00' ), // Livraison à domicile.
	);

	if ( ! WC()->cart || WC()->cart->get_displayed_subtotal() < $seuil ) {
		return $rates;
	}

	foreach ( $rates as $rate ) {
		if ( 'flat_rate' !== $rate->get_method_id() || ! isset( $couts_reduits[ $rate->get_instance_id() ] ) ) {
			continue;
		}

		$cost = $couts_reduits[ $rate->get_instance_id() ];
		$rate->set_cost( $cost );

		if ( $rate->get_taxes() ) {
			$rate->set_taxes( WC_Tax::calc_shipping_tax( $cost, WC_Tax::get_shipping_tax_rates() ) );
		}
	}

	return $rates;
}
