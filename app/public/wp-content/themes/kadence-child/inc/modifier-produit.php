<?php
/**
 * Customisations de l'écran d'édition et de la liste des produits WooCommerce.
 *
 * - Contrainte serveur « un seul terme `format_groupe` par produit »
 * - Indication sur le bloc Catégories
 * - Liste des produits : masque le bouton natif « Trier » et impose un tri par
 *   défaut par date de parution (plus récent d'abord, puis titre A→Z)
 *
 * La saisie de l'œuvre, du format et du titre a été déplacée dans
 * inc/product-format-admin.php : la métabox `format_groupe`, son émulation
 * checkbox→radio, le pré-remplissage du nom de groupe et l'aide sous le champ
 * titre y sont remplacés par un sélecteur d'œuvre et un menu de format.
 */

// ─────────────────────────────────────────────────────────────────────────
// Contrainte serveur : un seul terme `format_groupe` par produit
//
// Le formulaire produit écrit désormais ce terme en mode remplacement, mais ce
// filet reste utile aux écritures qui ne passent pas par lui (imports, API).
// ─────────────────────────────────────────────────────────────────────────

add_action('save_post_product', function ($post_id) {
    $terms = wp_get_object_terms($post_id, 'format_groupe');
    if (!is_wp_error($terms) && count($terms) > 1) {
        wp_set_object_terms($post_id, [$terms[0]->term_id], 'format_groupe');
    }
});

// ─────────────────────────────────────────────────────────────────────────
// CSS / JS pour l'écran d'édition produit
// ─────────────────────────────────────────────────────────────────────────

$admin_footer_product = function () {
    global $post;
    if (!$post || $post->post_type !== 'product') return;
    ?>
    <script>
    document.addEventListener('DOMContentLoaded', function () {
        const categoriesHeader = document.querySelector('#product_catdiv .postbox-header');
        if (!categoriesHeader) return;
        const hintCategories = document.createElement('p');
        hintCategories.className = 'description';
        hintCategories.style.padding = '0 12px';
        hintCategories.innerHTML = '<strong>S\u00e9lectionner aussi la cat\u00e9gorie parente</strong> (ex. : \u201cCulture Sud-Ouest\u201d et \u201cArt\u201d).';
        categoriesHeader.insertAdjacentElement('afterend', hintCategories);
    });
    </script>
    <?php
};

add_action('admin_footer-post.php',     $admin_footer_product);
add_action('admin_footer-post-new.php', $admin_footer_product);

// ─────────────────────────────────────────────────────────────────────────
// Liste des produits (edit.php?post_type=product)
// ─────────────────────────────────────────────────────────────────────────

// Masque le bouton « Trier » (tri manuel par glisser-déposer = `menu_order`)
// que WooCommerce ajoute aux vues de la liste des produits. Le front de ce site
// n'utilise jamais `menu_order` (catalogue et étagère ont leur propre tri), ce
// tri manuel n'aurait donc aucun effet visible.
add_filter('views_edit-product', function ($views) {
    unset($views['byorder']);
    return $views;
}, 20);

// Tri par défaut de la liste : de la parution la plus récente à la plus ancienne
// (SCF `date_de_parution`, stockée en Ymd → l'ordre lexicographique correspond à
// l'ordre chronologique), puis par titre A→Z à date de parution égale. Les
// produits sans date de parution (meta absente ou vide) sont renvoyés en tête de
// liste (LEFT JOIN + critère booléen prioritaire).
// But : les admins voient les dernières parutions en premier.
// Ne s'applique que si l'admin n'a pas cliqué sur un en-tête de colonne triable
// (dans ce cas `orderby` est présent dans l'URL → on respecte son choix).
add_action('pre_get_posts', function ($query) {
    global $pagenow;
    if (!is_admin() || 'edit.php' !== $pagenow || !$query->is_main_query()) {
        return;
    }
    if ('product' !== $query->get('post_type') || isset($_GET['orderby'])) {
        return;
    }
    $query->set('pf_parution_sort', true);
});

add_filter('posts_clauses', function ($clauses, $query) {
    if (!$query->get('pf_parution_sort')) {
        return $clauses;
    }
    global $wpdb;
    $clauses['join']   .= " LEFT JOIN {$wpdb->postmeta} pf_parution"
        . " ON ( {$wpdb->posts}.ID = pf_parution.post_id"
        . " AND pf_parution.meta_key = 'date_de_parution' ) ";
    $clauses['orderby'] = "( pf_parution.meta_value IS NULL OR TRIM(pf_parution.meta_value) = '' ) DESC, "
        . "pf_parution.meta_value DESC, {$wpdb->posts}.post_title ASC";
    return $clauses;
}, 10, 2);