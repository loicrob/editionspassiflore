<?php
// Exit if accessed directly
if ( !defined( 'ABSPATH' ) ) exit;

// BEGIN ENQUEUE PARENT ACTION
// AUTO GENERATED - Do not modify or remove comment markers above or below:

if ( !function_exists( 'chld_thm_cfg_locale_css' ) ):
    function chld_thm_cfg_locale_css( $uri ){
        if ( empty( $uri ) && is_rtl() && file_exists( get_template_directory() . '/rtl.css' ) )
            $uri = get_template_directory_uri() . '/rtl.css';
        return $uri;
    }
endif;
add_filter( 'locale_stylesheet_uri', 'chld_thm_cfg_locale_css' );
         
if ( !function_exists( 'child_theme_configurator_css' ) ):
    function child_theme_configurator_css() {
        wp_enqueue_style( 'chld_thm_cfg_child', trailingslashit( get_stylesheet_directory_uri() ) . 'style.css', array( 'kadence-global','kadence-header','kadence-content','kadence-woocommerce','kadence-footer' ) );
    }
endif;
add_action( 'wp_enqueue_scripts', 'child_theme_configurator_css', 10 );

// END ENQUEUE PARENT ACTION

function passiflore_enqueue_auteurs_styles() {
    // Newsletter : CSS fusionné dans style.css (site-wide) — seul le JS reste dédié.
    wp_enqueue_script(
        'pf-newsletter',
        get_stylesheet_directory_uri() . '/assets/js/newsletter.js',
        [],
        filemtime( get_stylesheet_directory() . '/assets/js/newsletter.js' ),
        true
    );
    wp_localize_script( 'pf-newsletter', 'PassifloreNewsletter', [
        'ajax_url' => admin_url( 'admin-ajax.php' ),
        'nonce'    => wp_create_nonce( 'pf_newsletter' ),
    ] );

    if ( is_product() ) {
        wp_enqueue_style(
            'pf-book-single',
            get_stylesheet_directory_uri() . '/assets/css/book-single.css',
            [ 'passiflore-pageflip', 'pf-bookshelf' ],
            filemtime( get_stylesheet_directory() . '/assets/css/book-single.css' )
        );
        wp_enqueue_style(
            'pf-auteurs',
            get_stylesheet_directory_uri() . '/assets/css/auteurs.css',
            [],
            filemtime( get_stylesheet_directory() . '/assets/css/auteurs.css' )
        );
        wp_enqueue_style(
            'pf-events',
            get_stylesheet_directory_uri() . '/assets/css/events.css',
            [ 'pf-auteurs' ],
            filemtime( get_stylesheet_directory() . '/assets/css/events.css' )
        );
        wp_enqueue_script(
            'pf-event-tiles',
            get_stylesheet_directory_uri() . '/assets/js/event-tiles.js',
            [],
            filemtime( get_stylesheet_directory() . '/assets/js/event-tiles.js' ),
            true
        );
    }
    if ( is_page( 'auteurs' ) ) {
        wp_enqueue_style(
            'pf-auteurs',
            get_stylesheet_directory_uri() . '/assets/css/auteurs.css',
            [],
            '1.0.0'
        );
    }
    if ( is_tax( 'auteur' ) ) {
        wp_enqueue_style(
            'pf-auteur-single',
            get_stylesheet_directory_uri() . '/assets/css/auteur-single.css',
            [],
            '1.0.0'
        );
        wp_enqueue_style(
            'pf-events',
            get_stylesheet_directory_uri() . '/assets/css/events.css',
            [],
            filemtime( get_stylesheet_directory() . '/assets/css/events.css' )
        );
        wp_enqueue_script(
            'pf-event-tiles',
            get_stylesheet_directory_uri() . '/assets/js/event-tiles.js',
            [],
            filemtime( get_stylesheet_directory() . '/assets/js/event-tiles.js' ),
            true
        );
    }
    if ( is_page( 'contact' ) ) {
        wp_enqueue_style(
            'pf-contact',
            get_stylesheet_directory_uri() . '/assets/css/contact.css',
            [],
            '1.0.0'
        );
    }
    if ( function_exists( 'tribe_is_event_query' ) && ( tribe_is_event_query() || is_singular( 'tribe_events' ) ) ) {
        wp_enqueue_style(
            'pf-auteurs',
            get_stylesheet_directory_uri() . '/assets/css/auteurs.css',
            [],
            '1.0.0'
        );
        wp_enqueue_style(
            'pf-events',
            get_stylesheet_directory_uri() . '/assets/css/events.css',
            [ 'pf-auteurs' ],
            '1.0.0'
        );
    }
    if ( function_exists( 'is_account_page' ) && is_account_page() ) {
        wp_enqueue_style(
            'pf-account',
            get_stylesheet_directory_uri() . '/assets/css/account.css',
            [],
            filemtime( get_stylesheet_directory() . '/assets/css/account.css' )
        );
        wp_enqueue_script(
            'pf-account-reco',
            get_stylesheet_directory_uri() . '/assets/js/account-reco.js',
            [],
            filemtime( get_stylesheet_directory() . '/assets/js/account-reco.js' ),
            true
        );
    }
    if ( function_exists( 'is_cart' ) && is_cart() ) {
        wp_enqueue_style(
            'pf-cart',
            get_stylesheet_directory_uri() . '/assets/css/cart.css',
            [],
            filemtime( get_stylesheet_directory() . '/assets/css/cart.css' )
        );
        // Panier vidé → redirection vers le catalogue (= page boutique WooCommerce).
        wp_enqueue_script(
            'pf-cart-empty-redirect',
            get_stylesheet_directory_uri() . '/assets/js/cart-empty-redirect.js',
            [ 'wp-data' ],
            filemtime( get_stylesheet_directory() . '/assets/js/cart-empty-redirect.js' ),
            true
        );
        wp_localize_script( 'pf-cart-empty-redirect', 'pfCartEmptyRedirect', [
            'catalogueUrl' => wc_get_page_permalink( 'shop' ),
        ] );
    }
    if ( function_exists( 'is_checkout' ) && is_checkout() ) {
        wp_enqueue_style(
            'pf-checkout',
            get_stylesheet_directory_uri() . '/assets/css/checkout.css',
            [],
            filemtime( get_stylesheet_directory() . '/assets/css/checkout.css' )
        );
    }
    // Pages portant les blocs Cart/Checkout (Store API) : resynchronise le
    // compteur du panier dans le header, que les blocs ne rafraîchissent pas.
    if ( ( function_exists( 'is_cart' ) && is_cart() ) || ( function_exists( 'is_checkout' ) && is_checkout() ) ) {
        wp_enqueue_script(
            'pf-cart-count-sync',
            get_stylesheet_directory_uri() . '/assets/js/cart-count-sync.js',
            [ 'wp-data', 'jquery', 'wc-cart-fragments' ],
            filemtime( get_stylesheet_directory() . '/assets/js/cart-count-sync.js' ),
            true
        );
    }
}
add_action( 'wp_enqueue_scripts', 'passiflore_enqueue_auteurs_styles' );

// remplace un guillemet par une absence de caractère pour les slugs
add_filter('sanitize_title', 'custom_sanitize_book_slugs', 10);
function custom_sanitize_book_slugs($title) {
    // Liste des apostrophes à remplacer
    $search = array("'", "’");
    // Remplacement par un tiret
    $title = str_replace($search, '', $title);
    return $title;
}

remove_action( 'woocommerce_after_single_product_summary', 'woocommerce_output_related_products', 20 );

/**
 * Panier vide → catalogue.
 *
 * À l'arrivée sur la page panier alors qu'il est déjà vide, on redirige côté
 * serveur vers le catalogue (= page boutique WooCommerce), sans jamais afficher
 * l'écran « panier vide ». Le cas « on vide le panier en restant sur la page »
 * (pas de rechargement) est géré côté client par assets/js/cart-empty-redirect.js.
 */
add_action( 'template_redirect', 'passiflore_redirect_empty_cart' );
function passiflore_redirect_empty_cart() {
    if ( ! function_exists( 'is_cart' ) || ! is_cart() ) {
        return;
    }
    if ( ! WC()->cart || ! WC()->cart->is_empty() ) {
        return;
    }
    $url = wc_get_page_permalink( 'shop' );
    if ( $url ) {
        wp_safe_redirect( $url );
        exit;
    }
}

require_once get_stylesheet_directory() . '/inc/author-books-grouping.php';
require_once get_stylesheet_directory() . '/inc/search.php';
require_once get_stylesheet_directory() . '/inc/modifier-produit.php';
require_once get_stylesheet_directory() . '/inc/catalogues.php';
require_once get_stylesheet_directory() . '/inc/header-hooks.php';
require_once get_stylesheet_directory() . '/inc/newsletter.php';
require_once get_stylesheet_directory() . '/inc/admin.php';
require_once get_stylesheet_directory() . '/inc/book-sheet.php';
require_once get_stylesheet_directory() . '/inc/auteurs.php';
require_once get_stylesheet_directory() . '/inc/events.php';
require_once get_stylesheet_directory() . '/inc/class-events-feed.php';
require_once get_stylesheet_directory() . '/inc/class-events-search.php';
require_once get_stylesheet_directory() . '/inc/class-events-map.php';
require_once get_stylesheet_directory() . '/inc/event-admin.php';
require_once get_stylesheet_directory() . '/inc/venue-admin.php';
require_once get_stylesheet_directory() . '/inc/book-groups-admin.php';
require_once get_stylesheet_directory() . '/inc/event-hours.php';
require_once get_stylesheet_directory() . '/inc/pageflip.php';
require_once get_stylesheet_directory() . '/inc/book-single-tabs.php';
require_once get_stylesheet_directory() . '/inc/class-bookshelf.php';
require_once get_stylesheet_directory() . '/inc/class-catalogue.php';
require_once get_stylesheet_directory() . '/inc/class-recherche-auteurs.php';
require_once get_stylesheet_directory() . '/inc/class-recherche-globale.php';
require_once get_stylesheet_directory() . '/inc/accueil.php';
require_once get_stylesheet_directory() . '/inc/class-reading-list.php';
require_once get_stylesheet_directory() . '/inc/class-mes-avis.php';
require_once get_stylesheet_directory() . '/inc/recommendations.php';