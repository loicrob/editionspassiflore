<?php
/**
 * Child theme override of archive-product.php.
 *
 * On the main shop page AND on product category archives, we render the
 * Catalogue page content (which holds [passiflore_catalogue]) instead of
 * the default products loop. Our shortcode picks up the current category
 * from `get_queried_object()` and pre-selects it in the filter UI.
 *
 * For other archives (product_tag, attributes), we keep the default loop.
 */

defined( 'ABSPATH' ) || exit;

get_header( 'shop' );

do_action( 'woocommerce_before_main_content' );

if ( is_shop() || is_product_taxonomy() ) {
	$catalogue_id = wc_get_page_id( 'shop' );
	$catalogue    = $catalogue_id > 0 ? get_post( $catalogue_id ) : null;
	if ( $catalogue ) {
		echo apply_filters( 'the_content', $catalogue->post_content );
	}
} else {
	do_action( 'woocommerce_shop_loop_header' );

	if ( woocommerce_product_loop() ) {
		do_action( 'woocommerce_before_shop_loop' );
		woocommerce_product_loop_start();

		if ( wc_get_loop_prop( 'total' ) ) {
			while ( have_posts() ) {
				the_post();
				do_action( 'woocommerce_shop_loop' );
				wc_get_template_part( 'content', 'product' );
			}
		}

		woocommerce_product_loop_end();
		do_action( 'woocommerce_after_shop_loop' );
	} else {
		do_action( 'woocommerce_no_products_found' );
	}
}

do_action( 'woocommerce_after_main_content' );
do_action( 'woocommerce_sidebar' );

get_footer( 'shop' );
