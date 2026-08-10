<?php
/**
 * Offre « version numérique en promo » à l'achat d'un livre papier.
 *
 * Quand un client achète l'édition papier d'un livre (format « classique » ou
 * « grands caractères »), on lui propose la version numérique du même ouvrage à
 * tarif réduit : pourcentage de réduction, prix fixe, ou gratuite. Réglage
 * global (deux valeurs, une par format source), paramétrable dans
 * Produits → « Offre version numérique ».
 *
 * Modèle de données : chaque édition est un product WooCommerce ; les éditions
 * d'une œuvre partagent un terme de la taxonomie `format_groupe` ; le format est
 * porté par l'attribut produit `pa_format_particulier` (classique = aucun terme,
 * les autres = slugs `grands-caracteres`, `numerique`, `poche`, `audio`).
 * → « la version numérique de ce livre » = le membre du même `format_groupe`
 *   dont `pa_format_particulier` vaut `numerique`.
 */

defined( 'ABSPATH' ) || exit;

const PF_NUMERIQUE_OPTION = 'pf_numerique_offer';

/* ═══════════════════════════════════════════════════════════════
   Réglages (option globale)
   ═══════════════════════════════════════════════════════════════ */

function pf_numerique_default_settings(): array {
	return [
		'classique'         => [ 'mode' => 'disabled', 'value' => '' ],
		'grands-caracteres' => [ 'mode' => 'disabled', 'value' => '' ],
	];
}

/**
 * Lit et normalise l'option (garantit les deux clés + un mode valide).
 */
function pf_numerique_get_settings(): array {
	$saved = get_option( PF_NUMERIQUE_OPTION, [] );
	$modes = [ 'disabled', 'percent', 'fixed', 'free' ];
	$out   = pf_numerique_default_settings();

	foreach ( array_keys( $out ) as $src ) {
		if ( isset( $saved[ $src ]['mode'] ) && in_array( $saved[ $src ]['mode'], $modes, true ) ) {
			$out[ $src ]['mode'] = $saved[ $src ]['mode'];
		}
		if ( isset( $saved[ $src ]['value'] ) ) {
			$out[ $src ]['value'] = (string) $saved[ $src ]['value'];
		}
	}
	return $out;
}

/**
 * Normalise une valeur numérique saisie en admin (accepte la virgule décimale
 * française). Même approche que passiflore_shipping_option_float() dans shipping.php.
 */
function pf_numerique_to_float( $val ): float {
	return (float) str_replace( ',', '.', (string) $val );
}

/* ═══════════════════════════════════════════════════════════════
   Logique métier (helpers partagés fiche / panier / calcul de prix)
   ═══════════════════════════════════════════════════════════════ */

/**
 * Format source d'un produit vis-à-vis de l'offre :
 * 'classique' (aucun terme pa_format_particulier), 'grands-caracteres', ou
 * null (numérique / audio / poche → pas un format source).
 */
function pf_numerique_source_format( int $id ): ?string {
	$slugs = wp_get_object_terms( $id, 'pa_format_particulier', [ 'fields' => 'slugs' ] );
	if ( is_wp_error( $slugs ) ) {
		return null;
	}
	if ( empty( $slugs ) ) {
		return 'classique';
	}
	if ( in_array( 'grands-caracteres', $slugs, true ) ) {
		return 'grands-caracteres';
	}
	return null;
}

/**
 * ID du produit numérique compagnon (même format_groupe, pa_format_particulier =
 * numerique), ou null si l'ouvrage n'a pas d'édition numérique.
 */
function pf_numerique_companion_id( int $id ): ?int {
	$fg = wp_get_object_terms( $id, 'format_groupe', [ 'fields' => 'ids' ] );
	if ( is_wp_error( $fg ) || empty( $fg ) ) {
		return null;
	}
	$sibling = get_posts( [
		'post_type'      => 'product',
		'post_status'    => 'publish',
		'posts_per_page' => 1,
		'fields'         => 'ids',
		'no_found_rows'  => true,
		'tax_query'      => [
			[ 'taxonomy' => 'format_groupe',         'terms' => $fg ],
			[ 'taxonomy' => 'pa_format_particulier', 'field' => 'slug', 'terms' => 'numerique' ],
		],
	] );
	return ! empty( $sibling ) ? (int) $sibling[0] : null;
}

/**
 * Calcule le tarif de l'offre à partir du prix régulier du numérique.
 */
function pf_numerique_price( float $regular, string $mode, $value ): float {
	$decimals = function_exists( 'wc_get_price_decimals' ) ? wc_get_price_decimals() : 2;

	switch ( $mode ) {
		case 'free':
			return 0.0;
		case 'fixed':
			return round( max( 0.0, pf_numerique_to_float( $value ) ), $decimals );
		case 'percent':
			$pct = min( 100.0, max( 0.0, pf_numerique_to_float( $value ) ) );
			return round( $regular * ( 1 - $pct / 100 ), $decimals );
	}
	return $regular;
}

/**
 * Point d'entrée unique de la logique métier. Renvoie l'offre applicable pour un
 * produit papier donné, ou null (pas un format source, mode désactivé, ou pas de
 * numérique compagnon achetable).
 *
 * @return array{numerique_id:int,source:string,mode:string,value:string,regular:float,price:float}|null
 */
function pf_numerique_offer_for( int $physical_id ): ?array {
	$source = pf_numerique_source_format( $physical_id );
	if ( null === $source ) {
		return null;
	}

	$settings = pf_numerique_get_settings();
	$mode     = $settings[ $source ]['mode'] ?? 'disabled';
	if ( 'disabled' === $mode ) {
		return null;
	}

	$num_id = pf_numerique_companion_id( $physical_id );
	if ( ! $num_id ) {
		return null;
	}

	$num = wc_get_product( $num_id );
	if ( ! $num || ! $num->is_purchasable() ) {
		return null;
	}

	$regular = (float) $num->get_regular_price();
	$value   = (string) ( $settings[ $source ]['value'] ?? '' );
	$price   = pf_numerique_price( $regular, $mode, $value );

	return [
		'numerique_id' => $num_id,
		'source'       => $source,
		'mode'         => $mode,
		'value'        => $value,
		'regular'      => $regular,
		'price'        => $price,
	];
}

/**
 * Un produit (par ID) est-il présent dans le panier, sous quelque forme que ce soit ?
 */
function pf_numerique_product_in_cart( int $product_id ): bool {
	if ( ! WC()->cart ) {
		return false;
	}
	foreach ( WC()->cart->get_cart() as $item ) {
		if ( (int) $item['product_id'] === $product_id ) {
			return true;
		}
	}
	return false;
}

/**
 * Rendu HTML du tarif de l'offre (prix promo + prix barré, ou « gratuite »).
 */
function pf_numerique_price_html( array $offer ): string {
	if ( 'free' === $offer['mode'] || $offer['price'] <= 0 ) {
		return '<ins class="pf-numerique-price">' . esc_html__( 'gratuite', 'kadence-child' ) . '</ins>';
	}
	$new = '<ins class="pf-numerique-price">' . wc_price( $offer['price'] ) . '</ins>';
	if ( $offer['price'] < $offer['regular'] ) {
		$new .= ' <del class="pf-numerique-price-old">' . wc_price( $offer['regular'] ) . '</del>';
	}
	return $new;
}

/* ═══════════════════════════════════════════════════════════════
   Fiche livre — case à cocher
   ═══════════════════════════════════════════════════════════════ */

/**
 * Phrase descriptive de l'offre, pour l'infobulle de la fiche livre. Le format
 * cité est celui du livre consulté (source de l'offre : « classique » ou
 * « grands caractères » — jamais « numérique »).
 * Ex. « Pour l'achat d'un format classique, la version numérique est à -70 %. »
 */
function pf_numerique_offer_sentence( array $offer ): string {
	$format = ( 'grands-caracteres' === $offer['source'] ) ? 'grands caractères' : 'classique';

	if ( 'free' === $offer['mode'] || $offer['price'] <= 0 ) {
		$term = 'gratuite';
	} elseif ( 'percent' === $offer['mode'] ) {
		$pct     = min( 100.0, max( 0.0, pf_numerique_to_float( $offer['value'] ) ) );
		$pct_str = rtrim( rtrim( number_format( $pct, 2, ',', ' ' ), '0' ), ',' );
		$term    = 'à -' . $pct_str . ' %';
	} else {
		$term = 'à ' . html_entity_decode( wp_strip_all_tags( wc_price( $offer['price'] ) ), ENT_QUOTES, 'UTF-8' );
	}

	return sprintf(
		"Pour l’achat d’un format %s, le format numérique est %s.\n➜ Vous pourrez le télécharger en ePub (sans DRM) une fois la commande effectuée. Si vous passez commande en étant connecté(e), vous pourrez directement le lire depuis votre compte.",
		$format,
		$term
	);
}

/**
 * Terme générique de l'offre pour un format source, INDÉPENDAMMENT de tout
 * produit : « gratuitement », « à -50 % » ou « à 4,90 € ». Null si ce format
 * source est désactivé.
 *
 * Distinct de pf_numerique_offer_sentence(), qui décrit l'offre pour un livre
 * précis (et a donc besoin du prix régulier de SON numérique compagnon) : ici
 * on ne lit que le réglage, ce qui permet d'en parler là où aucun produit n'est
 * en contexte — l'accroche de l'accueil.
 * ⚠️ En mode `fixed`, la valeur réglée EST le prix ; en mode `percent`, c'est
 * une remise. D'où deux formulations différentes.
 */
function pf_numerique_offer_term( string $source ): ?string {
	$cfg = pf_numerique_get_settings()[ $source ] ?? null;
	if ( ! $cfg || 'disabled' === $cfg['mode'] ) {
		return null;
	}
	if ( 'free' === $cfg['mode'] ) {
		return 'gratuitement';
	}
	if ( 'percent' === $cfg['mode'] ) {
		$pct = min( 100.0, max( 0.0, pf_numerique_to_float( $cfg['value'] ) ) );
		// -100 % = gratuit. On le dit comme tel, comme le fait déjà
		// pf_numerique_offer_sentence() sur la fiche livre (son test price <= 0).
		if ( $pct >= 100.0 ) {
			return 'gratuitement';
		}
		return 'à -' . rtrim( rtrim( number_format( $pct, 2, ',', ' ' ), '0' ), ',' ) . ' %';
	}
	add_filter( 'woocommerce_price_trim_zeros', '__return_true' );
	$prix = wc_price( max( 0.0, pf_numerique_to_float( $cfg['value'] ) ) );
	remove_filter( 'woocommerce_price_trim_zeros', '__return_true' );
	return 'à ' . html_entity_decode( wp_strip_all_tags( $prix ), ENT_QUOTES, 'UTF-8' );
}

/**
 * Phrase d'accroche de l'offre numérique pour l'accueil, ou '' si aucune offre
 * n'est active (l'offre est dormante par défaut → l'accroche disparaît d'elle-même).
 *
 * Les deux formats source se règlent séparément : on ne parle de « livre
 * physique » que s'ils sont tous deux actifs AU MÊME tarif, sinon on nomme le
 * ou les formats concernés — annoncer une remise sur « un livre physique »
 * quand seule l'édition classique en bénéficie serait une promesse fausse.
 */
function pf_numerique_offer_teaser(): string {
	$labels = [ 'classique' => 'classique', 'grands-caracteres' => 'grands caractères' ];
	$terms  = [];

	foreach ( $labels as $src => $label ) {
		$term = pf_numerique_offer_term( $src );
		if ( null !== $term ) {
			$terms[ $src ] = $term;
		}
	}

	if ( ! $terms ) {
		return '';
	}

	if ( count( $terms ) === count( $labels ) && 1 === count( array_unique( $terms ) ) ) {
		return sprintf(
			'En ce moment, pour l’achat d’un livre physique, sa version numérique est proposée %s.',
			reset( $terms )
		);
	}

	$parts = [];
	foreach ( $terms as $src => $term ) {
		$parts[] = sprintf(
			'pour l’achat d’un format %s, sa version numérique est proposée %s',
			$labels[ $src ],
			$term
		);
	}
	return 'En ce moment, ' . implode( ' ; ', $parts ) . '.';
}

/**
 * Rend la case « ajouter aussi la version numérique » pour un produit papier,
 * ou une chaîne vide si aucune offre ne s'applique. Appelée depuis
 * woocommerce/content-single-product.php, juste après le prix.
 */
function pf_numerique_render_offer_checkbox( int $physical_id ): string {
	$offer = pf_numerique_offer_for( $physical_id );
	if ( ! $offer ) {
		return '';
	}

	$is_free    = ( 'free' === $offer['mode'] || $offer['price'] <= 0 );
	$price_html = pf_numerique_price_html( $offer );
	$tip_text   = pf_numerique_offer_sentence( $offer );

	ob_start();
	?>
	<label class="pf-numerique-offer" data-pf-numerique-id="<?php echo esc_attr( (int) $offer['numerique_id'] ); ?>">
		<input type="checkbox" class="pf-numerique-offer__check">
		<span class="pf-numerique-offer__text">Inclure le format numérique <?php if ( $is_free ) : ?>— <?php echo $price_html; // phpcs:ignore WordPress.Security.EscapeOutput ?><?php else : ?>pour <?php echo $price_html; // phpcs:ignore WordPress.Security.EscapeOutput ?><?php endif; ?><span class="pf-tooltip"><span class="pf-tooltip__trigger" tabindex="0" role="button" aria-label="Détails de l'offre numérique" aria-describedby="pf-numerique-tip-bubble"><svg viewBox="0 0 24 24" width="15" height="15" fill="currentColor" aria-hidden="true"><path d="M11 7h2v2h-2V7zm0 4h2v6h-2v-6zm1-9C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm0 18c-4.41 0-8-3.59-8-8s3.59-8 8-8 8 3.59 8 8-3.59 8-8 8z"/></svg></span><span class="pf-tooltip__bubble" id="pf-numerique-tip-bubble" role="tooltip"><?php echo esc_html( $tip_text ); ?></span></span></span>
	</label>
	<?php
	$html = trim( (string) ob_get_clean() );

	// Markup de la liseuse, pour son vol vers le panier quand la case est cochée
	// (assets/js/add-to-cart-flight.js, qui n'y prend que le `.pf-book` et le pose
	// dans sa propre scène). Dans un <template> : contenu INERTE — rien n'est
	// rendu, le script inline du shortcode ne s'exécute pas, les images ne sont
	// pas téléchargées (le JS les réchauffe à la coche) et `relayoutAll()` du
	// contrôleur d'étagère ne le voit pas. Coût page : le seul markup.
	$html .= '<template class="pf-numerique-offer__book">'
		. do_shortcode( '[passiflore_etagere ids="' . (int) $offer['numerique_id'] . '" hero="true" nb_books_first_displayed="1"]' )
		. '</template>';

	return $html;
}

/**
 * Rend l'infobulle « téléchargement » juste après le prix d'un produit qui
 * est lui-même la version numérique (pas l'offre couplée à l'achat papier
 * ci-dessus). Chaîne vide si le produit n'est pas au format numérique.
 * Appelée depuis woocommerce/content-single-product.php.
 */
function pf_numerique_render_ebook_tip( int $id ): string {
	if ( ! has_term( 'numerique', 'pa_format_particulier', $id ) ) {
		return '';
	}
	$tip_id = 'pf-numerique-tip-bubble-ebook';
	ob_start();
	?>
<span class="pf-tooltip"><span class="pf-tooltip__trigger" tabindex="0" role="button" aria-label="Précision sur le téléchargement" aria-describedby="<?php echo esc_attr( $tip_id ); ?>"><svg viewBox="0 0 24 24" width="15" height="15" fill="currentColor" aria-hidden="true"><path d="M11 7h2v2h-2V7zm0 4h2v6h-2v-6zm1-9C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm0 18c-4.41 0-8-3.59-8-8s3.59-8 8-8 8 3.59 8 8-3.59 8-8 8z"/></svg></span><span class="pf-tooltip__bubble" id="<?php echo esc_attr( $tip_id ); ?>" role="tooltip">Vous pourrez le <strong>télécharger en ePub</strong> (sans DRM) une fois la commande effectuée. Si vous passez <strong>commande en étant connecté(e)</strong>, vous pourrez directement le <strong>lire depuis votre compte</strong>.</span></span>
	<?php
	return trim( (string) ob_get_clean() );
}

/* ═══════════════════════════════════════════════════════════════
   Panier — ajout du compagnon, prix, libellé
   ═══════════════════════════════════════════════════════════════ */

/**
 * Ajoute le numérique compagnon au panier quand la case de la fiche a été cochée.
 * Le JS cœur de WooCommerce transmet l'attribut data-pf_add_numerique du bouton
 * d'ajout dans la requête AJAX → on le lit ici via $_REQUEST.
 */
add_action( 'woocommerce_add_to_cart', 'pf_numerique_maybe_add_companion', 20, 6 );
function pf_numerique_maybe_add_companion( $cart_item_key, $product_id, $quantity, $variation_id, $variation, $cart_item_data ) {
	if ( empty( $_REQUEST['pf_add_numerique'] ) ) {
		return;
	}
	// Anti-récursion : l'ajout du compagnon ci-dessous re-déclenche ce hook.
	if ( ! empty( $cart_item_data['pf_numerique_companion'] ) ) {
		return;
	}

	$offer = pf_numerique_offer_for( (int) $product_id );
	if ( ! $offer ) {
		return;
	}
	if ( pf_numerique_product_in_cart( $offer['numerique_id'] ) ) {
		return;
	}

	WC()->cart->add_to_cart(
		$offer['numerique_id'],
		1,
		0,
		[],
		[ 'pf_numerique_companion' => (int) $product_id ]
	);
}

/**
 * Applique le tarif promo à la ligne numérique tant que le papier lié est au
 * panier ; sinon on ne touche à rien → prix plein (« repasse au prix plein »).
 * Idempotent : le prix promo est recalculé depuis le prix régulier du numérique,
 * jamais depuis un prix déjà modifié (le hook est appelé plusieurs fois / requête).
 */
add_action( 'woocommerce_before_calculate_totals', 'pf_numerique_apply_prices', 20, 1 );
function pf_numerique_apply_prices( $cart ) {
	if ( is_admin() && ! defined( 'DOING_AJAX' ) ) {
		return;
	}
	if ( ! $cart || ! is_a( $cart, 'WC_Cart' ) ) {
		return;
	}

	$present = [];
	foreach ( $cart->get_cart() as $item ) {
		$present[ (int) $item['product_id'] ] = true;
	}

	foreach ( $cart->get_cart() as $item ) {
		if ( empty( $item['pf_numerique_companion'] ) ) {
			continue;
		}
		$physical_id = (int) $item['pf_numerique_companion'];
		if ( empty( $present[ $physical_id ] ) ) {
			continue; // papier retiré → prix plein
		}
		$offer = pf_numerique_offer_for( $physical_id );
		if ( ! $offer ) {
			continue;
		}
		$item['data']->set_price( $offer['price'] );
	}
}

/**
 * Mention « Offre » sous la ligne numérique (panier + checkout, classique et
 * blocs Store API). Masquée si le papier lié n'est plus au panier.
 *
 * Volontairement PAS affichée dans le mini-panier Kadence (menu déroulant du
 * header) : à la place, la ligne de prix y montre le prix barré (cf.
 * pf_numerique_mini_cart_price). Un drapeau global posé entre
 * woocommerce_before/after_mini_cart cible ce seul contexte.
 */
add_filter( 'woocommerce_get_item_data', 'pf_numerique_cart_item_data', 10, 2 );
function pf_numerique_cart_item_data( $data, $cart_item ) {
	if ( ! empty( $GLOBALS['pf_in_mini_cart'] ) ) {
		return $data; // mini-panier : pas de ligne « Offre »
	}
	if ( empty( $cart_item['pf_numerique_companion'] ) ) {
		return $data;
	}
	if ( ! pf_numerique_product_in_cart( (int) $cart_item['pf_numerique_companion'] ) ) {
		return $data;
	}
	$data[] = [
		'key'       => __( 'Offre', 'kadence-child' ),
		'value'     => __( "Version numérique liée à l'achat du livre papier", 'kadence-child' ),
		// Cible précise pour cart.css (masquée dans le panier en blocs) sans
		// toucher .wc-block-components-product-details : ce conteneur rend
		// aussi les attributs de variation pour un produit variable, absents
		// ici (catalogue 100% produits simples) mais qu'un display:none global
		// masquerait aussi si ça changeait un jour.
		'className' => 'pf-numerique-offer-line',
	];
	return $data;
}

/**
 * Mini-panier Kadence uniquement : accole le prix régulier barré à droite du
 * prix promo sur la ligne numérique compagnon (au lieu de la mention « Offre »).
 * Le drapeau pf_in_mini_cart restreint l'effet au menu déroulant du header —
 * le panier/checkout en blocs (Store API) n'appellent pas ce filtre.
 */
add_action( 'woocommerce_before_mini_cart', function () { $GLOBALS['pf_in_mini_cart'] = true; } );
add_action( 'woocommerce_after_mini_cart',  function () { $GLOBALS['pf_in_mini_cart'] = false; } );

add_filter( 'woocommerce_cart_item_price', 'pf_numerique_mini_cart_price', 20, 2 );
function pf_numerique_mini_cart_price( $price_html, $cart_item ) {
	if ( empty( $GLOBALS['pf_in_mini_cart'] ) ) {
		return $price_html;
	}
	if ( empty( $cart_item['pf_numerique_companion'] ) ) {
		return $price_html;
	}
	if ( ! pf_numerique_product_in_cart( (int) $cart_item['pf_numerique_companion'] ) ) {
		return $price_html; // papier retiré → prix plein (core), pas de barré
	}
	$product = $cart_item['data'] ?? null;
	$offer   = pf_numerique_offer_for( (int) $cart_item['pf_numerique_companion'] );
	if ( ! $product || ! $offer ) {
		return $price_html;
	}

	// On reconstruit le prix depuis l'offre : selon le moment du rendu du
	// mini-panier, l'objet produit ne porte pas toujours encore le prix promo
	// posé par before_calculate_totals. wc_get_price_to_display respecte le
	// réglage d'affichage des taxes, comme le prix courant du core.
	$new_html = wc_price( wc_get_price_to_display( $product, [ 'price' => $offer['price'] ] ) );
	if ( $offer['price'] < $offer['regular'] ) {
		$old_html  = wc_price( wc_get_price_to_display( $product, [ 'price' => $offer['regular'] ] ) );
		$new_html .= ' <del class="pf-mini-old-amount">' . $old_html . '</del>';
	}
	return $new_html;
}

/* ═══════════════════════════════════════════════════════════════
   Numérique = vendu à l'unité (une seule copie par commande)
   ═══════════════════════════════════════════════════════════════ */

/**
 * Toute édition numérique est « vendue à l'unité » : acheter plusieurs fois le
 * même fichier n'a pas de sens. Conséquences : quantité verrouillée à 1 et
 * **sélecteur de quantité retiré** dans le panier/checkout en blocs (le Store API
 * expose alors quantity_limits.editable = false → le composant
 * .wc-block-components-quantity-selector n'est pas rendu). Indépendant de l'offre :
 * s'applique à tout produit `numerique`, remisé ou non.
 */
add_filter( 'woocommerce_is_sold_individually', 'pf_numerique_sold_individually', 10, 2 );
function pf_numerique_sold_individually( $sold_individually, $product ) {
	if ( $sold_individually ) {
		return true;
	}
	$id = $product ? $product->get_id() : 0;
	if ( $id && has_term( 'numerique', 'pa_format_particulier', $id ) ) {
		return true;
	}
	return $sold_individually;
}

/* ═══════════════════════════════════════════════════════════════
   Panier (blocs) — encart de rappel : endpoints AJAX
   ═══════════════════════════════════════════════════════════════ */

add_action( 'wp_ajax_pf_numerique_cart_offers', 'pf_numerique_ajax_cart_offers' );
add_action( 'wp_ajax_nopriv_pf_numerique_cart_offers', 'pf_numerique_ajax_cart_offers' );
function pf_numerique_ajax_cart_offers() {
	check_ajax_referer( 'pf_numerique_cart', 'nonce' );

	$offers = [];
	if ( WC()->cart ) {
		$seen = [];
		foreach ( WC()->cart->get_cart() as $item ) {
			$pid = (int) $item['product_id'];
			if ( isset( $seen[ $pid ] ) ) {
				continue;
			}
			$seen[ $pid ] = true;

			$offer = pf_numerique_offer_for( $pid );
			if ( ! $offer ) {
				continue;
			}
			if ( pf_numerique_product_in_cart( $offer['numerique_id'] ) ) {
				continue; // compagnon déjà au panier
			}

			$offers[] = [
				'physical_id' => $pid,
				'title'       => get_the_title( $pid ),
				// Titre du produit ajouté (« … (numérique) »), pour le toast de
				// confirmation : l'encart, lui, affiche « Version numérique de <titre
				// du papier> », tournure qui ne se recolle pas au gabarit de phrase
				// des toasts panier (accord au masculin, comme un titre de livre).
				'added_title' => get_the_title( $offer['numerique_id'] ),
				'price_html'  => pf_numerique_price_html( $offer ),
				'tip'         => pf_numerique_offer_sentence( $offer ),
			];
		}
	}
	wp_send_json_success( [ 'offers' => $offers ] );
}

/**
 * IDs des lignes du panier au format numérique — pour le tooltip « téléchargement »
 * injecté en JS juste après leur prix (le panier en blocs ne peut pas être
 * modifié via un hook PHP, cf. numerique-ebook-tip.js).
 */
add_action( 'wp_ajax_pf_numerique_ebook_ids', 'pf_numerique_ajax_ebook_ids' );
add_action( 'wp_ajax_nopriv_pf_numerique_ebook_ids', 'pf_numerique_ajax_ebook_ids' );
function pf_numerique_ajax_ebook_ids() {
	check_ajax_referer( 'pf_numerique_cart', 'nonce' );

	$ids = [];
	if ( WC()->cart ) {
		foreach ( WC()->cart->get_cart() as $item ) {
			$pid = (int) $item['product_id'];
			if ( has_term( 'numerique', 'pa_format_particulier', $pid ) ) {
				$ids[] = $pid;
			}
		}
	}
	wp_send_json_success( [ 'ids' => array_values( array_unique( $ids ) ) ] );
}

add_action( 'wp_ajax_pf_numerique_add_companion', 'pf_numerique_ajax_add_companion' );
add_action( 'wp_ajax_nopriv_pf_numerique_add_companion', 'pf_numerique_ajax_add_companion' );
function pf_numerique_ajax_add_companion() {
	check_ajax_referer( 'pf_numerique_cart', 'nonce' );

	$physical_id = (int) ( $_POST['physical_id'] ?? 0 );
	$offer       = pf_numerique_offer_for( $physical_id );

	if ( ! $offer || ! WC()->cart ) {
		wp_send_json_error( [ 'message' => 'no_offer' ] );
	}
	if ( ! pf_numerique_product_in_cart( $physical_id ) ) {
		wp_send_json_error( [ 'message' => 'no_physical' ] );
	}
	if ( pf_numerique_product_in_cart( $offer['numerique_id'] ) ) {
		wp_send_json_success( [ 'already' => true ] );
	}

	$key = WC()->cart->add_to_cart(
		$offer['numerique_id'],
		1,
		0,
		[],
		[ 'pf_numerique_companion' => $physical_id ]
	);

	if ( $key ) {
		// Pas de `wc_add_to_cart_message()` ici : `WC()->cart->add_to_cart()` n'émet
		// aucune notice, mais celle de WooCommerce porterait sa formulation et son
		// icône de notice, là où l'ajout doit ressembler à tous les autres ajouts au
		// panier. La confirmation est donc rendue par le contrôleur des toasts
		// panier, à qui l'encart passe le relais par sessionStorage avant de
		// recharger (cf. assets/js/numerique-cart-nudge.js et cart-toast.js).
		wp_send_json_success( [ 'added' => true ] );
	}
	wp_send_json_error( [ 'message' => 'add_failed' ] );
}

/* ═══════════════════════════════════════════════════════════════
   Assets front (fiche + panier)
   ═══════════════════════════════════════════════════════════════ */

add_action( 'wp_enqueue_scripts', 'pf_numerique_enqueue' );
function pf_numerique_enqueue() {
	$is_product = function_exists( 'is_product' ) && is_product();
	$is_cart    = function_exists( 'is_cart' ) && is_cart();
	if ( ! $is_product && ! $is_cart ) {
		return;
	}

	// Composant infobulle partagé (fiche + panier), dépendance des deux scripts.
	wp_enqueue_script(
		'pf-tooltip',
		get_stylesheet_directory_uri() . '/assets/js/pf-tooltip.js',
		[],
		filemtime( get_stylesheet_directory() . '/assets/js/pf-tooltip.js' ),
		true
	);

	if ( $is_product ) {
		wp_enqueue_script(
			'pf-numerique-offer',
			get_stylesheet_directory_uri() . '/assets/js/numerique-offer.js',
			[ 'pf-tooltip' ],
			filemtime( get_stylesheet_directory() . '/assets/js/numerique-offer.js' ),
			true
		);
	}
	if ( $is_cart ) {
		wp_enqueue_script(
			'pf-numerique-cart-nudge',
			get_stylesheet_directory_uri() . '/assets/js/numerique-cart-nudge.js',
			[ 'wp-data', 'pf-tooltip', 'pf-session-toast' ],
			filemtime( get_stylesheet_directory() . '/assets/js/numerique-cart-nudge.js' ),
			true
		);
		wp_localize_script( 'pf-numerique-cart-nudge', 'pfNumerique', [
			'ajaxUrl'  => admin_url( 'admin-ajax.php' ),
			'nonce'    => wp_create_nonce( 'pf_numerique_cart' ),
			'heading'  => __( 'Pour continuer la lecture n’importe où…', 'kadence-child' ),
			'addLabel' => __( 'Ajouter', 'kadence-child' ),
		] );

		wp_enqueue_script(
			'pf-numerique-ebook-tip',
			get_stylesheet_directory_uri() . '/assets/js/numerique-ebook-tip.js',
			[ 'wp-data', 'pf-tooltip', 'pf-numerique-cart-nudge' ],
			filemtime( get_stylesheet_directory() . '/assets/js/numerique-ebook-tip.js' ),
			true
		);
	}
}

/* ═══════════════════════════════════════════════════════════════
   Écran d'admin : Produits → Offre version numérique
   ═══════════════════════════════════════════════════════════════ */

add_action( 'admin_menu', function () {
	$hook = add_submenu_page(
		'woocommerce',
		'Offre version numérique',
		'Offre version numérique',
		'manage_woocommerce',
		'pf-numerique-offer',
		'pf_numerique_render_admin'
	);
	if ( $hook ) {
		add_action( 'load-' . $hook, 'pf_numerique_handle_post' );
	}
} );

/**
 * Positionne l'entrée dans le menu WooCommerce (« Boutique ») juste avant
 * « Codes promo » — donc entre « Clients » et « Codes promo », qui se suivent.
 * L'ordre d'un sous-menu WooCommerce dépend de l'ordre d'insertion (peu
 * déterministe entre extensions) : on réordonne donc en fin de admin_menu
 * plutôt que de se fier au paramètre $position de add_submenu_page.
 */
add_action( 'admin_menu', 'pf_numerique_reorder_submenu', 100 );
function pf_numerique_reorder_submenu() {
	global $submenu;
	if ( empty( $submenu['woocommerce'] ) ) {
		return;
	}
	$items = $submenu['woocommerce'];

	// Extraire notre entrée.
	$ours = null;
	foreach ( $items as $k => $it ) {
		if ( isset( $it[2] ) && $it[2] === 'pf-numerique-offer' ) {
			$ours = $it;
			unset( $items[ $k ] );
			break;
		}
	}
	if ( null === $ours ) {
		return;
	}
	$items = array_values( $items );

	// Cible : juste avant « Codes promo » ; sinon juste après « Clients » ; sinon fin.
	$at = count( $items );
	foreach ( $items as $i => $it ) {
		if ( isset( $it[2] ) && $it[2] === 'coupons-moved' ) {
			$at = $i;
			break;
		}
	}
	if ( count( $items ) === $at ) {
		foreach ( $items as $i => $it ) {
			if ( isset( $it[2] ) && strpos( (string) $it[2], 'path=/customers' ) !== false ) {
				$at = $i + 1;
				break;
			}
		}
	}

	array_splice( $items, $at, 0, [ $ours ] );
	$submenu['woocommerce'] = $items;
}

function pf_numerique_admin_url(): string {
	return admin_url( 'admin.php?page=pf-numerique-offer' );
}

/**
 * Correspondance nom de champ POST → clé de réglage. On évite un tiret dans le
 * nom de champ de premier niveau (mangling PHP) : on utilise « gc » côté formulaire.
 */
function pf_numerique_field_map(): array {
	return [ 'classique' => 'classique', 'gc' => 'grands-caracteres' ];
}

function pf_numerique_handle_post() {
	if ( ( $_SERVER['REQUEST_METHOD'] ?? '' ) !== 'POST' ) {
		return;
	}
	if ( ! isset( $_POST['pf_numerique_save'] ) ) {
		return;
	}
	if ( ! current_user_can( 'manage_woocommerce' ) ) {
		return;
	}
	check_admin_referer( 'pf_numerique_save', 'pf_numerique_nonce' );

	$modes  = [ 'disabled', 'percent', 'fixed', 'free' ];
	$posted = (array) ( $_POST['pfnum'] ?? [] );
	$out    = pf_numerique_default_settings();

	foreach ( pf_numerique_field_map() as $field => $key ) {
		$mode = sanitize_key( $posted[ $field ]['mode'] ?? 'disabled' );
		if ( ! in_array( $mode, $modes, true ) ) {
			$mode = 'disabled';
		}
		$value = trim( (string) wp_unslash( $posted[ $field ]['value'] ?? '' ) );
		$out[ $key ] = [ 'mode' => $mode, 'value' => $value ];
	}

	update_option( PF_NUMERIQUE_OPTION, $out, false );

	wp_safe_redirect( add_query_arg( [ 'msg' => 'saved' ], pf_numerique_admin_url() ) );
	exit;
}

function pf_numerique_render_admin() {
	if ( ! current_user_can( 'manage_woocommerce' ) ) {
		return;
	}
	$settings = pf_numerique_get_settings();

	echo '<div class="wrap">';
	echo '<h1>Offre version numérique</h1>';

	if ( ( $_GET['msg'] ?? '' ) === 'saved' ) {
		echo '<div class="notice notice-success is-dismissible"><p>Réglages enregistrés.</p></div>';
	}

	echo '<p>Proposer la version numérique au client qui achète la version papier, à tarif réduit. '
		. 'Un réglage par format source (Classique / Grands caractères), appliqué à tous les livres '
		. 'possédant une édition numérique.</p>';

	echo '<form method="post" action="">';
	wp_nonce_field( 'pf_numerique_save', 'pf_numerique_nonce' );

	pf_numerique_render_admin_block(
		'classique',
		'À l’achat d’une édition CLASSIQUE',
		$settings['classique']
	);
	pf_numerique_render_admin_block(
		'gc',
		'À l’achat d’une édition GRANDS CARACTÈRES',
		$settings['grands-caracteres']
	);

	echo '<p class="submit"><button type="submit" name="pf_numerique_save" value="1" class="button button-primary">Enregistrer</button></p>';
	echo '</form></div>';
}

function pf_numerique_render_admin_block( string $field, string $label, array $cfg ) {
	$mode  = $cfg['mode'] ?? 'disabled';
	$value = (string) ( $cfg['value'] ?? '' );
	$modes = [
		'disabled' => 'Désactivé (aucune offre)',
		'percent'  => 'Réduction en pourcentage',
		'fixed'    => 'Prix fixe',
		'free'     => 'Gratuit',
	];

	echo '<h2 style="margin-top:2em">' . esc_html( $label ) . '</h2>';
	echo '<table class="form-table" role="presentation"><tbody>';

	echo '<tr><th scope="row">Mode</th><td><fieldset>';
	foreach ( $modes as $m => $ml ) {
		printf(
			'<label style="display:block;margin-bottom:4px"><input type="radio" name="pfnum[%1$s][mode]" value="%2$s" %3$s> %4$s</label>',
			esc_attr( $field ),
			esc_attr( $m ),
			checked( $mode, $m, false ),
			esc_html( $ml )
		);
	}
	echo '</fieldset></td></tr>';

	printf(
		'<tr><th scope="row"><label for="pfnum-%1$s-value">Valeur</label></th><td>'
			. '<input type="text" id="pfnum-%1$s-value" name="pfnum[%1$s][value]" value="%2$s" class="regular-text" style="max-width:120px" inputmode="decimal">'
			. '<p class="description">Pourcentage de réduction (mode « Réduction en pourcentage ») ou prix en euros (mode « Prix fixe »). '
			. 'Ignoré pour « Désactivé » et « Gratuit ».</p>'
			. '</td></tr>',
		esc_attr( $field ),
		esc_attr( $value )
	);

	echo '</tbody></table>';
}
