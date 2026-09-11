<?php
/**
 * Téléphone obligatoire sur toute commande ; numéro de mobile exigé en point
 * relais.
 *
 * Le transporteur reçoit le téléphone de l'adresse de livraison (Boxtal le transmet
 * avec le contact du destinataire) et s'en sert en cas de souci ; pour un retrait en
 * boutique ou une commande numérique, il sert à joindre le client au sujet de sa
 * commande. En point relais, le réseau prévient en plus le client par SMS de la
 * mise à disposition du colis : un fixe n'y suffit pas.
 *
 * Seuls les numéros français sont vérifiés comme mobiles (06/07) : distinguer un
 * mobile d'un fixe à l'étranger demanderait une base de numérotation par pays
 * (libphonenumber). Un numéro étranger est donc accepté tel quel plutôt que de
 * risquer de refuser une vraie commande. Hors point relais, tout numéro convient
 * (certains lecteurs n'ont qu'un fixe).
 *
 * Deux couches, parce que WooCommerce n'offre pas de « champ cœur conditionnel » :
 *
 *  1. AFFICHAGE (libellé + astérisque + validation côté client) — filtre sur la
 *     locale pays. `phone` est un champ CŒUR du tunnel en blocs, piloté par une
 *     option unique et globale (`woocommerce_checkout_phone_field`) : réglée sur
 *     « requis », elle s'imposerait aussi au formulaire « Adresses » du compte
 *     client, resterait un réglage en base à reporter en prod et ne saurait pas
 *     adapter le libellé au panier. Le seul levier qui redescende jusqu'au client
 *     est `countryData[<pays>].locale`, alimenté par cette locale — le client y lit
 *     `required` et `label`.
 *     ⚠️ Le mécanisme de règles JSON-Schema utilisé dans inc/checkout-consent.php
 *     ne s'applique QU'aux champs additionnels — `CheckoutFields::get_fields_for_location()`
 *     ne retourne que ceux-là, jamais les champs cœur. Inutilisable ici, ne pas
 *     repartir de cette piste.
 *
 *  2. AUTORITÉ (refus de la commande) — garde serveur sur la validation d'adresse,
 *     qui elle connaît le groupe (livraison vs facturation).
 *
 * Limite assumée : la couche 1 est figée au rendu de la page et ne peut donc pas
 * suivre le mode de livraison choisi. D'où un libellé unique qui annonce l'exigence
 * du mobile en relais — exigence que seule la couche 2 fait respecter.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Case « Point relais » dans la popup « Configurer forfait » de chaque méthode
 * « Forfait », à côté des champs de seuil de inc/shipping.php.
 *
 * Nécessaire parce que la détection automatique ci-dessous ne peut rien voir tant
 * que Boxtal n'est pas appairé : hors production, « En point relais » n'est qu'un
 * `flat_rate` ordinaire, que rien ne distingue d'une livraison à domicile. Ce
 * réglage rend le mode relais déclarable à la main, donc testable, et couvre aussi
 * un futur transporteur relais qui ne passerait pas par Boxtal.
 *
 * La clé `pf_tel_obligatoire` date de l'époque où le téléphone n'était exigé qu'en
 * relais : conservée telle quelle, puisqu'elle est déjà enregistrée en base.
 */
add_filter( 'woocommerce_shipping_instance_form_fields_flat_rate', 'pf_relay_phone_instance_field' );
function pf_relay_phone_instance_field( $fields ) {
	$fields['pf_tel_obligatoire'] = array(
		'title'       => __( 'Point relais', 'kadence-child' ),
		'type'        => 'checkbox',
		'label'       => __( 'Exiger un numéro de mobile (SMS du point relais)', 'kadence-child' ),
		'default'     => 'no',
		'description' => __( 'À cocher pour une livraison en point relais : le réseau prévient le client par SMS de la mise à disposition du colis, un numéro de mobile est donc exigé. Inutile sur un tarif que Boxtal gère déjà en point relais — la détection est alors automatique.', 'kadence-child' ),
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
 * Ce numéro peut-il recevoir le SMS du point relais ?
 *
 * Seul un numéro français se laisse vérifier : mobile = 06 ou 07 (les mobiles des
 * DROM au format national — 0690, 0692, 0694, 0696… — en font partie) ; le 09 (box
 * internet) ne reçoit pas les SMS. `+33`/`0033` est ramené au format national, y
 * compris avec le 0 superflu (« +33 (0)6… », « +33 06… »). Tout autre numéro
 * international est accepté tel quel, et un format national n'est lu comme
 * français que si le colis part en France.
 */
function pf_phone_accepts_sms( string $phone, string $country ): bool {
	$number = preg_replace( '/[^\d+]/', '', str_replace( '(0)', '', $phone ) );

	if ( preg_match( '/^(?:\+|00)33/', $number ) ) {
		$number = preg_replace( '/^(?:\+|00)330?/', '0', $number );
	} elseif ( preg_match( '/^(?:\+|00)/', $number ) || 'FR' !== $country ) {
		return true;
	}

	return (bool) preg_match( '/^0[67]\d{8}$/', $number );
}

/**
 * Message « téléphone manquant », partagé par la garde serveur et le toast client.
 */
function pf_checkout_phone_missing_message(): string {
	return __( 'Merci d’indiquer un numéro de téléphone : il sert à vous joindre au sujet de votre commande.', 'kadence-child' );
}

/**
 * Couche 1 — `required` et libellé sur toute commande, dans la locale de CHAQUE
 * pays.
 *
 * Il faut passer par les pays et non par la locale « default » : `get_country_data()`
 * n'exporte au client que les entrées par pays, jamais `default`. Ajouter une
 * entrée à un pays qui n'en avait pas est sans risque : la locale est surchargée
 * PAR-DESSUS les champs par défaut (`wc_array_overlay()` côté PHP, étalement de
 * `defaultFields` côté JS), jamais substituée à eux.
 *
 * La mention du point relais n'a de sens que si quelque chose est expédié : elle
 * disparaît sur une commande 100 % numérique. La locale ne distingue pas livraison
 * et facturation : le champ est requis sur chaque formulaire affiché, ce qui est
 * voulu (la couche 2 exige la facturation).
 *
 * Restreint au rendu de la page Commander. Ailleurs — écran d'administration,
 * formulaire « Adresses » du compte client, Store API — la locale ne doit rien
 * exiger de plus : c'est le garde-fou ci-dessous qui tranche, et lui sait de quel
 * groupe d'adresse il parle. `did_action( 'wp' )` évite d'interroger les balises
 * conditionnelles avant que la requête principale ne soit jouée.
 */
add_filter( 'woocommerce_get_country_locale', 'pf_checkout_phone_locale', 20 );
function pf_checkout_phone_locale( $locale ) {
	if ( is_admin() || wp_doing_ajax() || ! did_action( 'wp' ) ) {
		return $locale;
	}
	if ( ! function_exists( 'is_checkout' ) || ! is_checkout() ) {
		return $locale;
	}

	$phone = array(
		'label'    => WC()->cart && WC()->cart->needs_shipping()
			? __( 'Téléphone (mobile pour un point relais)', 'kadence-child' )
			: __( 'Téléphone', 'kadence-child' ),
		'required' => true,
	);

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
 * Texte du toast quand la commande est bloquée côté client faute de téléphone : le
 * même que la garde serveur, au lieu du générique des blocs (« Veuillez saisir
 * un(e) téléphone … valide »). Le toast est celui du contrôleur des notices
 * (assets/js/wc-block-notices-toast.js, validation des champs), qui accepte des
 * surcharges par identifiant d'erreur. Priorité 20 : après son enregistrement.
 */
add_action( 'wp_enqueue_scripts', 'pf_checkout_phone_toast_message', 20 );
function pf_checkout_phone_toast_message() {
	if ( ! wp_script_is( 'pf-wc-block-notices-toast' ) || ! is_checkout() ) {
		return;
	}

	$message = pf_checkout_phone_missing_message();
	wp_add_inline_script(
		'pf-wc-block-notices-toast',
		'window.pfFieldErrorMessages = Object.assign( window.pfFieldErrorMessages || {}, '
			. wp_json_encode( array( 'shipping_phone' => $message, 'billing_phone' => $message ) ) . ' );',
		'before'
	);
}

/**
 * Couche 2 — refuse toute commande sans téléphone, et le point relais sans mobile.
 *
 *  - FACTURATION : présence. Ce groupe est validé à chaque commande et reçoit la
 *    copie du téléphone de livraison quand « utiliser la même adresse » est coché ;
 *    c'est aussi le seul formulaire affiché en retrait en boutique et pour une
 *    commande numérique. Il couvre donc tous les cas.
 *  - LIVRAISON, en point relais seulement : mobile. C'est ce numéro que Boxtal
 *    transmet au transporteur.
 *
 * Les deux contrôles sont indépendants et tournent dès la validation de la requête,
 * avant toute mise à jour de la commande : un téléphone vide en relais fait donc
 * remonter les deux messages — cas théorique, la couche 1 bloque le champ vide
 * avant l'envoi.
 *
 * Panier vide = hors tunnel (paiement d'une commande existante par la Store API) :
 * on ne réclame rien à une commande déjà passée.
 *
 * ⚠️ Ce hook sert aussi au formulaire « Adresses » du compte client
 * (`CheckoutFieldsFrontend::validate_and_persist_fields_for_customer()`), qui ne
 * lui passe QUE les champs additionnels. La présence de la clé `phone` distingue
 * les deux appels — sans ce test, enregistrer une adresse depuis le compte
 * échouerait sur un téléphone qui n'a jamais été soumis.
 */
add_action( 'woocommerce_blocks_validate_location_address_fields', 'pf_checkout_phone_validate', 10, 3 );
function pf_checkout_phone_validate( $errors, $fields, $group ) {
	if ( ! array_key_exists( 'phone', (array) $fields ) ) {
		return;
	}
	if ( ! WC()->cart || WC()->cart->is_empty() ) {
		return;
	}

	$phone = trim( (string) $fields['phone'] );

	if ( 'billing' === $group && '' === $phone ) {
		$errors->add( 'pf_phone_requis', pf_checkout_phone_missing_message() );
	}

	if ( 'shipping' === $group && pf_relay_shipping_selected()
		&& ( '' === $phone || ! pf_phone_accepts_sms( $phone, (string) ( $fields['country'] ?? '' ) ) ) ) {
		$errors->add(
			'pf_relay_mobile_requis',
			__( 'Pour un retrait en point relais, indiquez un numéro de mobile (06 ou 07) : le point relais vous prévient par SMS de l’arrivée de votre colis. Sans mobile, choisissez la livraison à domicile.', 'kadence-child' )
		);
	}
}
