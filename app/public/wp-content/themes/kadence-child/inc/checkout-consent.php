<?php
/**
 * Consentements du tunnel de commande.
 *
 * Deux cases, toutes deux obligatoires :
 *
 * 1. Acceptation des CGV — toujours affichée. Le bloc « Conditions générales »
 *    est déjà dans la page Commander, mais il reste muet tant qu'aucune page de
 *    CGV n'est rattachée et tant que son attribut `checkbox` vaut false (son
 *    défaut). Les deux sont forcés ici plutôt qu'en base / dans l'éditeur : le
 *    contenu des pages n'est pas versionné, ces filtres le sont.
 *
 * 2. Renonciation au droit de rétractation — affichée UNIQUEMENT si le panier
 *    contient un article téléchargeable, et bloquante dans ce cas. Les CGV
 *    l'annoncent (« Ce consentement vous est demandé explicitement au moment de
 *    la commande ») et l'article L. 221-28, 13° du code de la consommation en
 *    fait la condition de la livraison immédiate — or WooCommerce accorde ici
 *    l'accès au fichier dès le paiement (woocommerce_downloads_grant_access_after_payment).
 *
 * Le tunnel étant intégralement en blocs (Store API), aucun hook PHP classique
 * de checkout ne s'y déclenche. Tout passe donc par l'API « Additional Checkout
 * Fields » de WooCommerce, qui couvre ce besoin sans une ligne de JavaScript.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Identifiant du champ. Le préfixe avant le / est un espace de noms imposé par
 * l'API ; la valeur est persistée en post meta `_wc_other/<id>` sur la commande.
 */
const PF_CONSENT_NUMERIQUE_FIELD = 'passiflore/renonciation-retractation';

/**
 * Phrase soumise au client, accordée au singulier/pluriel selon le nombre
 * d'articles téléchargeables concernés. Source unique : sert de libellé de case
 * ET de copie archivée sur la commande (méta `_pf_renonciation_texte`), pour
 * qu'on puisse prouver plus tard à quoi le client a consenti même si la
 * formulation a changé depuis — d'où le compte passé en paramètre plutôt que
 * relu en interne (au moment de l'archivage, la source de vérité est la
 * commande, pas le panier, cf. plus bas).
 */
function pf_consent_numerique_texte( int $count ): string {
	return $count > 1
		? 'Pour avoir accès immédiatement à mes livres numériques, j’accepte de perdre mon droit de rétractation sur ces articles (article L. 221-28, 13° du code de la consommation).'
		: 'Pour avoir accès immédiatement à mon livre numérique, j’accepte de perdre mon droit de rétractation sur cet article (article L. 221-28, 13° du code de la consommation).';
}

/* ═══════════════════════════════════════════════════════════════
   1. Le panier contient-il un fichier ?
   ═══════════════════════════════════════════════════════════════ */

/**
 * Quantité cumulée d'articles téléchargeables dans le panier — pilote l'accord
 * singulier/pluriel de `pf_consent_numerique_texte()`.
 *
 * On teste `is_downloadable()` plutôt que le terme `numerique` de
 * `pa_format_particulier` : c'est la propriété qui déclenche réellement
 * l'article L. 221-28, 13° (contenu numérique sans support matériel), elle est
 * gratuite (l'objet produit est déjà chargé dans l'item de panier) et elle
 * couvrira un futur format téléchargeable sans retoucher à ce fichier. Les deux
 * ensembles coïncident aujourd'hui : les 64 éditions numériques sont les seuls
 * produits téléchargeables du site.
 */
function pf_cart_numerique_count(): int {
	if ( ! function_exists( 'WC' ) || ! WC()->cart ) {
		return 0;
	}
	$count = 0;
	foreach ( WC()->cart->get_cart() as $item ) {
		if ( ! empty( $item['data'] ) && $item['data']->is_downloadable() ) {
			$count += (int) $item['quantity'];
		}
	}
	return $count;
}

/** Vrai si au moins un article du panier est téléchargeable. */
function pf_cart_has_numerique(): bool {
	return pf_cart_numerique_count() > 0;
}

/* ═══════════════════════════════════════════════════════════════
   2. Rendre ce booléen lisible côté client
   ═══════════════════════════════════════════════════════════════ */

/**
 * Expose le prédicat dans la réponse Store API du panier, sous
 * `extensions.passiflore.has_numerique`.
 *
 * C'est ce qui permet à la case d'être conditionnelle SANS JavaScript maison :
 * les règles du champ (plus bas) sont évaluées contre un « document object » qui
 * recopie `extensions` tel quel des deux côtés — côté serveur dans
 * DocumentObject::get_cart_data(), côté client depuis le store `wc/store/cart`.
 *
 * Alternative écartée : lister les ID des produits numériques dans les règles
 * (le document object expose `cart.items`). Il faudrait alors un cache à
 * invalider à chaque nouveau livre numérique ; ici le booléen est recalculé à
 * chaque requête, donc toujours juste.
 *
 * ⚠️ Clés en snake_case : c'est la convention du document object des deux côtés.
 *
 * ⚠️ Enregistré sur `woocommerce_init`, PAS sur `woocommerce_blocks_loaded` —
 * celui-ci se déclenche sur `plugins_loaded`, donc AVANT le chargement du thème :
 * le callback ne serait jamais appelé et l'extension resterait absente de la
 * réponse Store API (vérifié). Le champ, lui, s'en accommode : son API se
 * reporte d'elle-même sur `woocommerce_blocks_loaded` si l'action a déjà eu
 * lieu — ce qui masque le problème et le rend d'autant plus déroutant.
 */
add_action( 'woocommerce_init', function () {
	if ( ! function_exists( 'woocommerce_store_api_register_endpoint_data' ) ) {
		return;
	}

	woocommerce_store_api_register_endpoint_data( [
		'endpoint'        => Automattic\WooCommerce\StoreApi\Schemas\V1\CartSchema::IDENTIFIER,
		'namespace'       => 'passiflore',
		'data_callback'   => static function () {
			return [ 'has_numerique' => pf_cart_has_numerique() ];
		},
		'schema_callback' => static function () {
			return [
				'has_numerique' => [
					'description' => __( 'Le panier contient au moins un article téléchargeable.', 'kadence-child' ),
					'type'        => 'boolean',
					'readonly'    => true,
				],
			];
		},
	] );
} );

/* ═══════════════════════════════════════════════════════════════
   3. La case, conditionnelle et bloquante
   ═══════════════════════════════════════════════════════════════ */

/**
 * Règle JSON-Schema « le panier contient (ou non) un article téléchargeable ».
 *
 * ⚠️ Les `required` internes (au sens JSON-Schema : « cette propriété doit
 * exister ») sont indispensables. Sans eux, une extension absente satisferait la
 * règle PAR VACUITÉ — `properties` ne contraint que les clés présentes — et la
 * case deviendrait obligatoire pour tout le monde, y compris sur un panier
 * papier.
 *
 * Avec eux, si l'extension venait à manquer les deux règles tombent à false :
 * case visible et non bloquante, et c'est le garde-fou serveur (plus bas) qui
 * refuse la commande. Jamais de blocage d'une commande papier.
 */
function pf_consent_numerique_rule( bool $expected ): array {
	return [
		'cart' => [
			'type'       => 'object',
			'required'   => [ 'extensions' ],
			'properties' => [
				'extensions' => [
					'type'       => 'object',
					'required'   => [ 'passiflore' ],
					'properties' => [
						'passiflore' => [
							'type'       => 'object',
							'required'   => [ 'has_numerique' ],
							'properties' => [
								'has_numerique' => [ 'const' => $expected ],
							],
						],
					],
				],
			],
		],
	];
}

/**
 * ⚠️ Sur `wp_loaded`, pas `woocommerce_init` : `WC()->cart` existe déjà à ce
 * stade (instancié dans `WooCommerce::init()`) mais son contenu n'est chargé
 * depuis la session qu'au hook `wp_loaded` (`WC_Cart_Session::get_cart_from_session()`,
 * priorité par défaut 10) — à `woocommerce_init`, `pf_cart_numerique_count()`
 * retombe toujours à 0 (constaté : accord au singulier même panier à 2 articles).
 * Priorité 20 pour s'exécuter après.
 */
add_action( 'wp_loaded', function () {
	if ( ! function_exists( 'woocommerce_register_additional_checkout_field' ) ) {
		return;
	}

	$texte = pf_consent_numerique_texte( pf_cart_numerique_count() );

	woocommerce_register_additional_checkout_field( [
		'id'            => PF_CONSENT_NUMERIQUE_FIELD,
		'label'         => $texte,
		// Sans optionalLabel, « (optional) » serait suffixé au libellé dans le
		// cas dégradé où la case s'affiche sans être requise.
		'optionalLabel' => $texte,
		'location'      => 'order',
		'type'          => 'checkbox',
		'required'      => pf_consent_numerique_rule( true ),
		'hidden'        => pf_consent_numerique_rule( false ),
		'error_message' => __( 'Vous devez accepter cette renonciation pour commander un livre numérique.', 'kadence-child' ),
		// show_in_order_confirmation reste à true (défaut) : c'est la
		// confirmation sur support durable exigée par l'article L. 221-9.
	] );
}, 20 );

/* ═══════════════════════════════════════════════════════════════
   4. Garde-fou serveur
   ═══════════════════════════════════════════════════════════════ */

/**
 * Mémorise la méthode de la requête REST en cours.
 *
 * Le tunnel en blocs envoie des mises à jour PARTIELLES (PUT/PATCH) pendant que
 * le client saisit son adresse — et même une au chargement de la page. Le hook
 * de validation ci-dessous étant appelé pour toutes, sans distinction, le
 * garde-fou affichait son erreur AVANT que le client ait pu cocher quoi que ce
 * soit (constaté : message rouge dès l'arrivée sur /commander, sans la moindre
 * interaction).
 *
 * `rest_pre_dispatch` reçoit la vraie sous-requête — donc la bonne méthode même
 * quand plusieurs sont groupées dans un appel `/batch`. Même idiome de drapeau
 * global que `pf_in_mini_cart` dans inc/numerique-offer.php.
 */
add_filter( 'rest_pre_dispatch', function ( $result, $server, $request ) {
	$GLOBALS['pf_rest_method'] = $request->get_method();
	return $result;
}, 10, 3 );

/**
 * Refuse la commande si le panier contient un fichier et que la case n'est pas
 * cochée — en PHP pur, sans dépendre des règles ni du parseur de schéma côté
 * client.
 *
 * Filet de sécurité : dans le cas nominal, WooCommerce bloque déjà tout seul
 * (la liste « contextuelle » des champs résout `required` depuis les règles, et
 * une case décochée est postée à false → erreur inline sous la case). Ce
 * garde-fou couvre le cas où ces règles ne s'évalueraient pas — par exemple si
 * l'extension de panier n'était plus enregistrée : sans lui, la commande
 * passerait SANS consentement et en silence, ce qui est précisément l'échec
 * qu'on ne peut pas se permettre ici.
 *
 * ⚠️ Uniquement sur le POST final : cf. le drapeau ci-dessus.
 */
add_action( 'woocommerce_blocks_validate_location_order_fields', function ( $errors, $fields ) {
	if ( 'POST' !== ( $GLOBALS['pf_rest_method'] ?? '' ) ) {
		return;
	}
	if ( ! pf_cart_has_numerique() ) {
		return;
	}
	if ( empty( $fields[ PF_CONSENT_NUMERIQUE_FIELD ] ) ) {
		$errors->add(
			'pf_consent_numerique_requis',
			__( 'Votre commande contient un livre numérique : vous devez accepter la renonciation au droit de rétractation pour la valider.', 'kadence-child' )
		);
	}
}, 10, 2 );

/* ═══════════════════════════════════════════════════════════════
   5. Traçabilité sur la commande
   ═══════════════════════════════════════════════════════════════ */

/**
 * Le oui/non est persisté d'office par WooCommerce en
 * `_wc_other/passiflore/renonciation-retractation`. On y ajoute la date et la
 * copie du texte consenti : la date de commande ne dit rien de la formulation en
 * vigueur ce jour-là, et c'est justement ce qu'il faut pouvoir produire.
 *
 * Accord singulier/pluriel recalculé depuis les lignes de LA COMMANDE (pas le
 * panier, potentiellement déjà modifié entre-temps) : c'est elle qui fait foi de
 * ce à quoi le client a réellement consenti.
 *
 * Pas de save() ici : CheckoutTrait enregistre la commande juste après ce hook.
 */
add_action( 'woocommerce_store_api_checkout_update_order_from_request', function ( $order, $request ) {
	$fields = (array) ( $request['additional_fields'] ?? [] );

	if ( empty( $fields[ PF_CONSENT_NUMERIQUE_FIELD ] ) ) {
		return;
	}

	$count = 0;
	foreach ( $order->get_items() as $item ) {
		$product = $item->get_product();
		if ( $product && $product->is_downloadable() ) {
			$count += (int) $item->get_quantity();
		}
	}

	$order->update_meta_data( '_pf_renonciation_date', current_time( 'mysql', true ) );
	$order->update_meta_data( '_pf_renonciation_texte', pf_consent_numerique_texte( $count ) );
}, 10, 2 );

/**
 * N'affiche la ligne de consentement (page de confirmation + e-mails) que sur
 * les commandes qui en portent effectivement un.
 *
 * Sans ce filtre, une commande PAPIER affiche la phrase juridique complète
 * suivie de « : Non » — constaté en test sur une commande sans livre numérique.
 * WooCommerce transtype systématiquement une case à cocher en booléen
 * (CheckoutFields::get_field_from_object(), `'1' === $value`), si bien qu'une
 * méta absente vaut `false` et passe le filtre « valeur vide » du rendu : ni
 * supprimer la méta ni s'abstenir de l'écrire n'y changerait quoi que ce soit.
 * Le seul point d'accroche est ce filtre-ci, qui reçoit la commande.
 */
add_filter( 'woocommerce_filter_fields_for_order_confirmation', function ( $show, $field, $fields, $context ) {
	if ( PF_CONSENT_NUMERIQUE_FIELD !== ( $field['id'] ?? '' ) ) {
		return $show;
	}

	$order = $context['order'] ?? null;

	return $order instanceof WC_Order
		&& '1' === (string) $order->get_meta( '_wc_other/' . PF_CONSENT_NUMERIQUE_FIELD );
}, 10, 4 );

/**
 * Rappel du consentement sur l'écran de commande en admin.
 *
 * Le oui/non natif atterrit dans la colonne « Livraison » de la boîte « Données
 * de la commande » (CheckoutFieldsAdmin le greffe sur woocommerce_admin_shipping_fields),
 * où il est à la fois mal placé et sans contexte. Ce bloc-ci est le rendu utile.
 */
add_action( 'woocommerce_admin_order_data_after_order_details', function ( $order ) {
	$date = $order->get_meta( '_pf_renonciation_date' );
	if ( ! $date ) {
		return;
	}

	$texte = $order->get_meta( '_pf_renonciation_texte' );

	// wp_date() et non get_date_from_gmt() : celui-ci passe par date(), qui ne
	// traduit pas les noms de mois (« 29 July 2026 »). Le « à » est concaténé
	// plutôt qu'échappé dans le format — un échappement ne porte que sur le
	// premier octet d'un caractère multi-octets.
	$ts    = strtotime( $date . ' UTC' );
	$local = $ts ? wp_date( 'j F Y', $ts ) . ' à ' . wp_date( 'H\hi', $ts ) : $date;

	echo '<div class="pf-order-consent" style="clear:both;padding-top:1em">';
	echo '<h4>' . esc_html__( 'Renonciation au droit de rétractation', 'kadence-child' ) . '</h4>';
	echo '<p style="margin:0"><strong>' . esc_html__( 'Consentement donné le', 'kadence-child' ) . '</strong> ' . esc_html( $local ) . '</p>';
	if ( $texte ) {
		echo '<p style="margin:.5em 0 0;font-style:italic">' . esc_html( $texte ) . '</p>';
	}
	echo '</div>';
} );

/* ═══════════════════════════════════════════════════════════════
   6. Case d'acceptation des CGV
   ═══════════════════════════════════════════════════════════════ */

/**
 * Rattache la page CGV au réglage WooCommerce, qui est vide sur ce site.
 *
 * Résolution par slug (et non par ID en dur) pour rester portable d'un
 * environnement à l'autre — même approche que inc/newsletter.php pour la
 * politique de confidentialité. Un réglage explicite en admin reste prioritaire.
 */
add_filter( 'woocommerce_terms_and_conditions_page_id', function ( $id ) {
	if ( $id ) {
		return $id;
	}

	static $cgv_id = null;
	if ( null === $cgv_id ) {
		$page   = get_page_by_path( 'conditions-generales-de-vente' );
		$cgv_id = $page ? (int) $page->ID : 0;
	}

	return $cgv_id ?: $id;
} );

/**
 * Phrase de la case CGV. Même logique que `pf_consent_numerique_texte()` :
 * versionnée en code plutôt que dans l'attribut `text` du bloc en base (contenu
 * de page non versionné, cf. tête de fichier).
 *
 * Lien Politique de confidentialité résolu par slug, même schéma que le bloc
 * newsletter (`inc/newsletter.php`) — la page y est déjà liée avec ce markup
 * exact (`target="_blank" rel="noopener"` + mention d'accessibilité), reproduit
 * ici à l'identique.
 */
function pf_consent_cgv_texte(): string {
	$cgv_id  = wc_terms_and_conditions_page_id();
	$cgv_url = $cgv_id ? get_permalink( $cgv_id ) : '';

	$lien_cgv = $cgv_url
		? '<a href="' . esc_url( $cgv_url ) . '" target="_blank" rel="noopener">Conditions Générales de Vente' . pf_new_window_note() . '</a>'
		: 'Conditions Générales de Vente';

	$privacy     = get_page_by_path( 'politique-de-confidentialite' );
	$privacy_url = $privacy ? get_permalink( $privacy ) : '';

	$lien_privacy = $privacy_url
		? '<a href="' . esc_url( $privacy_url ) . '" target="_blank" rel="noopener">Politique de confidentialité' . pf_new_window_note() . '</a>'
		: 'Politique de confidentialité';

	return 'J’accepte les ' . $lien_cgv . ' et affirme avoir lu la ' . $lien_privacy . ' pour procéder à l’achat.';
}

/**
 * Force la case à cocher du bloc « Conditions générales » et son libellé, et
 * supprime le titre de section « Additional order information » du bloc qui
 * porte la case numérique.
 *
 * `checkbox` vaut false par défaut (block.json du cœur) : sans ça le bloc
 * n'affiche qu'un texte, sans consentement à donner. `text` vaut '' par défaut,
 * auquel cas le bloc retombe sur sa phrase générique (Conditions + Politique de
 * confidentialité en anglais/traduction cœur). Les deux sont forcés ici plutôt
 * que réglés dans l'éditeur, le contenu des pages n'étant pas versionné.
 *
 * `title` (checkout-additional-information-block) vaut « Additional order
 * information » par défaut (traduit) ; le composant `FormStep` du cœur ne rend
 * le `<h2>` que si `title` est non vide (`!!s && <S title=…>`) — un `title: ''`
 * suffit donc à le faire disparaître sans toucher au reste du bloc.
 */
add_filter( 'render_block_data', function ( $parsed ) {
	if ( 'woocommerce/checkout-terms-block' === ( $parsed['blockName'] ?? '' ) ) {
		$parsed['attrs']['checkbox'] = true;
		$parsed['attrs']['text']     = pf_consent_cgv_texte();
	}
	if ( 'woocommerce/checkout-additional-information-block' === ( $parsed['blockName'] ?? '' ) ) {
		$parsed['attrs']['title'] = '';
	}
	return $parsed;
} );
