<?php
/**
 * Téléphone obligatoire pour une livraison en point relais.
 *
 * Les réseaux relais préviennent le client par SMS de la mise à disposition du
 * colis : sans numéro, il n'est averti que par l'e-mail Boxtal. On rend donc la
 * saisie obligatoire — mais SEULEMENT quand la commande part en point relais,
 * pour ne pas réclamer un numéro sur une commande 100 % numérique, qui n'en a
 * aucun besoin (minimisation RGPD).
 *
 * Deux couches, parce que WooCommerce n'offre pas de « champ cœur conditionnel » :
 *
 *  1. AFFICHAGE (libellé + astérisque + validation côté client) — filtre sur la
 *     locale pays. `phone` est un champ CŒUR du tunnel en blocs, piloté par une
 *     option unique et globale (`woocommerce_checkout_phone_field`), ni
 *     conditionnable par mode d'expédition ni par groupe d'adresse. Le seul levier
 *     qui redescende jusqu'au client est `countryData[<pays>].locale`, alimenté par
 *     cette locale — le client y lit `required`, `label` et `optionalLabel`.
 *     ⚠️ Le mécanisme de règles JSON-Schema utilisé dans inc/checkout-consent.php
 *     ne s'applique QU'aux champs additionnels — `CheckoutFields::get_fields_for_location()`
 *     ne retourne que ceux-là, jamais les champs cœur. Inutilisable ici, ne pas
 *     repartir de cette piste.
 *
 *  2. AUTORITÉ (refus de la commande) — garde serveur sur la validation d'adresse,
 *     qui elle connaît le groupe (livraison vs facturation).
 *
 * Deux limites assumées :
 *  - la couche 1 est figée au rendu de la page : un client qui bascule vers le
 *    point relais sans recharger ne verra pas l'astérisque apparaître. C'est
 *    exactement le trou que couvre la couche 2.
 *  - la locale pays ne distingue pas livraison et facturation : quand la commande
 *    part en relais ET que le client décoche « utiliser la même adresse pour la
 *    facturation », le téléphone est réclamé sur les deux formulaires. Seule la
 *    livraison est réellement exigée côté serveur.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Case « Téléphone obligatoire » dans la popup « Configurer forfait » de chaque
 * méthode « Forfait », à côté des champs de seuil de inc/shipping.php.
 *
 * Nécessaire parce que la détection automatique ci-dessous ne peut rien voir tant
 * que Boxtal n'est pas appairé : hors production, « En point relais » n'est qu'un
 * `flat_rate` ordinaire, que rien ne distingue d'une livraison à domicile. Ce
 * réglage rend le mode relais déclarable à la main, donc testable, et couvre aussi
 * un futur transporteur relais qui ne passerait pas par Boxtal.
 */
add_filter( 'woocommerce_shipping_instance_form_fields_flat_rate', 'pf_relay_phone_instance_field' );
function pf_relay_phone_instance_field( $fields ) {
	$fields['pf_tel_obligatoire'] = array(
		'title'       => __( 'Téléphone obligatoire', 'kadence-child' ),
		'type'        => 'checkbox',
		'label'       => __( 'Exiger un numéro de téléphone pour ce mode de livraison', 'kadence-child' ),
		'default'     => 'no',
		'description' => __( 'À cocher pour une livraison en point relais : le réseau prévient le client par SMS de la mise à disposition du colis. Inutile sur un tarif que Boxtal gère déjà en point relais — la détection est alors automatique.', 'kadence-child' ),
		'desc_tip'    => true,
	);

	return $fields;
}

/**
 * La commande en cours part-elle vers un point relais ?
 *
 * Deux signaux, dans cet ordre :
 *
 *  1. le réglage d'instance ci-dessus, lu directement en base — volontairement
 *     SANS passer par le helper Boxtal équivalent : ce chemin doit rester debout
 *     quand le connecteur est absent ou désactivé ;
 *  2. les réseaux relais que Boxtal attache au tarif. Détection déléguée plutôt
 *     qu'une liste d'identifiants de méthode en dur (ils diffèrent d'un
 *     environnement à l'autre) : `get_shipping_method_networks()` couvre la
 *     méthode propre au connecteur (`boxtal_connect:*`, dont les réseaux vivent en
 *     session, réécrits à chaque calcul de tarif) comme un « Forfait » auquel des
 *     réseaux ont été attachés dans ses réglages. Un tarif Boxtal de livraison à
 *     domicile ne porte aucun réseau et ressort donc bien à false.
 */
function pf_relay_shipping_selected(): bool {
	if ( ! function_exists( 'WC' ) || ! WC()->session ) {
		return false;
	}

	foreach ( (array) WC()->session->get( 'chosen_shipping_methods', array() ) as $rate_id ) {
		if ( ! is_string( $rate_id ) || false === strpos( $rate_id, ':' ) ) {
			continue;
		}

		list( $method_id, $instance_id ) = explode( ':', $rate_id, 2 );

		$settings = get_option( 'woocommerce_' . $method_id . '_' . $instance_id . '_settings' );
		if ( is_array( $settings ) && 'yes' === ( $settings['pf_tel_obligatoire'] ?? 'no' ) ) {
			return true;
		}

		if ( class_exists( '\Boxtal\BoxtalConnectWoocommerce\Util\Shipping_Rate_Util' ) ) {
			$networks = \Boxtal\BoxtalConnectWoocommerce\Util\Shipping_Rate_Util::get_shipping_method_networks( $rate_id );
			if ( ! empty( $networks ) ) {
				return true;
			}
		}
	}

	return false;
}

/**
 * Couche 1 — libellé du champ, et `required` dès que le relais est retenu, dans la
 * locale de CHAQUE pays.
 *
 * Il faut passer par les pays et non par la locale « default » : `get_country_data()`
 * n'exporte au client que les entrées par pays, jamais `default`. Ajouter une
 * entrée à un pays qui n'en avait pas est sans risque : la locale est surchargée
 * PAR-DESSUS les champs par défaut (`wc_array_overlay()` côté PHP, étalement de
 * `defaultFields` côté JS), jamais substituée à eux.
 *
 * Le client affiche `label` quand le champ est requis et `optionalLabel` sinon :
 * la mention « requis pour une commande en point relais » n'a donc de sens que
 * dans le second, où elle annonce ce qui va se passer. Elle est réservée aux
 * paniers à expédier — sur une commande 100 % numérique, elle n'aurait aucun sens.
 *
 * Restreint au rendu de la page Commander. Ailleurs — écran d'administration,
 * formulaire « Adresses » du compte client, Store API — la locale ne doit rien
 * exiger de plus : c'est le garde-fou ci-dessous qui tranche, et lui sait de quel
 * groupe d'adresse il parle. `did_action( 'wp' )` évite d'interroger les balises
 * conditionnelles avant que la requête principale ne soit jouée.
 */
add_filter( 'woocommerce_get_country_locale', 'pf_relay_phone_locale', 20 );
function pf_relay_phone_locale( $locale ) {
	if ( is_admin() || wp_doing_ajax() || ! did_action( 'wp' ) ) {
		return $locale;
	}
	if ( ! function_exists( 'is_checkout' ) || ! is_checkout() ) {
		return $locale;
	}
	if ( ! WC()->cart || ! WC()->cart->needs_shipping() ) {
		return $locale;
	}

	$phone = array(
		'label'         => __( 'Téléphone (requis pour une commande en point relais)', 'kadence-child' ),
		'optionalLabel' => __( 'Téléphone (requis pour une commande en point relais)', 'kadence-child' ),
	);

	if ( pf_relay_shipping_selected() ) {
		$phone['required'] = true;
	}

	$countries = array_merge(
		WC()->countries->get_allowed_countries(),
		WC()->countries->get_shipping_countries()
	);

	foreach ( array_keys( $countries ) as $code ) {
		$locale[ $code ]['phone'] = array_merge( $locale[ $code ]['phone'] ?? array(), $phone );
	}

	return $locale;
}

/**
 * Couche 2 — refuse la commande si le colis part en point relais sans téléphone.
 *
 * ⚠️ Ce hook sert aussi au formulaire « Adresses » du compte client
 * (`CheckoutFieldsFrontend::validate_and_persist_fields_for_customer()`), qui ne
 * lui passe QUE les champs additionnels. La présence de la clé `phone` distingue
 * les deux appels — sans ce test, enregistrer une adresse depuis le compte
 * échouerait sur un téléphone qui n'a jamais été soumis.
 */
add_action( 'woocommerce_blocks_validate_location_address_fields', 'pf_relay_phone_validate', 10, 3 );
function pf_relay_phone_validate( $errors, $fields, $group ) {
	if ( 'shipping' !== $group || ! array_key_exists( 'phone', (array) $fields ) ) {
		return;
	}
	if ( '' !== trim( (string) $fields['phone'] ) || ! pf_relay_shipping_selected() ) {
		return;
	}

	$errors->add(
		'pf_relay_phone_requis',
		__( 'Merci d’indiquer un numéro de téléphone mobile : le point relais vous prévient par SMS de la mise à disposition de votre colis.', 'kadence-child' )
	);
}
