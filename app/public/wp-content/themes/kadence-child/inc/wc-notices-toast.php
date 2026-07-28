<?php
/**
 * Notices WooCommerce → toasts du site.
 *
 * Le détail du périmètre et du mapping vit dans `assets/js/wc-notices-toast.js`
 * (notices classiques) et `assets/js/wc-block-notices-toast.js` (notices React des
 * blocs Panier/Commande). Ici : le chargement des contrôleurs, le primer inline
 * qui voile les conteneurs AVANT le premier paint — les scripts vivant en pied de
 * page, sans ce voile la notice serait peinte à sa place d'origine puis retirée
 * (flash + saut de layout), même idiome que `.pf-beta-collapsed` (bandeau bêta) et
 * `.pf-cat-js` (catalogue) — et le rendu du wrapper classique sur les pages en
 * blocs, qui n'en produisent aucun par défaut.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Pages Panier / Commander : elles portent les blocs, donc les notices React. */
function pf_wc_notices_is_blocks_page() {
	return function_exists( 'is_cart' ) && ( is_cart() || is_checkout() );
}

/**
 * Y a-t-il des notices à déporter sur cette page ?
 *
 * Deux cas. **Une notice classique en file** : elles sont mises en file en session
 * (`wc_add_notice()`) puis consommées au rendu par `wc_print_notices()`, donc on
 * interroge la file *avant* le rendu — `wp_enqueue_scripts` et le primer tombent
 * tous deux dans `wp_head`, où elle est encore intacte. **Ou** une page en blocs :
 * là, une notice React peut surgir à tout moment (Store API), sans rien en file au
 * chargement — il faut donc voiler et charger d'office.
 *
 * Résultat mémorisé pour que les points de décision ne puissent pas diverger.
 *
 * Angle mort assumé hors pages en blocs : une notice classique ajoutée *pendant*
 * le rendu (après `wp_head`) n'est pas vue ici → elle s'affiche dans son bandeau
 * d'origine. Repli correct, jamais une page cassée.
 */
function pf_wc_notices_toast_active() {
	static $active = null;

	if ( null === $active ) {
		$active = function_exists( 'wc_notice_count' ) && WC()->session
			&& ( wc_notice_count() > 0 || pf_wc_notices_is_blocks_page() );
	}

	return $active;
}

add_action( 'wp_enqueue_scripts', 'pf_wc_notices_toast_assets' );
function pf_wc_notices_toast_assets() {
	if ( ! pf_wc_notices_toast_active() ) {
		return;
	}

	wp_enqueue_script(
		'pf-wc-notices-toast',
		get_stylesheet_directory_uri() . '/assets/js/wc-notices-toast.js',
		[ 'pf-toast' ],
		filemtime( get_stylesheet_directory() . '/assets/js/wc-notices-toast.js' ),
		true
	);

	if ( ! pf_wc_notices_is_blocks_page() ) {
		return;
	}

	// Dépend du contrôleur classique : il expose les icônes de statut partagées
	// (`window.pfNoticeIcons`). `wp-data` pour retirer du store `core/notices` la
	// notice qu'on vient de reprendre en toast.
	wp_enqueue_script(
		'pf-wc-block-notices-toast',
		get_stylesheet_directory_uri() . '/assets/js/wc-block-notices-toast.js',
		[ 'pf-wc-notices-toast', 'wp-data' ],
		filemtime( get_stylesheet_directory() . '/assets/js/wc-block-notices-toast.js' ),
		true
	);
}

/** Primer : voile les conteneurs de notices dès le premier paint (cf. style.css). */
add_action( 'wp_head', 'pf_wc_notices_toast_head', 1 );
function pf_wc_notices_toast_head() {
	if ( ! pf_wc_notices_toast_active() ) {
		return;
	}

	echo "<script>document.documentElement.classList.add('pf-notices-js');</script>\n";
}

/**
 * Wrapper de notices classiques en tête des blocs Panier / Commande.
 *
 * Ces pages n'en rendent aucun : seul le bloc « Notices de la boutique » appelle
 * `woocommerce_output_all_notices()`, et il n'est sur aucune des deux. Une notice
 * mise en file pendant un chargement de page (un `?add-to-cart=` sur /panier, un
 * retour de redirection d'une passerelle) y restait donc **invisible** et ne
 * ressortait que sur la page suivante.
 *
 * Fait en code plutôt qu'en insérant le bloc dans l'éditeur : le contenu des pages
 * n'est pas versionné et ne survivrait pas à leur recréation (réinstallation,
 * assistant WooCommerce). Le bloc reste une alternative valable — la règle CSS le
 * couvre déjà.
 *
 * ⚠️ Rien sur les endpoints `order-pay` / `order-received` : le bloc Checkout s'y
 * efface au profit du shortcode classique, qui imprime déjà ses notices via
 * `before_woocommerce_pay`.
 */
add_filter( 'render_block', 'pf_wc_notices_blocks_wrapper', 10, 2 );
function pf_wc_notices_blocks_wrapper( $block_content, $block ) {
	$name = $block['blockName'] ?? '';

	if ( 'woocommerce/cart' !== $name && 'woocommerce/checkout' !== $name ) {
		return $block_content;
	}
	if ( ! function_exists( 'wc_notice_count' ) || ! wc_notice_count() ) {
		return $block_content;
	}
	if ( is_wc_endpoint_url( 'order-pay' ) || is_wc_endpoint_url( 'order-received' ) ) {
		return $block_content;
	}

	ob_start();
	woocommerce_output_all_notices();

	return ob_get_clean() . $block_content;
}
