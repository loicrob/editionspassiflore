<?php
add_filter( 'nav_menu_css_class', function( $classes, $item ) {
    if ( $item->object !== 'page' ) return $classes;

    $active = false;

    if ( ( is_product() || is_product_category() ) && (int) $item->object_id === (int) wc_get_page_id( 'shop' ) ) {
        $active = true;
    }

    if ( is_tax( 'auteur' ) ) {
        $auteurs_page = get_page_by_path( 'auteurs' );
        if ( $auteurs_page && (int) $item->object_id === (int) $auteurs_page->ID ) {
            $active = true;
        }
    }

    if ( $active ) {
        $classes[] = 'current-menu-item';
        $classes[] = 'current_page_item';
    }

    return $classes;
}, 10, 2 );

add_shortcode( 'passiflore_account_btn', function() {
    // Déconnecté, le bouton promet « Connexion » → il doit mener à /connexion,
    // pas à /mon-compte (cf. inc/account-auth.php). Connecté, c'est bien la page
    // compte elle-même. pf_auth_url() retombe sur la page compte si le fichier
    // d'authentification n'est pas chargé.
    $url = ( ! is_user_logged_in() && function_exists( 'pf_auth_url' ) )
        ? pf_auth_url( 'login' )
        : wc_get_page_permalink( 'myaccount' );
    // .pf-btn--sm pour la TAILLE (padding/texte/radius, cf. style.css) ; les
    // couleurs restent portées par button-style-outline/secondary (Kadence).
    // button-size-small retiré : sa règle de padding (0,2,0) battrait sinon
    // .pf-btn--sm (0,1,0).
    if ( is_user_logged_in() ) {
        // Espace INSÉCABLE : « Mon compte » est le seul libellé du header en deux
        // mots, donc le seul sécable — il se coupait en deux lignes quand la rangée
        // manquait de place (« Connexion », mot unique, ne l'a jamais fait, d'où un
        // symptôme visible uniquement connecté). Le logo fluide (style.css) évite
        // désormais la compression, ceci en est la ceinture : même si la rangée
        // devenait plus dense (entrée de menu en plus), le libellé reste d'un bloc.
        $label = "Mon\u{00A0}compte";
        $class = 'button header-button button-style-outline passiflore-account-btn is-logged-in pf-btn--sm';
    } else {
        $label = 'Connexion';
        $class = 'button header-button button-style-secondary passiflore-account-btn is-logged-out pf-btn--sm';
    }
    return '<a href="' . esc_url( $url ) . '" class="' . esc_attr( $class ) . '">' . esc_html( $label ) . '</a>';
} );

/**
 * Parité panier + « Mon compte » sur le header mobile.
 *
 * Le header builder Kadence place déjà le panier et le bouton compte dans la
 * zone de droite du header desktop. Le header mobile, lui, n'affiche par défaut
 * que le logo et le hamburger. On rétablit la parité côté code (versionné, sur
 * le même modèle que la recherche globale de inc/class-recherche-globale.php) :
 *  - le panier (élément natif Kadence) dans la barre mobile, à gauche du
 *    hamburger (rendu en priorité 8, < 10 du hamburger ; la recherche globale
 *    reste à gauche en priorité 5) ;
 *  - « Mon compte / Connexion » comme entrée du menu dans le tiroir mobile.
 */
add_action( 'kadence_render_mobile_header_column', 'passiflore_mobile_header_cart', 8, 2 );
function passiflore_mobile_header_cart( $row, $column ) {
    if ( $row !== 'main' || $column !== 'right' || ! class_exists( 'WooCommerce' ) ) {
        return;
    }
    // Template part natif : enveloppe .site-header-item + action kadence_mobile_cart.
    get_template_part( 'template-parts/header/mobile-cart' );
}

/**
 * Ajoute « Mon compte / Connexion » comme dernière entrée du menu de navigation
 * du tiroir mobile (menu_id 'mobile-menu', cf. Kadence\mobile_navigation()), rendu
 * comme les autres liens. Le menu desktop (#primary-menu) n'est pas touché : le
 * bouton compte y vit déjà dans la zone droite du header.
 */
add_filter( 'wp_nav_menu_items', 'passiflore_mobile_menu_account', 10, 2 );
function passiflore_mobile_menu_account( $items, $args ) {
    if ( ! isset( $args->menu_id ) || $args->menu_id !== 'mobile-menu' || ! function_exists( 'wc_get_page_permalink' ) ) {
        return $items;
    }
    // Même cible que le bouton du header (cf. [passiflore_account_btn] plus haut).
    $url   = ( ! is_user_logged_in() && function_exists( 'pf_auth_url' ) )
        ? pf_auth_url( 'login' )
        : wc_get_page_permalink( 'myaccount' );
    $label = is_user_logged_in() ? 'Mon compte' : 'Connexion';
    return $items . '<li class="menu-item menu-item-pf-account"><a href="' . esc_url( $url ) . '">' . esc_html( $label ) . '</a></li>';
}

/**
 * Marque chaque lien produit-catégorie du menu (réel ou injecté ci-dessous)
 * d'un attribut data-pf-cat="{slug}". Le filtrage de la page Catalogue est
 * entièrement AJAX (cf. class-catalogue.php / catalogue.js, history.pushState
 * sans rechargement) : le JS s'appuie sur cet attribut pour resynchroniser
 * l'état actif du sous-menu avec le filtre réellement appliqué dans la page.
 */
add_filter( 'nav_menu_link_attributes', function( $atts, $item ) {
    if ( 'taxonomy' === $item->type && 'product_cat' === $item->object ) {
        $term = get_term( $item->object_id, 'product_cat' );
        if ( $term && ! is_wp_error( $term ) ) {
            $atts['data-pf-cat'] = $term->slug;
        }
    }
    return $atts;
}, 10, 2 );

/**
 * Sous-menus de catégories produit auto-synchronisés.
 *
 * Toute entrée de menu pointant vers une catégorie produit (product_cat, ex.
 * "Littérature" ou "Culture Sud-Ouest") se voit complétée à l'affichage par
 * ses sous-catégories, dans l'ordre WooCommerce (Produits > Catégories,
 * glisser-déposer natif) — jamais stockées dans le menu. Une nouvelle
 * sous-catégorie créée plus tard apparaît donc directement, sans retoucher
 * au menu. Récursif pour couvrir une éventuelle 3ᵉ profondeur.
 */
add_filter( 'wp_nav_menu_objects', 'passiflore_inject_product_cat_children' );
function passiflore_inject_product_cat_children( $items ) {
    $next_id      = 900000;
    $queried      = get_queried_object();
    $current_term = ( $queried instanceof WP_Term && 'product_cat' === $queried->taxonomy ) ? (int) $queried->term_id : 0;

    foreach ( $items as $item ) {
        if ( 'taxonomy' === $item->type && 'product_cat' === $item->object ) {
            passiflore_add_product_cat_children( $item, $items, $next_id, $current_term );
        }
    }

    return $items;
}

function passiflore_add_product_cat_children( $parent_item, array &$items, int &$next_id, $current_term ) {
    $children = get_terms( array(
        'taxonomy'   => 'product_cat',
        'parent'     => (int) $parent_item->object_id,
        'orderby'    => 'menu_order',
        'order'      => 'ASC',
        'hide_empty' => false,
    ) );

    if ( empty( $children ) || is_wp_error( $children ) ) {
        return;
    }

    $parent_item->classes[] = 'menu-item-has-children';

    foreach ( $children as $term ) {
        // Actif si on est sur l'archive de cette catégorie, ou sur celle d'une de
        // ses descendantes (ex. "Littérature" reste marqué en visitant "Littérature
        // générale") — même logique que le cœur WP pour les vrais menu items, à
        // reproduire ici puisque ces items virtuels ne passent jamais par
        // _wp_menu_item_classes_by_context() (elle tourne avant leur injection).
        $is_current  = $current_term && $current_term === (int) $term->term_id;
        $is_ancestor = ! $is_current && $current_term
            && in_array( (int) $term->term_id, get_ancestors( $current_term, 'product_cat' ), true );

        $classes = array( 'menu-item', 'menu-item-type-taxonomy', 'menu-item-object-product_cat' );
        if ( $is_current ) {
            $classes[] = 'current-menu-item';
        } elseif ( $is_ancestor ) {
            $classes[] = 'current-product_cat-ancestor';
        }

        $child_item = (object) array(
            'ID'                    => $next_id,
            'db_id'                 => $next_id,
            'menu_item_parent'      => $parent_item->ID,
            'object_id'             => $term->term_id,
            'object'                => 'product_cat',
            'type'                  => 'taxonomy',
            'title'                 => $term->name,
            'url'                   => get_term_link( $term ),
            'target'                => '',
            'attr_title'            => '',
            'description'           => '',
            'classes'               => $classes,
            'xfn'                   => '',
            'current'               => $is_current,
            'current_item_ancestor' => $is_ancestor,
            'current_item_parent'   => false,
            'menu_order'            => $next_id,
        );
        ++$next_id;

        $items[] = $child_item;

        passiflore_add_product_cat_children( $child_item, $items, $next_id, $current_term );
    }
}