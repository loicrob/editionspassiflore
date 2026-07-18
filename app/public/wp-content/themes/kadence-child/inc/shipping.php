<?php
/**
 * Livraison : tarif réduit au-delà d'un montant de commande.
 *
 * Le seuil et le forfait réduit se configurent par méthode « Forfait » (flat rate),
 * directement dans la popup « Configurer forfait » d'une zone d'expédition
 * (WooCommerce > Réglages > Expédition > Zones d'expédition), et non plus dans un
 * onglet global.
 */

defined( 'ABSPATH' ) || exit;

/**
 * Ajoute deux champs à la popup « Configurer forfait » de chaque méthode « Forfait » :
 * - le seuil de sous-total (TTC) à partir duquel un forfait réduit s'applique ;
 * - le montant de ce forfait réduit.
 *
 * Insérés juste après le champ natif « Coût » pour regrouper la logique de tarif.
 */
add_filter( 'woocommerce_shipping_instance_form_fields_flat_rate', 'passiflore_flat_rate_seuil_fields' );
function passiflore_flat_rate_seuil_fields( $fields ) {
	$extra = array(
		'pf_seuil'       => array(
			'title'       => __( 'Seuil tarif réduit (€ TTC)', 'kadence-child' ),
			'type'        => 'text',
			'placeholder' => __( 'ex. 35', 'kadence-child' ),
			'description' => __( 'Sous-total du panier (TTC, tel qu’affiché au client) à partir duquel le forfait réduit ci-dessous remplace le coût normal. Laisser vide pour ne pas appliquer de tarif réduit.', 'kadence-child' ),
			'desc_tip'    => true,
			'default'     => '',
		),
		'pf_cout_reduit' => array(
			'title'       => __( 'Forfait à partir du seuil (€)', 'kadence-child' ),
			'type'        => 'text',
			'placeholder' => '0',
			'description' => __( 'Coût d’expédition appliqué une fois le seuil atteint. Laisser vide = gratuit.', 'kadence-child' ),
			'desc_tip'    => true,
			'default'     => '',
		),
	);

	$out = array();
	foreach ( $fields as $key => $field ) {
		$out[ $key ] = $field;
		if ( 'cost' === $key ) {
			$out += $extra;
		}
	}
	// Filet de sécurité si le champ « cost » venait à disparaître d'une future version.
	$out += $extra;

	return $out;
}

/**
 * Convertit une valeur numérique saisie en admin (accepte la virgule décimale française).
 */
function passiflore_shipping_parse_float( $value ) {
	return (float) str_replace( ',', '.', (string) $value );
}

/**
 * Applique le forfait réduit, par méthode « Forfait », lorsque le sous-total TTC du
 * panier (ce que le client voit) atteint ou dépasse le seuil configuré pour cette
 * méthode. Les réglages sont lus sur l'instance de méthode concernée (option
 * « woocommerce_flat_rate_{instance_id}_settings »).
 */
add_filter( 'woocommerce_package_rates', 'passiflore_shipping_rates_seuil', 100, 2 );
function passiflore_shipping_rates_seuil( $rates, $package ) {
	if ( ! WC()->cart ) {
		return $rates;
	}

	$subtotal = WC()->cart->get_displayed_subtotal();

	foreach ( $rates as $rate ) {
		if ( 'flat_rate' !== $rate->get_method_id() ) {
			continue;
		}

		$settings = get_option( 'woocommerce_flat_rate_' . $rate->get_instance_id() . '_settings' );
		if ( ! is_array( $settings ) || '' === trim( (string) ( $settings['pf_seuil'] ?? '' ) ) ) {
			continue;
		}

		$seuil = passiflore_shipping_parse_float( $settings['pf_seuil'] );
		if ( $seuil <= 0 || $subtotal < $seuil ) {
			continue;
		}

		$cost = passiflore_shipping_parse_float( $settings['pf_cout_reduit'] ?? '0' );
		$rate->set_cost( $cost );

		if ( $rate->get_taxes() ) {
			$rate->set_taxes( WC_Tax::calc_shipping_tax( $cost, WC_Tax::get_shipping_tax_rates() ) );
		}
	}

	return $rates;
}

/**
 * Migration unique : reporte les valeurs effectives de l'ancien réglage global
 * (onglet « Tarif réduit », supprimé) dans les réglages d'instance des deux méthodes
 * « Forfait » de la zone National, pour préserver le comportement à l'identique et
 * pré-remplir la popup. Sans valeur enregistrée, on retombe sur les défauts de l'ancien
 * code (seuil 35 €, Point relais 0,01 €, Livraison à domicile 2,00 €).
 */
add_action( 'admin_init', 'passiflore_shipping_seuil_migrate' );
function passiflore_shipping_seuil_migrate() {
	if ( get_option( 'pf_shipping_seuil_migrated' ) ) {
		return;
	}

	$seuil     = get_option( 'pf_shipping_seuil', '35' );
	$anciennes = array(
		1 => get_option( 'pf_shipping_cout_relais', '0.01' ),   // Point relais.
		2 => get_option( 'pf_shipping_cout_domicile', '2.00' ), // Livraison à domicile.
	);

	foreach ( $anciennes as $instance_id => $cout ) {
		$key      = 'woocommerce_flat_rate_' . $instance_id . '_settings';
		$settings = get_option( $key );
		if ( ! is_array( $settings ) ) {
			continue;
		}
		if ( '' === (string) ( $settings['pf_seuil'] ?? '' ) ) {
			$settings['pf_seuil'] = $seuil;
		}
		if ( '' === (string) ( $settings['pf_cout_reduit'] ?? '' ) ) {
			$settings['pf_cout_reduit'] = $cout;
		}
		update_option( $key, $settings );
	}

	update_option( 'pf_shipping_seuil_migrated', 1 );
}
