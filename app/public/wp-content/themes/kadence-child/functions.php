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
        wp_enqueue_style( 'chld_thm_cfg_child', trailingslashit( get_stylesheet_directory_uri() ) . 'style.css', array( 'kadence-global','kadence-header','kadence-content','kadence-woocommerce','kadence-footer' ), filemtime( get_stylesheet_directory() . '/style.css' ) );
    }
endif;
add_action( 'wp_enqueue_scripts', 'child_theme_configurator_css', 10 );

// END ENQUEUE PARENT ACTION

function passiflore_enqueue_auteurs_styles() {
    // Helper « session expirée » (toast de reprise après un 403 de nonce),
    // au-dessus du composant toast global pf-toast (enregistré site-wide par
    // Passiflore_Bookshelf). Enregistré ici, tiré à la demande par les scripts
    // des endpoints à nonce qui le déclarent en dépendance (newsletter, panier,
    // suppression d'un avis sur la fiche livre).
    wp_register_script(
        'pf-session-toast',
        get_stylesheet_directory_uri() . '/assets/js/pf-session-toast.js',
        [ 'pf-toast' ],
        filemtime( get_stylesheet_directory() . '/assets/js/pf-session-toast.js' ),
        true
    );

    // Newsletter : CSS fusionné dans style.css (site-wide) — seul le JS reste dédié.
    wp_enqueue_script(
        'pf-newsletter',
        get_stylesheet_directory_uri() . '/assets/js/newsletter.js',
        [ 'pf-session-toast' ],
        filemtime( get_stylesheet_directory() . '/assets/js/newsletter.js' ),
        true
    );
    wp_localize_script( 'pf-newsletter', 'PassifloreNewsletter', [
        'ajax_url' => admin_url( 'admin-ajax.php' ),
        'nonce'    => wp_create_nonce( 'pf_newsletter' ),
    ] );

    // Tiroir mobile : déplie automatiquement la catégorie produit courante
    // (site-wide, le header/tiroir existe sur toutes les pages).
    wp_enqueue_script(
        'pf-mobile-nav',
        get_stylesheet_directory_uri() . '/assets/js/mobile-nav.js',
        [],
        filemtime( get_stylesheet_directory() . '/assets/js/mobile-nav.js' ),
        true
    );

    // Séparateurs « · » du footer (site-wide, cf. [pf_footer_legal]).
    wp_enqueue_script(
        'pf-footer-legal',
        get_stylesheet_directory_uri() . '/assets/js/footer-legal.js',
        [],
        filemtime( get_stylesheet_directory() . '/assets/js/footer-legal.js' ),
        true
    );

    // Toasts d'ajout / de retrait du panier. Site-wide : le mini-panier du header
    // est sur toutes les pages, et son bouton × doit donner le même retour qu'un
    // ajout. jquery en dépendance — `added_to_cart` / `removed_from_cart` sont des
    // événements jQuery custom du cœur WooCommerce.
    if ( function_exists( 'wc_get_cart_url' ) ) {
        wp_enqueue_script(
            'pf-cart-toast',
            get_stylesheet_directory_uri() . '/assets/js/cart-toast.js',
            [ 'jquery', 'pf-toast' ],
            filemtime( get_stylesheet_directory() . '/assets/js/cart-toast.js' ),
            true
        );
        wp_localize_script( 'pf-cart-toast', 'PassifloreCartToast', [
            // Seule la fiche livre porte un bouton d'ajout AJAX : ailleurs, le titre
            // est inconnu et le script retombe sur le message que WooCommerce pose
            // lui-même sur le bouton (`data-success_message`).
            'title'    => is_product() ? get_the_title() : '',
            // Espace insécable avant le « ! » (convention typographique française,
            // cf. inc/class-reading-list.php). Le message est injecté en innerHTML
            // par pf-toast.js → l'entité est bien interprétée.
            'added'    => '%s a bien été ajouté au panier&nbsp;!',
            // Offre « version numérique » retenue : deux produits partent au
            // panier, le toast doit les annoncer tous les deux — sans quoi le
            // client ne voit confirmé que le livre papier.
            'added_num' => '%s et sa version numérique ont bien été ajoutés au panier&nbsp;!',
            'removed'  => '%s a bien été retiré du panier.',
            'cart_cta' => 'Voir le panier',
            'cart_url' => wc_get_cart_url(),
            'close'    => 'Fermer',
        ] );
    }

    // Composant partagé « section-nav » (fiche livre + fiche événement).
    if ( is_product() || is_singular( 'tribe_events' ) ) {
        wp_enqueue_script(
            'pf-section-nav',
            get_stylesheet_directory_uri() . '/assets/js/section-nav.js',
            [],
            filemtime( get_stylesheet_directory() . '/assets/js/section-nav.js' ),
            true
        );
    }

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
            'pf-scroll-fade',
            get_stylesheet_directory_uri() . '/assets/js/scroll-fade.js',
            [],
            filemtime( get_stylesheet_directory() . '/assets/js/scroll-fade.js' ),
            true
        );

        // Vol du livre vers le panier à l'ajout. Propre à la fiche livre (il clone
        // le livre du hero) ; le toast qui le suit, lui, vit au niveau du panier
        // et attend l'atterrissage via window.pfCartFlight.
        wp_enqueue_script(
            'pf-add-to-cart-flight',
            get_stylesheet_directory_uri() . '/assets/js/add-to-cart-flight.js',
            [],
            filemtime( get_stylesheet_directory() . '/assets/js/add-to-cart-flight.js' ),
            true
        );

        // Désactive le bouton d'ajout au panier quand le produit vendu à
        // l'unité (sold_individually) y est déjà — évite le rechargement de
        // page provoqué par l'erreur WooCommerce native sur un 2e clic.
        wp_enqueue_script(
            'pf-add-to-cart-sold-individually',
            get_stylesheet_directory_uri() . '/assets/js/add-to-cart-sold-individually.js',
            [],
            filemtime( get_stylesheet_directory() . '/assets/js/add-to-cart-sold-individually.js' ),
            true
        );
    }
    if ( is_page( 'auteurs' ) ) {
        wp_enqueue_style(
            'pf-auteurs',
            get_stylesheet_directory_uri() . '/assets/css/auteurs.css',
            [],
            filemtime( get_stylesheet_directory() . '/assets/css/auteurs.css' )
        );
    }
    if ( is_tax( 'auteur' ) ) {
        wp_enqueue_style(
            'pf-auteur-single',
            get_stylesheet_directory_uri() . '/assets/css/auteur-single.css',
            [],
            filemtime( get_stylesheet_directory() . '/assets/css/auteur-single.css' )
        );
        wp_enqueue_style(
            'pf-events',
            get_stylesheet_directory_uri() . '/assets/css/events.css',
            [],
            filemtime( get_stylesheet_directory() . '/assets/css/events.css' )
        );
        wp_enqueue_script(
            'pf-scroll-fade',
            get_stylesheet_directory_uri() . '/assets/js/scroll-fade.js',
            [],
            filemtime( get_stylesheet_directory() . '/assets/js/scroll-fade.js' ),
            true
        );
    }
    if ( function_exists( 'tribe_is_event_query' ) && ( tribe_is_event_query() || is_singular( 'tribe_events' ) ) ) {
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
    }
    // Bouton « S'abonner » : action directe vers le calendrier probable du visiteur.
    // Vues d'archive uniquement — la fiche événement n'utilise pas ce menu (simple lien .ics).
    if ( function_exists( 'tribe_is_event_query' ) && tribe_is_event_query() ) {
        wp_enqueue_script(
            'pf-subscribe-calendar',
            get_stylesheet_directory_uri() . '/assets/js/subscribe-calendar.js',
            [],
            filemtime( get_stylesheet_directory() . '/assets/js/subscribe-calendar.js' ),
            true
        );
    }
    if ( is_singular( 'tribe_events' ) ) {
        wp_enqueue_style(
            'pf-event-single',
            get_stylesheet_directory_uri() . '/assets/css/event-single.css',
            [ 'pf-events' ],
            filemtime( get_stylesheet_directory() . '/assets/css/event-single.css' )
        );
        wp_enqueue_script(
            'pf-scroll-fade',
            get_stylesheet_directory_uri() . '/assets/js/scroll-fade.js',
            [],
            filemtime( get_stylesheet_directory() . '/assets/js/scroll-fade.js' ),
            true
        );
        // Desktop : largeur de la colonne de l'image collée (calculée depuis le ratio).
        wp_enqueue_script(
            'pf-event-single-media',
            get_stylesheet_directory_uri() . '/assets/js/event-single-media.js',
            [],
            filemtime( get_stylesheet_directory() . '/assets/js/event-single-media.js' ),
            true
        );
    }
    if ( function_exists( 'is_account_page' ) && is_account_page() ) {
        wp_enqueue_style(
            'pf-account',
            get_stylesheet_directory_uri() . '/assets/css/account.css',
            [],
            filemtime( get_stylesheet_directory() . '/assets/css/account.css' )
        );
        // Nav du compte = composant .pf-sectionnav--static : ombres de bord du
        // défilement horizontal (mobile) + recentrage de la pilule active.
        wp_enqueue_script(
            'pf-scroll-fade',
            get_stylesheet_directory_uri() . '/assets/js/scroll-fade.js',
            [],
            filemtime( get_stylesheet_directory() . '/assets/js/scroll-fade.js' ),
            true
        );
        wp_enqueue_script(
            'pf-account-nav',
            get_stylesheet_directory_uri() . '/assets/js/account-nav.js',
            [],
            filemtime( get_stylesheet_directory() . '/assets/js/account-nav.js' ),
            true
        );
        // Bascule d'affichage des étagères du compte (Couvertures | Dos) : re-rend
        // l'étagère (Suggestions / Ma liste de lecture / Catalogue) via AJAX.
        wp_enqueue_script(
            'pf-shelf-display',
            get_stylesheet_directory_uri() . '/assets/js/shelf-display.js',
            [],
            filemtime( get_stylesheet_directory() . '/assets/js/shelf-display.js' ),
            true
        );
        wp_localize_script( 'pf-shelf-display', 'pfShelfDisplay', [
            'ajax_url' => admin_url( 'admin-ajax.php' ),
            'nonce'    => wp_create_nonce( 'pf_shelf_display' ),
        ] );
        // Mesure --pf-account-hub-top pour .pf-account-dashboard (account.css).
        // No-op sur les pages de compte autres que le hub (élément absent du DOM).
        wp_enqueue_script(
            'pf-account-hub',
            get_stylesheet_directory_uri() . '/assets/js/account-hub.js',
            [],
            filemtime( get_stylesheet_directory() . '/assets/js/account-hub.js' ),
            true
        );
        // Ouverture/fermeture animée du bloc mot de passe (form-edit-account.php).
        // No-op ailleurs sur le compte (élément absent du DOM).
        wp_enqueue_script(
            'pf-account-password-toggle',
            get_stylesheet_directory_uri() . '/assets/js/account-password-toggle.js',
            [],
            filemtime( get_stylesheet_directory() . '/assets/js/account-password-toggle.js' ),
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
    }
    if ( function_exists( 'is_checkout' ) && is_checkout() ) {
        wp_enqueue_style(
            'pf-checkout',
            get_stylesheet_directory_uri() . '/assets/css/checkout.css',
            [],
            filemtime( get_stylesheet_directory() . '/assets/css/checkout.css' )
        );
        // Remplace le placeholder par défaut du champ « note de commande ».
        wp_enqueue_script(
            'pf-checkout-note-placeholder',
            get_stylesheet_directory_uri() . '/assets/js/checkout-note-placeholder.js',
            [],
            filemtime( get_stylesheet_directory() . '/assets/js/checkout-note-placeholder.js' ),
            true
        );
        // Force le recalcul de la livraison au changement de pays (bug blocs WC).
        wp_enqueue_script(
            'pf-checkout-shipping-country',
            get_stylesheet_directory_uri() . '/assets/js/checkout-shipping-country.js',
            [ 'wp-data' ],
            filemtime( get_stylesheet_directory() . '/assets/js/checkout-shipping-country.js' ),
            true
        );
    }
    // Pages portant les blocs Cart/Checkout (Store API) : resynchronise le
    // compteur du panier dans le header, que les blocs ne rafraîchissent pas.
    if ( ( function_exists( 'is_cart' ) && is_cart() ) || ( function_exists( 'is_checkout' ) && is_checkout() ) ) {
        // Composant infobulle « i » partagé (registré ici indépendamment de
        // inc/numerique-offer.php, qui ne l'enregistre pas sur /commander).
        wp_enqueue_script(
            'pf-tooltip',
            get_stylesheet_directory_uri() . '/assets/js/pf-tooltip.js',
            [],
            filemtime( get_stylesheet_directory() . '/assets/js/pf-tooltip.js' ),
            true
        );
        // Badge « En précommande » à la place du badge de promo (panier +
        // récapitulatif de la validation de commande, tous deux en blocs).
        wp_enqueue_script(
            'pf-cart-backorder-badge',
            get_stylesheet_directory_uri() . '/assets/js/cart-backorder-badge.js',
            [ 'wp-data', 'pf-tooltip' ],
            filemtime( get_stylesheet_directory() . '/assets/js/cart-backorder-badge.js' ),
            true
        );
        wp_enqueue_script(
            'pf-cart-count-sync',
            get_stylesheet_directory_uri() . '/assets/js/cart-count-sync.js',
            [ 'wp-data', 'jquery', 'wc-cart-fragments' ],
            filemtime( get_stylesheet_directory() . '/assets/js/cart-count-sync.js' ),
            true
        );
        // Panier vidé (panier ou validation de la commande) → redirection vers le catalogue.
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
 * À l'arrivée sur la page panier ou la page de validation de la commande alors
 * que le panier est déjà vide, on redirige côté serveur vers le catalogue
 * (= page boutique WooCommerce), sans jamais afficher l'écran « panier vide ».
 * Le cas « on vide le panier en restant sur la page » (pas de rechargement)
 * est géré côté client par assets/js/cart-empty-redirect.js.
 */
add_action( 'template_redirect', 'passiflore_redirect_empty_cart' );
function passiflore_redirect_empty_cart() {
    $is_cart_page = function_exists( 'is_cart' ) && is_cart();
    $is_checkout_page = function_exists( 'is_checkout' ) && is_checkout();
    if ( ! $is_cart_page && ! $is_checkout_page ) {
        return;
    }
    // is_checkout() reste vrai sur les endpoints « order-received » (remerciement)
    // et « order-pay » : le panier y est légitimement vide (commande déjà validée,
    // ou paiement d'une commande existante) → ne pas rediriger dans ces cas.
    if ( $is_checkout_page && function_exists( 'is_wc_endpoint_url' )
        && ( is_wc_endpoint_url( 'order-received' ) || is_wc_endpoint_url( 'order-pay' ) ) ) {
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

require_once get_stylesheet_directory() . '/inc/a11y.php';
require_once get_stylesheet_directory() . '/inc/isbn.php';
require_once get_stylesheet_directory() . '/inc/format-groupe.php';
require_once get_stylesheet_directory() . '/inc/product-format-admin.php';
require_once get_stylesheet_directory() . '/inc/author-books-grouping.php';
require_once get_stylesheet_directory() . '/inc/search.php';
require_once get_stylesheet_directory() . '/inc/modifier-produit.php';
require_once get_stylesheet_directory() . '/inc/book-field-validation.php';
require_once get_stylesheet_directory() . '/inc/stock-sync.php';
require_once get_stylesheet_directory() . '/inc/catalogues.php';
require_once get_stylesheet_directory() . '/inc/header-hooks.php';
require_once get_stylesheet_directory() . '/inc/header-sticky.php';
require_once get_stylesheet_directory() . '/inc/newsletter.php';
require_once get_stylesheet_directory() . '/inc/admin.php';
require_once get_stylesheet_directory() . '/inc/book-sheet.php';
require_once get_stylesheet_directory() . '/inc/auteurs.php';
require_once get_stylesheet_directory() . '/inc/term-slug-redirect.php';
require_once get_stylesheet_directory() . '/inc/events.php';
require_once get_stylesheet_directory() . '/inc/event-single.php';
require_once get_stylesheet_directory() . '/inc/class-events-feed.php';
require_once get_stylesheet_directory() . '/inc/class-events-search.php';
require_once get_stylesheet_directory() . '/inc/class-events-map.php';
require_once get_stylesheet_directory() . '/inc/event-admin.php';
require_once get_stylesheet_directory() . '/inc/event-duplicate.php';
require_once get_stylesheet_directory() . '/inc/post-slug-sync.php';
require_once get_stylesheet_directory() . '/inc/venue-admin.php';
require_once get_stylesheet_directory() . '/inc/book-groups-admin.php';
require_once get_stylesheet_directory() . '/inc/event-hours.php';
require_once get_stylesheet_directory() . '/inc/seo-events.php';
require_once get_stylesheet_directory() . '/inc/pageflip.php';
require_once get_stylesheet_directory() . '/inc/section-nav.php';
require_once get_stylesheet_directory() . '/inc/book-single-tabs.php';
require_once get_stylesheet_directory() . '/inc/class-bookshelf.php';
require_once get_stylesheet_directory() . '/inc/class-catalogue.php';
require_once get_stylesheet_directory() . '/inc/class-recherche-auteurs.php';
require_once get_stylesheet_directory() . '/inc/class-recherche-globale.php';
require_once get_stylesheet_directory() . '/inc/accueil.php';
require_once get_stylesheet_directory() . '/inc/class-reading-list.php';
require_once get_stylesheet_directory() . '/inc/account-auth.php';
require_once get_stylesheet_directory() . '/inc/recommendations.php';
require_once get_stylesheet_directory() . '/inc/account-hub.php';
require_once get_stylesheet_directory() . '/inc/shipping.php';
require_once get_stylesheet_directory() . '/inc/boxtal-perf.php';
require_once get_stylesheet_directory() . '/inc/numerique-offer.php';
require_once get_stylesheet_directory() . '/inc/cart-backorder-badge.php';
require_once get_stylesheet_directory() . '/inc/epub-storage.php';
require_once get_stylesheet_directory() . '/inc/class-ebooks.php';
require_once get_stylesheet_directory() . '/inc/checkout.php';
require_once get_stylesheet_directory() . '/inc/checkout-consent.php';
require_once get_stylesheet_directory() . '/inc/order-statuses.php';
require_once get_stylesheet_directory() . '/inc/order-emails.php';
require_once get_stylesheet_directory() . '/inc/site-relaunch-email.php';
require_once get_stylesheet_directory() . '/inc/wc-notices-toast.php';
require_once get_stylesheet_directory() . '/inc/mini-cart.php';
require_once get_stylesheet_directory() . '/inc/shortcodes.php';
require_once get_stylesheet_directory() . '/inc/privacy-cookies.php';
